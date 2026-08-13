<?php
/**
 * Migration engine — the staging→production bridge. Serializes a chosen set of
 * site entities (pages, posts, products, snippets, skills, memory, selected
 * options incl. Elementor/SEO meta) into a portable JSON bundle, and imports a
 * bundle into another Claudpress site. This is the "build on staging, then ship
 * to production" workflow, without a full database sync.
 *
 * Design goals: idempotent upserts (match by slug/sku/name/key so re-importing
 * updates rather than duplicates) and no secret leakage (never exports the
 * Claudpress settings option, which holds API keys/tokens).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Migration
{
    const BUNDLE_VERSION = 2;

    /** Every entity type a bundle can carry. */
    const TYPES = ['pages', 'posts', 'products', 'snippets', 'skills', 'memory', 'options', 'menus', 'widgets'];

    /** Post meta keys that are safe/useful to carry between sites. */
    private static function meta_whitelist(): array
    {
        return [
            '_elementor_data', '_elementor_edit_mode', '_elementor_template_type', '_elementor_version',
            '_wp_page_template', '_claudpress_schema',
            '_claudpress_seo_title', '_claudpress_seo_desc', '_claudpress_seo_kw', '_claudpress_seo_canonical',
            '_claudpress_seo_noindex', '_claudpress_seo_nofollow', '_claudpress_seo_og_title', '_claudpress_seo_og_desc', '_claudpress_seo_og_image',
            '_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'rank_math_description',
        ];
    }

    /** Options safe to migrate (never the settings option with secrets). */
    private static function option_whitelist(): array
    {
        return [
            'blogname', 'blogdescription', 'claudpress_redirects', 'claudpress_site_schema', 'claudpress_robots_extra',
            'woocommerce_currency', 'woocommerce_default_country', 'woocommerce_weight_unit', 'woocommerce_dimension_unit',
        ];
    }

    public static function export(array $types, array $opts = []): array
    {
        $types = $types ?: self::TYPES;
        $entities = [];

        if (in_array('pages', $types, true)) {
            $entities['pages'] = self::export_posts('page', $opts);
        }
        if (in_array('posts', $types, true)) {
            $entities['posts'] = self::export_posts('post', $opts);
        }
        if (in_array('products', $types, true) && class_exists('WooCommerce')) {
            $entities['products'] = self::export_posts('product', $opts);
        }
        if (in_array('snippets', $types, true)) {
            $entities['snippets'] = Claudpress_Snippets::all_full();
        }
        if (in_array('skills', $types, true)) {
            $entities['skills'] = Claudpress::instance()->skills->all();
        }
        if (in_array('memory', $types, true)) {
            $entities['memory'] = Claudpress::instance()->memory->all();
        }
        if (in_array('options', $types, true)) {
            $opts_out = [];
            foreach (self::option_whitelist() as $k) {
                $v = get_option($k, null);
                if ($v !== null) {
                    $opts_out[$k] = $v;
                }
            }
            $entities['options'] = $opts_out;
        }
        if (in_array('menus', $types, true)) {
            $entities['menus'] = self::export_menus();
        }
        if (in_array('widgets', $types, true)) {
            $entities['widgets'] = self::export_widgets();
        }

        return [
            'bundle_version' => self::BUNDLE_VERSION,
            'source'         => home_url(),
            'generated_at'   => current_time('mysql'),
            'types'          => array_keys($entities),
            'entities'       => $entities,
        ];
    }

    private static function export_posts(string $type, array $opts): array
    {
        $q = new WP_Query([
            'post_type'      => $type,
            'post_status'    => $opts['status'] ?? ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => (int) ($opts['limit'] ?? -1),
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        $out = [];
        foreach ($q->posts as $p) {
            $meta = [];
            foreach (self::meta_whitelist() as $key) {
                $val = get_post_meta($p->ID, $key, true);
                if ($val !== '' && $val !== false) {
                    $meta[$key] = $val;
                }
            }
            $entry = [
                'type'       => $p->post_type,
                'title'      => $p->post_title,
                'slug'       => $p->post_name,
                'status'     => $p->post_status,
                'excerpt'    => $p->post_excerpt,
                'content'    => $p->post_content,
                'menu_order' => $p->menu_order,
                'meta'       => $meta,
                'categories' => wp_get_post_terms($p->ID, 'category', ['fields' => 'names']),
                'tags'       => wp_get_post_terms($p->ID, 'post_tag', ['fields' => 'names']),
                'featured_image' => get_post_thumbnail_id($p->ID) ? wp_get_attachment_url(get_post_thumbnail_id($p->ID)) : null,
            ];
            if ($p->post_type === 'product' && function_exists('wc_get_product')) {
                $prod = wc_get_product($p->ID);
                if ($prod) {
                    $entry['product'] = [
                        'type'          => $prod->get_type(),
                        'sku'           => $prod->get_sku(),
                        'regular_price' => $prod->get_regular_price(),
                        'sale_price'    => $prod->get_sale_price(),
                        'stock_status'  => $prod->get_stock_status(),
                        'manage_stock'  => $prod->get_manage_stock(),
                        'stock_quantity' => $prod->get_stock_quantity(),
                        'short_description' => $prod->get_short_description(),
                        'product_cats'  => wp_get_post_terms($p->ID, 'product_cat', ['fields' => 'names']),
                        'gallery'       => array_map('wp_get_attachment_url', $prod->get_gallery_image_ids()),
                    ];
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    // ---- menus & widgets ---------------------------------------------------

    /**
     * Nav menus, their items, and their theme-location assignments. Items that
     * point at a post/page are exported by slug so they can be relinked on the
     * destination; custom links keep their URL.
     */
    private static function export_menus(): array
    {
        $locations = get_nav_menu_locations();
        $by_menu   = [];
        foreach ($locations as $loc => $menu_id) {
            $by_menu[(int) $menu_id][] = $loc;
        }

        $out = [];
        foreach (wp_get_nav_menus() as $menu) {
            $items = [];
            foreach (wp_get_nav_menu_items($menu->term_id) ?: [] as $item) {
                $object_slug = '';
                if ($item->type === 'post_type' && $item->object_id) {
                    $obj = get_post((int) $item->object_id);
                    $object_slug = $obj ? $obj->post_name : '';
                } elseif ($item->type === 'taxonomy' && $item->object_id) {
                    $term = get_term((int) $item->object_id);
                    $object_slug = ($term && !is_wp_error($term)) ? $term->slug : '';
                }
                $items[] = [
                    'db_id'       => (int) $item->ID,
                    'parent_id'   => (int) $item->menu_item_parent,
                    'order'       => (int) $item->menu_order,
                    'title'       => $item->title,
                    'type'        => $item->type,          // custom | post_type | taxonomy
                    'object'      => $item->object,        // page | post | category …
                    'object_slug' => $object_slug,
                    'url'         => $item->url,
                    'target'      => $item->target,
                    'classes'     => is_array($item->classes) ? implode(' ', $item->classes) : '',
                    'attr_title'  => $item->attr_title,
                    'description' => $item->description,
                ];
            }
            $out[] = [
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'locations' => $by_menu[(int) $menu->term_id] ?? [],
                'items'     => $items,
            ];
        }
        return $out;
    }

    /** Sidebar assignments plus each widget instance's stored settings. */
    private static function export_widgets(): array
    {
        $sidebars = get_option('sidebars_widgets', []);
        if (!is_array($sidebars)) {
            $sidebars = [];
        }
        unset($sidebars['array_version']);

        // Collect the option blob for every widget base in use (widget_text, …).
        $bases = [];
        foreach ($sidebars as $widgets) {
            foreach ((array) $widgets as $widget_id) {
                if (preg_match('/^(.+)-(\d+)$/', (string) $widget_id, $m)) {
                    $bases[$m[1]] = true;
                }
            }
        }
        $instances = [];
        foreach (array_keys($bases) as $base) {
            $opt = get_option('widget_' . $base, null);
            if ($opt !== null) {
                $instances['widget_' . $base] = $opt;
            }
        }

        return ['sidebars_widgets' => $sidebars, 'instances' => $instances];
    }

    /**
     * Import a bundle. Idempotent: matches existing entities and updates them.
     * @return array report
     */
    public static function import(array $bundle, string $mode = 'upsert'): array
    {
        if (empty($bundle['entities']) || !is_array($bundle['entities'])) {
            return ['success' => false, 'error' => 'Bundle has no entities.'];
        }
        $report = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
        $e = $bundle['entities'];

        foreach (['pages', 'posts', 'products'] as $ptype) {
            foreach (($e[$ptype] ?? []) as $item) {
                self::import_post($item, $report);
            }
        }
        foreach (($e['snippets'] ?? []) as $s) {
            Claudpress_Snippets::save($s);
            $report['updated'][] = 'snippet:' . ($s['name'] ?? '');
        }
        foreach (($e['skills'] ?? []) as $s) {
            Claudpress::instance()->skills->save($s);
            $report['updated'][] = 'skill:' . ($s['name'] ?? '');
        }
        foreach (($e['memory'] ?? []) as $m) {
            Claudpress::instance()->memory->save((string) ($m['key'] ?? ''), (string) ($m['value'] ?? ''), (string) ($m['category'] ?? 'general'));
            $report['updated'][] = 'memory:' . ($m['key'] ?? '');
        }
        if (!empty($e['options']) && is_array($e['options'])) {
            foreach ($e['options'] as $k => $v) {
                if (in_array($k, self::option_whitelist(), true)) {
                    update_option($k, $v);
                    $report['updated'][] = 'option:' . $k;
                }
            }
        }
        // Menus after posts, so menu items can resolve the pages they point at.
        foreach (($e['menus'] ?? []) as $menu) {
            self::import_menu($menu, $report);
        }
        if (!empty($e['widgets']) && is_array($e['widgets'])) {
            self::import_widgets($e['widgets'], $report);
        }

        $report['success'] = true;
        $report['source']  = $bundle['source'] ?? null;
        return $report;
    }

    private static function import_post(array $item, array &$report): void
    {
        $type = $item['type'] ?? 'post';
        $slug = $item['slug'] ?? sanitize_title($item['title'] ?? '');

        // Match by slug within the type; products also try SKU.
        $existing = get_page_by_path($slug, OBJECT, $type);
        if (!$existing && $type === 'product' && !empty($item['product']['sku']) && function_exists('wc_get_product_id_by_sku')) {
            $by_sku = wc_get_product_id_by_sku($item['product']['sku']);
            if ($by_sku) {
                $existing = get_post($by_sku);
            }
        }

        $postarr = [
            'post_type'    => $type,
            'post_title'   => $item['title'] ?? '',
            'post_name'    => $slug,
            'post_status'  => $item['status'] ?? 'publish',
            'post_excerpt' => $item['excerpt'] ?? '',
            'post_content' => $item['content'] ?? '',
            'menu_order'   => (int) ($item['menu_order'] ?? 0),
        ];
        if ($existing) {
            $postarr['ID'] = $existing->ID;
            $id = wp_update_post($postarr, true);
        } else {
            $id = wp_insert_post($postarr, true);
        }
        if (is_wp_error($id)) {
            $report['errors'][] = ($item['title'] ?? $slug) . ': ' . $id->get_error_message();
            return;
        }

        foreach (($item['meta'] ?? []) as $k => $v) {
            update_post_meta($id, $k, wp_slash($v));
        }
        if (!empty($item['categories'])) {
            wp_set_object_terms($id, $item['categories'], 'category');
        }
        if (!empty($item['tags'])) {
            wp_set_object_terms($id, $item['tags'], 'post_tag');
        }
        if ($type === 'product' && !empty($item['product'])) {
            self::apply_product($id, $item['product'], $report);
        }
        if (!empty($item['featured_image'])) {
            self::sideload_featured($id, $item['featured_image']);
        }

        $report[$existing ? 'updated' : 'created'][] = $type . ':' . $slug;
    }

    private static function import_menu(array $menu, array &$report): void
    {
        $name = (string) ($menu['name'] ?? '');
        if ($name === '') {
            return;
        }
        $existing = wp_get_nav_menu_object($menu['slug'] ?? $name) ?: wp_get_nav_menu_object($name);
        if ($existing) {
            $menu_id = (int) $existing->term_id;
            // Clear existing items so a re-import mirrors the source exactly
            // rather than appending a second copy of every link.
            foreach (wp_get_nav_menu_items($menu_id) ?: [] as $old) {
                wp_delete_post((int) $old->ID, true);
            }
        } else {
            $menu_id = wp_create_nav_menu($name);
            if (is_wp_error($menu_id)) {
                $report['errors'][] = 'menu:' . $name . ': ' . $menu_id->get_error_message();
                return;
            }
            $menu_id = (int) $menu_id;
        }

        // Parents must exist before children: source db_id → new item id.
        $id_map = [];
        $items  = (array) ($menu['items'] ?? []);
        usort($items, static fn($a, $b) => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

        foreach ($items as $item) {
            $args = [
                'menu-item-title'       => (string) ($item['title'] ?? ''),
                'menu-item-url'         => (string) ($item['url'] ?? ''),
                'menu-item-target'      => (string) ($item['target'] ?? ''),
                'menu-item-classes'     => (string) ($item['classes'] ?? ''),
                'menu-item-attr-title'  => (string) ($item['attr_title'] ?? ''),
                'menu-item-description' => (string) ($item['description'] ?? ''),
                'menu-item-position'    => (int) ($item['order'] ?? 0),
                'menu-item-status'      => 'publish',
                'menu-item-type'        => (string) ($item['type'] ?? 'custom'),
            ];

            // Relink by slug on this site; fall back to a custom link if the
            // target does not exist here.
            $resolved = 0;
            if (($item['type'] ?? '') === 'post_type' && !empty($item['object_slug'])) {
                $obj = get_page_by_path((string) $item['object_slug'], OBJECT, (string) ($item['object'] ?? 'page'));
                $resolved = $obj ? (int) $obj->ID : 0;
            } elseif (($item['type'] ?? '') === 'taxonomy' && !empty($item['object_slug'])) {
                $term = get_term_by('slug', (string) $item['object_slug'], (string) ($item['object'] ?? 'category'));
                $resolved = ($term && !is_wp_error($term)) ? (int) $term->term_id : 0;
            }
            if ($resolved) {
                $args['menu-item-object']    = (string) ($item['object'] ?? '');
                $args['menu-item-object-id'] = $resolved;
            } elseif (($item['type'] ?? 'custom') !== 'custom') {
                $args['menu-item-type'] = 'custom';
                if ($args['menu-item-url'] === '') {
                    continue; // nothing sensible to link to
                }
            }

            $parent = (int) ($item['parent_id'] ?? 0);
            if ($parent && isset($id_map[$parent])) {
                $args['menu-item-parent-id'] = $id_map[$parent];
            }

            $new_id = wp_update_nav_menu_item($menu_id, 0, $args);
            if (!is_wp_error($new_id) && !empty($item['db_id'])) {
                $id_map[(int) $item['db_id']] = (int) $new_id;
            }
        }

        // Theme locations that exist on this theme.
        $locations = get_nav_menu_locations();
        $registered = get_registered_nav_menus();
        foreach ((array) ($menu['locations'] ?? []) as $loc) {
            if (isset($registered[$loc])) {
                $locations[$loc] = $menu_id;
            }
        }
        set_theme_mod('nav_menu_locations', $locations);

        $report[$existing ? 'updated' : 'created'][] = 'menu:' . $name;
    }

    /**
     * Widgets are restored by writing each widget base's instance option and
     * then the sidebar map. Sidebars this theme does not register are skipped —
     * writing them would orphan the widgets in wp_inactive_widgets.
     */
    private static function import_widgets(array $widgets, array &$report): void
    {
        foreach ((array) ($widgets['instances'] ?? []) as $option_name => $value) {
            if (strpos((string) $option_name, 'widget_') !== 0) {
                continue;
            }
            update_option($option_name, $value);
            $report['updated'][] = 'widgets:' . $option_name;
        }

        global $wp_registered_sidebars;
        $incoming = (array) ($widgets['sidebars_widgets'] ?? []);
        $current  = get_option('sidebars_widgets', []);
        if (!is_array($current)) {
            $current = [];
        }
        foreach ($incoming as $sidebar_id => $ids) {
            if ($sidebar_id !== 'wp_inactive_widgets' && !isset($wp_registered_sidebars[$sidebar_id])) {
                $report['skipped'][] = 'sidebar:' . $sidebar_id . ' (not registered by this theme)';
                continue;
            }
            $current[$sidebar_id] = (array) $ids;
            $report['updated'][]  = 'sidebar:' . $sidebar_id;
        }
        update_option('sidebars_widgets', $current);
    }

    private static function apply_product(int $id, array $p, array &$report): void
    {
        if (!function_exists('wc_get_product')) {
            return;
        }
        // Only a simple product crosses a bundle intact. A variable product's
        // identity lives in child posts and in attribute taxonomies whose term
        // IDs and slugs mean nothing on the destination, so rebuilding it from
        // JSON produces a product that looks right and cannot be bought. Its
        // content is still imported; the store side is left to be rebuilt on the
        // destination with the WooCommerce tools (wc_product / execute_php),
        // which go through WooCommerce's own API instead of guessing at it.
        $type = (string) ($p['type'] ?? 'simple');
        if ($type !== 'simple') {
            $label = ($p['sku'] ?? '') !== '' ? $p['sku'] : (string) $id;
            $report['skipped'][] = 'product-data:' . $label . ' (' . $type . ' product — rebuild its attributes and variations on the destination with wc_product)';
            return;
        }
        $product = wc_get_product($id);
        if (!$product) {
            $product = new WC_Product_Simple();
            $product->set_id($id);
        }
        foreach (['sku' => 'set_sku', 'regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price', 'stock_status' => 'set_stock_status', 'short_description' => 'set_short_description'] as $k => $m) {
            if (isset($p[$k]) && $p[$k] !== '' && method_exists($product, $m)) {
                try {
                    $product->{$m}($p[$k]);
                } catch (Throwable $e) {
                }
            }
        }
        if (!empty($p['manage_stock'])) {
            $product->set_manage_stock(true);
            if (isset($p['stock_quantity'])) {
                $product->set_stock_quantity((int) $p['stock_quantity']);
            }
        }
        if (!empty($p['product_cats'])) {
            $ids = [];
            foreach ($p['product_cats'] as $name) {
                $t = term_exists($name, 'product_cat') ?: wp_insert_term($name, 'product_cat');
                if (!is_wp_error($t)) {
                    $ids[] = (int) $t['term_id'];
                }
            }
            $product->set_category_ids($ids);
        }
        $product->save();
    }

    private static function sideload_featured(int $post_id, string $url): void
    {
        if (get_post_thumbnail_id($post_id)) {
            return; // already has one
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att = media_sideload_image($url, $post_id, null, 'id');
        if (!is_wp_error($att)) {
            set_post_thumbnail($post_id, $att);
        }
    }
}
