<?php
/**
 * Admin UI: the Claudpress settings page. Flip abilities on/off, manage the
 * bearer token, and copy ready-to-paste connection commands for Claude Code and
 * other MCP clients.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Admin
{
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_claudpress_save', [$this, 'handle_save']);
        add_action('admin_bar_menu', [$this, 'admin_bar'], 100);
        add_action('admin_notices', [$this, 'notice']);
    }

    public function menu(): void
    {
        add_menu_page('Claudpress', 'Claudpress', 'manage_options', 'claudpress', [$this, 'render'], 'dashicons-superhero', 58);
        add_submenu_page('claudpress', __('Connect', 'claudpress'), __('Connect', 'claudpress'), 'manage_options', 'claudpress', [$this, 'render']);
        // Directly after Connect: this is the primary "what has the AI done to
        // my site" surface, and it must be easy to find in a hurry.
        add_submenu_page('claudpress', __('Snippets', 'claudpress'), __('Snippets', 'claudpress'), 'manage_options', 'claudpress-snippets', [$this, 'render_snippets']);
        add_submenu_page('claudpress', __('Automation', 'claudpress'), __('Automation', 'claudpress'), 'manage_options', 'claudpress-automation', [$this, 'render_automation']);
        add_submenu_page('claudpress', __('Guide', 'claudpress'), __('Guide', 'claudpress'), 'manage_options', 'claudpress-guide', [$this, 'render_guide']);
        add_submenu_page('claudpress', __('Activity', 'claudpress'), __('Activity', 'claudpress'), 'manage_options', 'claudpress-activity', [$this, 'render_activity']);
    }

    public function notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'claudpress') === false) {
            return;
        }
        if (Claudpress::instance()->abilities_enabled()) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Claudpress AI abilities are ON.', 'claudpress'),
                esc_html__('A connected AI agent can read and modify everything on this site. Use development/staging sites only.', 'claudpress')
            );
        }
    }

    public function admin_bar($bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $on = Claudpress::instance()->abilities_enabled();
        $bar->add_node([
            'id'    => 'claudpress',
            'title' => 'Claudpress: ' . ($on ? __('🟢 ON', 'claudpress') : __('⚪ OFF', 'claudpress')),
            'href'  => admin_url('admin.php?page=claudpress'),
        ]);
    }

    /**
     * Collapsible cheat-sheet of valid model IDs for every provider.
     *
     * The model field has to accept free text — vendors ship new models faster
     * than a plugin can ship a dropdown, and gateways expose their own names.
     * Free text means an owner can type something that simply does not exist and
     * get an opaque 404 from the API, so the valid IDs live right next to the
     * field, with a link to the vendor's list for anything newer than this build.
     *
     * @param array<string, class-string> $providers  id => adapter class
     * @param string                      $selected   currently chosen provider id
     */
    private static function render_model_guide(string $title, array $providers, string $selected): void
    {
        ?>
        <details style="margin:10px 0 18px;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px;background:#fbfbfc;">
            <summary style="cursor:pointer;font-weight:600;"><?php echo esc_html($title); ?></summary>
            <p style="color:#666;margin:10px 0;">
                <?php esc_html_e('Type the ID exactly as shown. Anything not on this list may still work if your provider offers it — check the vendor\'s model list.', 'claudpress'); ?>
            </p>
            <?php foreach ($providers as $pid => $cls) : ?>
                <div style="margin:12px 0;<?php echo $pid === $selected ? 'padding:8px 10px;border-left:3px solid #2271b1;background:#fff;' : ''; ?>">
                    <p style="margin:0 0 6px;">
                        <strong><?php echo esc_html($cls::label()); ?></strong>
                        <?php if ($pid === $selected) : ?>
                            <span style="color:#2271b1;">← <?php esc_html_e('your current provider', 'claudpress'); ?></span>
                        <?php endif; ?>
                    </p>
                    <table class="widefat striped" style="max-width:720px;">
                        <thead><tr>
                            <th style="width:230px;"><?php esc_html_e('Model ID to type', 'claudpress'); ?></th>
                            <th><?php esc_html_e('What it is', 'claudpress'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($cls::models() as $m) : ?>
                            <tr>
                                <td><code><?php echo esc_html($m['id']); ?></code></td>
                                <td><?php echo esc_html($m['note']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin:6px 0 0;">
                        <a href="<?php echo esc_url($cls::models_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Full model list from the vendor ↗', 'claudpress'); ?></a>
                        &nbsp;·&nbsp;
                        <a href="<?php echo esc_url($cls::key_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get an API key ↗', 'claudpress'); ?></a>
                    </p>
                </div>
            <?php endforeach; ?>
        </details>
        <?php
    }

    /**
     * Store an endpoint only when it genuinely overrides the provider default.
     *
     * Without this, switching provider would leave the previous vendor's URL
     * behind and every request would go to the wrong host with the right key.
     * Storing '' means "follow whichever provider is selected".
     */
    private static function endpoint_override(string $submitted, string $default): string
    {
        $submitted = trim($submitted);
        return ($submitted === '' || untrailingslashit($submitted) === untrailingslashit($default)) ? '' : $submitted;
    }

    /** Every admin screen this plugin owns. Also the redirect whitelist. */
    private static function page_slugs(): array
    {
        return ['claudpress', 'claudpress-snippets', 'claudpress-automation', 'claudpress-guide', 'claudpress-activity'];
    }

    /**
     * Send the user back to the page they submitted from, preserving its
     * filters.
     *
     * The slug is whitelisted rather than trusted: it arrives in the POST body,
     * and a free-text redirect target is an open-redirect vector.
     */
    private static function redirect_back(): void
    {
        $page = sanitize_key($_POST['claudpress_redirect_page'] ?? '');
        if (!in_array($page, self::page_slugs(), true)) {
            $page = 'claudpress';
        }
        $args = ['page' => $page, 'saved' => 1];

        $filters = json_decode((string) wp_unslash($_POST['claudpress_filters'] ?? ''), true);
        if (is_array($filters)) {
            foreach (['search' => 's', 'tag' => 'tag', 'snippet_type' => 'type', 'active' => 'active'] as $key => $arg) {
                $value = isset($filters[$key]) ? sanitize_text_field((string) $filters[$key]) : '';
                if ($value !== '') {
                    $args[$arg] = $value;
                }
            }
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function render_snippets(): void
    {
        (new Claudpress_Admin_Snippets())->render();
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('claudpress_save')) {
            wp_die(esc_html__('Unauthorized', 'claudpress'));
        }
        $action = $_POST['claudpress_action'] ?? 'settings';

        if ($action === 'regenerate_token') {
            Claudpress::instance()->update(['token' => Claudpress::generate_token()]);
        } elseif ($action === 'settings') {
            Claudpress::instance()->update([
                'abilities_enabled'   => !empty($_POST['abilities_enabled']),
                'allow_app_passwords' => !empty($_POST['allow_app_passwords']),
                'require_https'       => !empty($_POST['require_https']),
                'log_calls'           => !empty($_POST['log_calls']),
                'fs_base'             => !empty($_POST['fs_base']) ? sanitize_text_field(wp_unslash($_POST['fs_base'])) : ABSPATH,
                'run_snippets'        => !empty($_POST['run_snippets']),
                'site_label'          => sanitize_text_field(wp_unslash($_POST['site_label'] ?? '')),
                'builder'             => sanitize_key($_POST['builder'] ?? 'auto'),
                'image_provider'      => sanitize_key($_POST['image_provider'] ?? 'openai'),
                'image_api_key'       => sanitize_text_field(wp_unslash($_POST['image_api_key'] ?? '')),
                'image_api_endpoint'  => self::endpoint_override(
                    esc_url_raw(wp_unslash($_POST['image_api_endpoint'] ?? '')),
                    Claudpress_Image_Provider::get(sanitize_key($_POST['image_provider'] ?? 'openai'))::default_endpoint()
                ),
                'image_api_model'     => sanitize_text_field(wp_unslash($_POST['image_api_model'] ?? '')),
                'deploy_target_url'   => esc_url_raw(wp_unslash($_POST['deploy_target_url'] ?? '')),
                'deploy_target_token' => sanitize_text_field(wp_unslash($_POST['deploy_target_token'] ?? '')),
            ]);
        } elseif ($action === 'clear_memory') {
            Claudpress::instance()->memory->clear();
        } elseif ($action === 'capabilities') {
            $posted = isset($_POST['cap']) && is_array($_POST['cap']) ? array_map('sanitize_key', array_keys($_POST['cap'])) : [];
            $caps = [];
            foreach (array_keys(Claudpress_Capabilities::groups()) as $group) {
                $caps[$group] = in_array($group, $posted, true);
            }
            Claudpress::instance()->update(['capabilities' => $caps]);
        } elseif ($action === 'automation_settings') {
            Claudpress::instance()->update([
                'agent_provider'        => sanitize_key($_POST['agent_provider'] ?? 'anthropic'),
                'agent_api_key'         => sanitize_text_field(wp_unslash($_POST['agent_api_key'] ?? '')),
                'agent_api_endpoint'    => self::endpoint_override(
                    esc_url_raw(wp_unslash($_POST['agent_api_endpoint'] ?? '')),
                    Claudpress_Provider::get(sanitize_key($_POST['agent_provider'] ?? 'anthropic'))::default_endpoint()
                ),
                'agent_model'           => sanitize_text_field(wp_unslash($_POST['agent_model'] ?? '')),
                'agent_max_usd_per_run'    => max(0, (float) ($_POST['agent_max_usd_per_run'] ?? 0)),
                'agent_max_tokens_per_run' => max(0, (int) ($_POST['agent_max_tokens_per_run'] ?? 0)),
                'agent_max_usd_per_day'    => max(0, (float) ($_POST['agent_max_usd_per_day'] ?? 0)),
                'agent_max_runs_per_day'   => max(0, (int) ($_POST['agent_max_runs_per_day'] ?? 0)),
                'notify_email'          => sanitize_text_field(wp_unslash($_POST['notify_email'] ?? '')),
                'notify_webhook_url'    => esc_url_raw(wp_unslash($_POST['notify_webhook_url'] ?? '')),
                'notify_telegram_token' => sanitize_text_field(wp_unslash($_POST['notify_telegram_token'] ?? '')),
                'notify_telegram_chat'  => sanitize_text_field(wp_unslash($_POST['notify_telegram_chat'] ?? '')),
                'policy' => [
                    'enabled'       => !empty($_POST['policy_enabled']),
                    'blocked_tools' => array_filter(array_map('trim', explode(',', (string) wp_unslash($_POST['policy_blocked_tools'] ?? '')))),
                    'max_refund'    => (float) ($_POST['policy_max_refund'] ?? 0),
                    'require_optin' => !empty($_POST['policy_require_optin']),
                ],
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'saved' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_create') {
            Claudpress_Tasks::save([
                'name'     => sanitize_text_field(wp_unslash($_POST['task_name'] ?? 'Status digest')),
                'mode'     => 'digest',
                'schedule' => sanitize_key($_POST['task_schedule'] ?? 'daily'),
                'run_hour' => ($_POST['task_run_hour'] ?? '') === '' ? null : (int) $_POST['task_run_hour'],
                'notify'   => !empty($_POST['task_notify']),
                'enabled'  => true,
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'saved' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_run') {
            Claudpress_Tasks::run_task((int) ($_POST['task_id'] ?? 0));
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'ran' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_toggle') {
            $t = Claudpress_Tasks::get((int) ($_POST['task_id'] ?? 0));
            if ($t) {
                Claudpress_Tasks::toggle((int) $t['id'], (int) $t['enabled'] !== 1);
            }
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_delete') {
            Claudpress_Tasks::delete((int) ($_POST['task_id'] ?? 0));
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'telegram_settings') {
            Claudpress::instance()->update([
                'telegram_bot_enabled'   => !empty($_POST['telegram_bot_enabled']),
                'telegram_allowed_chats' => sanitize_text_field(wp_unslash($_POST['telegram_allowed_chats'] ?? '')),
                'telegram_history_turns' => max(0, min(60, (int) ($_POST['telegram_history_turns'] ?? 12))),
                'telegram_allow_risky'   => !empty($_POST['telegram_allow_risky']),
                'telegram_voice'         => !empty($_POST['telegram_voice']),
                'telegram_photos'        => !empty($_POST['telegram_photos']),
                'telegram_progress'      => !empty($_POST['telegram_progress']),
                'voice_provider'         => sanitize_key($_POST['voice_provider'] ?? 'openai'),
                'voice_api_key'          => sanitize_text_field(wp_unslash($_POST['voice_api_key'] ?? '')),
                'voice_model'            => sanitize_text_field(wp_unslash($_POST['voice_model'] ?? '')),
                'voice_api_endpoint'     => self::endpoint_override(
                    esc_url_raw(wp_unslash($_POST['voice_api_endpoint'] ?? '')),
                    Claudpress_Transcribe_Provider::get(sanitize_key($_POST['voice_provider'] ?? 'openai'))::default_endpoint()
                ),
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'saved' => 1], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'telegram_connect') {
            $res = Claudpress_Telegram::set_webhook();
            wp_safe_redirect(add_query_arg([
                'page'    => 'claudpress-automation',
                'tg'      => !empty($res['ok']) ? 'connected' : 'failed',
                'tg_msg'  => rawurlencode((string) ($res['description'] ?? '')),
            ], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'telegram_disconnect') {
            Claudpress_Telegram::delete_webhook();
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'tg' => 'disconnected'], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'telegram_rotate_secret') {
            // Rotating invalidates the old webhook registration, so re-register
            // in the same step — otherwise every delivery would 403 silently.
            Claudpress::instance()->update(['telegram_secret' => Claudpress_Telegram::generate_secret()]);
            $res = Claudpress_Telegram::enabled() ? Claudpress_Telegram::set_webhook() : ['ok' => true];
            wp_safe_redirect(add_query_arg([
                'page' => 'claudpress-automation',
                'tg'   => !empty($res['ok']) ? 'rotated' : 'failed',
            ], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'events_settings') {
            Claudpress::instance()->update([
                'events_enabled'   => !empty($_POST['events_enabled']),
                'events_order'     => !empty($_POST['events_order']),
                'events_order_min' => max(0, (float) ($_POST['events_order_min'] ?? 0)),
                'events_stock'     => !empty($_POST['events_stock']),
                'events_snippet'   => !empty($_POST['events_snippet']),
                'events_updates'   => !empty($_POST['events_updates']),
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'saved' => 1], admin_url('admin.php')) . '#events');
            exit;
        } elseif ($action === 'telegram_clear_chats') {
            $n = Claudpress_Telegram::clear_all();
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'tg' => 'cleared', 'tg_n' => $n], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'snippet_toggle') {
            Claudpress_Snippets::toggle((int) ($_POST['snippet_id'] ?? 0), (string) ($_POST['snippet_active'] ?? '0') === '1');
            self::redirect_back();
        } elseif ($action === 'snippet_update') {
            Claudpress_Snippets::save([
                'id'   => (int) ($_POST['snippet_id'] ?? 0),
                'name' => sanitize_text_field(wp_unslash($_POST['snippet_name'] ?? '')),
                'tags' => sanitize_text_field(wp_unslash($_POST['snippet_tags'] ?? '')),
                // Deliberately raw: any sanitiser corrupts valid PHP/JS/CSS
                // (&& → &amp;&amp;, angle brackets stripped) and would hand the
                // owner a broken snippet with no error. Authorisation is the
                // manage_options + nonce check above; correctness is the parse
                // check inside save() and the runtime crash guard.
                'code' => wp_unslash($_POST['code'] ?? ''),
            ]);
            self::redirect_back();
        } elseif ($action === 'snippet_delete') {
            Claudpress_Snippets::delete((int) ($_POST['snippet_id'] ?? 0));
            self::redirect_back();
        } elseif ($action === 'test_notify') {
            Claudpress_Notifier::send('[' . get_bloginfo('name') . '] Claudpress test', 'This is a test notification from Claudpress. Channels are working.');
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'tested' => 1], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => 'claudpress', 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        $s        = Claudpress::instance()->settings();
        $endpoint = rest_url(CLAUDPRESS_REST_NAMESPACE . '/mcp');
        $token    = (string) $s['token'];
        $host     = wp_parse_url(home_url(), PHP_URL_HOST);
        // The MCP server name is the string the agent sees for this site, so it
        // should be the same handle the owner uses — that is what makes
        // "copy that snippet to the other shop" resolve to the right server.
        $server_name = 'claudpress-' . sanitize_title(Claudpress::site_label() ?: ($host ?: 'site'));

        $claude_code_cmd = sprintf(
            "claude mcp add %s --transport http %s --header \"Authorization: Bearer %s\"",
            $server_name,
            esc_html($endpoint),
            esc_html($token)
        );

        $json_config = wp_json_encode([
            'mcpServers' => [
                $server_name => [
                    'type'    => 'http',
                    'url'     => $endpoint,
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $enabled = Claudpress::instance()->abilities_enabled();
        ?>
        <div class="wrap">
            <h1>Claudpress <span style="font-size:13px;color:#888;">v<?php echo esc_html(CLAUDPRESS_VERSION); ?></span></h1>
            <p style="max-width:760px;"><?php esc_html_e('Give an AI agent full, programmatic control of this WordPress & WooCommerce site over MCP — content, store, SEO, files, PHP — with cross-session memory and a skills engine. Self-contained, no external dependencies.', 'claudpress'); ?></p>

            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:#217296;background:radial-gradient(45% 200% at 100% 50%,#d37355 0%,rgba(211,115,85,.9) 25%,rgba(211,115,85,0) 62%),linear-gradient(104deg,#1b6183 0%,#217296 55%,#2a83aa 100%);color:#fff;padding:14px 20px;border-radius:10px;margin:6px 0 18px;max-width:980px;box-shadow:0 6px 20px rgba(33,114,150,.22);">
                <span style="font-size:22px;">📖</span>
                <div style="flex:1;min-width:240px;">
                    <strong style="font-size:15px;"><?php esc_html_e('New to Claudpress? Start with the Guide.', 'claudpress'); ?></strong><br>
                    <span style="opacity:.9;font-size:13px;"><?php esc_html_e('A plain-English walkthrough of every feature, what the AI can do, which switches to leave off, and how to prompt it.', 'claudpress'); ?></span>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=claudpress-guide')); ?>" class="button button-primary" style="background:#fff;color:#a2503a;border-color:#fff;font-weight:600;"><?php esc_html_e('Open the master guide →', 'claudpress'); ?></a>
            </div>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'claudpress'); ?></p></div>
            <?php endif; ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <div style="flex:1;min-width:420px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('1 · Turn on AI abilities', 'claudpress'); ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="settings">
                            <p>
                                <label style="font-size:15px;">
                                    <input type="checkbox" name="abilities_enabled" value="1" <?php checked($enabled); ?>>
                                    <strong><?php esc_html_e('Enable AI abilities', 'claudpress'); ?></strong> <?php esc_html_e('(master switch)', 'claudpress'); ?>
                                </label>
                            </p>
                            <p style="color:#a00;"><?php esc_html_e('While ON, a connected agent can modify everything. Keep this to dev/staging sites with backups.', 'claudpress'); ?></p>
                            <hr>
                            <p><label><input type="checkbox" name="allow_app_passwords" value="1" <?php checked(!empty($s['allow_app_passwords'])); ?>> <?php esc_html_e('Allow Application Password (Basic) auth in addition to the bearer token', 'claudpress'); ?></label></p>
                            <p><label><input type="checkbox" name="require_https" value="1" <?php checked(!empty($s['require_https'])); ?>> <?php esc_html_e('Require HTTPS (recommended for remote sites)', 'claudpress'); ?></label></p>
                            <p><label><input type="checkbox" name="log_calls" value="1" <?php checked(!empty($s['log_calls'])); ?>> <?php esc_html_e('Log tool calls (Activity tab)', 'claudpress'); ?></label></p>
                            <p>
                                <label><?php esc_html_e('Filesystem base directory (tools are confined at/below this path):', 'claudpress'); ?><br>
                                <input type="text" name="fs_base" value="<?php echo esc_attr($s['fs_base']); ?>" class="regular-text" style="width:100%;max-width:520px;"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Site label (what your agent calls this site):', 'claudpress'); ?><br>
                                <input type="text" name="site_label" value="<?php echo esc_attr((string) ($s['site_label'] ?? '')); ?>" placeholder="<?php echo esc_attr(Claudpress::site_label()); ?>" class="regular-text" style="width:100%;max-width:520px;"></label>
                                <br><span style="color:#787c82;"><?php esc_html_e('Leave blank to use the site name. Matters when one agent is connected to several sites — this is the handle you say out loud, as in "copy that snippet to the other shop".', 'claudpress'); ?></span>
                            </p>
                            <hr>
                            <p><label><input type="checkbox" name="run_snippets" value="1" <?php checked(!empty($s['run_snippets'])); ?>> <strong><?php esc_html_e('Run code snippets', 'claudpress'); ?></strong> <?php esc_html_e('(active PHP/CSS/JS/HTML snippets execute on the site; uncheck to kill all at once)', 'claudpress'); ?></label></p>
                            <p>
                                <label><strong><?php esc_html_e('Page builder', 'claudpress'); ?></strong> <?php esc_html_e('(loads matching guidance for the agent):', 'claudpress'); ?><br>
                                <select name="builder">
                                    <?php
                                    $builders = [
                                        'auto'           => __('Auto-detect', 'claudpress'),
                                        'gutenberg'      => __('Gutenberg (block editor)', 'claudpress'),
                                        'elementor_free' => __('Elementor (free) + Xpro Addons', 'claudpress'),
                                        'elementor_pro'  => __('Elementor Pro', 'claudpress'),
                                        'bricks'         => __('Bricks', 'claudpress'),
                                        'custom_php'     => __('Custom-coded (pixel-perfect PHP pages)', 'claudpress'),
                                    ];
                                    foreach ($builders as $val => $label) {
                                        printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($s['builder'] ?? 'auto', $val, false), esc_html($label));
                                    }
                                    ?>
                                </select></label>
                            </p>
                            <?php
                            $img_id = (string) ($s['image_provider'] ?? 'openai');
                            /** @var class-string<Claudpress_Image_Provider> $img_p */
                            $img_p  = Claudpress_Image_Provider::get($img_id);
                            ?>
                            <p style="color:#666;margin-top:16px;"><strong><?php esc_html_e('AI image generation', 'claudpress'); ?></strong> — <?php esc_html_e('optional. Leave the key blank to use free Openverse stock images via image_search instead.', 'claudpress'); ?></p>
                            <p>
                                <label><?php esc_html_e('Image provider:', 'claudpress'); ?>
                                    <select name="image_provider" style="max-width:320px;">
                                        <?php foreach (Claudpress_Image_Provider::providers() as $pid => $cls) : ?>
                                            <option value="<?php echo esc_attr($pid); ?>" <?php selected($img_id, $pid); ?>><?php echo esc_html($cls::label()); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </p>
                            <p><label><?php esc_html_e('Image API key:', 'claudpress'); ?> <input type="password" name="image_api_key" value="<?php echo esc_attr($s['image_api_key'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" autocomplete="off" placeholder="<?php echo esc_attr($img_p::key_hint()); ?>"></label></p>
                            <p><label><?php esc_html_e('Image model:', 'claudpress'); ?> <input type="text" name="image_api_model" value="<?php echo esc_attr($s['image_api_model'] ?? ''); ?>" class="regular-text" style="max-width:280px;" placeholder="<?php echo esc_attr($img_p::default_model()); ?>"></label>
                                <span style="color:#888;margin-left:8px;"><?php esc_html_e('Blank = ', 'claudpress'); ?><code><?php echo esc_html($img_p::default_model()); ?></code></span></p>
                            <p><label><?php esc_html_e('Image endpoint override (optional):', 'claudpress'); ?> <input type="text" name="image_api_endpoint" value="<?php echo esc_attr($s['image_api_endpoint'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" placeholder="<?php echo esc_attr($img_p::default_endpoint()); ?>"></label></p>

                            <?php self::render_model_guide(
                                __('Which image model ID do I put in the Image model field?', 'claudpress'),
                                Claudpress_Image_Provider::providers(),
                                $img_id
                            ); ?>
                            <p style="color:#666;margin-top:16px;"><strong><?php esc_html_e('Staging → production deploy', 'claudpress'); ?></strong> <?php esc_html_e('(optional — target for deploy_push: your live site\'s Claudpress endpoint)', 'claudpress'); ?></p>
                            <p><label><?php esc_html_e('Production site URL:', 'claudpress'); ?> <input type="text" name="deploy_target_url" value="<?php echo esc_attr($s['deploy_target_url'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" placeholder="https://your-live-site.com"></label></p>
                            <p><label><?php esc_html_e('Production bearer token:', 'claudpress'); ?> <input type="password" name="deploy_target_token" value="<?php echo esc_attr($s['deploy_target_token'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" autocomplete="off" placeholder="<?php esc_attr_e('cp_... (the live site\'s Claudpress token)', 'claudpress'); ?>"></label></p>
                            <p><button class="button button-primary"><?php esc_html_e('Save settings', 'claudpress'); ?></button></p>
                        </form>
                    </div>

                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('2 · Connect Claude Code', 'claudpress'); ?></h2>
                        <p><?php esc_html_e('Run this in your terminal (Claude Code will then list the Claudpress tools):', 'claudpress'); ?></p>
                        <textarea readonly rows="3" style="width:100%;font-family:monospace;font-size:12px;" onclick="this.select()"><?php echo esc_textarea($claude_code_cmd); ?></textarea>

                        <p style="margin-top:16px;"><strong><?php esc_html_e('Endpoint URL', 'claudpress'); ?></strong></p>
                        <input readonly value="<?php echo esc_attr($endpoint); ?>" class="regular-text" style="width:100%;max-width:520px;font-family:monospace;" onclick="this.select()">

                        <p style="margin-top:16px;"><strong><?php esc_html_e('Bearer token', 'claudpress'); ?></strong> <?php esc_html_e('(keep secret — it grants full site access)', 'claudpress'); ?></p>
                        <input readonly value="<?php echo esc_attr($token); ?>" class="regular-text" style="width:100%;max-width:520px;font-family:monospace;" onclick="this.select()">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;" onsubmit="return confirm('<?php echo esc_js(__('Regenerating the token will disconnect existing clients. Continue?', 'claudpress')); ?>');">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="regenerate_token">
                            <button class="button"><?php esc_html_e('Regenerate token', 'claudpress'); ?></button>
                        </form>

                        <details style="margin-top:16px;">
                            <summary style="cursor:pointer;"><?php esc_html_e('JSON config for Claude Desktop / Cursor / Codex / other clients', 'claudpress'); ?></summary>
                            <textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;margin-top:8px;" onclick="this.select()"><?php echo esc_textarea($json_config); ?></textarea>
                            <p style="color:#666;"><?php esc_html_e('Application Password alternative: use Authorization: Basic base64(username:app_password) against the same endpoint. Generate an Application Password under Users → Profile.', 'claudpress'); ?></p>
                        </details>
                    </div>
                </div>

                <div style="flex:1;min-width:300px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Status', 'claudpress'); ?></h2>
                        <p><?php esc_html_e('Abilities:', 'claudpress'); ?> <strong style="color:<?php echo $enabled ? '#16a34a' : '#888'; ?>;"><?php echo $enabled ? esc_html__('ENABLED', 'claudpress') : esc_html__('DISABLED', 'claudpress'); ?></strong></p>
                        <p><?php esc_html_e('Registered tools:', 'claudpress'); ?> <strong><?php echo count(Claudpress::instance()->registry->names()); ?></strong></p>
                        <p><?php esc_html_e('WooCommerce:', 'claudpress'); ?> <strong><?php echo class_exists('WooCommerce') ? esc_html__('detected ✓', 'claudpress') : esc_html__('not active', 'claudpress'); ?></strong></p>
                        <p><?php esc_html_e('Skills:', 'claudpress'); ?> <strong><?php echo count(Claudpress::instance()->skills->all()); ?></strong> · <?php esc_html_e('Memory:', 'claudpress'); ?> <strong><?php echo count(Claudpress::instance()->memory->all()); ?></strong> · <?php esc_html_e('Snippets:', 'claudpress'); ?> <strong><?php echo count(Claudpress_Snippets::all()); ?></strong></p>
                        <?php
                        /*
                         * The MCP endpoint only answers authenticated POSTs, so linking to it
                         * just showed the browser a 401 and read as a broken feature. Show the
                         * URL to copy instead, and keep the link only for the health check,
                         * which is the one route a browser can actually load.
                         */
                        ?>
                        <p style="margin-bottom:4px;"><?php esc_html_e('MCP endpoint (needs a token — not viewable in a browser):', 'claudpress'); ?></p>
                        <p><input type="text" readonly value="<?php echo esc_attr($endpoint); ?>" class="regular-text" style="width:100%;font-family:monospace;font-size:11px;" onclick="this.select();"></p>
                        <p><a href="<?php echo esc_url(rest_url(CLAUDPRESS_REST_NAMESPACE . '/status')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open health check ↗', 'claudpress'); ?></a></p>
                    </div>

                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Tool catalog', 'claudpress'); ?></h2>
                        <div style="max-height:320px;overflow:auto;font-family:monospace;font-size:12px;line-height:1.8;">
                            <?php foreach (Claudpress::instance()->registry->names() as $name) : ?>
                                <div><?php echo esc_html($name); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="max-width:none;padding:16px 20px;margin-top:24px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Capabilities — what the agent may touch', 'claudpress'); ?></h2>
                <p style="max-width:820px;"><?php esc_html_e('Turn any area off to make it off-limits. Disabled tools are hidden from the agent, refused if called, and named as off-limits in every session — for both interactive and autonomous use. Sensitive areas (money, shipping, code, files, users, deploy) are marked ●.', 'claudpress'); ?></p>
                <p style="color:#b45309;"><strong><?php esc_html_e('Note:', 'claudpress'); ?></strong> <?php esc_html_e('to truly wall off a sensitive area, also disable Code execution — otherwise the agent could reach it via raw PHP/SQL.', 'claudpress'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('claudpress_save'); ?>
                    <input type="hidden" name="action" value="claudpress_save">
                    <input type="hidden" name="claudpress_action" value="capabilities">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:10px 24px;">
                        <?php foreach (Claudpress_Capabilities::groups() as $key => $g) :
                            $on  = Claudpress_Capabilities::is_group_enabled($key);
                            $tip = sprintf(
                                /* translators: 1: what the capability lets the agent do, 2: comma-separated tool names */
                                __('WHEN ON — the agent can: %1$s Tools: %2$s.', 'claudpress'),
                                $g['desc'],
                                implode(', ', $g['tools'])
                            )
                                 . ' ' . __('WHEN OFF — these tools are hidden from the agent and refused if called.', 'claudpress')
                                 . (!empty($g['sensitive']) ? ' ' . __('This is a SENSITIVE area (money/data/code) — leave it off until you trust the agent.', 'claudpress') : ''); ?>
                            <label style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid #e2e2e2;border-radius:8px;background:<?php echo $on ? '#fff' : '#fbfbfb'; ?>;">
                                <input type="checkbox" name="cap[<?php echo esc_attr($key); ?>]" value="1" <?php checked($on); ?> style="margin-top:3px;">
                                <span>
                                    <strong><?php echo esc_html($g['label']); ?></strong>
                                    <?php if (!empty($g['sensitive'])) : ?>
                                        <span style="color:#b91c1c;" title="<?php esc_attr_e('Sensitive area', 'claudpress'); ?>">●</span>
                                    <?php endif; ?>
                                    <span title="<?php echo esc_attr($tip); ?>" style="cursor:help;color:#217296;font-weight:700;border:1px solid #b9d3e0;border-radius:50%;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;">i</span>
                                    <br><span style="color:#666;font-size:12px;"><?php echo esc_html($g['desc']); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p style="margin-top:14px;"><button class="button button-primary"><?php esc_html_e('Save capabilities', 'claudpress'); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_automation(): void
    {
        $s       = Claudpress::instance()->settings();
        $policy  = is_array($s['policy'] ?? null) ? $s['policy'] : [];
        $tasks   = Claudpress_Tasks::all();
        $next    = wp_next_scheduled(Claudpress_Tasks::HOOK);
        $has_key = !empty($s['agent_api_key']);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Claudpress · Automation', 'claudpress'); ?></h1>
            <p style="max-width:760px;"><?php esc_html_e('Let the site run itself. Digest tasks email you a status snapshot on a schedule. Autonomous tasks hand a plain-language instruction to the on-site AI agent, which does the work and reports back — no client open. Add a provider API key below to enable autonomy.', 'claudpress'); ?></p>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'claudpress'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['ran'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Task ran. See its last summary below.', 'claudpress'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['tested'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test notification sent.', 'claudpress'); ?></p></div><?php endif; ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:2;min-width:460px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;">
                            <?php esc_html_e('Autonomy engine', 'claudpress'); ?>
                            <?php if ($has_key) : ?>
                                <span style="color:#16a34a;"><?php esc_html_e('● ready', 'claudpress'); ?></span>
                            <?php else : ?>
                                <span style="color:#888;"><?php esc_html_e('○ needs API key', 'claudpress'); ?></span>
                            <?php endif; ?>
                        </h2>
                        <p>
                            <?php esc_html_e('Cron heartbeat:', 'claudpress'); ?>
                            <strong><?php echo $next
                                /* translators: %s: human-readable time until the next cron run */
                                ? esc_html(sprintf(__('%s from now', 'claudpress'), human_time_diff(time(), $next)))
                                : esc_html__('not scheduled', 'claudpress'); ?></strong>
                            <?php esc_html_e('(WP-Cron fires when your site gets traffic).', 'claudpress'); ?>
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="automation_settings">
                            <?php
                            $agent_id = (string) ($s['agent_provider'] ?? 'anthropic');
                            /** @var class-string<Claudpress_Provider> $agent_p */
                            $agent_p  = Claudpress_Provider::get($agent_id);
                            ?>
                            <h3><?php esc_html_e('Agent brain', 'claudpress'); ?></h3>
                            <p style="color:#666;max-width:620px;">
                                <?php esc_html_e('Bring your own key. Pick your provider, paste its key, and choose a model. Leave the model and endpoint blank to use the provider\'s recommended defaults.', 'claudpress'); ?>
                            </p>
                            <p>
                                <label><?php esc_html_e('Provider:', 'claudpress'); ?>
                                    <select name="agent_provider" style="max-width:280px;">
                                        <?php foreach (Claudpress_Provider::providers() as $pid => $cls) : ?>
                                            <option value="<?php echo esc_attr($pid); ?>" <?php selected($agent_id, $pid); ?>><?php echo esc_html($cls::label()); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <span style="color:#888;margin-left:8px;"><?php esc_html_e('Save after changing, then check the model guide below.', 'claudpress'); ?></span>
                            </p>
                            <p><label><?php esc_html_e('API key:', 'claudpress'); ?> <input type="password" name="agent_api_key" value="<?php echo esc_attr($s['agent_api_key'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" autocomplete="off" placeholder="<?php echo esc_attr($agent_p::key_hint()); ?>"></label>
                                <br><span style="color:#888;"><?php esc_html_e('Get a key:', 'claudpress'); ?> <a href="<?php echo esc_url($agent_p::key_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($agent_p::key_url()); ?></a></span></p>
                            <p><label><?php esc_html_e('Model:', 'claudpress'); ?> <input type="text" name="agent_model" value="<?php echo esc_attr($s['agent_model'] ?? ''); ?>" class="regular-text" style="max-width:280px;" placeholder="<?php echo esc_attr($agent_p::default_model()); ?>"></label>
                                <span style="color:#888;margin-left:8px;"><?php esc_html_e('Blank = ', 'claudpress'); ?><code><?php echo esc_html($agent_p::default_model()); ?></code></span></p>
                            <p><label><?php esc_html_e('Endpoint override (optional):', 'claudpress'); ?> <input type="text" name="agent_api_endpoint" value="<?php echo esc_attr($s['agent_api_endpoint'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" placeholder="<?php echo esc_attr($agent_p::default_endpoint()); ?>"></label>
                                <br><span style="color:#888;"><?php esc_html_e('Only needed for a gateway, proxy or self-hosted deployment (Azure, OpenRouter, Ollama…). Blank uses the provider default.', 'claudpress'); ?></span></p>

                            <?php self::render_model_guide(
                                __('Which model ID do I put in the Model field?', 'claudpress'),
                                Claudpress_Provider::providers(),
                                $agent_id
                            ); ?>

                            <h3><?php esc_html_e('Budget caps', 'claudpress'); ?></h3>
                            <p style="color:#666;max-width:620px;">
                                <?php esc_html_e('The agent bills your API key on every run. These caps stop a runaway loop from spending real money — a run halts cleanly and reports what it managed to finish.', 'claudpress'); ?>
                                <strong><?php esc_html_e('0 = unlimited.', 'claudpress'); ?></strong>
                            </p>
                            <p style="color:#b45309;max-width:620px;">
                                <?php esc_html_e('Costs shown here are estimates, not billing. They are calculated from token counts using built-in list prices that can go out of date, and they do not know about your discounts, cached-token rates, or a gateway\'s own pricing. Treat them as a safety brake — your provider\'s invoice is the real number.', 'claudpress'); ?>
                            </p>
                            <p>
                                <label><?php esc_html_e('Max spend per run (USD):', 'claudpress'); ?> <input type="number" step="0.01" min="0" name="agent_max_usd_per_run" value="<?php echo esc_attr($s['agent_max_usd_per_run'] ?? 0.5); ?>" style="max-width:120px;"></label>
                                <label style="margin-left:14px;"><?php esc_html_e('Max tokens per run:', 'claudpress'); ?> <input type="number" step="1000" min="0" name="agent_max_tokens_per_run" value="<?php echo esc_attr($s['agent_max_tokens_per_run'] ?? 300000); ?>" style="max-width:140px;"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Max spend per day (USD):', 'claudpress'); ?> <input type="number" step="0.01" min="0" name="agent_max_usd_per_day" value="<?php echo esc_attr($s['agent_max_usd_per_day'] ?? 5); ?>" style="max-width:120px;"></label>
                                <label style="margin-left:14px;"><?php esc_html_e('Max runs per day:', 'claudpress'); ?> <input type="number" step="1" min="0" name="agent_max_runs_per_day" value="<?php echo esc_attr($s['agent_max_runs_per_day'] ?? 48); ?>" style="max-width:120px;"></label>
                            </p>
                            <?php $u24 = Claudpress_Agent_Runs::usage_since(24); ?>
                            <p style="color:#555;">
                                <?php
                                printf(
                                    /* translators: 1: number of runs, 2: token count, 3: estimated USD cost */
                                    esc_html__('Last 24h: %1$s runs · %2$s tokens · $%3$s estimated.', 'claudpress'),
                                    '<strong>' . esc_html((string) (int) $u24['runs']) . '</strong>',
                                    '<strong>' . esc_html(number_format((int) $u24['tokens'])) . '</strong>',
                                    '<strong>' . esc_html(number_format((float) $u24['cost'], 4)) . '</strong>'
                                );
                                ?>
                            </p>

                            <h3><?php esc_html_e('Notifications', 'claudpress'); ?></h3>
                            <p><label><?php esc_html_e('Email(s), comma-separated (blank = admin email):', 'claudpress'); ?> <input type="text" name="notify_email" value="<?php echo esc_attr($s['notify_email'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>
                            <p><label><?php esc_html_e('Webhook URL (Slack/generic, optional):', 'claudpress'); ?> <input type="text" name="notify_webhook_url" value="<?php echo esc_attr($s['notify_webhook_url'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>
                            <p><label><?php esc_html_e('Telegram bot token:', 'claudpress'); ?> <input type="text" name="notify_telegram_token" value="<?php echo esc_attr($s['notify_telegram_token'] ?? ''); ?>" class="regular-text" style="max-width:340px;"></label>
                               <label style="margin-left:8px;"><?php esc_html_e('chat id:', 'claudpress'); ?> <input type="text" name="notify_telegram_chat" value="<?php echo esc_attr($s['notify_telegram_chat'] ?? ''); ?>" style="max-width:160px;"></label></p>

                            <h3><?php esc_html_e('Guardrails (policy)', 'claudpress'); ?></h3>
                            <p><label><input type="checkbox" name="policy_enabled" value="1" <?php checked(!empty($policy['enabled'])); ?>> <?php esc_html_e('Enforce policy', 'claudpress'); ?></label></p>
                            <p><label><input type="checkbox" name="policy_require_optin" value="1" <?php checked(!empty($policy['require_optin'])); ?>> <?php esc_html_e('In autonomous runs, require a task to opt in (allow_risky) before destructive/financial tools', 'claudpress'); ?></label></p>
                            <p><label><?php esc_html_e('Max autonomous refund (0 = block refunds):', 'claudpress'); ?> <input type="number" step="0.01" name="policy_max_refund" value="<?php echo esc_attr($policy['max_refund'] ?? 0); ?>" style="max-width:140px;"></label></p>
                            <p><label><?php esc_html_e('Always-blocked tools (comma-separated, optional):', 'claudpress'); ?> <input type="text" name="policy_blocked_tools" value="<?php echo esc_attr(implode(',', (array) ($policy['blocked_tools'] ?? []))); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>

                            <p><button class="button button-primary"><?php esc_html_e('Save automation settings', 'claudpress'); ?></button></p>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="test_notify">
                            <button class="button"><?php esc_html_e('Send test notification', 'claudpress'); ?></button>
                        </form>
                    </div>

                    <?php $this->render_telegram_card($s); ?>
                    <?php $this->render_events_card($s); ?>

                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Scheduled tasks', 'claudpress'); ?></h2>

                        <div style="background:#f6f7f7;border:1px solid #e2e4e7;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                            <strong><?php esc_html_e('Schedule a status digest', 'claudpress'); ?></strong>
                            <p style="color:#666;margin:.4em 0 .8em;">
                                <?php
                                printf(
                                    /* translators: 1: site timezone string, 2: current site time (HH:MM) */
                                    esc_html__('Get the store snapshot sent to you on a cadence — no API key needed. Times use the site timezone (%1$s, currently %2$s).', 'claudpress'),
                                    '<code>' . esc_html(wp_timezone_string()) . '</code>',
                                    esc_html(current_time('H:i'))
                                );
                                ?>
                            </p>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                                <?php wp_nonce_field('claudpress_save'); ?>
                                <input type="hidden" name="action" value="claudpress_save">
                                <input type="hidden" name="claudpress_action" value="task_create">
                                <label><?php esc_html_e('Name', 'claudpress'); ?><br><input type="text" name="task_name" value="<?php esc_attr_e('Status digest', 'claudpress'); ?>" style="max-width:200px;"></label>
                                <label><?php esc_html_e('Cadence', 'claudpress'); ?><br>
                                    <select name="task_schedule" id="cp-digest-schedule">
                                        <?php
                                        $cadences = [
                                            'hourly'     => __('Every hour', 'claudpress'),
                                            'twicedaily' => __('Twice a day', 'claudpress'),
                                            'daily'      => __('Daily', 'claudpress'),
                                            'weekly'     => __('Weekly', 'claudpress'),
                                            'monthly'    => __('Monthly', 'claudpress'),
                                        ];
                                        foreach ($cadences as $val => $label) : ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($val, 'daily'); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><?php esc_html_e('At hour', 'claudpress'); ?><br>
                                    <select name="task_run_hour" id="cp-digest-hour">
                                        <option value=""><?php esc_html_e('any time', 'claudpress'); ?></option>
                                        <?php for ($hr = 0; $hr < 24; $hr++) : ?>
                                            <option value="<?php echo (int) $hr; ?>" <?php selected($hr, 8); ?>><?php echo esc_html(sprintf('%02d:00', $hr)); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                                <label style="padding-bottom:6px;"><input type="checkbox" name="task_notify" value="1" checked> <?php esc_html_e('Notify me', 'claudpress'); ?></label>
                                <button class="button button-primary"><?php esc_html_e('Add digest', 'claudpress'); ?></button>
                            </form>
                            <p style="color:#888;font-size:12px;margin:.8em 0 0;"><?php esc_html_e('Hour-of-day applies to daily, weekly and monthly cadences. WP-Cron fires on site traffic, so a digest lands on the first visit at or after the chosen hour.', 'claudpress'); ?></p>
                        </div>

                        <p style="color:#666;"><?php esc_html_e('You can also create tasks by asking your AI:', 'claudpress'); ?> <em><?php esc_html_e('"Create an autonomous task that fulfils paid orders and publishes due drafts every morning at 7."', 'claudpress'); ?></em></p>
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php esc_html_e('Name', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Mode', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Schedule', 'claudpress'); ?></th>
                                <th><?php esc_html_e('On', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Last run', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Actions', 'claudpress'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($tasks as $t) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($t['name']); ?></strong><?php if ((int) $t['allow_risky'] === 1) : ?> <span title="<?php esc_attr_e('allow_risky', 'claudpress'); ?>">⚠️</span><?php endif; ?></td>
                                    <td><?php echo esc_html($t['mode']); ?></td>
                                    <td><?php
                                        echo esc_html($t['schedule']);
                                        if (isset($t['run_hour']) && $t['run_hour'] !== null && $t['run_hour'] !== '') {
                                            echo ' <span style="color:#666;">@ ' . esc_html(sprintf('%02d:00', (int) $t['run_hour'])) . '</span>';
                                        }
                                    ?></td>
                                    <td><?php echo (int) $t['enabled'] === 1 ? '✓' : '—'; ?></td>
                                    <td><?php
                                        if ($t['last_run']) {
                                            printf(
                                                /* translators: 1: timestamp of the last run, 2: its status */
                                                esc_html__('%1$s (%2$s)', 'claudpress'),
                                                esc_html($t['last_run']),
                                                esc_html((string) $t['last_status'])
                                            );
                                        } else {
                                            echo '<em>' . esc_html__('never', 'claudpress') . '</em>';
                                        }
                                    ?></td>
                                    <td style="white-space:nowrap;">
                                        <?php
                                        $task_actions = [
                                            'task_run'    => __('Run now', 'claudpress'),
                                            'task_toggle' => ((int) $t['enabled'] === 1 ? __('Disable', 'claudpress') : __('Enable', 'claudpress')),
                                            'task_delete' => __('Delete', 'claudpress'),
                                        ];
                                        foreach ($task_actions as $act => $label) : ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" <?php if ($act === 'task_delete') : ?>onsubmit="return confirm('<?php echo esc_js(__('Delete this task?', 'claudpress')); ?>');"<?php endif; ?>>
                                                <?php wp_nonce_field('claudpress_save'); ?>
                                                <input type="hidden" name="action" value="claudpress_save">
                                                <input type="hidden" name="claudpress_action" value="<?php echo esc_attr($act); ?>">
                                                <input type="hidden" name="task_id" value="<?php echo (int) $t['id']; ?>">
                                                <button class="button button-small"><?php echo esc_html($label); ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($t['last_summary'])) : ?>
                                    <tr><td colspan="6" style="background:#fafafa;"><small><strong><?php esc_html_e('Last summary:', 'claudpress'); ?></strong> <?php echo esc_html(wp_trim_words((string) $t['last_summary'], 60)); ?></small></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($tasks)) : ?><tr><td colspan="6"><em><?php esc_html_e('No tasks yet.', 'claudpress'); ?></em></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php $runs = Claudpress_Agent_Runs::recent(25); ?>
                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Agent run history', 'claudpress'); ?></h2>
                        <p style="color:#666;"><?php esc_html_e('Every autonomous run — scheduled or one-off — with what it cost. "capped" means a budget cap stopped it early; "truncated" means it hit the step limit. Costs are estimates, not billing.', 'claudpress'); ?></p>
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php esc_html_e('Started', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Source', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Status', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Steps', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Tools', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Retries', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Tokens', 'claudpress'); ?></th>
                                <th><?php esc_html_e('Est. cost', 'claudpress'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($runs as $r) :
                                $colors = ['ok' => '#16a34a', 'error' => '#b91c1c', 'capped' => '#b45309', 'truncated' => '#b45309', 'running' => '#888'];
                                $color  = $colors[$r['status']] ?? '#555'; ?>
                                <tr>
                                    <td><?php echo esc_html($r['started_at']); ?></td>
                                    <td><?php echo esc_html($r['context']); ?><?php echo $r['task_id'] ? ' #' . (int) $r['task_id'] : ''; ?></td>
                                    <td style="color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo esc_html($r['status']); ?></td>
                                    <td><?php echo (int) $r['steps']; ?></td>
                                    <td><?php echo (int) $r['tool_calls']; ?></td>
                                    <td><?php echo (int) $r['retries'] ?: '—'; ?></td>
                                    <td><?php echo esc_html(number_format((int) $r['input_tokens'] + (int) $r['output_tokens'])); ?></td>
                                    <td>$<?php echo esc_html(number_format((float) $r['cost_usd'], 4)); ?></td>
                                </tr>
                                <?php if (!empty($r['summary'])) : ?>
                                    <tr><td colspan="8" style="background:#fafafa;"><small><strong><?php echo esc_html(wp_trim_words((string) $r['instruction'], 18)); ?></strong> → <?php echo esc_html(wp_trim_words((string) $r['summary'], 50)); ?></small></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($runs)) : ?><tr><td colspan="8"><em><?php esc_html_e('No autonomous runs yet.', 'claudpress'); ?></em></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="flex:1;min-width:300px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Live status', 'claudpress'); ?></h2>
                        <?php $d = Claudpress_Digest::snapshot(); ?>
                        <ul style="line-height:1.7;">
                            <?php foreach ($d['summary'] as $line) : ?><li><?php echo esc_html($line); ?></li><?php endforeach; ?>
                        </ul>
                        <details><summary style="cursor:pointer;"><?php esc_html_e('Full snapshot JSON', 'claudpress'); ?></summary>
                        <pre style="white-space:pre-wrap;font-size:11px;max-height:320px;overflow:auto;"><?php echo esc_html(wp_json_encode($d, JSON_PRETTY_PRINT)); ?></pre></details>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * The Telegram chat card on the Automation tab.
     *
     * Split out of render_automation() because it is the one panel that talks to
     * a third-party API while rendering (to show whether the webhook is really
     * registered), and that call is worth keeping visible rather than buried in
     * a 300-line template.
     */
    private function render_telegram_card(array $s): void
    {
        $tg_on      = Claudpress_Telegram::enabled();
        $tg_tok     = Claudpress_Telegram::token() !== '';
        $chat_roles = Claudpress_Telegram::chat_roles();
        $allowed    = array_keys($chat_roles);

        // Only ask Telegram about the webhook when there is something to ask
        // about — otherwise every page load would pay for a pointless request.
        $info = $tg_on ? Claudpress_Telegram::webhook_info() : [];
        $hook = is_array($info['result'] ?? null) ? $info['result'] : [];
        $live = !empty($hook['url']) && strpos((string) $hook['url'], Claudpress_Telegram::webhook_url()) === 0;
        ?>
        <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;" id="chat">
            <h2 style="margin-top:0;">
                <?php esc_html_e('Chat to your site on Telegram', 'claudpress'); ?>
                <?php if ($tg_on && $live) : ?>
                    <span style="color:#16a34a;"><?php esc_html_e('● connected', 'claudpress'); ?></span>
                <?php elseif ($tg_on) : ?>
                    <span style="color:#b45309;"><?php esc_html_e('● on, webhook not registered', 'claudpress'); ?></span>
                <?php else : ?>
                    <span style="color:#888;"><?php esc_html_e('○ off', 'claudpress'); ?></span>
                <?php endif; ?>
            </h2>

            <p style="max-width:720px;">
                <?php esc_html_e('Message a Telegram bot and the on-site agent does the work — same tools, same guardrails as a scheduled run. No MCP client, no terminal, no laptop. This uses the bot token above, and it bills the API key above.', 'claudpress'); ?>
            </p>

            <?php if (isset($_GET['tg'])) :
                $tg_state = sanitize_key(wp_unslash($_GET['tg']));
                $tg_note  = [
                    'connected'    => __('Bot connected. Message it on Telegram to try it.', 'claudpress'),
                    'disconnected' => __('Webhook removed. The bot no longer reaches this site.', 'claudpress'),
                    'rotated'      => __('Secret rotated and the webhook re-registered.', 'claudpress'),
                    'cleared'      => __('Conversation history wiped.', 'claudpress'),
                    'failed'       => __('Telegram rejected that. Check the bot token, and that this site is reachable over public HTTPS.', 'claudpress'),
                ][$tg_state] ?? '';
                ?>
                <?php if ($tg_note !== '') : ?>
                    <div class="notice <?php echo $tg_state === 'failed' ? 'notice-error' : 'notice-success'; ?> is-dismissible" style="margin:10px 0;">
                        <p><?php echo esc_html($tg_note); ?>
                        <?php if (!empty($_GET['tg_msg'])) : ?>
                            <code><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['tg_msg'])))); ?></code>
                        <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$tg_tok) : ?>
                <div class="notice notice-warning inline" style="margin:10px 0;">
                    <p><?php esc_html_e('Add a Telegram bot token in the Notifications section above first — talk to @BotFather on Telegram, send /newbot, and paste the token it gives you.', 'claudpress'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('claudpress_save'); ?>
                <input type="hidden" name="action" value="claudpress_save">
                <input type="hidden" name="claudpress_action" value="telegram_settings">

                <p>
                    <label style="font-size:15px;">
                        <input type="checkbox" name="telegram_bot_enabled" value="1" <?php checked($tg_on); ?>>
                        <strong><?php esc_html_e('Accept messages from Telegram', 'claudpress'); ?></strong>
                    </label>
                </p>

                <p>
                    <label><?php esc_html_e('Allowed chat IDs (comma-separated, optionally with a role):', 'claudpress'); ?><br>
                    <input type="text" name="telegram_allowed_chats" value="<?php echo esc_attr($s['telegram_allowed_chats'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" placeholder="<?php echo esc_attr(($s['notify_telegram_chat'] ?? '123456789') . ', 987654321:viewer'); ?>"></label>
                    <br><span style="color:#888;">
                        <?php esc_html_e('Only these chats get answered; everyone else is refused. Blank falls back to the notification chat ID above. Don\'t know yours? Message the bot and it replies with your ID.', 'claudpress'); ?>
                    </span>
                    <?php if (empty($allowed)) : ?>
                        <br><span style="color:#a00;"><?php esc_html_e('No chat is allowlisted yet, so the bot will refuse everyone — including you.', 'claudpress'); ?></span>
                    <?php endif; ?>
                </p>

                <table class="widefat striped" style="max-width:720px;margin:0 0 14px;">
                    <thead><tr>
                        <th style="width:150px;"><?php esc_html_e('Write it as', 'claudpress'); ?></th>
                        <th><?php esc_html_e('What that chat can do', 'claudpress'); ?></th>
                    </tr></thead>
                    <tbody>
                        <tr><td><code>123456789</code></td><td><?php esc_html_e('admin (the default) — everything, may press Approve and use /risky', 'claudpress'); ?></td></tr>
                        <tr><td><code>123456789:editor</code></td><td><?php esc_html_e('may change the site, but never destructive or financial tools, and cannot approve', 'claudpress'); ?></td></tr>
                        <tr><td><code>123456789:viewer</code></td><td><?php esc_html_e('read-only — can ask questions and read reports, cannot change anything', 'claudpress'); ?></td></tr>
                    </tbody>
                </table>

                <?php if (!empty($chat_roles)) : ?>
                    <p style="color:#555;">
                        <strong><?php esc_html_e('Currently allowlisted:', 'claudpress'); ?></strong>
                        <?php
                        $bits = [];
                        foreach ($chat_roles as $cid => $crole) {
                            $bits[] = '<code>' . esc_html((string) $cid) . '</code> → ' . esc_html($crole);
                        }
                        echo wp_kses_post(implode(' · ', $bits));
                        ?>
                    </p>
                <?php endif; ?>

                <p>
                    <label><?php esc_html_e('Remember this many turns per conversation:', 'claudpress'); ?>
                    <input type="number" min="0" max="60" step="1" name="telegram_history_turns" value="<?php echo esc_attr($s['telegram_history_turns'] ?? 12); ?>" style="max-width:100px;"></label>
                    <span style="color:#888;margin-left:8px;"><?php esc_html_e('0 = every message starts fresh. Longer memory means better follow-ups and a bigger bill per message.', 'claudpress'); ?></span>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="telegram_allow_risky" value="1" <?php checked(!empty($s['telegram_allow_risky'])); ?>>
                        <?php esc_html_e('Let an admin chat escalate to destructive/financial tools', 'claudpress'); ?>
                    </label>
                    <br><span style="color:#888;"><?php esc_html_e('Off by default. When allowed, escalation is either a per-action "Approve & run" button on the reply, or a /risky on window that lapses by itself after 30 minutes. Editor and viewer chats can never escalate, whatever this says.', 'claudpress'); ?></span>
                </p>

                <hr>
                <h3><?php esc_html_e('Voice notes, photos and progress', 'claudpress'); ?></h3>

                <p>
                    <label>
                        <input type="checkbox" name="telegram_progress" value="1" <?php checked(!empty($s['telegram_progress'])); ?>>
                        <?php esc_html_e('Show the work in progress', 'claudpress'); ?>
                    </label>
                    <br><span style="color:#888;"><?php esc_html_e('One message is edited in place as the agent works, then becomes the answer — instead of silence for 40 seconds.', 'claudpress'); ?></span>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="telegram_photos" value="1" <?php checked(!empty($s['telegram_photos'])); ?>>
                        <?php esc_html_e('Accept photos and screenshots', 'claudpress'); ?>
                    </label>
                    <br><span style="color:#888;"><?php esc_html_e('The image is shown to the model and filed in the media library, so it can go straight into a post or product. Needs a model that can see images — all current Claude, GPT and Gemini models can.', 'claudpress'); ?></span>
                </p>

                <?php
                $voice_id = (string) ($s['voice_provider'] ?? 'openai');
                /** @var class-string<Claudpress_Transcribe_Provider> $voice_p */
                $voice_p   = Claudpress_Transcribe_Provider::get($voice_id);
                $voice_key = Claudpress_Transcribe_Provider::key();
                ?>
                <p>
                    <label>
                        <input type="checkbox" name="telegram_voice" value="1" <?php checked(!empty($s['telegram_voice'])); ?>>
                        <?php esc_html_e('Accept voice notes', 'claudpress'); ?>
                    </label>
                    <br><span style="color:#888;"><?php esc_html_e('Transcribed first, and the transcript is shown to you before the agent acts — so a mis-hearing is visible, not silently acted on.', 'claudpress'); ?></span>
                    <?php if (!empty($s['telegram_voice']) && $voice_key === '') : ?>
                        <br><span style="color:#a00;"><?php esc_html_e('Voice is on but there is no transcription key, so voice notes will be refused.', 'claudpress'); ?></span>
                    <?php endif; ?>
                </p>

                <p style="color:#666;max-width:620px;">
                    <?php esc_html_e('Transcription is a separate service from the agent brain: Anthropic has no speech-to-text API, so voice needs an OpenAI or Google key even if Claude is doing the thinking. Leave the key blank to reuse a key you already entered for the same vendor.', 'claudpress'); ?>
                </p>
                <p>
                    <label><?php esc_html_e('Transcription provider:', 'claudpress'); ?>
                        <select name="voice_provider" style="max-width:320px;">
                            <?php foreach (Claudpress_Transcribe_Provider::providers() as $vid => $vcls) : ?>
                                <option value="<?php echo esc_attr($vid); ?>" <?php selected($voice_id, $vid); ?>><?php echo esc_html($vcls::label()); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label><?php esc_html_e('Transcription API key:', 'claudpress'); ?>
                    <input type="password" name="voice_api_key" value="<?php echo esc_attr($s['voice_api_key'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" autocomplete="off"></label>
                    <br><span style="color:#888;">
                        <?php
                        if ($voice_key !== '' && trim((string) ($s['voice_api_key'] ?? '')) === '') {
                            esc_html_e('Blank — currently reusing another key you configured for this vendor.', 'claudpress');
                        } else {
                            esc_html_e('Get a key:', 'claudpress');
                            echo ' <a href="' . esc_url($voice_p::key_url()) . '" target="_blank" rel="noopener noreferrer">' . esc_html($voice_p::key_url()) . '</a>';
                        }
                        ?>
                    </span>
                </p>
                <p>
                    <label><?php esc_html_e('Transcription model:', 'claudpress'); ?>
                    <input type="text" name="voice_model" value="<?php echo esc_attr($s['voice_model'] ?? ''); ?>" class="regular-text" style="max-width:280px;" placeholder="<?php echo esc_attr($voice_p::default_model()); ?>"></label>
                    <span style="color:#888;margin-left:8px;"><?php esc_html_e('Blank = ', 'claudpress'); ?><code><?php echo esc_html($voice_p::default_model()); ?></code></span>
                </p>
                <p>
                    <label><?php esc_html_e('Transcription endpoint override (optional):', 'claudpress'); ?>
                    <input type="text" name="voice_api_endpoint" value="<?php echo esc_attr($s['voice_api_endpoint'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" placeholder="<?php echo esc_attr($voice_p::default_endpoint()); ?>"></label>
                </p>

                <p><button class="button button-primary"><?php esc_html_e('Save chat settings', 'claudpress'); ?></button></p>
            </form>

            <hr>
            <p>
                <strong><?php esc_html_e('Webhook:', 'claudpress'); ?></strong>
                <code><?php echo esc_html(Claudpress_Telegram::webhook_url()); ?></code>
            </p>
            <?php if ($tg_on && !empty($hook['last_error_message'])) : ?>
                <p style="color:#b91c1c;">
                    <?php esc_html_e('Telegram\'s last delivery failed:', 'claudpress'); ?>
                    <code><?php echo esc_html((string) $hook['last_error_message']); ?></code>
                </p>
            <?php endif; ?>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php
                $tg_buttons = [
                    'telegram_connect'       => __('Connect bot to this site', 'claudpress'),
                    'telegram_disconnect'    => __('Disconnect', 'claudpress'),
                    'telegram_rotate_secret' => __('Rotate webhook secret', 'claudpress'),
                    'telegram_clear_chats'   => __('Clear all conversations', 'claudpress'),
                ];
                foreach ($tg_buttons as $tg_act => $tg_label) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" <?php if ($tg_act === 'telegram_clear_chats') : ?>onsubmit="return confirm('<?php echo esc_js(__('Wipe every stored Telegram conversation?', 'claudpress')); ?>');"<?php endif; ?>>
                        <?php wp_nonce_field('claudpress_save'); ?>
                        <input type="hidden" name="action" value="claudpress_save">
                        <input type="hidden" name="claudpress_action" value="<?php echo esc_attr($tg_act); ?>">
                        <button class="button <?php echo $tg_act === 'telegram_connect' ? 'button-primary' : ''; ?>"><?php echo esc_html($tg_label); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <p style="color:#888;margin-top:10px;">
                <?php esc_html_e('Telegram only delivers to a publicly reachable HTTPS URL with a valid certificate — a localhost or self-signed staging site cannot receive messages.', 'claudpress'); ?>
            </p>

            <details style="margin-top:14px;">
                <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('Commands the bot understands', 'claudpress'); ?></summary>
                <table class="widefat striped" style="max-width:720px;margin-top:10px;">
                    <tbody>
                    <?php foreach (Claudpress_Telegram::commands() as $tg_cmd => $tg_desc) : ?>
                        <tr>
                            <td style="width:120px;"><code>/<?php echo esc_html($tg_cmd); ?></code></td>
                            <td><?php echo esc_html($tg_desc); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:#666;">
                    <?php esc_html_e('Anything that is not a command goes to the agent as plain instruction. /status and /usage are answered by the plugin itself, so they cost nothing.', 'claudpress'); ?>
                </p>
            </details>
        </div>
        <?php
    }

    /**
     * The event-notification card: the site messaging the owner unprompted.
     *
     * Every event is individually switchable and orders have a value floor,
     * because a notifier that cries wolf gets muted — and then the one message
     * that mattered is missed with the rest.
     */
    private function render_events_card(array $s): void
    {
        $has_wc = class_exists('WooCommerce');
        ?>
        <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;" id="events">
            <h2 style="margin-top:0;">
                <?php esc_html_e('Let the site tell you first', 'claudpress'); ?>
                <?php if (!empty($s['events_enabled'])) : ?>
                    <span style="color:#16a34a;"><?php esc_html_e('● on', 'claudpress'); ?></span>
                <?php else : ?>
                    <span style="color:#888;"><?php esc_html_e('○ off', 'claudpress'); ?></span>
                <?php endif; ?>
            </h2>
            <p style="max-width:720px;">
                <?php esc_html_e('Everything else here is you asking and the site answering. This is the other way round: an order lands, stock runs out, a live snippet crashes — and you hear about it on whichever notification channels you configured above (email, webhook, Telegram).', 'claudpress'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('claudpress_save'); ?>
                <input type="hidden" name="action" value="claudpress_save">
                <input type="hidden" name="claudpress_action" value="events_settings">

                <p>
                    <label style="font-size:15px;">
                        <input type="checkbox" name="events_enabled" value="1" <?php checked(!empty($s['events_enabled'])); ?>>
                        <strong><?php esc_html_e('Send me event notifications', 'claudpress'); ?></strong>
                    </label>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="events_order" value="1" <?php checked(!empty($s['events_order'])); ?> <?php disabled(!$has_wc); ?>>
                        <?php esc_html_e('Paid orders', 'claudpress'); ?>
                    </label>
                    <label style="margin-left:14px;"><?php esc_html_e('only at or above:', 'claudpress'); ?>
                        <input type="number" step="0.01" min="0" name="events_order_min" value="<?php echo esc_attr($s['events_order_min'] ?? 0); ?>" style="max-width:110px;">
                    </label>
                    <span style="color:#888;margin-left:8px;"><?php esc_html_e('0 = every order. Each order is announced once per status change, never twice.', 'claudpress'); ?></span>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="events_stock" value="1" <?php checked(!empty($s['events_stock'])); ?> <?php disabled(!$has_wc); ?>>
                        <?php esc_html_e('Low stock and sold out', 'claudpress'); ?>
                    </label>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="events_snippet" value="1" <?php checked(!empty($s['events_snippet'])); ?>>
                        <?php esc_html_e('A live code snippet crashed and was auto-disabled', 'claudpress'); ?>
                    </label>
                    <br><span style="color:#888;"><?php esc_html_e('Strongly recommended. The site stays up because the snippet switched itself off — but whatever it was doing has stopped, and only you can judge whether that matters.', 'claudpress'); ?></span>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="events_updates" value="1" <?php checked(!empty($s['events_updates'])); ?>>
                        <?php esc_html_e('New plugin, theme or core updates are available', 'claudpress'); ?>
                    </label>
                    <br><span style="color:#888;"><?php esc_html_e('Checked on the hourly cron tick, and only when the set of pending updates actually changes — so holding one back deliberately does not nag you forever.', 'claudpress'); ?></span>
                </p>

                <?php if (!$has_wc) : ?>
                    <p style="color:#888;"><?php esc_html_e('Order and stock alerts need WooCommerce, which is not active on this site.', 'claudpress'); ?></p>
                <?php endif; ?>

                <p><button class="button button-primary"><?php esc_html_e('Save notification events', 'claudpress'); ?></button></p>
            </form>
        </div>
        <?php
    }

    public function render_guide(): void
    {
        // Map tool name → description straight from the live registry so this
        // guide always documents exactly what's installed.
        $desc = [];
        foreach (Claudpress::instance()->registry->list() as $t) {
            $desc[$t['name']] = $t['description'];
        }
        $short = static function (string $s): string {
            $s = preg_replace('/\s+/', ' ', $s);
            return mb_strlen($s) > 200 ? mb_substr($s, 0, 200) . '…' : $s;
        };
        $enabled = Claudpress::instance()->abilities_enabled();
        // Style value only, not a whole attribute — so it can be escaped at output.
        $h = 'margin:28px 0 10px;';
        ?>
        <div class="wrap" style="max-width:1000px;">
            <h1><?php esc_html_e('Claudpress — Master Guide', 'claudpress'); ?></h1>
            <p style="font-size:15px;max-width:800px;"><?php esc_html_e('Everything this plugin lets your AI do, in plain English. Read this once and you\'ll know what to turn on, what to leave off, and how to get the best out of it — no coding required.', 'claudpress'); ?></p>

            <div class="notice notice-warning" style="max-width:800px;"><p><strong><?php esc_html_e('Golden rule:', 'claudpress'); ?></strong> <?php esc_html_e('use this on a development or staging site with backups. When AI abilities are ON, a connected agent can change anything. Don\'t point it at a live store you can\'t afford to have edited without review.', 'claudpress'); ?></p></div>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('What is Claudpress?', 'claudpress'); ?></h2>
            <p><?php esc_html_e('Claudpress turns your WordPress site into something an AI assistant (Claude, ChatGPT/Codex, Gemini, Cursor…) can operate directly. Instead of you clicking around the dashboard, you chat with your AI — "add this product", "write a blog post", "how are sales?" — and it does the work on your site through a set of tools this plugin provides. It speaks a standard called MCP (Model Context Protocol), so it works with most AI apps, not just one.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Seeing what the AI installed', 'claudpress'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: link to the Snippets page */
                    esc_html__('When your AI writes code onto this site, every piece of it is listed on the %s page — what it is, why it was written, what it changes, and a switch to turn it off. That page is the first place to look if the site starts behaving oddly.', 'claudpress'),
                    '<a href="' . esc_url(admin_url('admin.php?page=claudpress-snippets')) . '">' . esc_html__('Snippets', 'claudpress') . '</a>'
                );
                ?>
            </p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Get started in 3 steps', 'claudpress'); ?></h2>
            <ol style="font-size:14px;line-height:1.9;max-width:800px;">
                <li>
                    <strong><?php esc_html_e('Turn on AI abilities', 'claudpress'); ?></strong>
                    <?php
                    printf(
                        /* translators: %s: link to the Connect page */
                        esc_html__('on the %s page (master switch). Currently:', 'claudpress'),
                        '<a href="' . esc_url(admin_url('admin.php?page=claudpress')) . '">' . esc_html__('Connect', 'claudpress') . '</a>'
                    );
                    ?>
                    <strong style="color:<?php echo $enabled ? '#16a34a' : '#b91c1c'; ?>;"><?php echo $enabled ? esc_html__('ON', 'claudpress') : esc_html__('OFF', 'claudpress'); ?></strong>.
                </li>
                <li><strong><?php esc_html_e('Connect your AI', 'claudpress'); ?></strong> — <?php esc_html_e('copy the ready-made command on the Connect page into your AI app (e.g. Claude Code). It links your AI to this site securely with a token.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Start chatting.', 'claudpress'); ?></strong> <?php esc_html_e('Try: "List the plugins on this site" or "Show me this month\'s sales." If it answers, you\'re set.', 'claudpress'); ?></li>
            </ol>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Your two safety controls', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('1. The master switch (Connect page) turns all abilities on or off in one click. 2. Capability toggles (Connect page, lower down) let you allow some areas and forbid others — so you can let the AI write blog posts but never touch payments, for example. A forbidden area is hidden from the AI entirely and refused if it tries.', 'claudpress'); ?></p>

            <h3 style="margin-top:18px;"><?php esc_html_e('Recommended starting point for newbies', 'claudpress'); ?></h3>
            <p><?php esc_html_e('When you\'re still learning to trust it, keep the safe creative areas on and the sensitive ones off:', 'claudpress'); ?></p>
            <table class="widefat striped" style="max-width:800px;">
                <thead><tr>
                    <th><?php esc_html_e('Leave ON (low risk)', 'claudpress'); ?></th>
                    <th><?php esc_html_e('Turn OFF until you trust it (sensitive)', 'claudpress'); ?></th>
                </tr></thead>
                <tbody><tr>
                    <td style="vertical-align:top;"><?php esc_html_e('Content · SEO · Design/Elementor · Images · Memory · Skills', 'claudpress'); ?></td>
                    <td style="vertical-align:top;"><?php esc_html_e('Payments (money) · Store & orders · Shipping · Tax · Code execution · Filesystem · Users · Site admin · Updates · Autonomy · Deploy · Email', 'claudpress'); ?></td>
                </tr></tbody>
            </table>
            <p style="color:#b45309;max-width:800px;"><strong><?php esc_html_e('Important:', 'claudpress'); ?></strong> <?php esc_html_e('"Code execution" is the master key — with raw PHP the AI can reach any area. To truly wall off something sensitive, turn Code execution OFF too.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('What the AI can do — every area & tool', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Each area below is one capability toggle. When it\'s ON, the AI can use the listed tools.', 'claudpress'); ?></p>
            <?php foreach (Claudpress_Capabilities::groups() as $key => $g) :
                $on = Claudpress_Capabilities::is_group_enabled($key); ?>
                <div class="card" style="max-width:none;padding:12px 18px;margin:10px 0;">
                    <h3 style="margin:.2em 0;">
                        <?php echo esc_html($g['label']); ?>
                        <?php if (!empty($g['sensitive'])) : ?>
                            <span style="color:#b91c1c;font-size:12px;"><?php esc_html_e('● sensitive', 'claudpress'); ?></span>
                        <?php endif; ?>
                        <span style="float:right;font-size:12px;color:<?php echo $on ? '#16a34a' : '#888'; ?>;"><?php echo $on ? esc_html__('ENABLED', 'claudpress') : esc_html__('OFF', 'claudpress'); ?></span>
                    </h3>
                    <p style="color:#555;margin:.2em 0 .6em;"><?php echo esc_html($g['desc']); ?></p>
                    <table class="widefat" style="font-size:12px;"><tbody>
                        <?php foreach ($g['tools'] as $tool) : ?>
                            <tr><td style="width:180px;"><code><?php echo esc_html($tool); ?></code></td><td><?php echo esc_html(isset($desc[$tool]) ? $short($desc[$tool]) : '—'); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table>
                </div>
            <?php endforeach; ?>

            <h3 style="margin-top:18px;"><?php esc_html_e('Always available (read-only)', 'claudpress'); ?></h3>
            <p style="max-width:800px;"><?php esc_html_e('These never turn off, so you can always ask what\'s going on: get_site_info, get_dashboard, memory_list, skill_list, task_list.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Run the shop on autopilot (Autonomy)', 'claudpress'); ?></h2>
            <p style="max-width:800px;">
                <?php
                printf(
                    /* translators: %s: link to the Automation tab */
                    esc_html__('On the %s tab you can:', 'claudpress'),
                    '<a href="' . esc_url(admin_url('admin.php?page=claudpress-automation')) . '">' . esc_html__('Automation', 'claudpress') . '</a>'
                );
                ?>
            </p>
            <ul style="font-size:14px;line-height:1.8;max-width:800px;">
                <li><strong><?php esc_html_e('Ask "what\'s going on"', 'claudpress'); ?></strong> <?php esc_html_e('anytime — sales, orders needing attention, low stock, updates — via the live dashboard (no login needed once your AI is connected).', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Schedule a daily digest', 'claudpress'); ?></strong> <?php esc_html_e('emailed/Telegram\'d to you — no API key required.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Let it act on a schedule', 'claudpress'); ?></strong> — <?php esc_html_e('e.g. "every morning, fulfil paid orders and publish due drafts." This needs an API key from Anthropic, OpenAI or Google (you pick the provider and add the key there) because the site runs its own AI to do the work. Guardrails cap refunds and require you to opt in before it does anything destructive.', 'claudpress'); ?></li>
            </ul>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Run your site from your phone (Telegram)', 'claudpress'); ?></h2>
            <p style="max-width:800px;">
                <?php esc_html_e('You don\'t need Claude Code, a terminal or a laptop to use any of this. Connect a Telegram bot and you can just message your site: "how did the store do this week?", "publish the draft about winter tyres", "the checkout footer is broken, fix it." The site\'s own AI key does the work and reports back.', 'claudpress'); ?>
            </p>
            <ul style="font-size:14px;line-height:1.8;max-width:800px;">
                <li><strong><?php esc_html_e('Set it up in two minutes', 'claudpress'); ?></strong> — <?php esc_html_e('message @BotFather on Telegram, send /newbot, paste the token into Automation → Notifications, then tick "Accept messages from Telegram" and press Connect.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('It remembers the conversation', 'claudpress'); ?></strong> <?php esc_html_e('so follow-ups work. Send /clear to make it forget and start fresh.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Talk or show, not just type', 'claudpress'); ?></strong> — <?php esc_html_e('send a voice note and it transcribes what you said (showing you the transcript first), or send a screenshot of something broken and it looks at it.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('It shows its work', 'claudpress'); ?></strong> — <?php esc_html_e('one message updates as it goes, so you can see which tools it is using instead of waiting in silence.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Changed its mind? /undo', 'claudpress'); ?></strong> — <?php esc_html_e('puts back the posts, pages and products it changed on its last turn. It does not cover settings, plugins, files or code snippets, and it tells you so.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Free commands', 'claudpress'); ?></strong> — <?php esc_html_e('/status, /usage, /tasks and /model are answered by the plugin itself and cost nothing.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Only you can drive it', 'claudpress'); ?></strong> — <?php esc_html_e('only chat IDs you allowlist get answered, each with a role (admin, editor or read-only viewer), and destructive or financial actions stay locked until you approve them with a button.', 'claudpress'); ?></li>
            </ul>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Let the site tell you first', 'claudpress'); ?></h2>
            <p style="max-width:800px;">
                <?php esc_html_e('Turn on event notifications and the site messages you when something happens: a paid order (above a value you choose), stock running low or selling out, a piece of live code that crashed and switched itself off, or new updates waiting. Every event is individually switchable, and nothing is announced twice.', 'claudpress'); ?>
            </p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Build websites & content', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Ask for a page, homepage, or blog post and the AI follows a built-in designer\'s playbook (modern layout, responsive on phone/tablet/desktop, real images via free Openverse search). It can build pixel-perfect custom pages or editable Gutenberg/Elementor pages — just say which you prefer.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Go live: staging → production', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Build safely on a staging site, then ship to your live site in one step with deploy_push (set the production URL + token in Connect settings), or export a bundle and import it on the live site. Re-deploying updates instead of duplicating.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Skills & memory (why it gets smarter)', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Memory = facts it remembers between chats ("our brand color is #f8ca50"). Skills = playbooks it follows automatically (this plugin ships expert skills for WooCommerce, SEO, blogging, design, and deploys). Both are shared by every AI you connect, so your whole team\'s AI stays consistent.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Copy-paste starter prompts', 'claudpress'); ?></h2>
            <pre style="white-space:pre-wrap;background:#f6f7f7;padding:14px;border-radius:8px;max-width:800px;font-size:12px;"><?php
            echo esc_html(implode("\n", [
                __('• "Give me a full status report on the store and site."', 'claudpress'),
                __('• "Write and publish a 900-word blog post about <topic>, add a hero image, and optimize its SEO."', 'claudpress'),
                __('• "Create a product called <name> at <price>, add an image, and put it in the <category> category."', 'claudpress'),
                __('• "Set up Cash on Delivery and a flat-rate shipping zone for the UAE at 15 AED."', 'claudpress'),
                __('• "Build a modern, responsive landing page for <offer> and show me the link."', 'claudpress'),
                __('• "Set up a daily digest email of sales and low-stock items."', 'claudpress'),
            ]));
            ?></pre>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('FAQ', 'claudpress'); ?></h2>
            <p style="max-width:800px;">
                <strong><?php esc_html_e('Is it safe on my live store?', 'claudpress'); ?></strong> <?php esc_html_e('Install it anywhere, but only turn abilities ON where you\'re comfortable with changes. Best practice: build on staging, deploy to production.', 'claudpress'); ?><br>
                <strong><?php esc_html_e('What if the AI breaks something?', 'claudpress'); ?></strong> <?php esc_html_e('Code snippets that error auto-disable themselves; you have an Activity log; and destructive actions can be toggled off entirely.', 'claudpress'); ?><br>
                <strong><?php esc_html_e('Does it cost money?', 'claudpress'); ?></strong> <?php esc_html_e('The plugin is free. Your AI app\'s usage and (optionally) the autonomy API key are billed by those providers.', 'claudpress'); ?><br>
                <strong><?php esc_html_e('Does it work with ChatGPT/Codex/Gemini?', 'claudpress'); ?></strong> <?php esc_html_e('Yes — any MCP-compatible AI app.', 'claudpress'); ?><br>
                <strong><?php esc_html_e('Which switches should I leave off?', 'claudpress'); ?></strong> <?php esc_html_e('See the recommended table above; when unsure, off is safer — you can always turn it on later.', 'claudpress'); ?>
            </p>

            <p style="margin-top:24px;"><a href="<?php echo esc_url(admin_url('admin.php?page=claudpress')); ?>" class="button button-primary"><?php esc_html_e('← Back to Connect', 'claudpress'); ?></a></p>
        </div>
        <?php
    }

    public function render_activity(): void
    {
        $log = Claudpress::instance()->logger->recent(100);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Claudpress · Activity', 'claudpress'); ?></h1>
            <h2><?php esc_html_e('Memory', 'claudpress'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Wipe all memory?', 'claudpress')); ?>');" style="margin-bottom:8px;">
                <?php wp_nonce_field('claudpress_save'); ?>
                <input type="hidden" name="action" value="claudpress_save">
                <input type="hidden" name="claudpress_action" value="clear_memory">
                <button class="button"><?php esc_html_e('Clear all memory', 'claudpress'); ?></button>
            </form>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('Key', 'claudpress'); ?></th>
                <th><?php esc_html_e('Category', 'claudpress'); ?></th>
                <th><?php esc_html_e('Value', 'claudpress'); ?></th>
                <th><?php esc_html_e('Updated', 'claudpress'); ?></th>
            </tr></thead><tbody>
                <?php foreach (Claudpress::instance()->memory->all() as $m) : ?>
                    <tr><td><code><?php echo esc_html($m['key']); ?></code></td><td><?php echo esc_html($m['category']); ?></td><td><?php echo esc_html(wp_trim_words($m['value'], 30)); ?></td><td><?php echo esc_html($m['updated_at']); ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty(Claudpress::instance()->memory->all())) : ?><tr><td colspan="4"><em><?php esc_html_e('No memory yet.', 'claudpress'); ?></em></td></tr><?php endif; ?>
            </tbody></table>

            <h2 style="margin-top:32px;"><?php esc_html_e('Recent tool calls', 'claudpress'); ?></h2>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('When', 'claudpress'); ?></th>
                <th><?php esc_html_e('Tool', 'claudpress'); ?></th>
                <th><?php esc_html_e('OK', 'claudpress'); ?></th>
                <th><?php esc_html_e('Args', 'claudpress'); ?></th>
                <th><?php esc_html_e('Note', 'claudpress'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($log as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['created_at']); ?></td>
                        <td><code><?php echo esc_html($row['tool']); ?></code></td>
                        <td><?php echo $row['ok'] ? '✓' : '✗'; ?></td>
                        <td style="max-width:360px;overflow:hidden;"><code style="font-size:11px;"><?php echo esc_html(wp_trim_words((string) $row['args_json'], 30)); ?></code></td>
                        <td><?php echo esc_html($row['summary']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($log)) : ?><tr><td colspan="5"><em><?php esc_html_e('No activity logged yet.', 'claudpress'); ?></em></td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php
    }
}
