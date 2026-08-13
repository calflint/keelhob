<?php
/**
 * Telegram chat front-end for the on-site agent.
 *
 * Claudpress could already *talk to* Telegram (digests and run summaries go out
 * through Claudpress_Notifier). This is the other direction: the owner messages
 * the bot, Telegram POSTs the message to a REST route here, and the text is
 * handed to Claudpress_Agent_Runtime — the same agentic loop, the same tools,
 * the same policy guardrails that a scheduled task gets. The reply goes back as
 * a Telegram message.
 *
 * The point is that no MCP client is involved. The owner does not need Claude
 * Code, a terminal, or even a laptop; the site's own API key is the brain and
 * the phone is the interface.
 *
 * It accepts text, voice notes (transcribed first) and photos (sent to the model
 * as vision input and filed in the media library), shows its work by editing one
 * message as the run progresses, offers an inline Approve button when policy
 * blocks something, and can undo the content changes of its last turn.
 *
 * Four things make that safe enough to expose on a public URL:
 *   1. Telegram signs every delivery with a secret header we generate and store,
 *      compared in constant time. A request without it never reaches the agent.
 *   2. Only chat ids on the owner's allowlist are answered at all, each with a
 *      role — admin, editor or viewer — that decides what it may do.
 *   3. Destructive/financial tools stay behind Claudpress_Policy, and a chat can
 *      only escalate when the owner has armed that switch in WP admin.
 *   4. Escalation is either a self-expiring /risky window or a per-action
 *      Approve button; nothing is ever silently permitted.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Telegram
{
    const API_BASE  = 'https://api.telegram.org/bot';
    const FILE_BASE = 'https://api.telegram.org/file/bot';

    /** Telegram rejects anything longer; we split rather than truncate. */
    const MAX_MESSAGE = 4096;

    /** How long a chat's /risky opt-in survives before lapsing (seconds). */
    const RISKY_TTL = 1800;

    /** A single chat may not have two agent runs in flight (seconds). */
    const BUSY_TTL = 600;

    /** How long an unanswered Approve button stays valid (seconds). */
    const APPROVAL_TTL = 3600;

    /** Telegram rate-limits edits; don't rewrite the progress line faster. */
    const PROGRESS_THROTTLE = 2;

    /** Biggest media we will pull down from Telegram (bytes). */
    const MAX_FILE_BYTES = 20971520; // 20 MB, Telegram's own bot download ceiling

    const OPTION_LAST_UPDATE = 'claudpress_tg_last_update';
    const OPTION_RISKY       = 'claudpress_tg_risky';
    const OPTION_PENDING     = 'claudpress_tg_pending';

    /**
     * Chat roles, most to least privileged.
     *
     *   admin  — everything, may use /risky and press Approve. The default, so
     *            an allowlist written before roles existed keeps working.
     *   editor — may change the site, but can never escalate to destructive or
     *            financial tools, and cannot approve.
     *   viewer — read-only tools only. For someone who should be able to ask
     *            "how are sales?" without being able to touch anything.
     */
    const ROLES = ['admin', 'editor', 'viewer'];

    /** @var array<string, int> last progress-edit timestamp, keyed by message */
    private static $last_progress = [];

    // ---- storage ----------------------------------------------------------

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_chat';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id VARCHAR(64) NOT NULL,
            role VARCHAR(16) NOT NULL DEFAULT 'user',
            content LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY chat_id (chat_id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    // ---- configuration ----------------------------------------------------

    public static function token(): string
    {
        return trim((string) Claudpress::instance()->get('notify_telegram_token', ''));
    }

    /** Inbound chat is off unless the owner switched it on and set a token. */
    public static function enabled(): bool
    {
        return (bool) Claudpress::instance()->get('telegram_bot_enabled', false) && self::token() !== '';
    }

    /**
     * The shared secret Telegram echoes back on every delivery. Generated on
     * first use so an owner never has to invent one, and rotated by the admin
     * button (which re-registers the webhook).
     */
    public static function secret(): string
    {
        $secret = trim((string) Claudpress::instance()->get('telegram_secret', ''));
        if ($secret === '') {
            $secret = self::generate_secret();
            Claudpress::instance()->update(['telegram_secret' => $secret]);
        }
        return $secret;
    }

    public static function generate_secret(): string
    {
        // Telegram allows A-Z a-z 0-9 _ - only, 1-256 chars.
        return bin2hex(random_bytes(24));
    }

    /**
     * Chat ids allowed to drive the site. Falls back to the notification chat
     * id, so an owner who already configured outbound Telegram is allowlisted
     * without configuring the same number twice.
     *
     * @return array<int, string>
     */
    public static function allowed_chats(): array
    {
        // strval because PHP silently turns a numeric string array key into an
        // int, and every chat id comparison downstream is a strict string one.
        return array_map('strval', array_keys(self::chat_roles()));
    }

    /**
     * The allowlist as id => role.
     *
     * @return array<string, string>
     */
    public static function chat_roles(): array
    {
        $s   = Claudpress::instance()->settings();
        $raw = trim((string) ($s['telegram_allowed_chats'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($s['notify_telegram_chat'] ?? ''));
        }
        return self::parse_chat_roles($raw);
    }

    /**
     * Parse "123, 456:viewer, -100987:editor" into id => role.
     *
     * A bare id means admin: the allowlist predates roles, and silently
     * demoting an existing owner's own chat on upgrade would look like a bug.
     * An unrecognised role also means admin rather than being dropped — losing
     * access over a typo is worse than the typo.
     *
     * @return array<string, string>
     */
    public static function parse_chat_roles(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $bits = explode(':', $part, 2);
            $id   = trim($bits[0]);
            if ($id === '') {
                continue;
            }
            $role = strtolower(trim((string) ($bits[1] ?? '')));
            $out[$id] = in_array($role, self::ROLES, true) ? $role : 'admin';
        }
        return $out;
    }

    /** @return array<int, string> ids only, for the plain-list callers */
    public static function parse_chat_list(string $raw): array
    {
        return array_map('strval', array_keys(self::parse_chat_roles($raw)));
    }

    /**
     * An empty allowlist denies everyone. That is deliberate: the alternative
     * reading — "not configured means open" — would hand the whole site to the
     * first stranger who found the bot.
     */
    public static function is_allowed(string $chat_id, array $allowed): bool
    {
        return $chat_id !== '' && in_array($chat_id, $allowed, true);
    }

    /** @return string|null the chat's role, or null when it is not allowlisted */
    public static function role_for(string $chat_id): ?string
    {
        if ($chat_id === '') {
            return null;
        }
        // A numeric chat id is an int key in the parsed map (PHP's doing), and a
        // string lookup coerces to match it — but '0'/'' must not, so the blank
        // check above stands guard.
        $roles = self::chat_roles();
        return isset($roles[$chat_id]) ? (string) $roles[$chat_id] : null;
    }

    public static function webhook_url(): string
    {
        return rest_url(CLAUDPRESS_REST_NAMESPACE . '/telegram');
    }

    public static function history_turns(): int
    {
        $n = (int) Claudpress::instance()->get('telegram_history_turns', 12);
        return max(0, min(60, $n));
    }

    public static function voice_enabled(): bool
    {
        return (bool) Claudpress::instance()->get('telegram_voice', false)
            && Claudpress_Transcribe_Provider::key() !== '';
    }

    public static function photos_enabled(): bool
    {
        return (bool) Claudpress::instance()->get('telegram_photos', false);
    }

    public static function progress_enabled(): bool
    {
        return (bool) Claudpress::instance()->get('telegram_progress', true);
    }

    // ---- pure helpers -----------------------------------------------------

    /**
     * Split "/clear@MyBot some args" into its command and the rest. Telegram
     * appends @botname when several bots share a group, and it is not part of
     * the command the owner typed.
     *
     * @return array{cmd: string, args: string} cmd is '' for ordinary messages
     */
    public static function parse_command(string $text): array
    {
        $text = trim($text);
        if ($text === '' || $text[0] !== '/') {
            return ['cmd' => '', 'args' => $text];
        }
        $parts = preg_split('/\s+/', $text, 2);
        $cmd   = strtolower(ltrim((string) $parts[0], '/'));
        if (strpos($cmd, '@') !== false) {
            $cmd = substr($cmd, 0, strpos($cmd, '@'));
        }
        // Telegram commands start with a letter, so "/2 orders came in?" is a
        // sentence the owner typed, not a command we should fail to recognise.
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $cmd)) {
            return ['cmd' => '', 'args' => $text];
        }
        return ['cmd' => $cmd, 'args' => trim((string) ($parts[1] ?? ''))];
    }

    /**
     * Break a reply into Telegram-sized chunks, preferring paragraph then line
     * boundaries so a split never lands mid-sentence when it doesn't have to.
     *
     * @return array<int, string>
     */
    public static function split_message(string $text, int $limit = self::MAX_MESSAGE): array
    {
        $text  = trim($text);
        $limit = max(64, $limit);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        while (mb_strlen($text) > $limit) {
            $window = mb_substr($text, 0, $limit);
            $cut    = mb_strrpos($window, "\n\n");
            if ($cut === false || $cut < (int) ($limit * 0.5)) {
                $cut = mb_strrpos($window, "\n");
            }
            if ($cut === false || $cut < (int) ($limit * 0.5)) {
                $cut = mb_strrpos($window, ' ');
            }
            if ($cut === false || $cut < 1) {
                $cut = $limit;
            }
            $chunks[] = trim(mb_substr($text, 0, $cut));
            $text     = ltrim(mb_substr($text, $cut));
        }
        if (trim($text) !== '') {
            $chunks[] = trim($text);
        }
        return array_values(array_filter($chunks, static fn($c) => $c !== ''));
    }

    /**
     * Keep the last N turns, and never open the replay on an assistant turn —
     * a conversation that starts mid-answer reads as though the model invented
     * it, and some vendors reject a leading assistant message outright.
     *
     * @param array<int, array{role: string, text: string}> $turns oldest first
     */
    public static function trim_history(array $turns, int $max_turns): array
    {
        if ($max_turns <= 0) {
            return [];
        }
        $turns = array_slice(array_values($turns), -$max_turns);
        while (!empty($turns) && ($turns[0]['role'] ?? 'user') !== 'user') {
            array_shift($turns);
        }
        return array_values($turns);
    }

    /**
     * The footer that turns "it says it did something" into something the owner
     * can audit: how much work it did and roughly what it cost.
     */
    public static function format_reply(array $run): string
    {
        if (empty($run['success'])) {
            return '⚠️ ' . (string) ($run['error'] ?? 'The run failed.');
        }

        $body  = trim((string) ($run['summary'] ?? '')) ?: '(no reply)';
        $usage = is_array($run['usage'] ?? null) ? $run['usage'] : [];
        $bits  = [];

        $tools = count((array) ($run['tool_calls'] ?? []));
        if ($tools > 0) {
            $bits[] = $tools . ($tools === 1 ? ' tool' : ' tools');
        }
        if (!empty($usage['cost_usd'])) {
            $bits[] = '~$' . number_format((float) $usage['cost_usd'], 4);
        }

        return $bits ? $body . "\n\n— " . implode(' · ', $bits) : $body;
    }

    /**
     * The tools a run wanted but policy refused, deduplicated.
     *
     * This is what the Approve button is offered for. A capability the owner
     * switched off is excluded on purpose: that is a settled decision made in
     * WP admin, not a question to re-ask on every message.
     *
     * @return array<int, string>
     */
    public static function blocked_tools(array $run): array
    {
        $out = [];
        foreach ((array) ($run['tool_calls'] ?? []) as $call) {
            if (empty($call['blocked'])) {
                continue;
            }
            $reason = (string) ($call['reason'] ?? '');
            if ($reason === 'capability off' || $reason === 'read-only conversation') {
                continue;
            }
            $out[(string) $call['tool']] = true;
        }
        return array_keys($out);
    }

    /** One line of progress: what step we're on and what it just used. */
    public static function format_progress(array $steps): string
    {
        $lines = ['⚙️ Working…'];
        foreach (array_slice($steps, -6) as $s) {
            $tools = array_unique((array) ($s['tools'] ?? []));
            $lines[] = '• ' . ($tools ? implode(', ', $tools) : 'thinking');
        }
        return implode("\n", $lines);
    }

    // ---- conversation history --------------------------------------------

    /** @return array<int, array{role: string, text: string}> oldest first */
    public static function history(string $chat_id, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content FROM " . self::table() . " WHERE chat_id = %s ORDER BY id DESC LIMIT %d",
            $chat_id,
            $limit
        ), ARRAY_A);

        $turns = [];
        foreach (array_reverse($rows ?: []) as $row) {
            $turns[] = ['role' => (string) $row['role'], 'text' => (string) $row['content']];
        }
        return self::trim_history($turns, $limit);
    }

    public static function remember(string $chat_id, string $role, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        global $wpdb;
        $wpdb->insert(self::table(), [
            'chat_id'    => substr($chat_id, 0, 64),
            'role'       => $role === 'assistant' ? 'assistant' : 'user',
            'content'    => mb_substr($text, 0, 12000),
            'created_at' => current_time('mysql'),
        ]);
    }

    /** Wipe one conversation. Returns how many turns were dropped. */
    public static function clear(string $chat_id): int
    {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE chat_id = %s",
            $chat_id
        ));
    }

    /** Wipe every conversation (the admin button). */
    public static function clear_all(): int
    {
        global $wpdb;
        return (int) $wpdb->query("DELETE FROM " . self::table());
    }

    public static function turn_count(string $chat_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE chat_id = %s",
            $chat_id
        ));
    }

    /** Housekeeping: drop conversation turns older than N days. */
    public static function prune(int $keep_days = 30): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($keep_days * 86400));
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE created_at < %s",
            $cutoff
        ));
    }

    // ---- per-chat risky opt-in -------------------------------------------

    /**
     * Two switches have to agree before a chat may run destructive tools: the
     * owner arms it in WP admin, and the chat opts in with /risky on. The
     * opt-in then lapses by itself, so a forgotten "on" cannot sit armed for
     * weeks.
     */
    public static function risky_allowed(string $chat_id): bool
    {
        if (!self::may_escalate($chat_id)) {
            return false;
        }
        $map   = (array) get_option(self::OPTION_RISKY, []);
        $until = (int) ($map[$chat_id] ?? 0);
        return $until > time();
    }

    /**
     * Whether this chat is even permitted to escalate — by /risky or by pressing
     * Approve. Requires the owner's master switch and the admin role.
     */
    public static function may_escalate(string $chat_id): bool
    {
        return (bool) Claudpress::instance()->get('telegram_allow_risky', false)
            && self::role_for($chat_id) === 'admin';
    }

    public static function set_risky(string $chat_id, bool $on): int
    {
        $map = (array) get_option(self::OPTION_RISKY, []);
        if ($on) {
            $map[$chat_id] = time() + self::RISKY_TTL;
        } else {
            unset($map[$chat_id]);
        }
        // Drop lapsed entries while we are here.
        foreach ($map as $id => $until) {
            if ((int) $until <= time()) {
                unset($map[$id]);
            }
        }
        update_option(self::OPTION_RISKY, $map);
        return $on ? (int) (self::RISKY_TTL / 60) : 0;
    }

    // ---- pending approvals ------------------------------------------------

    /**
     * Park an instruction behind an Approve button and return its token.
     *
     * The instruction is stored server-side and the button carries only the
     * token, because callback_data is 64 bytes and, more importantly, is
     * attacker-supplied on the way back in — it must not be something we
     * execute.
     */
    public static function stash_approval(string $chat_id, string $instruction, array $blocked): string
    {
        $token   = substr(bin2hex(random_bytes(8)), 0, 16);
        $pending = self::pending();

        $pending[$token] = [
            'chat'        => $chat_id,
            'instruction' => mb_substr($instruction, 0, 4000),
            'blocked'     => array_values($blocked),
            'at'          => time(),
        ];
        self::save_pending($pending);
        return $token;
    }

    /** @return array<string, array>|array the live (unexpired) approvals */
    public static function pending(): array
    {
        $pending = get_option(self::OPTION_PENDING, []);
        $pending = is_array($pending) ? $pending : [];
        $cutoff  = time() - self::APPROVAL_TTL;

        foreach ($pending as $token => $row) {
            if ((int) ($row['at'] ?? 0) < $cutoff) {
                unset($pending[$token]);
            }
        }
        return $pending;
    }

    private static function save_pending(array $pending): void
    {
        // Keep the store small; an owner who ignored 20 buttons does not need
        // the 21st to be remembered either.
        if (count($pending) > 20) {
            uasort($pending, static fn($a, $b) => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));
            $pending = array_slice($pending, 0, 20, true);
        }
        update_option(self::OPTION_PENDING, $pending);
    }

    /**
     * Redeem a token. Single-use: it is deleted whether approved or declined,
     * so a button cannot be pressed twice to run something twice.
     *
     * @return array|null the stashed row, or null when unknown/expired/foreign
     */
    public static function claim_approval(string $token, string $chat_id): ?array
    {
        $pending = self::pending();
        $row     = $pending[$token] ?? null;
        if (!is_array($row)) {
            self::save_pending($pending);
            return null;
        }
        unset($pending[$token]);
        self::save_pending($pending);

        // A token belongs to the chat it was offered to.
        return ((string) ($row['chat'] ?? '') === $chat_id) ? $row : null;
    }

    // ---- Telegram API -----------------------------------------------------

    /** @return array decoded response, or ['ok' => false, 'description' => …] */
    public static function api(string $method, array $params = []): array
    {
        $token = self::token();
        if ($token === '') {
            return ['ok' => false, 'description' => 'No Telegram bot token configured.'];
        }
        $resp = wp_remote_post(self::API_BASE . $token . '/' . $method, [
            'timeout' => 20,
            'body'    => $params,
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'description' => $resp->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return is_array($data) ? $data : ['ok' => false, 'description' => 'Malformed Telegram response.'];
    }

    /**
     * Send a reply, split across messages when it exceeds Telegram's limit.
     *
     * @param array $markup optional reply_markup (inline keyboard)
     * @return int the message_id of the first message sent, 0 on failure
     */
    public static function send(string $chat_id, string $text, array $markup = []): int
    {
        $first  = 0;
        $chunks = self::split_message($text);
        $last   = count($chunks) - 1;

        foreach ($chunks as $i => $chunk) {
            $params = [
                'chat_id'                  => $chat_id,
                'text'                     => $chunk,
                'disable_web_page_preview' => 'true',
            ];
            // A keyboard belongs on the last message, where the reader ends up.
            if ($markup && $i === $last) {
                $params['reply_markup'] = wp_json_encode($markup);
            }
            $res = self::api('sendMessage', $params);
            if ($first === 0) {
                $first = (int) ($res['result']['message_id'] ?? 0);
            }
        }
        return $first;
    }

    /** Rewrite a message already on screen. */
    public static function edit(string $chat_id, int $message_id, string $text, array $markup = []): bool
    {
        if ($message_id <= 0) {
            return false;
        }
        $params = [
            'chat_id'                  => $chat_id,
            'message_id'               => $message_id,
            'text'                     => mb_substr($text, 0, self::MAX_MESSAGE),
            'disable_web_page_preview' => 'true',
        ];
        if ($markup) {
            $params['reply_markup'] = wp_json_encode($markup);
        }
        $res = self::api('editMessageText', $params);
        return !empty($res['ok']);
    }

    /**
     * Edit the progress message, but not more often than the throttle allows.
     * Telegram rate-limits edits, and a run that calls six tools in four seconds
     * would otherwise spend its time being told to slow down.
     */
    public static function progress(string $chat_id, int $message_id, string $text): void
    {
        if ($message_id <= 0) {
            return;
        }
        $key  = $chat_id . ':' . $message_id;
        $last = self::$last_progress[$key] ?? 0;
        if (time() - $last < self::PROGRESS_THROTTLE) {
            return;
        }
        self::$last_progress[$key] = time();
        self::edit($chat_id, $message_id, $text);
    }

    /** The "…is typing" indicator, so a 40-second run doesn't look dead. */
    public static function typing(string $chat_id): void
    {
        self::api('sendChatAction', ['chat_id' => $chat_id, 'action' => 'typing']);
    }

    public static function get_me(): array
    {
        return self::api('getMe');
    }

    public static function webhook_info(): array
    {
        return self::api('getWebhookInfo');
    }

    /**
     * Point the bot at this site. Registering the command menu at the same time
     * means the owner gets the slash-command list in Telegram's own UI instead
     * of having to remember it.
     */
    public static function set_webhook(): array
    {
        $res = self::api('setWebhook', [
            'url'                  => self::webhook_url(),
            'secret_token'         => self::secret(),
            'allowed_updates'      => wp_json_encode(['message', 'callback_query']),
            'drop_pending_updates' => 'true',
        ]);
        if (!empty($res['ok'])) {
            self::register_commands();
        }
        return $res;
    }

    public static function delete_webhook(): array
    {
        return self::api('deleteWebhook', ['drop_pending_updates' => 'true']);
    }

    public static function register_commands(): array
    {
        $commands = [];
        foreach (self::commands() as $cmd => $desc) {
            $commands[] = ['command' => $cmd, 'description' => $desc];
        }
        return self::api('setMyCommands', ['commands' => wp_json_encode($commands)]);
    }

    /** @return array<string, string> command => description */
    public static function commands(): array
    {
        return [
            'help'   => 'What I can do and how to talk to me',
            'clear'  => 'Forget this conversation and start fresh',
            'undo'   => 'Put back the content I changed last turn',
            'status' => 'Site & store snapshot (free, no AI spend)',
            'usage'  => 'Runs, tokens and estimated spend in the last 24h',
            'tasks'  => 'Scheduled automations and when they last ran',
            'model'  => 'Which provider and model I am running on',
            'risky'  => 'on|off — allow destructive/financial tools for a while',
            'whoami' => 'Your chat id, role and whether you are allowlisted',
        ];
    }

    // ---- media ------------------------------------------------------------

    /**
     * Download a file the user sent. Telegram gives out a path first, then the
     * bytes from a different host.
     *
     * @return array{success: bool, bytes?: string, name?: string, error?: string}
     */
    public static function fetch_file(string $file_id): array
    {
        $info = self::api('getFile', ['file_id' => $file_id]);
        $path = (string) ($info['result']['file_path'] ?? '');
        if ($path === '') {
            return ['success' => false, 'error' => (string) ($info['description'] ?? 'Telegram would not hand over that file.')];
        }
        if ((int) ($info['result']['file_size'] ?? 0) > self::MAX_FILE_BYTES) {
            return ['success' => false, 'error' => 'That file is too large for me to fetch.'];
        }

        $resp = wp_remote_get(self::FILE_BASE . self::token() . '/' . $path, ['timeout' => 60]);
        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
            return ['success' => false, 'error' => 'Download failed (HTTP ' . wp_remote_retrieve_response_code($resp) . ').'];
        }

        $bytes = (string) wp_remote_retrieve_body($resp);
        if ($bytes === '') {
            return ['success' => false, 'error' => 'The download came back empty.'];
        }
        return ['success' => true, 'bytes' => $bytes, 'name' => basename($path)];
    }

    /**
     * Turn a voice note into text.
     *
     * @return array{success: bool, text?: string, error?: string}
     */
    public static function transcribe(string $file_id, string $mime): array
    {
        $key = Claudpress_Transcribe_Provider::key();
        if ($key === '') {
            return ['success' => false, 'error' => 'Voice needs a transcription API key (Claudpress → Automation → Chat).'];
        }

        $file = self::fetch_file($file_id);
        if (empty($file['success'])) {
            return ['success' => false, 'error' => (string) $file['error']];
        }

        $s = Claudpress::instance()->settings();
        /** @var class-string<Claudpress_Transcribe_Provider> $p */
        $p = Claudpress_Transcribe_Provider::current();

        return $p::transcribe(
            $key,
            (string) ($s['voice_model'] ?? ''),
            (string) ($s['voice_api_endpoint'] ?? ''),
            (string) $file['bytes'],
            $mime ?: 'audio/ogg',
            (string) ($file['name'] ?? 'voice.ogg')
        );
    }

    /**
     * Which piece of an incoming message is the media we care about, and how to
     * treat it.
     *
     * Telegram sends a photo as an array of rescaled copies, smallest first; the
     * last one is the original, which is the only one worth showing a model.
     *
     * @return array{kind: string, file_id: string, mime: string}|null
     */
    public static function extract_media(array $message): ?array
    {
        if (!empty($message['voice']['file_id'])) {
            return [
                'kind'    => 'voice',
                'file_id' => (string) $message['voice']['file_id'],
                'mime'    => (string) ($message['voice']['mime_type'] ?? 'audio/ogg'),
            ];
        }
        if (!empty($message['audio']['file_id'])) {
            return [
                'kind'    => 'voice',
                'file_id' => (string) $message['audio']['file_id'],
                'mime'    => (string) ($message['audio']['mime_type'] ?? 'audio/mpeg'),
            ];
        }
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $largest = end($message['photo']);
            if (!empty($largest['file_id'])) {
                return ['kind' => 'photo', 'file_id' => (string) $largest['file_id'], 'mime' => 'image/jpeg'];
            }
        }
        // A screenshot sent as a file rather than a photo, so it keeps full size.
        $doc_mime = (string) ($message['document']['mime_type'] ?? '');
        if (!empty($message['document']['file_id']) && strpos($doc_mime, 'image/') === 0) {
            return ['kind' => 'photo', 'file_id' => (string) $message['document']['file_id'], 'mime' => $doc_mime];
        }
        return null;
    }

    /**
     * File an incoming photo in the media library so the agent can actually use
     * it (put it in a post, set it as a product image) rather than only look at
     * it. Failure here is not fatal — vision still works without the upload.
     *
     * @return array{id: int, url: string}|null
     */
    private static function file_photo(string $bytes, string $mime): ?array
    {
        if (!Claudpress_Capabilities::tool_allowed('upload_media')) {
            return null;
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'jpg';

        $res = Claudpress_Tools_Content::upload_media([
            'data'     => base64_encode($bytes),
            'filename' => 'telegram-' . gmdate('Ymd-His') . '.' . $ext,
            'title'    => 'Sent via Telegram ' . current_time('Y-m-d H:i'),
        ]);
        return empty($res['success']) ? null : ['id' => (int) $res['id'], 'url' => (string) $res['url']];
    }

    // ---- inbound ----------------------------------------------------------

    /**
     * Entry point from the REST route. Returns a small array for the HTTP
     * response; Telegram ignores the body but the shape helps debugging and
     * the live smoke test.
     */
    public static function handle_update(array $update): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }

        // Telegram retries a delivery it thinks failed, and an agent run is far
        // too slow to ack inside its patience. update_id is monotonic, so a
        // high-water mark makes a replay a no-op instead of doing the work twice.
        $update_id = (int) ($update['update_id'] ?? 0);
        $last      = (int) get_option(self::OPTION_LAST_UPDATE, 0);
        if ($update_id > 0 && $update_id <= $last) {
            return ['ok' => true, 'reason' => 'duplicate'];
        }
        if ($update_id > 0) {
            update_option(self::OPTION_LAST_UPDATE, $update_id);
        }

        if (is_array($update['callback_query'] ?? null)) {
            return self::handle_callback($update['callback_query']);
        }

        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $chat_id = (string) ($message['chat']['id'] ?? '');
        $text    = trim((string) ($message['text'] ?? ''));

        if ($chat_id === '') {
            return ['ok' => true, 'reason' => 'no chat'];
        }

        if (self::role_for($chat_id) === null) {
            // Tell them their id and nothing else. The owner needs this exact
            // number to allowlist themselves, and it reveals nothing about the
            // site to anyone else who stumbles onto the bot.
            self::send($chat_id, "Not authorised for this site.\n\nYour chat id is " . $chat_id . " — the site owner can add it in WP Admin → Claudpress → Automation.");
            return ['ok' => true, 'reason' => 'not allowed'];
        }

        // Voice notes and photos become text (plus, for photos, an image the
        // model can see) before anything else looks at the message.
        $media = self::extract_media($message);
        $image = null;

        if ($media !== null) {
            $converted = self::absorb_media($chat_id, $media, trim((string) ($message['caption'] ?? '')));
            if (empty($converted['success'])) {
                self::send($chat_id, '⚠️ ' . (string) $converted['error']);
                return ['ok' => true, 'reason' => 'media failed'];
            }
            $text  = (string) $converted['text'];
            $image = $converted['image'] ?? null;
        }

        if ($text === '') {
            self::send($chat_id, 'I got that, but there was nothing in it for me to act on — send some text, a voice note, or a photo with a caption.');
            return ['ok' => true, 'reason' => 'no text'];
        }

        $parsed = self::parse_command($text);
        if ($parsed['cmd'] !== '') {
            $reply = self::run_command($chat_id, $parsed['cmd'], $parsed['args']);
            if ($reply !== null) {
                self::send($chat_id, $reply);
                return ['ok' => true, 'reason' => 'command:' . $parsed['cmd']];
            }
            // Unknown command — fall through and let the agent read it as text.
        }

        return self::run_chat($chat_id, $text, $image);
    }

    /**
     * Convert an incoming voice note or photo into something the agent can work
     * with, telling the owner what we understood on the way.
     *
     * @return array{success: bool, text?: string, image?: array, error?: string}
     */
    private static function absorb_media(string $chat_id, array $media, string $caption): array
    {
        if ($media['kind'] === 'voice') {
            if (!self::voice_enabled()) {
                return ['success' => false, 'error' => 'Voice notes are switched off (or have no transcription key). Send text instead.'];
            }
            self::typing($chat_id);
            $t = self::transcribe($media['file_id'], $media['mime']);
            if (empty($t['success'])) {
                return ['success' => false, 'error' => 'I could not transcribe that: ' . (string) $t['error']];
            }
            // Echo the transcript back: a wrong transcription that silently acts
            // on the site is much worse than one the owner can see and correct.
            self::send($chat_id, '🎙 ' . (string) $t['text']);
            return ['success' => true, 'text' => trim((string) $t['text'])];
        }

        if (!self::photos_enabled()) {
            return ['success' => false, 'error' => 'Photos are switched off. Describe it in words, or enable photos in Claudpress → Automation → Chat.'];
        }

        $file = self::fetch_file($media['file_id']);
        if (empty($file['success'])) {
            return ['success' => false, 'error' => 'I could not download that image: ' . (string) $file['error']];
        }

        $filed = self::file_photo((string) $file['bytes'], $media['mime']);
        $text  = $caption !== '' ? $caption : 'Look at this image and tell me what you see, and what you would do about it on this site.';

        if ($filed !== null) {
            $text .= "\n\n[The image is attached to this message so you can see it. It is also now in the media library as attachment ID "
                . $filed['id'] . ' (' . $filed['url'] . "), so you can use it in a post or product without re-uploading.]";
        } else {
            $text .= "\n\n[The image is attached to this message so you can see it. It was not saved to the media library.]";
        }

        return [
            'success' => true,
            'text'    => $text,
            'image'   => ['mime' => $media['mime'], 'b64' => base64_encode((string) $file['bytes'])],
        ];
    }

    /**
     * The Approve / Leave it buttons.
     *
     * Approving re-runs the original instruction with the guardrail lowered for
     * that one run. It is a fresh run rather than a resumption because the
     * earlier run already ended — and re-running means the model sees current
     * state instead of acting on what was true a minute ago.
     */
    private static function handle_callback(array $cb): array
    {
        $chat_id = (string) ($cb['message']['chat']['id'] ?? '');
        $data    = (string) ($cb['data'] ?? '');
        $cb_id   = (string) ($cb['id'] ?? '');
        $msg_id  = (int) ($cb['message']['message_id'] ?? 0);

        $answer = static function (string $text) use ($cb_id): void {
            if ($cb_id !== '') {
                self::api('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => $text]);
            }
        };

        if ($chat_id === '' || self::role_for($chat_id) === null) {
            $answer('Not authorised.');
            return ['ok' => true, 'reason' => 'callback not allowed'];
        }
        if (!preg_match('/^cp:(ok|no):([a-f0-9]{1,32})$/', $data, $m)) {
            $answer('I do not recognise that button.');
            return ['ok' => true, 'reason' => 'callback unknown'];
        }

        [, $verdict, $token] = $m;
        $row = self::claim_approval($token, $chat_id);

        if ($row === null) {
            $answer('That request has expired.');
            self::edit($chat_id, $msg_id, '⌛ That approval expired. Send the instruction again if you still want it.');
            return ['ok' => true, 'reason' => 'callback expired'];
        }

        if ($verdict === 'no') {
            $answer('Left alone.');
            self::edit($chat_id, $msg_id, '✋ Left alone. Nothing was changed.');
            return ['ok' => true, 'reason' => 'declined'];
        }

        if (!self::may_escalate($chat_id)) {
            $answer('Not permitted.');
            self::edit($chat_id, $msg_id, '🔒 Approvals are locked for this chat, so I did not run it.');
            return ['ok' => true, 'reason' => 'escalation locked'];
        }

        $answer('Running it now.');
        self::edit($chat_id, $msg_id, '✅ Approved — running it now.');

        return self::run_chat($chat_id, (string) $row['instruction'], null, true);
    }

    /**
     * Hand a message to the agent and reply with what it did.
     *
     * @param array|null $image    vision input, when the owner sent a photo
     * @param bool       $approved true when this run came from an Approve press
     */
    private static function run_chat(string $chat_id, string $text, ?array $image = null, bool $approved = false): array
    {
        $lock = 'claudpress_tg_busy_' . md5($chat_id);
        if (get_transient($lock)) {
            self::send($chat_id, '⏳ Still working on your last message. Send this again once I reply.');
            return ['ok' => true, 'reason' => 'busy'];
        }
        set_transient($lock, 1, self::BUSY_TTL);

        $role = (string) (self::role_for($chat_id) ?? 'viewer');
        self::typing($chat_id);
        @set_time_limit(0);

        // One message that becomes the running commentary and then the answer.
        $progress_id = self::progress_enabled() ? self::send($chat_id, '⚙️ Working…') : 0;
        $steps       = [];

        $opts = [
            'history'     => self::history($chat_id, self::history_turns()),
            'interactive' => true,
            'channel'     => 'Telegram',
            'context'     => 'telegram',
            'allow_risky' => $approved || self::risky_allowed($chat_id),
        ];
        if ($image !== null) {
            $opts['image'] = $image;
        }
        if ($role === 'viewer') {
            $opts['allow_tools'] = Claudpress_Capabilities::viewer_tools(Claudpress::instance()->registry->list());
        }
        if ($progress_id > 0) {
            $opts['on_step'] = static function (array $step) use ($chat_id, $progress_id, &$steps): void {
                $steps[] = $step;
                self::progress($chat_id, $progress_id, self::format_progress($steps));
            };
        }

        // Record content changes so /undo has something to put back. Viewers
        // cannot change anything, so there is nothing to record for them.
        if ($role !== 'viewer') {
            Claudpress_Undo::start($chat_id);
        }

        try {
            $run = Claudpress_Agent_Runtime::run($text, $opts);
        } catch (Throwable $e) {
            Claudpress_Undo::finish();
            delete_transient($lock);
            self::deliver($chat_id, $progress_id, '⚠️ Something broke while running that: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'exception'];
        }

        $undoable = Claudpress_Undo::finish();
        delete_transient($lock);

        $reply = self::format_reply($run);
        if ($undoable > 0) {
            $reply .= "\n" . '↩︎ /undo puts back ' . $undoable . ' ' . ($undoable === 1 ? 'item' : 'items') . '.';
        }

        // Only a successful exchange becomes history. Storing a failed turn
        // would feed the model an error transcript it then tries to explain.
        if (!empty($run['success'])) {
            self::remember($chat_id, 'user', $text);
            self::remember($chat_id, 'assistant', (string) ($run['summary'] ?? ''));
        }

        // If policy stopped something, offer the owner the one-tap escalation
        // rather than making them learn the /risky command.
        $blocked = self::blocked_tools($run);
        $markup  = [];
        if (!empty($blocked) && !$approved && self::may_escalate($chat_id)) {
            $token  = self::stash_approval($chat_id, $text, $blocked);
            $reply .= "\n\n🔒 Blocked by your guardrails: " . implode(', ', $blocked) . '.';
            $markup = ['inline_keyboard' => [[
                ['text' => '✅ Approve & run', 'callback_data' => 'cp:ok:' . $token],
                ['text' => '✋ Leave it',      'callback_data' => 'cp:no:' . $token],
            ]]];
        } elseif (!empty($blocked)) {
            $reply .= "\n\n🔒 Blocked by your guardrails: " . implode(', ', $blocked)
                . '. Unlock approvals in WP Admin → Claudpress → Automation → Chat.';
        }

        self::deliver($chat_id, $progress_id, $reply, $markup);
        return ['ok' => true, 'reason' => 'ran', 'success' => !empty($run['success'])];
    }

    /**
     * Put the final answer on screen. The progress message becomes the reply
     * where it fits, so the chat isn't littered with "Working…" leftovers.
     */
    private static function deliver(string $chat_id, int $progress_id, string $text, array $markup = []): void
    {
        $chunks = self::split_message($text);
        if (empty($chunks)) {
            return;
        }

        if ($progress_id > 0 && self::edit($chat_id, $progress_id, $chunks[0], count($chunks) === 1 ? $markup : [])) {
            $rest = array_slice($chunks, 1);
            if (!empty($rest)) {
                self::send($chat_id, implode("\n\n", $rest), $markup);
            }
            return;
        }

        self::send($chat_id, $text, $markup);
    }

    /**
     * Built-in commands. These are answered by the plugin directly, without an
     * API call — /status and /usage in particular exist so the owner can check
     * on the site all day without spending a cent of their key.
     *
     * @return string|null null when the text is not a command we know
     */
    public static function run_command(string $chat_id, string $cmd, string $args): ?string
    {
        $role = (string) (self::role_for($chat_id) ?? 'viewer');

        switch ($cmd) {
            case 'start':
            case 'help':
                $lines = [
                    '👋 I run ' . get_bloginfo('name') . ' (' . home_url() . ').',
                    '',
                    'Just tell me what you want in plain English — "how did the store do this week?", "publish the draft about winter tyres", "the checkout page footer is broken, fix it". I use the site\'s own tools and report back.',
                ];
                if ($role === 'viewer') {
                    $lines[] = '';
                    $lines[] = '👀 This chat is read-only: I can look things up and answer questions, but I cannot change anything.';
                }
                if (self::voice_enabled()) {
                    $lines[] = '';
                    $lines[] = '🎙 Send a voice note and I will transcribe it, show you what I heard, then act on it.';
                }
                if (self::photos_enabled()) {
                    $lines[] = '📷 Send a photo or screenshot (with a caption if you like) and I will look at it and file it in the media library.';
                }
                $lines[] = '';
                $lines[] = 'Commands:';
                foreach (self::commands() as $c => $desc) {
                    $lines[] = '/' . $c . ' — ' . $desc;
                }
                $lines[] = '';
                $lines[] = 'I remember the last ' . self::history_turns() . ' turns of this chat. /clear wipes them.';
                return implode("\n", $lines);

            case 'clear':
            case 'reset':
            case 'new':
                $n = self::clear($chat_id);
                return $n > 0
                    ? '🧹 Cleared ' . $n . ' ' . ($n === 1 ? 'turn' : 'turns') . '. Fresh start — I no longer remember what we were doing.'
                    : '🧹 Nothing to clear; this conversation was already empty.';

            case 'undo':
                if ($role === 'viewer') {
                    return '👀 This chat is read-only, so there is nothing of mine to undo.';
                }
                $res = Claudpress_Undo::revert($chat_id);
                if (empty($res['success']) && empty($res['restored'])) {
                    return '↩︎ ' . (string) ($res['error'] ?? 'Nothing to undo.');
                }
                $out = ['↩︎ Undone.'];
                foreach ((array) $res['restored'] as $line) {
                    $out[] = '• ' . $line;
                }
                foreach ((array) $res['failed'] as $line) {
                    $out[] = '⚠️ could not undo ' . $line;
                }
                $out[] = '';
                $out[] = 'Note: /undo covers content — posts, pages, products. It does not cover settings, plugins, files or live code snippets.';
                return implode("\n", $out);

            case 'status':
                $snapshot = Claudpress_Digest::snapshot();
                return "📊 " . get_bloginfo('name') . "\n\n" . Claudpress_Digest::format_text($snapshot);

            case 'usage':
                $u    = Claudpress_Agent_Runs::usage_since(24);
                $caps = Claudpress_Agent_Runtime::caps();
                $cap  = static fn($v, $fmt) => $v ? sprintf($fmt, $v) : 'unlimited';
                return implode("\n", [
                    '💳 Last 24 hours',
                    'Runs: ' . (int) $u['runs'] . ' of ' . $cap((int) $caps['max_runs_per_day'], '%d'),
                    'Tokens: ' . number_format((int) $u['tokens']),
                    'Estimated spend: $' . number_format((float) $u['cost'], 4) . ' of ' . $cap((float) $caps['max_usd_per_day'], '$%.2f'),
                    '',
                    'Per message: ' . $cap((float) $caps['max_usd_per_run'], '$%.2f') . ' / ' . $cap((int) $caps['max_tokens_per_run'], '%d tokens'),
                    'Costs are estimates from token counts, not your provider\'s bill.',
                ]);

            case 'tasks':
                $tasks = Claudpress_Tasks::all();
                if (empty($tasks)) {
                    return '🗓 No scheduled automations yet. Ask me to create one — "every morning at 7, publish due drafts and email me a summary".';
                }
                $lines = ['🗓 Scheduled automations:'];
                foreach ($tasks as $t) {
                    $when  = (string) $t['schedule'];
                    if ($t['run_hour'] !== null && $t['run_hour'] !== '') {
                        $when .= sprintf(' @ %02d:00', (int) $t['run_hour']);
                    }
                    $lines[] = ((int) $t['enabled'] === 1 ? '✅' : '⏸') . ' ' . $t['name']
                        . ' (' . $t['mode'] . ', ' . $when . ')'
                        . ' — last: ' . ($t['last_run'] ? $t['last_run'] . ' ' . (string) $t['last_status'] : 'never');
                }
                return implode("\n", $lines);

            case 'model':
                $s = Claudpress::instance()->settings();
                /** @var class-string<Claudpress_Provider> $p */
                $p = Claudpress_Provider::current();
                return implode("\n", [
                    '🧠 Provider: ' . $p::label(),
                    'Model: ' . ((string) ($s['agent_model'] ?? '') ?: $p::default_model() . ' (default)'),
                    'API key: ' . (trim((string) ($s['agent_api_key'] ?? '')) !== '' ? 'set' : 'MISSING — I cannot think without one'),
                    'AI abilities: ' . (Claudpress::instance()->abilities_enabled() ? 'ON' : 'OFF — I can read settings but not act'),
                    'Voice: ' . (self::voice_enabled() ? 'on' : 'off') . ' · Photos: ' . (self::photos_enabled() ? 'on' : 'off'),
                ]);

            case 'risky':
                $want = strtolower(trim($args));
                if ($role !== 'admin') {
                    return '🔒 Only an admin chat can unlock destructive tools. This chat is "' . $role . '".';
                }
                if (!Claudpress::instance()->get('telegram_allow_risky', false)) {
                    return '🔒 Destructive and financial tools are locked for Telegram. The owner can unlock them in WP Admin → Claudpress → Automation → Chat.';
                }
                if ($want === 'on') {
                    $mins = self::set_risky($chat_id, true);
                    return '⚠️ Destructive/financial tools unlocked for ' . $mins . ' minutes. They re-lock on their own.';
                }
                if ($want === 'off') {
                    self::set_risky($chat_id, false);
                    return '🔒 Re-locked. Destructive and financial tools are blocked again.';
                }
                return self::risky_allowed($chat_id)
                    ? '⚠️ Currently unlocked. Send /risky off to re-lock.'
                    : '🔒 Currently locked. Send /risky on to unlock for ' . (int) (self::RISKY_TTL / 60) . ' minutes.';

            case 'whoami':
            case 'id':
                $allowed = self::role_for($chat_id) !== null;
                $what    = [
                    'admin'  => 'full access, may approve and unlock risky tools',
                    'editor' => 'may change the site, never destructive or financial tools',
                    'viewer' => 'read-only — can look, cannot touch',
                ][$role] ?? $role;
                return 'Chat id: ' . $chat_id . "\n"
                    . ($allowed ? '✅ allowlisted' : '⛔ not allowlisted') . "\n"
                    . 'Role: ' . $role . ' — ' . $what;

            case 'history':
                $n = self::turn_count($chat_id);
                return '🧠 ' . $n . ' ' . ($n === 1 ? 'turn' : 'turns') . ' stored; I replay the last ' . self::history_turns()
                    . '. /clear wipes them. Undo depth: ' . Claudpress_Undo::depth($chat_id) . '.';
        }

        return null;
    }
}
