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

KH_Test::group('Integrity — every tool has a capability toggle');

// Pull every $r->add('tool_name', … registration straight out of the source.
$registered = [];
foreach (glob($root . '/includes/tools/*.php') as $file) {
    preg_match_all("/\\\$r->add\(\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
    foreach ($m[1] as $name) {
        $registered[$name] = basename($file);
    }
}
KH_Test::ok(count($registered) > 40, 'found the tool registrations in source (' . count($registered) . ' tools)');

$grouped = [];
foreach (Keelhob_Capabilities::groups() as $key => $group) {
    foreach ($group['tools'] as $tool) {
        $grouped[$tool] = $key;
    }
}
$always = Keelhob_Capabilities::always_on();

$ungoverned = [];
foreach ($registered as $tool => $file) {
    if (!isset($grouped[$tool]) && !in_array($tool, $always, true)) {
        $ungoverned[] = $tool . ' (' . $file . ')';
    }
}
KH_Test::same([], $ungoverned, 'every registered tool is either in a capability group or explicitly always-on');

// A capability group naming a tool that does not exist is a bug: the plugin is
// supposed to contain everything it advertises.
//
// This assertion used to carve out an exception. Some groups described features
// whose code shipped in a separate paid zip, so a missing tool there was
// expected and the test asserted that at least one WAS missing. There is one
// zip now, so the exception is gone and the rule is the stronger one: every
// tool every group names must actually exist.
$phantom = [];
foreach ($grouped as $tool => $group) {
    if (!isset($registered[$tool])) {
        $phantom[] = $tool . ' (group: ' . $group . ')';
    }
}
KH_Test::same([], $phantom, 'no capability group references a tool that does not ship');

$phantom_always = [];
foreach ($always as $tool) {
    if (!isset($registered[$tool])) {
        $phantom_always[] = $tool;
    }
}
KH_Test::same([], $phantom_always, 'no always-on entry references a tool that no longer exists');

KH_Test::group('Integrity — the agent install guide lists the default-off capability groups');

$guide = (string) file_get_contents($root . '/llms-install.md');
preg_match('/^## Capabilities are off by default, on purpose\R[ \t]*\R(.*?)(?=\R[ \t]*\R|\z)/ms', $guide, $section);
KH_Test::ok(isset($section[1]), 'llms-install.md has a paragraph listing the default-off capability groups');
preg_match_all('/`([a-z][a-z0-9_]*)`/', $section[1] ?? '', $documented);
$default_off = Keelhob_Capabilities::DISABLED_BY_DEFAULT;
KH_Test::same([], array_values(array_diff($default_off, $documented[1])), 'every default-off group is listed in llms-install.md');
KH_Test::same([], array_values(array_diff($documented[1], $default_off)), 'llms-install.md lists only default-off groups');

KH_Test::group('Integrity — sensitive tools are marked sensitive');

// Anything that can spend money, run code, or touch the filesystem must live in
// a group flagged sensitive, because that flag drives the UI warnings.
$must_be_sensitive = ['execute_php', 'execute_sql', 'wc_payments', 'wc_order', 'manage_user', 'delete_file', 'write_file', 'deploy_push', 'run_agent'];
$groups = Keelhob_Capabilities::groups();
foreach ($must_be_sensitive as $tool) {
    $group = $grouped[$tool] ?? null;
    KH_Test::ok($group !== null && !empty($groups[$group]['sensitive']), $tool . ' sits in a group flagged sensitive');
}

KH_Test::group('Integrity — every include resolves');

// keelhob.php used to carry a hand-maintained require_once list, and this
// group read that list back to prove no class file had been added to includes/
// and then forgotten. The list is an autoloader now, so the same guarantee is
// checked the other way round: start from the classes that actually ship, and
// prove the autoloader can find every one of them.
//
// The explicit map is read out of keelhob.php rather than restated here, so
// this test cannot drift into agreeing with a copy of itself.
$bootstrap = (string) file_get_contents($root . '/keelhob.php');

preg_match_all("/'(keelhob[a-z0-9_]*)'\s*=>\s*'(includes\/[^']+)'/", $bootstrap, $mm, PREG_SET_ORDER);
$map = [];
foreach ($mm as $pair) {
    $map[$pair[1]] = $pair[2];
}
KH_Test::ok(
    strpos($bootstrap, 'spl_autoload_register') !== false,
    'keelhob.php registers an autoloader (' . count($map) . ' classes also mapped explicitly)'
);

// The shipped resolution rule: lowercase, underscores to hyphens, includes/
// before includes/tools/, with the explicit map taking precedence.
$resolve = static function (string $class) use ($map, $root): ?string {
    $lc = strtolower($class);
    if (isset($map[$lc])) {
        return $map[$lc];
    }
    if ($lc === 'keelhob') {
        $slug = 'keelhob';
    } elseif (strncmp($lc, 'keelhob_', strlen('keelhob_')) === 0) {
        $slug = str_replace('_', '-', substr($lc, strlen('keelhob_')));
    } else {
        return null;
    }
    foreach (['includes/class-', 'includes/tools/class-'] as $dir) {
        if (is_readable($root . '/' . $dir . $slug . '.php')) {
            return $dir . $slug . '.php';
        }
    }
    return null;
};

// Every class/interface/trait declared anywhere under includes/, read from source.
$declared = [];
foreach (array_merge(glob($root . '/includes/*.php'), glob($root . '/includes/tools/*.php')) as $file) {
    $rel  = str_replace('\\', '/', substr($file, strlen($root) + 1));
    $toks = token_get_all((string) file_get_contents($file));
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        if (!is_array($toks[$i]) || !in_array($toks[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            continue;
        }
        // Skip the ::class constant.
        for ($p = $i - 1; $p >= 0 && is_array($toks[$p]) && $toks[$p][0] === T_WHITESPACE; $p--) {
        }
        if (isset($toks[$p]) && is_array($toks[$p]) && $toks[$p][0] === T_DOUBLE_COLON) {
            continue;
        }
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                $declared[$toks[$j][1]] = $rel;
            }
            break;
        }
    }
}
KH_Test::ok(count($declared) > 25, 'found the classes the plugin ships (' . count($declared) . ' classes)');

$orphans = [];
foreach ($declared as $class => $rel) {
    if ($resolve($class) !== $rel) {
        $orphans[] = $class . ' (declared in ' . $rel . ', autoloader looks in ' . var_export($resolve($class), true) . ')';
    }
}
KH_Test::same([], $orphans, 'no class file sits in includes/ without being loaded');

KH_Test::group('Integrity — version is consistent everywhere');

preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $bootstrap, $header);
preg_match("/define\('KEELHOB_VERSION',\s*'([^']+)'\)/", $bootstrap, $constant);
$readme = (string) file_get_contents($root . '/readme.txt');
preg_match('/^Stable tag:\s*(\S+)/m', $readme, $stable);

KH_Test::ok(!empty($header[1]), 'the plugin header declares a version');
KH_Test::same($header[1] ?? 'a', $constant[1] ?? 'b', 'the plugin header and KEELHOB_VERSION agree');
KH_Test::same($header[1] ?? 'a', $stable[1] ?? 'b', 'readme.txt Stable tag matches the plugin version');
KH_Test::contains('= ' . ($header[1] ?? '?'), $readme, 'readme.txt has a changelog entry for this version');

// ROADMAP.md is mirrored onto keelhob.com/roadmap, so a stale file is a stale
// public page. It went stale for seventeen commits and four releases: the page
// kept telling strangers that 1.1.0 was the newest version while 2.1.2 was on
// GitHub, and that the demo video was the one thing blocking a release, after
// it had been recorded. The mirror was working perfectly. Nobody had edited the
// file, and nothing could tell.
//
// So the version lives in a marker instead of in prose, and this test fails the
// moment a release is cut without the roadmap being brought with it. There is
// nothing to remember.
$roadmap = (string) file_get_contents($root . '/ROADMAP.md');
preg_match('/<!--\s*keelhob:latest-release\s+(\S+)\s*-->/', $roadmap, $marker);
KH_Test::ok(!empty($marker[1]), 'ROADMAP.md carries a keelhob:latest-release marker');
KH_Test::same(
    $header[1] ?? 'a',
    $marker[1] ?? 'b',
    'ROADMAP.md names the current version as the latest release'
);
KH_Test::contains(
    '**' . ($header[1] ?? '?') . ' is the latest published release.**',
    $roadmap,
    'the sentence a reader sees matches the marker a machine reads'
);

// A version that already shipped cannot still be planned. This catches the
// other half of the drift: headings like "## Now (1.3)" left behind after 2.x
// shipped, which is what actually made the old file read as abandoned.
$planned = [];
if (preg_match('/^## Now \((\d+\.\d+)\)/m', $roadmap, $now)) {
    $planned[] = $now[1];
}
preg_match_all('/^### (\d+\.\d+) /m', $roadmap, $next);
foreach ($next[1] as $v) {
    $planned[] = $v;
}
KH_Test::ok($planned !== [], 'ROADMAP.md still plans at least one future version');
$shipped = [];
foreach ($planned as $v) {
    if (version_compare($v, (string) ($header[1] ?? '0'), '<=')) {
        $shipped[] = $v;
    }
}
KH_Test::same([], $shipped, 'ROADMAP.md does not plan a version that already shipped');

KH_Test::group('Integrity — readme.txt has not drifted from README.md');

// Three sections say the same thing in both files: what this plugin can do to a
// site, what eval() is doing in the source, and what it cannot do yet. They are
// what a careful person reads before trusting it, and they HAD already drifted —
// README.md grew an eleventh limitation that readme.txt never got, and nothing
// noticed, because nothing was looking.
//
// bin/build-readme-txt.php generates them from README.md. This is the half that
// makes that stick: edit README.md, forget to regenerate, and CI says so.
//
// It is included rather than shelled out to. Nothing in that file touches
// WordPress, so including it costs nothing, and a subprocess would be the only
// one in this suite.
require_once $root . '/bin/build-readme-txt.php';

// Compared line by line rather than whole-string. A failing same() on two
// 280-line documents prints both of them, which buries the one line that
// actually differs — and a failure nobody can read is a failure nobody fixes.
try {
    [$current, $expected] = readme_txt_expected($root);

    $a = explode("\n", $current);
    $b = explode("\n", $expected);
    $firstDiff = null;
    for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
        if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
            $firstDiff = 'readme.txt line ' . ($i + 1) . " differs.\n"
                . '      readme.txt says: ' . var_export($a[$i] ?? '(end of file)', true) . "\n"
                . '      README.md gives: ' . var_export($b[$i] ?? '(end of file)', true) . "\n"
                . '      Fix by running:  php bin/build-readme-txt.php';
            break;
        }
    }
    KH_Test::same(
        null,
        $firstDiff,
        'readme.txt matches what bin/build-readme-txt.php generates from README.md'
    );
} catch (RuntimeException $e) {
    KH_Test::ok(false, 'the readme generator ran: ' . $e->getMessage());
}

KH_Test::group('Integrity — no secrets leak through the migration bundle');

$migration = (string) file_get_contents($root . '/includes/class-migration.php');
KH_Test::ok(strpos($migration, 'keelhob_settings') === false, 'the settings option (API keys, tokens) is never named in the export path');

// The option whitelist is the only thing that decides what leaves the site.
preg_match('/function option_whitelist\(\): array\s*\{(.*?)\}/s', $migration, $wl);
KH_Test::ok(!empty($wl[1]), 'the option whitelist is present');
foreach (['token', 'secret', 'password', 'api_key'] as $danger) {
    KH_Test::ok(stripos((string) ($wl[1] ?? ''), $danger) === false, 'the option whitelist contains nothing matching "' . $danger . '"');
}

KH_Test::group('Integrity — bundle types line up with the exporter');

foreach (Keelhob_Migration::TYPES as $type) {
    KH_Test::contains("'" . $type . "'", $migration, 'exporter handles bundle type: ' . $type);
}

KH_Test::group('Integrity — PHP files are syntactically clean and guarded');

$unguarded = [];
foreach (array_merge(glob($root . '/includes/*.php'), glob($root . '/includes/tools/*.php')) as $file) {
    if (strpos((string) file_get_contents($file), "if (!defined('ABSPATH'))") === false) {
        $unguarded[] = basename($file);
    }
}
KH_Test::same([], $unguarded, 'every class file blocks direct web access with an ABSPATH guard');

KH_Test::group('Integrity — always-on tools survive the tier split');

// always_on() is a promise: these tools work no matter what the owner has
// restricted, so they can always ask "what is going on". Tiering must not be
// able to break that promise, and it very nearly did - three of them
// (get_dashboard, task_list, agent_runs) sit in the PRO automation group, so
// moving that whole file to the add-on would have made always-on tools vanish
// on a free site.
//
// On free, task_list and agent_runs simply return nothing, because nothing can
// have created a task or a run. Empty is the right answer, and a much better
// one than a missing tool.
$always_missing = [];
foreach (Keelhob_Capabilities::always_on() as $tool) {
    if (!isset($registered[$tool])) {
        $always_missing[] = $tool;
    }
}
KH_Test::same([], $always_missing, 'every always-on tool still ships in the FREE plugin');

foreach (Keelhob_Capabilities::always_on() as $tool) {
    KH_Test::ok(Keelhob_Capabilities::tool_allowed($tool), $tool . ' is allowed on a free install regardless of its group tier');
}

KH_Test::group('Integrity — reflection in tests works on the PHP floor');

// PHP 8.0 refuses to invoke a private method through reflection unless
// setAccessible(true) was called first; 8.1 made the call unnecessary. So a test
// that reaches into a private method passes on a modern dev machine and fatals
// on the version readme.txt names as the minimum — and because the runner dies
// at the first fatal, everything after it silently stops running too.
//
// This has now happened twice. It is invisible locally and only CI catches it,
// which is a slow and confusing way to find out. So the suite checks itself.

$unguarded = [];
foreach (glob(dirname(__DIR__) . '/unit/*.php') as $file) {
    $lines = preg_split('/\R/', (string) file_get_contents($file));
    foreach ($lines as $i => $line) {
        // Only the assign-then-invoke form. The inline
        // (new ReflectionMethod(...))->getNumberOfParameters() form reads
        // metadata rather than invoking, and needs no accessibility.
        if (!preg_match('/^\s*(\$\w+)\s*=\s*new\s+ReflectionMethod\(/', $line)) {
            continue;
        }
        if (strpos($lines[$i + 1] ?? '', 'setAccessible') === false) {
            $unguarded[] = basename($file) . ':' . ($i + 1);
        }
    }
}
KH_Test::same([], $unguarded, 'every ReflectionMethod assigned in a test calls setAccessible(true) on the next line');
