=== Claudpress ===
Contributors: calflint
Tags: mcp, ai, claude, woocommerce, seo, automation
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-contained MCP server that lets an AI agent (Claude Code, Claude Desktop, Cursor, Codex) fully run your WordPress and WooCommerce site.

== Description ==

Claudpress turns your WordPress site into an MCP (Model Context Protocol) server. Connect any MCP-compatible AI client and it can manage content, run your WooCommerce store, handle SEO, edit files, and execute PHP — with cross-session memory and a skills engine. No external dependencies, no Node bridge for HTTP clients, every feature unlocked.

WARNING: This gives connected AI agents unrestricted control of the site. Use development or staging sites only, with backups. Deactivating the plugin turns AI abilities off.

Features:

* MCP Streamable-HTTP endpoint at /wp-json/claudpress/v1/mcp
* Bearer token or Application Password authentication
* execute_php / execute_sql universal power tools
* Content: posts, pages, any CPT, media, taxonomies, menus
* WooCommerce: products, orders, coupons, reports, settings
* SEO: meta (Yoast/Rank Math/AIOSEO/SEOPress compatible), analysis, schema, redirects, sitemap
* Filesystem tools confined to a configurable base, plus a crash-recovering sandbox
* Cross-session memory shared across agents
* Skills engine with bundled expert playbooks (WooCommerce, SEO, blogging)
* Master safety switch + full activity log
* Bring your own key: Anthropic, OpenAI or Google for the agent brain and image generation
* Telegram chat: message a bot and the on-site agent runs your site — no MCP client needed
* Voice notes, photos, one-tap approvals, /undo and per-chat roles for that chat
* Event alerts: paid orders, low stock, a crashed snippet, available updates

== External services ==

This plugin can send data to third-party APIs. Nothing is sent until you enter a
key for that service, and no service is contacted at all if you leave its key blank.
Claudpress itself has no servers and phones nothing home.

**Your MCP client (Claude Code, Claude Desktop, Cursor, Codex, or any other).**
Whatever client you connect makes the requests; whatever it reads from your site
goes to that client's model provider under that provider's terms. This is the
whole point of the plugin, and it is the largest single disclosure here: an agent
you connect can read your posts, orders, customers and files.

**Autonomous runs (optional — Claudpress > Automation).** When you add an agent
API key and schedule an autonomous task, Claudpress sends the task instruction,
your site name and URL, the stored site memory, the tool list, and each tool's
results to the provider you selected, in order to decide the next step:

* Anthropic — https://api.anthropic.com — terms: https://www.anthropic.com/legal/commercial-terms — privacy: https://www.anthropic.com/legal/privacy
* OpenAI — https://api.openai.com — terms: https://openai.com/policies/terms-of-use — privacy: https://openai.com/policies/privacy-policy
* Google (Gemini) — https://generativelanguage.googleapis.com — terms: https://ai.google.dev/gemini-api/terms — privacy: https://policies.google.com/privacy

An endpoint override sends the same data to whichever gateway you point it at instead.

**Image generation (optional).** When you add an image API key, the prompt text
you or the agent writes is sent to OpenAI or Google (same endpoints and terms as
above) and the returned image is stored in your media library.

**Image search (optional, no key).** image_search queries Openverse
(https://api.openverse.org) for openly licensed stock images. Only your search
term is sent. Terms: https://wordpress.org/openverse/terms

**Notifications (optional).** If you configure them, digests and alerts are sent
to your own email, your own webhook URL, and/or the Telegram Bot API
(https://api.telegram.org — https://telegram.org/privacy) using your own token.

**Telegram chat (optional, off by default).** If you switch it on and connect the
bot, Telegram delivers the messages you send it to this site, and the replies —
including whatever the agent reports about your site or store — go back through
the Telegram Bot API (https://api.telegram.org — https://telegram.org/privacy).
Your messages are also stored in this site's own database as conversation history
until you clear them, and each one is sent to your chosen AI provider as the
instruction for that run. Only chat IDs you allowlist are answered.

If you enable photos, an image you send is downloaded from Telegram, stored in
this site's media library, and sent to your chosen AI provider as part of the
message so the model can see it.

**Voice transcription (optional, off by default).** If you enable voice notes, the
audio you send the bot is downloaded from Telegram and sent to the transcription
provider you select — OpenAI (https://api.openai.com) or Google
(https://generativelanguage.googleapis.com), same terms and privacy links as
above — using your own key. Only the audio is sent; the transcript comes back and
is shown to you before anything acts on it.

**Deploy (optional).** export_bundle/deploy_push send your content bundle only to
the destination Claudpress site whose URL and token you configure.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin, then Activate.
2. Open the Claudpress admin page.
3. Enable AI abilities (development/staging only).
4. Copy the "Connect Claude Code" command and run it in your terminal.

Autonomous runs are optional and off until you add a provider API key under
Claudpress > Automation. Pick your provider there — the model guide next to the
field lists the exact model IDs to type for each one.

== Changelog ==

= 1.7.0 — Voice, photos, approvals, undo and roles =
* Voice notes: send the bot a voice message and it transcribes it, shows you the transcript, then acts on it. Transcription is its own provider choice (OpenAI Whisper / gpt-4o-transcribe, or Gemini audio) because Anthropic has no speech-to-text API; a blank key reuses any key you already entered for the same vendor.
* Photos and screenshots: send an image (with a caption if you like) and the model actually sees it — proper vision input in each vendor's own format. The image is also filed in the media library and its attachment ID handed to the agent, so "use this as the product image" works without a re-upload.
* One-tap approvals: when your guardrails block something, the reply carries Approve & run / Leave it buttons instead of making you learn /risky. Approving re-runs the instruction once with the guardrail lowered — a fresh run, so the model acts on current state rather than on what was true a minute ago. Tokens are single-use, chat-bound, expire in an hour, and the instruction is stored server-side because callback data comes back from the client.
* Progress in place: one message is edited as the run works ("• list_posts", "• update_post") and then becomes the answer, instead of 40 seconds of silence. Throttled so Telegram's edit rate limit is never the thing slowing a run down.
* /undo: puts back the content changed in the last turn — posts, pages and products, whether the agent changed them through update_post or through execute_php, because recording hooks WordPress core rather than the tool layer. Edits are reverted to how they were *before* the turn, created posts are trashed, trashed posts are restored. It says plainly what it could not undo, and that its scope is content only — not settings, plugins, files or live snippets.
* Chat roles: allowlist entries can carry a role — 123456789:viewer or :editor. A viewer gets read-only tools only (and not read_file, list_directory or export_bundle: "read-only" and "can read your database password" are not the same claim). An editor can change the site but can never escalate to destructive or financial tools. A bare chat ID stays admin, so an existing allowlist is unaffected.
* Event notifications — the site tells you first: a paid order (with a value floor), low stock or a sell-out, a live code snippet that crashed and auto-disabled itself, and new available updates. Every event is individually switchable, and nothing is announced twice — orders are keyed per status change, and the update alert only fires when the pending set actually changes, so holding an update back deliberately does not nag you forever.
* Tests: 573 assertions, up from 468.

= 1.6.0 — Chat to your site on Telegram =
* Telegram chat front-end: message a Telegram bot and the on-site agent does the work — no MCP client, no terminal, no laptop. The plugin's own API key is the brain, your phone is the interface. Connect it in Claudpress > Automation > Chat with one button.
* Multi-turn conversation memory, so follow-ups like "now do the same for the other three" work. Configurable depth (0–60 turns), and /clear wipes a conversation from Telegram. "Clear all conversations" in WP admin wipes every one; transcripts older than 30 days are pruned automatically.
* Built-in commands answered by the plugin without spending a cent on the API: /status (store snapshot), /usage (runs, tokens and estimated spend against your caps), /tasks (scheduled automations), /model, /history, /whoami, /help. They register into Telegram's own command menu on connect.
* Access control: only allowlisted chat IDs are answered (an unconfigured allowlist refuses everyone, including you), every delivery is verified against a generated secret compared in constant time, and the secret can be rotated from admin. An unrecognised chat is told nothing but its own chat ID, which is what the owner needs to allowlist themselves.
* Destructive and financial tools stay locked for chat by default. The owner arms them in admin, the chat opts in with /risky on, and the opt-in lapses by itself after 30 minutes.
* A live chat gets its own operating prompt — it may ask one clarifying question, and it writes for a phone screen — rather than the "never ask, you are unattended" rules a 3am cron run needs. Everything else (protocol, policy, budget caps, run history) is identical, and Telegram runs appear in the run log under the "telegram" source.
* Replies longer than Telegram's 4,096-character limit are split on paragraph boundaries instead of being truncated, and each reply carries a footer with tools used and estimated cost.
* Delivery is deduplicated by update_id and acked before the agent runs (via fastcgi_finish_request where available), so Telegram's retries cannot run the same instruction twice; a per-chat lock stops two runs overlapping.
* Tests: 468 assertions, up from 370.

= 1.5.0 — Bring your own model, and guardrails =
* Multi-provider agent brain: choose Anthropic, OpenAI or Google in Claudpress > Automation and paste that vendor's key. Each provider has a real adapter for its request format, tool-calling schema and response shape — this is not just a URL swap. OpenAI-compatible gateways (Azure, OpenRouter, Groq, Ollama) work via the endpoint override.
* Multi-provider image generation: OpenAI (GPT Image / DALL·E) or Google Gemini image, a.k.a. "Nano Banana".
* Model guide: an expandable cheat-sheet next to the Model field lists the exact model IDs to type for every provider, what each one is for, and links to the vendor's own model list and key page — so a wrong model name is no longer a guessing game.
* Blank model/endpoint now means "use the provider's recommended default", and switching provider can no longer strand the previous vendor's URL in the endpoint field.
* Operating protocol: connected agents — interactive and autonomous alike — now receive explicit working rules covering read-before-write, minimal changes, preferring specific tools over execute_php/execute_sql, verifying changes by reading them back, treating live snippets as the highest-risk action, and reporting honestly. Extend it with the `claudpress_operating_protocol` filter.
* Security: the unauthenticated /status health check no longer advertises whether AI abilities are on or how many tools are exposed; that detail now requires authentication.
* The Connect page no longer links to the MCP endpoint (a browser GET could only ever return 401). It shows the URL to copy, and links the health check instead.
* Internationalization: text domain loaded and every admin string across all four screens wrapped for translation, with translator comments and positional placeholders where values are interpolated.
* Budget caps now state plainly that costs are estimates from built-in list prices — they do not know your discounts, cached-token rates, or a gateway's pricing. Your provider's invoice is the authoritative number.
* Tests: 370 assertions, up from 206. Adds full provider adapter coverage (parsing, tool-call round-trips, malformed responses, Google schema sanitising), protocol guardrail checks, and a new admin render suite that renders all four screens and asserts balanced markup, that every form carries a nonce, that API keys and tokens only ever appear inside password inputs, and that no untranslated strings creep back in. Filters now execute for real in the test harness instead of being no-op stubs.

= 1.4.0 — Reliability, budgets & migration depth =
* Autonomy reliability: the agent loop now retries transient API failures (429/408/5xx and network errors) with exponential backoff and jitter, honouring the server's Retry-After. Permanent failures (bad key, bad request) still fail fast.
* Budget caps: per-run and per-day spend/token/run limits stop a runaway autonomous loop from spending real money. A capped run halts cleanly and reports what it finished. Configurable under Claudpress > Automation; 0 means unlimited.
* Agent run history: every autonomous run is recorded (source, status, steps, tool calls, retries, tokens, estimated USD) and shown on the Automation tab, plus a new read-only `agent_runs` tool so your AI can answer "what has the site been doing on its own, and what is it costing?".
* Digest cadence UI: schedule a status digest straight from the Automation tab with a cadence and a time of day. Tasks gained a `run_hour` and a `monthly` schedule.
* Migration: bundles now carry nav menus with their items and theme locations (relinked to the destination's pages by slug), and widgets/sidebars. Variable products migrate as content only — their attributes and variations are reported as skipped, to be rebuilt on the destination with the WooCommerce tools.
* Shipping: `wc_shipping` gained `list_available_methods`, `get_method`, `configure_method` (enter a carrier's API credentials on an existing instance) and `quote` — which runs WooCommerce's real shipping engine for a destination and returns the live rates, including rates fetched from carrier APIs.
* Tests & CI: a dependency-free PHP test suite (`php tests/run.php`, 200+ assertions covering policy, capabilities, budget caps, retry/backoff, scheduling and whole-plugin integrity), a live MCP smoke test (`node tests/live-smoke.mjs`), and GitHub Actions running lint on PHP 8.0–8.4 plus the suite on every push.

= 1.3.3 — Autonomy hotfix =
* Fixed autonomous agent runs failing when the model called a tool with no arguments (tool_use.input serialized as [] instead of {}, rejected by the Anthropic API). Found via a full live feature test.

= 1.3.2 — Newbie guidance =
* New "Guide" admin page: an auto-generated, plain-English master guide — what the plugin is, 3-step start, the two safety controls, a recommended newbie on/off config, every capability with its tools and descriptions, autonomy, deploy, skills/memory, starter prompts, and an FAQ.
* Banner on the plugin page linking to the guide.
* Hover info (ℹ️) on every capability toggle explaining what it does when ON, which tools it covers, and the risk if it's sensitive.

= 1.3.1 — Capability toggles =
* New Capabilities panel on the dashboard: switch any area (Payments/money, Store & orders, Shipping, Tax, Code execution, Filesystem, Users, Site admin, Updates, Comms, Autonomy, Deploy, Content, SEO, Design, Images, Memory, Skills) on or off.
* Disabled capabilities are hidden from the agent's tool list, refused if called, and named as off-limits in the session instructions — enforced for both interactive and autonomous runs. Read-only status tools stay available.

= 1.3.0 — Commerce & Deploy =
* Checkout setup tools: wc_payments (enable/configure gateways — Stripe, PayPal, COD, BACS, cheque; test mode), wc_shipping (zones, flat/free/local-pickup methods, shipping classes), wc_tax (enable + rates).
* Staging→production deploy: export_bundle / import_bundle (portable, idempotent JSON of pages, posts, products, snippets, skills, memory, options incl. Elementor & SEO meta; secrets never exported), and deploy_push to send straight to a configured production site.
* New authenticated /wp-json/claudpress/v1/import endpoint so a staging site can push a bundle to production in one call.
* New bundled skill: store-launch-and-deploy. Deploy-target settings on the Connect page.

= 1.2.0 — Autonomy =
* Autonomous agent runtime: runs a plain-language instruction through an on-site AI agentic loop (Anthropic Messages API) over the internal tools, with policy guardrails. Tools: run_agent, task_save (autonomous mode).
* Scheduled automations via WP-Cron: digest tasks (email status snapshots, no API key) and autonomous tasks. Tools: task_save/list/run/delete.
* get_dashboard: on-demand status snapshot (sales today/7d/30d, open orders, out-of-stock, updates, pending comments) — answer "what's going on" without logging in.
* Multi-channel notifications: email, webhook/Slack, Telegram. Tool: notify_owner.
* Policy/guardrails: financial caps (refunds), destructive-tool opt-in for unattended runs, always-block list; enforced for both interactive and autonomous calls.
* New admin "Automation" tab: engine status, API key + notification + policy settings, live dashboard, task management.

= 1.1.1 =
* New bundled "web-design-master" skill: a full modern-responsive web-design reference (design tokens, mobile-first layout, component patterns, WordPress theme-safe CSS rules, anti-slop quality audit) synthesized from leading design skills + WPWriter-style patterns.
* Memory keys now keep dots/colons (e.g. brand.accent) instead of stripping them.

= 1.1.0 =
* Code Snippets engine (PHP/CSS/JS/HTML) with locations, conditions, custom page templates, and crash auto-deactivation.
* Elementor tools: read/write page data, create pages, global kit, prepare_for_ai (Container on, Atomic off).
* Ops tools: manage_updates (core/plugins/themes), send_email, image_search (Openverse, free), image_generate (API).
* Builder toggle setting, optional image API key, and seeded default memory so any agent starts informed.
* Auto-upgrade routine: updating the plugin creates new tables without reactivation.

= 1.0.0 =
* Initial release.
