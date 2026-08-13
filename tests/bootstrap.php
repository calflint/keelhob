<?php
/**
 * Test bootstrap: just enough WordPress to load and exercise Claudpress's pure
 * logic outside a WordPress install.
 *
 * This is deliberately NOT a WordPress test harness. It covers the decision
 * logic — policy, capability gating, budget caps, retry/backoff, scheduling,
 * key normalization — which is where the bugs that matter live and which needs
 * no database. Anything that genuinely talks to WordPress or WooCommerce is
 * covered by tests/live-smoke.mjs against a real site instead.
 */

define('ABSPATH', __DIR__ . '/fake-wp/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('CLAUDPRESS_VERSION', '1.4.0-test');
define('CLAUDPRESS_FILE', dirname(__DIR__) . '/claudpress.php');
define('CLAUDPRESS_DIR', dirname(__DIR__) . '/');
define('CLAUDPRESS_URL', 'http://example.test/wp-content/plugins/claudpress/');
define('CLAUDPRESS_BASENAME', 'claudpress/claudpress.php');
define('CLAUDPRESS_REST_NAMESPACE', 'claudpress/v1');
define('CLAUDPRESS_SANDBOX_DIR', WP_CONTENT_DIR . '/claudpress-sandbox');
define('CLAUDPRESS_MAX_EXECUTION_TIME', 120);

// ---- in-memory option store ------------------------------------------------

$GLOBALS['cp_test_options'] = [];

function cp_test_reset_options(array $options = []): void
{
    $GLOBALS['cp_test_options'] = $options;
}

function get_option(string $name, $default = false)
{
    return array_key_exists($name, $GLOBALS['cp_test_options']) ? $GLOBALS['cp_test_options'][$name] : $default;
}

function update_option(string $name, $value): bool
{
    $GLOBALS['cp_test_options'][$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['cp_test_options'][$name]);
    return true;
}

// ---- WordPress helpers used by the code under test -------------------------

/**
 * Real (if minimal) filter dispatch.
 *
 * Filters are part of the plugin's public extension contract — 'claudpress_
 * model_prices', 'claudpress_operating_protocol' and friends are documented for
 * site owners to override. A pass-through stub would let those break silently,
 * so the suite runs callbacks for real, in priority order.
 *
 * @var array<string, array<int, array<int, callable>>> $GLOBALS['cp_test_filters']
 */
$GLOBALS['cp_test_filters'] = [];

function apply_filters(string $hook, $value, ...$args)
{
    if (empty($GLOBALS['cp_test_filters'][$hook])) {
        return $value;
    }
    $by_priority = $GLOBALS['cp_test_filters'][$hook];
    ksort($by_priority);
    foreach ($by_priority as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}

function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['cp_test_filters'][$hook][$priority][] = $cb;
    return true;
}

/** Drop registered filters so one test cannot leak into the next. */
function cp_test_reset_filters(): void
{
    $GLOBALS['cp_test_filters'] = [];
}

function add_action(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    return true;
}

function do_action(string $hook, ...$args): void
{
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_text_field($str): string
{
    return trim(strip_tags((string) $str));
}

function sanitize_title(string $s): string
{
    return trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($s))), '-');
}

function wp_json_encode($data, int $flags = 0)
{
    return json_encode($data, $flags);
}

function current_time(string $type = 'mysql', $gmt = 0)
{
    if ($type === 'timestamp' || $type === 'U') {
        return time();
    }
    if ($type === 'G') {
        return (int) gmdate('G');
    }
    return gmdate('Y-m-d H:i:s');
}

function get_bloginfo(string $show = 'name'): string
{
    return 'Test Site';
}

function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function number_format_i18n($number, $decimals = 0): string
{
    return number_format((float) $number, (int) $decimals);
}

function wp_strip_all_tags($text): string
{
    return strip_tags((string) $text);
}

// ---- Escaping / i18n, so admin markup can be rendered and asserted ----------
// Deliberately real (if simplified) rather than pass-through: a test that
// renders admin HTML should fail if output stops being escaped.

function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($u) { return (string) $u; }
function __($t, $d = null) { return (string) $t; }
function esc_html__($t, $d = null) { return esc_html($t); }
function esc_attr__($t, $d = null) { return esc_attr($t); }
function esc_html_e($t, $d = null) { echo esc_html($t); }
function esc_attr_e($t, $d = null) { echo esc_attr($t); }
function _e($t, $d = null) { echo (string) $t; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function esc_js($t) { return str_replace(["'", '"', "\n"], ["\\'", '&quot;', ''], (string) $t); }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }

function selected($a, $b = true, $echo = true)
{
    $out = ((string) $a === (string) $b) ? " selected='selected'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}

function checked($a, $b = true, $echo = true)
{
    $out = ((string) $a === (string) $b) ? " checked='checked'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}

function disabled($a, $b = true, $echo = true)
{
    $out = ((string) $a === (string) $b) ? " disabled='disabled'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}

function wp_kses_post($content) { return (string) $content; }

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

class WP_Error
{
    private string $message;

    public function __construct(string $code = '', string $message = '')
    {
        $this->message = $message;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

// A minimal $wpdb so classes that reference it at load time don't explode.
class CP_Test_WPDB
{
    public string $prefix = 'wp_';

    public function get_charset_collate(): string
    {
        return '';
    }

    public function prepare(string $query, ...$args): string
    {
        return $query;
    }

    public function get_row($query, $output = null)
    {
        return null;
    }

    public function get_results($query, $output = null)
    {
        return [];
    }

    public function get_var($query, $column = 0, $row = 0)
    {
        return null;
    }

    public function query($query)
    {
        return 0;
    }

    public function insert($table, $data)
    {
        return 0;
    }

    public function update($table, $data, $where)
    {
        return 0;
    }

    public function delete($table, $where)
    {
        return 0;
    }
}

$GLOBALS['wpdb'] = new CP_Test_WPDB();

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ---- Admin-render helpers --------------------------------------------------
// Enough of the admin surface for the settings pages to render end to end.
// Rendering them is the only way to catch broken markup in the interleaved
// PHP/HTML templates — `php -l` only proves the file parses.

function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function rest_url($path = '') { return 'https://example.test/wp-json/' . $path; }
function wp_nonce_field($action = '') { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_timezone_string() { return 'UTC'; }
function wp_next_scheduled($hook) { return time() + 3600; }
function human_time_diff($from, $to = 0) { return '1 hour'; }
function get_current_screen() { return null; }
function set_transient($key, $value, $ttl = 0) { return true; }
function get_transient($key) { return false; }

function delete_transient($key) { return true; }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }

function wp_trim_words($text, $num_words = 55, $more = null)
{
    $words = preg_split('/\s+/', trim((string) $text));
    return implode(' ', array_slice($words, 0, $num_words));
}

require_once CLAUDPRESS_DIR . 'includes/class-claudpress.php';
require_once CLAUDPRESS_DIR . 'includes/class-tool-registry.php';
require_once CLAUDPRESS_DIR . 'includes/class-capabilities.php';
require_once CLAUDPRESS_DIR . 'includes/class-memory.php';
require_once CLAUDPRESS_DIR . 'includes/class-policy.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runs.php';
require_once CLAUDPRESS_DIR . 'includes/class-mcp-server.php';
require_once CLAUDPRESS_DIR . 'includes/class-providers.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runtime.php';
require_once CLAUDPRESS_DIR . 'includes/class-undo.php';
require_once CLAUDPRESS_DIR . 'includes/class-events.php';
require_once CLAUDPRESS_DIR . 'includes/class-telegram.php';
require_once CLAUDPRESS_DIR . 'includes/class-rest.php';
require_once CLAUDPRESS_DIR . 'includes/class-tasks.php';
require_once CLAUDPRESS_DIR . 'includes/class-migration.php';
require_once CLAUDPRESS_DIR . 'includes/class-snippets.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin.php';
