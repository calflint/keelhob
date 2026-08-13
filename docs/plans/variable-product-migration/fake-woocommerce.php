<?php
/**
 * PARKED — not part of tests/run.php. Kept with the deferred plan in
 * ../variable-product-migration.md; reusable as is when that work resumes.
 *
 * Just enough WooCommerce + WordPress term/media API to exercise the migration
 * engine's product path (attributes, variations, sideloaded variation images)
 * outside a real install.
 *
 * The fakes model the two things that actually make variation migration hard:
 * taxonomy attributes are stored as *term IDs* on the parent but as *term
 * slugs* on each variation, and the destination site's term IDs/slugs are not
 * the source site's. Everything is in-memory; cp_wc_reset() clears it.
 */

function cp_wc_reset(): void
{
    $GLOBALS['cp_wc'] = [
        'products'   => [],   // id => CP_Fake_Product
        'next_id'    => 100,
        'terms'      => [],   // term_id => ['term_id','name','slug','taxonomy']
        'next_term'  => 500,
        'taxonomies' => [],   // taxonomy => attribute id
        'thumbs'     => [],   // post_id => attachment id
        'next_att'   => 900,
        'synced'     => [],   // product ids passed to WC_Product_Variable::sync()
    ];
}

cp_wc_reset();

// ---- terms -----------------------------------------------------------------

function taxonomy_exists(string $taxonomy): bool
{
    return isset($GLOBALS['cp_wc']['taxonomies'][$taxonomy]);
}

function register_taxonomy(string $taxonomy, $object_type = [], array $args = []): void
{
    $GLOBALS['cp_wc']['taxonomies'][$taxonomy] = $GLOBALS['cp_wc']['taxonomies'][$taxonomy] ?? count($GLOBALS['cp_wc']['taxonomies']) + 1;
}

function wc_create_attribute(array $args)
{
    $slug = (string) ($args['slug'] ?? sanitize_title((string) ($args['name'] ?? '')));
    if ($slug === '' || strlen($slug) > 28) {
        return new WP_Error('invalid_product_attribute_slug', 'Slug too long');
    }
    $taxonomy = 'pa_' . $slug;
    $GLOBALS['cp_wc']['taxonomies'][$taxonomy] = count($GLOBALS['cp_wc']['taxonomies']) + 1;
    return $GLOBALS['cp_wc']['taxonomies'][$taxonomy];
}

function wc_attribute_taxonomy_id_by_name(string $taxonomy): int
{
    $taxonomy = strpos($taxonomy, 'pa_') === 0 ? $taxonomy : 'pa_' . $taxonomy;
    return (int) ($GLOBALS['cp_wc']['taxonomies'][$taxonomy] ?? 0);
}

function wc_attribute_label(string $name, $product = null): string
{
    return ucfirst(str_replace('pa_', '', $name));
}

/** Real term_exists() matches on either slug or name. */
function term_exists($term, string $taxonomy = '', $parent = null)
{
    foreach ($GLOBALS['cp_wc']['terms'] as $t) {
        if ($taxonomy !== '' && $t['taxonomy'] !== $taxonomy) {
            continue;
        }
        if ((string) $term === $t['slug'] || (string) $term === $t['name'] || (string) $term === (string) $t['term_id']) {
            return ['term_id' => $t['term_id'], 'term_taxonomy_id' => $t['term_id']];
        }
    }
    return null;
}

function wp_insert_term(string $name, string $taxonomy = '', array $args = [])
{
    $slug = (string) ($args['slug'] ?? '');
    if ($slug === '') {
        $slug = sanitize_title($name);
    }
    if (term_exists($slug, $taxonomy)) {
        return new WP_Error('term_exists', 'Term already exists');
    }
    $id = $GLOBALS['cp_wc']['next_term']++;
    $GLOBALS['cp_wc']['terms'][$id] = ['term_id' => $id, 'name' => $name, 'slug' => $slug, 'taxonomy' => $taxonomy];
    return ['term_id' => $id, 'term_taxonomy_id' => $id];
}

function get_term($term_id, string $taxonomy = '')
{
    $t = $GLOBALS['cp_wc']['terms'][(int) $term_id] ?? null;
    return $t ? (object) $t : null;
}

/** Test helper: seed a term with an explicit slug, as a real site would have. */
function cp_wc_seed_term(string $taxonomy, string $name, string $slug): int
{
    $id = $GLOBALS['cp_wc']['next_term']++;
    $GLOBALS['cp_wc']['terms'][$id] = ['term_id' => $id, 'name' => $name, 'slug' => $slug, 'taxonomy' => $taxonomy];
    $GLOBALS['cp_wc']['taxonomies'][$taxonomy] = $GLOBALS['cp_wc']['taxonomies'][$taxonomy] ?? 1;
    return $id;
}

function cp_wc_term_by_id(int $id): ?array
{
    return $GLOBALS['cp_wc']['terms'][$id] ?? null;
}

// ---- media -----------------------------------------------------------------

function get_post_thumbnail_id($post = null)
{
    return $GLOBALS['cp_wc']['thumbs'][(int) $post] ?? 0;
}

function set_post_thumbnail($post, int $thumbnail_id): bool
{
    $GLOBALS['cp_wc']['thumbs'][(int) (is_object($post) ? $post->ID : $post)] = $thumbnail_id;
    return true;
}

function wp_get_attachment_url(int $id)
{
    return 'https://source.test/wp-content/uploads/att-' . $id . '.jpg';
}

function media_sideload_image(string $file, int $post_id = 0, $desc = null, string $return = 'html')
{
    if (strpos($file, 'http') !== 0) {
        return new WP_Error('image_sideload_failed', 'Invalid URL');
    }
    return $GLOBALS['cp_wc']['next_att']++;
}

// ---- products --------------------------------------------------------------

class WC_Product_Attribute
{
    private int $id = 0;
    private string $name = '';
    private array $options = [];
    private int $position = 0;
    private bool $visible = false;
    private bool $variation = false;

    public function set_id(int $id): void { $this->id = $id; }
    public function get_id(): int { return $this->id; }
    public function set_name(string $n): void { $this->name = $n; }
    public function get_name(): string { return $this->name; }
    public function set_options(array $o): void { $this->options = $o; }
    public function get_options(): array { return $this->options; }
    public function set_position(int $p): void { $this->position = $p; }
    public function get_position(): int { return $this->position; }
    public function set_visible(bool $v): void { $this->visible = $v; }
    public function get_visible(): bool { return $this->visible; }
    public function set_variation(bool $v): void { $this->variation = $v; }
    public function get_variation(): bool { return $this->variation; }
    public function is_taxonomy(): bool { return $this->id > 0 && strpos($this->name, 'pa_') === 0; }
}

class CP_Fake_Product
{
    protected int $id = 0;
    protected array $props = [];
    protected array $attributes = [];
    protected array $children = [];
    protected array $category_ids = [];
    protected int $parent_id = 0;
    protected string $status = 'publish';

    public function __construct(int $id = 0)
    {
        if ($id && isset($GLOBALS['cp_wc']['products'][$id])) {
            $src = $GLOBALS['cp_wc']['products'][$id];
            $this->id = $id;
            $this->props = $src->export_props();
            $this->attributes = $src->get_attributes();
            $this->children = $src->get_children();
            $this->parent_id = $src->get_parent_id();
        } elseif ($id) {
            $this->id = $id;
        }
    }

    public function export_props(): array { return $this->props; }

    public function get_id(): int { return $this->id; }
    public function set_id(int $id): void { $this->id = $id; }
    public function get_type(): string { return 'simple'; }
    public function is_type($type): bool { return is_array($type) ? in_array($this->get_type(), $type, true) : $this->get_type() === $type; }

    public function get_parent_id(): int { return $this->parent_id; }
    public function set_parent_id(int $id): void { $this->parent_id = $id; }
    public function set_status(string $s): void { $this->status = $s; }
    public function get_status(): string { return $this->status; }

    public function get_children(): array { return $this->children; }
    public function add_child(int $id): void { if (!in_array($id, $this->children, true)) { $this->children[] = $id; } }

    public function get_attributes(): array { return $this->attributes; }
    public function set_attributes(array $a): void { $this->attributes = $a; }

    public function set_category_ids(array $ids): void { $this->category_ids = $ids; }
    public function get_category_ids(): array { return $this->category_ids; }

    public function get_gallery_image_ids(): array { return (array) ($this->props['gallery'] ?? []); }
    public function get_image_id() { return $this->props['image_id'] ?? 0; }
    public function set_image_id($id): void { $this->props['image_id'] = $id; }

    public function set_sku($v): void
    {
        foreach ($GLOBALS['cp_wc']['products'] as $p) {
            if ($p->get_id() !== $this->id && (string) $p->get_sku() === (string) $v && $v !== '') {
                throw new RuntimeException('Duplicate SKU');
            }
        }
        $this->props['sku'] = (string) $v;
    }
    public function get_sku(): string { return (string) ($this->props['sku'] ?? ''); }

    public function set_regular_price($v): void { $this->props['regular_price'] = (string) $v; }
    public function get_regular_price(): string { return (string) ($this->props['regular_price'] ?? ''); }
    public function set_sale_price($v): void { $this->props['sale_price'] = (string) $v; }
    public function get_sale_price(): string { return (string) ($this->props['sale_price'] ?? ''); }
    public function set_stock_status($v): void { $this->props['stock_status'] = (string) $v; }
    public function get_stock_status(): string { return (string) ($this->props['stock_status'] ?? 'instock'); }
    public function set_manage_stock($v): void { $this->props['manage_stock'] = (bool) $v; }
    public function get_manage_stock(): bool { return (bool) ($this->props['manage_stock'] ?? false); }
    public function set_stock_quantity($v): void { $this->props['stock_quantity'] = $v === null ? null : (int) $v; }
    public function get_stock_quantity() { return $this->props['stock_quantity'] ?? null; }
    public function set_weight($v): void { $this->props['weight'] = (string) $v; }
    public function get_weight(): string { return (string) ($this->props['weight'] ?? ''); }
    public function set_description($v): void { $this->props['description'] = (string) $v; }
    public function get_description(): string { return (string) ($this->props['description'] ?? ''); }
    public function set_short_description($v): void { $this->props['short_description'] = (string) $v; }
    public function get_short_description(): string { return (string) ($this->props['short_description'] ?? ''); }

    public function save(): int
    {
        if (!$this->id) {
            $this->id = $GLOBALS['cp_wc']['next_id']++;
        }
        $GLOBALS['cp_wc']['products'][$this->id] = $this;
        if ($this->parent_id && isset($GLOBALS['cp_wc']['products'][$this->parent_id])) {
            $GLOBALS['cp_wc']['products'][$this->parent_id]->add_child($this->id);
        }
        return $this->id;
    }
}

class WC_Product_Simple extends CP_Fake_Product
{
    public function get_type(): string { return 'simple'; }
}

class WC_Product_Variable extends CP_Fake_Product
{
    public function get_type(): string { return 'variable'; }

    public static function sync($product_id, bool $save = true): void
    {
        $GLOBALS['cp_wc']['synced'][] = (int) $product_id;
    }
}

class WC_Product_Variation extends CP_Fake_Product
{
    public function get_type(): string { return 'variation'; }
}

class WooCommerce
{
}

function wc_get_product($id = false)
{
    if ($id instanceof CP_Fake_Product) {
        return $id;
    }
    return $GLOBALS['cp_wc']['products'][(int) $id] ?? false;
}

function wc_get_product_id_by_sku(string $sku): int
{
    foreach ($GLOBALS['cp_wc']['products'] as $id => $p) {
        if ($sku !== '' && $p->get_sku() === $sku) {
            return (int) $id;
        }
    }
    return 0;
}

/** Test helper: build and persist a product of a given class with props set. */
function cp_wc_make(string $class, array $props = [], int $id = 0): CP_Fake_Product
{
    $p = new $class($id);
    if ($id) {
        $p->set_id($id);
    }
    foreach ($props as $k => $v) {
        $setter = 'set_' . $k;
        if (method_exists($p, $setter)) {
            $p->{$setter}($v);
        }
    }
    $p->save();
    return $p;
}
