---
name: site-safety
description: Baseline safety and verification rules for making changes on this WordPress site. Loads automatically for any create/update/delete/install/PHP/SQL operation to keep changes reversible and verified.
enable_agentic: true
enable_prompt: false
---

# Site safety

You have full control of a real WordPress site. Move fast, but never leave it broken.

## Before changing anything
- Understand current state first: read the record (`get_post`, `wc_order action=get`, `manage_option action=get`) before you modify it.
- For anything destructive or irreversible — deleting content, refunds, bulk price/stock changes, deleting/replacing files, running `DELETE`/`UPDATE` SQL without a tight `WHERE`, changing base country/currency, deactivating a plugin the site depends on — state exactly what you will do and get explicit confirmation.

## Prefer reversible paths
- Trash, don't force-delete (`delete_post` without `force`) unless asked.
- When editing files or theme code, prefer a child theme or a `write_sandbox_file` snippet over editing core/parent files. Sandbox files that fatal are auto-quarantined, so the site stays up.
- For risky `execute_php`, first run a read-only probe (return the data you'd change) before the write.

## GOTCHA: full-file rewrites of live .php files
`write_file`/`edit_file` content is syntax-checked before it touches disk (missing/mangled `<?php` tag, unbalanced braces, stray tokens — all rejected, nothing written). That catches broken syntax, but it can't catch broken *content that still parses* — and a live plugin/theme .php file is `require`d on every request with no crash recovery, unlike sandbox files.
- **Prefer `edit_file` with a small, targeted `old_string`/`new_string` over `write_file` with the whole file retyped.** A full rewrite of a large file is exactly how this class of incident happens: a wrong character transcribed once near the top (e.g. `<` coming out as `&lt;` while composing the write) corrupts the entire file, and the more content you retype, the more surface area for that slip.
- If you must rewrite a whole live .php file, read it back afterward and confirm it matches what you intended before considering the change done — don't infer success from the tool call alone.
- This applies double to anything in `includes/` — those files run unconditionally on every single request site-wide, so a bad one takes the whole site down, not just one feature.

## Verify after
- After a change, read one affected record back and confirm the new value. Report what actually changed, with IDs and links.
- If a tool returns `success: false`, surface the exact error and adjust — don't retry the same call blindly.

## Scope
- Keep the filesystem within the configured base; don't try to escape it.
- Don't touch other admins' users, unrelated plugins, or production credentials unless that's the task.

## Remember
- Save durable decisions and conventions with `memory_save` so future sessions don't repeat mistakes or re-ask.
