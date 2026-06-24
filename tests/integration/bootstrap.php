<?php
declare(strict_types=1);

// Always use the Composer-installed wp-phpunit package (7.x, PHPUnit 11 compatible).
// The container sets WP_TESTS_DIR=/wordpress-phpunit (WP 6.x test suite), which predates
// PHPUnit 10/11 and causes failures — we deliberately ignore it here.
$_tests_dir = getenv('WP_PHPUNIT__DIR') ?: __DIR__ . '/../../vendor/wp-phpunit/wp-phpunit';

// When running inside wp-env, the container's wp-tests-config.php already defines DB_* and the
// required WP_TESTS_* constants. Point WP_PHPUNIT__TESTS_CONFIG at it so the Composer package
// picks up the correct config instead of falling back to its own stub.
if (getenv('WP_TESTS_DIR') && !getenv('WP_PHPUNIT__TESTS_CONFIG')) {
    putenv('WP_PHPUNIT__TESTS_CONFIG=' . getenv('WP_TESTS_DIR') . '/wp-tests-config.php');
}

// Tell the WP bootstrap where our Composer-installed PHPUnit Polyfills live.
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', __DIR__ . '/../../vendor/yoast/phpunit-polyfills');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/porto-sender.php';
});

require $_tests_dir . '/includes/bootstrap.php';
