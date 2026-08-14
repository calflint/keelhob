---
name: elementor-page-building
description: Build or edit fully editable pages, headers, and footers with Elementor (free or Pro, incl. Xpro Addons). Use whenever the task is to build/edit an Elementor page, header, footer, or template, or the site's builder is Elementor and the user wants client-editable pages.
enable_agentic: true
enable_prompt: true
---

# Elementor page building

Build **client-editable** pages with Elementor via the `elementor_page`, `elementor_settings`, and `elementor_kit` tools. (For maximum-fidelity pages the client won't edit, prefer a custom-coded page template — see the `website-building` skill.)

## One-time prep (do this first)
1. Confirm Elementor is active (`get_site_info`). If not: `manage_plugin action=install plugin=elementor` then `action=activate plugin=elementor/elementor.php`.
2. `elementor_settings action=prepare_for_ai` — this enables **Container (flexbox)** and disables the **Atomic editor**. This is essential: build with flexbox Containers, never the deprecated Section/Column widgets, and the Atomic/v4 editor confuses programmatic builds.
3. For custom headers/footers on Elementor **free**, use **Xpro Theme Builder** (already available on this site), or if the active theme is **Solace**, use the `solace-header-footer` skill instead — it has its own Site Builder with real traps. Elementor Pro's Theme Builder also works if `elementor_settings` reports `pro_active: true`.

## The data model
Elementor stores a page as `_elementor_data`: a JSON tree of elements. Each element is:

```json
{ "id": "a1b2c3d", "elType": "container", "settings": { ... }, "elements": [ ... ] }
```

- `elType`: `container` (flexbox — your default building block), or `widget` (with `"widgetType": "heading" | "text-editor" | "button" | "image" | ...`).
- `id`: a unique 7-char hex-ish string per element — generate one for every element.
- `settings`: element options (typography, spacing, colors, flex direction, etc.).
- Nest children in `elements`.

Read an existing page first with `elementor_page action=get` to learn the exact settings keys the current theme/widgets use, then mirror that shape. When unsure of a widget's settings, build a minimal version in the editor mentally, or inspect another page.

## Building
- `elementor_page action=create title="…" data=[…]` for a new page, or `action=set post_id=… data=[…]` to replace a page's tree.
- Structure top-down: a `container` per section (hero, features, testimonials, CTA, footer), each with flex settings and child widgets.
- After writing, Elementor CSS is regenerated automatically. Give the user the `edit_link` so they can fine-tune visually.

## Global design (brand kit)
Apply site-wide colors/fonts once with `elementor_kit action=set` (merges into the active kit's `_elementor_page_settings`: `system_colors`, `custom_colors`, `system_typography`). Every Elementor page inherits it.

## Guardrails
- Always `action=get` before `action=set` so you don't wipe a page you meant to extend.
- Keep element `id`s unique; duplicate ids break the editor.
- Verify by loading the page link after building; if the layout is empty, the JSON was malformed — re-read and fix.
- Save the site's chosen builder and any recurring section patterns with `memory_save`.
