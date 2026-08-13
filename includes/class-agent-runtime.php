<?php
/**
 * Autonomous agent runtime. Given a plain-language instruction and an LLM API
 * key (Anthropic Messages API by default), this runs a real agentic loop
 * *inside* WordPress: the model plans, calls Claudpress tools, reads the
 * results, and continues until the task is done — then returns a summary. This
 * is what lets the site run itself on a schedule with no external client open.
 *
 * Every tool call passes through Claudpress_Policy in 'autonomous' context, so
 * an unattended run cannot do something irreversible or costly without opt-in.
 *
 * Unattended means nobody is there to retry a blip or notice a runaway bill, so
 * the loop also: retries transient API failures with exponential backoff, meters
 * token spend against per-run and per-day caps, and records every run to
 * Claudpress_Agent_Runs for the owner to review.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Agent_Runtime
{
    const DEFAULT_MODEL    = 'claude-sonnet-5';
    const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const MAX_STEPS        = 14;

    /** Retry policy for transient API failures. */
    const MAX_ATTEMPTS     = 4;     // per step: 1 try + 3 retries
    const BACKOFF_BASE_MS  = 1000;  // doubles each attempt
    const BACKOFF_CAP_MS   = 30000; // never sleep longer than this on one wait

    /**
     * Approximate list prices in USD per million tokens, matched by model-name
     * prefix (longest match wins). Used only to estimate spend for the caps and
     * the run log — filter 'claudpress_model_prices' to correct or extend it.
     *
     * These are estimates for budgeting, not billing. Vendors change prices
     * without notice; the authoritative number is on your provider invoice.
     *
     * @return array<string, array{in: float, out: float}>
     */
    public static function model_prices(): array
    {
        $prices = [
            // Anthropic
            'claude-opus'    => ['in' => 15.0, 'out' => 75.0],
            'claude-sonnet'  => ['in' => 3.0,  'out' => 15.0],
            'claude-haiku'   => ['in' => 1.0,  'out' => 5.0],
            'claude-fable'   => ['in' => 3.0,  'out' => 15.0],
            'claude-3-opus'  => ['in' => 15.0, 'out' => 75.0],
            'claude-3-haiku' => ['in' => 0.25, 'out' => 1.25],
            // OpenAI
            'gpt-5.1-mini'   => ['in' => 0.25, 'out' => 2.0],
            'gpt-5.1'        => ['in' => 1.25, 'out' => 10.0],
            'gpt-5-mini'     => ['in' => 0.25, 'out' => 2.0],
            'gpt-5'          => ['in' => 1.25, 'out' => 10.0],
            'gpt-4o-mini'    => ['in' => 0.15, 'out' => 0.6],
            'gpt-4o'         => ['in' => 2.5,  'out' => 10.0],
            'o4-mini'        => ['in' => 1.1,  'out' => 4.4],
            // Google
            'gemini-3-pro'   => ['in' => 1.25, 'out' => 10.0],
            'gemini-3-flash' => ['in' => 0.3,  'out' => 2.5],
            'gemini-2.5-pro' => ['in' => 1.25, 'out' => 10.0],
            'gemini-2.5-flash' => ['in' => 0.3, 'out' => 2.5],
        ];
        return (array) apply_filters('claudpress_model_prices', $prices);
    }

    /** Longest-prefix price lookup; falls back to Sonnet-class pricing. */
    public static function price_for_model(string $model): array
    {
        $model = strtolower(trim($model));
        $best  = null;
        $len   = -1;
        foreach (self::model_prices() as $prefix => $price) {
            if (strpos($model, $prefix) === 0 && strlen($prefix) > $len) {
                $best = $price;
                $len  = strlen($prefix);
            }
        }
        return $best ?: ['in' => 3.0, 'out' => 15.0];
    }

    /** Estimated USD for a token count on a given model. */
    public static function estimate_cost(string $model, int $input_tokens, int $output_tokens): float
    {
        $p = self::price_for_model($model);
        return round((($input_tokens / 1000000) * $p['in']) + (($output_tokens / 1000000) * $p['out']), 6);
    }

    /**
     * Which failures are worth trying again. Rate limits, overload, and 5xx are
     * transient; 4xx (bad key, bad request) will fail identically forever.
     *
     * @param int|null $http_code null = transport-level failure (DNS, timeout)
     */
    public static function should_retry(?int $http_code): bool
    {
        if ($http_code === null) {
            return true; // network/transport error
        }
        if ($http_code === 408 || $http_code === 429) {
            return true;
        }
        return $http_code >= 500 && $http_code < 600;
    }

    /**
     * Backoff in milliseconds for a given attempt (0-indexed), with jitter.
     * A server-sent Retry-After always wins, capped so a bad header cannot
     * stall a cron run indefinitely.
     */
    public static function backoff_delay_ms(int $attempt, ?int $retry_after_seconds = null, ?float $jitter = null): int
    {
        if ($retry_after_seconds !== null && $retry_after_seconds > 0) {
            return (int) min($retry_after_seconds * 1000, self::BACKOFF_CAP_MS);
        }
        $base = self::BACKOFF_BASE_MS * (2 ** max(0, $attempt));
        $base = (int) min($base, self::BACKOFF_CAP_MS);
        // Full jitter: sleep somewhere in [50%, 100%] of the window.
        $j = $jitter === null ? (mt_rand(500, 1000) / 1000) : max(0.0, min(1.0, $jitter));
        return (int) max(1, round($base * (0.5 + ($j * 0.5))));
    }

    /** Cap settings, merged over defaults. 0 = unlimited. */
    public static function caps(): array
    {
        $s = Claudpress::instance()->settings();
        return [
            'max_usd_per_run'    => (float) ($s['agent_max_usd_per_run'] ?? 0.50),
            'max_tokens_per_run' => (int) ($s['agent_max_tokens_per_run'] ?? 300000),
            'max_usd_per_day'    => (float) ($s['agent_max_usd_per_day'] ?? 5.00),
            'max_runs_per_day'   => (int) ($s['agent_max_runs_per_day'] ?? 48),
        ];
    }

    /**
     * Pure cap evaluation — given caps, the spend so far in this run, and the
     * trailing-24h usage, return a human-readable stop reason or null.
     *
     * @param array $caps     from caps()
     * @param array $run      ['cost'=>float,'tokens'=>int]
     * @param array $day      ['cost'=>float,'runs'=>int] trailing 24h (excluding this run)
     * @param bool  $starting true when checked before the first API call
     */
    public static function cap_exceeded(array $caps, array $run, array $day, bool $starting = false): ?string
    {
        if ($starting) {
            if (!empty($caps['max_runs_per_day']) && ($day['runs'] ?? 0) >= $caps['max_runs_per_day']) {
                return sprintf('Daily run cap reached (%d runs in the last 24h). Raise it in Claudpress > Automation.', (int) $caps['max_runs_per_day']);
            }
            if (!empty($caps['max_usd_per_day']) && ($day['cost'] ?? 0) >= $caps['max_usd_per_day']) {
                return sprintf('Daily spend cap reached ($%.2f of $%.2f in the last 24h). Raise it in Claudpress > Automation.', (float) ($day['cost'] ?? 0), (float) $caps['max_usd_per_day']);
            }
            return null;
        }
        if (!empty($caps['max_usd_per_run']) && ($run['cost'] ?? 0) >= $caps['max_usd_per_run']) {
            return sprintf('Run spend cap reached ($%.2f of $%.2f).', (float) ($run['cost'] ?? 0), (float) $caps['max_usd_per_run']);
        }
        if (!empty($caps['max_tokens_per_run']) && ($run['tokens'] ?? 0) >= $caps['max_tokens_per_run']) {
            return sprintf('Run token cap reached (%s of %s tokens).', number_format((int) ($run['tokens'] ?? 0)), number_format((int) $caps['max_tokens_per_run']));
        }
        if (!empty($caps['max_usd_per_day']) && (($day['cost'] ?? 0) + ($run['cost'] ?? 0)) >= $caps['max_usd_per_day']) {
            return sprintf('Daily spend cap reached ($%.2f of $%.2f).', (float) (($day['cost'] ?? 0) + ($run['cost'] ?? 0)), (float) $caps['max_usd_per_day']);
        }
        return null;
    }

    /**
     * Normalize stored chat history into provider messages.
     *
     * Turns arrive as ['role' => 'user'|'assistant', 'text' => string]. Blank
     * turns are dropped (an agent step that only called tools produces no text,
     * and an empty message is a hard API error), and the run always ends on the
     * new user instruction, so a trailing stored user turn is harmless.
     *
     * @param class-string<Claudpress_Provider> $provider
     */
    public static function history_messages(string $provider, array $history): array
    {
        $out = [];
        foreach ($history as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = (($turn['role'] ?? 'user') === 'assistant')
                ? $provider::assistant_text_message($text)
                : $provider::user_message($text);
        }
        return $out;
    }

    /**
     * @param array $opts allow_risky(bool), max_steps(int), model(string), context(string label), task_id(int),
     *                    history(array of ['role','text']), interactive(bool), channel(string),
     *                    image(['mime','b64']), allow_tools(string[] whitelist), on_step(callable)
     * @return array {success, summary, steps, tool_calls, usage, error?}
     */
    public static function run(string $instruction, array $opts = []): array
    {
        $s   = Claudpress::instance()->settings();
        $key = (string) ($s['agent_api_key'] ?? '');
        if ($key === '') {
            return ['success' => false, 'error' => 'No agent API key configured. Add a provider API key in WP Admin > Claudpress > Automation to enable autonomous runs.'];
        }
        if (!Claudpress::instance()->abilities_enabled()) {
            return ['success' => false, 'error' => 'AI abilities are disabled; autonomous runs are paused. Enable abilities in Claudpress settings.'];
        }

        /** @var class-string<Claudpress_Provider> $provider */
        $provider  = Claudpress_Provider::current();
        // A blank endpoint means "use the provider default"; a non-blank one is
        // an owner override for a gateway, proxy or self-hosted deployment.
        $endpoint  = trim((string) ($s['agent_api_endpoint'] ?? '')) ?: $provider::default_endpoint();
        $model     = (string) ($opts['model'] ?? ($s['agent_model'] ?? '')) ?: $provider::default_model();
        $max_steps = (int) ($opts['max_steps'] ?? self::MAX_STEPS);
        $context   = (string) ($opts['context'] ?? 'manual');
        $task_opts = ['allow_risky' => !empty($opts['allow_risky'])];

        // ---- budget gate (before we spend a cent) ----
        $caps = self::caps();
        $day  = Claudpress_Agent_Runs::usage_since(24);
        $stop = self::cap_exceeded($caps, ['cost' => 0, 'tokens' => 0], $day, true);
        if ($stop !== null) {
            return ['success' => false, 'error' => $stop, 'cost_capped' => true, 'usage_24h' => $day];
        }

        $run_id   = Claudpress_Agent_Runs::start($instruction, $context, $model, (int) ($opts['task_id'] ?? 0) ?: null);
        $registry = Claudpress::instance()->registry;

        // A whitelist (the read-only chat role) is applied to what the model is
        // even told about, as well as to dispatch below. Offering a tool and
        // then refusing it wastes a step and reads as a malfunction.
        $allow_tools = isset($opts['allow_tools']) && is_array($opts['allow_tools']) ? $opts['allow_tools'] : null;
        if ($allow_tools === []) {
            // An empty whitelist is not "no restrictions" and must never be read
            // as one. Some vendors also reject an empty tools array outright, so
            // say what happened rather than surfacing a confusing API error.
            Claudpress_Agent_Runs::finish($run_id, ['status' => 'error', 'summary' => 'No tools available to this actor.']);
            return ['success' => false, 'error' => 'There are no tools available to this conversation, so there is nothing I can do here.'];
        }
        $tools = self::tools_for_api($registry, $allow_tools);
        $on_step     = isset($opts['on_step']) && is_callable($opts['on_step']) ? $opts['on_step'] : null;

        $messages = self::history_messages($provider, (array) ($opts['history'] ?? []));
        $image    = is_array($opts['image'] ?? null) ? $opts['image'] : null;
        $messages[] = ($image && !empty($image['b64']))
            ? $provider::user_image_message($instruction, (string) ($image['mime'] ?? 'image/jpeg'), (string) $image['b64'])
            : $provider::user_message($instruction);
        $tool_calls = [];
        $final_text = '';
        $usage      = ['input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0, 'tokens' => 0, 'retries' => 0];
        $capped     = null;
        $step       = 0;
        $system     = self::system_prompt(!empty($opts['interactive']), (string) ($opts['channel'] ?? ''));

        for ($step = 0; $step < $max_steps; $step++) {
            $resp = self::call_api($provider, $endpoint, $key, $model, $system, $tools, $messages);
            $usage['retries'] += (int) ($resp['_retries'] ?? 0);

            if (!empty($resp['error'])) {
                $out = ['success' => false, 'error' => 'LLM API error: ' . $resp['error'], 'steps' => $step, 'tool_calls' => $tool_calls, 'usage' => self::public_usage($usage)];
                self::record($run_id, 'error', $usage, $step, $tool_calls, (string) $resp['error'], $out);
                return $out;
            }

            $parsed = $provider::parse($resp);

            // Meter this step's tokens before deciding whether to continue.
            $usage['input_tokens']  += (int) $parsed['usage']['input'];
            $usage['output_tokens'] += (int) $parsed['usage']['output'];
            $usage['tokens']         = $usage['input_tokens'] + $usage['output_tokens'];
            $usage['cost']           = self::estimate_cost($model, $usage['input_tokens'], $usage['output_tokens']);

            $final_text .= $parsed['text'];

            if (empty($parsed['wants_tools'])) {
                $out = ['success' => true, 'summary' => trim($final_text), 'steps' => $step + 1, 'tool_calls' => $tool_calls, 'usage' => self::public_usage($usage)];
                self::record($run_id, 'ok', $usage, $step + 1, $tool_calls, trim($final_text), $out);
                return $out;
            }

            // Record assistant turn, then execute each call and feed results back.
            $messages[]   = $provider::assistant_message($parsed);
            $tool_results = [];

            foreach ($parsed['tool_calls'] as $call) {
                $name = (string) $call['name'];
                $args = is_array($call['args']) ? $call['args'] : [];

                if ($allow_tools !== null && !in_array($name, $allow_tools, true)) {
                    $result  = ['success' => false, 'error' => 'This conversation is read-only; that tool is not available here.', 'policy_blocked' => true];
                    $blocked = 'read-only conversation';
                } elseif (!Claudpress_Capabilities::tool_allowed($name)) {
                    $result  = ['success' => false, 'error' => 'That capability is turned off by the site owner.', 'policy_blocked' => true];
                    $blocked = 'capability off';
                } else {
                    $blocked = Claudpress_Policy::check($name, $args, 'autonomous', $task_opts);
                    if ($blocked !== null) {
                        $result = ['success' => false, 'error' => $blocked, 'policy_blocked' => true];
                    } elseif (!$registry->has($name)) {
                        $result = ['success' => false, 'error' => 'Unknown tool: ' . $name];
                    } else {
                        try {
                            $result = $registry->call($name, $args);
                        } catch (Throwable $e) {
                            $result = ['success' => false, 'error' => $e->getMessage()];
                        }
                    }
                }

                $tool_calls[]   = [
                    'tool'    => $name,
                    'blocked' => $blocked !== null,
                    'ok'      => !(isset($result['success']) && $result['success'] === false),
                ] + ($blocked !== null ? ['reason' => $blocked] : []);
                $tool_results[] = ['id' => (string) $call['id'], 'name' => $name, 'result' => $result];
            }

            foreach ($provider::tool_result_message($tool_results) as $m) {
                $messages[] = $m;
            }

            // Progress callback, for a caller that shows the work as it happens
            // (the Telegram front-end edits one message in place). A throwing
            // callback must not take the run down with it — the work is already
            // done and the summary still matters.
            if ($on_step !== null) {
                try {
                    $on_step([
                        'step'  => $step + 1,
                        'tools' => array_map(static fn($r) => $r['name'], $tool_results),
                        'text'  => trim((string) $parsed['text']),
                    ]);
                } catch (Throwable $e) {
                    // Deliberately swallowed; progress reporting is cosmetic.
                }
            }

            // Budget check between steps: stop cleanly rather than mid-tool-call.
            $capped = self::cap_exceeded($caps, $usage, $day, false);
            if ($capped !== null) {
                break;
            }
        }

        if ($capped !== null) {
            $summary = trim($final_text) ?: 'Stopped before finishing.';
            $out = [
                'success' => true, 'summary' => $summary . "\n\n[Stopped early: " . $capped . ']',
                'steps' => $step + 1, 'tool_calls' => $tool_calls, 'usage' => self::public_usage($usage),
                'cost_capped' => true, 'cap_reason' => $capped,
            ];
            self::record($run_id, 'capped', $usage, $step + 1, $tool_calls, $out['summary'], $out);
            return $out;
        }

        $out = [
            'success' => true, 'summary' => trim($final_text) ?: 'Reached the step limit before finishing.',
            'steps' => $max_steps, 'tool_calls' => $tool_calls, 'usage' => self::public_usage($usage), 'truncated' => true,
        ];
        self::record($run_id, 'truncated', $usage, $max_steps, $tool_calls, $out['summary'], $out);
        return $out;
    }

    private static function public_usage(array $u): array
    {
        return [
            'input_tokens'  => (int) $u['input_tokens'],
            'output_tokens' => (int) $u['output_tokens'],
            'total_tokens'  => (int) $u['tokens'],
            'cost_usd'      => round((float) $u['cost'], 5),
            'retries'       => (int) $u['retries'],
        ];
    }

    private static function record(int $run_id, string $status, array $usage, int $steps, array $tool_calls, string $summary, array $detail): void
    {
        Claudpress_Agent_Runs::finish($run_id, [
            'status'        => $status,
            'steps'         => $steps,
            'tool_calls'    => count($tool_calls),
            'retries'       => (int) $usage['retries'],
            'input_tokens'  => (int) $usage['input_tokens'],
            'output_tokens' => (int) $usage['output_tokens'],
            'cost_usd'      => (float) $usage['cost'],
            'summary'       => $summary,
            'detail'        => ['tools' => $tool_calls] + array_intersect_key($detail, ['error' => 1, 'cap_reason' => 1, 'truncated' => 1]),
        ]);
    }

    /**
     * @param bool   $interactive true when a human is waiting on the other end
     *                            (Telegram chat) rather than a cron run
     * @param string $channel     label for the surface, e.g. 'Telegram'
     */
    private static function system_prompt(bool $interactive = false, string $channel = ''): string
    {
        $mem = [];
        foreach (Claudpress::instance()->memory->all() as $m) {
            $mem[] = '- ' . $m['key'] . ': ' . $m['value'];
        }
        $memory_block = $mem ? "\n\nSite memory (conventions & context):\n" . implode("\n", array_slice($mem, 0, 40)) : '';

        if ($interactive) {
            return self::interactive_prompt($channel) . $memory_block;
        }

        return implode("\n", [
            'You are the Claudpress autonomous operator for the WordPress/WooCommerce site "' . get_bloginfo('name') . '" (' . home_url() . ').',
            'You run UNATTENDED on a schedule — no human will answer questions mid-run, so never ask; make sound decisions and act, then report.',
            '',
            // The same protocol interactive sessions get: one definition, so a
            // scheduled run cannot quietly hold itself to a looser standard.
            Claudpress_MCP_Server::operating_protocol(),
            '',
            'BECAUSE THIS RUN IS UNATTENDED, additionally:',
            '- Nobody can catch a mistake before it reaches visitors. When a choice is between a reversible action and an irreversible one, take the reversible one and note the alternative in your summary.',
            '- Some destructive/financial tools are blocked by policy unless this task opted in. If a tool comes back policy_blocked, that is the owner\'s decision — adapt or report it. Do not retry it, and do not reach for execute_php/execute_sql to accomplish the same thing.',
            '- You have a spend budget and a step limit. Be efficient: batch your work, avoid redundant reads, and do not re-fetch data you already have. Getting a smaller job fully done beats leaving a big one half-applied.',
            '- Leave the site in a working state at every step. Never end a run mid-migration, with a broken template live, or with a half-updated set of products.',
            '',
            'Keep going until the task is complete or truly blocked, then stop and write a concise plain-text summary of what you did, what changed, what you skipped, and anything the owner should review.',
        ]) . $memory_block;
    }

    /**
     * The prompt for a live conversation. The unattended rules are wrong here in
     * one specific way: "never ask" is the right call at 3am and the wrong call
     * when the owner is holding their phone. Everything else — protocol, policy,
     * leave-it-working — still applies, so it is the same body with the
     * unattended clauses swapped for chat ones.
     */
    private static function interactive_prompt(string $channel): string
    {
        $where = $channel !== '' ? ' over ' . $channel : '';

        return implode("\n", [
            'You are the Claudpress operator for the WordPress/WooCommerce site "' . get_bloginfo('name') . '" (' . home_url() . ').',
            'You are in a LIVE conversation with the site owner' . $where . '. They are waiting on their phone right now.',
            '',
            Claudpress_MCP_Server::operating_protocol(),
            '',
            'BECAUSE THIS IS A LIVE CHAT, additionally:',
            '- You MAY ask one short clarifying question when a request is genuinely ambiguous and guessing wrong would be expensive or hard to undo. Otherwise just do the work — do not check in on every step.',
            '- Answer in plain text for a phone screen: short paragraphs, no markdown tables, no code fences unless the owner asked for code. A few lines is usually right; write more only when the answer genuinely needs it.',
            '- Say what you actually changed, and name anything you could not do. Never claim work you did not verify.',
            '- Some destructive/financial tools may be blocked by policy. If a tool comes back policy_blocked, tell the owner plainly which switch to flip rather than routing around it with execute_php/execute_sql.',
            '- You have a spend budget and a step limit per message. Be efficient; if a job is too big for one message, do the first useful slice and tell them what remains.',
            '- Leave the site in a working state before you reply. Never end a turn with a broken template live or a half-applied change.',
            '',
            'Earlier turns of this conversation are included above as plain text. Tool results from those turns are not replayed, so re-read the site if you need current state rather than trusting your own earlier summary.',
        ]);
    }

    /**
     * Registry tools in the neutral shape the provider adapters consume.
     * Each adapter maps 'schema' onto its vendor's parameter field.
     */
    private static function tools_for_api(Claudpress_Tool_Registry $registry, ?array $allow_tools = null): array
    {
        $tools = [];
        foreach ($registry->list() as $t) {
            if (!Claudpress_Capabilities::tool_allowed($t['name'])) {
                continue; // owner switched this capability off
            }
            if ($allow_tools !== null && !in_array($t['name'], $allow_tools, true)) {
                continue; // restricted actor (e.g. a read-only chat role)
            }
            $schema = $t['inputSchema'];
            if (empty($schema['type'])) {
                $schema = ['type' => 'object', 'properties' => (object) []];
            }
            $tools[] = [
                'name'        => $t['name'],
                'description' => mb_substr((string) $t['description'], 0, 1000),
                'schema'      => $schema,
            ];
        }
        return $tools;
    }

    /**
     * One provider API call, with retry/backoff around transient failures.
     * Adds a '_retries' key so the caller can account for them.
     *
     * @param class-string<Claudpress_Provider> $provider
     */
    private static function call_api(string $provider, string $endpoint, string $key, string $model, string $system, array $tools, array $messages): array
    {
        $attempts = (int) apply_filters('claudpress_agent_max_attempts', self::MAX_ATTEMPTS);
        $attempts = max(1, min(8, $attempts));
        $retries  = 0;
        $last     = 'unknown error';

        $url  = $provider::url($endpoint, $model);
        $body = $provider::body($model, $system, $tools, $messages);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                $retries++;
            }

            $resp = wp_remote_post($url, [
                'timeout' => 120,
                'headers' => $provider::headers($key),
                'body'    => wp_json_encode($body),
            ]);

            if (is_wp_error($resp)) {
                $last = $resp->get_error_message();
                $code = null;
            } else {
                $code = (int) wp_remote_retrieve_response_code($resp);
                if ($code >= 200 && $code < 300) {
                    $data = json_decode(wp_remote_retrieve_body($resp), true);
                    if (!is_array($data)) {
                        return ['error' => 'Malformed API response', '_retries' => $retries];
                    }
                    $data['_retries'] = $retries;
                    return $data;
                }
                $data = json_decode(wp_remote_retrieve_body($resp), true);
                $msg  = is_array($data) ? $provider::error_message($data) : '';
                $msg  = $msg !== '' ? $msg : wp_remote_retrieve_body($resp);
                $last = 'HTTP ' . $code . ': ' . mb_substr((string) $msg, 0, 400);
            }

            $is_last = ($attempt === $attempts - 1);
            if ($is_last || !self::should_retry($code)) {
                break;
            }

            $retry_after = null;
            if (!is_wp_error($resp)) {
                $hdr = wp_remote_retrieve_header($resp, 'retry-after');
                if (is_string($hdr) && is_numeric($hdr)) {
                    $retry_after = (int) $hdr;
                }
            }
            $wait_ms = self::backoff_delay_ms($attempt, $retry_after);
            Claudpress::instance()->logger->record('agent_retry', ['attempt' => $attempt + 1, 'wait_ms' => $wait_ms], ['success' => false, 'error' => $last]);
            usleep($wait_ms * 1000);
        }

        return ['error' => $last . ($retries ? sprintf(' (after %d %s)', $retries, $retries === 1 ? 'retry' : 'retries') : ''), '_retries' => $retries];
    }
}
