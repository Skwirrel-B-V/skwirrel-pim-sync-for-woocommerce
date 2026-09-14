<?php

declare(strict_types=1);

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-history.php';

beforeEach(function () {
	$GLOBALS['_test_transients'] = [];
});

test('acquire_sync_mutex succeeds when nothing is set', function () {
	expect(Skwirrel_WC_Sync_History::acquire_sync_mutex())->toBeTrue();
	expect(get_transient(Skwirrel_WC_Sync_History::SYNC_MUTEX))->not->toBeFalse();
});

test('acquire_sync_mutex refuses a second acquisition while the first is fresh', function () {
	expect(Skwirrel_WC_Sync_History::acquire_sync_mutex())->toBeTrue();
	expect(Skwirrel_WC_Sync_History::acquire_sync_mutex())->toBeFalse();
});

test('acquire_sync_mutex takes over when the prior run is stale', function () {
	// Simulate a dead prior run that wrote the mutex more than HEARTBEAT_TTL ago.
	$stale_ts = (string) ( time() - Skwirrel_WC_Sync_History::HEARTBEAT_TTL - 1 );
	$GLOBALS['_test_transients'][Skwirrel_WC_Sync_History::SYNC_MUTEX] = [
		'value'   => $stale_ts,
		'expires' => time() + 60,
	];

	expect(Skwirrel_WC_Sync_History::acquire_sync_mutex())->toBeTrue();
	expect(get_transient(Skwirrel_WC_Sync_History::SYNC_MUTEX))->not->toBe($stale_ts);
});

test('acquire_sync_mutex ignores SYNC_IN_PROGRESS (the UI badge)', function () {
	// handle_sync_now() pre-sets the UI badge so the dashboard shows "Sync running"
	// from the moment the user clicks. Before 3.10.0 this collided with the mutex
	// and refused every manual click. Two-key separation means a fresh SYNC_IN_PROGRESS
	// must not block mutex acquisition.
	set_transient(Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS, (string) time(), 60);

	expect(Skwirrel_WC_Sync_History::acquire_sync_mutex())->toBeTrue();
});

test('sync_heartbeat refreshes both the badge and the mutex', function () {
	Skwirrel_WC_Sync_History::sync_heartbeat();

	expect(get_transient(Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS))->not->toBeFalse();
	expect(get_transient(Skwirrel_WC_Sync_History::SYNC_MUTEX))->not->toBeFalse();
});

test('release_sync_mutex clears only the mutex, leaving the UI badge alone', function () {
	Skwirrel_WC_Sync_History::sync_heartbeat();

	Skwirrel_WC_Sync_History::release_sync_mutex();

	expect(get_transient(Skwirrel_WC_Sync_History::SYNC_MUTEX))->toBeFalse();
	expect(get_transient(Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS))->not->toBeFalse();
});

test('update_last_result clears both the badge and the mutex', function () {
	Skwirrel_WC_Sync_History::sync_heartbeat();

	Skwirrel_WC_Sync_History::update_last_result(true, 0, 0, 0);

	expect(get_transient(Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS))->toBeFalse();
	expect(get_transient(Skwirrel_WC_Sync_History::SYNC_MUTEX))->toBeFalse();
});

test('update_last_result records the run start so the overview can show its duration', function () {
	$started = time() - 90;

	Skwirrel_WC_Sync_History::update_last_result(true, 0, 2, 0, '', 0, 0, 0, 0, Skwirrel_WC_Sync_History::TRIGGER_MANUAL, '', 0, 0, 'run-1', '', $started);

	$result = $GLOBALS['_test_options'][Skwirrel_WC_Sync_History::OPTION_LAST_SYNC_RESULT];
	expect($result['started_at'])->toBe($started);
	expect($result['timestamp'])->toBeGreaterThanOrEqual($started);
});

test('update_last_result defaults started_at to 0 when the caller does not know it', function () {
	Skwirrel_WC_Sync_History::update_last_result(true, 0, 0, 0);

	expect($GLOBALS['_test_options'][Skwirrel_WC_Sync_History::OPTION_LAST_SYNC_RESULT]['started_at'])->toBe(0);
});
