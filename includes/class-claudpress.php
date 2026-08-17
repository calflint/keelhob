<?php
/**
 * Core plugin orchestrator. Holds settings and wires subsystems together.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress
{
    const OPTION_SETTINGS = 'claudpress_settings';

    /** @var Claudpress|null */
    private static $instance = null;

    /** @var Claudpress_Tool_Registry */
    public $registry;

    /** @var Claudpress_Memory */
    public $memory;

    /** @var Claudpress_Skills */
    public $skills;

    /** @var Claudpress_Logger */
    public $logger;

    public static function instance(): Claudpress
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * The name an agent uses for this site when it is connected to several.
     *
     * Resolved at read time, never frozen into the option: if the owner renames
     * the site, the handle follows. Falls back site name → host.
     */
    public static function site_label(): string
    {
        $set = trim((string) self::instance()->get('site_label', ''));
        if ($set !== '') {
            return $set;
        }
        $name = trim((string) get_bloginfo('name'));
        if ($name !== '') {
            return $name;
        }
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /**
     * Default settings. `abilities_enabled` is the master safety switch — when
     * false, the MCP endpoint authenticates but exposes no destructive tools.
     */
    public static function default_settings(): array
    {
        return [
            'abilities_enabled' => false,
            'token'             => '',
            'allow_app_passwords' => true,
            // Filesystem base: operations are confined at or below this path.
            'fs_base'           => ABSPATH,
            'require_https'     => false,
            'log_calls'         => true,
            // Snippet engine kill-switch (active snippets run for real visitors).
            'run_snippets'      => true,
            // Short handle an agent uses to tell this site apart from the other
            // sites it is connected to ("copy that snippet from Pranknologia").
            // Blank = derive it from the site name at read time — see site_label().
            'site_label'        => '',
            // Which page builder the site uses; loads matching guidance/skill.
            'builder'           => 'auto', // auto | gutenberg | elementor_free | elementor_pro | bricks | custom_php
            // Optional AI image generation. Blank key = use free image_search.
            'image_provider'    => 'openai',
            'image_api_key'     => '',
            'image_api_endpoint' => '', // blank = the provider's default endpoint
            'image_api_model'   => '',  // blank = the provider's default model
            // Autonomous agent runtime. Blank key = autonomy off.
            // See Claudpress_Provider for the supported vendors.
            'agent_provider'     => 'anthropic',
            'agent_api_key'      => '',
            'agent_api_endpoint' => '', // blank = the provider's default endpoint
            'agent_model'        => '', // blank = the provider's default model
            // Autonomy budget caps (0 = unlimited). Estimated from token usage.
            'agent_max_usd_per_run'    => 0.50,
            'agent_max_tokens_per_run' => 300000,
            'agent_max_usd_per_day'    => 5.00,
            'agent_max_runs_per_day'   => 48,
            // Notifications.
            'notify_email'          => '',
            'notify_webhook_url'    => '',
            'notify_telegram_token' => '',
            'notify_telegram_chat'  => '',
            // Telegram chat front-end (inbound). Off until the owner connects a
            // bot — the same token above is reused, this only opens the return
            // direction. See Claudpress_Telegram.
            'telegram_bot_enabled'   => false,
            'telegram_secret'        => '',  // webhook shared secret, auto-generated
            'telegram_allowed_chats' => '',  // blank = fall back to notify_telegram_chat
            'telegram_history_turns' => 12,
            'telegram_allow_risky'   => false,
            'telegram_voice'         => false, // transcribe voice notes
            'telegram_photos'        => false, // accept photos as vision input
            'telegram_progress'      => true,  // edit one message as the run works
            // Speech-to-text for voice notes. Anthropic has no audio endpoint,
            // so this is its own provider choice; a blank key falls back to any
            // other key already configured for the same vendor.
            'voice_provider'     => 'openai',
            'voice_api_key'      => '',
            'voice_api_endpoint' => '',
            'voice_model'        => '',
            // Event notifications — the site messaging the owner first.
            'events_enabled'   => false,
            'events_order'     => true,
            'events_order_min' => 0,      // only orders at/above this total
            'events_stock'     => true,
            'events_snippet'   => true,
            'events_updates'   => false,
            // Policy / guardrails.
            'policy' => ['enabled' => true, 'blocked_tools' => [], 'max_refund' => 0, 'require_optin' => true],
            // Staging → production deploy target.
            'deploy_target_url'   => '',
            'deploy_target_token' => '',
            // Per-capability toggles (empty = every capability enabled). See Claudpress_Capabilities.
            'capabilities' => [],
        ];
    }

    public function settings(): array
    {
        $saved = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return array_merge(self::default_settings(), $saved);
    }

    public function get(string $key, $default = null)
    {
        $s = $this->settings();
        return $s[$key] ?? $default;
    }

    public function update(array $patch): void
    {
        update_option(self::OPTION_SETTINGS, array_merge($this->settings(), $patch));
    }

    public function abilities_enabled(): bool
    {
        return (bool) $this->get('abilities_enabled', false);
    }

    public function boot(): void
    {
        $this->maybe_upgrade();

        $this->logger   = new Claudpress_Logger();
        $this->memory   = new Claudpress_Memory();
        $this->skills   = new Claudpress_Skills();
        $this->registry = new Claudpress_Tool_Registry();

        // Register every tool group. Each group decides internally whether it
        // is applicable (e.g. WooCommerce tools only register when WC is active).
        Claudpress_Tools_Core::register($this->registry);
        Claudpress_Tools_Filesystem::register($this->registry);
        Claudpress_Tools_Content::register($this->registry);
        Claudpress_Tools_Site::register($this->registry);
        Claudpress_Tools_WooCommerce::register($this->registry);
        Claudpress_Tools_SEO::register($this->registry);
        Claudpress_Tools_Memory::register($this->registry);
        Claudpress_Tools_Skills::register($this->registry);
        Claudpress_Tools_Snippets::register($this->registry);
        Claudpress_Tools_Elementor::register($this->registry);
        Claudpress_Tools_Ops::register($this->registry);
        Claudpress_Tools_Automation::register($this->registry);
        Claudpress_Tools_Payments::register($this->registry);
        Claudpress_Tools_Deploy::register($this->registry);

        do_action('claudpress_register_tools', $this->registry);

        // Code-snippets engine — active snippets run on every request.
        Claudpress_Snippets::init();

        // Scheduled automations (WP-Cron tick).
        Claudpress_Tasks::init();

        // Push notifications for things the owner should hear about unprompted.
        Claudpress_Events::init();

        // Front-end SEO injection (schema, redirects, fallback meta, robots.txt).
        Claudpress_Tools_SEO::hooks();

        // REST / MCP endpoint.
        (new Claudpress_Rest())->hooks();

        // Crash-recovery sandbox loader (runs AI-written persistent PHP safely).
        Claudpress_Sandbox::init();

        // Admin UI.
        if (is_admin()) {
            (new Claudpress_Admin())->hooks();
        }
    }

    /**
     * Runs on every boot but only does work when the stored DB version differs
     * from the plugin version — so updating the plugin (without reactivating)
     * still creates new tables and seeds new defaults.
     */
    private function maybe_upgrade(): void
    {
        $stored = get_option('claudpress_db_version', '');
        if ($stored === CLAUDPRESS_VERSION) {
            return;
        }
        Claudpress_Memory::install_table();
        Claudpress_Skills::install_table();
        Claudpress_Snippets::install_table();
        Claudpress_Tasks::install_table();
        Claudpress_Agent_Runs::install_table();
        Claudpress_Telegram::install_table();
        Claudpress_Logger::install_table();
        // After install_table() has added the columns: give pre-1.9 snippets a
        // uid and a created_at so the Snippets page and cross-site copy work.
        Claudpress_Snippets::backfill();
        Claudpress_Skills::seed_bundled_skills();
        self::seed_default_memory();
        if (!file_exists(CLAUDPRESS_SANDBOX_DIR)) {
            wp_mkdir_p(CLAUDPRESS_SANDBOX_DIR);
            @file_put_contents(CLAUDPRESS_SANDBOX_DIR . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents(CLAUDPRESS_SANDBOX_DIR . '/.htaccess', "Deny from all\n");
        }
        update_option('claudpress_db_version', CLAUDPRESS_VERSION);
    }

    public static function on_activate(): void
    {
        $existing = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        // Generate a token on first activation so the user has something to copy.
        if (empty($existing['token'])) {
            $existing['token'] = self::generate_token();
        }
        update_option(self::OPTION_SETTINGS, array_merge(self::default_settings(), $existing));

        if (!file_exists(CLAUDPRESS_SANDBOX_DIR)) {
            wp_mkdir_p(CLAUDPRESS_SANDBOX_DIR);
            // Harden: no direct web access, no directory listing.
            @file_put_contents(CLAUDPRESS_SANDBOX_DIR . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents(CLAUDPRESS_SANDBOX_DIR . '/.htaccess', "Deny from all\n");
        }

        Claudpress_Memory::install_table();
        Claudpress_Skills::install_table();
        Claudpress_Snippets::install_table();
        Claudpress_Tasks::install_table();
        Claudpress_Agent_Runs::install_table();
        Claudpress_Telegram::install_table();
        Claudpress_Logger::install_table();
        Claudpress_Snippets::backfill();
        Claudpress_Skills::seed_bundled_skills();
        self::seed_default_memory();
        update_option('claudpress_db_version', CLAUDPRESS_VERSION);
    }

    /**
     * Seed a small set of default memories so any connected agent starts with
     * the vital operating context for this plugin, even before the owner adds
     * their own. Only writes a key when it does not already exist.
     */
    public static function seed_default_memory(): void
    {
        $memory = new Claudpress_Memory();
        $defaults = [
            'claudpress.capabilities' => 'This site runs Claudpress: you can execute PHP/SQL, manage content, run the WooCommerce store, do SEO, edit files, write live code snippets (snippet_save), build Elementor pages (elementor_page), update plugins, email the owner (send_email), and add images (image_search / image_generate). Prefer dedicated tools; fall back to execute_php.',
            'build.page_modes'        => 'Two ways to build pages: (1) pixel-perfect custom-coded page = snippet_save with location=page_template + target_id, writing full responsive HTML/CSS; editable only via the agent. (2) editable page = Gutenberg blocks (create_post) or Elementor (elementor_page). Ask the owner which they prefer if unclear.',
            'build.elementor'         => 'For Elementor building: run elementor_settings action=prepare_for_ai first (enables Container/flexbox, disables the Atomic editor). Build layouts with flexbox Containers (elType "container"), never deprecated sections/columns. Xpro Theme Builder (free) makes custom headers/footers without Elementor Pro.',
            'content.images'          => 'Images: prefer image_search (Openverse, free, no key) to fetch a real licensed image and attach it; use image_generate only if an image API key is configured in Claudpress settings. Always set descriptive alt text.',
            'design.reference'        => 'For ANY visual/build work, follow the web-design-master skill (skill_get name=web-design-master): design tokens, mobile-first responsive, component patterns, WordPress "style with the theme not against it" rules, and the anti-slop quality audit. Prefer a CSS snippet or page_template over inline <style> (security plugins strip inline styles).',
            'safety.workflow'         => 'This should be a dev/staging site. Read before you write, confirm destructive/financial actions, verify after changing, and save durable decisions with memory_save. Snippets that fatal auto-deactivate.',
            'chat.telegram'           => 'The owner may be talking to you from Telegram (context "telegram") rather than an MCP client. That is a live chat on a phone: keep replies short and plain-text, ask at most one clarifying question, and remember they cannot see tool output — only what you write. Built-in commands (/status, /usage, /clear, /tasks, /undo) are answered by the plugin without you. A voice note arrives already transcribed; a photo arrives as an image you can see plus a media-library attachment ID you can use directly in a post or product. If a tool comes back policy_blocked, say which one — the owner gets an Approve button to allow it, so name it plainly instead of working around it. Content changes you make in a turn are undoable via /undo, but only content: settings, plugins, files and snippets are not, so be correspondingly careful with those.',
            'autonomy.usage'          => 'The site can run itself: get_dashboard answers "what is going on / how is the store doing" on demand; task_save creates scheduled digest or autonomous tasks; run_agent executes a one-off instruction now. Autonomous runs need an agent API key (Claudpress > Automation) and obey policy guardrails — pass allow_risky on a task only when destructive/financial actions are intended.',
        ];
        foreach ($defaults as $key => $value) {
            if (!$memory->get($key)) {
                $memory->save($key, $value, 'claudpress');
            }
        }
    }

    public static function on_deactivate(): void
    {
        // Safety: turn abilities off when the plugin is deactivated.
        $s = get_option(self::OPTION_SETTINGS, []);
        if (is_array($s)) {
            $s['abilities_enabled'] = false;
            update_option(self::OPTION_SETTINGS, $s);
        }
        // Stop the automation cron so nothing runs while deactivated.
        Claudpress_Tasks::unschedule();
    }

    public static function generate_token(): string
    {
        return 'cp_' . bin2hex(random_bytes(24));
    }
}
