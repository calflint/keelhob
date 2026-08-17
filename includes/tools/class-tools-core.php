<?php
/**
 * Core power tools: arbitrary PHP execution, SQL, and site introspection.
 * execute_php is the universal escape hatch — anything WordPress/WooCommerce can
 * do, it can do.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Core
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add(
            'execute_php',
            'Execute arbitrary PHP in the full WordPress runtime ($wpdb, WP_Query, get_option, WooCommerce, every loaded plugin API). Do NOT include <?php tags. Use `return $value;` to return data for inspection; echoed output is captured separately. This is the universal fallback for anything the dedicated tools do not cover.',
            [
                'type'       => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'PHP code to run. No <?php tags. Return a value to inspect it.'],
                ],
                'required' => ['code'],
            ],
            [self::class, 'execute_php'],
            ['destructiveHint' => true, 'title' => 'Execute PHP']
        );

        $r->add(
            'execute_sql',
            'Run a SQL query against the WordPress database via $wpdb. SELECT queries return rows; INSERT/UPDATE/DELETE return the affected row count. Table prefix is available as {prefix} in your query (e.g. SELECT * FROM {prefix}posts LIMIT 5).',
            [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The SQL query. Use {prefix} for the table prefix.'],
                ],
                'required' => ['query'],
            ],
            [self::class, 'execute_sql'],
            ['destructiveHint' => true, 'title' => 'Execute SQL']
        );

        $r->add(
            'get_site_info',
            'Return an overview of this WordPress site: WP/PHP versions, site URL, active theme, active plugins, whether WooCommerce/known SEO plugins are present, counts of posts/pages/products, the Claudpress abilities state, and this site\'s short site_label (the handle to use when the owner names this site among several, e.g. when copying a snippet to another site). Safe, read-only — works even when abilities are disabled.',
            ['type' => 'object', 'properties' => (object) []],
            [self::class, 'get_site_info'],
            ['readOnlyHint' => true, 'title' => 'Get Site Info']
        );
    }

    public static function execute_php(array $args): array
    {
        $code = (string) ($args['code'] ?? '');
        if (trim($code) === '') {
            return ['success' => false, 'error' => 'No code provided.'];
        }

        $captured = [];
        set_error_handler(function ($no, $str, $file, $line) use (&$captured) {
            $captured[] = ['type' => $no, 'message' => $str, 'file' => $file, 'line' => $line];
            return true;
        });

        $started = microtime(true);
        ob_start();
        $return_value = null;
        $error = null;
        try {
            $return_value = eval($code);
        } catch (Throwable $e) {
            $error = ['message' => $e->getMessage(), 'class' => get_class($e), 'line' => $e->getLine()];
        }
        $output = ob_get_clean();
        restore_error_handler();

        return [
            'success'            => $error === null,
            'return_value'       => self::sanitize_value($return_value),
            'output'             => $output,
            'errors'             => $captured,
            'error_message'      => $error['message'] ?? null,
            'error_class'        => $error['class'] ?? null,
            'execution_time_ms'  => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    public static function execute_sql(array $args): array
    {
        global $wpdb;
        $query = (string) ($args['query'] ?? '');
        $query = str_replace('{prefix}', $wpdb->prefix, $query);
        if (trim($query) === '') {
            return ['success' => false, 'error' => 'No query provided.'];
        }

        $is_read = (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN|PRAGMA)\b/i', $query);

        $wpdb->suppress_errors(true);
        if ($is_read) {
            $rows = $wpdb->get_results($query, ARRAY_A);
            $err  = $wpdb->last_error;
            if ($err) {
                return ['success' => false, 'error' => $err];
            }
            return ['success' => true, 'rows' => $rows, 'row_count' => is_array($rows) ? count($rows) : 0];
        }

        $affected = $wpdb->query($query);
        $err      = $wpdb->last_error;
        if ($err) {
            return ['success' => false, 'error' => $err];
        }
        return ['success' => true, 'rows_affected' => (int) $affected, 'insert_id' => $wpdb->insert_id ?: null];
    }

    public static function get_site_info(array $args): array
    {
        global $wp_version, $wpdb;

        $active_plugins = array_values((array) get_option('active_plugins', []));
        $theme = wp_get_theme();

        $seo_plugins = [];
        foreach ([
            'wordpress-seo/wp-seo.php'        => 'Yoast SEO',
            'seo-by-rank-math/rank-math.php'  => 'Rank Math',
            'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
            'wp-seopress/seopress.php'        => 'SEOPress',
        ] as $file => $label) {
            if (in_array($file, $active_plugins, true)) {
                $seo_plugins[] = $label;
            }
        }

        return [
            'success'      => true,
            'site_url'     => site_url(),
            'home_url'     => home_url(),
            'name'         => get_bloginfo('name'),
            // The handle to use when the owner names this site among several
            // ("copy the sticky-header snippet from Pranknologia to DesertDrift").
            'site_label'   => Claudpress::site_label(),
            'description'  => get_bloginfo('description'),
            'wp_version'   => $wp_version,
            'php_version'  => PHP_VERSION,
            'is_multisite' => is_multisite(),
            'active_theme' => ['name' => $theme->get('Name'), 'version' => $theme->get('Version')],
            'active_plugins' => $active_plugins,
            'woocommerce'  => [
                'active'  => class_exists('WooCommerce'),
                'version' => defined('WC_VERSION') ? WC_VERSION : null,
            ],
            'seo_plugins'  => $seo_plugins,
            'counts'       => [
                'posts'    => (int) wp_count_posts('post')->publish,
                'pages'    => (int) wp_count_posts('page')->publish,
                'products' => class_exists('WooCommerce') ? (int) wp_count_posts('product')->publish : null,
                'users'    => (int) count_users()['total_users'],
            ],
            'claudpress'   => [
                'version'           => CLAUDPRESS_VERSION,
                'abilities_enabled' => Claudpress::instance()->abilities_enabled(),
                'tool_count'        => count(Claudpress::instance()->registry->names()),
                'fs_base'           => Claudpress::instance()->get('fs_base'),
            ],
        ];
    }

    /**
     * Make an arbitrary return value JSON-safe (objects → arrays, closures → note,
     * depth-limited) so eval results serialize cleanly.
     */
    public static function sanitize_value($value, int $depth = 0)
    {
        if ($depth > 6) {
            return '[max depth]';
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if ($value instanceof Closure) {
            return '[Closure]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::sanitize_value($v, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) {
            if (method_exists($value, 'to_array')) {
                return self::sanitize_value($value->to_array(), $depth + 1);
            }
            if ($value instanceof JsonSerializable) {
                return self::sanitize_value($value->jsonSerialize(), $depth + 1);
            }
            $vars = get_object_vars($value);
            if (!empty($vars)) {
                return ['_class' => get_class($value)] + self::sanitize_value($vars, $depth + 1);
            }
            return '[' . get_class($value) . ']';
        }
        return '[unserializable]';
    }
}
