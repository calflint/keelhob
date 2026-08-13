<?php
/**
 * Regression test for the incident that took pranknologia.shop down: a
 * write_file call meant to update includes/tools/class-tools-seo.php instead
 * wrote HTML-entity-escaped content into that live, always-require_once'd
 * plugin file — critically, the opening "<?php" itself came out as "&lt;?php".
 *
 * That is NOT a PHP parse error: a file with no real opening tag is 100%
 * valid PHP, it's just treated as one giant literal-text token. So the class
 * the file was supposed to define silently never existed, the raw escaped
 * markup got echoed as the HTTP response body on every request (hence the
 * "text/html" responses in place of the expected JSON), and nothing about it
 * would trip a naive token_get_all()-only syntax check — confirmed below.
 *
 * write_file/edit_file/write_sandbox_file now run the resulting content
 * through php_syntax_error() before touching disk and refuse to write if the
 * opening tag is missing/wrong OR the content has a real parse error. These
 * tests exercise that gate directly via reflection, since it's intentionally
 * private (an internal safety check, not a tool).
 */

require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-filesystem.php';

function cp_test_syntax_check(string $path, string $content): ?string
{
    $method = new ReflectionMethod(Claudpress_Tools_Filesystem::class, 'php_syntax_error');
    @$method->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
    return $method->invoke(null, $path, $content);
}

CP_Test::group('Filesystem safety — the exact incident is now caught');

// The real shape of the broken write: even the opening tag got HTML-escaped.
$broken = <<<'PHP'
&lt;?php
if (!defined('ABSPATH')) {
    exit;
}
final class Claudpress_Tools_SEO {
    public static function hooks(): void {
        echo "\n&lt;script type=\"application/ld+json\"&gt;" . $x . "&lt;/script&gt;\n";
    }
}
PHP;

CP_Test::same([T_INLINE_HTML], array_unique(array_column(token_get_all($broken, TOKEN_PARSE), 0)), 'sanity check: confirms the actual failure mode — a mangled opening tag makes PHP see the whole file as one single T_INLINE_HTML token, not a parse error, so a naive token_get_all()-only check would have let this exact incident through');
CP_Test::ok(cp_test_syntax_check('includes/tools/class-tools-seo.php', $broken) !== null, 'the mangled opening tag is caught');
CP_Test::contains('<?php', (string) cp_test_syntax_check('includes/tools/class-tools-seo.php', $broken), 'the error explains the file is missing a real opening tag');

CP_Test::group('Filesystem safety — real parse errors are rejected before disk');

$mismatched_brace = "<?php\nfunction foo() {\n    echo 'no closing brace';\n";
CP_Test::ok(cp_test_syntax_check('includes/tools/class-tools-seo.php', $mismatched_brace) !== null, 'a file missing a closing brace is rejected');

$stray_token = "<?php\nfoo(;\n";
CP_Test::ok(cp_test_syntax_check('includes/tools/class-tools-seo.php', $stray_token) !== null, 'a stray syntax token is rejected');

$unterminated_string = "<?php\n\$x = 'unterminated;\n";
CP_Test::ok(cp_test_syntax_check('includes/tools/class-tools-seo.php', $unterminated_string) !== null, 'an unterminated string literal is rejected');

CP_Test::group('Filesystem safety — the gate only applies to .php files');

CP_Test::ok(cp_test_syntax_check('assets/notes.txt', $mismatched_brace) === null, 'non-.php paths are never checked, even with broken-PHP-looking content');
CP_Test::ok(cp_test_syntax_check('READY.PHP', $mismatched_brace) !== null, 'the .php check is case-insensitive');

CP_Test::group('Filesystem safety — valid PHP always passes');

$valid = "<?php\nfinal class Foo {\n    public static function bar(): void {}\n}\n";
CP_Test::ok(cp_test_syntax_check('includes/tools/class-tools-seo.php', $valid) === null, 'syntactically valid PHP with a real opening tag is never rejected');

$valid_with_bom_and_blank_lines = "\xEF\xBB\xBF\n\n  <?php\necho 'ok';\n";
CP_Test::ok(cp_test_syntax_check('includes/tools/class-tools-seo.php', $valid_with_bom_and_blank_lines) === null, 'a UTF-8 BOM or leading whitespace before a real <?php tag is tolerated');
