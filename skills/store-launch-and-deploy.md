---
name: store-launch-and-deploy
description: Take a WooCommerce store from empty to able-to-take-real-orders (payments, shipping, tax), and ship changes from a staging site to production. Use when setting up checkout, enabling payment methods, configuring shipping/tax, launching a store, or deploying/migrating a site staging→production.
enable_agentic: true
enable_prompt: true
---

# Store launch & deploy

## Part A — make checkout work (payments · shipping · tax)
A store can't take orders until all three are configured. Confirm currency/base country first (`wc_settings action=get`).

1. **Payments** (`wc_payments`):
   - `action=list` to see gateway ids and enabled state.
   - Fast start with no processor: `action=enable gateway=cod` (cash on delivery) and/or `gateway=bacs` (bank transfer — then `action=configure` its account details).
   - Card payments: enable and `configure` the relevant gateway (e.g. Stripe: set `publishable_key`, `secret_key`, `testmode`; PayPal: set the email/client id). Ask the owner for keys — never invent them. Use `testmode: "yes"` until they've done a live test.
2. **Shipping** (`wc_shipping`):
   - Create a zone per region you ship to: `action=create_zone zone_name="UAE" regions=["AE"]`.
   - Add a method to each zone: `action=add_method zone_id=<id> method=flat_rate cost="15" title="Standard"` (or `free_shipping`, `local_pickup`).
   - Add `create_class` shipping classes if products need different rates.
3. **Tax** (`wc_tax`): `action=enable`, then `action=add_rate country=AE rate="5.0" name="VAT"` for each jurisdiction. See the tax notes below — the defaults matter more than the rate does.
4. **Verify**: read each back (`list`/`list_zones`/`list_rates`) and tell the owner exactly what a customer will see and pay at checkout.

### Tax — get these four decisions right before entering any rate

- **Inclusive or exclusive?** With prices entered *exclusive* of tax, a $100 product is
  entered as $100 and checkout adds the tax on top. With *inclusive*, the same product
  must be entered as $105 to net $100 at 5%. The customer pays the same either way; what
  changes is the number on the shelf. Ask which the owner wants — most US stores are
  exclusive, most EU/UK/GCC retail is inclusive — and never flip it on an existing
  catalogue without warning them that every displayed price moves.
- **Rates take four decimal places.** `7.2500`, not `7.25`. WooCommerce stores them that
  way and a short value can round wrong.
- **Overlapping rows resolve by priority — lowest number wins**, and only one rate per
  priority level applies. The standard pattern is a specific row (US / CA / 7.2500) at
  priority 1 and a nationwide fallback (US / blank state / 5.0000) at priority 2.
  **`wc_tax add_rate` cannot do this**: it hard-codes `tax_rate_priority = 1`, so two
  rows both land at priority 1 and the fallback fights the specific row. For the
  fallback pattern, insert the second row with `execute_php`:
  ```php
  WC_Tax::_insert_tax_rate(['tax_rate_country'=>'US','tax_rate_state'=>'','tax_rate'=>'5.0000',
    'tax_rate_name'=>'US Sales Tax','tax_rate_priority'=>2,'tax_rate_shipping'=>0,'tax_rate_class'=>'']);
  ```
- **`add_rate` also taxes shipping by default** (`tax_rate_shipping = 1`). Some
  jurisdictions do not tax shipping. If the owner's does not, set it to 0 the same way.

Tax is calculated on the customer's shipping address by default. Never source rates from
memory — have the owner confirm them, or point them at their tax authority's table.

### Shipping — the two things that surprise people

- A flat rate cost of `10.00` is **$10 per order**, however many items. To charge per
  item, the cost field takes `10.00 * [qty]`. Per-order suits small light goods;
  per-item suits anything bulky. Ask which, don't assume.
- **Narrower zones layer on top of broader ones.** A "Whole USA" zone with $10 flat rate
  plus a "California" zone with free shipping over $400 does what you'd expect: WooCommerce
  matches the most specific zone the address falls in. Build the broad zone first, then
  the exceptions. Shipping classes let one zone charge different rates per product group —
  set the class cost *and* the "no shipping class" cost, or unclassified products ship free
  by accident.

### The launch steps that are easy to forget

- **Site visibility.** A new WooCommerce install can sit behind "Coming soon". Flip it to
  Live or the owner sees their store and the public sees a holding page.
- **Order emails are enabled one at a time.** Walk the list — new order, processing,
  completed, cancelled, failed, refunded — and confirm which go to the admin and which to
  the customer. A store that takes an order and sends nothing looks broken to both sides.
- **Accounts and privacy.** Decide guest checkout, account creation during checkout, and
  login at checkout deliberately; they change the conversion path.
- **Legal pages.** Privacy Policy, Terms, Returns/Refunds must be real pages with real
  content and linked in the footer, and the WooCommerce *Terms and conditions* setting
  must point at the terms page. Leaving them as `#` links is the most common way a
  finished-looking store is not actually ready to take money. If the owner has no text
  yet, say so plainly rather than writing law for them.
- **Place one real end-to-end test order** before launch: add to cart, apply a coupon,
  check out, confirm the emails arrive, then move the order through to completed and
  refund it. Nothing else proves checkout works.

Guardrails: payment keys are secrets — set them, then confirm they're stored (the tool redacts them in its response). Don't switch a gateway out of test mode without the owner's explicit go-ahead.

## Part B — staging → production deploy
Build and validate on staging, then ship to the live site. Never edit production directly.

1. On **staging**, build/verify the work (pages, products, snippets, design).
2. Export what you changed: `export_bundle types=["pages","products","snippets","skills","memory","options"]`. Secrets are never exported.
3. Ship it, either:
   - **One call** (preferred): the owner sets the production URL + token in Claudpress → Settings, then `deploy_push types=[...]`. It exports here and imports on production in one step. **Confirm with the owner before pushing to a live site.**
   - **Manual**: take the bundle from `export_bundle` and run `import_bundle` on the production site (or hand it to the agent connected there).
4. Imports are **idempotent** — matched by slug/SKU/name/key, so re-deploying updates instead of duplicating. Featured images are sideloaded.
5. After deploy, spot-check production: load a migrated page/product and confirm it renders, then report what changed.

Guardrails: production is real. Announce exactly which entities you'll push and get a yes. Prefer pushing a narrow set (the things you actually changed) over "everything". Keep production abilities OFF except during a supervised deploy window.
