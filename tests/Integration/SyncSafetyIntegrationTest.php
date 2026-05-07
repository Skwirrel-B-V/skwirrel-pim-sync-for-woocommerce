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
