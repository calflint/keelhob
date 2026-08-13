<?php
/**
 * One-step undo for content changes made during a chat turn.
 *
 * The honest scope: this covers **posts** — every post type, including pages and
 * products — created, edited, or trashed while recording is on. That is where
 * "wait, put that back" actually happens, and it is the one thing WordPress
 * gives us reliable hooks for.
 *
 * It deliberately does NOT cover options, users, plugin state, files, live code
 * snippets, or WooCommerce settings. A half-working undo is worse than none,
 * because the owner trusts it and finds out later — so /undo says plainly what
 * it restored and what it could not.
 *
 * Recording works off WordPress core hooks rather than the tool layer, so a
 * change made through execute_php is captured just the same as one made through
 * update_post.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Undo
{
    const OPTION = 'claudpress_undo_stack';

    /** How many undoable turns to keep per scope (a scope is usually a chat id). */
    const KEEP = 5;

    /** Post types whose churn is noise, not a change the owner made. */
    const IGNORED_TYPES = ['revision', 'nav_menu_item', 'oembed_cache', 'customize_changeset', 'attachment'];

    /** @var string|null the scope currently recording, or null */
    private static $scope = null;

    /** @var array<int, array> buffered entries for the current recording */
    private static $buffer = [];

    /** @var bool guards against double-hooking within one request */
    private static $hooked = false;

    /**
     * Begin recording. Idempotent per request: the hooks attach once, and the
     * buffer resets so two turns in one request cannot bleed together.
     */
    public static function start(string $scope): void
    {
        self::$scope  = $scope;
        self::$buffer = [];

        if (self::$hooked) {
            return;
        }
        // Priority 1 for pre_post_update: snapshot before anything else can
        // alter $data on the way through.
        add_action('pre_post_update', [self::class, 'on_pre_update'], 1, 2);
        add_action('wp_insert_post', [self::class, 'on_insert'], 99, 3);
        add_action('wp_trash_post', [self::class, 'on_trash'], 1, 1);
        self::$hooked = true;
    }

    /** Stop recording and push the buffer onto the scope's stack. */
    public static function finish(): int
    {
        $scope  = self::$scope;
        $buffer = self::$buffer;
        self::$scope  = null;
        self::$buffer = [];

        if ($scope === null || empty($buffer)) {
            return 0;
        }

        $stack = self::stack();
        $stack[$scope] = $stack[$scope] ?? [];
        $stack[$scope][] = ['at' => current_time('mysql'), 'entries' => array_values($buffer)];
        $stack[$scope] = array_slice($stack[$scope], -self::KEEP);
        update_option(self::OPTION, $stack);

        return count($buffer);
    }

    public static function recording(): bool
    {
        return self::$scope !== null;
    }

    /** @return array<string, array<int, array>> */
    public static function stack(): array
    {
        $stack = get_option(self::OPTION, []);
        return is_array($stack) ? $stack : [];
    }

    /** How many undoable turns this scope has banked. */
    public static function depth(string $scope): int
    {
        return count(self::stack()[$scope] ?? []);
    }

    public static function clear(string $scope): void
    {
        $stack = self::stack();
        unset($stack[$scope]);
        update_option(self::OPTION, $stack);
    }

    // ---- recording hooks --------------------------------------------------

    /**
     * Snapshot a post's editable fields before an update lands.
     *
     * Only the first snapshot per post is kept: if a turn edits the same post
     * three times, undo should return it to how it looked before the turn, not
     * to how it looked between the second and third edit.
     */
    public static function on_pre_update($post_id, $data = null): void
    {
        if (self::$scope === null) {
            return;
        }
        $post_id = (int) $post_id;
        if (isset(self::$buffer['post:' . $post_id])) {
            return;
        }
        $post = get_post($post_id);
        if (!$post || in_array($post->post_type, self::IGNORED_TYPES, true)) {
            return;
        }
        self::$buffer['post:' . $post_id] = [
            'kind'     => 'updated',
            'id'       => $post_id,
            'type'     => $post->post_type,
            'title'    => $post->post_title,
            'snapshot' => [
                'post_title'   => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_status'  => $post->post_status,
                'post_name'    => $post->post_name,
            ],
        ];
    }

    public static function on_insert($post_id, $post = null, $update = null): void
    {
        if (self::$scope === null || $update) {
            return;
        }
        $post_id = (int) $post_id;
        if (isset(self::$buffer['post:' . $post_id])) {
            return;
        }
        $type = is_object($post) ? (string) $post->post_type : (string) get_post_type($post_id);
        if (in_array($type, self::IGNORED_TYPES, true)) {
            return;
        }
        // auto-draft is WordPress reserving an ID, not the agent creating a post.
        if (is_object($post) && $post->post_status === 'auto-draft') {
            return;
        }
        self::$buffer['post:' . $post_id] = [
            'kind'  => 'created',
            'id'    => $post_id,
            'type'  => $type,
            'title' => is_object($post) ? (string) $post->post_title : '',
        ];
    }

    public static function on_trash($post_id): void
    {
        if (self::$scope === null) {
            return;
        }
        $post_id = (int) $post_id;
        $post    = get_post($post_id);
        if (!$post || in_array($post->post_type, self::IGNORED_TYPES, true)) {
            return;
        }
        // A trash overrides an earlier snapshot of the same post: untrashing is
        // the move that puts it back, and it restores the content with it.
        self::$buffer['post:' . $post_id] = [
            'kind'  => 'trashed',
            'id'    => $post_id,
            'type'  => $post->post_type,
            'title' => $post->post_title,
        ];
    }

    // ---- reverting --------------------------------------------------------

    /**
     * Undo the most recent recorded turn for a scope.
     *
     * @return array{success: bool, error?: string, at?: string, restored: array<int, string>, failed: array<int, string>}
     */
    public static function revert(string $scope): array
    {
        $stack = self::stack();
        $turns = $stack[$scope] ?? [];
        if (empty($turns)) {
            return ['success' => false, 'error' => 'Nothing to undo — I have not changed any content in this conversation.', 'restored' => [], 'failed' => []];
        }

        $turn     = array_pop($turns);
        $restored = [];
        $failed   = [];

        // Recording must be off while we revert, or the undo would record
        // itself as the next undoable turn.
        $was = self::$scope;
        self::$scope = null;

        foreach ((array) ($turn['entries'] ?? []) as $entry) {
            $id    = (int) ($entry['id'] ?? 0);
            $label = ($entry['type'] ?? 'post') . ' #' . $id . (!empty($entry['title']) ? ' "' . $entry['title'] . '"' : '');

            if ($id <= 0 || !get_post($id)) {
                $failed[] = $label . ' (no longer exists)';
                continue;
            }

            switch ($entry['kind'] ?? '') {
                case 'created':
                    if (wp_trash_post($id)) {
                        $restored[] = 'trashed the new ' . $label;
                    } else {
                        $failed[] = $label . ' (could not be trashed)';
                    }
                    break;

                case 'updated':
                    $res = wp_update_post(array_merge(['ID' => $id], (array) ($entry['snapshot'] ?? [])), true);
                    if (is_wp_error($res)) {
                        $failed[] = $label . ' (' . $res->get_error_message() . ')';
                    } else {
                        $restored[] = 'reverted ' . $label;
                    }
                    break;

                case 'trashed':
                    if (wp_untrash_post($id)) {
                        $restored[] = 'restored ' . $label;
                    } else {
                        $failed[] = $label . ' (could not be untrashed)';
                    }
                    break;

                default:
                    $failed[] = $label . ' (unknown change type)';
            }
        }

        self::$scope = $was;

        $stack[$scope] = $turns;
        update_option(self::OPTION, $stack);

        return [
            'success'  => empty($failed),
            'at'       => (string) ($turn['at'] ?? ''),
            'restored' => $restored,
            'failed'   => $failed,
        ];
    }
}
