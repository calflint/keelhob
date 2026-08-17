<?php
/**
 * Skills engine. Markdown playbooks (YAML frontmatter + body) the agent loads on
 * demand when a task matches the description. Backed by a custom table so skills
 * live inside WordPress and are shared by every connected agent.
 *
 * This replicates (and extends, for free) Novamira's Skills + the Pro idea of
 * bundled expert "specializations" — see seed_bundled_skills().
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Skills
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_skills';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            description TEXT NOT NULL,
            body LONGTEXT NOT NULL,
            enable_agentic TINYINT(1) NOT NULL DEFAULT 1,
            enable_prompt TINYINT(1) NOT NULL DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_bundled TINYINT(1) NOT NULL DEFAULT 0,
            bundled_hash VARCHAR(32) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) {$charset};");
    }

    /**
     * Save (upsert) a skill. Accepts either a raw markdown document (frontmatter
     * + body) via $markdown, or explicit fields.
     */
    public function save(array $args): array
    {
        global $wpdb;

        if (!empty($args['markdown'])) {
            $parsed = self::parse_markdown((string) $args['markdown']);
            $args   = array_merge($parsed, array_diff_key($args, ['markdown' => 1]));
        }

        $name = sanitize_title((string) ($args['name'] ?? ''));
        if ($name === '' && !empty($args['description'])) {
            $name = sanitize_title(wp_trim_words((string) $args['description'], 5, ''));
        }
        if ($name === '') {
            return ['success' => false, 'error' => 'Skill needs a name or description.'];
        }

        $row = [
            'name'           => $name,
            'description'    => (string) ($args['description'] ?? ''),
            'body'           => (string) ($args['body'] ?? ''),
            'enable_agentic' => array_key_exists('enable_agentic', $args) ? (int) (bool) $args['enable_agentic'] : 1,
            'enable_prompt'  => array_key_exists('enable_prompt', $args) ? (int) (bool) $args['enable_prompt'] : 1,
            'enabled'        => array_key_exists('enabled', $args) ? (int) (bool) $args['enabled'] : 1,
            'is_bundled'     => (int) (bool) ($args['is_bundled'] ?? 0),
            'updated_at'     => current_time('mysql'),
        ];
        if (array_key_exists('bundled_hash', $args)) {
            $row['bundled_hash'] = (string) $args['bundled_hash'];
        }

        $exists = $this->get_by_name($name);
        if ($exists) {
            $wpdb->update(self::table(), $row, ['name' => $name]);
        } else {
            $wpdb->insert(self::table(), $row);
        }
        return ['success' => true, 'name' => $name];
    }

    public function get_by_name(string $name): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE name = %s",
            sanitize_title($name)
        ), ARRAY_A);
        return $row ?: null;
    }

    public function delete(string $name): array
    {
        global $wpdb;
        $deleted = $wpdb->delete(self::table(), ['name' => sanitize_title($name)]);
        return ['success' => (bool) $deleted, 'name' => $name];
    }

    /** @return array<int, array> */
    public function all(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY name", ARRAY_A);
        return $rows ?: [];
    }

    /** Skills exposed to the agent's automatic catalog (description only). */
    public function agentic_catalog(): array
    {
        $out = [];
        foreach ($this->all() as $s) {
            if ((int) $s['enabled'] === 1 && (int) $s['enable_agentic'] === 1) {
                $out[] = ['name' => $s['name'], 'description' => $s['description']];
            }
        }
        return $out;
    }

    /** Skills exposed as MCP prompts. */
    public function prompt_catalog(): array
    {
        $out = [];
        foreach ($this->all() as $s) {
            if ((int) $s['enabled'] === 1 && (int) $s['enable_prompt'] === 1) {
                $out[] = ['name' => $s['name'], 'description' => $s['description']];
            }
        }
        return $out;
    }

    /**
     * Parse a skill markdown document into fields.
     * @return array{name?:string,description?:string,body:string,enable_agentic?:bool,enable_prompt?:bool}
     */
    public static function parse_markdown(string $doc): array
    {
        $doc = str_replace(["\r\n", "\r"], "\n", $doc);
        $fields = ['body' => $doc];

        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $doc, $m)) {
            $yaml = $m[1];
            $fields['body'] = ltrim($m[2], "\n");
            foreach (explode("\n", $yaml) as $line) {
                if (!preg_match('/^([a-zA-Z_]+)\s*:\s*(.*)$/', trim($line), $kv)) {
                    continue;
                }
                $key = strtolower($kv[1]);
                $val = trim($kv[2], " \"'");
                switch ($key) {
                    case 'name':
                        $fields['name'] = $val;
                        break;
                    case 'description':
                        $fields['description'] = $val;
                        break;
                    case 'enable_agentic':
                        $fields['enable_agentic'] = in_array(strtolower($val), ['1', 'true', 'yes'], true);
                        break;
                    case 'enable_prompt':
                        $fields['enable_prompt'] = in_array(strtolower($val), ['1', 'true', 'yes'], true);
                        break;
                }
            }
        }
        return $fields;
    }

    /**
     * Ship a set of expert skills out of the box — this is the "Pro
     * specializations" idea, unlocked and free. Only seeds on activation and
     * only when a same-named skill does not already exist (so user edits win).
     */
    /**
     * Install the skills that ship with the plugin, and refresh the ones the
     * owner has not touched.
     *
     * The original rule was "never overwrite an existing skill", which is right
     * for a skill somebody edited and wrong for every other case: an improved
     * playbook shipped in a plugin update would never reach a site that had
     * been running since the previous release, so the bundled skills silently
     * froze at whatever version was installed first.
     *
     * `bundled_hash` is what tells the two apart. It records the body Claudpress
     * last wrote. If the stored body still hashes to it, nobody has edited the
     * skill and it is safe to replace with the newer text. If it differs, the
     * owner (or an agent) rewrote it, and their version is left alone.
     *
     * @param bool $force Replace bundled skills even when they look edited.
     *                    Only ever set from an explicit owner-driven refresh.
     * @return array{installed: string[], updated: string[], kept: string[]}
     */
    public static function seed_bundled_skills(bool $force = false): array
    {
        $report = ['installed' => [], 'updated' => [], 'kept' => []];
        $skills = self::instance_free();
        $dir    = CLAUDPRESS_DIR . 'skills';
        if (!is_dir($dir)) {
            return $report;
        }
        foreach (glob($dir . '/*.md') as $file) {
            $doc    = file_get_contents($file);
            $parsed = self::parse_markdown($doc);
            $name   = sanitize_title($parsed['name'] ?? basename($file, '.md'));
            if ($name === '') {
                continue;
            }

            $parsed['is_bundled']   = 1;
            $parsed['name']         = $name;
            $parsed['bundled_hash'] = md5((string) ($parsed['body'] ?? ''));

            $existing = $skills->get_by_name($name);
            switch (self::bundled_action($existing, $parsed['bundled_hash'], $force)) {
                case 'install':
                    $skills->save($parsed);
                    $report['installed'][] = $name;
                    break;

                case 'update':
                    // Preserve the owner's on/off choices; only the text changes.
                    $parsed['enabled']        = (int) $existing['enabled'];
                    $parsed['enable_agentic'] = (int) $existing['enable_agentic'];
                    $parsed['enable_prompt']  = (int) $existing['enable_prompt'];
                    $skills->save($parsed);
                    $report['updated'][] = $name;
                    break;

                case 'keep':
                    // Stamp the hash from the body that is on the site right now,
                    // so the *next* update can tell edited from untouched and this
                    // one-time ambiguity does not repeat forever.
                    if ((string) ($existing['bundled_hash'] ?? '') === '') {
                        $GLOBALS['wpdb']->update(
                            self::table(),
                            ['bundled_hash' => md5((string) $existing['body'])],
                            ['name' => $name]
                        );
                    }
                    $report['kept'][] = $name;
                    break;

                // 'current' — already the shipped text, nothing to do.
            }
        }
        return $report;
    }

    /**
     * Decide what to do with one bundled skill. Pure, so the rules are testable.
     *
     * @param array<string, mixed>|null $existing     the stored row, if any
     * @param string                    $shipped_hash md5 of the body being shipped
     * @return string install | update | keep | current
     */
    public static function bundled_action(?array $existing, string $shipped_hash, bool $force = false): string
    {
        if (!$existing) {
            return 'install';
        }
        $stored_hash = (string) ($existing['bundled_hash'] ?? '');
        $untouched   = $stored_hash !== '' && $stored_hash === md5((string) ($existing['body'] ?? ''));

        if (!$untouched) {
            return $force ? 'update' : 'keep';
        }
        return $stored_hash === $shipped_hash ? 'current' : 'update';
    }

    /** Static helper so activation (no booted singleton) can use the store. */
    private static function instance_free(): Claudpress_Skills
    {
        return new self();
    }
}
