<?php
/**
 * Code Snippets engine — WPCode / Code-Snippets style. The connected AI (or the
 * admin) can save PHP / CSS / JS / HTML that actually runs on the site and
 * changes its behaviour and appearance, without editing theme files.
 *
 * Safety: every active PHP snippet runs inside a crash guard. If a snippet
 * triggers a fatal error, a shutdown handler deactivates that exact snippet so
 * the next request loads cleanly — the site never gets stuck in a fatal loop.
 * This mirrors what WPCode calls "safe error handling".
 *
 * Locations: everywhere, admin_only, frontend_only, header (wp_head),
 * footer (wp_footer), before_content, after_content, shortcode, page_template
 * (serve raw PHP/HTML as a full page template for a specific page — the
 * "pixel-perfect custom-coded page" build mode).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Snippets
{
    /** @var int|null id of the snippet currently executing, for crash attribution */
    private static $running = null;

    /** @var bool guards against double-registering shutdown handler */
    private static $shutdown_registered = false;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_snippets';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            code LONGTEXT NOT NULL,
            snippet_type VARCHAR(20) NOT NULL DEFAULT 'php',
            location VARCHAR(40) NOT NULL DEFAULT 'everywhere',
            target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            priority INT NOT NULL DEFAULT 10,
            active TINYINT(1) NOT NULL DEFAULT 0,
            conditions_json LONGTEXT NULL,
            last_error TEXT NULL,
            uid VARCHAR(40) NOT NULL DEFAULT '',
            tags VARCHAR(191) NOT NULL DEFAULT '',
            purpose TEXT NULL,
            impact TEXT NULL,
            origin_site VARCHAR(191) NOT NULL DEFAULT '',
            origin_uid VARCHAR(40) NOT NULL DEFAULT '',
            created_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY location (location),
            KEY active (active),
            KEY uid (uid),
            KEY origin_uid (origin_uid)
        ) {$charset};");
    }

    /**
     * Give every legacy row a portable identity. Idempotent — safe to call on
     * every upgrade and activation.
     *
     * `uid` is deliberately a plain KEY, never UNIQUE: before this runs every
     * pre-1.9 row holds the same '' default, and a UNIQUE index would simply
     * fail to build, leaving the column silently unindexed.
     */
    public static function backfill(): void
    {
        global $wpdb;
        $table = self::table();

        $ids = $wpdb->get_col("SELECT id FROM {$table} WHERE uid = '' OR uid IS NULL");
        foreach (($ids ?: []) as $id) {
            $wpdb->update($table, ['uid' => self::new_uid()], ['id' => (int) $id]);
        }
        $wpdb->query("UPDATE {$table} SET created_at = updated_at WHERE created_at IS NULL");
    }

    /** Portable, site-independent identity for a snippet. */
    public static function new_uid(): string
    {
        return 'cps_' . bin2hex(random_bytes(8));
    }

    /**
     * Normalize tags to a sentinel-wrapped comma string (",seo,perf,").
     *
     * The sentinels are what make filtering exact: LIKE '%,seo,%' matches the
     * tag `seo` and not `seo-audit`. Empty input stays '' (no lone sentinel).
     *
     * @param array<int, string>|string|null $in
     */
    public static function normalize_tags($in): string
    {
        if (is_string($in)) {
            $in = explode(',', $in);
        }
        if (!is_array($in)) {
            return '';
        }
        $out = [];
        foreach ($in as $tag) {
            $tag = substr(strtolower(trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $tag))), 0, 32);
            $tag = trim(preg_replace('/[\s_]+/', '-', $tag), '-');
            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
            if (count($out) >= 10) {
                break;
            }
        }
        return $out ? ',' . implode(',', $out) . ',' : '';
    }

    /** @return array<int, string> */
    public static function tags_array(?string $stored): array
    {
        $stored = trim((string) $stored, ',');
        return $stored === '' ? [] : explode(',', $stored);
    }

    /**
     * Pre-flight parse check for a snippet body.
     *
     * Uses token_get_all(..., TOKEN_PARSE) rather than shell_exec('php -l'),
     * matching Claudpress_Tools_Filesystem — many hosts disable shell functions
     * outright. Snippet PHP carries no opening tag, so one is prefixed; a
     * page_template body is raw markup with <?php islands and lints as-is.
     *
     * @return string '' when the code parses, otherwise the parse error message
     */
    public static function lint_php(string $code, string $type = 'php', string $location = 'everywhere'): string
    {
        if ($type !== 'php' || trim($code) === '') {
            return '';
        }
        $source = $location === 'page_template' ? $code : "<?php " . $code;
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $e->getMessage();
        }
        return '';
    }

    /**
     * Decide which existing row a save() payload refers to, if any.
     *
     * Split out from save() with the lookup injected so the precedence rules —
     * the thing that makes a re-copy update instead of duplicate — are testable
     * without a database.
     *
     * @param callable(string,string):?int $finder  (column, value) => row id|null
     */
    public static function resolve_target(array $a, callable $finder): ?int
    {
        $id = (int) ($a['id'] ?? 0);
        if ($id && $finder('id', (string) $id)) {
            return $id;
        }
        foreach (['uid', 'origin_uid'] as $col) {
            $val = trim((string) ($a[$col] ?? ''));
            if ($val !== '') {
                $found = $finder($col, $val);
                if ($found) {
                    return (int) $found;
                }
            }
        }
        return null;
    }

    // ---- registration -----------------------------------------------------

    public static function init(): void
    {
        // Snippet execution is independent of the AI abilities master switch —
        // once saved, snippets are site code and must run for real visitors,
        // exactly like any snippet plugin. A separate kill-switch can disable all.
        if (!Claudpress::instance()->get('run_snippets', true)) {
            return;
        }

        add_action('plugins_loaded', [self::class, 'run_php_everywhere'], 50);
        add_action('wp_head', [self::class, 'output_header'], 20);
        add_action('wp_footer', [self::class, 'output_footer'], 20);
        add_filter('the_content', [self::class, 'wrap_content'], 20);
        add_shortcode('claudpress_snippet', [self::class, 'shortcode']);
        add_filter('template_include', [self::class, 'maybe_page_template'], 99);
    }

    private static function register_shutdown(): void
    {
        if (self::$shutdown_registered) {
            return;
        }
        register_shutdown_function([self::class, 'on_shutdown']);
        self::$shutdown_registered = true;
    }

    // ---- execution --------------------------------------------------------

    /** @return array<int, object> active snippets for a location, ordered by priority */
    private static function for_location(string $location): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE active = 1 AND location = %s ORDER BY priority ASC, id ASC",
            $location
        ));
        return $rows ?: [];
    }

    public static function run_php_everywhere(): void
    {
        foreach (['everywhere', 'admin_only', 'frontend_only'] as $loc) {
            foreach (self::for_location($loc) as $snip) {
                if ($snip->snippet_type !== 'php') {
                    continue;
                }
                if ($loc === 'admin_only' && !is_admin()) {
                    continue;
                }
                if ($loc === 'frontend_only' && is_admin()) {
                    continue;
                }
                if (!self::conditions_pass($snip)) {
                    continue;
                }
                self::run_php($snip);
            }
        }
    }

    public static function output_header(): void
    {
        self::output_assets('header');
    }

    public static function output_footer(): void
    {
        self::output_assets('footer');
    }

    private static function output_assets(string $location): void
    {
        foreach (self::for_location($location) as $snip) {
            if (!self::conditions_pass($snip)) {
                continue;
            }
            switch ($snip->snippet_type) {
                case 'css':
                    echo "\n<style id=\"claudpress-snippet-{$snip->id}\">\n" . $snip->code . "\n</style>\n";
                    break;
                case 'js':
                    echo "\n<script id=\"claudpress-snippet-{$snip->id}\">\n" . $snip->code . "\n</script>\n";
                    break;
                case 'html':
                    echo "\n" . $snip->code . "\n";
                    break;
                case 'php':
                    self::run_php($snip); // echoed output lands in the head/footer
                    break;
            }
        }
    }

    public static function wrap_content(string $content): string
    {
        if (!is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $before = self::render_content_snippets('before_content');
        $after  = self::render_content_snippets('after_content');
        return $before . $content . $after;
    }

    private static function render_content_snippets(string $location): string
    {
        $out = '';
        foreach (self::for_location($location) as $snip) {
            if (!self::conditions_pass($snip)) {
                continue;
            }
            $out .= self::render_snippet_value($snip);
        }
        return $out;
    }

    public static function shortcode($atts): string
    {
        $atts = shortcode_atts(['id' => 0], $atts, 'claudpress_snippet');
        $snip = self::get((int) $atts['id']);
        if (!$snip || (int) $snip['active'] !== 1) {
            return '';
        }
        return self::render_snippet_value((object) $snip);
    }

    /** Serve a page_template snippet as the whole page for its target post. */
    public static function maybe_page_template(string $template): string
    {
        if (is_admin() || !is_singular()) {
            return $template;
        }
        $id = get_queried_object_id();
        if (!$id) {
            return $template;
        }
        global $wpdb;
        $snip = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE active = 1 AND location = 'page_template' AND target_id = %d ORDER BY priority ASC LIMIT 1",
            $id
        ));
        if (!$snip) {
            return $template;
        }
        // Render the custom template and stop WordPress from loading the theme one.
        self::run_php_template($snip);
        exit;
    }

    private static function render_snippet_value(object $snip): string
    {
        switch ($snip->snippet_type) {
            case 'css':
                return "<style>\n" . $snip->code . "\n</style>";
            case 'js':
                return "<script>\n" . $snip->code . "\n</script>";
            case 'html':
                return $snip->code;
            case 'php':
                return self::run_php($snip, true);
            default:
                return '';
        }
    }

    /**
     * Execute a PHP snippet with crash attribution. Returns captured output.
     * $code must NOT include <?php tags (like WPCode's PHP snippets).
     */
    private static function run_php(object $snip, bool $return_output = false): string
    {
        self::register_shutdown();
        self::$running = (int) $snip->id;
        ob_start();
        try {
            eval($snip->code);
        } catch (Throwable $e) {
            self::deactivate_with_error((int) $snip->id, $e->getMessage());
        }
        $out = ob_get_clean();
        self::$running = null;
        return $return_output ? (string) $out : '';
    }

    /** Render a full page template snippet (may contain get_header()/get_footer()). */
    private static function run_php_template(object $snip): void
    {
        self::register_shutdown();
        self::$running = (int) $snip->id;
        try {
            if ($snip->snippet_type === 'php') {
                eval('?>' . $snip->code); // allow inline HTML with <?php islands
            } else {
                echo $snip->code;
            }
        } catch (Throwable $e) {
            self::deactivate_with_error((int) $snip->id, $e->getMessage());
            echo '<!-- Claudpress template snippet error: ' . esc_html($e->getMessage()) . ' -->';
        }
        self::$running = null;
    }

    public static function on_shutdown(): void
    {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            return;
        }
        if (self::$running) {
            self::deactivate_with_error(self::$running, $error['message']);
            @error_log('[Claudpress] Auto-deactivated crashing snippet #' . self::$running . ': ' . $error['message']);
        }
    }

    private static function deactivate_with_error(int $id, string $message): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['active' => 0, 'last_error' => mb_substr($message, 0, 1000)], ['id' => $id]);

        // Something the agent wrote is now switched off. The site is up because
        // of it, but only the owner can decide whether the missing behaviour
        // matters — so this is announced, not just logged.
        do_action('claudpress_snippet_auto_deactivated', $id, $message);
    }

    // ---- conditions -------------------------------------------------------

    private static function conditions_pass(object $snip): bool
    {
        if (empty($snip->conditions_json)) {
            return true;
        }
        $cond = json_decode($snip->conditions_json, true);
        if (!is_array($cond) || empty($cond)) {
            return true;
        }
        if (isset($cond['logged_in']) && (bool) $cond['logged_in'] !== is_user_logged_in()) {
            return false;
        }
        if (!empty($cond['post_types']) && is_singular()) {
            if (!in_array(get_post_type(), (array) $cond['post_types'], true)) {
                return false;
            }
        }
        if (!empty($cond['post_ids']) && is_singular()) {
            if (!in_array(get_queried_object_id(), array_map('intval', (array) $cond['post_ids']), true)) {
                return false;
            }
        }
        if (isset($cond['front_page']) && (bool) $cond['front_page'] !== is_front_page()) {
            return false;
        }
        return true;
    }

    // ---- CRUD -------------------------------------------------------------

    /**
     * Create or merge-update a snippet.
     *
     * Merge, not replace: an update starts from the stored row and overwrites
     * only the keys actually present in $a. The old behaviour rebuilt the whole
     * row, so `snippet_save({id, tags})` silently blanked `code` — the exact
     * regression the admin page (which saves a subset of fields) would trip on.
     */
    public static function save(array $a): array
    {
        global $wpdb;

        $types     = ['php', 'css', 'js', 'html'];
        $locations = ['everywhere', 'admin_only', 'frontend_only', 'header', 'footer', 'before_content', 'after_content', 'shortcode', 'page_template'];

        $id       = self::resolve_target($a, [self::class, 'find_id']);
        $existing = $id ? self::get($id) : null;
        if (!$existing) {
            $id = null;
        }

        $type = array_key_exists('snippet_type', $a)
            ? (in_array($a['snippet_type'], $types, true) ? $a['snippet_type'] : 'php')
            : (string) ($existing['snippet_type'] ?? 'php');
        $location = array_key_exists('location', $a)
            ? (in_array($a['location'], $locations, true) ? $a['location'] : 'everywhere')
            : (string) ($existing['location'] ?? 'everywhere');

        $row = [
            'name'         => array_key_exists('name', $a) ? sanitize_text_field((string) $a['name']) : (string) ($existing['name'] ?? 'Untitled snippet'),
            'description'  => array_key_exists('description', $a) ? (string) $a['description'] : (string) ($existing['description'] ?? ''),
            'code'         => array_key_exists('code', $a) ? (string) $a['code'] : (string) ($existing['code'] ?? ''),
            'snippet_type' => $type,
            'location'     => $location,
            'target_id'    => array_key_exists('target_id', $a) ? (int) $a['target_id'] : (int) ($existing['target_id'] ?? 0),
            'priority'     => array_key_exists('priority', $a) ? (int) $a['priority'] : (int) ($existing['priority'] ?? 10),
            'active'       => array_key_exists('active', $a) ? (int) (bool) $a['active'] : (int) ($existing['active'] ?? 0),
            'tags'         => array_key_exists('tags', $a) ? self::normalize_tags($a['tags']) : (string) ($existing['tags'] ?? ''),
            'purpose'      => array_key_exists('purpose', $a) ? (string) $a['purpose'] : ($existing['purpose'] ?? null),
            'impact'       => array_key_exists('impact', $a) ? (string) $a['impact'] : ($existing['impact'] ?? null),
            'origin_site'  => array_key_exists('origin_site', $a) ? sanitize_text_field((string) $a['origin_site']) : (string) ($existing['origin_site'] ?? ''),
            'origin_uid'   => array_key_exists('origin_uid', $a) ? sanitize_text_field((string) $a['origin_uid']) : (string) ($existing['origin_uid'] ?? ''),
            'updated_at'   => current_time('mysql'),
        ];

        $code_changed = $existing ? ($row['code'] !== (string) $existing['code']) : true;

        // Fail soft, like the crash guard: broken code is still stored so the
        // owner does not lose their work, but it is forced off and labelled.
        $lint = self::lint_php($row['code'], $type, $location);
        if ($lint !== '') {
            $row['active']     = 0;
            $row['last_error'] = 'Syntax error: ' . mb_substr($lint, 0, 900);
        } elseif ($code_changed || $row['active'] === 1) {
            // Only a real code change or a deliberate re-activation clears the
            // auto-deactivation record; a metadata-only edit must not erase it.
            $row['last_error'] = null;
        }

        if ($id) {
            $wpdb->update(self::table(), $row, ['id' => $id]);
        } else {
            $row['uid']        = trim((string) ($a['uid'] ?? '')) !== '' ? sanitize_text_field((string) $a['uid']) : self::new_uid();
            $row['created_at'] = current_time('mysql');
            $wpdb->insert(self::table(), $row);
            $id = (int) $wpdb->insert_id;
        }

        $res = ['success' => true, 'id' => $id, 'active' => (bool) $row['active'], 'type' => $type, 'location' => $location];
        if ($lint !== '') {
            $res['lint_error'] = $lint;
            $res['note']       = 'Saved but forced inactive: the PHP does not parse.';
        }
        return $res;
    }

    /** Row-id lookup by a single column. The seam resolve_target() is given. */
    private static function find_id(string $column, string $value): ?int
    {
        global $wpdb;
        if (!in_array($column, ['id', 'uid', 'origin_uid'], true)) {
            return null;
        }
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE {$column} = %s LIMIT 1",
            $value
        ));
        return $found ? (int) $found : null;
    }

    /**
     * Filtered list for the admin page.
     *
     * @param array{search?:string, tag?:string, snippet_type?:string, active?:string} $filters
     */
    public static function query(array $filters = []): array
    {
        global $wpdb;
        $where = ['1=1'];
        $args  = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like    = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(name LIKE %s OR description LIKE %s OR purpose LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        $tag = trim((string) ($filters['tag'] ?? ''));
        if ($tag !== '') {
            $where[] = 'tags LIKE %s';
            $args[]  = '%,' . $wpdb->esc_like($tag) . ',%';
        }
        $type = (string) ($filters['snippet_type'] ?? '');
        if (in_array($type, ['php', 'css', 'js', 'html'], true)) {
            $where[] = 'snippet_type = %s';
            $args[]  = $type;
        }
        $active = (string) ($filters['active'] ?? '');
        if ($active === '1' || $active === '0') {
            $where[] = 'active = %d';
            $args[]  = (int) $active;
        }

        $sql = "SELECT * FROM " . self::table() . " WHERE " . implode(' AND ', $where) . " ORDER BY id DESC";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        return $rows ?: [];
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function all(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, uid, name, description, tags, purpose, impact, snippet_type, location, target_id, priority, active, last_error, origin_site, origin_uid, created_at, updated_at FROM " . self::table() . " ORDER BY id DESC", ARRAY_A);
        return $rows ?: [];
    }

    /** Full rows including code — used by the migration/export engine. */
    public static function all_full(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT uid, name, description, code, snippet_type, location, target_id, priority, active, tags, purpose, impact, conditions_json FROM " . self::table() . " ORDER BY id ASC", ARRAY_A);
        // Decode conditions back to an array so save() re-encodes cleanly on import.
        foreach ($rows as &$r) {
            if (!empty($r['conditions_json'])) {
                $r['conditions'] = json_decode($r['conditions_json'], true);
            }
            unset($r['conditions_json']);
        }
        return $rows ?: [];
    }

    public static function toggle(int $id, bool $active): array
    {
        global $wpdb;
        if (!self::get($id)) {
            return ['success' => false, 'error' => 'Snippet not found.'];
        }
        $update = ['active' => $active ? 1 : 0];
        if ($active) {
            $update['last_error'] = null; // clear prior crash record on manual re-activate
        }
        $wpdb->update(self::table(), $update, ['id' => $id]);
        return ['success' => true, 'id' => $id, 'active' => $active];
    }

    public static function delete(int $id): array
    {
        global $wpdb;
        $deleted = $wpdb->delete(self::table(), ['id' => $id]);
        return ['success' => (bool) $deleted, 'id' => $id];
    }
}
