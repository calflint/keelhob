---
name: woocommerce-store-operations
description: Run the WooCommerce shop end to end — add or edit products, manage stock and pricing, process and fulfil orders, create coupons and sales, and read sales reports. Use whenever the task involves the store, products, inventory, orders, customers, discounts, or shop revenue.
enable_agentic: true
enable_prompt: true
---

# WooCommerce store operations

You are operating a live WooCommerce store. Prefer the dedicated `wc_*` tools; drop to `execute_php` only for things they do not cover (WooCommerce class APIs are all available there).

## Orientation first
Before acting, call `get_site_info` (confirms WooCommerce + currency) and, for anything financial, `wc_settings action=get`. Never assume currency, tax mode, or country.

## Products
- Create with `wc_product action=create`. Always set: `name`, `regular_price`, a `short_description` (1–2 sentences, benefit-led), a fuller `description`, at least one image URL, and one or more `categories`. Set `sku` when the user has a scheme; if a duplicate-SKU error returns, ask rather than guessing.
- Pricing: `sale_price` must be below `regular_price`. To run a timed sale prefer a coupon or scheduled price, and tell the user the sale has no end date unless they set one.
- Stock: set `manage_stock=true` + `stock_quantity` for tracked items; otherwise set `stock_status`.
- Variable products: create the product `type=variable` with variation `attributes`, then add variations via `execute_php` using `WC_Product_Variation` (set attributes, price, stock, save).

### Pick the right product type — there are four, not one

Choosing wrong here creates work that has to be undone later, so decide before creating:

| Type | Use when | Watch for |
|---|---|---|
| **Simple** | one thing, one price | the default; don't reach further without a reason |
| **Variable** | same product, real choices (size, colour, weight) | needs attributes **first** — see below |
| **Virtual + downloadable** | ebook, preset pack, license | tick *Virtual* and the shipping tab disappears; set the file, download limit and expiry (unlimited/never unless the user says otherwise) |
| **External / affiliate** | you send the buyer elsewhere to pay | set the product URL and the button text; tax and shipping are irrelevant because the sale never happens here |

**Build attributes globally, never inline.** Create the attribute and its terms once
(`manage_taxonomy taxonomy=pa_<name>`, or WooCommerce → Products → Attributes), then
attach it to each product. An attribute typed directly into one product cannot be reused
by the next one, cannot be filtered on in the shop sidebar, and quietly forces a rebuild
the first time a second product needs the same sizes. Then generate the variations and
set price, SKU, image and weight per variation — but only override tax class and
shipping class per variation when they genuinely differ, because the parent cascades.

A variable product changes the storefront: the archive shows a price *range* and the
button becomes "Select options" instead of "Add to cart". Say so, or the owner will
report it as a bug.

### Product details that are load-bearing

- **SKU scheme**: pick one and keep it. A readable convention (`canine-crunch-01`,
  then `-02`… per variation) beats random strings when the owner is reading an order.
- **Short description** renders under the title and price on the product page; the long
  description is a tab further down. Put the reason to buy in the short one.
- **Weight and dimensions are the shipped package**, not the product itself. This is the
  most common data-entry mistake and it silently mis-prices carrier-calculated shipping.
- **Upsells** show on the cart page ("you may be interested in"); **cross-sells** show as
  "you may also like". Both are wasted until there are enough products to link.
- **Upload product images at a consistent aspect ratio.** WooCommerce crops thumbnails to
  one shape; mixed source ratios give a shop grid where every card crops differently, and
  no CSS fixes it after the fact.
- WordPress **rejects SVG uploads** by default. Convert to PNG rather than enabling SVG
  uploads, which is a real security trade-off the owner did not ask for.

## Orders
- List with `wc_order action=list` (filter by `status`). Read one with `action=get` before changing it.
- Advance fulfilment with `action=update_status` (processing → completed). Add `add_note` for the customer when you ship.
- Refunds are real money and are hard to reverse: confirm the amount and the order with the user before `action=refund`. Never refund on your own initiative.

## Coupons & promotions
- `wc_coupon action=create`: set `code` (uppercase, memorable), `discount_type`, `amount`, and where relevant `expiry_date`, `usage_limit`, `minimum_amount`. Report the final code back.
- Three discount types behave differently: **percentage** scales with the cart, **fixed
  cart** takes a flat amount off the whole order, **fixed product** comes off each
  qualifying item. A percentage coupon with no ceiling on a large order is how a
  promotion loses money — suggest a **maximum spend** alongside the minimum, and a
  **per-user** limit as well as a total one. Say what the worst-case discount is in
  currency before the owner approves it.

## Reporting
- `wc_report` for revenue, order count, AOV, and top sellers over a date range. Lead with the headline number and the date range, then the top sellers. Do not present unfinished/cancelled orders as revenue.

## Guardrails
- Destructive or financial actions (delete product, refund, bulk price change, change base country/currency): state exactly what you will do and get a yes first.
- After a batch change, verify by reading one affected record back and report what changed.
- Persist store conventions (brand voice, category structure, SKU scheme, price rounding) with `memory_save` so later sessions stay consistent.
