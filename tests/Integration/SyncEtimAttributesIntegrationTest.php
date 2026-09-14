<?php
/**
 * Integration tests for `sync_etim`: turning it off must remove ETIM attributes that an earlier
 * sync wrote — including when nothing else is left, which used to skip the attribute write and
 * keep the stale set forever. Asserts the stored `_product_attributes` AND the pa_* term
 * relationships, since layered-nav filters read the latter.
 */

declare(strict_types=1);

beforeEach( function () {
	skwPurgeSkwirrelPosts();
	update_option( 'skwirrel_wc_sync_settings', seam_settings( true ) );
} );

afterEach( function () {
	skwPurgeSkwirrelPosts();
	delete_option( 'skwirrel_wc_sync_settings' );
} );

function seam_settings( bool $sync_etim ): array {
	return [
		'endpoint_url'          => 'https://test.skwirrel.example/jsonrpc',
		'sync_etim'             => $sync_etim,
		'sync_grouped_products' => false,
		'sync_custom_classes'   => false,
		'sync_images'           => false,
		'sync_manufacturers'    => true, // Keeps Manufacturer out of the attributes.
		'image_language'        => 'nl',
	];
}

function seam_upserter(): Skwirrel_WC_Sync_Product_Upserter {
	$logger = new Skwirrel_WC_Sync_Logger();
	$mapper = new Skwirrel_WC_Sync_Product_Mapper();

	return new Skwirrel_WC_Sync_Product_Upserter(
		$logger,
		$mapper,
		new Skwirrel_WC_Sync_Product_Lookup( $mapper ),
		new Skwirrel_WC_Sync_Category_Sync( $logger, $mapper ),
		new Skwirrel_WC_Sync_Brand_Sync( $logger ),
		new Skwirrel_WC_Sync_Taxonomy_Manager( $logger ),
		new Skwirrel_WC_Sync_Slug_Resolver()
	);
}

function seam_payload( string $sku, string $updated_on, ?string $gtin = null ): array {
	$payload = [
		'product_id'              => abs( crc32( $sku ) ) % 100000,
		'external_product_id'     => $sku,
		'internal_product_code'   => $sku,
		'product_erp_description' => 'ERP title for ' . $sku,
		'product_updated_on'      => $updated_on,
		'_trade_items'            => [ [ '_trade_item_prices' => [ [ 'net_price' => 10.0 ] ] ] ],
		'_etim'                   => [
			[
				'_etim_features' => [
					[
						'etim_feature_code'          => 'EF000007',
						'etim_feature_type'          => 'L',
						'logical_value'              => true,
						'not_applicable'             => false,
						'_etim_feature_translations' => [
							[ 'language' => 'nl', 'etim_feature_description' => 'Draadloos' ],
						],
					],
				],
			],
		],
	];
	if ( null !== $gtin ) {
		$payload['product_gtin'] = $gtin;
	}
	return $payload;
}

/** @return array<string, WC_Product_Attribute> */
function seam_attributes( string $sku ): array {
	$id = wc_get_product_id_by_sku( $sku );
	clean_post_cache( $id );
	return wc_get_product( $id )->get_attributes();
}

test( 'turning sync_etim off removes ETIM attributes and their terms when nothing else remains', function () {
	$sku = 'SEAM-EMPTY';

	seam_upserter()->upsert_product( seam_payload( $sku, '2026-01-01T00:00:00Z' ) );
	$before = seam_attributes( $sku );
	expect( $before )->not->toBe( [] );
	$taxonomy = (string) array_key_first( $before );
	$id       = wc_get_product_id_by_sku( $sku );
	expect( wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'ids' ] ) )->not->toBe( [] );

	update_option( 'skwirrel_wc_sync_settings', seam_settings( false ) );
	seam_upserter()->upsert_product( seam_payload( $sku, '2026-02-01T00:00:00Z' ) );

	expect( seam_attributes( $sku ) )->toBe( [] );
	expect( wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'ids' ] ) )->toBe( [] );
} );

test( 'turning sync_etim off keeps non-ETIM attributes and drops only the ETIM ones', function () {
	$sku = 'SEAM-GTIN';

	seam_upserter()->upsert_product( seam_payload( $sku, '2026-01-01T00:00:00Z', '8711893004885' ) );
	expect( seam_attributes( $sku ) )->toHaveCount( 2 );

	update_option( 'skwirrel_wc_sync_settings', seam_settings( false ) );
	seam_upserter()->upsert_product( seam_payload( $sku, '2026-02-01T00:00:00Z', '8711893004885' ) );

	$after = seam_attributes( $sku );
	expect( $after )->toHaveCount( 1 );
	expect( array_key_first( $after ) )->toContain( 'gtin' );
} );

test( 'assign_attributes clears stale attributes on a simple product that now maps none', function () {
	$sku = 'SEAM-ASSIGN';

	seam_upserter()->upsert_product( seam_payload( $sku, '2026-01-01T00:00:00Z' ) );
	expect( seam_attributes( $sku ) )->not->toBe( [] );

	update_option( 'skwirrel_wc_sync_settings', seam_settings( false ) );
	$count = seam_upserter()->assign_attributes( wc_get_product_id_by_sku( $sku ), seam_payload( $sku, '2026-02-01T00:00:00Z' ) );

	expect( $count )->toBe( 0 );
	expect( seam_attributes( $sku ) )->toBe( [] );
} );
