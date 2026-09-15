<?php

declare(strict_types=1);

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-history.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-queue.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php';

/**
 * Answers GET_LOCK with a configurable result and records queue deletes, so the step-lock
 * ownership check and the per-run teardown can be asserted without a real database.
 */
final class FakeStepLockWpdb {
	public string $prefix = 'wp_';
	/** '1' = acquired, '0' = held by another connection, null = advisory locks unavailable. */
	public ?string $get_lock = '1';
	/** @var array<int, array<string, mixed>> */
	public array $deletes = [];
	/** @var array<int, string> */
	public array $locks = [];

	public function prepare(string $query, ...$args): string {
		return vsprintf(str_replace([ '%s', '%d' ], [ "'%s'", '%d' ], $query), $args);
	}

	public function get_var(string $query) {
		if (str_starts_with($query, 'SELECT GET_LOCK')) {
			$this->locks[] = $query;
			return $this->get_lock;
		}
		if (str_starts_with($query, 'SELECT RELEASE_LOCK')) {
			$this->locks[] = $query;
			return '1';
		}
		return 'wp_skwirrel_sync_queue'; // table_exists().
	}

	/**
	 * @param array<string, mixed> $where
	 * @param array<int, string>   $format
	 */
	public function delete(string $table, array $where, array $format = []): int {
		$this->deletes[] = $where;
		return 1;
	}
}

beforeEach(function () {
	$GLOBALS['_test_transients'] = [];
	$GLOBALS['_test_options']    = [];
	$this->prev_wpdb             = $GLOBALS['wpdb'] ?? null;
	$this->wpdb                  = new FakeStepLockWpdb();
	$GLOBALS['wpdb']             = $this->wpdb;

	// A run whose last persisted progress is past the stuck threshold, with its per-run artifacts.
	update_option(Skwirrel_WC_Sync_Service::OPTION_RUN_STATE, [
		'run_id'     => 'dead-run',
		'step'       => 'products',
		'started_at' => time() - 1200,
		'saved_at'   => time() - 600,
	], false);
	update_option('skwirrel_wc_sync_run_groupmap', [ 'run_id' => 'dead-run', 'map' => [ 1 => 2 ] ], false);
	update_option('skwirrel_wc_sync_run_sweep', [ 'run_id' => 'dead-run', 'ids' => [ 1 ], 'complete' => true ], false);
});

afterEach(function () {
	$GLOBALS['wpdb'] = $this->prev_wpdb;
});

test('force_clear_stuck_run refuses while a worker holds the step lock', function () {
	$this->wpdb->get_lock = '0';

	expect(Skwirrel_WC_Sync_Service::force_clear_stuck_run())->toBeFalse();
	expect(Skwirrel_WC_Sync_Service::load_run_state())->not->toBeNull();
	expect(get_option('skwirrel_wc_sync_run_groupmap', null))->not->toBeNull();
	expect($this->wpdb->deletes)->toBe([]);
});

test('force_clear_stuck_run tears down the dead run\'s queue rows and auxiliary state', function () {
	expect(Skwirrel_WC_Sync_Service::force_clear_stuck_run())->toBeTrue();

	expect(Skwirrel_WC_Sync_Service::load_run_state())->toBeNull();
	expect(get_option('skwirrel_wc_sync_run_groupmap', null))->toBeNull();
	expect(get_option('skwirrel_wc_sync_run_sweep', null))->toBeNull();
	expect($this->wpdb->deletes)->toBe([ [ 'sync_run_id' => 'dead-run' ] ]);
	// The step lock is released again afterwards.
	expect(end($this->wpdb->locks))->toStartWith('SELECT RELEASE_LOCK');
});

test('force_clear_stuck_run clears nothing when the run is not stuck', function () {
	update_option(Skwirrel_WC_Sync_Service::OPTION_RUN_STATE, [
		'run_id'     => 'live-run',
		'step'       => 'products',
		'started_at' => time() - 1200,
		'saved_at'   => time() - 5,
	], false);

	expect(Skwirrel_WC_Sync_Service::force_clear_stuck_run())->toBeFalse();
	expect(Skwirrel_WC_Sync_Service::load_run_state())->not->toBeNull();
	expect($this->wpdb->deletes)->toBe([]);
});

test('force_clear_stuck_run falls back to the stuck heuristic when advisory locks are unavailable', function () {
	$this->wpdb->get_lock = null;

	expect(Skwirrel_WC_Sync_Service::force_clear_stuck_run())->toBeTrue();
	expect(Skwirrel_WC_Sync_Service::load_run_state())->toBeNull();
});

test('run_async_step exits without touching the run when another worker holds the step lock', function () {
	$this->wpdb->get_lock = '0';
	$before              = Skwirrel_WC_Sync_Service::load_run_state();

	Skwirrel_WC_Sync_Service::run_async_step('dead-run');

	expect(Skwirrel_WC_Sync_Service::load_run_state())->toBe($before);
});
