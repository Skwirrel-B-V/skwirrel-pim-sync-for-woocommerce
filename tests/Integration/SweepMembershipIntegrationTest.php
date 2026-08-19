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
 * @param array<int, int>      $payload_ids Ids the (delta) content fetch returns on page 1.
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
					];
				}
			} else {
				$page               = (int) ( $params['page'] ?? 1 );
				$envelope['result'] = [
					'products' => 1 === $page ? array_map( 'sweepPayload', $payload_ids ) : [],
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

// ------------------------------------------------------------------
// 1. A scheduled (delta) run retires a product that left the selection
// ------------------------------------------------------------------

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
