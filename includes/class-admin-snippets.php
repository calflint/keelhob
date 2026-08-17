<?php
/**
 * The Snippets admin page — "what has the AI actually installed on my site?"
 *
 * Claudpress lets an agent write real code onto a live site. Until this page
 * existed that code ran with no human-facing surface at all: the owner could
 * not see it, understand why it was there, or switch it off without asking the
 * agent. This is the trust surface for the whole snippet engine.
 *
 * Deliberately asset-free. The plugin ships no CSS or JS files, and nothing
 * here needs them: expansion is a native <details>, filtering is a plain GET
 * form, and editing is a <textarea>. Markup follows the hand-rolled
 * `<table class="widefat striped">` convention used by the other pages.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Admin_Snippets
{
    /**
     * Row source, overridable so the render tests can drive the template with
     * fixture rows. Null = read the database through Claudpress_Snippets.
     *
     * @var callable(array):array|null
     */
    public static $source = null;

    /** @param array<string, string> $filters */
    private static function rows(array $filters): array
    {
        if (is_callable(self::$source)) {
            return (array) call_user_func(self::$source, $filters);
        }
        return Claudpress_Snippets::query($filters);
    }

    public function render(): void
    {
        $filters = [
            'search'       => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'tag'          => isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '',
            'snippet_type' => isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '',
            'active'       => isset($_GET['active']) && $_GET['active'] !== '' ? (string) (int) $_GET['active'] : '',
        ];
        $rows  = self::rows($filters);
        $label = Claudpress::site_label();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Snippets', 'claudpress'); ?></h1>
            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'claudpress'); ?></p></div>
            <?php endif; ?>
            <p style="max-width:820px;color:#50575e;">
                <?php esc_html_e('Every piece of code Claudpress has installed on this site, and what each one is for. Switch one off and it stops running immediately — the rest of the site is untouched.', 'claudpress'); ?>
                <?php if (!Claudpress::instance()->get('run_snippets', true)) : ?>
                    <br><strong style="color:#b32d2e;"><?php esc_html_e('All snippets are currently switched off by the master kill-switch on the Connect page.', 'claudpress'); ?></strong>
                <?php endif; ?>
            </p>
            <p style="color:#50575e;">
                <?php
                printf(
                    /* translators: %s: this site's agent-facing label. */
                    esc_html__('This site answers to the name %s. Use that name when you ask your agent to copy a snippet between sites.', 'claudpress'),
                    '<strong>' . esc_html($label) . '</strong>'
                );
                ?>
            </p>

            <?php self::render_filters($filters); ?>

            <table class="widefat striped" style="margin-top:14px;">
                <thead>
                    <tr>
                        <th style="width:20%;"><?php esc_html_e('Name', 'claudpress'); ?></th>
                        <th><?php esc_html_e('Description', 'claudpress'); ?></th>
                        <th style="width:130px;"><?php esc_html_e('Tags', 'claudpress'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Type', 'claudpress'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Status', 'claudpress'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('ID', 'claudpress'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr>
                        <td colspan="6" style="padding:22px;color:#666;">
                            <?php if (implode('', $filters) !== '') : ?>
                                <?php esc_html_e('No snippets match this filter.', 'claudpress'); ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=claudpress-snippets')); ?>"><?php esc_html_e('Show all snippets', 'claudpress'); ?></a>
                            <?php else : ?>
                                <?php esc_html_e('No snippets yet. When your agent writes code onto this site, it appears here with an explanation of what it does.', 'claudpress'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <?php self::render_row($row, $filters); ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** @param array<string, string> $filters */
    private static function render_filters(array $filters): void
    {
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:16px;">
            <input type="hidden" name="page" value="claudpress-snippets">
            <?php wp_nonce_field('claudpress_save'); ?>
            <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Search name, description or purpose', 'claudpress'); ?>" style="width:280px;">
            <input type="text" name="tag" value="<?php echo esc_attr($filters['tag']); ?>" placeholder="<?php esc_attr_e('Tag', 'claudpress'); ?>" style="width:120px;">
            <select name="type">
                <option value=""><?php esc_html_e('Any type', 'claudpress'); ?></option>
                <?php foreach (['php' => 'PHP', 'css' => 'CSS', 'js' => 'JavaScript', 'html' => 'HTML'] as $val => $text) : ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected($filters['snippet_type'], $val); ?>><?php echo esc_html($text); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="active">
                <option value=""><?php esc_html_e('Any status', 'claudpress'); ?></option>
                <option value="1" <?php selected($filters['active'], '1'); ?>><?php esc_html_e('Active only', 'claudpress'); ?></option>
                <option value="0" <?php selected($filters['active'], '0'); ?>><?php esc_html_e('Inactive only', 'claudpress'); ?></option>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'claudpress'); ?></button>
            <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=claudpress-snippets')); ?>"><?php esc_html_e('Reset', 'claudpress'); ?></a>
        </form>
        <?php
    }

    /**
     * One snippet: the summary row plus its collapsed detail row.
     *
     * Emitted from a single helper so that every embedded form is guaranteed to
     * carry its nonce — the render test counts forms against nonce fields and a
     * per-row form is easy to add and easy to forget.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $filters
     */
    private static function render_row(array $row, array $filters): void
    {
        $id      = (int) ($row['id'] ?? 0);
        $active  = (int) ($row['active'] ?? 0) === 1;
        $error   = trim((string) ($row['last_error'] ?? ''));
        $tags    = Claudpress_Snippets::tags_array((string) ($row['tags'] ?? ''));
        $type    = (string) ($row['snippet_type'] ?? 'php');
        $uid     = (string) ($row['uid'] ?? '');
        ?>
        <tr>
            <td>
                <strong><?php echo esc_html((string) ($row['name'] ?? '')); ?></strong>
                <?php if ($error !== '') : ?><span title="<?php echo esc_attr($error); ?>">&#9888;</span><?php endif; ?>
            </td>
            <td><?php echo esc_html(wp_trim_words((string) ($row['description'] ?? ''), 18)); ?></td>
            <td>
                <?php foreach ($tags as $tag) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=claudpress-snippets&tag=' . rawurlencode($tag))); ?>" style="display:inline-block;margin:1px 2px;padding:1px 7px;border-radius:9px;background:#eef3f7;color:#217296;text-decoration:none;font-size:11px;"><?php echo esc_html($tag); ?></a>
                <?php endforeach; ?>
            </td>
            <td>
                <code><?php echo esc_html($type); ?></code>
                <br><span style="color:#888;font-size:11px;"><?php echo esc_html((string) ($row['location'] ?? '')); ?></span>
            </td>
            <td>
                <?php if ($error !== '') : ?>
                    <span style="color:#b32d2e;font-weight:600;"><?php esc_html_e('Auto-disabled', 'claudpress'); ?></span>
                <?php elseif ($active) : ?>
                    <span style="color:#007017;font-weight:600;"><?php esc_html_e('Active', 'claudpress'); ?></span>
                <?php else : ?>
                    <span style="color:#787c82;"><?php esc_html_e('Inactive', 'claudpress'); ?></span>
                <?php endif; ?>
                <br>
                <?php
                self::form_open('snippet_toggle', $filters);
                ?>
                <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
                <input type="hidden" name="snippet_active" value="<?php echo $active ? '0' : '1'; ?>">
                <button type="submit" class="button button-small"><?php echo $active ? esc_html__('Deactivate', 'claudpress') : esc_html__('Activate', 'claudpress'); ?></button>
                </form>
            </td>
            <td>
                <input type="text" readonly value="<?php echo esc_attr((string) $id); ?>" onclick="this.select()" style="width:52px;text-align:center;">
                <?php if ($uid !== '') : ?>
                    <br><span style="color:#888;font-size:11px;"><?php echo esc_html($uid); ?></span>
                <?php endif; ?>
                <br><span style="color:#888;font-size:11px;">
                    <?php
                    printf(
                        /* translators: %d: snippet ID. */
                        esc_html__('Ask your agent: "tell me about snippet %d"', 'claudpress'),
                        $id
                    );
                    ?>
                </span>
            </td>
        </tr>
        <tr>
            <td colspan="6" style="background:#fbfbfc;">
                <details>
                    <summary style="cursor:pointer;"><?php esc_html_e('Details, code and controls', 'claudpress'); ?></summary>
                    <?php self::render_detail($row, $filters); ?>
                </details>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $filters
     */
    private static function render_detail(array $row, array $filters): void
    {
        $id       = (int) ($row['id'] ?? 0);
        $error    = trim((string) ($row['last_error'] ?? ''));
        $fallback = __('Legacy snippet — no notes recorded. Ask the agent: "what does snippet %d do?"', 'claudpress');
        ?>
        <div style="padding:10px 4px 4px;max-width:900px;">
            <h3 style="margin:8px 0 4px;"><?php esc_html_e('Why it exists', 'claudpress'); ?></h3>
            <p><?php echo trim((string) ($row['purpose'] ?? '')) !== '' ? esc_html((string) $row['purpose']) : esc_html(sprintf($fallback, $id)); ?></p>

            <h3 style="margin:12px 0 4px;"><?php esc_html_e('What it changes', 'claudpress'); ?></h3>
            <p><?php echo trim((string) ($row['impact'] ?? '')) !== '' ? esc_html((string) $row['impact']) : esc_html(sprintf($fallback, $id)); ?></p>

            <h3 style="margin:12px 0 4px;"><?php esc_html_e('Where and when it runs', 'claudpress'); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: 1: snippet type, 2: location, 3: priority. */
                    esc_html__('Type %1$s, location %2$s, priority %3$s.', 'claudpress'),
                    '<code>' . esc_html((string) ($row['snippet_type'] ?? '')) . '</code>',
                    '<code>' . esc_html((string) ($row['location'] ?? '')) . '</code>',
                    '<code>' . esc_html((string) ($row['priority'] ?? 10)) . '</code>'
                );
                ?>
                <?php if ((int) ($row['target_id'] ?? 0) > 0) : ?>
                    <br>
                    <?php
                    printf(
                        /* translators: %s: the page this template serves. */
                        esc_html__('Serves page %s.', 'claudpress'),
                        '<a href="' . esc_url((string) get_permalink((int) $row['target_id'])) . '" target="_blank" rel="noopener noreferrer">#' . esc_html((string) $row['target_id']) . '</a>'
                    );
                    ?>
                <?php endif; ?>
            </p>

            <h3 style="margin:12px 0 4px;"><?php esc_html_e('Where it came from', 'claudpress'); ?></h3>
            <p>
                <?php if (trim((string) ($row['origin_site'] ?? '')) !== '') : ?>
                    <?php
                    printf(
                        /* translators: %s: the site this snippet was copied from. */
                        esc_html__('Copied from %s. If your agent copies it again from there, any edit you make here is overwritten.', 'claudpress'),
                        '<strong>' . esc_html((string) $row['origin_site']) . '</strong>'
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e('Created on this site.', 'claudpress'); ?>
                <?php endif; ?>
                <br><span style="color:#888;">
                    <?php echo esc_html__('Portable ID:', 'claudpress') . ' ' . esc_html((string) ($row['uid'] ?? '')); ?>
                    &nbsp;·&nbsp;
                    <?php echo esc_html__('Created:', 'claudpress') . ' ' . esc_html((string) ($row['created_at'] ?? '—')); ?>
                    &nbsp;·&nbsp;
                    <?php echo esc_html__('Last changed:', 'claudpress') . ' ' . esc_html((string) ($row['updated_at'] ?? '—')); ?>
                </span>
            </p>

            <?php if ($error !== '') : ?>
                <div style="margin:12px 0;padding:10px 12px;border-left:4px solid #b32d2e;background:#fcf0f1;">
                    <strong><?php esc_html_e('This snippet switched itself off after an error:', 'claudpress'); ?></strong>
                    <br><code><?php echo esc_html($error); ?></code>
                </div>
            <?php endif; ?>

            <h3 style="margin:14px 0 4px;"><?php esc_html_e('Edit the code', 'claudpress'); ?></h3>
            <p style="color:#787c82;"><?php esc_html_e('Edits are not versioned — saving replaces what is stored, and there is no undo. PHP is checked for syntax errors first; code that does not parse is saved but switched off.', 'claudpress'); ?></p>
            <?php self::form_open('snippet_update', $filters); ?>
                <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
                <p>
                    <label><?php esc_html_e('Name', 'claudpress'); ?><br>
                    <input type="text" class="regular-text" name="snippet_name" value="<?php echo esc_attr((string) ($row['name'] ?? '')); ?>"></label>
                </p>
                <p>
                    <label><?php esc_html_e('Tags, comma separated', 'claudpress'); ?><br>
                    <input type="text" class="regular-text" name="snippet_tags" value="<?php echo esc_attr(implode(', ', Claudpress_Snippets::tags_array((string) ($row['tags'] ?? '')))); ?>"></label>
                </p>
                <p>
                    <label><?php esc_html_e('Code', 'claudpress'); ?><br>
                    <textarea name="code" rows="14" class="large-text code" spellcheck="false"><?php
                        /*
                         * Raw esc_textarea only. Do NOT add sanitize_textarea_field,
                         * wp_kses or any other filter to this field on the way in or
                         * out: they mangle valid code (&& becomes &amp;&amp;, angle
                         * brackets vanish) and hand the owner a silently broken
                         * snippet. The safety net here is the manage_options check,
                         * the nonce, the parse check in save(), and the runtime
                         * crash guard — not sanitising the source.
                         */
                        echo esc_textarea((string) ($row['code'] ?? ''));
                    ?></textarea></label>
                </p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save snippet', 'claudpress'); ?></button>
            </form>

            <?php self::form_open('snippet_delete', $filters); ?>
                <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
                <button type="submit" class="button button-link-delete" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js(__('Delete this snippet permanently? This cannot be undone.', 'claudpress')); ?>');"><?php esc_html_e('Delete permanently', 'claudpress'); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * Open a form posting to the shared admin-post handler.
     *
     * `claudpress_redirect_page` is what sends the user back here instead of the
     * Connect page; Claudpress_Admin validates it against a slug whitelist, so
     * it cannot be turned into an open redirect.
     *
     * @param array<string, string> $filters
     */
    private static function form_open(string $action, array $filters): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field('claudpress_save'); ?>
            <input type="hidden" name="action" value="claudpress_save">
            <input type="hidden" name="claudpress_action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="claudpress_redirect_page" value="claudpress-snippets">
            <input type="hidden" name="claudpress_filters" value="<?php echo esc_attr((string) wp_json_encode($filters)); ?>">
        <?php
    }
}
