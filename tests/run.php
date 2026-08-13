<?php
/**
 * Test runner.  php tests/run.php
 *
 * No composer, no PHPUnit, no WordPress install — see tests/bootstrap.php for
 * why. Exits non-zero on the first failing assertion set, so CI can gate on it.
 */

require_once __DIR__ . '/framework.php';
require_once __DIR__ . '/bootstrap.php';

echo "Claudpress test suite — PHP " . PHP_VERSION . "\n";

$files = glob(__DIR__ . '/unit/test-*.php');
sort($files);

foreach ($files as $file) {
    require $file;
}

exit(CP_Test::summary());
