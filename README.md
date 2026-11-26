<div align="center">

# Keelhob

**Build your WordPress site or store from one prompt. Free, forever.**

[![CI](https://github.com/calflint/keelhob/actions/workflows/ci.yml/badge.svg)](https://github.com/calflint/keelhob/actions/workflows/ci.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE)
[![Donate on Ko-fi](https://img.shields.io/badge/Ko--fi-Support%20this%20project-c2185b.svg)](https://ko-fi.com/keelhob)
[![Roadmap](https://img.shields.io/badge/Roadmap-what's%20next-333.svg)](ROADMAP.md)
[![Documentation](https://img.shields.io/badge/Docs-read%20the%20manual-c87941.svg)](https://keelhob.com/docs)

</div>

https://github.com/user-attachments/assets/e4c0be23-7aea-4dac-adaf-e1725a499c6e

**One sitting, 69 minutes, cut to three and a half.** Eight chapters — install, connect Claude, one
prompt, store setup, products, pages, redesign, result. Nothing is staged and nothing is re-shot:
it is one recording, and the parts where the screen sat still were dropped. Where it is sped up,
the speed is on screen the whole time, so you can see what was skipped. Keys, tokens and local
paths are blurred.

WordPress shipped a famous five-minute install, back when standing up a database-driven site took
days. It kept that promise. Then the hard part moved: installing is trivial now, and building is
twenty plugins and a week you did not plan to spend. Keelhob puts the five minutes back where the
work actually is, with a database, a store, and code you actually own.

Install one plugin, connect Claude Code (or Claude Desktop, Cursor, Codex, any MCP client), and say
what you want. It asks a few questions, then builds: pages, products, checkout, SEO, snippets,
media. Don't want to run a client at all? Add your own API key and the site runs itself — on a
schedule, or from a Telegram chat with voice notes, screenshots and approve-by-button.

**New here? Read [the manual](https://keelhob.com/docs).** It is the whole thing in one page —
install, connect, every tool, every capability group, troubleshooting — written for someone who has
never touched MCP, and you do not need GitHub to read it. The same document ships inside the zip as
[DOCUMENTATION.md](DOCUMENTATION.md), and an agent can fetch it as plain markdown in one request at
[keelhob.com/llms-full.txt](https://keelhob.com/llms-full.txt).

Already know what this is, and want the tool-by-tool detail? That is
**[docs/features.md](docs/features.md)** — the developer view.

## What it cannot do yet

- **No onboarding wizard.** Activation drops you on an admin page; connecting an agent is a
  copy-paste step you do yourself.
- **No independent security audit.** ~2,600 unit assertions and a live rig against real WordPress,
  and nobody outside this project has read the code. Commissioning that is the top line in
  [FUNDING.md](FUNDING.md).
- **Permissions are coarse.** Capability groups, on or off — no per-tool approval and no queue
  that asks before a destructive action runs.
- **No rollback.** `/undo` reverses one turn and only content: posts, pages, products. Not
  settings, plugins, files or live snippets. Nothing snapshots the site, so backups are yours.
- **Block themes and full site editing are not supported.** Pages are built with Gutenberg,
  Elementor, or custom-coded page templates.
- **No data importer.** Nothing reads a Shopify, Wix or Squarespace export and loads it in. The
  site-clone skills rebuild a site's *design and structure* by looking at it, which is a different
  job: orders, customers and stock do not come across. Going the other way, a variable product only
  half-migrates between Keelhob sites: the page moves, its attributes and variations do not, and
  the import says so.
- **One agent at a time**, no background job queue: a long interactive build lives inside the
  session that started it. **Not translated**, either — strings are wrapped, no translation ships.
- **Telegram needs a public HTTPS URL** with a valid certificate — a localhost or self-signed
  staging site cannot receive messages.
- **Not on WordPress.org**, and it will not be: the directory does not allow a plugin that executes
  arbitrary PHP. Install the zip; it updates itself from GitHub releases.

[ROADMAP.md](ROADMAP.md) says which of these are being fixed, and in what order.

## Screenshots

| | |
|---|---|
| ![The Connect page, showing the generated MCP command](docs/img/01-connect.png)<br>**Connect** — the ready-to-paste command, with this site's URL already in it. The token is stored hashed and shown once. | ![The Snippets Studio listing installed snippets](docs/img/02-studio.png)<br>**Snippets Studio** — every piece of code the agent installed, why it exists, and its off switch. The top row switched itself off after taking the site down. |
| ![The capability toggles panel](docs/img/03-capabilities.png)<br>**Capabilities** — twenty-four groups, ten of them off out of the box, each saying what it unlocks. | ![The front end of a store the agent built](docs/img/04-built-site.png)<br>**The output** — the front end of a real store Keelhob built: theme, pages, products, and the live preview that puts your words on the mug. Nothing here was hand-coded. |

## Why it is free

Keelhob was built as a free core with a paid add-on beside it. The paid version was designed,
built and finished — payments, orders, deploy, the Snippets Studio, six expert skills — and then
given away, because a plugin whose whole point is handing people back a promise should not take
part of that promise away again at a checkout. It runs on donations instead. Every feature is in
this repository: no paid tier, no locked capability, no trial, no upsell in the admin, and no
telemetry beyond an update check you can switch off ([exactly what it sends](PRIVACY.md)).

**[Support Keelhob on Ko-fi](https://ko-fi.com/keelhob)** — or star the repo, report a bug,
or write a skill. Skills are plain markdown; no PHP needed.

## Install

1. Download `keelhob.zip` from the [latest release](https://github.com/calflint/keelhob/releases).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → the zip → **Install Now** → **Activate**.
3. Open **Keelhob** in the admin menu.

Setting this up with a coding agent? Point it at [llms-install.md](llms-install.md).

Requires WordPress 6.3+ and PHP 8.0+. WooCommerce and any SEO plugin (Yoast, Rank Math, AIOSEO,
SEOPress) are auto-detected if present; none are required. **Keelhob → Connect** then prints a
ready-to-paste command with your real URL and token in it:

```bash
claude mcp add keelhob-yoursite --transport http https://yoursite.com/wp-json/keelhob/v1/mcp --header "Authorization: Bearer kh_xxxxxxxx"
```

Run it, start a new session, and the tools appear. Try *"list the plugins on this site"*. The
same page outputs a JSON `mcpServers` block for Claude Desktop, Cursor and Codex; authenticate
with the bearer token or a WordPress Application Password.

### If a snippet breaks your site

Put this in `wp-config.php`, above the *"That's all, stop editing"* line:

```php
define('KEELHOB_SNIPPETS_OFF', true);
```

Every snippet stops running immediately, whatever the admin page says. Remove the line to switch
them back on; nothing is deleted. Edit the file however you normally would — FTP, your host's file
manager, a shell. None of that needs a working site or a database client, which is the point: the
case this exists for is a site you cannot load.

**Why a constant and not a button.** A snippet can take a site offline without ever erroring, and
the commonest way is returning a `503` from the front end — for a coming-soon page, say. Managed
and containerised hosts poll the home page to decide whether the site is alive, read that `503` as
*this server is dead*, and stop routing to it. `wp-admin` goes with it, and so does the MCP
endpoint an agent would need to undo its own change. Every switch inside WordPress is unreachable
at that point, so the switch has to live outside it.

The plugin already auto-deactivates a snippet that causes a **fatal error**. This covers the other
case: one that runs exactly as written and breaks the site anyway.

## How risky this is, honestly

A connected agent can write PHP, read your database and change your files. That is the product,
not a defect. So the answer is not "staging only" — it is graded, and this is the grading.

**Ten capability groups ship switched off**, and are forced off once on any site still carrying
settings from the old free/paid split, so an automatic update cannot quietly hand them over:

| Group | Off because it can |
|---|---|
| `abilities` | let the AI write new PHP tools for this site, for you to approve |
| `sitecare_fix` | compress images in place, delete database junk, rewrite links, install plugins |
| `store_orders` | read and refund real orders, and read sales reports |
| `payments` | enable and configure payment gateways and their keys |
| `shipping` | change shipping zones, methods and rates |
| `tax` | change tax settings and rates |
| `users` | create, edit and delete user accounts and roles |
| `updates` | update WordPress core, plugins and themes |
| `comms` | send email to real addresses, including your customers |
| `deploy` | export a bundle and push it to your production site |

**What stays on** is what a build needs, and none of it is harmless: `content` writes posts, pages
and media · `store` creates products, coupons and store settings · `seo` and `seo_optimize` write
meta, schema, sitemap and redirects · `design` builds Elementor pages · `snippets` installs code
that runs on every page load · `code_exec` runs arbitrary PHP and SQL · `files` reads and writes
under a configurable base · `site_admin` changes options and activates plugins and themes ·
`images` searches and generates media · `automation` runs unattended agent loops · `memory` and
`skills` write what the agent remembers and follows. Above all of them sits one master switch:
with **AI abilities** off the endpoint authenticates but exposes only read-only introspection.
Every call is written to the Activity log.

**Before pointing this at anything you care about:** a backup you have actually restored once, not one
you assume works; a staging copy for the first build, so you can watch what it does; only the capability
groups the job in front of you needs; and the endpoint token treated like a root password, because it
is one — stored hashed and shown once, so regenerate it the moment it leaks.

**Zero external dependencies at runtime.** No npm, no vendored libraries, no bundled SDK, and nothing
from Composer — the plugin runs from its own source, so there is no supply chain underneath it to
compromise. For software with this much reach that is worth more than any feature. There *is* a
`composer.json`, and it exists only for the test and analysis tooling: its `require` block is empty
on purpose and `vendor/` is not in the shipped zip. [SECURITY.md](SECURITY.md) covers
what is in scope and how to report something.

## About `eval()`

Grep this repository and you will find `eval()` three times: twice in `includes/class-snippets.php`
(in `run_php()` and `run_php_template()`) and once in `includes/tools/class-tools-core.php` (in
`execute_php()`). That is the product working as
designed, not an oversight — `execute_php` is the escape hatch that lets an agent do anything
WordPress can do without waiting for a dedicated tool, and the snippet engine is how AI-written
code becomes site code that runs for real visitors. What is built around it: a PHP snippet is
parse-checked with `token_get_all(TOKEN_PARSE)` before it can ever be active, and code that fails
the check is still saved — you do not lose the work — but is forced inactive with the parse error
attached. A snippet that fatals at runtime is switched off by a shutdown handler that knows which
snippet was executing, with the error written to its row, to the snippet log and to the PHP error
log, and an event fired so the site can tell you. The snippet tools sit in the `snippets`
capability group and `execute_php`/`execute_sql` in `code_exec`: switch either off and those tools
disappear from the agent's tool list and are refused if called anyway. Snippets already saved keep
running regardless, because by then they are site code — the separate **Run code snippets** setting
is the kill switch that stops every one of them at once.

## Skills and memory

**Skills** are markdown playbooks stored in WordPress that the agent loads when a task matches their
description; twenty-seven ship with the plugin, and you can write your own from the agent — *"create a
skill that…"* — with no PHP. **Memory** is per-site key/value knowledge every connected agent shares
(*"remember: primary color is #f8ca50"*).

## Development

The plugin runs from source; there is nothing to build.

```bash
php tests/run.php                 # unit suite, ~2,600 assertions, no composer, no WP install
bash bin/build-zip.sh             # → build/keelhob.zip

composer install                  # dev tooling only — the plugin itself needs none of it
composer analyse                  # PHPStan, level 1, analysed against the PHP 8.0 floor
composer lint                     # php -l across every file, Windows included
```

CI lints every PHP file on 8.0–8.4, runs the suite on 8.0/8.3/8.4, then builds the zip and asserts
its layout. Module map, test strategy and the MCP transport: **[docs/architecture.md](docs/architecture.md)**.

## Who builds this

Built and maintained by [calflint](https://github.com/calflint), in evenings around a full-time
job. Bug reports, skills, translations and pull requests are wanted and credited by name —
[CONTRIBUTING.md](CONTRIBUTING.md) has the setup and what "done" means here.

## More

[Manual](https://keelhob.com/docs) · [Roadmap](ROADMAP.md) · [Funding](FUNDING.md) ·
[Contributing](CONTRIBUTING.md) · [Security](SECURITY.md) · [Privacy](PRIVACY.md) ·
[Code of conduct](CODE_OF_CONDUCT.md)

**Renamed in 2.0.0.** This project was published under a different name until then, one that read
as an AI vendor's trademark. Nothing it does changed. Why, and how to upgrade an existing site:
**[docs/why-the-name-changed.md](docs/why-the-name-changed.md)**.

GPL-2.0-or-later. Inspired by [Novamira](https://novamira.ai), rebuilt from scratch with no
external dependencies and no WordPress 6.9 requirement. Built with Claude Code.
