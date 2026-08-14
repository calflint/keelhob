---
name: seo-optimization
description: Optimize pages, posts, and products for search AND AI answer engines — set SEO titles and meta descriptions, target keywords, add structured data, manage llms.txt and AI-crawler access, fix on-page and GEO issues, manage redirects and sitemaps. Use whenever the task involves SEO, GEO, AI search visibility, ranking, meta tags, schema, keywords, ChatGPT/Perplexity/AI Overviews citations, or "make this rank"/"get this cited by AI".
enable_agentic: true
enable_prompt: true
---

# SEO + GEO optimization

Two goals, one workflow: rank in classical search (Google/Bing) AND get cited by AI answer engines (ChatGPT, Perplexity, Google AI Overviews, Gemini, Claude). GEO is not a separate discipline bolted on after — AI engines extract and cite pages that are already well-structured, well-sourced, and fresh, so most of the work below serves both goals at once.

The `seo_*` tools write to whatever SEO plugin is active (Yoast, Rank Math, AIOSEO, SEOPress) and fall back to Claudpress's own head injection when none is present — so your changes apply either way.

## Before writing anything
If the site has brand/voice reference material (check `memory_get` for a saved brand brief, voice guide, or fact sheet), read it first. An AI search engine isn't matching keywords — it's deciding *who to recommend* — so generic copy that could belong to any site is a GEO liability, not just a style problem. Avoid AI-tell phrasing ("unlock," "leverage," "seamless," "in today's fast-paced world"); lead with a direct answer, not a preamble ("In this article we'll explore...").

## Workflow for a single URL
1. `seo_analyze post_id=… focus_keyword=…` — returns two scores: a classical `score` (title/meta length, keyword placement/density, word count, headings, links, alt coverage) and a `geo_score` (answer-first opening, FAQ-style headings, list/table structuring, schema presence, author bio, content freshness, outbound citations). Read the `checks` array — each failing check has a `note` explaining why it matters to AI engines, not just Google.
2. Fix failing classical checks, in priority order:
   - **SEO title** (`seo_meta action=set title=…`): 30–60 chars, keyword near the front, one clear promise. Do not stuff.
   - **Meta description** (`description=…`): 120–160 chars, includes the keyword naturally, ends with a reason to click.
   - **Keyword placement**: focus keyword in title, URL slug, first paragraph, and at least one H2.
   - **Density**: keep 0.5–2.5%. If higher, rewrite for readability, don't delete mechanically.
   - **Images**: every content image needs descriptive `alt` text.
   - **Internal links**: add at least one relevant internal link.
3. Fix failing GEO checks:
   - **Answer-first opening**: state the direct answer/definition in the first 1–2 sentences, before any throat-clearing.
   - **FAQ-style headings**: phrase 2–4 H2/H3s as real questions ("How much does X cost?") answered in 2–4 sentences directly underneath — this is what gets lifted verbatim as an AI citation.
   - **List/table structuring**: convert dense paragraphs of comparable items into a `<ul>`/`<ol>`/table where it fits naturally.
   - **Outbound citations**: link 1–2 authoritative external sources (not competitors) to signal the page is a sourced primary reference, not a rehash.
   - **Freshness**: update stale posts (>6 months) with a current date-stamped revision rather than leaving them static — AI engines deprioritize stale sources.
   - **Author bio**: make sure the WordPress user publishing has a filled-in bio (`manage_user`) — it feeds the `Person` author schema below and is a real E-E-A-T signal.
4. Re-run `seo_analyze` and report the before/after for both scores.

## Structured data
`seo_schema action=set` two ways:
- **Hand-written**: pass `schema={...}` (or an array of objects — Claudpress combines them into one `@graph` so a page can carry `Article` + `FAQPage` + `BreadcrumbList` together). Match the type to the page: `Article`/`BlogPosting` for posts, `Product` (+ `offers`, `aggregateRating`) for products, `LocalBusiness`/`Organization` site-wide, `BreadcrumbList` for navigation.
- **Generated**: pass `generate=article|faq|howto|local_business|person` with `data={...}` and Claudpress builds valid JSON-LD for you — `generate=article` automatically pulls the post author into a `Person` sub-schema (name, bio, author URL), which is exactly the attribution data AI engines use to decide whose content to trust and cite. `generate=faq` takes `data={"items":[{"question":"...","answer":"..."}]}` and is the fastest way to make FAQ-style headings machine-citable, not just human-readable.

Always use real values from the page — never placeholder text.

## Titles that win clicks (and citations)
Front-load the keyword, add a differentiator (year, number, benefit, location), stay under 60 chars so it doesn't truncate. Every page needs a *unique* title and description — never duplicate across pages.

## GEO infrastructure (site-wide, do this once per site)
- **llms.txt**: `seo_sitemap action=get_llms_txt` to check if one exists; `action=set_llms_txt content=…` to write it. This is the file AI engines read first to understand what a site is — format per llmstxt.org: an H1 site name, a one-line blockquote summary, then H2 sections with links to the pages worth knowing about (docs, key products/services, about/contact). Served automatically at `/llms.txt`.
- **AI crawler access**: `seo_sitemap action=get_ai_crawlers` to see current allow/disallow state for GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot, Applebot-Extended, Bytespider (all allowed by default). Never disallow one without the user explicitly asking to opt out of a specific AI engine — blocking these is invisible-to-AI-search by definition.
- **JS-rendered content**: if pricing, specs, or other commercial content only appears after client-side JS runs, AI crawlers (and many SEO crawlers) can't read it. Flag this to the user — it needs to render in the initial server HTML.

## Technical
- Redirects: when a slug changes, add a 301 with `seo_redirect action=add from=/old to=/new` so link equity and users survive.
- Sitemap: `seo_sitemap action=get_sitemap` for the URL; `action=ping` after a big content change (pings Bing only — Google retired sitemap ping in 2023, use Search Console for Google).
- robots.txt: `seo_sitemap action=set_robots` for extra rules — never block resources search engines or AI crawlers need to render/read the page.

## Guardrails
- Never set `noindex` on a page the user wants ranked, and never bulk-noindex without explicit confirmation.
- Never disallow an AI crawler without the user explicitly asking to.
- Write, don't overwrite blindly: read current SEO meta before replacing it, and keep the human's wording where it already works.
- Don't fabricate stats, testimonials, or credentials to game E-E-A-T signals — use real data only; ask the user for anything you don't have.
- If publishing content in bulk (e.g. via `create_post` in a loop), throttle it — sudden volume spikes read as spam to Google, not just AI engines.
- Save the site's keyword targets, brand voice, and title formula to `memory_save` so future content stays on-strategy.
