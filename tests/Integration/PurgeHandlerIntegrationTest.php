<?php
/**
 * Integration test for Skwirrel_WC_Sync_Purge_Handler.
 *
 * Exercises the real $wpdb queries against a real WordPress + WooCommerce
 * database. Critical safety behaviours covered:
 *
 *  - purge_stale_products(): REGEXP guard against corrupt synced_at meta,
 *    "trashed-already" and "non-Skwirrel product" exclusions, variation
 *    cascade for stale variable parents.
 *  - purge_stale_categories(): "products-still-assigned" safety check
 *    that prevents data loss when a Skwirrel category still has products
 *    on it.
 */

declare(strict_types=1);

beforeEach(function () {
	$this->logger        = new Skwirrel_WC_Sync_Logger();
	$this->mapper        = new Skwirrel_WC_Sync_Product_Mapper();
	$this->purge_handler = new Skwirrel_WC_Sync_Purge_Handler( $this->logger );

	// Nuke any leftover Skwirrel-tagged data from previous tests so count
	// assertions are independent. WP_UnitTestCase rolls back the parent
	// transaction, but WC writes to several side tables and caches that
	// don't always participate, leading to flaky count assertions.
	global $wpdb;
	$leftover_post_ids = $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		WHERE meta_key IN ('_skwirrel_external_id', '_skwirrel_grouped_product_id', '_skwirrel_synced_at')"
	);
	foreach ( $leftover_post_ids as $pid ) {
		wp_delete_post( (int) $pid, true );
	}
	$leftover_term_ids = $wpdb->get_col(
		"SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_skwirrel_category_id'"
	);
	foreach ( $leftover_term_ids as $tid ) {
		wp_delete_term( (int) $tid, 'product_cat' );
	}
});

/**
 * Helper: create a Skwirrel-managed simple product with a synced_at timestamp.
 *
 * @param string|null $synced_at Pass null to omit the meta entirely (simulates
 *                                a Skwirrel product that has never been synced),
 *                                or a string to set it (numeric or otherwise).
 */
function createPurgeProduct( string $external_id, ?string $synced_at = null ): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Purge test ' . $external_id );
	$product->set_sku( 'SKU-' . $external_id );
	$product->set_status( 'publish' );
	$wc_id = (int) $product->save();

	update_post_meta( $wc_id, '_skwirrel_external_id', $external_id );
	if ( null !== $synced_at ) {
		update_post_meta( $wc_id, '_skwirrel_synced_at', $synced_at );
	}

	return $wc_id;
}

/**
 * Helper: create a Skwirrel category (product_cat term) with the
 * `_skwirrel_category_id` term meta.
 */
function createPurgeCategory( string $name, string $skwirrel_id ): int {
	$term = wp_insert_term( $name, 'product_cat' );
	if ( is_wp_error( $term ) ) {
		throw new RuntimeException( 'failed to create category: ' . $term->get_error_message() );
	}
	$term_id = (int) $term['term_id'];
	update_term_meta( $term_id, '_skwirrel_category_id', $skwirrel_id );
	return $term_id;
}

// ------------------------------------------------------------------
// purge_stale_products()
// ------------------------------------------------------------------

test( 'purge_stale_products returns 0 when no stale products exist', function () {
	$now = time();
	createPurgeProduct( 'EXT-FRESH-1', (string) $now );
	createPurgeProduct( 'EXT-FRESH-2', (string) $now );

	$result = $this->purge_handler->purge_stale_products( $now - 10, $this->mapper );

	expect( $result )->toBe( 0 );
} );

test( 'purge_stale_products trashes products with synced_at older than threshold', function () {
	$now           = time();
	$stale_id      = createPurgeProduct( 'EXT-STALE', (string) ( $now - 1000 ) );
	$fresh_id      = createPurgeProduct( 'EXT-FRESH', (string) $now );

	$result = $this->purge_handler->purge_stale_products( $now - 500, $this->mapper );

	expect( $result )->toBe( 1 );
	expect( get_post_status( $stale_id ) )->toBe( 'trash' );
	expect( get_post_status( $fresh_id ) )->toBe( 'publish' );
} );

test( 'purge_stale_products trashes products with missing synced_at meta', function () {
	$id = createPurgeProduct( 'EXT-NOSYNC', null );

	$result = $this->purge_handler->purge_stale_products( time(), $this->mapper );

	expect( $result )->toBe( 1 );
	expect( get_post_status( $id ) )->toBe( 'trash' );
} );

test( 'purge_stale_products skips products with corrupt non-numeric synced_at meta', function () {
	// REGEXP '^[0-9]+$' guard: non-numeric meta values must be ignored, NOT
	// treated as 0 (which would compare-as-stale and incorrectly trash the
	// product). This is the primary safety check in the SQL.
	$now = time();
	$id  = createPurgeProduct( 'EXT-CORRUPT', 'not-a-timestamp' );

	$result = $this->purge_handler->purge_stale_products( $now, $this->mapper );

	expect( $result )->toBe( 0 );
	expect( get_post_status( $id ) )->toBe( 'publish' );
} );

test( 'purge_stale_products ignores non-Skwirrel products', function () {
	// Plain product with no _skwirrel_external_id should never be picked up.
	$plain = new WC_Product_Simple();
	$plain->set_name( 'Manual product' );
	$plain->set_status( 'publish' );
	$plain_id = (int) $plain->save();

	$stale_id = createPurgeProduct( 'EXT-STALE', '1' );

	$result = $this->purge_handler->purge_stale_products( time(), $this->mapper );

	expect( $result )->toBe( 1 );
	expect( get_post_status( $stale_id ) )->toBe( 'trash' );
	expect( get_post_status( $plain_id ) )->toBe( 'publish' );
} );

test( 'purge_stale_products skips products that are already trashed', function () {
	$id = createPurgeProduct( 'EXT-ALREADY-TRASHED', '1' );
	wp_trash_post( $id );

	$result = $this->purge_handler->purge_stale_products( time(), $this->mapper );

	expect( $result )->toBe( 0 );
} );

test( 'purge_stale_products cascades trash to variations of a stale variable parent', function () {
	$now = time();

	$variable = new WC_Product_Variable();
	$variable->set_name( 'Stale variable parent' );
	$variable->set_status( 'publish' );
	$parent_id = (int) $variable->save();

	update_post_meta( $parent_id, '_skwirrel_grouped_product_id', 'GRP-STALE' );
	update_post_meta( $parent_id, '_skwirrel_synced_at', (string) ( $now - 1000 ) );

	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_status( 'publish' );
	$variation_id = (int) $variation->save();

	$result = $this->purge_handler->purge_stale_products( $now - 500, $this->mapper );

	expect( $result )->toBe( 2 ); // parent + 1 variation
	expect( get_post_status( $parent_id ) )->toBe( 'trash' );
	expect( get_post_status( $variation_id ) )->toBe( 'trash' );
} );

// ------------------------------------------------------------------
// purge_stale_categories()
// ------------------------------------------------------------------

test( 'purge_stale_categories returns 0 when all Skwirrel categories are seen', function () {
	createPurgeCategory( 'Seen A', '101' );
	createPurgeCategory( 'Seen B', '102' );

	$result = $this->purge_handler->purge_stale_categories( [ '101', '102' ] );

	expect( $result )->toBe( 0 );
} );

test( 'purge_stale_categories deletes categories whose Skwirrel ID is not in the seen list', function () {
	$kept_term_id   = createPurgeCategory( 'Keep me', '201' );
	$stale_term_id  = createPurgeCategory( 'Drop me', '202' );

	$result = $this->purge_handler->purge_stale_categories( [ '201' ] );

	expect( $result )->toBe( 1 );
	expect( get_term( $kept_term_id, 'product_cat' ) )->not->toBeNull();
	expect( get_term( $stale_term_id, 'product_cat' ) )->toBeNull();
} );

test( 'purge_stale_categories does NOT delete a stale category that still has non-trashed products assigned', function () {
	// Safety check: the SQL counts non-trashed products in the term and
	// refuses to delete if any exist. Prevents data loss when a category
	// is reused outside the Skwirrel sync.
	$term_id = createPurgeCategory( 'Has products', '301' );

	$product = new WC_Product_Simple();
	$product->set_name( 'Manual product on category' );
	$product->set_status( 'publish' );
	$product->set_category_ids( [ $term_id ] );
	$product->save();

	$result = $this->purge_handler->purge_stale_categories( [] ); // 301 not seen

	expect( $result )->toBe( 0 );
	expect( get_term( $term_id, 'product_cat' ) )->not->toBeNull();
} );

test( 'purge_stale_categories ignores categories without _skwirrel_category_id meta', function () {
	$plain_term = wp_insert_term( 'Plain category', 'product_cat' );
	$plain_id   = (int) $plain_term['term_id'];

	$result = $this->purge_handler->purge_stale_categories( [] );

	expect( $result )->toBe( 0 );
	expect( get_term( $plain_id, 'product_cat' ) )->not->toBeNull();
} );
