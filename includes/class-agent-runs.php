<?php
/**
 * Agent run history. Every autonomous run (scheduled task or one-off run_agent)
 * writes a row here: what it was asked, how many steps and tool calls it took,
 * how many tokens it burned, the estimated USD cost, and how it ended.
 *
 * This exists for two reasons. The owner needs to see what the site did while
 * they weren't looking, and the daily spend caps need somewhere to read
 * yesterday's cost from — a cap you cannot measure is not a cap.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Agent_Runs
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_agent_runs';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            context VARCHAR(64) NOT NULL DEFAULT 'manual',
            task_id BIGINT UNSIGNED NULL,
            model VARCHAR(64) NULL,
            instruction LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            steps INT NOT NULL DEFAULT 0,
            tool_calls INT NOT NULL DEFAULT 0,
            retries INT NOT NULL DEFAULT 0,
            input_tokens BIGINT NOT NULL DEFAULT 0,
            output_tokens BIGINT NOT NULL DEFAULT 0,
            cost_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
            summary LONGTEXT NULL,
            detail LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY started_at (started_at),
            KEY status (status)
        ) {$charset};");
    }

    /** Open a run row and return its id (0 if the table is unavailable). */
    public static function start(string $instruction, string $context, string $model, ?int $task_id = null): int
    {
        global $wpdb;
        $ok = $wpdb->insert(self::table(), [
            'started_at'  => current_time('mysql'),
            'context'     => substr($context, 0, 64),
            'task_id'     => $task_id ?: null,
            'model'       => substr($model, 0, 64),
            'instruction' => mb_substr($instruction, 0, 8000),
            'status'      => 'running',
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** Close a run row with its outcome. */
    public static function finish(int $id, array $fields): void
    {
        if ($id <= 0) {
            return;
        }
        global $wpdb;
        $row = [
            'finished_at'   => current_time('mysql'),
            'status'        => substr((string) ($fields['status'] ?? 'ok'), 0, 20),
            'steps'         => (int) ($fields['steps'] ?? 0),
            'tool_calls'    => (int) ($fields['tool_calls'] ?? 0),
            'retries'       => (int) ($fields['retries'] ?? 0),
            'input_tokens'  => (int) ($fields['input_tokens'] ?? 0),
            'output_tokens' => (int) ($fields['output_tokens'] ?? 0),
            'cost_usd'      => round((float) ($fields['cost_usd'] ?? 0), 5),
            'summary'       => mb_substr((string) ($fields['summary'] ?? ''), 0, 8000),
            'detail'        => mb_substr((string) wp_json_encode($fields['detail'] ?? []), 0, 20000),
        ];
        $wpdb->update(self::table(), $row, ['id' => $id]);
    }

    /** @return array<int, array> most recent first */
    public static function recent(int $limit = 25): array
    {
        global $wpdb;
        $limit = max(1, min(200, $limit));
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
        return $rows ?: [];
    }

    /** Spend and run count in the trailing N hours — what the daily caps read. */
    public static function usage_since(int $hours = 24): array
    {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS runs, COALESCE(SUM(cost_usd),0) AS cost, COALESCE(SUM(input_tokens+output_tokens),0) AS tokens
             FROM " . self::table() . " WHERE started_at >= %s",
            $since
        ), ARRAY_A);
        return [
            'runs'   => (int) ($row['runs'] ?? 0),
            'cost'   => (float) ($row['cost'] ?? 0),
            'tokens' => (int) ($row['tokens'] ?? 0),
            'hours'  => $hours,
        ];
    }

    /** Trim old rows so the table cannot grow without bound. */
    public static function prune(int $keep_days = 90): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($keep_days * 86400));
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE started_at < %s",
            $cutoff
        ));
    }
}
