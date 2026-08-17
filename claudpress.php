<?php
/**
 * Plugin Name:       Claudpress
 * Plugin URI:        https://github.com/calflint/claudpress
 * Description:        Self-contained MCP server that gives an AI agent (Claude Code, Claude Desktop, Cursor, Codex…) full, programmatic control of WordPress & WooCommerce — PHP execution, filesystem, content, store, SEO, a code-snippets engine, Elementor building, updates/email, AI/stock images, cross-session memory and a skills engine. No external dependencies. For development & staging sites.
 * Version:           1.9.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Caleb Arthur-Flints
 * Author URI:        https://github.com/calflint
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       claudpress
 *
 * WARNING: Claudpress gives connected AI agents unrestricted control of this
 * WordPress installation (arbitrary PHP, database, filesystem). Only enable AI
 * abilities on development or staging sites you can afford to break. Never
 * activate abilities on a production site without a full backup and review flow.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLAUDPRESS_VERSION', '1.9.0');
define('CLAUDPRESS_FILE', __FILE__);
define('CLAUDPRESS_DIR', plugin_dir_path(__FILE__));
define('CLAUDPRESS_URL', plugin_dir_url(__FILE__));
define('CLAUDPRESS_BASENAME', plugin_basename(__FILE__));

// Namespace for the MCP REST route: /wp-json/claudpress/v1/mcp
define('CLAUDPRESS_REST_NAMESPACE', 'claudpress/v1');

// Persistent sandbox directory for AI-written PHP that must survive requests.
define('CLAUDPRESS_SANDBOX_DIR', WP_CONTENT_DIR . '/claudpress-sandbox');

// How long (seconds) a single execute_php / tool call may run.
if (!defined('CLAUDPRESS_MAX_EXECUTION_TIME')) {
    define('CLAUDPRESS_MAX_EXECUTION_TIME', 120);
}

/**
 * Manual, dependency-free loader. Every class lives under includes/.
 */
require_once CLAUDPRESS_DIR . 'includes/class-claudpress.php';
require_once CLAUDPRESS_DIR . 'includes/class-auth.php';
require_once CLAUDPRESS_DIR . 'includes/class-tool-registry.php';
require_once CLAUDPRESS_DIR . 'includes/class-capabilities.php';
require_once CLAUDPRESS_DIR . 'includes/class-mcp-server.php';
require_once CLAUDPRESS_DIR . 'includes/class-rest.php';
require_once CLAUDPRESS_DIR . 'includes/class-memory.php';
require_once CLAUDPRESS_DIR . 'includes/class-skills.php';
require_once CLAUDPRESS_DIR . 'includes/class-snippets.php';
require_once CLAUDPRESS_DIR . 'includes/class-sandbox.php';
require_once CLAUDPRESS_DIR . 'includes/class-logger.php';
require_once CLAUDPRESS_DIR . 'includes/class-policy.php';
require_once CLAUDPRESS_DIR . 'includes/class-digest.php';
require_once CLAUDPRESS_DIR . 'includes/class-notifier.php';
require_once CLAUDPRESS_DIR . 'includes/class-telegram.php';
require_once CLAUDPRESS_DIR . 'includes/class-events.php';
require_once CLAUDPRESS_DIR . 'includes/class-undo.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runs.php';
require_once CLAUDPRESS_DIR . 'includes/class-providers.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runtime.php';
require_once CLAUDPRESS_DIR . 'includes/class-tasks.php';
require_once CLAUDPRESS_DIR . 'includes/class-migration.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin-snippets.php';

require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-core.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-filesystem.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-content.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-site.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-woocommerce.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-seo.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-memory.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-skills.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-snippets.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-elementor.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-ops.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-automation.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-payments.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-deploy.php';

/**
 * Boot the plugin once all plugins are loaded (so WooCommerce detection works).
 */
add_action('plugins_loaded', static function () {
    load_plugin_textdomain('claudpress', false, dirname(CLAUDPRESS_BASENAME) . '/languages');
    Claudpress::instance()->boot();
}, 20);

register_activation_hook(__FILE__, ['Claudpress', 'on_activate']);
register_deactivation_hook(__FILE__, ['Claudpress', 'on_deactivate']);
