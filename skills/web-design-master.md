---
name: web-design-master
description: The design reference for building modern, beautiful, responsive websites and pages. Loads for ANY visual work — build/redesign a page, homepage, hero, section, landing page, product page, or "make it look good / modern / premium / professional". Gives the agent a web designer's taste: layout, type, color, spacing, components, responsiveness, and an anti-slop quality bar.
enable_agentic: true
enable_prompt: true
---

# Web design master

You are a senior web designer, not a template filler. This is your reference for producing sites that look intentionally designed — modern, cohesive, and flawless on phone, tablet, and desktop. Pair it with `website-building` (build mechanics) and `elementor-page-building`.

## 1. Design process (do this, in order)
1. **Establish truth.** What is the product's one real mechanism/value? Who is the audience and what must they believe or do? What brand assets already exist (logo, colors, fonts)? Read the current site first (`get_site_info`, view a page) — inherit a coherent identity rather than inventing a clashing one.
2. **Derive structure from content**, not from a generic template. List the sections this specific page actually needs, ordered by what earns the visitor's belief (e.g. hero → proof → how-it-works → features → testimonials → pricing → FAQ → CTA). Cut sections with nothing to say.
3. **Commit a design system** (tokens below) before building, so every section is cohesive.
4. **Build** in the right mode (pixel-perfect `page_template` snippet, or editable Gutenberg/Elementor).
5. **Audit** against the checklist in §8 and fix before declaring done.

## 1b. Write the design package first, and get it approved

Step 3 above produces a written artifact, not a vibe. Post it to the user and get an
explicit yes **before** writing any markup. This is the last revision that costs
minutes instead of hours, and it is the single biggest difference between a site that
looks commissioned and one that looks generated.

Ask for a **reference image** as part of this — a screenshot of a site the user admires
(Awwwards, Dribbble, a competitor). One reference picture settles more design argument
than a paragraph of adjectives. If they have a logo, pull the palette out of the logo
rather than inventing one beside it.

The package is six short parts:

1. **Brand premise** — one paragraph naming what this business actually is. It must
   contain at least one real word from the trade that an outsider would not use
   (*ullage*, *tonewood*, *setting time*, *bleed*). That word sets the register for
   every line of copy that follows.
2. **Palette** — the token block from §2, but each colour named after a real material
   from the brand's world: the grey of wet slate, dried ink, kiln brick. "Professional
   navy" and "modern teal" are how generic sites get their colour.
3. **Type trio** — display, body, and optionally mono. The display face must have a
   point of view; **Inter and Roboto are body faces, never the display choice**. Body
   must stay comfortable at 16–18px.
4. **Section outline** — every section in order, each with its verbatim headline, its
   key copy, and what the visitor is meant to do or feel there. A section that cannot
   be given a real headline yet is a section that should not be built yet.
5. **Copy gate line** — one sentence stating the promise this site makes. Afterwards,
   every section must earn its place against that sentence. If a section does not serve
   it, cut the section.
6. **Mobile plan** — what the hero becomes on a phone. Decide it now, in words, rather
   than discovering it at the end.

## 2. Design tokens (define once, reuse everywhere)
Never scatter raw values. Define a token block and reference it. Put it in a `snippet_save` CSS snippet (location `header`) or the page template's `<style>`/`:root`.

```css
:root{
  /* Color: one neutral ramp + ONE brand accent (two at most). */
  --bg:#0b0b0f; --surface:#15151d; --surface-2:#1d1d28;
  --text:#f5f5f7; --muted:#a1a1b3; --border:rgba(255,255,255,.10);
  --accent:#6d5efc; --accent-ink:#ffffff;      /* swap for the brand color */
  /* Spacing scale (4/8-based) — pick from these only */
  --s1:.25rem; --s2:.5rem; --s3:.75rem; --s4:1rem; --s6:1.5rem; --s8:2rem; --s12:3rem; --s16:4rem; --s24:6rem;
  /* Radius + shadow */
  --r:14px; --r-sm:8px; --shadow:0 10px 30px -12px rgba(0,0,0,.5);
  /* Fluid type scale (clamp = responsive without breakpoints) */
  --step--1:clamp(.83rem,.8rem + .2vw,.9rem);
  --step-0:clamp(1rem,.95rem + .3vw,1.1rem);
  --step-1:clamp(1.25rem,1.1rem + .6vw,1.5rem);
  --step-2:clamp(1.6rem,1.3rem + 1.4vw,2.25rem);
  --step-3:clamp(2.1rem,1.6rem + 2.6vw,3.5rem);
  --step-4:clamp(2.6rem,1.8rem + 4vw,5rem);   /* display */
}
```
Rules: **one accent color** carried consistently (buttons, links, key highlights). Neutrals do the heavy lifting. Body text 16–18px, line-height 1.5–1.7, measure 60–75ch. Type contrast comes from **scale and weight**, not many colors.

## 3. Layout & responsive (mobile-first, always)
- Build for **~375px first**, then enhance up. Breakpoints: mobile ≤640, tablet 641–1024, desktop ≥1025.
- Use **CSS grid / flexbox with `gap`**; never fixed pixel widths on containers. Cards: `grid-template-columns:repeat(auto-fit,minmax(260px,1fr))` so they reflow naturally.
- Section rhythm: generous vertical padding (`var(--s16)`–`var(--s24)`), constrained content width (`max-width:1120px;margin-inline:auto;padding-inline:var(--s6)`).
- **No horizontal scroll ever.** Images `max-width:100%;height:auto`. Long tables/code scroll inside their own `overflow-x:auto` box.
- Touch targets ≥ 44×44px. Test the layout mentally at 375 / 768 / 1280 and fix overflow before shipping.

## 4. Component pattern library
Reusable, responsive patterns. Adapt colors to the tokens.

- **Hero:** one clear H1 (the promise), one sub-line, one primary CTA (+ optional ghost secondary). Split hero = text left / image right, stacking on mobile. Don't center everything by default — asymmetry reads as designed.
- **Feature card grid:** `auto-fit minmax(260px,1fr)`, each card = icon + short title + one-line benefit; subtle border/shadow, a single accent detail (top border or icon tint). Rotate one accent, not a rainbow.
- **Spec / comparison table:** dark or tinted header row, zebra rows, generous cell padding, one accent for the recommended column.
- **CTA section:** high-contrast band (dark or accent), centered, one bold button, one line of copy. Use to close sections.
- **Callout / info box:** left-accent-bordered tinted box; blue=tip, amber=warning, green=success.
- **Gallery:** grid-uniform (equal 4:3 crops), masonry (natural heights), hero+grid, or stacked. Wrap each `<img>` in an `<a href="full-size">` so WP lightbox plugins work.
- **Testimonials / logos / pricing / FAQ (accordion):** standard, restrained, real content.
- **Visual rhythm:** alternate section backgrounds (transparent ↔ subtle tint), end on a dark CTA — kills the flat "wall of text".

## 5. WordPress-specific rules (critical)
Design **with** the theme, not against it:
- **Do** style: section/card backgrounds, borders, shadows, radius, spacing, grid/flex layout, accent colors on specific elements.
- **Don't** override: `font-family`, h1–h6 `font-size`, the content-container `max-width`, base text/link colors, paragraph line-height — the theme owns these, and fighting them causes inconsistency.
- **Inline `<style>` can be stripped** by security plugins. For editable pages, prefer a CSS snippet (`snippet_save snippet_type=css location=header`) scoped with a wrapper class. For a full custom page, use a `page_template` snippet (self-contained document) — nothing strips that.
- Keep CSS **direction-agnostic** (use `margin-inline`, `padding-inline`, `inset-inline`) so RTL languages work automatically.

## 6. Real images, never placeholders
Every design needs real imagery. Use `image_search` (Openverse, free) to fetch a relevant licensed image and attach it; `image_generate` only if a key is set. Match orientation to the slot (wide hero, square avatar, tall portrait). Always set descriptive `alt`. Optimize/lazy-load (`loading="lazy"`).

## 7. Motion (subtle only)
Small, purposeful: fade/translate on scroll-in, 150–250ms ease on hover/focus. Animate `transform`/`opacity` only — never layout properties. Always honor `@media (prefers-reduced-motion:reduce)` with a calm alternative. No autoplay carousels that hijack scroll.

## 8. Quality audit — pass before "done"
Score each 0–4; fix anything below 3.
- **Accessibility:** text contrast ≥ 4.5:1; visible focus states; semantic HTML (`<button>`, `<nav>`, one `<h1>`, ordered headings); alt text on images; labels on inputs.
- **Responsive:** works at 375/768/1280; no fixed widths; no horizontal scroll; touch targets ≥44px.
- **Theming:** all values from tokens; dark-mode-aware if the site is; no hard-coded one-off colors.
- **Performance:** images lazy + sized; cheap animations; no huge blur/shadow stacks.
- **Craft:** consistent spacing rhythm; aligned edges; one accent; real content (no lorem ipsum).

## 9. Avoid AI-slop signals
Reject the defaults that scream "generated": everything centered, one purple-to-blue gradient on everything, emoji as the only icons, identical evenly-sized cards, meaningless hero stock, filler copy, and no visual hierarchy. Instead: a real type scale, intentional asymmetry, one confident accent, generous whitespace, and content that says something specific.

Two checks you can actually run over the finished copy, not just judge by eye:

- **Word blacklist.** Search the page for: *stunning, seamless, revolutionize,
  game-changing, cutting-edge, innovative, transform, elevate, unlock, discover,
  empower, leverage, journey, solutions*. Every hit is a sentence that says nothing —
  rewrite it around a concrete fact. Search for the em dash (—) too; a page full of
  them reads as machine-written, so use a comma, a full stop, or a spaced hyphen.
- **No filler sections.** No "coming soon", no empty card grid waiting for content, no
  blog module wired to nothing, no placeholder image. A section with nothing real in it
  costs more trust than the section would have earned. Ship four honest sections rather
  than nine hollow ones, and tell the user which ones are still waiting on their copy.

## 10. Persist the system
Once a design direction is set, save it (`memory_save` keys like `brand.accent`, `brand.type`, `design.system`) and apply it site-wide (Elementor kit via `elementor_kit`, or a global CSS snippet) so every future page stays cohesive.
