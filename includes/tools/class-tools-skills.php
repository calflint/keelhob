<?php
/**
 * Skills tools — author, list, read, and manage the Markdown playbooks the agent
 * loads on demand. Includes skill_get so the agent can pull a full skill body
 * when the session-start catalog description matches the current task.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Skills
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('skill_list', 'List all skills on this site (name, description, enabled/agentic/prompt flags). At session start you receive descriptions only; call skill_get to load a body when a task matches. Read-only.', [
            'type' => 'object', 'properties' => (object) [],
        ], [self::class, 'list'], ['readOnlyHint' => true]);

        $r->add('skill_get', 'Load the full body of a skill by name. Call this when a task matches a skill\'s description so you can follow its playbook.', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ], [self::class, 'get'], ['readOnlyHint' => true]);

        $r->add('skill_save', 'Create or update a skill. Provide either a full Markdown document (YAML frontmatter + body) as `markdown`, or explicit fields. Skills are shared site-wide and loaded automatically when their description matches a task.', [
            'type' => 'object',
            'properties' => [
                'markdown'       => ['type' => 'string', 'description' => 'Full skill document (--- frontmatter --- then body).'],
                'name'           => ['type' => 'string'],
                'description'    => ['type' => 'string', 'description' => 'The trigger blurb — write it so it clearly signals when to use the skill.'],
                'body'           => ['type' => 'string'],
                'enable_agentic' => ['type' => 'boolean', 'default' => true],
                'enable_prompt'  => ['type' => 'boolean', 'default' => true],
                'enabled'        => ['type' => 'boolean', 'default' => true],
            ],
        ], [self::class, 'save'], ['destructiveHint' => true]);

        $r->add('skill_refresh', 'Reinstall the expert skills that ship with Claudpress, so a plugin update\'s improved playbooks reach this site. Skills that were edited here are kept, and named in the response, unless force=true replaces them too. Reports what was installed, updated and kept.', [
            'type' => 'object',
            'properties' => [
                'force' => ['type' => 'boolean', 'default' => false, 'description' => 'Also overwrite bundled skills that look edited on this site. Destroys those edits — confirm with the owner first.'],
            ],
        ], [self::class, 'refresh'], ['destructiveHint' => true, 'title' => 'Refresh Bundled Skills']);

        $r->add('skill_delete', 'Delete a skill by name.', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ], [self::class, 'delete'], ['destructiveHint' => true]);
    }

    public static function list(array $a): array
    {
        $skills = array_map(fn($s) => [
            'name' => $s['name'], 'description' => $s['description'],
            'enabled' => (bool) $s['enabled'], 'agentic' => (bool) $s['enable_agentic'],
            'prompt' => (bool) $s['enable_prompt'], 'bundled' => (bool) $s['is_bundled'],
        ], Claudpress::instance()->skills->all());
        return ['success' => true, 'skills' => $skills];
    }

    public static function get(array $a): array
    {
        $s = Claudpress::instance()->skills->get_by_name((string) ($a['name'] ?? ''));
        return $s ? ['success' => true, 'name' => $s['name'], 'description' => $s['description'], 'body' => $s['body']] : ['success' => false, 'error' => 'Skill not found.'];
    }

    public static function save(array $a): array
    {
        return Claudpress::instance()->skills->save($a);
    }

    public static function refresh(array $a): array
    {
        $report = Claudpress_Skills::seed_bundled_skills(!empty($a['force']));
        return array_merge(['success' => true], $report, [
            'note' => $report['kept']
                ? 'Kept (edited on this site, or installed before Claudpress tracked edits): ' . implode(', ', $report['kept']) . '. Re-run with force=true to replace them.'
                : 'Every bundled skill is current.',
        ]);
    }

    public static function delete(array $a): array
    {
        return Claudpress::instance()->skills->delete((string) ($a['name'] ?? ''));
    }
}
