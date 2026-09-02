<?php
/**
 * Integration tests for stock quantity on variations (Story 6.2).
 *
 * These run against real WooCommerce data stores, because the thing under test is what
 * actually lands on a WC_Product_Variation after save — the unit suite's stub WC_Product
 * has no stock setters, so it cannot answer that.
 *
 * Pinned here:
 *  1. A resolved quantity lands on the variation as managed stock (AC 1).
 *  2. A configured mapping that resolves nothing leaves an existing quantity exactly as it
 *     was — not zeroed, not flipped to unmanaged, not forced to instock (AC 5, NFR-9).
 *  3. Siblings are independent: one resolves, one does not, neither affects the other (AC 6).
 *  4. A price-on-request variation still ends up out of stock (AC 7).
 *  5. The variable parent's aggregate status comes from the existing sync_stock_status()
 *     call, and the parent itself stays unmanaged (AC 8).
 *  6. With the mapping unconfigured, both variation paths behave exactly as before (AC 4).
 */

declare(strict_types=1);

/**
 * Build the upserter with its seven real collaborators.
 */
function stock_upserter(): Skwirrel_WC_Sync_Product_Upserter {
	$logger           = new Skwirrel_WC_Sync_Logger();
	$mapper           = new Skwirrel_WC_Sync_Product_Mapper();
	$lookup           = new Skwirrel_WC_Sync_Product_Lookup( $mapper );
	$brand_sync       = new Skwirrel_WC_Sync_Brand_Sync( $logger );
	$taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager( $logger );
	$category_sync    = new Skwirrel_WC_Sync_Category_Sync( $logger, $mapper );
	$slug_resolver    = new Skwirrel_WC_Sync_Slug_Resolver();

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
 * A Skwirrel product payload for one variation.
 *
 * @param string     $sku              Variation SKU.
 * @param float|null $stock            Value for the mapped feature; null omits the feature entirely.
 * @param float|null $price            Net price; null means "no price".
 * @param bool       $price_on_request Flag the price row as price-on-request. Note this needs an
 *                                     explicit flag on a price row — merely omitting the price is
 *                                     "no price", which is a different branch entirely.
 * @return array<string, mixed>
 */
function stock_payload( string $sku, ?float $stock, ?float $price = 10.0, bool $price_on_request = false ): array {
	if ( $price_on_request ) {
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
		'product_erp_description' => 'Variation ' . $sku,
		'_trade_items'            => [
			[ '_trade_item_prices' => $prices ],
		],
	];

	if ( null !== $stock ) {
		$payload['_custom_classes'] = [
			[
				'custom_class_id'  => 7,
				'_custom_features' => [
					[
						'custom_feature_id'   => 1234,
						'custom_feature_code' => 'STOCK_QTY',
						'custom_feature_type' => 'N',
						'numeric_value'       => $stock,
					],
				],
			],
		];
	}

	return $payload;
}

/**
 * Create a bare variable parent product.
 */
function stock_variable_parent( string $sku ): int {
	$parent = new WC_Product_Variable();
	$parent->set_name( 'Parent ' . $sku );
	$parent->set_sku( $sku );
	$parent->set_status( 'publish' );
	$parent->set_stock_status( 'instock' );
	$parent->set_manage_stock( false );
	return $parent->save();
}

beforeEach( function () {
	delete_option( 'skwirrel_wc_sync_settings' );

	update_option( 'skwirrel_wc_sync_settings', [
		'endpoint_url'                    => 'https://test.skwirrel.example/jsonrpc',
		'batch_size'                      => 10,
		'sync_categories'                 => false,
		'sync_images'                     => false,
		'sync_grouped_products'           => true,
		'prices_managed_outside_skwirrel' => false,
		'stock_quantity_feature'          => '1234',
	] );

	$this->upserter = stock_upserter();
} );

afterEach( function () {
	delete_option( 'skwirrel_wc_sync_settings' );
} );

// ------------------------------------------------------------------
// AC 1 — a resolved quantity lands as managed stock
// ------------------------------------------------------------------

test( 'a resolved quantity lands on the variation as managed stock', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC1' );

	$this->upserter->create_or_update_variation(
		stock_payload( 'VAR-AC1', 42.0 ),
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC1' ]
	);

	$variation_id = wc_get_product_id_by_sku( 'VAR-AC1' );
	expect( $variation_id )->toBeGreaterThan( 0 );

	$variation = wc_get_product( $variation_id );
	expect( $variation->get_manage_stock() )->toBeTrue();
	expect( (float) $variation->get_stock_quantity() )->toBe( 42.0 );
} );

// ------------------------------------------------------------------
// AC 5 — NFR-9: a configured mapping that resolves nothing changes nothing
// ------------------------------------------------------------------

test( 'a variation with no mapped value keeps its existing stock exactly', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC5' );

	// Seed the variation with a hand-managed quantity a shop owner set themselves.
	$this->upserter->create_or_update_variation(
		stock_payload( 'VAR-AC5', 15.0 ),
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC5' ]
	);
	$variation_id = wc_get_product_id_by_sku( 'VAR-AC5' );

	$seed = wc_get_product( $variation_id );
	$seed->set_manage_stock( true );
	$seed->set_stock_quantity( 7 );
	$seed->save();

	// Re-sync with the feature entirely absent from the payload.
	$this->upserter->create_or_update_variation(
		stock_payload( 'VAR-AC5', null ),
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC5' ]
	);

	$after = wc_get_product( $variation_id );
	expect( $after->get_manage_stock() )->toBeTrue();
	expect( (float) $after->get_stock_quantity() )->toBe( 7.0 );
} );

test( 'a non-numeric mapped value does not zero the variation stock', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC5B' );

	$payload = stock_payload( 'VAR-AC5B', 20.0 );
	$this->upserter->create_or_update_variation(
		$payload,
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC5B' ]
	);
	$variation_id = wc_get_product_id_by_sku( 'VAR-AC5B' );

	// Same feature, now carrying text instead of a number.
	$payload['_custom_classes'][0]['_custom_features'][0] = [
		'custom_feature_id'   => 1234,
		'custom_feature_type' => 'T',
		'text_value'          => 'op voorraad',
	];
	$this->upserter->create_or_update_variation(
		$payload,
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC5B' ]
	);

	$after = wc_get_product( $variation_id );
	expect( (float) $after->get_stock_quantity() )->toBe( 20.0 );
	expect( $after->get_stock_status() )->not->toBe( 'outofstock' );
} );

// ------------------------------------------------------------------
// AC 6 — siblings are independent
// ------------------------------------------------------------------

test( 'one sibling resolves stock while the other is left untouched', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC6' );
	$group     = [ 'wc_variable_id' => $parent_id ];

	$this->upserter->create_or_update_variation(
		stock_payload( 'VAR-AC6-A', 5.0 ),
		$group + [ 'sku' => 'VAR-AC6-A' ]
	);
	$this->upserter->create_or_update_variation(
		stock_payload( 'VAR-AC6-B', null ),
		$group + [ 'sku' => 'VAR-AC6-B' ]
	);

	$a = wc_get_product( wc_get_product_id_by_sku( 'VAR-AC6-A' ) );
	$b = wc_get_product( wc_get_product_id_by_sku( 'VAR-AC6-B' ) );

	expect( $a->get_manage_stock() )->toBeTrue();
	expect( (float) $a->get_stock_quantity() )->toBe( 5.0 );

	// The sibling that resolved nothing was never given managed stock.
	expect( $b->get_manage_stock() )->toBeFalse();
} );

// ------------------------------------------------------------------
// AC 7 — price on request stays out of stock
// ------------------------------------------------------------------

test( 'a price-on-request variation ends up out of stock whatever quantity resolves', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC7' );

	// Price on request, plus a stock value that would otherwise resolve to a managed quantity.
	$payload = stock_payload( 'VAR-AC7', 99.0, null, true );

	$this->upserter->create_or_update_variation(
		$payload,
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC7' ]
	);

	$variation = wc_get_product( wc_get_product_id_by_sku( 'VAR-AC7' ) );
	expect( $variation->get_stock_status() )->toBe( 'outofstock' );
} );

test( 'a price-on-request SIMPLE product is not made available by a mapped quantity', function () {
	// Simple products and variations reach the same conclusion by different routes. A variation
	// carries a pre-existing explicit outofstock write; a simple product has never had its stock
	// touched for price-on-request, so the mapping is skipped and WooCommerce is left exactly as
	// it was. What must NOT happen either way is a quantity turning a priceless product available.
	$payload = stock_payload( 'SIMPLE-AC7-POR', 99.0, null, true );

	$this->upserter->upsert_product( $payload );

	$product = wc_get_product( wc_get_product_id_by_sku( 'SIMPLE-AC7-POR' ) );
	expect( $product )->not->toBeFalse();
	expect( $product->get_manage_stock() )->toBeFalse();
	expect( $product->get_stock_quantity() )->toBeNull();
} );

test( 'a priced simple product still receives its mapped quantity', function () {
	// The control: skipping price-on-request must not have disabled the mapping generally.
	$payload = stock_payload( 'SIMPLE-AC7-PRICED', 42.0, 10.0 );

	$this->upserter->upsert_product( $payload );

	$product = wc_get_product( wc_get_product_id_by_sku( 'SIMPLE-AC7-PRICED' ) );
	expect( $product->get_manage_stock() )->toBeTrue();
	expect( (int) $product->get_stock_quantity() )->toBe( 42 );
	expect( $product->get_stock_status() )->toBe( 'instock' );
} );

test( 'the legacy variation path also writes no price when prices are managed outside Skwirrel', function () {
	// The fourth write path. The other three are covered above; this one is reached by grouped
	// products arriving as members rather than through create_or_update_variation(), and it was the
	// one with no external-price coverage at all.
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings', [] ),
		[ 'prices_managed_outside_skwirrel' => true ]
	) );
	$upserter = stock_upserter();

	$parent_id = stock_variable_parent( 'PARENT-EXT-LEGACY' );
	$group     = [ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-EXT-LEGACY' ];

	// A payload carrying a price: nothing is written, because the ERP owns the field.
	$upserter->upsert_product_as_variation( stock_payload( 'VAR-EXT-LEGACY', null, 77.0 ), $group );

	$variation_id = wc_get_product_id_by_sku( 'VAR-EXT-LEGACY' );
	expect( wc_get_product( $variation_id )->get_regular_price() )->toBe( '' );

	// The ERP sets its price, then the PIM turns the item price-on-request. Availability changes;
	// the price does not.
	$erp = wc_get_product( $variation_id );
	$erp->set_regular_price( '77' );
	$erp->set_price( '77' );
	$erp->save();

	$upserter->upsert_product_as_variation( stock_payload( 'VAR-EXT-LEGACY', null, null, true ), $group );

	$after = wc_get_product( $variation_id );
	expect( (float) $after->get_regular_price() )->toBe( 77.0 );
	expect( $after->get_stock_status() )->toBe( 'outofstock' );
} );

test( 'with prices managed outside Skwirrel the plugin writes no price at all', function () {
	// The setting's contract, verified against real WooCommerce: not the PIM price, not the
	// price-on-request blank, not the missing-price zero. An external system owns the field.
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings', [] ),
		[ 'prices_managed_outside_skwirrel' => true ]
	) );
	$upserter = stock_upserter();

	// Seed a product carrying a price the ERP put there.
	$seed = new WC_Product_Simple();
	$seed->set_name( 'ERP priced' );
	$seed->set_sku( 'EXT-PRICE-POR' );
	$seed->set_status( 'publish' );
	$seed->set_regular_price( '249.50' );
	$seed->set_price( '249.50' );
	$id = $seed->save();
	update_post_meta( $id, '_skwirrel_external_id', 'ext:EXT-PRICE-POR' );
	update_post_meta( $id, '_skwirrel_product_id', abs( crc32( 'EXT-PRICE-POR' ) ) % 100000 );

	// The PIM now says price-on-request — which used to blank the price on this path.
	$payload = stock_payload( 'EXT-PRICE-POR', null, null, true );
	$upserter->upsert_product( $payload );

	$after = wc_get_product( wc_get_product_id_by_sku( 'EXT-PRICE-POR' ) );
	expect( $after->get_regular_price() )->toBe( '249.50' );
} );

test( 'with prices managed outside Skwirrel a PIM price does not overwrite the ERP price', function () {
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings', [] ),
		[ 'prices_managed_outside_skwirrel' => true ]
	) );
	$upserter = stock_upserter();

	$seed = new WC_Product_Simple();
	$seed->set_name( 'ERP priced 2' );
	$seed->set_sku( 'EXT-PRICE-PIM' );
	$seed->set_status( 'publish' );
	$seed->set_regular_price( '99.00' );
	$seed->set_price( '99.00' );
	$id = $seed->save();
	update_post_meta( $id, '_skwirrel_external_id', 'ext:EXT-PRICE-PIM' );
	update_post_meta( $id, '_skwirrel_product_id', abs( crc32( 'EXT-PRICE-PIM' ) ) % 100000 );

	// The PIM has a price of its own. The ERP still owns the field.
	$upserter->upsert_product( stock_payload( 'EXT-PRICE-PIM', null, 12.34 ) );

	$after = wc_get_product( wc_get_product_id_by_sku( 'EXT-PRICE-PIM' ) );
	expect( $after->get_regular_price() )->toBe( '99.00' );
} );

test( 'a queued variation that becomes price on request is no longer stock managed', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC7-QUEUED' );
	$group     = [ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC7-QUEUED' ];

	$this->upserter->create_or_update_variation( stock_payload( 'VAR-AC7-QUEUED', 99.0 ), $group );
	$this->upserter->create_or_update_variation( stock_payload( 'VAR-AC7-QUEUED', 99.0, null, true ), $group );

	$variation = wc_get_product( wc_get_product_id_by_sku( 'VAR-AC7-QUEUED' ) );
	expect( $variation->get_manage_stock() )->toBeFalse();
	expect( $variation->get_stock_status() )->toBe( 'outofstock' );
} );

test( 'a legacy variation that becomes price on request is no longer stock managed', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC7-LEGACY' );
	$group     = [ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC7-LEGACY' ];

	$this->upserter->upsert_product_as_variation( stock_payload( 'VAR-AC7-LEGACY', 99.0 ), $group );
	$this->upserter->upsert_product_as_variation( stock_payload( 'VAR-AC7-LEGACY', 99.0, null, true ), $group );

	$variation = wc_get_product( wc_get_product_id_by_sku( 'VAR-AC7-LEGACY' ) );
	expect( $variation->get_manage_stock() )->toBeFalse();
	expect( $variation->get_stock_status() )->toBe( 'outofstock' );
} );

// ------------------------------------------------------------------
// AC 9 — a payload-hash mismatch must pass the default observe-mode gate
// ------------------------------------------------------------------

test( 'an observe-mode hash mismatch updates a variation despite an unchanged timestamp', function () {
	$this->upserter->set_change_gate_enabled( true );
	$this->upserter->set_content_hash_context( 'observe', 'variation-stock-delta' );

	$parent_id = stock_variable_parent( 'PARENT-AC9' );
	$group     = [ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC9' ];
	$before    = stock_payload( 'VAR-AC9', 3.0 );
	$before['product_updated_on'] = '2026-08-26T12:00:00Z';

	$this->upserter->create_or_update_variation( $before, $group );
	$variation_id = wc_get_product_id_by_sku( 'VAR-AC9' );
	update_post_meta( $variation_id, Skwirrel_WC_Sync_Product_Mapper::UPDATED_ON_META, $before['product_updated_on'] );
	update_post_meta( $variation_id, Skwirrel_WC_Sync_Product_Upserter::CONTENT_HASH_META, $this->upserter->payload_signature( $before ) );

	$after = stock_payload( 'VAR-AC9', 8.0 );
	$after['product_updated_on'] = $before['product_updated_on'];
	$result = $this->upserter->create_or_update_variation( $after, $group );

	expect( $result['outcome'] )->toBe( 'updated' );
	expect( (float) wc_get_product( $variation_id )->get_stock_quantity() )->toBe( 8.0 );
} );

// ------------------------------------------------------------------
// AC 8 — parent aggregation via the existing mechanism
// ------------------------------------------------------------------

test( 'the variable parent stays unmanaged and aggregates from its children', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC8' );

	$this->upserter->create_or_update_variation(
		stock_payload( 'VAR-AC8', 3.0 ),
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC8' ]
	);

	WC_Product_Variable::sync_stock_status( $parent_id );

	$parent = wc_get_product( $parent_id );
	expect( $parent->get_manage_stock() )->toBeFalse();
	expect( $parent->get_stock_status() )->toBe( 'instock' );
} );

// ------------------------------------------------------------------
// AC 4 — unconfigured mapping is today's behaviour
// ------------------------------------------------------------------

test( 'with the mapping off a priced variation is unmanaged and in stock, as before', function () {
	$settings                           = get_option( 'skwirrel_wc_sync_settings', [] );
	$settings['stock_quantity_feature'] = '';
	update_option( 'skwirrel_wc_sync_settings', $settings );

	$parent_id = stock_variable_parent( 'PARENT-AC4' );

	// The payload still carries a value; with the mapping off it must be ignored entirely.
	$this->upserter->create_or_update_variation(
		stock_payload( 'VAR-AC4', 42.0 ),
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC4' ]
	);

	$variation = wc_get_product( wc_get_product_id_by_sku( 'VAR-AC4' ) );
	expect( $variation->get_manage_stock() )->toBeFalse();
	expect( $variation->get_stock_status() )->toBe( 'instock' );
} );

// ------------------------------------------------------------------
// AC 2 — the legacy variation path behaves identically
// ------------------------------------------------------------------

test( 'the legacy upsert_product_as_variation path also writes mapped stock', function () {
	$parent_id = stock_variable_parent( 'PARENT-AC2' );

	$this->upserter->upsert_product_as_variation(
		stock_payload( 'VAR-AC2', 11.0 ),
		[ 'wc_variable_id' => $parent_id, 'sku' => 'VAR-AC2' ]
	);

	$variation = wc_get_product( wc_get_product_id_by_sku( 'VAR-AC2' ) );
	expect( $variation->get_manage_stock() )->toBeTrue();
	expect( (float) $variation->get_stock_quantity() )->toBe( 11.0 );
} );
