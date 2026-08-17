<?php
/**
 * Bundled-skill refresh rules.
 *
 * These decide whether a plugin update is allowed to replace a playbook already
 * on somebody's site. Get it wrong in one direction and shipped improvements
 * never arrive; get it wrong in the other and you silently delete work the owner
 * did. Neither failure announces itself, so the rules are pinned here.
 */

CP_Test::group('Skills — deciding whether an update may replace a bundled skill');

$shipped = md5('NEW BODY');

/** @param string $body @param string $hash */
$row = static function (string $body, string $hash): array {
    return ['body' => $body, 'bundled_hash' => $hash, 'enabled' => 1];
};

CP_Test::same(
    'install',
    Claudpress_Skills::bundled_action(null, $shipped),
    'a skill the site has never seen is installed'
);

// Untouched = the stored body still hashes to what Claudpress last wrote.
CP_Test::same(
    'update',
    Claudpress_Skills::bundled_action($row('OLD BODY', md5('OLD BODY')), $shipped),
    'an untouched bundled skill is refreshed to the newer shipped text'
);
CP_Test::same(
    'current',
    Claudpress_Skills::bundled_action($row('NEW BODY', $shipped), $shipped),
    'a skill already matching the shipped text is left alone'
);

// Edited = the stored body no longer matches the hash Claudpress recorded.
CP_Test::same(
    'keep',
    Claudpress_Skills::bundled_action($row('OWNER REWROTE THIS', md5('OLD BODY')), $shipped),
    'a skill the owner edited is never overwritten by an update'
);

// Pre-1.9 rows carry no hash at all, so an edit cannot be ruled out.
CP_Test::same(
    'keep',
    Claudpress_Skills::bundled_action($row('OLD BODY', ''), $shipped),
    'a skill with no recorded hash is kept rather than guessed at'
);

// force is the owner explicitly asking for the shipped version back.
CP_Test::same(
    'update',
    Claudpress_Skills::bundled_action($row('OWNER REWROTE THIS', md5('OLD BODY')), $shipped, true),
    'force replaces an edited skill'
);
CP_Test::same(
    'update',
    Claudpress_Skills::bundled_action($row('OLD BODY', ''), $shipped, true),
    'force replaces an untracked skill'
);
CP_Test::same(
    'install',
    Claudpress_Skills::bundled_action(null, $shipped, true),
    'force still installs a missing skill rather than skipping it'
);

CP_Test::group('Skills — every bundled skill file is loadable');

// A skill with broken frontmatter seeds under the wrong name, or not at all, and
// the failure only shows up as the agent quietly not having the playbook.
$files = glob(CLAUDPRESS_DIR . 'skills/*.md');
CP_Test::ok(count($files) > 8, 'the bundled skills are present (' . count($files) . ' files)');

$bad = [];
foreach ($files as $file) {
    $parsed = Claudpress_Skills::parse_markdown((string) file_get_contents($file));
    $name   = trim((string) ($parsed['name'] ?? ''));
    $desc   = trim((string) ($parsed['description'] ?? ''));
    $body   = trim((string) ($parsed['body'] ?? ''));
    if ($name === '' || $desc === '' || $body === '') {
        $bad[] = basename($file);
        continue;
    }
    // The description is the only thing the agent sees before loading the skill,
    // so a vague one-liner means the skill never triggers.
    if (strlen($desc) < 60) {
        $bad[] = basename($file) . ' (description too short to trigger on)';
    }
}
CP_Test::same([], $bad, 'every bundled skill has a name, a usable description, and a body');
