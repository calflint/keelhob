---
name: code-snippets
description: Add custom functionality or behavior to the site by writing live code snippets (PHP, CSS, JS, HTML) — hooks, filters, shortcodes, tracking pixels, tweaks, fixes. Use when the task is to change how the site works or looks via code rather than content, or the user says "add code", "add a snippet", "add a hook/filter", "add tracking", "custom function".
enable_agentic: true
enable_prompt: true
---

# Code snippets

Use `snippet_save` to add real code that runs on the live site — the safe alternative to editing `functions.php`. A PHP snippet that fatals is auto-deactivated so the site never breaks.

## Types & where they run
- **php** — runs in WordPress. Do NOT include `<?php` tags. Register hooks/filters/shortcodes or define functions. Location `everywhere` (default) runs on `plugins_loaded`; `admin_only` / `frontend_only` scope it.
- **css** — injected in `<head>` (location `header`) — theme/layout tweaks.
- **js** — injected in `header` or `footer` — behavior, tracking, pixels.
- **html** — output in `header`, `footer`, `before_content`, `after_content`, or via `[claudpress_snippet id=N]`.

## Locations
`everywhere`, `admin_only`, `frontend_only`, `header`, `footer`, `before_content`, `after_content`, `shortcode`, `page_template` (serve raw PHP/HTML as a whole page for `target_id` — the pixel-perfect page mode).

## Conditions
Pass `conditions` to scope loading: `{ "logged_in": true, "front_page": true, "post_types": ["product"], "post_ids": [12,44] }`.

## Always explain the snippet to the owner

The site owner sees every snippet on the **Claudpress > Snippets** admin page, and
those four fields are the only thing standing between them and a page full of
unexplained code they are afraid to switch off. Fill all four, every time:

- `name` — short and concrete: "Sticky header on scroll", not "Custom code".
- `description` — one line, what it does.
- `purpose` — **why it exists**. What was asked for, or what problem it solves.
- `impact` — **what changes on the site**, and what stops working if it is turned off.
- `tags` — 1–3 short lowercase labels the owner can filter by: `["header","design"]`.

## Updates merge — send only what changes

`snippet_save` with an `id` **merges**: fields you omit keep their stored values.
To retitle a snippet, send `{id, name}` — the code is untouched. Never re-send the
whole body just to change one field.

## Workflow
1. Write the smallest snippet that does the job. For PHP, hook the right action/filter rather than running work at import time.
2. `snippet_save … active=true` with name, description, purpose, impact and tags.
3. Read the response. PHP is parse-checked before it is stored: a `lint_error` means the snippet was saved **inactive** — fix the syntax and re-save.
4. For PHP, after activating, call `snippet_get` to check `last_error` — if it auto-deactivated at runtime, read the error, fix, and re-save.
5. Verify the effect on the front end (load the page) before calling it done.

## Copying a snippet to another site

Every snippet has a portable `uid`, and every site has a short label (from
`get_site_info` → `site_label`). When the owner says "copy the sticky-header
snippet from Pranknologia to the other shop":

1. `get_site_info` on **both** sites to confirm which label is which.
2. `snippet_get` on the source — the returned payload is portable as-is.
3. `snippet_save` on the destination with that payload, but **drop `id`**, and set
   `origin_site` to the source's label and `origin_uid` to the source's `uid`.

Re-copying later with the same `origin_uid` **updates** the copy instead of adding a
second one — which also means it overwrites anything the owner edited on the
destination. Say so before you re-copy.

**What does not travel.** Hard-coded post/page IDs, `target_id`, menu IDs, category
IDs, uploaded-file URLs and absolute site URLs are almost never valid on the other
site. Read the code before copying and re-resolve every one of them by slug, title
or SKU on the destination.

## Examples
- Tracking pixel: `snippet_type=js location=header` with the pixel code.
- Remove a WooCommerce field: `snippet_type=php location=everywhere` adding a filter on `woocommerce_checkout_fields`.
- Section under every blog post: `snippet_type=html location=after_content` with `conditions={"post_types":["post"]}`.

## Guardrails
- Never call `exit`/`die` in an `everywhere` PHP snippet — it halts the site.
- Prefer snippets over editing theme files; they survive theme updates and auto-recover from fatals.
- Deactivate (don't delete) while iterating so you keep the code. Save reusable snippets' purpose to memory.
- The owner can edit or delete any snippet from the Snippets page, and those edits are not versioned. If code you wrote looks different from what you remember, read it with `snippet_get` before changing it — do not assume your version is still there.
