<?php
/**
 * Automation & autonomy tools: an on-demand status dashboard, scheduled task
 * management, a manual autonomous-run trigger, and a notification sender.
 * These let the owner ask "what's going on?" and set the site to run itself.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Automation
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('get_dashboard', 'Get a full status snapshot of the site right now — store sales (today / 7d / 30d), open orders needing attention, out-of-stock products, content counts, pending comments, and available updates, plus a plain-language summary. Use this to answer "what\'s going on / how is the store doing / what needs attention" without the owner logging in. Read-only; works even when abilities are disabled.', [
            'type' => 'object', 'properties' => (object) [],
        ], [self::class, 'get_dashboard'], ['readOnlyHint' => true, 'title' => 'Get Dashboard']);

        $r->add('task_save', 'Create or update a scheduled automation. mode="digest" emails a status snapshot on a schedule (no API key needed). mode="autonomous" runs a plain-language instruction through the on-site AI agent on a schedule (requires an agent API key in settings) — e.g. "fulfil paid orders, restock flagged items, and publish any due draft posts". schedule: hourly|twicedaily|daily|weekly|monthly, with an optional run_hour (0-23, site time) to pin it to a time of day. Set allow_risky=true only if the task may perform destructive/financial actions (refunds, deletes, updates).', [
            'type' => 'object',
            'properties' => [
                'id'          => ['type' => 'integer'],
                'name'        => ['type' => 'string'],
                'mode'        => ['type' => 'string', 'enum' => ['digest', 'autonomous'], 'default' => 'digest'],
                'instruction' => ['type' => 'string', 'description' => 'For autonomous mode: what the agent should do each run.'],
                'schedule'    => ['type' => 'string', 'enum' => ['hourly', 'twicedaily', 'daily', 'weekly', 'monthly'], 'default' => 'daily'],
                'run_hour'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 23, 'description' => 'Preferred hour of day (0-23, site timezone) for daily/weekly/monthly tasks — e.g. 7 for a 7am digest. Omit to run whenever the interval elapses.'],
                'allow_risky' => ['type' => 'boolean', 'default' => false],
                'enabled'     => ['type' => 'boolean', 'default' => true],
                'notify'      => ['type' => 'boolean', 'default' => true],
            ],
            'required' => ['name', 'mode'],
        ], [self::class, 'task_save'], ['destructiveHint' => true]);

        $r->add('task_list', 'List all scheduled automations with mode, schedule, enabled state, and last run status/summary. Read-only.', [
            'type' => 'object', 'properties' => (object) [],
        ], [self::class, 'task_list'], ['readOnlyHint' => true]);

        $r->add('task_run', 'Run a scheduled task immediately (does not change its schedule). Returns the run result/summary.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ], [self::class, 'task_run'], ['destructiveHint' => true]);

        $r->add('task_delete', 'Delete a scheduled task by ID.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ], [self::class, 'task_delete'], ['destructiveHint' => true]);

        $r->add('run_agent', 'Run a one-off autonomous task NOW: hand a plain-language instruction to the on-site AI agent, which plans and executes it using Claudpress tools (with policy guardrails), then returns a summary. Requires an agent API key in settings. Use for "just handle X" automations you don\'t want to schedule.', [
            'type' => 'object',
            'properties' => [
                'instruction' => ['type' => 'string'],
                'allow_risky' => ['type' => 'boolean', 'default' => false],
                'max_steps'   => ['type' => 'integer', 'default' => 14],
            ],
            'required' => ['instruction'],
        ], [self::class, 'run_agent'], ['destructiveHint' => true]);

        $r->add('agent_runs', 'Review the history of autonomous runs: when each ran, what it was asked, how many steps and tool calls it took, how it ended (ok / capped / truncated / error), and its token usage and estimated USD cost. Also returns the trailing-24h spend against the configured budget caps. Use to answer "what has the site been doing on its own / what is autonomy costing me". Read-only.', [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'default' => 20, 'description' => 'How many recent runs to return (max 200).'],
            ],
        ], [self::class, 'agent_runs'], ['readOnlyHint' => true, 'title' => 'Agent Run History']);

        $r->add('notify_owner', 'Send a notification to the site owner across every configured channel (email, webhook/Slack, Telegram). Use to proactively flag something that needs a human.', [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string'],
                'message' => ['type' => 'string'],
            ],
            'required' => ['subject', 'message'],
        ], [self::class, 'notify_owner'], ['destructiveHint' => true]);
    }

    public static function get_dashboard(array $a): array
    {
        return ['success' => true, 'dashboard' => Claudpress_Digest::snapshot()];
    }

    public static function task_save(array $a): array
    {
        return Claudpress_Tasks::save($a);
    }

    public static function task_list(array $a): array
    {
        $tasks = array_map(fn($t) => [
            'id' => (int) $t['id'], 'name' => $t['name'], 'mode' => $t['mode'], 'schedule' => $t['schedule'],
            'run_hour' => isset($t['run_hour']) && $t['run_hour'] !== null ? (int) $t['run_hour'] : null,
            'enabled' => (bool) $t['enabled'], 'allow_risky' => (bool) $t['allow_risky'],
            'last_run' => $t['last_run'], 'last_status' => $t['last_status'],
            'last_summary' => mb_substr((string) $t['last_summary'], 0, 400),
        ], Claudpress_Tasks::all());
        return ['success' => true, 'tasks' => $tasks];
    }

    public static function task_run(array $a): array
    {
        return Claudpress_Tasks::run_task((int) ($a['id'] ?? 0));
    }

    public static function task_delete(array $a): array
    {
        return Claudpress_Tasks::delete((int) ($a['id'] ?? 0));
    }

    public static function run_agent(array $a): array
    {
        return Claudpress_Agent_Runtime::run((string) ($a['instruction'] ?? ''), [
            'allow_risky' => (bool) ($a['allow_risky'] ?? false),
            'max_steps'   => (int) ($a['max_steps'] ?? 14),
            'context'     => 'run_agent',
        ]);
    }

    public static function agent_runs(array $a): array
    {
        $runs = array_map(static fn($r) => [
            'id'          => (int) $r['id'],
            'started_at'  => $r['started_at'],
            'finished_at' => $r['finished_at'],
            'context'     => $r['context'],
            'task_id'     => $r['task_id'] ? (int) $r['task_id'] : null,
            'model'       => $r['model'],
            'status'      => $r['status'],
            'steps'       => (int) $r['steps'],
            'tool_calls'  => (int) $r['tool_calls'],
            'retries'     => (int) $r['retries'],
            'tokens'      => (int) $r['input_tokens'] + (int) $r['output_tokens'],
            'cost_usd'    => (float) $r['cost_usd'],
            'instruction' => mb_substr((string) $r['instruction'], 0, 300),
            'summary'     => mb_substr((string) $r['summary'], 0, 600),
        ], Claudpress_Agent_Runs::recent((int) ($a['limit'] ?? 20)));

        return [
            'success'   => true,
            'runs'      => $runs,
            'usage_24h' => Claudpress_Agent_Runs::usage_since(24),
            'caps'      => Claudpress_Agent_Runtime::caps(),
        ];
    }

    public static function notify_owner(array $a): array
    {
        $res = Claudpress_Notifier::send((string) ($a['subject'] ?? ''), (string) ($a['message'] ?? ''));
        return ['success' => true, 'channels' => $res];
    }
}
