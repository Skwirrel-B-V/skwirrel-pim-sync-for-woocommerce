<?php
/**
 * Bootstrap for integration tests.
 *
 * Loads the real WordPress test framework + WooCommerce + this plugin so
 * tests run against an actual WordPress instance with a real database.
 *
 * Designed to run inside the wp-env "tests" container, where:
 *   - WP_PHPUNIT__DIR  is auto-set to /wordpress-phpunit
 *   - WP_TESTS_DOMAIN  is auto-set
 *   - WP_TESTS_DB_*    is auto-configured
 *   - WordPress + WooCommerce are pre-installed
 *
 * Run with:
 *   npx wp-env start
 *   npm run test:integration
 *
 * Or directly:
 *   wp-env run tests-cli --env-cwd=wp-content/plugins/skwirrel-pim-sync \
 *     vendor/bin/pest -c phpunit-integration.xml.dist
 */

declare(strict_types=1);

// Resolve the WordPress test framework location.
// Inside wp-env, WP_PHPUNIT__DIR is set automatically.
// Outside wp-env, fall back to the wp-phpunit composer package.
$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find {$_tests_dir}/includes/functions.php.\n" .
		"Either run inside wp-env (npm run test:integration) or install wp-phpunit/wp-phpunit via composer.\n"
	);
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load WooCommerce + this plugin before WordPress finishes loading.
 *
 * `muplugins_loaded` fires before regular plugins, so we hook the loader here
 * and require the plugin files directly. This is the standard pattern for
 * the WP test suite (which doesn't run the regular plugin activation flow).
 */
tests_add_filter(
	'muplugins_loaded',
	function (): void {
		// WooCommerce — installed by wp-env via .wp-env.json plugins list.
		$wc_main = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( file_exists( $wc_main ) ) {
			require_once $wc_main;
		} else {
			fwrite( STDERR, "WooCommerce not found at {$wc_main}\n" );
			exit( 1 );
		}

		// This plugin.
		require dirname( __DIR__, 2 ) . '/skwirrel-pim-sync.php';
	}
);

/**
 * Install WooCommerce tables once WordPress + WC are loaded.
 *
 * The WP test suite truncates tables between tests via transactions, but the
 * initial schema must exist. WC creates its own tables on activation; we
 * trigger the installer manually here.
 */
tests_add_filter(
	'setup_theme',
	function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}
	}
);

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
