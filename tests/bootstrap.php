<?php
/**
 * PHPUnit bootstrap: WordPress's own test bootstrap with WP Updates Notifier loaded.
 *
 * Run through bin/run-tests.sh, which checks out WordPress and WP Updates Notifier and sets WP_TESTS_DIR.
 *
 * @package wpun-slack-channel
 */

$wpun_slack_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wpun_slack_tests_dir || ! file_exists( $wpun_slack_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Run the tests with bin/run-tests.sh.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

require_once $wpun_slack_tests_dir . '/includes/functions.php';

// WP Updates Notifier is the plugin under test's host. The add-on itself is loaded by the test file.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/wp-updates-notifier/wp-updates-notifier.php';
	}
);

require $wpun_slack_tests_dir . '/includes/bootstrap.php';

// Needs WP_UnitTestCase, so it loads after core's bootstrap.
require_once __DIR__ . '/class-test-case.php';
