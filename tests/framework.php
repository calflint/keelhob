<?php
/**
 * A ~100-line test framework. Claudpress ships with zero composer dependencies
 * and that constraint applies to its tests too — anything a contributor needs to
 * run them is already in PHP 8.
 */

final class CP_Test
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var array<int, string> */
    public static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public static function ok(bool $condition, string $message): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m " . $message . "\n";
            return;
        }
        self::$failed++;
        self::$failures[] = self::$group . ' → ' . $message;
        echo "  \033[31m✗ " . $message . "\033[0m\n";
    }

    public static function same($expected, $actual, string $message): void
    {
        $pass = $expected === $actual;
        if (!$pass) {
            $message .= sprintf(
                "\n      expected: %s\n      actual:   %s",
                self::render($expected),
                self::render($actual)
            );
        }
        self::ok($pass, $message);
    }

    public static function equals($expected, $actual, string $message): void
    {
        $pass = $expected == $actual;
        if (!$pass) {
            $message .= sprintf("\n      expected: %s\n      actual:   %s", self::render($expected), self::render($actual));
        }
        self::ok($pass, $message);
    }

    public static function between(float $min, float $max, float $actual, string $message): void
    {
        self::ok($actual >= $min && $actual <= $max, $message . sprintf(' (got %s, want %s..%s)', $actual, $min, $max));
    }

    public static function contains(string $needle, string $haystack, string $message): void
    {
        self::ok(strpos($haystack, $needle) !== false, $message . sprintf(' (looking for "%s")', $needle));
    }

    public static function throws(callable $fn, string $message): void
    {
        try {
            $fn();
            self::ok(false, $message . ' (nothing was thrown)');
        } catch (Throwable $e) {
            self::ok(true, $message);
        }
    }

    private static function render($v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_scalar($v)) {
            return var_export($v, true);
        }
        return json_encode($v, JSON_UNESCAPED_SLASHES);
    }

    public static function summary(): int
    {
        $total = self::$passed + self::$failed;
        echo "\n" . str_repeat('─', 60) . "\n";
        if (self::$failed === 0) {
            echo "\033[32m" . self::$passed . " / " . $total . " assertions passed\033[0m\n";
            return 0;
        }
        echo "\033[31m" . self::$failed . " of " . $total . " assertions FAILED\033[0m\n";
        foreach (self::$failures as $f) {
            echo "  · " . $f . "\n";
        }
        return 1;
    }
}
