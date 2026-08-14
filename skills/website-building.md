---
name: website-building
description: Build a beautiful website, landing page, homepage, header, or footer in WordPress from a brief, screenshot, or design. Use when the user wants to build/redesign a page or whole site, clone a design pixel-for-pixel, or set up theme, navigation, and footer. For a brand-new site with no brief yet, load `site-build-kickoff` first — it runs the stack menu + intake questions this skill assumes are already answered.
enable_agentic: true
enable_prompt: true
---

# Website building

Turn a brief, screenshot, or design into a live WordPress page. First decide the **build mode** with the user (or from the `build.page_modes` memory):

## Mode 1 — Pixel-perfect custom-coded page (highest fidelity)
Best when the design must match exactly and the client won't edit it themselves.
1. Write the full page as responsive HTML + CSS (mobile-first, semantic, accessible, SEO-optimized: one H1, meta later via `seo_meta`).
2. Create the page: `create_post post_type=page title="…" status=publish` (empty content is fine).
3. Attach the design as a custom template: `snippet_save snippet_type=php location=page_template target_id=<pageId> active=true` with the full markup. The template snippet may contain inline HTML with `<?php ?>` islands and should call `get_header()`/`get_footer()` only if you want the theme's nav/footer — otherwise output a self-contained document.
4. Open the page link to verify pixel accuracy. Iterate by editing the same snippet.
5. Add real images (see below) — never ship grey placeholders.

## Mode 2 — Editable page (Gutenberg or Elementor)
Best when the client needs to edit later. Lower design ceiling, fully editable.
- Gutenberg: `create_post` with block markup in `content`.
- Elementor: follow the `elementor-page-building` skill (run `elementor_settings action=prepare_for_ai` first).

## Site chrome (theme, nav, footer)
- Pick a stack (usually already chosen by `site-build-kickoff`): **Xpro + Elementor** (lightweight, Xpro Theme Builder for header/footer) or **Solace + Elementor** (see `solace-header-footer` skill — has real traps, read it first) or Elementor Pro Theme Builder if active. Install with `manage_theme action=install theme=<slug>` then `action=activate`.
- Build the nav bar and footer once with the chosen stack's builder, then reuse across pages.
- Apply the brand kit (colors, fonts, spacing) site-wide so every page is cohesive — store it in memory and in the Elementor kit.

## Images (critical — do not skip)
Designs need real images. Use `image_search` (free Openverse, no key) to fetch and attach a relevant licensed image; use `image_generate` only if an image API key is configured. Always set descriptive alt text. Match orientation to the slot (wide hero, square avatar).

## Finish
- Run the `seo-optimization` pass on the new page.
- Report the live URL and the edit path.
- Save the design system, chosen build mode, and section patterns with `memory_save`.

## Guardrails
- Confirm before overwriting an existing homepage or template.
- Keep everything mobile-responsive; test narrow widths mentally and fix overflow.
- Prefer child theme or snippets over editing core/parent theme files.
