<?php
/**
 * Capability toggles. Groups every tool into a capability the owner can switch
 * on or off from the dashboard, so they decide exactly what the agent may touch
 * — especially sensitive areas like money (payments/orders), shipping, code,
 * files, users, and production deploys.
 *
 * When a capability is OFF: its tools are hidden from tools/list (so the agent
 * never sees them), removed from the autonomous agent's toolset, blocked if
 * somehow called, and named as off-limits in the session instructions.
 *
 * A short always-on read-only set (site info, dashboard, memory/skill/task
 * listing) is never gated, so the owner can always ask "what's going on".
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Capabilities
{
    /** Read-only introspection that is always available regardless of toggles. */
    public static function always_on(): array
    {
        return ['get_site_info', 'get_dashboard', 'memory_list', 'memory_get', 'skill_list', 'skill_get', 'task_list', 'agent_runs'];
    }

    /**
     * Capability group definitions.
     * @return array<string, array{label:string, desc:string, sensitive:bool, tools:string[]}>
     */
    public static function groups(): array
    {
        return [
            'content' => [
                'label' => 'Content', 'sensitive' => false,
                'desc' => 'Create/edit posts, pages, media, taxonomies.',
                'tools' => ['create_post', 'update_post', 'delete_post', 'get_post', 'list_posts', 'upload_media', 'manage_taxonomy'],
            ],
            'store' => [
                'label' => 'Store (products & orders)', 'sensitive' => true,
                'desc' => 'WooCommerce products, orders (incl. refunds), coupons, reports, settings.',
                'tools' => ['wc_product', 'wc_order', 'wc_coupon', 'wc_report', 'wc_settings'],
            ],
            'payments' => [
                'label' => 'Payments (money)', 'sensitive' => true,
                'desc' => 'Enable and configure payment gateways and keys.',
                'tools' => ['wc_payments'],
            ],
            'shipping' => [
                'label' => 'Shipping', 'sensitive' => true,
                'desc' => 'Shipping zones, methods, and rates.',
                'tools' => ['wc_shipping'],
            ],
            'tax' => [
                'label' => 'Tax', 'sensitive' => true,
                'desc' => 'Tax settings and rates.',
                'tools' => ['wc_tax'],
            ],
            'seo' => [
                'label' => 'SEO', 'sensitive' => false,
                'desc' => 'Meta, schema, redirects, sitemap, analysis.',
                'tools' => ['seo_meta', 'seo_analyze', 'seo_schema', 'seo_redirect', 'seo_sitemap'],
            ],
            'design' => [
                'label' => 'Design / Elementor', 'sensitive' => false,
                'desc' => 'Build and edit Elementor pages, kit, settings.',
                'tools' => ['elementor_page', 'elementor_settings', 'elementor_kit'],
            ],
            'code' => [
                'label' => 'Code execution', 'sensitive' => true,
                'desc' => 'Run arbitrary PHP/SQL and save live code snippets.',
                'tools' => ['execute_php', 'execute_sql', 'snippet_save', 'snippet_list', 'snippet_get', 'snippet_toggle', 'snippet_delete'],
            ],
            'files' => [
                'label' => 'Filesystem', 'sensitive' => true,
                'desc' => 'Read/write/delete files and sandbox scripts.',
                'tools' => ['read_file', 'write_file', 'edit_file', 'delete_file', 'list_directory', 'write_sandbox_file'],
            ],
            'users' => [
                'label' => 'Users', 'sensitive' => true,
                'desc' => 'Create/edit/delete user accounts and roles.',
                'tools' => ['manage_user'],
            ],
            'site_admin' => [
                'label' => 'Site admin (plugins/themes/options)', 'sensitive' => true,
                'desc' => 'Options, plugins, themes, menus.',
                'tools' => ['manage_option', 'manage_plugin', 'manage_theme', 'manage_menu'],
            ],
            'updates' => [
                'label' => 'Updates', 'sensitive' => true,
                'desc' => 'Update WordPress core, plugins, and themes.',
                'tools' => ['manage_updates'],
            ],
            'images' => [
                'label' => 'Images', 'sensitive' => false,
                'desc' => 'Search (Openverse) and generate images.',
                'tools' => ['image_search', 'image_generate'],
            ],
            'comms' => [
                'label' => 'Email & notifications', 'sensitive' => true,
                'desc' => 'Send email and owner notifications.',
                'tools' => ['send_email', 'notify_owner'],
            ],
            'automation' => [
                'label' => 'Autonomy (agent & scheduled tasks)', 'sensitive' => true,
                'desc' => 'Run the autonomous agent and create/run scheduled tasks.',
                'tools' => ['run_agent', 'task_save', 'task_run', 'task_delete', 'agent_runs'],
            ],
            'memory' => [
                'label' => 'Memory (write)', 'sensitive' => false,
                'desc' => 'Save/delete cross-session memory.',
                'tools' => ['memory_save', 'memory_delete'],
            ],
            'skills' => [
                'label' => 'Skills (write)', 'sensitive' => false,
                'desc' => 'Create/delete agent skills.',
                'tools' => ['skill_save', 'skill_delete', 'skill_refresh'],
            ],
            'deploy' => [
                'label' => 'Deploy to production', 'sensitive' => true,
                'desc' => 'Export/import bundles and push to the production site.',
                'tools' => ['export_bundle', 'import_bundle', 'deploy_push'],
            ],
        ];
    }

    /** Reverse map tool → group key (built once). */
    public static function tool_group(string $tool): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::groups() as $key => $g) {
                foreach ($g['tools'] as $t) {
                    $map[$t] = $key;
                }
            }
        }
        return $map[$tool] ?? null;
    }

    public static function is_group_enabled(string $group): bool
    {
        $caps = Claudpress::instance()->get('capabilities', []);
        if (!is_array($caps) || !array_key_exists($group, $caps)) {
            return true; // default on
        }
        return (bool) $caps[$group];
    }

    /** Whether a tool may run, given its group's toggle. Ungrouped/always-on tools are allowed. */
    public static function tool_allowed(string $tool): bool
    {
        if (in_array($tool, self::always_on(), true)) {
            return true;
        }
        $group = self::tool_group($tool);
        if ($group === null) {
            return true;
        }
        return self::is_group_enabled($group);
    }

    /**
     * Tools a strictly read-only actor may use — the `viewer` Telegram role.
     *
     * Derived from each tool's own readOnlyHint annotation rather than a second
     * hand-maintained list that would drift, minus an explicit exclusion list.
     * Those exclusions are not oversights:
     *   - read_file / list_directory read *anything* under the filesystem base,
     *     and wp-config.php is under it. "Read-only" and "can read your database
     *     password" are not the same claim.
     *   - export_bundle copies the site's content out wholesale. It mutates
     *     nothing and is still not something to hand a viewer.
     *
     * @param array<int, array{name: string, annotations?: array}> $tools registry->list()
     * @return array<int, string>
     */
    public static function viewer_tools(array $tools): array
    {
        $never = ['read_file', 'list_directory', 'export_bundle'];
        $out   = [];

        foreach ($tools as $t) {
            $name = (string) ($t['name'] ?? '');
            if ($name === '' || in_array($name, $never, true)) {
                continue;
            }
            if (($t['annotations']['readOnlyHint'] ?? null) === true && self::tool_allowed($name)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** Human-readable labels of disabled groups, for the session instructions. */
    public static function disabled_labels(): array
    {
        $out = [];
        foreach (self::groups() as $key => $g) {
            if (!self::is_group_enabled($key)) {
                $out[] = $g['label'];
            }
        }
        return $out;
    }
}
