<?php
/**
 * Deploy / migration tools — the staging→production workflow.
 *   - export_bundle: serialize chosen entities to a portable JSON bundle.
 *   - import_bundle:  import a bundle produced by export_bundle into this site.
 *   - deploy_push:    push a freshly-exported bundle straight to a configured
 *                     production Claudpress site over its /import endpoint.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Deploy
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $types = Claudpress_Migration::TYPES;

        $r->add('export_bundle', 'Export a portable JSON bundle of this site\'s content and config: pages, posts, products (simple products in full; a variable product\'s content only — its attributes and variations are not portable), snippets, skills, memory, nav menus with their theme locations, widgets/sidebars, and selected options — incl. Elementor & SEO meta. Secrets (API keys/tokens) are never included. Use as the first half of a staging→production migration. Returns the bundle.', [
            'type' => 'object',
            'properties' => [
                'types'  => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $types], 'description' => 'Which entity types to include. Default: all.'],
                'status' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Post statuses to include (default publish+draft+pending+private).'],
                'limit'  => ['type' => 'integer', 'description' => 'Max posts per type (default all).'],
            ],
        ], [self::class, 'export_bundle'], ['readOnlyHint' => true]);

        $r->add('import_bundle', 'Import a bundle produced by export_bundle into THIS site. Idempotent: matches by slug/SKU/name/key and updates rather than duplicating. Relinks menu items to this site\'s pages by slug, and restores widgets into sidebars this theme registers. Sideloads featured images. Variable products are reported in "skipped" — import the content, then rebuild their attributes and variations here with wc_product / execute_php. Use as the second half of a migration (run it on the destination site).', [
            'type' => 'object',
            'properties' => [
                'bundle' => ['type' => 'object', 'description' => 'The bundle object from export_bundle.'],
                'mode'   => ['type' => 'string', 'enum' => ['upsert'], 'default' => 'upsert'],
            ],
            'required' => ['bundle'],
        ], [self::class, 'import_bundle'], ['destructiveHint' => true]);

        $r->add('deploy_push', 'Export the chosen entities and push them straight to your PRODUCTION Claudpress site (configured under Claudpress > Settings: deploy target URL + token), which imports them. One-call staging→production ship. Confirm with the owner before pushing to a live site.', [
            'type' => 'object',
            'properties' => [
                'types'       => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $types]],
                'target_url'  => ['type' => 'string', 'description' => 'Override the configured production site base URL (e.g. https://prod.example.com).'],
                'target_token' => ['type' => 'string', 'description' => 'Override the configured production bearer token.'],
            ],
        ], [self::class, 'deploy_push'], ['destructiveHint' => true]);
    }

    public static function export_bundle(array $a): array
    {
        $bundle = Claudpress_Migration::export((array) ($a['types'] ?? []), [
            'status' => $a['status'] ?? null,
            'limit'  => $a['limit'] ?? -1,
        ]);
        $counts = [];
        foreach ($bundle['entities'] as $type => $items) {
            // Widgets are a map (sidebars + instances), not a list — count the
            // sidebars so the number means something to the caller.
            $counts[$type] = $type === 'widgets'
                ? count((array) ($items['sidebars_widgets'] ?? []))
                : (is_array($items) ? count($items) : 0);
        }
        return ['success' => true, 'counts' => $counts, 'bundle' => $bundle];
    }

    public static function import_bundle(array $a): array
    {
        $bundle = $a['bundle'] ?? [];
        if (!is_array($bundle)) {
            return ['success' => false, 'error' => 'bundle must be an object.'];
        }
        return Claudpress_Migration::import($bundle, (string) ($a['mode'] ?? 'upsert'));
    }

    public static function deploy_push(array $a): array
    {
        $s     = Claudpress::instance()->settings();
        $url   = rtrim((string) ($a['target_url'] ?? ($s['deploy_target_url'] ?? '')), '/');
        $token = (string) ($a['target_token'] ?? ($s['deploy_target_token'] ?? ''));

        if ($url === '' || $token === '') {
            return ['success' => false, 'error' => 'No production target configured. Set the deploy target URL + token in Claudpress > Settings, or pass target_url and target_token.'];
        }

        $export = self::export_bundle(['types' => $a['types'] ?? []]);
        $bundle = $export['bundle'];

        $endpoint = $url . '/wp-json/' . CLAUDPRESS_REST_NAMESPACE . '/import';
        $resp = wp_remote_post($endpoint, [
            'timeout' => 120,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['bundle' => $bundle, 'mode' => 'upsert']),
        ]);
        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'error' => 'Target responded ' . $code . ': ' . mb_substr(wp_remote_retrieve_body($resp), 0, 400)];
        }
        return ['success' => true, 'pushed_counts' => $export['counts'], 'target' => $url, 'import_report' => $body];
    }
}
