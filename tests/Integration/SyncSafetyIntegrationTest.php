<?php
/**
 * Integration tests for sync-safety invariants surfaced in code review.
 *
 * Each test pins a behavioural guarantee that the current implementation
 * VIOLATES — they are RED on `main` and turn GREEN once the matching fix
 * lands. Locking these down first prevents the next refactor from quietly
 * re-introducing data-loss scenarios.
 *
 * Covered invariants:
 *  1. Mutual exclusion: a second sync run must not start while a heartbeat
 *     for an in-flight run is still fresh.
 *  2. Queue isolation: a new run's startup cleanup must not wipe queue rows
 *     tagged with another run's sync_run_id.
 *  3. Pagination atomicity: when a later-page fetch fails, the run must be
 *     recorded as failed AND must not advance `last_sync` AND must not
 *     trigger the stale-product purge — otherwise products on the missing
 *     pages get dropped from future delta syncs or trashed by purge.
 */

declare(strict_types=1);

beforeEach(function () {
	delete_option( 'skwirrel_wc_sync_settings' );
	delete_option( 'skwirrel_wc_sync_auth_token' );
	delete_option( 'skwirrel_wc_sync_last_sync' );
	delete_option( 'skwirrel_wc_sync_last_result' );
	delete_option( 'skwirrel_wc_sync_history' );
	delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );

	update_option( 'skwirrel_wc_sync_settings', [
		'endpoint_url'                   => 'https://test.skwirrel.example/jsonrpc',
		'auth_type'                      => 'bearer',
		'timeout'                        => 5,
		'retries'                        => 0,
		'batch_size'                     => 10,
		'collection_ids'                 => '1',
		'custom_collection_id'           => '1',
		'sync_categories'                => false,
		'sync_grouped_products'          => false,
		'sync_custom_classes'            => false,
		'sync_trade_item_custom_classes' => false,
		'sync_images'                    => false,
		'sync_related_products'          => false,
	] );
	update_option( 'skwirrel_wc_sync_auth_token', 'test-token-123' );

	// Nuke leftover Skwirrel queue / product state so count assertions are
	// independent across tests (matches the pattern in PurgeHandlerIntegrationTest).
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
} );

afterEach(function () {
	remove_all_filters( 'pre_http_request' );
	delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
} );

/**
 * Generic JSON-RPC stub: a callable per method returning either an array
 * (becomes the `result` field) OR an array with `__rpc_error` key
 * (becomes the `error` field — used to simulate API failures mid-run).
 */
function safetyStub( array $responders ): void {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $responders ) {
		if ( false === strpos( $url, 'test.skwirrel.example' ) ) {
			return $pre;
		}
		$body     = json_decode( (string) ( $args['body'] ?? '' ), true );
		$method   = $body['method'] ?? '';
		$params   = $body['params'] ?? [];
		$id       = $body['id'] ?? 1;
		$handler  = $responders[ $method ] ?? null;
		$result   = is_callable( $handler ) ? $handler( $params ) : ( $handler ?? [] );

		$envelope = [ 'jsonrpc' => '2.0', 'id' => $id ];
		if ( is_array( $result ) && isset( $result['__rpc_error'] ) ) {
			$envelope['error'] = $result['__rpc_error'];
		} else {
			$envelope['result'] = $result;
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

// ------------------------------------------------------------------
// 1. Mutual exclusion — concurrent run_sync must be refused
// ------------------------------------------------------------------

test( 'run_sync refuses to start while another sync heartbeat is fresh', function () {
	// Pretend an in-flight sync just refreshed its heartbeat — the lock must
	// trip before any HTTP request, regardless of what the API would return.
	set_transient(
		Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS,
		(string) time(),
		Skwirrel_WC_Sync_History::HEARTBEAT_TTL
	);

	$api_call_count = 0;
	safetyStub( [
		'getBrands'           => function () use ( &$api_call_count ) {
			++$api_call_count;
			return [ 'brands' => [] ];
		},
		'getProductsByFilter' => function () use ( &$api_call_count ) {
			++$api_call_count;
			return [ 'products' => [] ];
		},
	] );

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

	expect( $result['success'] )->toBeFalse();
	// A meaningful error string so admins can recognise the lock collision.
	expect( strtolower( $result['error'] ?? '' ) )->toContain( 'already running' );
	// And critically: no API calls were made — the lock fires up-front.
	expect( $api_call_count )->toBe( 0 );
} );

// ------------------------------------------------------------------
// 2. Queue isolation — startup cleanup must not wipe other runs
// ------------------------------------------------------------------

test( 'queue: starting a new sync must not delete rows belonging to another sync_run_id', function () {
	$queue_a = new Skwirrel_WC_Sync_Queue( 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa' );
	$queue_a->insert_item( [ 'product_id' => 100, 'product_type' => 'STANDARD' ] );
	$queue_a->insert_item( [ 'product_id' => 101, 'product_type' => 'STANDARD' ] );
	expect( $queue_a->count_items( false ) )->toBe( 2 );

	// Whatever run_sync does at startup to ensure a clean queue for ITS run,
	// it must not nuke rows belonging to another active run. Simulate the
	// startup cleanup at the queue layer; today the global TRUNCATE TABLE
	// wipes everything, after the fix only orphans of THIS run get cleared.
	Skwirrel_WC_Sync_Queue::truncate();

	expect( $queue_a->count_items( false ) )->toBe( 2 );
} );

// ------------------------------------------------------------------
// 3. Pagination atomicity — a later-page failure must fail the whole run
// ------------------------------------------------------------------

test( 'run_sync records failure and does NOT advance last_sync when a later page fails', function () {
	update_option( 'skwirrel_wc_sync_last_sync', '2025-01-01T00:00:00Z' );
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[ 'batch_size' => 1 ]
	) );

	$page1_product = [
		'product_id'              => 800001,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-800001',
		'internal_product_code'   => 'SKU-800001',
		'product_erp_description' => 'Page 1 product',
		'_product_status'         => [ 'product_status_description' => 'active' ],
	];

	$page = 0;
	safetyStub( [
		'getBrands'           => [ 'brands' => [] ],
		'getProductsByFilter' => function ( $params ) use ( &$page, $page1_product ) {
			$is_attr_fetch = isset( $params['filter']['code']['type'] )
				&& 'product_id' === $params['filter']['code']['type'];
			if ( $is_attr_fetch ) {
				return [ 'products' => [ $page1_product ] ];
			}
			++$page;
			if ( 1 === $page ) {
				return [ 'products' => [ $page1_product ] ];
			}
			// Page 2 simulates a transient API failure mid-pagination.
			return [ '__rpc_error' => [ 'code' => -32000, 'message' => 'Simulated mid-pagination failure' ] ];
		},
	] );

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

	// The whole run must be recorded as failed.
	expect( $result['success'] )->toBeFalse();
	// last_sync must NOT have moved forward — otherwise products on the
	// failed pages get permanently dropped from the next delta.
	expect( get_option( 'skwirrel_wc_sync_last_sync' ) )->toBe( '2025-01-01T00:00:00Z' );
} );

test( 'run_sync does NOT trigger stale-product purge when a later page fails', function () {
	// A Skwirrel-tagged WC product that would normally be flagged stale
	// because its synced_at is ancient. After a partial-fetch failure the
	// purge MUST be skipped — otherwise the products that we failed to
	// fetch get trashed.
	$stale = new WC_Product_Simple();
	$stale->set_name( 'Pre-existing stale product' );
	$stale->set_sku( 'SKU-STALE' );
	$stale->set_status( 'publish' );
	$stale_id = (int) $stale->save();
	update_post_meta( $stale_id, '_skwirrel_external_id', 'ext:EXT-STALE' );
	update_post_meta( $stale_id, '_skwirrel_product_id', '999999' );
	update_post_meta( $stale_id, '_skwirrel_synced_at', '1' );

	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[
			'batch_size'           => 1,
			'purge_stale_products' => true,
		]
	) );

	$page1_product = [
		'product_id'              => 800002,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-800002',
		'internal_product_code'   => 'SKU-800002',
		'product_erp_description' => 'Page 1 product',
		'_product_status'         => [ 'product_status_description' => 'active' ],
	];

	$page = 0;
	safetyStub( [
		'getBrands'           => [ 'brands' => [] ],
		'getProductsByFilter' => function ( $params ) use ( &$page, $page1_product ) {
			$is_attr_fetch = isset( $params['filter']['code']['type'] )
				&& 'product_id' === $params['filter']['code']['type'];
			if ( $is_attr_fetch ) {
				return [ 'products' => [ $page1_product ] ];
			}
			++$page;
			if ( 1 === $page ) {
				return [ 'products' => [ $page1_product ] ];
			}
			return [ '__rpc_error' => [ 'code' => -32000, 'message' => 'Simulated mid-pagination failure' ] ];
		},
	] );

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

	expect( $result['success'] )->toBeFalse();
	// The stale product must NOT have been trashed — purge skipped on partial fetch.
	expect( get_post_status( $stale_id ) )->toBe( 'publish' );
} );

// ------------------------------------------------------------------
// 1b. Mutex + queue isolation — extra coverage for the P1 fixes
// ------------------------------------------------------------------

test( 'end-of-run $queue->cleanup() removes only the calling run rows', function () {
	$queue_a = new Skwirrel_WC_Sync_Queue( 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa' );
	$queue_a->insert_item( [ 'product_id' => 200, 'product_type' => 'STANDARD' ] );
	$queue_a->insert_item( [ 'product_id' => 201, 'product_type' => 'STANDARD' ] );

	$queue_b = new Skwirrel_WC_Sync_Queue( 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb' );
	$queue_b->insert_item( [ 'product_id' => 300, 'product_type' => 'STANDARD' ] );

	expect( $queue_a->count_items( false ) )->toBe( 2 );
	expect( $queue_b->count_items( false ) )->toBe( 1 );

	// Run B finishing must not touch run A's rows. This is the inverse of
	// the start-of-run isolation test — together they pin "queue rows are
	// owned by exactly one sync_run_id" from both ends of a run's lifecycle.
	$queue_b->cleanup();

	expect( $queue_a->count_items( false ) )->toBe( 2 );
	expect( $queue_b->count_items( false ) )->toBe( 0 );
} );

test( 'heartbeat transient is cleared after a successful run, so the next click can start', function () {
	$skwirrel_product = [
		'product_id'              => 700001,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-700001',
		'internal_product_code'   => 'SKU-700001',
		'product_erp_description' => 'Cleared heartbeat product',
		'_product_status'         => [ 'product_status_description' => 'active' ],
	];

	$page = 0;
	safetyStub( [
		'getBrands'           => [ 'brands' => [] ],
		'getProductsByFilter' => function ( $params ) use ( &$page, $skwirrel_product ) {
			$is_attr_fetch = isset( $params['filter']['code']['type'] )
				&& 'product_id' === $params['filter']['code']['type'];
			if ( $is_attr_fetch ) {
				return [ 'products' => [ $skwirrel_product ] ];
			}
			++$page;
			return 1 === $page ? [ 'products' => [ $skwirrel_product ] ] : [ 'products' => [] ];
		},
	] );

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );
	expect( $result['success'] )->toBeTrue();

	// After a clean finish the in-progress transient must be gone — otherwise
	// the next manual click would always trip the mutex even though no sync
	// is actually running.
	expect( get_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS ) )->toBeFalse();

	// And a follow-up run actually proceeds (no lock collision).
	$page    = 0;
	$result2 = $service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );
	expect( $result2['success'] )->toBeTrue();
} );

// ------------------------------------------------------------------
// 4. Multiple selection IDs — UI says "1, 2, 3", sync must honour all
// ------------------------------------------------------------------

test( 'run_sync queries every configured selection id, not just the first one', function () {
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[ 'collection_ids' => '1, 2' ]
	) );

	$product_in_one = [
		'product_id'              => 600001,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-SEL1',
		'internal_product_code'   => 'SKU-SEL1',
		'product_erp_description' => 'From selection 1',
		'_product_status'         => [ 'product_status_description' => 'active' ],
	];
	$product_in_two = [
		'product_id'              => 600002,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-SEL2',
		'internal_product_code'   => 'SKU-SEL2',
		'product_erp_description' => 'From selection 2',
		'_product_status'         => [ 'product_status_description' => 'active' ],
	];

	$selection_calls = [ 1 => 0, 2 => 0 ];
	safetyStub( [
		'getBrands'           => [ 'brands' => [] ],
		'getProductsByFilter' => function ( $params ) use ( &$selection_calls, $product_in_one, $product_in_two ) {
			$is_attr_fetch = isset( $params['filter']['code']['type'] )
				&& 'product_id' === $params['filter']['code']['type'];
			if ( $is_attr_fetch ) {
				$pid = $params['filter']['code']['data'][0] ?? '';
				if ( '600001' === (string) $pid ) {
					return [ 'products' => [ $product_in_one ] ];
				}
				return [ 'products' => [ $product_in_two ] ];
			}
			$selection_id = (int) ( $params['filter']['dynamic_selection_id'] ?? 0 );
			if ( isset( $selection_calls[ $selection_id ] ) ) {
				++$selection_calls[ $selection_id ];
				if ( 1 === $selection_calls[ $selection_id ] ) {
					return [ 'products' => [ 1 === $selection_id ? $product_in_one : $product_in_two ] ];
				}
			}
			return [ 'products' => [] ];
		},
	] );

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

	expect( $result['success'] )->toBeTrue();
	// Both selections must have been queried at least once.
	expect( $selection_calls[1] )->toBeGreaterThan( 0 );
	expect( $selection_calls[2] )->toBeGreaterThan( 0 );
	// Both products must end up in WooCommerce — currently the second one
	// never gets fetched because only $collection_ids[0] is used.
	$matches_one = wc_get_products( [ 'sku' => 'SKU-SEL1', 'limit' => 1, 'status' => [ 'publish', 'draft' ] ] );
	$matches_two = wc_get_products( [ 'sku' => 'SKU-SEL2', 'limit' => 1, 'status' => [ 'publish', 'draft' ] ] );
	expect( $matches_one )->toHaveCount( 1 );
	expect( $matches_two )->toHaveCount( 1 );
} );

// ------------------------------------------------------------------
// 4b. Grouped-products prefilter must honour every configured selection id
// ------------------------------------------------------------------

test( 'sync_grouped_products_first queries every selection id when building the allowed-products filter', function () {
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[
			'sync_grouped_products' => true,
			'collection_ids'        => '1, 2',
		]
	) );

	// Per-id call counter for getProductsByFilter. Both the main-fetch loop
	// (already multi-selection-aware after the 3.8.0 fix) and the grouped-
	// products prefilter use this RPC, so a correct implementation queries
	// each configured id from BOTH paths — i.e. at least twice per id.
	$by_selection = [];
	safetyStub( [
		'getBrands'           => [ 'brands' => [] ],
		'getGroupedProducts'  => [ 'grouped_products' => [] ],
		'getProductsByFilter' => function ( $params ) use ( &$by_selection ) {
			$sid = $params['filter']['dynamic_selection_id'] ?? null;
			if ( null !== $sid ) {
				$sid_int = (int) $sid;
				$by_selection[ $sid_int ] = ( $by_selection[ $sid_int ] ?? 0 ) + 1;
			}
			return [ 'products' => [] ];
		},
	] );

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

	expect( $result['success'] )->toBeTrue();
	// Both selections must have been hit at least twice (once by the main
	// fetch loop, once by sync_grouped_products_first's prefilter). Today
	// the prefilter only uses $collection_ids[0], so selection 2 ends up
	// at exactly 1 call instead of 2 — the test fails red against main.
	expect( $by_selection[1] ?? 0 )->toBeGreaterThanOrEqual( 2 );
	expect( $by_selection[2] ?? 0 )->toBeGreaterThanOrEqual( 2 );
} );

// ------------------------------------------------------------------
// 5. Related products — empty API relations must clear existing WC links
// ------------------------------------------------------------------

test( 'empty cross_sells in the API payload clears existing WC cross_sells', function () {
	update_option( 'skwirrel_wc_sync_settings', array_merge(
		(array) get_option( 'skwirrel_wc_sync_settings' ),
		[
			'sync_related_products' => true,
			'related_products_type' => 'cross_sells',
		]
	) );

	// Seed: a Skwirrel-tagged product with an existing cross_sell to a sibling.
	$sibling = new WC_Product_Simple();
	$sibling->set_name( 'Sibling target' );
	$sibling->set_sku( 'SKU-SIBLING' );
	$sibling->set_status( 'publish' );
	$sibling_id = (int) $sibling->save();

	$tracked = new WC_Product_Simple();
	$tracked->set_name( 'Tracked product' );
	$tracked->set_sku( 'SKU-TRACKED' );
	$tracked->set_status( 'publish' );
	$tracked->set_cross_sell_ids( [ $sibling_id ] );
	$tracked_id = (int) $tracked->save();
	update_post_meta( $tracked_id, '_skwirrel_external_id', 'ext:EXT-TRACKED' );
	update_post_meta( $tracked_id, '_skwirrel_product_id', '500001' );

	$skwirrel_product = [
		'product_id'              => 500001,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-TRACKED',
		'internal_product_code'   => 'SKU-TRACKED',
		'product_erp_description' => 'Tracked product (no relations)',
		'_product_status'         => [ 'product_status_description' => 'active' ],
		// Critically: NO `_related_products` field. Skwirrel removed all relations.
	];

	$page = 0;
	safetyStub( [
		'getBrands'           => [ 'brands' => [] ],
		'getProductsByFilter' => function ( $params ) use ( &$page, $skwirrel_product ) {
			$is_attr_fetch = isset( $params['filter']['code']['type'] )
				&& 'product_id' === $params['filter']['code']['type'];
			if ( $is_attr_fetch ) {
				return [ 'products' => [ $skwirrel_product ] ];
			}
			++$page;
			return 1 === $page ? [ 'products' => [ $skwirrel_product ] ] : [ 'products' => [] ];
		},
	] );

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

	expect( $result['success'] )->toBeTrue();

	// The cross_sell that Skwirrel removed must also disappear in WC,
	// otherwise stale relations linger forever once a relation is removed
	// at the source.
	$refreshed = wc_get_product( $tracked_id );
	expect( $refreshed )->not->toBeFalse();
	expect( $refreshed->get_cross_sell_ids() )->toBe( [] );
} );
