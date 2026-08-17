---
name: website-building
description: Build a beautiful website, landing page, homepage, header, or footer in WordPress from a brief, screenshot, or design. Use when the user wants to build/redesign a page or whole site, clone a design pixel-for-pixel, set up theme, navigation, and footer, or build a cinematic scroll-driven hero where the video scrubs with the scroll wheel. For a brand-new site with no brief yet, load `site-build-kickoff` first — it runs the stack menu + intake questions this skill assumes are already answered.
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

## Mode 3 — Cinematic scroll-scrub hero (the "$10k site" mode)

The look agencies charge five figures for: a full-bleed hero video whose playhead is
driven by the scroll wheel, with copy fading in and out over it. WordPress has no
native way to do this — no theme, no page builder, no block does it — but it is fully
achievable here, because a `page_template` snippet is an arbitrary HTML/CSS/JS document
served at a real WordPress URL. Build it in Mode 1, then add this layer.

Use it when the hero **is** the product (spirits, fragrance, cars, architecture,
fashion, luxury services). Do not use it for a store archive, a blog, or anything the
client edits weekly.

### What has to happen outside WordPress

Be honest with the user about these three, up front:

1. **Generating the footage.** Claudpress has `image_generate` and `image_search` for
   stills only. There is no video generation tool here. The user supplies the clip, or
   generates it with a video tool of their own.
2. **Re-encoding it.** A raw clip will not scrub — seeking between distant keyframes
   stutters. It must be re-encoded with dense keyframes, and ffmpeg does not run on a
   typical WordPress host. Run it on your own machine before uploading:
   ```bash
   ffmpeg -i raw.mp4 -c:v libx264 -crf 18 -preset slow \
     -g 8 -keyint_min 8 -pix_fmt yuv420p -movflags +faststart -an hero-scrub.mp4
   ```
   `-g 8 -keyint_min 8` is the whole trick: a keyframe every 8 frames gives the browser
   a seek target near any scroll position. `-an` because a scrubbed video is silent.
   Target 4–8 MB for 6 seconds at 1080p. Grainy footage tolerates `-crf 25`; smooth
   gradients need `-crf 18` or lower or they band.
   Also export a poster frame: `ffmpeg -i hero-scrub.mp4 -vframes 1 -q:v 2 poster.jpg`.
3. **Getting the file into the media library.** `upload_media` cannot take a video —
   its URL path uses WordPress's `media_sideload_image()`, which rejects any extension
   outside jpg/jpeg/jpe/png/gif/webp and returns "Invalid image URL". (Verified against
   WordPress core, not assumed.) Two routes that do work:
   - Ask the user to drag the file into **Media → Add New**. Simplest, always works.
   - Or do it yourself with `execute_php` from a URL the file is already reachable at:
     ```php
     require_once ABSPATH.'wp-admin/includes/file.php';
     $tmp  = download_url($url);
     $side = wp_handle_sideload(['name'=>'hero-scrub.mp4','tmp_name'=>$tmp], ['test_form'=>false]);
     $id   = wp_insert_attachment(['post_mime_type'=>$side['type'],'post_title'=>'Hero scrub','post_status'=>'inherit'], $side['file']);
     echo wp_get_attachment_url($id);
     ```
     This is verified working: it stores the file as `video/mp4` and serves it 200 over
     HTTP. `upload_media`'s base64 `data` path also works in principle but a multi-MB
     file rarely survives the request size limits.

Confirm the video URL returns 200 before writing any markup.

### The five patterns that make it work

All of this goes in the `page_template` snippet. Each one exists because the naive
version is visibly broken.

**Tall track, sticky stage.** The scroll distance is the timeline.
```css
.hero-track  { height: 400vh; }               /* longer track = slower scrub */
.hero-sticky { position: sticky; top: 0; height: 100vh; overflow: hidden; }
```

**Blob fetch, not `<video src>`.** Many hosts and CDNs mishandle the Range requests
that seeking depends on, which shows up as a video that plays but refuses to scrub.
Fetch the whole file once and hand the browser an object URL:
```js
const blob = await (await fetch(SRC)).blob();
video.src = URL.createObjectURL(blob);
await video.play().catch(() => {}); video.pause();
```
Show a progress indicator while it loads if the file is over ~8 MB.

**Lerp toward the target, never assign directly.** Setting `currentTime` straight from
the scroll position looks jittery. Ease toward it, frame-rate independent:
```js
let shown = 0, target = 0, prev = 0;
function loop(ts) {
  const dt = Math.min(ts - prev, 50); prev = ts;
  shown += (target - shown) * (1 - Math.pow(1 - 0.16, dt / 16.667));
  if (Math.abs(shown - video.currentTime) > 0.01) seekTo(shown);
  requestAnimationFrame(loop);
}
```

**Gate the seeks.** Chrome drops requests if you seek while a seek is in flight, which
reads as the video sticking then jumping.
```js
let busy = false, pending = null;
function seekTo(t){ if (busy) { pending = t; return; } busy = true; video.currentTime = t; }
video.onseeked = () => { busy = false; if (pending !== null) { const t = pending; pending = null; seekTo(t); } };
video.onerror  = () => { busy = false; };
```

**Five static gates.** Phones will not scrub — iOS throttles it and the file is a waste
of a data plan. Every one of these gets a static hero image plus real headline copy, and
the CSS media queries and the JS condition must test *exactly* the same things, or one
fires without the other and the phone gets a blank stage:
`max-width: 720px` · `max-width: 1024px` and portrait · `pointer: coarse` and portrait ·
`max-height: 560px` and landscape · `prefers-reduced-motion: reduce`.

### Copy over footage

Split the scroll into bands, each with one line of copy that fades up and out —
80–130vh of plateau where it sits still, ~20vh of eased ramp either side. Ease with
smoothstep, and only write to the DOM when the value actually changed.

Legibility is layered, in this order: a global top-down scrim, a tighter scrim behind
the active caption, a text-shadow token, and a small pill scrim behind any small text.
Pause the video on its busiest, brightest frame and check the copy still clears 4.5:1
there — that frame, not the average one, is what decides whether this looks premium.

### Before you call it done

Beyond the normal audit: scroll the hero in ~120px, 240px and 360px steps and confirm
every caption beat is readable on the way past; load the page at 390px wide and confirm
the static hero appears with no horizontal overflow; watch the console for 404s on the
video; and confirm the scrub still works after a hard refresh with an empty cache.

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
