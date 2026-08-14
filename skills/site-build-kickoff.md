---
name: site-build-kickoff
description: The FIRST skill to load whenever someone asks to build a WordPress site from scratch — "build me a website", "make my site", "I need a website for my business", or any brand-new-site request with no detailed brief attached. Runs a short menu + intake Q&A instead of requiring a long prompt, saves the answers to memory, then hands off to website-building/elementor-page-building/solace-header-footer/blog-publishing/seo-optimization. Do NOT use for edits to an existing page — only for a new site build.
enable_agentic: true
enable_prompt: true
---

# Site build kickoff

A simple "build me a website" is enough. Never demand a long brief — ask a short menu + a short batch of questions, then build. Keep every reply caveman-brief: short sentences, no filler, plain words. Save state as you go so a fresh session can resume.

## Step 0 — check memory first
`memory_list` / `memory_get` — if a stack choice and brief already exist (a prior kickoff was interrupted), resume from there instead of asking again.

## Step 1 — stack menu (ask this first, one question)
Ask the user to pick a builder/theme stack. Give a one-line reason for each so they can choose blind:

1. **Xpro + Elementor** (default) — free, lightweight theme, Xpro Theme Builder for header/footer. Fastest, no extra install.
2. **Solace + Elementor** — free Solace theme + Solace Extra's Site Builder for header/footer. Good when the user wants a more decorated/starter-template look. Read `solace-header-footer` skill before touching Solace's header/footer — it has two competing header systems and real traps.
3. **Elementor Pro Theme Builder** — only if `elementor_settings action=get` reports `pro_active: true`. Best header/footer flexibility (mega menus, popups).
4. **Custom-coded page** (no builder) — pixel-perfect, not client-editable. Only when the user explicitly wants max fidelity over editability.

If unsure, recommend #1. Install what's picked: `manage_theme`, `manage_plugin`, `elementor_settings action=prepare_for_ai`. Save it: `memory_save key=site.stack value=<choice>`.

## Step 2 — intake batch (ask together, one batch, not one-by-one interrogation)
Ask everything needed to build without guessing:

- **Site name / business name.**
- **Niche / what the business does** — one line is enough, more detail helps.
- **Primary goal** — leads, bookings, sales, portfolio, info only.
- **Pages** — which ones and how many (home, about, services, contact, etc.). If they don't know, propose a sensible default set for their niche and confirm.
- **Blog** — build blog posts now, or skip and do it later? If now: how many, and target keywords/topics if known.
- **Design system** — do they have brand colors/fonts/logo already, or should the agent propose one? If proposing, follow `web-design-master`.
- **Content sources** — where should images/videos come from: their own files (ask them to attach/upload), `image_search` (free stock), `image_generate` (if a key is set), or a reference site/screenshot to match.
- **Reference design** — any site or screenshot to match the style of? If yes, that changes the build to a clone workflow (see Step 3).
- **Domain/credentials** — only if going live now; otherwise skip.

Don't ask about things covered by the stack choice already (builder, header/footer tool). Don't ask more than this batch — extra questions can wait until they come up mid-build.

## Step 3 — save the brief
`memory_save` the whole intake as a structured brief (e.g. `site.name`, `site.niche`, `site.goal`, `site.pages`, `site.blog`, `site.design`, `site.content_source`, `site.stack`) so it survives context loss and every sub-task can read it back instead of re-asking the user.

## Step 4 — build order
1. Site chrome first: theme active, Elementor prepared, header + footer built and verified live on one page (use the stack's builder — Xpro/Solace/Pro Theme Builder per `elementor-page-building` / `solace-header-footer`).
2. Menus created (`manage_menu`) before any page links to them.
3. Homepage.
4. Remaining pages, one at a time, each checked against the brief and the `web-design-master` quality bar.
5. Blog posts — only if the brief says "now" (`blog-publishing` skill); otherwise leave for later and say so.
6. `seo-optimization` pass across every new page once content is final.
7. Clear Elementor cache once, at the end, not per-page.
8. Report the live URL(s) and what's left (e.g. blog deferred, images to swap).

## Delegating to sub-agents
If the environment supports spawning sub-agents (e.g. an `Agent`/Task tool is available), don't build every page yourself serially:
- Spawn one cheap sub-agent per independent page/section once chrome + brief + design system are locked, each given: the saved brief, the design tokens, the stack choice, and which single page to build.
- Keep header/footer and the design-system decision on the main thread — never delegate those, everything else depends on them.
- Review each sub-agent's page against the brief and `web-design-master` §8 checklist before marking it done; send it back with precise fixes if it misses.
- If no sub-agent tool is available, build sequentially yourself — don't block on it.

## Guardrails
- Never invent business facts (address, phone, testimonials, stats) — placeholder + flag it if the user hasn't given it.
- Never ship lorem ipsum or grey placeholder images — use `image_search`/`image_generate` or ask for real assets.
- Confirm before overwriting an existing live site's homepage.
- Stay caveman-brief in every message to the user, from the first menu question to the final report.
