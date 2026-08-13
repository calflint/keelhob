# Plan — variable products in the migration bundle

**Status: deferred, not in the plugin.** Shipped in v1.4.0, then backed out before
release. Nothing in `includes/` carries variable-product attributes or variations
today. This document is what to build if it comes back, and why it was pulled.

## Where it stands now

`export_bundle` still exports the `product` block for every product — type, SKU,
prices, stock, short description, categories, gallery — and `import_bundle`
applies it **only when `type === 'simple'`**. Anything else (variable, grouped,
external) imports its post content, featured image, SEO meta and Elementor data
like any other page, and the store side is reported as skipped:

```
product-data:TEE (variable product — rebuild its attributes and variations on the destination with wc_product)
```

The agent rebuilds it on the destination with the WooCommerce tools —
`wc_product` for the parent and `execute_php` with `WC_Product_Variation` for the
children (see `skills/woocommerce-store-operations.md`). That path goes through
WooCommerce's own API on a site where the taxonomies and terms actually exist, so
it cannot produce the failure modes below.

## Why it was pulled

A variable product is the only entity in a bundle whose identity lives in two
places at once, and neither one is portable:

- the **parent** stores its attribute options as **term IDs** in `pa_*` taxonomies;
- each **variation** selects itself by **term slug**.

Term IDs are meaningless on another site, and slugs collide with whatever the
destination already has. Rebuilding that from JSON yields a product that looks
correct in the admin and cannot be added to the cart — the worst failure shape
there is, because nothing errors.

Two concrete bugs were found and fixed before the feature was pulled. Both are
worth re-reading before rebuilding it, because either will reappear:

1. **Attribute term slugs were lost.** The parent exported option *names*, so on
   a fresh destination `wp_insert_term('Large')` produced slug `large` while the
   variation still pointed at the source's `lg`. Every variation was unselectable.
   Fix: export `option_slugs` parallel to `options`, create destination terms with
   the source slug, and where the destination already had a same-named term under
   a different slug, record the shift in a per-bundle map and rewrite the incoming
   variation attributes to match.
2. **SKU-fallback hijack.** When no attribute combination matched, the import
   trusted `wc_get_product_id_by_sku()` and re-parented whatever it found —
   silently converting an unrelated simple product, or another parent's variation,
   into a child of the product being imported. Fix: only reuse a SKU match that is
   already a variation of *this* parent.

Both fixes passed. They were still not enough to ship on, because the remaining
gaps are structural rather than bugs:

- variations deleted at the source are never pruned on the destination (import is
  upsert-only), so a re-deploy leaves stale buyable variants behind;
- an emptied price or sale price is skipped rather than cleared, so a sale that
  ended at the source stays live on the destination;
- an attribute slug over 28 characters makes `wc_create_attribute` fail and the
  attribute is dropped with no signal in the import report;
- `virtual`, `downloadable`, dimensions, shipping class and variation
  `menu_order` were never carried at all;
- none of it had ever run against a real WooCommerce install.

## If it comes back

Order matters — 1 and 2 are prerequisites, 5 is the gate.

1. **Carry the slug**, not just the name, and remap variation attributes to the
   destination's slugs. This is the whole ballgame.
2. **Never re-parent by SKU** unless the match is already a child of this parent.
3. **Make re-import authoritative**: prune destination variations whose attribute
   signature is absent from the bundle, and clear emptied prices instead of
   skipping them. Both need an explicit opt-in flag on `import_bundle`, since a
   staging bundle should not be able to silently delete production variants.
4. **Report honestly**: surface dropped attributes (slug too long, taxonomy
   creation failure) and pruned variations in the import report. Silence is what
   made the original version dangerous.
5. **Test against real WooCommerce** before shipping — a round trip between two
   real installs, verified by adding a migrated variation to the cart. The unit
   harness proves the mapping logic, not that WooCommerce agrees with it.

## Parked work

`docs/plans/variable-product-migration/` holds the test harness written for the
pulled implementation. It is **not** part of `tests/run.php` and will not run as
is — it drives private methods (`export_attributes`, `export_variations`,
`build_attributes`, `apply_variations`, `variation_key`) that no longer exist.

- `fake-woocommerce.php` — in-memory WooCommerce: products, variations,
  attributes, terms and media, modelling the term-ID/term-slug split that makes
  this hard. Reusable as is.
- `test-migration-products.php` — 45 assertions: export shape, fresh-destination
  import, import onto a site that already owns the terms, idempotent re-import,
  variation image sideload, SKU-hijack safety, matching-key semantics.

To reinstate: move both into `tests/` (the test file into `tests/unit/`), restore
`tests/fake-wp/wp-admin/includes/{media,file,image}.php` as empty stubs — the
sideload path `require_once`s them — and rebuild the implementation until they
pass.
