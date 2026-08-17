<?php
/**
 * Snippet storage logic — the pure, database-free half.
 *
 * These cover the three rules the Snippets page and cross-site copying stand
 * on, each of which fails silently rather than loudly when it breaks:
 *   - tag matching must be exact, or filtering by "seo" also returns "seo-audit"
 *   - a re-copy must resolve to the row it already created, or every copy
 *     duplicates the snippet on the destination site
 *   - PHP that does not parse must be caught before it is switched on
 */

CP_Test::group('Snippets — portable identity');

$uid = Claudpress_Snippets::new_uid();
CP_Test::ok(strpos($uid, 'cps_') === 0, 'a uid is prefixed cps_');
CP_Test::same(20, strlen($uid), 'a uid is a fixed 20 characters');

$seen = [];
for ($i = 0; $i < 1000; $i++) {
    $seen[Claudpress_Snippets::new_uid()] = true;
}
CP_Test::same(1000, count($seen), '1000 generated uids are all distinct');

CP_Test::group('Snippets — tag normalization');

CP_Test::same(',seo,perf,', Claudpress_Snippets::normalize_tags(['seo', 'perf']), 'tags are stored sentinel-wrapped');
CP_Test::same(',seo,', Claudpress_Snippets::normalize_tags('seo'), 'a bare string is accepted');
CP_Test::same(',seo,perf,', Claudpress_Snippets::normalize_tags(' SEO , Perf '), 'tags are lowercased and trimmed');
CP_Test::same(',seo,', Claudpress_Snippets::normalize_tags(['seo', 'seo', '']), 'duplicates and blanks are dropped');
CP_Test::same('', Claudpress_Snippets::normalize_tags([]), 'no tags means an empty string, not a lone sentinel');
CP_Test::same('', Claudpress_Snippets::normalize_tags(null), 'null is tolerated');
CP_Test::same(',sticky-header,', Claudpress_Snippets::normalize_tags('Sticky Header'), 'spaces become hyphens');
CP_Test::same(',drop,', Claudpress_Snippets::normalize_tags('dr%op'), 'punctuation is stripped rather than stored');

$many = Claudpress_Snippets::normalize_tags(range(1, 40));
CP_Test::same(10, count(Claudpress_Snippets::tags_array($many)), 'no more than 10 tags are kept');
CP_Test::ok(strlen(Claudpress_Snippets::tags_array(Claudpress_Snippets::normalize_tags([str_repeat('a', 80)]))[0]) <= 32, 'a tag is capped at 32 characters');

CP_Test::same(['seo', 'perf'], Claudpress_Snippets::tags_array(',seo,perf,'), 'tags round-trip back to an array');
CP_Test::same([], Claudpress_Snippets::tags_array(''), 'an empty tag string yields no tags');
CP_Test::same([], Claudpress_Snippets::tags_array(null), 'a null tag column yields no tags');

// The whole reason for the sentinels: an exact-tag LIKE must not match a prefix.
$stored = Claudpress_Snippets::normalize_tags(['seo-audit']);
CP_Test::ok(strpos($stored, ',seo,') === false, 'the tag seo-audit does not contain the tag seo');

CP_Test::group('Snippets — save() resolves the row to update');

/** Pretend rows 5 (uid cps_local) and 7 (a copy of cps_remote) already exist. */
$finder = static function (string $column, string $value): ?int {
    $rows = [
        'id'         => ['5' => 5, '7' => 7],
        'uid'        => ['cps_local' => 5, 'cps_copy' => 7],
        'origin_uid' => ['cps_remote' => 7],
    ];
    return $rows[$column][$value] ?? null;
};

CP_Test::same(5, Claudpress_Snippets::resolve_target(['id' => 5], $finder), 'an existing id resolves to that row');
CP_Test::same(null, Claudpress_Snippets::resolve_target(['id' => 99], $finder), 'an id that does not exist falls through to insert');
CP_Test::same(5, Claudpress_Snippets::resolve_target(['uid' => 'cps_local'], $finder), 'a known uid resolves to its row');
CP_Test::same(null, Claudpress_Snippets::resolve_target([], $finder), 'an empty payload inserts');
CP_Test::same(null, Claudpress_Snippets::resolve_target(['uid' => '', 'origin_uid' => ''], $finder), 'blank identifiers do not match anything');

// The anti-duplication guarantee: copying the same source snippet again lands
// on the copy already made, instead of adding a second one every time.
CP_Test::same(7, Claudpress_Snippets::resolve_target(['origin_uid' => 'cps_remote'], $finder), 're-copying resolves to the existing copy');
CP_Test::same(null, Claudpress_Snippets::resolve_target(['origin_uid' => 'cps_never_seen'], $finder), 'a first-time copy inserts');

// Precedence: an explicit id wins over the identity fields.
CP_Test::same(5, Claudpress_Snippets::resolve_target(['id' => 5, 'origin_uid' => 'cps_remote'], $finder), 'an explicit id outranks origin_uid');

CP_Test::group('Snippets — PHP is parse-checked before it can run');

CP_Test::same('', Claudpress_Snippets::lint_php('add_action("init", function () { return 1; });'), 'valid tag-less snippet PHP passes');
CP_Test::same('', Claudpress_Snippets::lint_php('$a = [1, 2, 3]; foreach ($a as $n) { echo $n; }'), 'valid multi-statement PHP passes');
CP_Test::ok(Claudpress_Snippets::lint_php('if (true) { echo "x";') !== '', 'an unclosed brace is rejected');
CP_Test::ok(Claudpress_Snippets::lint_php('function () {{{') !== '', 'garbage is rejected');

// page_template bodies are raw markup with <?php islands, so they lint as-is.
CP_Test::same('', Claudpress_Snippets::lint_php('<h1>Hi</h1><?php echo get_the_title(); ?>', 'php', 'page_template'), 'a page_template body with PHP islands passes');
CP_Test::ok(Claudpress_Snippets::lint_php('<h1>Hi</h1><?php echo (;', 'php', 'page_template') !== '', 'a broken page_template island is rejected');

// Only PHP is parse-checked; CSS and JS are not PHP and must not be judged as such.
CP_Test::same('', Claudpress_Snippets::lint_php('.a { color: red; }', 'css'), 'CSS is not linted as PHP');
CP_Test::same('', Claudpress_Snippets::lint_php('if (x) { y(); }', 'js'), 'JS is not linted as PHP');
CP_Test::same('', Claudpress_Snippets::lint_php('', 'php'), 'an empty body is not an error');
