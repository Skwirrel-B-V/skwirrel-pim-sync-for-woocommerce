<?php
/**
 * Integration test for the "Managed in Skwirrel" product list filter: the meta query is built the
 * way the filter builds it and run through a real WP_Query.
 */

declare(strict_types=1);

/** Ids of the products a WP_Query returns for the given filter value, on top of an optional existing meta query. */
function skwManagedFilterIds( string $value, array $existing = [] ): array {
	$clause = Skwirrel_WC_Sync_Product_List_Filter::meta_clause( $value );
	$query  = new WP_Query(
		[
			'post_type'      => 'product',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'meta_query'     => Skwirrel_WC_Sync_Product_List_Filter::merge_meta_query( $existing, $clause ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		]
	);
	$ids    = array_map( 'intval', $query->posts );
	sort( $ids );
	return $ids;
}

function skwCreateProduct( array $meta ): int {
	$id = wp_insert_post(
		[
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Product ' . wp_generate_password( 6, false ),
		]
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}

test( 'managed lists synced simple products and grouped variable products, not managed lists the rest', function (): void {
	$simple   = skwCreateProduct( [ '_skwirrel_external_id' => 'ext:1' ] );
	$variable = skwCreateProduct( [ '_skwirrel_grouped_product_id' => 55 ] );
	$manual   = skwCreateProduct( [] );

	$managed = [ $simple, $variable ];
	sort( $managed );
	expect( skwManagedFilterIds( 'managed' ) )->toBe( $managed );
	expect( skwManagedFilterIds( 'not_managed' ) )->toBe( [ $manual ] );
	expect( skwManagedFilterIds( '' ) )->toBe( skwAllProductIds() );
} );

test( 'the filter is required together with an existing OR meta query, not alongside it', function (): void {
	$managed_red   = skwCreateProduct( [ '_skwirrel_external_id' => 'ext:2', 'color' => 'red' ] );
	$manual_red    = skwCreateProduct( [ 'color' => 'red' ] );
	$managed_green = skwCreateProduct( [ '_skwirrel_external_id' => 'ext:3', 'color' => 'green' ] );
	skwCreateProduct( [ 'color' => 'blue' ] );

	$existing = [
		'relation' => 'OR',
		[ 'key' => 'color', 'value' => 'red' ],
		[ 'key' => 'color', 'value' => 'green' ],
	];

	expect( skwManagedFilterIds( 'managed', $existing ) )->toBe( [ $managed_red, $managed_green ] );
	expect( skwManagedFilterIds( 'not_managed', $existing ) )->toBe( [ $manual_red ] );
} );

function skwAllProductIds(): array {
	$ids = array_map( 'intval', get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ] ) );
	sort( $ids );
	return $ids;
}
