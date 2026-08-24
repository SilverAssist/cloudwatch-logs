<?php
/**
 * PHPUnit bootstrap for the Silver Assist CloudWatch Logs plugin.
 *
 * Loads the WordPress test suite so that integration tests run against a real
 * WordPress environment.
 *
 * @package SilverAssist\CloudWatchLogs
 * @since 1.0.0
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "\n";
	echo "========================================\n";
	echo "WordPress Test Suite Not Found\n";
	echo "========================================\n";
	echo "Location checked: {$_tests_dir}\n\n";
	echo "Install it with:\n";
	echo "  bash scripts/install-wp-tests.sh wordpress_test root '' localhost latest\n\n";
	echo "Or point WP_TESTS_DIR at an existing installation:\n";
	echo "  export WP_TESTS_DIR=/path/to/wordpress-tests-lib\n\n";
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load the plugin under test before WordPress finishes booting.
 *
 * @return void
 */
function silver_assist_cloudwatch_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/silver-assist-cloudwatch-logs.php';
}

tests_add_filter( 'muplugins_loaded', 'silver_assist_cloudwatch_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";

echo "\n";
echo "========================================\n";
echo "WordPress Test Environment Loaded\n";
echo "========================================\n";
echo 'WordPress Version: ' . get_bloginfo( 'version' ) . "\n";
echo 'PHP Version: ' . phpversion() . "\n";
echo 'Plugin Version: ' . SILVER_ASSIST_CLOUDWATCH_VERSION . "\n";
echo "========================================\n\n";
