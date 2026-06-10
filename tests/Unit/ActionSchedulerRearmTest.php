<?php

declare(strict_types=1);

// Stub the Action Scheduler API. State is driven by globals so each test can
// assert on scheduling decisions without a running WooCommerce/WP install.
if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	function as_next_scheduled_action( string $hook, array $args = [], string $group = '' ) {
		return $GLOBALS['_test_as_scheduled'] ?? false;
	}
}
if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = [], string $group = '' ): int {
		$GLOBALS['_test_as_calls'][] = [ 'recurring', $hook, $interval, $group ];
		$GLOBALS['_test_as_scheduled'] = true;
		return 1;
	}
}
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
		$GLOBALS['_test_as_calls'][] = [ 'unschedule_all', $hook, $group ];
		$GLOBALS['_test_as_scheduled'] = false;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = [] ): void {}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, $args = [] ) {
		return $GLOBALS['_test_wp_cron_scheduled'] ?? false;
	}
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-logger.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php';

if ( ! defined( 'SKWIRREL_WC_SYNC_VERSION' ) ) {
	define( 'SKWIRREL_WC_SYNC_VERSION', '3.10.2' );
}

beforeEach(function () {
	$GLOBALS['_test_options']           = [];
	$GLOBALS['_test_as_calls']          = [];
	$GLOBALS['_test_as_scheduled']      = false;
	$GLOBALS['_test_wp_cron_scheduled'] = false;
});

/**
 * Helper: count recurring-action arming calls captured by the AS stub.
 */
function rearm_recurring_calls(): array {
	return array_values(
		array_filter(
			$GLOBALS['_test_as_calls'] ?? [],
			static fn ( array $call ): bool => 'recurring' === $call[0]
		)
	);
}

test('upgrade with interval set arms exactly one action and updates the version option', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_version']  = '3.10.1';
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'sync_interval' => 'daily' ];

	Skwirrel_WC_Sync_Action_Scheduler::instance()->maybe_upgrade_reschedule();

	expect(rearm_recurring_calls())->toHaveCount(1);
	expect($GLOBALS['_test_options']['skwirrel_wc_sync_version'])->toBe('3.10.2');
	expect($GLOBALS['_test_as_scheduled'])->toBeTrue();
});

test('upgrade with empty interval schedules nothing but still updates the version option', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_version']  = '3.10.1';
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'sync_interval' => '' ];

	Skwirrel_WC_Sync_Action_Scheduler::instance()->maybe_upgrade_reschedule();

	expect(rearm_recurring_calls())->toHaveCount(0);
	expect($GLOBALS['_test_as_scheduled'])->toBeFalse();
	expect($GLOBALS['_test_options']['skwirrel_wc_sync_version'])->toBe('3.10.2');
});

test('version match with interval set but no action self-heals exactly one action', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_version']  = '3.10.2';
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'sync_interval' => 'daily' ];
	$GLOBALS['_test_as_scheduled']                         = false;

	Skwirrel_WC_Sync_Action_Scheduler::instance()->maybe_upgrade_reschedule();

	expect(rearm_recurring_calls())->toHaveCount(1);
	expect($GLOBALS['_test_as_scheduled'])->toBeTrue();
});

test('version match with interval set and action already armed creates no duplicate', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_version']  = '3.10.2';
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'sync_interval' => 'daily' ];
	$GLOBALS['_test_as_scheduled']                         = true;

	Skwirrel_WC_Sync_Action_Scheduler::instance()->maybe_upgrade_reschedule();

	expect(rearm_recurring_calls())->toHaveCount(0);
});

test('ensure_scheduled is a no-op when the interval is empty', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'sync_interval' => '' ];

	Skwirrel_WC_Sync_Action_Scheduler::instance()->ensure_scheduled();

	expect(rearm_recurring_calls())->toHaveCount(0);
});
