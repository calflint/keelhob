# Claudpress

**A self-contained MCP server for WordPress.** Upload one plugin, flip a switch, and connect Claude Code (or Claude Desktop, Cursor, Codex, any MCP client) to run your whole site — content, WooCommerce store, SEO, files, and PHP — with cross-session memory and a skills engine.

Don't want to run a client at all? Add your own API key and the site runs itself: on a schedule, or [from a Telegram chat on your phone](#chat-to-your-site-on-telegram--no-mcp-client-needed) — voice notes, screenshots, approve-by-button and `/undo` included.

Inspired by [Novamira](https://novamira.ai), rebuilt from scratch with **no external dependencies**, **no WordPress 6.9 requirement**, and **every "pro" feature unlocked**: bundled expert skills, persistent memory, WooCommerce, and SEO all included and free.

> ⚠️ **Development / staging only.** Claudpress gives a connected AI agent unrestricted control of this WordPress install (arbitrary PHP, database, filesystem). Only enable AI abilities on sites you can afford to break, with backups. Deactivating the plugin turns abilities off.

---

## Install

1. Download `claudpress.zip`.
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Install Now** → **Activate**.
3. Go to **Claudpress** in the admin menu.

Requires WordPress 6.3+ and PHP 8.0+. WooCommerce and any SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) are auto-detected if present — none are required.

## Connect Claude Code

On the **Claudpress → Connect** page you'll find a ready-to-paste command with your real URL and token filled in. It looks like:

```bash
claude mcp add claudpress-yoursite --transport http https://yoursite.com/wp-json/claudpress/v1/mcp --header "Authorization: Bearer cp_xxxxxxxx"
```

Run it in your terminal, start a new Claude Code session, and you'll see the Claudpress tools. Try: *"list the plugins on this site"* or *"show me the last 10 WooCommerce orders."*

### Other clients
The Connect page also outputs a JSON `mcpServers` block for Claude Desktop, Cursor, Codex, etc. You can authenticate with either the **bearer token** (simplest) or a WordPress **Application Password** (`Authorization: Basic base64(user:app_password)`) against the same endpoint.

## The safety switch

- **AI abilities** is a master toggle. When **off**, the endpoint authenticates but only exposes read-only introspection (`get_site_info`, `memory_list`, `skill_list`). When **on**, the full tool surface is live.
- Sandbox PHP files that fatal are auto-quarantined so the site never boot-loops.
- Every tool call is logged (Activity tab).

## What it can do

| Area | Tools |
|------|-------|
| **Power** | `execute_php`, `execute_sql`, `get_site_info` |
| **Filesystem** | `read_file`, `write_file`, `edit_file`, `delete_file`, `list_directory`, `write_sandbox_file` |
| **Content** | `create_post`, `update_post`, `get_post`, `list_posts`, `delete_post`, `upload_media`, `manage_taxonomy` |
| **Site admin** | `manage_option`, `manage_user`, `manage_plugin`, `manage_theme`, `manage_menu` |
| **WooCommerce** | `wc_product`, `wc_order`, `wc_coupon`, `wc_report`, `wc_settings` |
| **SEO** | `seo_meta`, `seo_analyze`, `seo_schema`, `seo_redirect`, `seo_sitemap` |
| **Code snippets** | `snippet_save`, `snippet_list`, `snippet_get`, `snippet_toggle`, `snippet_delete` |
| **Elementor** | `elementor_page`, `elementor_settings`, `elementor_kit` |
| **Ops** | `manage_updates`, `send_email`, `image_search` (Openverse, free), `image_generate` (API) |
| **Autonomy** | `get_dashboard`, `run_agent`, `agent_runs`, `task_save`, `task_list`, `task_run`, `task_delete`, `notify_owner` |
| **Checkout** | `wc_payments`, `wc_shipping`, `wc_tax` |
| **Deploy** | `export_bundle`, `import_bundle`, `deploy_push` |
| **Memory** | `memory_save`, `memory_get`, `memory_list`, `memory_delete` |
| **Skills** | `skill_list`, `skill_get`, `skill_save`, `skill_delete` |

`execute_php` is the universal escape hatch — anything WordPress, WooCommerce, or a plugin can do in PHP, the agent can do, even where no dedicated tool exists.

### Code snippets engine (WPCode-style)
`snippet_save` lets the agent write **real code that runs on the live site** — PHP, CSS, JS, or HTML — with location control (everywhere, header, footer, before/after content, shortcode, admin-only, or a full **custom page template** for pixel-perfect pages) and conditional loading. Any PHP snippet that triggers a fatal error is **auto-deactivated** by a shutdown handler, so the site never gets stuck. There's a master "Run code snippets" kill-switch in settings.

### Website & Elementor building
Two build modes, straight from the field-tested Claude-Code workflow: **(1) pixel-perfect custom-coded pages** (a `page_template` snippet renders exact HTML/CSS), and **(2) editable Gutenberg/Elementor pages**. The Elementor tools read/write the `_elementor_data` tree, create pages, apply a global brand kit, and run `prepare_for_ai` (Container/flexbox ON, Atomic editor OFF). Pick your builder in settings (Gutenberg / Elementor free+Xpro / Elementor Pro / Bricks / custom PHP).

### Images that actually show up
`image_search` pulls real, openly-licensed images from **Openverse (free, no API key)** and attaches them; `image_generate` uses an OpenAI-compatible key if you add one in settings.

### Default memory
On install, Claudpress seeds cross-session memory with the vital operating context (capabilities, build modes, Elementor setup, image strategy, safety) so **any** connected agent — Claude, Codex, Gemini — starts informed. Agents are told to call `memory_list` first.

### Autonomy (v1.2) — the site runs itself
Beyond the interactive MCP connection, Claudpress can operate unattended:
- **`get_dashboard`** — an on-demand status snapshot (sales today/7d/30d, open orders, out-of-stock, pending comments, available updates) so you can ask *"how's the store doing?"* and get an answer without logging in.
- **Autonomous agent runtime** — add an Anthropic API key in **Claudpress → Automation**, and `run_agent` / autonomous tasks run a plain-language instruction through a real on-site agentic loop over the internal tools (e.g. *"fulfil paid orders, restock flagged items, publish due drafts"*).
- **Scheduled automations** (WP-Cron) — `task_save` creates **digest** tasks (emailed status snapshots, no key needed) or **autonomous** tasks on an hourly/daily/weekly schedule.
- **Notifications** — email, Slack/generic webhook, or Telegram (`notify_owner`).
- **Guardrails** — a policy layer caps refunds, requires per-task opt-in for destructive tools in unattended runs, and can hard-block tools. Enforced for both interactive and autonomous calls.

### Chat to your site on Telegram — no MCP client needed

Everything above assumes an MCP client on the other end. This is the path that doesn't: **message a Telegram bot and the site's own agent does the work.** The plugin's API key is the brain, your phone is the interface — no Claude Code, no terminal, no laptop.

Setup (about two minutes):

1. Talk to [@BotFather](https://t.me/BotFather) on Telegram → `/newbot` → copy the token.
2. Paste it into **Claudpress → Automation → Notifications → Telegram bot token**.
3. In the **Chat** card below it: tick *Accept messages from Telegram*, save, then hit **Connect bot to this site**.
4. Message the bot. Don't know your chat ID? Message it anyway — it replies with your ID so you can allowlist it.

Then just talk to it: *"how did the store do this week?"*, *"publish the draft about winter tyres"*, *"the footer on the checkout page is broken, fix it."* It runs the same tools, the same policy guardrails and the same budget caps as a scheduled run, and every Telegram run shows up in the run history under the `telegram` source.

**Commands** — answered by the plugin itself, so they cost nothing:

| | |
|---|---|
| `/clear` | Forget this conversation and start fresh |
| `/undo` | Put back the content changed last turn |
| `/status` | Store & site snapshot |
| `/usage` | Runs, tokens and estimated spend in the last 24h, against your caps |
| `/tasks` | Scheduled automations and when they last ran |
| `/model` | Which provider and model is answering |
| `/risky on\|off` | Temporarily unlock destructive/financial tools |
| `/whoami`, `/history`, `/help` | Chat ID and role, memory depth, the list above |

**Conversation memory.** It replays the last N turns (default 12, configurable 0–60) so follow-ups like *"now do the same for the other three"* work. `/clear` wipes a conversation from your phone; **Clear all conversations** in admin wipes every one; anything older than 30 days is pruned on the cron tick.

**Voice notes.** Send audio and it transcribes, **shows you the transcript**, then acts — so a mis-hearing is something you can see and correct, not something that quietly edits your site. Transcription is a separate provider choice (OpenAI Whisper / `gpt-4o-transcribe`, or Gemini audio) because Anthropic has no speech-to-text API; leave the key blank to reuse one you already entered for the same vendor.

**Photos and screenshots.** The model actually sees the image — real vision input in each vendor's own format — and the image is filed in the media library with its attachment ID handed to the agent, so *"use this as the product image"* works with no re-upload. Send it with a caption to say what you want done.

**Progress in place.** One message is edited as the run works (`• list_posts`, `• update_post`) and then becomes the answer, instead of forty seconds of silence. Throttled, so Telegram's edit rate limit is never what slows a run down.

**One-tap approvals.** When your guardrails block something, the reply carries **Approve & run** / **Leave it** buttons — no need to learn `/risky`. Approving re-runs the instruction once with the guardrail lowered. It's a fresh run rather than a resumption, so the model acts on current state instead of on what was true a minute ago. Tokens are single-use, bound to the chat they were offered to, and expire in an hour; the instruction is stored server-side because callback data arrives from the client and must never be something we execute.

**`/undo`.** Puts back the content changed in the last turn: edits reverted to how they were *before* the turn, created posts trashed, trashed posts restored. Recording hooks WordPress core rather than the tool layer, so a change made through `execute_php` is captured just like one through `update_post`. Its scope is content — posts, pages, products — and it says so: **not** settings, plugins, files or live snippets. It also reports anything it couldn't put back rather than claiming a clean sweep.

**Roles.** Allowlist entries can carry one:

| Written as | That chat can |
|---|---|
| `123456789` | **admin** (the default) — everything, may Approve and use `/risky` |
| `123456789:editor` | change the site, but never escalate to destructive/financial tools |
| `123456789:viewer` | **read-only** tools only — ask questions, read reports, touch nothing |

A bare ID stays admin, so an allowlist written before roles existed is unaffected. `viewer` is derived from each tool's own `readOnlyHint`, minus `read_file`, `list_directory` and `export_bundle` — *"read-only"* and *"can read your database password"* are not the same claim.

**Why this is safe enough to put on a public URL.** Only allowlisted chat IDs are answered, and an unconfigured allowlist refuses *everyone* rather than defaulting open. Every delivery must carry a generated secret, compared in constant time — rotate it from admin at any time. Destructive and financial tools stay locked for chat unless the owner arms escalation in WP, and even then it's either a self-expiring `/risky` window or a per-action Approve press. Deliveries are deduplicated by `update_id` and acked before the run starts, so Telegram's retries can't run the same instruction twice.

Telegram only delivers to a publicly reachable HTTPS URL with a valid certificate — a localhost or self-signed staging site can't receive messages.

### Let the site tell you first (v1.7)

Everything else here is pull — you ask, the site answers. This is push. Switch it on in **Automation → Let the site tell you first** and Claudpress messages you (on whichever channels you configured: email, webhook, Telegram) when:

- **a paid order lands** — with a value floor, so a busy shop doesn't ping you 400 times a day
- **stock runs low or sells out**
- **a live code snippet crashed and auto-disabled itself** — the site stayed up *because* it switched off, but whatever it was doing has stopped and only you can judge whether that matters
- **new plugin/theme/core updates appear**

Every event is individually switchable, and **nothing is announced twice**: orders are keyed per status transition, and the update alert only fires when the pending set actually changes — so an update you're deliberately holding back doesn't nag you every hour forever.

### Running unattended, reliably (v1.4)
Nobody is watching a 3am cron run, so the loop defends itself:

- **Retries with backoff** — transient API failures (429 rate limits, 408, 5xx, network errors) are retried with exponential backoff and jitter, honouring the server's `Retry-After`. Permanent failures (bad key, malformed request) fail fast instead of burning the window.
- **Budget caps** — per-run and per-day limits on spend, tokens, and run count. A run that hits a cap stops cleanly between steps and reports what it managed to finish, rather than being killed mid-tool-call. Set them in **Claudpress → Automation**; `0` means unlimited. Cost is estimated from token usage at list prices (filter `claudpress_model_prices` to adjust).
- **Run history** — every autonomous run is recorded with its source, status, steps, tool calls, retries, tokens, and estimated cost, shown on the Automation tab and queryable by your AI via `agent_runs`. So *"what has the site been doing on its own, and what is it costing me?"* has an answer.
- **Digest cadence** — schedule a status digest from the Automation tab with a cadence (hourly → monthly) and a preferred hour of day.

### Staging → production migration (v1.4)
`export_bundle` / `import_bundle` / `deploy_push` carry more than posts and pages:

- **Products**, matched by SKU. Simple products carry their prices, stock, categories and gallery. A **variable** product's own page still migrates — content, images, SEO meta, Elementor data — but its attributes and variations do not: they live in child posts and `pa_*` term IDs that mean nothing on the destination, so the import reports them as skipped and you rebuild them there with the WooCommerce tools (`wc_product`, or `execute_php` with `WC_Product_Variation`). That path goes through WooCommerce's own API on a site where the terms actually exist. See [docs/plans/variable-product-migration.md](docs/plans/variable-product-migration.md).
- **Nav menus** with items and theme-location assignments. Items are relinked to the destination's own pages by slug, falling back to a custom link when the target doesn't exist there.
- **Widgets and sidebars**, skipping sidebars the destination theme doesn't register (writing them would orphan the widgets).

Secrets are never exported — the Claudpress settings option holding API keys and tokens is excluded by design, and a test asserts the option whitelist stays clean.

### Live carrier shipping rates (v1.4)
`wc_shipping` can now set up and *verify* real carriers, not just flat rates:

- `list_available_methods` — every shipping method type installed, so the agent can see which carrier plugins (DHL, UPS, FedEx, Aramex…) are available.
- `configure_method` / `get_method` — set a method instance's own settings, which is how carrier API credentials and account numbers get entered. Credentials are redacted when read back.
- `quote` — runs WooCommerce's **real** shipping engine for a destination and cart, returning the rates every applicable method produces. Carrier methods hit their API exactly as they would at checkout, so a rate coming back proves the carrier is genuinely configured and quoting.

## Development

No build step and no dependencies — the plugin runs from source.

```bash
php tests/run.php                 # unit suite: 573 assertions, no composer, no WP install needed
bash bin/build-zip.sh             # → build/claudpress.zip (single claudpress/ root, runtime files only)
bash bin/build-zip.sh ~/Downloads # ...and drop a copy there

# Live integration test against a real site (read-only by default)
CLAUDPRESS_URL=https://your-site.com CLAUDPRESS_TOKEN=cp_xxx node tests/live-smoke.mjs
node tests/live-smoke.mjs --url https://your-site.com --token cp_xxx --write   # + a create/read/delete round-trip
```

**`tests/run.php`** covers the decision logic where the costly bugs live — policy guardrails, capability gating, budget caps, retry classification and backoff, task scheduling, memory key normalization — against a small WordPress stub in `tests/bootstrap.php`. It deliberately isn't a WordPress test harness; anything that genuinely needs WordPress or WooCommerce is covered by the live smoke test instead.

It also runs **whole-plugin integrity checks** that read the source: every registered tool must belong to a capability group or the always-on list (so a new tool can't silently escape the owner's off switch), every file in `includes/` must be loaded, the version must match across the plugin header, the constant and `readme.txt`, and the migration option whitelist must contain nothing credential-shaped.

**CI** (`.github/workflows/ci.yml`) lints every PHP file on 8.0–8.4, runs the suite on 8.0/8.3/8.4, builds the zip and asserts its layout (one top-level `claudpress/`, no dev files). The live smoke test runs on manual dispatch when `CLAUDPRESS_URL` / `CLAUDPRESS_TOKEN` secrets are configured.

## Skills & memory (the "pro" features, free)

- **Skills** are Markdown playbooks stored in WordPress that the agent loads automatically when a task matches their description. Claudpress ships with expert skills for WooCommerce operations, SEO, blog publishing, safety, and a `skill-creator`. Write your own from the agent ("create a skill that…") or edit them in the DB.
- **Memory** is per-site key/value knowledge the agent persists across sessions ("remember: primary color is #f8ca50"). Shared by every connected agent.

## Architecture

```
claudpress.php              bootstrap, constants, activation
includes/
  class-claudpress.php      orchestrator + settings
  class-auth.php            bearer token + application password auth
  class-mcp-server.php      JSON-RPC 2.0 MCP protocol (initialize/tools/prompts/resources)
  class-rest.php            /wp-json/claudpress/v1/mcp transport
  class-tool-registry.php   tool registration + dispatch
  class-memory.php          cross-session memory store
  class-skills.php          skills store + markdown parser + bundled seeding
  class-sandbox.php         persistent AI PHP with crash recovery
  class-logger.php          tool-call audit log
  class-policy.php          guardrails for unattended runs
  class-capabilities.php    per-area on/off toggles
  class-agent-runtime.php   on-site agentic loop (retries, budget caps)
  class-agent-runs.php      autonomous run history + spend accounting
  class-tasks.php           WP-Cron scheduler (cadence + hour of day)
  class-digest.php          status snapshot
  class-notifier.php        email / webhook / Telegram (outbound)
  class-telegram.php        Telegram chat front-end (inbound): webhook, commands,
                            conversation history, roles, voice, photos, approvals
  class-events.php          push alerts (orders, stock, crashed snippet, updates)
  class-undo.php            one-step undo of a turn's content changes
  class-migration.php       portable bundles (products, menus, widgets)
  class-admin.php           connect / automation / guide / activity UI
  tools/                    core, filesystem, content, site, woocommerce, seo, memory, skills,
                            snippets, elementor, ops, automation, payments, deploy
skills/                     bundled expert skill playbooks (markdown)
tests/                      dependency-free unit suite + live MCP smoke test
bin/build-zip.sh            reproducible plugin packaging
```

The MCP transport answers a single `application/json` JSON-RPC response per request (the simplest valid Streamable-HTTP mode), which every MCP client accepts. No Node bridge required for HTTP-capable clients.

## License

GPL-2.0-or-later. Built with Claude Code.
