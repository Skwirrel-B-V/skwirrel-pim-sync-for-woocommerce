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
// Inside wp-env, WP_TESTS_DIR points at /wordpress-phpunit (pre-provisioned
// with auto-generated wp-tests-config.php for the tests DB).
// Outside wp-env, fall back to the wp-phpunit composer package.
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' );

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

/**
 * Is this JSON-RPC call the sync run's membership sweep?
 *
 * The sweep (Story 2.6) asks `getProductsByFilter` for the complete product-id membership of a
 * selection: `filter: { dynamic_selection_id }` and no payload includes — no `updated_on`, no
 * `include_*` flags. That is a different question from the paginated content fetch, which carries
 * the include flags, so a stub must answer it separately; otherwise the run sees an empty
 * selection, treats the sweep as incomplete, and the stub's page counter is off by one call.
 *
 * What makes it a sweep is the ABSENCE of payload includes, not an empty `options` array. Story 5.3
 * put the Context ID on every call site including this one, so a sweep on an install with a
 * configured Context ID legitimately sends `options: { include_contexts: [...] }`. Scoping is not
 * payload: the keys below are the ones that select WHICH context is being asked about, and they
 * leave a sweep a sweep.
 *
 * @param array<string, mixed> $params JSON-RPC params of the call.
 */
function skwIsSweepCall( array $params ): bool {
	if ( ! isset( $params['filter']['dynamic_selection_id'] ) || isset( $params['filter']['code'] ) ) {
		return false;
	}

	$scoping_only = array( 'include_contexts' );

	return array() === array_diff( array_keys( (array) ( $params['options'] ?? array() ) ), $scoping_only );
}

/**
 * Delete every Skwirrel-managed post left in the database.
 *
 * `WP_UnitTestCase` wraps each test in a transaction, but WooCommerce product saves write to side
 * tables and caches that do not all roll back, so products seeded by one test file stay visible to
 * the next. That is harmless for a test that looks up its own fixtures by id, and fatal for any
 * test that reasons about the SIZE of the Skwirrel catalogue — the sweep diff and its mass-removal
 * ratio are computed over every live Skwirrel-owned product in the shop.
 *
 * The key list is deliberately wider than the older per-file cleanups: a product seeded with ONLY
 * `_skwirrel_product_id` (no external id, no sync stamp) is invisible to those, but is very much
 * counted by the sweep diff.
 */
function skwPurgeSkwirrelPosts(): void {
	global $wpdb;
	$post_ids = $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		WHERE meta_key IN (
			'_skwirrel_product_id',
			'_skwirrel_external_id',
			'_skwirrel_grouped_product_id',
			'_skwirrel_synced_at'
		)"
	);
	foreach ( $post_ids as $pid ) {
		wp_delete_post( (int) $pid, true );
	}
}
