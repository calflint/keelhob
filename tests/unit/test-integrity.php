<?php
/**
 * Whole-plugin integrity checks that read the source itself.
 *
 * These catch the class of mistake unit tests miss: a tool added without a
 * capability toggle (so the owner's off switch silently doesn't cover it), a
 * class file added to includes/ but never required, or a version bumped in one
 * place and not the other three.
 */

$root = dirname(__DIR__, 2);

CP_Test::group('Integrity — every tool has a capability toggle');

// Pull every $r->add('tool_name', … registration straight out of the source.
$registered = [];
foreach (glob($root . '/includes/tools/*.php') as $file) {
    preg_match_all("/\\\$r->add\(\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
    foreach ($m[1] as $name) {
        $registered[$name] = basename($file);
    }
}
CP_Test::ok(count($registered) > 40, 'found the tool registrations in source (' . count($registered) . ' tools)');

$grouped = [];
foreach (Claudpress_Capabilities::groups() as $key => $group) {
    foreach ($group['tools'] as $tool) {
        $grouped[$tool] = $key;
    }
}
$always = Claudpress_Capabilities::always_on();

$ungoverned = [];
foreach ($registered as $tool => $file) {
    if (!isset($grouped[$tool]) && !in_array($tool, $always, true)) {
        $ungoverned[] = $tool . ' (' . $file . ')';
    }
}
CP_Test::same([], $ungoverned, 'every registered tool is either in a capability group or explicitly always-on');

$phantom = [];
foreach ($grouped as $tool => $group) {
    if (!isset($registered[$tool])) {
        $phantom[] = $tool . ' (group: ' . $group . ')';
    }
}
CP_Test::same([], $phantom, 'no capability group references a tool that no longer exists');

$phantom_always = [];
foreach ($always as $tool) {
    if (!isset($registered[$tool])) {
        $phantom_always[] = $tool;
    }
}
CP_Test::same([], $phantom_always, 'no always-on entry references a tool that no longer exists');

CP_Test::group('Integrity — sensitive tools are marked sensitive');

// Anything that can spend money, run code, or touch the filesystem must live in
// a group flagged sensitive, because that flag drives the UI warnings.
$must_be_sensitive = ['execute_php', 'execute_sql', 'wc_payments', 'wc_order', 'manage_user', 'delete_file', 'write_file', 'deploy_push', 'run_agent'];
$groups = Claudpress_Capabilities::groups();
foreach ($must_be_sensitive as $tool) {
    $group = $grouped[$tool] ?? null;
    CP_Test::ok($group !== null && !empty($groups[$group]['sensitive']), $tool . ' sits in a group flagged sensitive');
}

CP_Test::group('Integrity — every include resolves');

$bootstrap = (string) file_get_contents($root . '/claudpress.php');
preg_match_all("/require_once CLAUDPRESS_DIR \. '([^']+)'/", $bootstrap, $m);
CP_Test::ok(count($m[1]) > 25, 'found the require_once list (' . count($m[1]) . ' files)');

$missing = [];
foreach ($m[1] as $rel) {
    if (!file_exists($root . '/' . $rel)) {
        $missing[] = $rel;
    }
}
CP_Test::same([], $missing, 'every required file exists');

$required = array_flip($m[1]);
$orphans  = [];
foreach (array_merge(glob($root . '/includes/*.php'), glob($root . '/includes/tools/*.php')) as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    if (!isset($required[$rel])) {
        $orphans[] = $rel;
    }
}
CP_Test::same([], $orphans, 'no class file sits in includes/ without being loaded');

CP_Test::group('Integrity — version is consistent everywhere');

preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $bootstrap, $header);
preg_match("/define\('CLAUDPRESS_VERSION',\s*'([^']+)'\)/", $bootstrap, $constant);
$readme = (string) file_get_contents($root . '/readme.txt');
preg_match('/^Stable tag:\s*(\S+)/m', $readme, $stable);

CP_Test::ok(!empty($header[1]), 'the plugin header declares a version');
CP_Test::same($header[1] ?? 'a', $constant[1] ?? 'b', 'the plugin header and CLAUDPRESS_VERSION agree');
CP_Test::same($header[1] ?? 'a', $stable[1] ?? 'b', 'readme.txt Stable tag matches the plugin version');
CP_Test::contains('= ' . ($header[1] ?? '?'), $readme, 'readme.txt has a changelog entry for this version');

CP_Test::group('Integrity — no secrets leak through the migration bundle');

$migration = (string) file_get_contents($root . '/includes/class-migration.php');
CP_Test::ok(strpos($migration, 'claudpress_settings') === false, 'the settings option (API keys, tokens) is never named in the export path');

// The option whitelist is the only thing that decides what leaves the site.
preg_match('/function option_whitelist\(\): array\s*\{(.*?)\}/s', $migration, $wl);
CP_Test::ok(!empty($wl[1]), 'the option whitelist is present');
foreach (['token', 'secret', 'password', 'api_key'] as $danger) {
    CP_Test::ok(stripos((string) ($wl[1] ?? ''), $danger) === false, 'the option whitelist contains nothing matching "' . $danger . '"');
}

CP_Test::group('Integrity — bundle types line up with the exporter');

foreach (Claudpress_Migration::TYPES as $type) {
    CP_Test::contains("'" . $type . "'", $migration, 'exporter handles bundle type: ' . $type);
}

CP_Test::group('Integrity — PHP files are syntactically clean and guarded');

$unguarded = [];
foreach (array_merge(glob($root . '/includes/*.php'), glob($root . '/includes/tools/*.php')) as $file) {
    if (strpos((string) file_get_contents($file), "if (!defined('ABSPATH'))") === false) {
        $unguarded[] = basename($file);
    }
}
CP_Test::same([], $unguarded, 'every class file blocks direct web access with an ABSPATH guard');
