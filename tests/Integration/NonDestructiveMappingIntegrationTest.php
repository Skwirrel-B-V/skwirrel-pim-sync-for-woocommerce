<?php
/**
 * Integration tests pinning NFR-9: a missing PIM value never clears WooCommerce data.
 *
 * These are integration tests by necessity, not by preference. The unit bootstrap's
 * WC_Product stub is read-mostly — it has no stock or content setters — so at the unit level
 * there is nowhere for a pre-existing WooCommerce value to live and "assert the existing value
 * survived" is unassertable. Fattening the stub would only prove the stub remembers what it was
 * told, which is precisely the failure mode this story exists to prevent.
 *
 * The cautionary example is `tests/Unit/ProductUpserterPriceTest.php`: titled as a
 * price-preservation test, but every assertion targets get_options() and no price is ever
 * written or read. Every test here exercises a real write path and asserts the stored value.
 *
 * Pinned here:
 *  AC-1  simple-product stock survives absent / empty / non-numeric mapped values
 *  AC-2  variation stock survives the same three, and siblings resolve independently
 *  AC-3  title/short/long fall back to their chains and are never written empty
 *  AC-4  an unconfigured mapping performs no write at all
 *  AC-5  a payload with no _custom_classes at all completes without notices
 *  AC-6  the price canary asserts the stored price, not the option value
 */

declare(strict_types=1);

beforeEach( function () {
	delete_option( 'skwirrel_wc_sync_settings' );
	delete_option( 'skwirrel_wc_sync_auth_token' );
	delete_option( 'skwirrel_wc_sync_last_sync' );
	delete_option( 'skwirrel_wc_sync_last_result' );
	delete_option( 'skwirrel_wc_sync_history' );
	delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
	delete_transient( Skwirrel_WC_Sync_History::SYNC_MUTEX );

	update_option( 'skwirrel_wc_sync_settings', [
		'endpoint_url'                    => 'https://test.skwirrel.example/jsonrpc',
		'auth_type'                       => 'bearer',
		'timeout'                         => 5,
		'retries'                         => 0,
		'batch_size'                      => 10,
		'collection_ids'                  => '1',
		'custom_collection_id'            => '1',
		'sync_categories'                 => false,
		'sync_grouped_products'           => false,
		'sync_custom_classes'             => false,
		'sync_trade_item_custom_classes'  => false,
		'sync_images'                     => false,
		'sync_related_products'           => false,
		'prices_managed_outside_skwirrel' => false,
		// All four FR-18/FR-19 mappings on, so "nothing was written" is a real result rather
		// than the trivial consequence of an unconfigured mapping.
		'stock_quantity_feature'          => '1234',
		'title_feature_id'                => '812',
		'short_description_feature_id'    => '813',
		'long_description_feature_id'     => '814',
	] );
	update_option( 'skwirrel_wc_sync_auth_token', 'test-token-123' );

	global $wpdb;
	$queue_table = $wpdb->prefix . 'skwirrel_sync_queue';
	$wpdb->query( "DELETE FROM {$queue_table}" ); // phpcs:ignore
	$leftover_post_ids = $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		WHERE meta_key IN ('_skwirrel_external_id', '_skwirrel_grouped_product_id', '_skwirrel_synced_at')"
	);
	foreach ( $leftover_post_ids as $pid ) {
		wp_delete_post( (int) $pid, true );
	}

	$this->upserter = ndm_upserter();
} );

afterEach( function () {
	remove_all_filters( 'pre_http_request' );
	delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
} );

/**
 * The upserter with its seven real collaborators, content mappings injected the way
 * Sync_Service::apply_status_handling() does at run start.
 */
function ndm_upserter(): Skwirrel_WC_Sync_Product_Upserter {
	$logger           = new Skwirrel_WC_Sync_Logger();
	$mapper           = new Skwirrel_WC_Sync_Product_Mapper();
	$lookup           = new Skwirrel_WC_Sync_Product_Lookup( $mapper );
	$brand_sync       = new Skwirrel_WC_Sync_Brand_Sync( $logger );
	$taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager( $logger );
	$category_sync    = new Skwirrel_WC_Sync_Category_Sync( $logger, $mapper );
	$slug_resolver    = new Skwirrel_WC_Sync_Slug_Resolver();

	$opts = get_option( 'skwirrel_wc_sync_settings', [] );
	$mapper->set_content_mapping(
		(string) ( $opts['title_feature_id'] ?? '' ),
		(string) ( $opts['short_description_feature_id'] ?? '' ),
		(string) ( $opts['long_description_feature_id'] ?? '' )
	);

	return new Skwirrel_WC_Sync_Product_Upserter(
		$logger,
		$mapper,
		$lookup,
		$category_sync,
		$brand_sync,
		$taxonomy_manager,
		$slug_resolver
	);
}

/**
 * One custom feature, in each of the four shapes the ACs enumerate.
 *
 * 'valid' is the control: it proves the test can detect a write at all. Without it, an
 * "unchanged" assertion passes equally well when the write path is simply broken.
 *
 * @param int    $id    Feature ID.
 * @param string $shape absent | empty | malformed | valid
 * @param string $type  Custom feature type.
 * @return array<string,mixed>|null Null means "omit this feature entirely".
 */
function ndm_feature( int $id, string $shape, string $type = 'N' ): ?array {
	if ( 'absent' === $shape ) {
		return null;
	}

	$feature = [
		'custom_feature_id'   => $id,
		'custom_feature_type' => $type,
	];

	$value_key = 'N' === $type ? 'numeric_value' : ( 'B' === $type ? 'big_text_value' : 'text_value' );

	if ( 'empty' === $shape ) {
		$feature[ $value_key ] = '';
		return $feature;
	}
	if ( 'malformed' === $shape ) {
		// A non-numeric stock value; for text mappings this is still a legitimate string, so
		// text mappings use the not_applicable route for their "malformed" case instead.
		$feature[ $value_key ] = 'op aanvraag';
		if ( 'N' !== $type ) {
			$feature['not_applicable'] = true;
		}
		return $feature;
	}

	$feature[ $value_key ] = 'N' === $type ? 42 : 'Mapped value';
	return $feature;
}

/**
 * A product payload, optionally carrying custom features.
 *
 * @param string                          $sku      SKU / external id.
 * @param array<int, array<string,mixed>> $features Feature payloads (nulls filtered out).
 * @param bool                            $omit_cc  Omit `_custom_classes` entirely (AC-5).
 * @param float|null                      $price    Net price; null means no price row.
 * @param bool                            $por      Flag the price row price-on-request.
 * @return array<string,mixed>
 */
function ndm_payload( string $sku, array $features = [], bool $omit_cc = false, ?float $price = 10.0, bool $por = false ): array {
	if ( $por ) {
		$prices = [ [ 'price_on_request' => true ] ];
	} elseif ( null === $price ) {
		$prices = [];
	} else {
		$prices = [ [ 'net_price' => $price ] ];
	}

	$payload = [
		'product_id'              => abs( crc32( $sku ) ) % 100000,
		'external_product_id'     => $sku,
		'internal_product_code'   => $sku,
		'product_erp_description' => 'ERP title for ' . $sku,
		'_product_translations'   => [
			[
				'language'                 => 'nl',
				'product_description'      => 'Chain short description',
				'product_long_description' => 'Chain long description',
			],
		],
		'_trade_items'            => [ [ '_trade_item_prices' => $prices ] ],
	];

	if ( ! $omit_cc ) {
		$features = array_values( array_filter( $features ) );
		if ( array() !== $features ) {
			$payload['_custom_classes'] = [
				[
					'custom_class_id'  => 9,
					'_custom_features' => $features,
				],
			];
		}
	}

	return $payload;
}

/**
 * Seed a real simple product carrying managed stock and content, findable by the upsert path.
 */
function ndm_seed_product( string $sku ): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Seeded title' );
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_short_description( 'Seeded excerpt' );
	$product->set_description( 'Seeded content' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 42 );
	$product->set_regular_price( '99' );
	$product->set_price( '99' );
	$id = $product->save();

	// Stamp the keys the upsert path looks products up by, so it updates rather than creates.
	update_post_meta( $id, '_skwirrel_external_id', 'ext:' . $sku );
	update_post_meta( $id, '_skwirrel_product_id', abs( crc32( $sku ) ) % 100000 );

	return $id;
}

// ------------------------------------------------------------------
// AC-1 — simple-product stock survives a missing mapped value
// ------------------------------------------------------------------

test( 'a simple product keeps its stock when the mapped value is absent, empty or non-numeric', function ( string $shape ) {
	$sku = 'NDM-AC1-' . $shape;
	$id  = ndm_seed_product( $sku );

	$this->upserter->create_or_update_product(
		ndm_payload( $sku, [ ndm_feature( 1234, $shape ) ] )
	);

	// Re-read fresh: the in-memory object we seeded would lie.
	$after = wc_get_product( $id );
	expect( $after->get_manage_stock() )->toBeTrue( "shape: {$shape}" );
	expect( (int) $after->get_stock_quantity() )->toBe( 42, "shape: {$shape}" );
} )->with( [ 'absent', 'empty', 'malformed' ] );

test( 'a simple product keeps its stock when custom classes omit the mapped feature', function () {
	$sku = 'NDM-AC1-unrelated-feature';
	$id  = ndm_seed_product( $sku );

	// AC-1's missing-feature case is distinct from AC-5: custom classes arrived, but none matches
	// the configured stock mapping.
	$this->upserter->create_or_update_product(
		ndm_payload( $sku, [ ndm_feature( 9999, 'valid' ) ] )
	);

	$after = wc_get_product( $id );
	expect( $after->get_manage_stock() )->toBeTrue();
	expect( (int) $after->get_stock_quantity() )->toBe( 42 );
} );

test( 'the control case proves the write path works — a valid value does land', function () {
	$sku = 'NDM-AC1-control';
	$id  = ndm_seed_product( $sku );

	$this->upserter->create_or_update_product(
		ndm_payload( $sku, [ ndm_feature( 1234, 'valid' ) ] )
	);

	$after = wc_get_product( $id );
	expect( (int) $after->get_stock_quantity() )->toBe( 42 );
	// Seeded value is also 42, so prove the write independently with a different quantity.
	$feature                  = ndm_feature( 1234, 'valid' );
	$feature['numeric_value'] = 7;
	$this->upserter->create_or_update_product( ndm_payload( $sku, [ $feature ] ) );

	$after = wc_get_product( $id );
	expect( (int) $after->get_stock_quantity() )->toBe( 7 );
} );

test( 'a value present only under trade-item custom classes resolves to nothing', function () {
	$sku = 'NDM-AC1-ti';
	$id  = ndm_seed_product( $sku );

	$payload                                        = ndm_payload( $sku );
	$payload['_trade_items'][0]['_trade_item_custom_classes'] = [
		[
			'custom_class_id'  => 9,
			'_custom_features' => [ ndm_feature( 1234, 'valid' ) ],
		],
	];

	$this->upserter->create_or_update_product( $payload );

	$after = wc_get_product( $id );
	expect( (int) $after->get_stock_quantity() )->toBe( 42 );
} );

// ------------------------------------------------------------------
// AC-2 — variation stock, and sibling independence
// ------------------------------------------------------------------

test( 'a variation keeps its stock when the mapped value is absent, empty or non-numeric', function ( string $shape ) {
	$parent = new WC_Product_Variable();
	$parent->set_name( 'NDM parent ' . $shape );
	$parent->set_sku( 'NDM-AC2-P-' . $shape );
	$parent->set_status( 'publish' );
	$parent_id = $parent->save();

	$sku = 'NDM-AC2-V-' . $shape;

	// Create the variation with a valid value, then hand-set a quantity a shop owner owns.
	$this->upserter->create_or_update_variation(
		ndm_payload( $sku, [ ndm_feature( 1234, 'valid' ) ] ),
		[ 'wc_variable_id' => $parent_id, 'sku' => $sku ]
	);
	$variation_id = wc_get_product_id_by_sku( $sku );
	$seed         = wc_get_product( $variation_id );
	$seed->set_manage_stock( true );
	$seed->set_stock_quantity( 42 );
	$seed->save();

	$this->upserter->create_or_update_variation(
		ndm_payload( $sku, [ ndm_feature( 1234, $shape ) ] ),
		[ 'wc_variable_id' => $parent_id, 'sku' => $sku ]
	);

	$after = wc_get_product( $variation_id );
	expect( $after->get_manage_stock() )->toBeTrue( "shape: {$shape}" );
	expect( (int) $after->get_stock_quantity() )->toBe( 42, "shape: {$shape}" );
} )->with( [ 'absent', 'empty', 'malformed' ] );

test( 'the legacy variation path keeps its stock when the mapped value is absent', function () {
	$parent = new WC_Product_Variable();
	$parent->set_name( 'NDM legacy parent' );
	$parent->set_sku( 'NDM-AC2-LEGACY-P' );
	$parent->set_status( 'publish' );
	$parent_id = $parent->save();
	$sku       = 'NDM-AC2-LEGACY';
	$group     = [ 'wc_variable_id' => $parent_id, 'sku' => $sku ];

	$this->upserter->upsert_product_as_variation(
		ndm_payload( $sku, [ ndm_feature( 1234, 'valid' ) ] ),
		$group
	);
	$variation_id = wc_get_product_id_by_sku( $sku );
	$seed         = wc_get_product( $variation_id );
	$seed->set_manage_stock( true );
	$seed->set_stock_quantity( 42 );
	$seed->save();

	$this->upserter->upsert_product_as_variation(
		ndm_payload( $sku, [ ndm_feature( 1234, 'absent' ) ] ),
		$group
	);

	$after = wc_get_product( $variation_id );
	expect( $after->get_manage_stock() )->toBeTrue();
	expect( (int) $after->get_stock_quantity() )->toBe( 42 );
} );

test( 'a grouped-product sync preserves an unresolved variation while its sibling still resolves', function () {
	$settings                         = get_option( 'skwirrel_wc_sync_settings', [] );
	$settings['sync_grouped_products'] = true;
	update_option( 'skwirrel_wc_sync_settings', $settings );

	$missing_sku = 'NDM-AC2-GROUP-A';
	$valid_sku   = 'NDM-AC2-GROUP-B';
	$missing     = ndm_payload( $missing_sku, [ ndm_feature( 1234, 'valid' ) ] );
	$valid       = ndm_payload( $valid_sku, [ ndm_feature( 1234, 'valid' ) ] );
	$valid['_custom_classes'][0]['_custom_features'][0]['numeric_value'] = 9;
	$members = [ $missing, $valid ];
	$group   = [
		'grouped_product_id'   => 6404,
		'grouped_product_name' => 'NDM grouped parent',
		'grouped_product_code' => 'NDM-AC2-GROUP-P',
		'_products'            => [
			[ 'product_id' => $missing['product_id'], 'internal_product_code' => $missing_sku, 'order' => 1 ],
			[ 'product_id' => $valid['product_id'], 'internal_product_code' => $valid_sku, 'order' => 2 ],
		],
	];

	add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$members, $group ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}

		$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
		$method = $body['method'] ?? '';
		$id     = $body['id'] ?? 1;
		$result = 'getGroupedProducts' === $method
			? [ 'grouped_products' => [ $group ], 'page' => [ 'current_page' => 1, 'number_of_pages' => 1 ] ]
			: ( 'getProductsByFilter' === $method ? [ 'products' => $members ] : [] );

		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$service = new Skwirrel_WC_Sync_Service();
	expect( $service->sync_single_grouped_product( 6404 )['success'] )->toBeTrue();

	$missing_id = wc_get_product_id_by_sku( $missing_sku );
	$seed       = wc_get_product( $missing_id );
	$seed->set_manage_stock( true );
	$seed->set_stock_quantity( 42 );
	$seed->save();

	// The same grouped route receives no stock for A and a changed valid value for B.
	$members[0] = ndm_payload( $missing_sku, [ ndm_feature( 1234, 'absent' ) ] );
	expect( $service->sync_single_grouped_product( 6404 )['success'] )->toBeTrue();

	$after_missing = wc_get_product( $missing_id );
	$after_valid   = wc_get_product( wc_get_product_id_by_sku( $valid_sku ) );
	expect( $after_missing->get_manage_stock() )->toBeTrue();
	expect( (int) $after_missing->get_stock_quantity() )->toBe( 42 );
	expect( $after_valid->get_manage_stock() )->toBeTrue();
	expect( (int) $after_valid->get_stock_quantity() )->toBe( 9 );
} );

test( 'one variation missing a value does not suppress its siblings', function () {
	$parent = new WC_Product_Variable();
	$parent->set_name( 'NDM sibling parent' );
	$parent->set_sku( 'NDM-AC2-SIB-P' );
	$parent->set_status( 'publish' );
	$parent_id = $parent->save();

	$missing        = ndm_payload( 'NDM-AC2-SIB-A', [ ndm_feature( 1234, 'absent' ) ] );
	$resolving_feat = ndm_feature( 1234, 'valid' );
	$resolving_feat['numeric_value'] = 9;
	$resolving      = ndm_payload( 'NDM-AC2-SIB-B', [ $resolving_feat ] );

	$this->upserter->create_or_update_variation( $missing, [ 'wc_variable_id' => $parent_id, 'sku' => 'NDM-AC2-SIB-A' ] );
	$this->upserter->create_or_update_variation( $resolving, [ 'wc_variable_id' => $parent_id, 'sku' => 'NDM-AC2-SIB-B' ] );

	$a = wc_get_product( wc_get_product_id_by_sku( 'NDM-AC2-SIB-A' ) );
	$b = wc_get_product( wc_get_product_id_by_sku( 'NDM-AC2-SIB-B' ) );

	expect( $a->get_manage_stock() )->toBeFalse();
	expect( $b->get_manage_stock() )->toBeTrue();
	expect( (int) $b->get_stock_quantity() )->toBe( 9 );
} );

test( 'price on request still ends out of stock even with a valid stock value', function () {
	$parent = new WC_Product_Variable();
	$parent->set_name( 'NDM POR parent' );
	$parent->set_sku( 'NDM-AC2-POR-P' );
	$parent->set_status( 'publish' );
	$parent_id = $parent->save();

	$this->upserter->create_or_update_variation(
		ndm_payload( 'NDM-AC2-POR', [ ndm_feature( 1234, 'valid' ) ], false, null, true ),
		[ 'wc_variable_id' => $parent_id, 'sku' => 'NDM-AC2-POR' ]
	);

	$variation = wc_get_product( wc_get_product_id_by_sku( 'NDM-AC2-POR' ) );
	expect( $variation->get_stock_status() )->toBe( 'outofstock' );
} );

// ------------------------------------------------------------------
// AC-3 — content falls back, and is never written empty
// ------------------------------------------------------------------

test( 'content falls back to its chain when the mapped feature is absent or empty', function ( string $shape ) {
	$sku = 'NDM-AC3-' . $shape;
	$id  = ndm_seed_product( $sku );

	$this->upserter->create_or_update_product(
		ndm_payload(
			$sku,
			[
				ndm_feature( 812, $shape, 'T' ),
				ndm_feature( 813, $shape, 'T' ),
				ndm_feature( 814, $shape, 'B' ),
			]
		)
	);

	$after = wc_get_product( $id );

	// The chain applies — not the mapping, and above all not an empty string.
	expect( $after->get_name() )->toBe( 'ERP title for ' . $sku, "shape: {$shape}" );
	expect( $after->get_short_description() )->toBe( 'Chain short description', "shape: {$shape}" );
	expect( $after->get_description() )->toBe( 'Chain long description', "shape: {$shape}" );

	// The specific NFR-9 failure this AC names: a blank title.
	expect( $after->get_name() )->not->toBe( '' );
} )->with( [ 'absent', 'empty', 'malformed' ] );

test( 'resolved title, short-description and long-description values each win — the controls for AC-3', function () {
	$sku = 'NDM-AC3-control';
	$id  = ndm_seed_product( $sku );

	$this->upserter->create_or_update_product(
		ndm_payload(
			$sku,
			[
				ndm_feature( 812, 'valid', 'T' ),
				ndm_feature( 813, 'valid', 'T' ),
				ndm_feature( 814, 'valid', 'B' ),
			]
		)
	);

	$after = wc_get_product( $id );
	expect( $after->get_name() )->toBe( 'Mapped value' );
	expect( $after->get_short_description() )->toBe( 'Mapped value' );
	expect( $after->get_description() )->toBe( 'Mapped value' );
} );

// ------------------------------------------------------------------
// AC-4 — the mappings are independent, and an unconfigured one writes nothing
// ------------------------------------------------------------------

test( 'with only the long-description mapping configured other mapping values cannot override their fallback chains', function () {
	$settings                                 = get_option( 'skwirrel_wc_sync_settings', [] );
	$settings['stock_quantity_feature']       = '';
	$settings['title_feature_id']             = '';
	$settings['short_description_feature_id'] = '';
	$settings['long_description_feature_id']  = '814';
	update_option( 'skwirrel_wc_sync_settings', $settings );

	$upserter = ndm_upserter();
	$sku      = 'NDM-AC4';
	$id       = ndm_seed_product( $sku );

	// The payload carries values for ALL four features, so this proves the three unconfigured
	// mappings performed no write — rather than merely that empty input produced no change.
	$upserter->create_or_update_product(
		ndm_payload(
			$sku,
			[
				ndm_feature( 1234, 'valid' ),
				ndm_feature( 812, 'valid', 'T' ),
				ndm_feature( 813, 'valid', 'T' ),
				ndm_feature( 814, 'valid', 'B' ),
			]
		)
	);

	$after = wc_get_product( $id );

	// Stock mapping off: the seeded managed stock survives untouched.
	expect( $after->get_manage_stock() )->toBeTrue();
	expect( (int) $after->get_stock_quantity() )->toBe( 42 );
	// Title and short description fall to their chains, not to the mapped value.
	expect( $after->get_name() )->toBe( 'ERP title for ' . $sku );
	expect( $after->get_short_description() )->toBe( 'Chain short description' );
	// Only the configured mapping took effect.
	expect( $after->get_description() )->toBe( 'Mapped value' );
} );

// ------------------------------------------------------------------
// AC-5 — a payload with no custom classes at all
// ------------------------------------------------------------------

test( 'a full sync with no _custom_classes key succeeds, preserves every mapped field and raises no notice', function () {
	$sku = 'NDM-AC5';
	$id  = ndm_seed_product( $sku );
	$payload = ndm_payload( $sku, [], true );
	$payload['product_type']    = 'STANDARD';
	$payload['_product_status'] = [ 'product_status_description' => 'active' ];

	add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $payload ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}

		$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
		$method = $body['method'] ?? '';
		$params = $body['params'] ?? [];
		$id     = $body['id'] ?? 1;
		$result = [];

		if ( 'getBrands' === $method ) {
			$result = [ 'brands' => [] ];
		} elseif ( 'getProductsByFilter' === $method ) {
			if ( isset( $params['filter']['code']['type'] ) && 'product_id' === $params['filter']['code']['type'] ) {
				$result = [ 'products' => [ $payload ] ];
			} elseif ( skwIsSweepCall( $params ) ) {
				$result = [
					'products' => [ [ 'product_id' => $payload['product_id'] ] ],
					'page'     => [ 'current_page' => 1, 'number_of_pages' => 1 ],
				];
			} elseif ( 1 === (int) ( $params['page'] ?? 1 ) ) {
				$result = [ 'products' => [ $payload ] ];
			} else {
				$result = [ 'products' => [] ];
			}
		}

		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$raised = [];
	set_error_handler(
		static function ( int $errno, string $errstr ) use ( &$raised ): bool {
			$raised[] = $errstr;
			return true;
		},
		E_ALL
	);

	try {
		$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );
	} finally {
		restore_error_handler();
	}

	expect( $result['success'] )->toBeTrue();
	expect( $result['failed'] )->toBe( 0 );
	expect( $raised )->toBe( [] );

	$after = wc_get_product( $id );
	expect( $after->get_manage_stock() )->toBeTrue();
	expect( (int) $after->get_stock_quantity() )->toBe( 42 );
	expect( $after->get_name() )->toBe( 'ERP title for ' . $sku );
	expect( $after->get_short_description() )->toBe( 'Chain short description' );
	expect( $after->get_description() )->toBe( 'Chain long description' );
} );

// ------------------------------------------------------------------
// AC-6 — the price canary asserts the stored price, not the option value
// ------------------------------------------------------------------

test( 'with prices managed outside Skwirrel an existing variation price survives a priceless payload', function () {
	$settings                                    = get_option( 'skwirrel_wc_sync_settings', [] );
	$settings['prices_managed_outside_skwirrel'] = true;
	update_option( 'skwirrel_wc_sync_settings', $settings );
	$upserter = ndm_upserter();

	$parent = new WC_Product_Variable();
	$parent->set_name( 'NDM price parent' );
	$parent->set_sku( 'NDM-AC6-EXT-P' );
	$parent->set_status( 'publish' );
	$parent_id = $parent->save();

	$sku = 'NDM-AC6-EXT';
	$upserter->create_or_update_variation(
		ndm_payload( $sku, [], false, 55.0 ),
		[ 'wc_variable_id' => $parent_id, 'sku' => $sku ]
	);
	$variation_id = wc_get_product_id_by_sku( $sku );
	expect( (float) wc_get_product( $variation_id )->get_regular_price() )->toBe( 55.0 );

	// Now a payload with no price at all: the ERP's price must survive.
	$upserter->create_or_update_variation(
		ndm_payload( $sku, [], false, null ),
		[ 'wc_variable_id' => $parent_id, 'sku' => $sku ]
	);

	expect( (float) wc_get_product( $variation_id )->get_regular_price() )->toBe( 55.0 );

	// `price_on_request` is another way the PIM has no price to contribute. The external system's
	// price survives, while the variation keeps its explicit availability semantics.
	$upserter->create_or_update_variation(
		ndm_payload( $sku, [], false, null, true ),
		[ 'wc_variable_id' => $parent_id, 'sku' => $sku ]
	);

	expect( (float) wc_get_product( $variation_id )->get_regular_price() )->toBe( 55.0 );
} );

test( 'with the flag off a priceless payload still falls to the documented zero branch', function () {
	// Today's documented behaviour, asserted as behaviour so an accidental change is caught.
	$parent = new WC_Product_Variable();
	$parent->set_name( 'NDM zero parent' );
	$parent->set_sku( 'NDM-AC6-ZERO-P' );
	$parent->set_status( 'publish' );
	$parent_id = $parent->save();

	$sku = 'NDM-AC6-ZERO';
	$this->upserter->create_or_update_variation(
		ndm_payload( $sku, [], false, 55.0 ),
		[ 'wc_variable_id' => $parent_id, 'sku' => $sku ]
	);
	$variation_id = wc_get_product_id_by_sku( $sku );

	$this->upserter->create_or_update_variation(
		ndm_payload( $sku, [], false, null ),
		[ 'wc_variable_id' => $parent_id, 'sku' => $sku ]
	);

	expect( (float) wc_get_product( $variation_id )->get_regular_price() )->toBe( 0.0 );
} );
