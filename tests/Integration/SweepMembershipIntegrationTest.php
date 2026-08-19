<?php
/**
 * Integration tests for the scheduled membership sweep (Story 2.6).
 *
 * Every scheduled sync is a delta, and a delta payload lies about selection membership: the
 * upstream API drops or widens the `dynamic_selection_id` scope once an `updated_on` filter is
 * present, so products that have LEFT the selection keep coming back in the payload and get
 * re-published. The sweep is a second, filter-free `getProductsByFilter` pass that reports the
 * true membership; the run then filters its payload against it and drives removals from the diff.
 *
 * Pinned here:
 *  1. A scheduled (delta) run retires a product that left the selection.
 *  2. A payload product absent from the sweep is dropped — not untrashed, not re-stamped.
 *  3. A failed sweep removes nothing, and the content sync still completes.
 *  4. A removal set over the mass-removal bound removes nothing and reports why.
 *  5. A genuine re-add (present in both sweep and payload) still revives from trash.
 */

declare(strict_types=1);

beforeEach(function () {
	delete_option( 'skwirrel_wc_sync_settings' );
	delete_option( 'skwirrel_wc_sync_auth_token' );
	delete_option( 'skwirrel_wc_sync_last_sync' );
	delete_option( 'skwirrel_wc_sync_last_sync_sig' );
	delete_option( 'skwirrel_wc_sync_last_result' );
	delete_option( 'skwirrel_wc_sync_history' );
	delete_option( 'skwirrel_wc_sync_run_sweep' );
	delete_option( Skwirrel_WC_Sync_Service::OPTION_RUN_STATE );
	delete_option( 'skwirrel_wc_sync_run_groupmap' );
	delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
	delete_transient( Skwirrel_WC_Sync_History::SYNC_MUTEX );

	update_option( 'skwirrel_wc_sync_settings', [
		'endpoint_url'                   => 'https://test.skwirrel.example/jsonrpc',
		'auth_type'                      => 'bearer',
		'timeout'                        => 5,
		'retries'                        => 0,
		'batch_size'                     => 10,
		'collection_ids'                 => '3',
		'custom_collection_id'           => '1',
		'sync_categories'                => false,
		'sync_grouped_products'          => false,
		'sync_custom_classes'            => false,
		'sync_trade_item_custom_classes' => false,
		'sync_images'                    => false,
		'sync_related_products'          => false,
		'purge_stale_products'           => true,
	] );
	update_option( 'skwirrel_wc_sync_auth_token', 'test-token-123' );

	global $wpdb;
	$queue_table = $wpdb->prefix . 'skwirrel_sync_queue';
	$wpdb->query( "DELETE FROM {$queue_table}" ); // phpcs:ignore

	// Every assertion in this file is about the SIZE of the removal set relative to the catalogue,
	// so the catalogue must contain exactly what the test seeds. A product left behind by another
	// file with only `_skwirrel_product_id` on it is still counted by the sweep diff, and inflates
	// the denominator until the mass-removal brake fires on every case.
	skwPurgeSkwirrelPosts();
} );

afterEach(function () {
	remove_all_filters( 'pre_http_request' );
	remove_all_filters( 'skwirrel_wc_sync_mass_removal_ratio' );
	delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
	delete_option( Skwirrel_WC_Sync_Service::OPTION_RUN_STATE );
	delete_option( 'skwirrel_wc_sync_run_groupmap' );
	delete_option( 'skwirrel_wc_sync_run_sweep' );
	skwPurgeSkwirrelPosts();
} );

/**
 * Seed a Skwirrel-managed WooCommerce product.
 *
 * @param int    $skwirrel_id Skwirrel product id (what the sweep diff compares against).
 * @param string $status      WooCommerce post status.
 * @param int    $synced_at   `_skwirrel_synced_at` stamp to plant.
 */
function sweepSeedProduct( int $skwirrel_id, string $status = 'publish', int $synced_at = 1 ): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Sweep product ' . $skwirrel_id );
	$product->set_sku( 'SKU-' . $skwirrel_id );
	$product->set_status( $status );
	$id = (int) $product->save();
	update_post_meta( $id, '_skwirrel_external_id', 'ext:EXT-' . $skwirrel_id );
	update_post_meta( $id, '_skwirrel_product_id', (string) $skwirrel_id );
	update_post_meta( $id, '_skwirrel_synced_at', (string) $synced_at );
	return $id;
}

/** Build the Skwirrel API payload for one product. */
function sweepPayload( int $skwirrel_id ): array {
	return [
		'product_id'              => $skwirrel_id,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-' . $skwirrel_id,
		'internal_product_code'   => 'SKU-' . $skwirrel_id,
		'product_erp_description' => 'Sweep product ' . $skwirrel_id,
		'_product_status'         => [ 'product_status_description' => 'active' ],
	];
}

/**
 * Stub the endpoint with an explicit split between the two questions the run asks:
 * the membership sweep (which ids are in the selection) and the content fetch (their payloads).
 *
 * @param array<int, int>|null $sweep_ids   Ids the sweep reports, or null to fail the sweep call.
 * @param array<int, int|array<string, mixed>> $payload_ids Ids or raw rows returned by the content fetch.
 */
function sweepStub( ?array $sweep_ids, array $payload_ids ): void {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $sweep_ids, $payload_ids ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
		$method = $body['method'] ?? '';
		$params = $body['params'] ?? [];
		$id     = $body['id'] ?? 1;

		$envelope = [ 'jsonrpc' => '2.0', 'id' => $id ];

		if ( 'getBrands' === $method ) {
			$envelope['result'] = [ 'brands' => [] ];
		} elseif ( 'getProductsByFilter' === $method ) {
			$is_attr_fetch = isset( $params['filter']['code']['type'] )
				&& 'product_id' === $params['filter']['code']['type'];

			if ( $is_attr_fetch ) {
				$pid                = (int) ( $params['filter']['code']['data'][0] ?? 0 );
				$envelope['result'] = [ 'products' => [ sweepPayload( $pid ) ] ];
			} elseif ( skwIsSweepCall( $params ) ) {
				if ( null === $sweep_ids ) {
					$envelope['error'] = [ 'code' => -32000, 'message' => 'Simulated sweep failure' ];
				} else {
					$envelope['result'] = [
						'products' => array_map(
							static fn ( int $pid ): array => [ 'product_id' => $pid ],
							$sweep_ids
						),
						'page'     => [ 'current_page' => 1, 'number_of_pages' => 1 ],
					];
				}
			} else {
				$page               = (int) ( $params['page'] ?? 1 );
				$envelope['result'] = [
					'products' => 1 === $page
						? array_map( static fn ( $item ): array => is_array( $item ) ? $item : sweepPayload( (int) $item ), $payload_ids )
						: [],
				];
			}
		} else {
			$envelope['result'] = [];
		}

		return [
			'headers'  => [],
			'body'     => wp_json_encode( $envelope ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );
}

/** Stub different memberships and payloads for multiple configured selections. */
function sweepStubBySelection( array $sweeps_by_selection, array $payloads_by_selection ): void {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $sweeps_by_selection, $payloads_by_selection ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body         = json_decode( (string) ( $args['body'] ?? '' ), true );
		$method       = $body['method'] ?? '';
		$params       = $body['params'] ?? [];
		$selection_id = (int) ( $params['filter']['dynamic_selection_id'] ?? 0 );
		$envelope     = [ 'jsonrpc' => '2.0', 'id' => $body['id'] ?? 1 ];

		if ( 'getBrands' === $method ) {
			$envelope['result'] = [ 'brands' => [] ];
		} elseif ( 'getProductsByFilter' === $method ) {
			$ids                = skwIsSweepCall( $params )
				? ( $sweeps_by_selection[ $selection_id ] ?? [] )
				: ( $payloads_by_selection[ $selection_id ] ?? [] );
			$envelope['result'] = [
				'products' => array_map(
					skwIsSweepCall( $params )
						? static fn ( int $pid ): array => [ 'product_id' => $pid ]
						: static fn ( int $pid ): array => sweepPayload( $pid ),
					$ids
				),
				'page'     => [ 'current_page' => 1, 'number_of_pages' => 1 ],
			];
		} else {
			$envelope['result'] = [];
		}

		return [
			'headers'  => [],
			'body'     => wp_json_encode( $envelope ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );
}

/** Build the production upserter for direct sweep pagination checks. */
function sweepTestUpserter(): Skwirrel_WC_Sync_Product_Upserter {
	$logger   = new Skwirrel_WC_Sync_Logger();
	$mapper   = new Skwirrel_WC_Sync_Product_Mapper();
	$category = new Skwirrel_WC_Sync_Category_Sync( $logger );
	$brand    = new Skwirrel_WC_Sync_Brand_Sync( $logger );
	$taxonomy = new Skwirrel_WC_Sync_Taxonomy_Manager( $logger );
	return new Skwirrel_WC_Sync_Product_Upserter(
		$logger,
		$mapper,
		new Skwirrel_WC_Sync_Product_Lookup( $mapper ),
		$category,
		$brand,
		$taxonomy,
		new Skwirrel_WC_Sync_Slug_Resolver()
	);
}

test( 'sweep pagination follows API page metadata even below the requested limit', function () {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body     = json_decode( (string) $args['body'], true );
		$page     = (int) ( $body['params']['page'] ?? 1 );
		$envelope = [
			'jsonrpc' => '2.0',
			'id'      => $body['id'] ?? 1,
			'result'  => [
				'products' => [ [ 'product_id' => 5000 + $page ] ],
				'page'     => [ 'current_page' => $page, 'number_of_pages' => 2 ],
			],
		];
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $envelope ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$client = new Skwirrel_WC_Sync_JsonRpc_Client( 'https://test.skwirrel.example/jsonrpc', 'bearer', 'token', 5, 0 );
	$result = sweepTestUpserter()->fetch_product_ids_for_selection( $client, 3, 2 );

	expect( $result['complete'] )->toBeTrue();
	expect( array_keys( $result['ids'] ) )->toBe( [ 5001, 5002 ] );
} );

test( 'a successful sweep page with an invalid product id is incomplete', function () {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body     = json_decode( (string) $args['body'], true );
		$envelope = [
			'jsonrpc' => '2.0',
			'id'      => $body['id'] ?? 1,
			'result'  => [ 'products' => [ [ 'product_id' => 'not-an-id' ] ] ],
		];
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $envelope ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$client = new Skwirrel_WC_Sync_JsonRpc_Client( 'https://test.skwirrel.example/jsonrpc', 'bearer', 'token', 5, 0 );
	$result = sweepTestUpserter()->fetch_product_ids_for_selection( $client, 3, 2 );

	expect( $result['complete'] )->toBeFalse();
	expect( $result['ids'] )->toBe( [] );
} );

test( 'a successful sweep response without a products field is incomplete', function () {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body = json_decode( (string) $args['body'], true );
		$envelope = [
			'jsonrpc' => '2.0',
			'id'      => $body['id'] ?? 1,
			'result'  => [ 'page' => [ 'current_page' => 1, 'number_of_pages' => 1 ] ],
		];
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $envelope ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$client = new Skwirrel_WC_Sync_JsonRpc_Client( 'https://test.skwirrel.example/jsonrpc', 'bearer', 'token', 5, 0 );
	$result = sweepTestUpserter()->fetch_product_ids_for_selection( $client, 3, 2 );

	expect( $result['complete'] )->toBeFalse();
	expect( $result['ids'] )->toBe( [] );
} );

test( 'sweep without pagination metadata continues until an empty page despite short server-capped pages', function () {
	$calls = 0;
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		++$calls;
		$body     = json_decode( (string) $args['body'], true );
		$page     = (int) ( $body['params']['page'] ?? 1 );
		$products = $page <= 2 ? [ [ 'product_id' => 5100 + $page ] ] : [];
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => $body['id'] ?? 1, 'result' => [ 'products' => $products ] ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$client = new Skwirrel_WC_Sync_JsonRpc_Client( 'https://test.skwirrel.example/jsonrpc', 'bearer', 'token', 5, 0 );
	$result = sweepTestUpserter()->fetch_product_ids_for_selection( $client, 3, 50 );

	expect( $result['complete'] )->toBeTrue();
	expect( array_keys( $result['ids'] ) )->toBe( [ 5101, 5102 ] );
	expect( $calls )->toBe( 3 );
} );

test( 'contradictory sweep pagination metadata is incomplete', function () {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body = json_decode( (string) $args['body'], true );
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [
				'jsonrpc' => '2.0',
				'id'      => $body['id'] ?? 1,
				'result'  => [
					'products' => [ [ 'product_id' => 5201 ] ],
					'page'     => [ 'current_page' => '1.5', 'number_of_pages' => 1 ],
				],
			] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$client = new Skwirrel_WC_Sync_JsonRpc_Client( 'https://test.skwirrel.example/jsonrpc', 'bearer', 'token', 5, 0 );
	$result = sweepTestUpserter()->fetch_product_ids_for_selection( $client, 3, 2 );

	expect( $result['complete'] )->toBeFalse();
} );

test( 'a repeated metadata-free sweep page is incomplete', function () {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body = json_decode( (string) $args['body'], true );
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => $body['id'] ?? 1, 'result' => [ 'products' => [ [ 'product_id' => 5301 ] ] ] ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$client = new Skwirrel_WC_Sync_JsonRpc_Client( 'https://test.skwirrel.example/jsonrpc', 'bearer', 'token', 5, 0 );
	$result = sweepTestUpserter()->fetch_product_ids_for_selection( $client, 3, 2 );

	expect( $result['complete'] )->toBeFalse();
	expect( array_keys( $result['ids'] ) )->toBe( [ 5301 ] );
} );

test( 'an overflowing numeric product id makes the sweep incomplete', function () {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body = json_decode( (string) $args['body'], true );
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => $body['id'] ?? 1, 'result' => [ 'products' => [ [ 'product_id' => '999999999999999999999999999' ] ] ] ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$client = new Skwirrel_WC_Sync_JsonRpc_Client( 'https://test.skwirrel.example/jsonrpc', 'bearer', 'token', 5, 0 );
	$result = sweepTestUpserter()->fetch_product_ids_for_selection( $client, 3, 2 );

	expect( $result['complete'] )->toBeFalse();
} );

test( 'an incomplete sweep aborts before grouped products or payload rows are written', function () {
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[ 'sync_grouped_products' => true ]
	) );
	$product       = sweepSeedProduct( 5001, 'publish', 4242 );
	$grouped_calls = 0;
	sweepStub( null, [ 5001 ] );
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$grouped_calls ) {
		if ( false !== strpos( $url, 'test.skwirrel.example' ) ) {
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			if ( 'getGroupedProducts' === ( $body['method'] ?? '' ) ) {
				++$grouped_calls;
			}
		}
		return $pre;
	}, 20, 3 );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeFalse();
	expect( $grouped_calls )->toBe( 0 );
	expect( get_post_meta( $product, '_skwirrel_synced_at', true ) )->toBe( '4242' );
} );

test( 'a removal warning renders on both the dashboard card and history row', function () {
	$warning = 'Safety warning visible to the store owner';
	Skwirrel_WC_Sync_History::update_last_result( true, 0, 0, 0, '', 0, 0, 0, 0, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED, '', 0, 0, 'review-run', $warning );
	$dashboard = new Skwirrel_WC_Sync_Admin_Dashboard();

	ob_start();
	$dashboard->render( 'dashboard' );
	$status_html = (string) ob_get_clean();

	ob_start();
	$dashboard->render( 'history' );
	$history_html = (string) ob_get_clean();

	expect( $status_html )->toContain( $warning );
	expect( $history_html )->toContain( $warning );
	expect( $history_html )->toContain( 'skw-row-warning' );
} );

test( 'the membership cursor yields, resumes on the next page, and changes the poison-loop signature', function () {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
		$method = $body['method'] ?? '';
		$page   = (int) ( $body['params']['page'] ?? 1 );
		if ( 'getBrands' === $method ) {
			$result = [ 'brands' => [] ];
		} elseif ( 'getProductsByFilter' === $method && skwIsSweepCall( $body['params'] ?? [] ) ) {
			$result = [
				'products' => [ [ 'product_id' => 5400 + $page ] ],
				'page'     => [ 'current_page' => $page, 'number_of_pages' => 2 ],
			];
		} else {
			$result = [ 'products' => [] ];
		}
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => $body['id'] ?? 1, 'result' => $result ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3 );

	$service = new Skwirrel_WC_Sync_Service();
	$begin   = new ReflectionMethod( $service, 'begin_run' );
	$ctx     = $begin->invoke( $service, true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED )['ctx'];
	$status  = $service->run_step( $ctx, microtime( true ) - 1 );

	expect( $status )->toBe( 'continue' );
	expect( $ctx['step'] )->toBe( 'init' );
	expect( $ctx['sweep_page'] )->toBe( 2 );
	expect( $ctx['sweep_count'] )->toBe( 1 );

	$signature = new ReflectionMethod( Skwirrel_WC_Sync_Service::class, 'progress_signature' );
	$page_one  = $signature->invoke( null, $ctx );
	$page_two  = $ctx;
	$page_two['sweep_page'] = 3;
	expect( $signature->invoke( null, $page_two ) )->not->toBe( $page_one );

	Skwirrel_WC_Sync_Service::save_run_state( $ctx );
	$resumed = new Skwirrel_WC_Sync_Service();
	$status  = $resumed->run_step( $ctx, microtime( true ) + 30 );

	expect( $status )->toBe( 'continue' );
	expect( $ctx['step'] )->toBe( 'fetch' );
	expect( $ctx['sweep_count'] )->toBe( 2 );
	expect( $ctx['sweep_complete'] )->toBeTrue();

	$fail = new ReflectionMethod( $resumed, 'fail_run' );
	$fail->invokeArgs( $resumed, [ &$ctx, 'Test cleanup' ] );
} );

test( 'authoritative membership cannot be bypassed by a colliding grouped-product SKU', function () {
	$payload                          = sweepPayload( 5002 );
	$payload['internal_product_code'] = 'SKU-5001';
	sweepStub( [ 5001 ], [ $payload ] );

	$service = new Skwirrel_WC_Sync_Service();
	$begin   = new ReflectionMethod( $service, 'begin_run' );
	$ctx     = $begin->invoke( $service, true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED )['ctx'];
	$service->run_step( $ctx, microtime( true ) + 30 );

	$group_info = [
		'grouped_product_id'   => 99,
		'sku'                  => 'SKU-5001',
		'wc_variable_id'       => 123,
		'etim_variation_codes' => [],
	];
	$save_group_map = new ReflectionMethod( Skwirrel_WC_Sync_Service::class, 'save_group_map' );
	$save_group_map->invoke( null, (string) $ctx['run_id'], [ 5001 => $group_info, 'sku:SKU-5001' => $group_info ] );

	$service->run_step( $ctx, microtime( true ) + 30 );

	expect( $ctx['fetched'] )->toBe( 0 );
	expect( $ctx['sweep_dropped'] )->toBe( 1 );

	$fail = new ReflectionMethod( $service, 'fail_run' );
	$fail->invokeArgs( $service, [ &$ctx, 'Test cleanup' ] );
} );

test( 'invalid configured collection identifiers do not undergo lossy integer casts', function () {
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[ 'collection_ids' => '3.5,-4,999999999999999999999999999' ]
	) );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeFalse();
	expect( $result['error'] ?? '' )->toContain( 'No selection IDs configured' );
} );

// ------------------------------------------------------------------
// 1. A scheduled (delta) run retires a product that left the selection
// ------------------------------------------------------------------

test( 'membership from every configured selection is merged into one union', function () {
	update_option(
		'skwirrel_wc_sync_settings',
		array_merge( (array) get_option( 'skwirrel_wc_sync_settings' ), [ 'collection_ids' => '3,4' ] )
	);
	$first  = sweepSeedProduct( 5001, 'publish', 1 );
	$second = sweepSeedProduct( 5002, 'publish', 1 );
	sweepStubBySelection( [ 3 => [ 5001 ], 4 => [ 5002 ] ], [ 3 => [ 5001 ], 4 => [ 5002 ] ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( get_post_status( $first ) )->toBe( 'publish' );
	expect( get_post_status( $second ) )->toBe( 'publish' );
	expect( (int) get_post_meta( $first, '_skwirrel_synced_at', true ) )->toBeGreaterThan( 1 );
	expect( (int) get_post_meta( $second, '_skwirrel_synced_at', true ) )->toBeGreaterThan( 1 );
} );

test( 'a delta run trashes a product that the membership sweep says left the selection', function () {
	// Eight owned products; 5002 has left. 1 of 8 is 12.5% — comfortably under the 25% bound.
	$ids = [ 5001, 5002, 5003, 5004, 5005, 5006, 5007, 5008 ];
	$wc  = [];
	foreach ( $ids as $sid ) {
		$wc[ $sid ] = sweepSeedProduct( $sid );
	}

	$in_selection = [ 5001, 5003, 5004, 5005, 5006, 5007, 5008 ];
	// The delta payload still carries 5002 — that is exactly the upstream bug this story neutralises.
	sweepStub( $in_selection, [ 5001, 5002 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	// Cleanup ran on a SCHEDULED DELTA run — the whole point of the story.
	expect( get_post_status( $wc[5002] ) )->toBe( 'trash' );
	// Everything still in the selection is untouched.
	expect( get_post_status( $wc[5001] ) )->toBe( 'publish' );
	expect( get_post_status( $wc[5008] ) )->toBe( 'publish' );
	expect( $result['trashed'] ?? 0 )->toBe( 1 );
	$run_id = (string) get_post_meta( $wc[5002], Skwirrel_WC_Sync_Run_Links::RUN_ID_META, true );
	expect( $run_id )->not->toBe( '' );
	expect( get_post_meta( $wc[5002], Skwirrel_WC_Sync_Run_Links::RUN_OUTCOME_META, false ) )->toContain( 'trashed' );
} );

test( 'a delta with no queued content still finalizes sweep removals', function () {
	$ids = [ 5001, 5002, 5003, 5004, 5005, 5006, 5007, 5008 ];
	$wc  = [];
	foreach ( $ids as $sid ) {
		$wc[ $sid ] = sweepSeedProduct( $sid );
	}

	sweepStub( [ 5001, 5003, 5004, 5005, 5006, 5007, 5008 ], [] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( $result['trashed'] ?? 0 )->toBe( 1 );
	expect( get_post_status( $wc[5002] ) )->toBe( 'trash' );
} );

// ------------------------------------------------------------------
// 2. Payload products outside the sweep are dropped before upsert
// ------------------------------------------------------------------

test( 'a delta payload product absent from the sweep is dropped, not upserted', function () {
	// Purge off: this test isolates the FETCH filter from the removal path.
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[ 'purge_stale_products' => false ]
	) );

	$in_wc   = sweepSeedProduct( 5001 );
	// 5002 was retired by an earlier run and must stay retired: it is in the payload but not the sweep.
	$retired = sweepSeedProduct( 5002, 'trash', 4242 );

	sweepStub( [ 5001 ], [ 5001, 5002 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	// Dropped before upsert: no untrash…
	expect( get_post_status( $retired ) )->toBe( 'trash' );
	// …and no fresh sync stamp, which would otherwise hide it from the full-sync stale purge too.
	expect( get_post_meta( $retired, '_skwirrel_synced_at', true ) )->toBe( '4242' );
	// The in-selection product was still processed normally.
	expect( get_post_status( $in_wc ) )->toBe( 'publish' );
	expect( (int) get_post_meta( $in_wc, '_skwirrel_synced_at', true ) )->toBeGreaterThan( 4242 );
} );

test( 'a payload row without a usable product id cannot bypass membership filtering', function () {
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[ 'purge_stale_products' => false ]
	) );
	$retired   = sweepSeedProduct( 5002, 'trash', 4242 );
	$malformed = sweepPayload( 5002 );
	unset( $malformed['product_id'] );
	sweepStub( [ 5001 ], [ $malformed ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( get_post_status( $retired ) )->toBe( 'trash' );
	expect( get_post_meta( $retired, '_skwirrel_synced_at', true ) )->toBe( '4242' );
} );

// ------------------------------------------------------------------
// 3. An incomplete sweep never removes anything
// ------------------------------------------------------------------

test( 'a run whose membership sweep failed removes nothing but still syncs content', function () {
	$ids = [ 5001, 5002, 5003, 5004, 5005, 5006, 5007, 5008 ];
	$wc  = [];
	foreach ( $ids as $sid ) {
		$wc[ $sid ] = sweepSeedProduct( $sid );
	}

	// null => the sweep RPC fails, so the id set is partial and must not authorise any removal.
	sweepStub( null, [ 5001 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	// The content sync still completes normally — a failed sweep is not a failed run.
	expect( $result['success'] )->toBeTrue();
	expect( $result['trashed'] ?? 0 )->toBe( 0 );
	foreach ( $ids as $sid ) {
		expect( get_post_status( $wc[ $sid ] ) )->toBe( 'publish' );
	}

	$last = (array) get_option( 'skwirrel_wc_sync_last_result' );
	// The refusal is reported to the admin, naming incompleteness as the reason.
	expect( (string) ( $last['warning'] ?? '' ) )->toContain( 'incomplete' );
} );

test( 'a scheduled FULL sync is braked exactly like a scheduled delta', function () {
	// The 2026-08-18 15:31 production run was a scheduled sync with delta:false — the
	// force_full_sync flag, armed by the previous run's own purge, promoted it. Nobody was at the
	// keyboard, so it must not get the human escape hatch just because it is a full sync.
	// Twelve products, so the eleven that go stale are both over the 25% ratio AND over
	// MASS_REMOVAL_FLOOR — a set at or below the floor is never treated as a mass removal.
	$ids = range( 5001, 5012 );
	$wc  = [];
	foreach ( $ids as $sid ) {
		$wc[ $sid ] = sweepSeedProduct( $sid );
	}

	// Everything is still in the selection, but nothing is in the delta payload except 5001 — on a
	// FULL run the synced_at detection therefore marks the other eleven as stale.
	sweepStub( $ids, [ 5001 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( $result['trashed'] ?? 0 )->toBe( 0 );
	foreach ( $ids as $sid ) {
		expect( get_post_status( $wc[ $sid ] ) )->toBe( 'publish' );
	}

	$last = (array) get_option( 'skwirrel_wc_sync_last_result' );
	expect( (string) ( $last['warning'] ?? '' ) )->toContain( 'Mass removal refused' );
} );

test( 'a manual FULL sync applies the same removal the scheduled one refused', function () {
	// Identical fixture and stub to the test above — only the trigger differs. This is the escape
	// hatch AC 4 requires, and it is keyed on who started the run, not on delta vs full.
	$ids = range( 5001, 5012 );
	$wc  = [];
	foreach ( $ids as $sid ) {
		$wc[ $sid ] = sweepSeedProduct( $sid );
	}

	sweepStub( $ids, [ 5001 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

	expect( $result['success'] )->toBeTrue();
	expect( $result['trashed'] ?? 0 )->toBe( 11 );
	expect( get_post_status( $wc[5001] ) )->toBe( 'publish' );
	expect( get_post_status( $wc[5012] ) )->toBe( 'trash' );
} );

// ------------------------------------------------------------------
// 4. A mass removal is refused, not performed
// ------------------------------------------------------------------

test( 'a removal set over the mass-removal ratio is refused and reported', function () {
	$ids = range( 5001, 5012 );
	$wc  = [];
	foreach ( $ids as $sid ) {
		$wc[ $sid ] = sweepSeedProduct( $sid );
	}

	// Only 5001, 5002 and 5003 remain: 9 of 12 (75%) would be removed — over the 25% bound, and
	// over MASS_REMOVAL_FLOOR, so this is a mass removal rather than the ordinary handful.
	sweepStub( [ 5001, 5002, 5003 ], [ 5001 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	// Refusing is not a failure: the run completes and the content sync stands.
	expect( $result['success'] )->toBeTrue();
	expect( $result['trashed'] ?? 0 )->toBe( 0 );
	foreach ( $ids as $sid ) {
		expect( get_post_status( $wc[ $sid ] ) )->toBe( 'publish' );
	}

	$last    = (array) get_option( 'skwirrel_wc_sync_last_result' );
	$warning = (string) ( $last['warning'] ?? '' );
	expect( $warning )->toContain( 'Mass removal refused' );
	// The warning names the count and the ratio so an admin can judge it.
	expect( $warning )->toContain( '9 of 12' );
	expect( $warning )->toContain( '25' );
	expect( (string) ( $result['warning'] ?? '' ) )->toBe( $warning );
} );

test( 'a scheduled empty sweep refuses removal and reports the manual escape hatch', function () {
	$first  = sweepSeedProduct( 5001 );
	$second = sweepSeedProduct( 5002 );
	sweepStub( [], [ 5001 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( $result['trashed'] ?? 0 )->toBe( 0 );
	expect( get_post_status( $first ) )->toBe( 'publish' );
	expect( get_post_status( $second ) )->toBe( 'publish' );
	expect( (int) get_post_meta( $first, '_skwirrel_synced_at', true ) )->toBeGreaterThan( 1 );
	expect( (string) ( $result['warning'] ?? '' ) )->toContain( 'manual sync' );
} );

test( 'a scheduled empty sweep reports its warning even when removal is disabled', function () {
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[ 'purge_stale_products' => false ]
	) );
	$product = sweepSeedProduct( 5001 );
	sweepStub( [], [ 5001 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( (int) get_post_meta( $product, '_skwirrel_synced_at', true ) )->toBeGreaterThan( 1 );
	expect( (string) ( $result['warning'] ?? '' ) )->toContain( 'manual sync' );
} );

test( 'a manual run can reconcile an intentionally empty selection', function () {
	$first  = sweepSeedProduct( 5001 );
	$second = sweepSeedProduct( 5002 );
	sweepStub( [], [] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

	expect( $result['success'] )->toBeTrue();
	expect( $result['trashed'] ?? 0 )->toBe( 2 );
	expect( get_post_status( $first ) )->toBe( 'trash' );
	expect( get_post_status( $second ) )->toBe( 'trash' );
} );

test( 'conflicting duplicate product-id meta refuses the sweep diff', function () {
	$product_id = sweepSeedProduct( 5001 );
	add_post_meta( $product_id, '_skwirrel_product_id', '5999' );
	sweepStub( [ 5001 ], [] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( get_post_status( $product_id ) )->toBe( 'publish' );
	expect( (string) ( $result['warning'] ?? '' ) )->toContain( 'conflicting Skwirrel product IDs' );
} );

test( 'an overflowing product-id meta value refuses the sweep diff', function () {
	$product_id = sweepSeedProduct( 5001 );
	update_post_meta( $product_id, '_skwirrel_product_id', '999999999999999999999999999' );
	sweepStub( [ 5001 ], [] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( get_post_status( $product_id ) )->toBe( 'publish' );
	expect( (string) ( $result['warning'] ?? '' ) )->toContain( 'invalid Skwirrel product ID' );
} );

test( 'raising the mass-removal ratio through its filter lets the same removal through', function () {
	// Same 9-of-12 fixture as the refusal above, so the filter is what makes the difference here
	// and not the floor: 75% is under the raised 90% bound.
	$ids = range( 5001, 5012 );
	$wc  = [];
	foreach ( $ids as $sid ) {
		$wc[ $sid ] = sweepSeedProduct( $sid );
	}

	add_filter( 'skwirrel_wc_sync_mass_removal_ratio', static fn () => 0.9 );
	sweepStub( [ 5001, 5002, 5003 ], [ 5001 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( $result['trashed'] ?? 0 )->toBe( 9 );
	expect( get_post_status( $wc[5001] ) )->toBe( 'publish' );
	expect( get_post_status( $wc[5012] ) )->toBe( 'trash' );
} );

// ------------------------------------------------------------------
// 5. Genuine re-adds still revive
// ------------------------------------------------------------------

test( 'a product re-added to the selection is untrashed by the normal revive path', function () {
	$ids = [ 5001, 5002, 5003, 5004, 5005, 5006, 5007, 5008 ];
	$wc  = [];
	foreach ( $ids as $sid ) {
		$wc[ $sid ] = sweepSeedProduct( $sid, 5002 === $sid ? 'trash' : 'publish' );
	}

	// 5002 is back in BOTH the sweep and the payload — a real re-add, not an upstream artefact.
	sweepStub( $ids, [ 5002 ] );

	$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );

	expect( $result['success'] )->toBeTrue();
	expect( get_post_status( $wc[5002] ) )->toBe( 'publish' );
	expect( $result['trashed'] ?? 0 )->toBe( 0 );
} );
