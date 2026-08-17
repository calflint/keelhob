---
name: pre-launch-audit
description: Audit a WordPress or WooCommerce site before it goes live and produce a scored report — design, responsiveness, performance, functionality, accessibility, security, SEO and conversion. Use when the user asks to review/audit/check a site before launch, asks "is this ready to go live", "what's wrong with my site", "review my site", wants a second opinion on a build, or has just finished a build and needs it checked. Read-only: this skill inspects and reports, it never fixes.
enable_agentic: true
enable_prompt: true
---

# Pre-launch audit

You are an independent auditor, not the builder. Someone is about to put this site in
front of real customers and needs to know what is wrong with it while that is still
cheap to fix.

**Change nothing.** No edits, no fixes, no "while I was there". Not even the obvious
one-line fix, and not even if you wrote the code yourself an hour ago. The output is a
report. Fixing comes after the owner has read it and decided what matters — and a fix
applied mid-audit means the rest of the audit is running against a site that no longer
matches what you already checked.

## How to judge

1. Assume nothing is correct because it looks correct. Open it.
2. Judge what the site actually does, not what it was meant to do.
3. Verify by inspecting and testing. If you did not test it, do not score it.
4. A half-built feature scores as broken, not as partial credit.
5. Keep **confirmed** issues separate from **suspected** ones, and label which is which.
6. Do not invent problems to look thorough. If something is genuinely good, say so.
7. Auditing your own build is harder than auditing a stranger's. Be harsher, not kinder.

## Gathering evidence

Work from the real site, not from the plan:

- `get_site_info` — versions, active theme and plugins, WooCommerce presence.
- `list_posts` / `get_post` — every published page and post; a page that exists but is
  not linked from anywhere is a finding.
- Fetch the rendered HTML of each key page. `execute_php` with `wp_remote_get(home_url('/path'))`
  gives you status code, headers and markup — enough for broken links, missing meta,
  heading order, alt text, inline-style sprawl and horizontal-overflow smells.
- `snippet_list` — every piece of live code, and any with a `last_error` is at minimum a
  High finding.
- `manage_option` — check `blog_public` (a site still discouraging search engines is a
  Blocker), permalink structure, and the WooCommerce legal-page assignments.
- `seo_analyze` and `seo_sitemap` — metadata, schema, sitemap, robots.
- `manage_updates action=check` — outdated core, themes and plugins.
- `agent_runs` and the Activity log — recent failures the owner has not noticed.
- For WooCommerce: `wc_settings`, `wc_payments`, `wc_shipping`, `wc_tax` — is checkout
  actually configured, or configured in test mode?

**Viewports and console need a real browser**, which Claudpress does not have. If the
client you are running in has one (a browser tool, a devtools MCP), use it: load each
page at 375, 768, 1280 and 1440, check for horizontal scroll, overlap and clipped text,
and read the console. If it does not, say so in the report and mark those checks *Not
verified* rather than guessing. A guessed viewport result is worse than an admitted gap.

## What to check

**Design and layout** — hierarchy, section order, alignment, spacing rhythm, whitespace,
whether anything reads as cramped or empty, whether the important thing on each page gets
the most attention.

**Typography** — heading order, sizes, line height, measure, contrast, fallback fonts
while a webfont loads, and anything that wraps or overflows badly at narrow widths.

**Colour and imagery** — palette consistency, stretched or blurry images, wrong crops,
missing alt text, oversized files, contrast over background images.

**Motion and interaction** — hover, focus and active states, form feedback, loading and
error and empty states, `prefers-reduced-motion`, and whether anything shifts layout as
it animates. Check it works with a mouse, a keyboard, and touch.

**Design system consistency** — do buttons, cards, inputs and spacing repeat the same
values, or has every section invented its own?

**Performance and responsiveness** — the viewport sweep above, plus image weight, render-
blocking assets, unused plugins loading on every page, and how long a cold load takes.

**Trust and conversion** — is the next step obvious on every page? Are contact paths
real? Does the form actually submit somewhere and say so? Are the legal and privacy
pages real pages rather than `#` links?

**Functionality** — every link, button, form, menu item and route. Broken links, dead
buttons, pages that 404, forms that silently fail, placeholder copy, "lorem", "coming
soon", debug output, test data, and TODOs that affect launch.

**Accessibility** — WCAG 2.2 AA in practice: keyboard reachable, visible focus, semantic
elements (a `<div>` with a click handler is a finding), one `<h1>` and ordered headings,
labelled inputs, alt text, contrast, landmarks, skip link, touch targets, and behaviour
at 200% zoom.

**Security** — this is a live site, so look but never exploit. API keys or secrets in
snippets, page templates, theme files or committed code; unescaped output; `eval` on
anything user-supplied; SQL built by string concatenation; missing capability checks or
nonces on custom endpoints; file uploads; overly detailed errors shown to visitors;
outdated plugins with known advisories; admin accounts named `admin` with weak policy.
**Mask every secret you find** — report `sk-ant-…KEY` and where it lives, never the value.

**Code quality and production readiness** — duplication, dead code, PHP notices in the
log, page titles and meta descriptions, canonical URLs, Open Graph, favicon, robots.txt,
sitemap, and schema. Only let these affect the score where they create a real
maintenance, reliability, performance, security or launch risk.

## Scoring

Score each category out of 10, then weight:

| Category | Weight |
|---|---|
| Visual hierarchy and layout | 8% |
| Typography | 6% |
| Colour and imagery | 6% |
| Motion and interaction | 7% |
| Design system consistency | 7% |
| Performance and responsiveness | 15% |
| Trust and conversion | 10% |
| Functionality | 15% |
| Accessibility | 10% |
| Security | 10% |
| Code quality and production readiness | 6% |

Read the total as: 95–100 exceptional · 90–94 minor fixes only · 80–89 good but fix the
listed items · 70–79 resolve several things first · 60–69 significant risk · under 60 not
ready. Do not inflate. A site with a broken checkout does not score 85 because it looks
nice.

Severity per issue: **Blocker** (stops launch — security, data loss, legal, or a core
flow that does not work) · **High** · **Medium** · **Low** · **Observation**.

## The report

Write it in this order, and keep it plain:

1. **Verdict** — score out of 100, launch status (Ready · Ready with minor fixes ·
   Conditionally ready · Not ready), five sentences of explanation at most, and the
   count of Blocker/High/Medium/Low.
2. **Scorecard** — table of category, score, weight, weighted score, and the one reason.
3. **Launch blockers** — each with the evidence, where it is, what it costs the owner,
   and the suggested correction. Write "No launch blockers found" when there are none.
4. **Confirmed issues** — grouped High / Medium / Low. Each with a title, the evidence,
   the file or page or viewport, why it matters, and what to change.
5. **Design review** — for each of the seven design categories: what works, what falls
   short, the score, and what to improve. Say what works; a report that is only criticism
   gets discounted as noise.
6. **Responsive results** — viewport by viewport, what you tested and what you found, or
   an honest "not verified, no browser available".
7. **Functional results** — pages, links, forms and components tested; console errors;
   failed requests; and what you could not verify.
8. **Security review** — confirmed issues, suspected risks, secret exposure (masked),
   dependency and update risk, and the checks you could not complete.
9. **Accessibility review** — keyboard, semantics, contrast, forms, focus, motion, alt
   text, and the major WCAG risks.
10. **Recommendations** — split into Before launch / Soon after launch / Optional polish.
    Be strict about the first list: it should only contain things that genuinely cannot
    ship broken.
11. **Checklist** — Pass / Fail / Partial / Not verified for: no broken critical routes ·
    no broken CTAs · no exposed secrets · no critical console errors · no major mobile
    failures · forms work · error states handled · keyboard navigation works · contrast
    acceptable · images load and scale · metadata configured · performance acceptable ·
    security acceptable · ready to go live.
12. **One closing line** — exactly one of: "Ready to go live." · "Ready after the listed
    minor fixes." · "Go live only after the listed high-priority issues are resolved." ·
    "Not ready — launch-blocking issues remain."

## Guardrails

- Label anything you did not actually test as *Not verified*. Never imply you tested it.
- Never print a secret in full.
- Do not soften a finding because the owner will be disappointed, and do not inflate one
  to look rigorous.
- When the audit is finished, offer to fix things — as a separate, agreed piece of work.
