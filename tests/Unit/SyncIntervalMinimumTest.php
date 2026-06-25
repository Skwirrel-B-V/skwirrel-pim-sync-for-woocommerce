<?php

declare(strict_types=1);

/**
 * Tests for the dynamic minimum auto-sync interval. Auto-syncs must leave at least one full hour of
 * rest beyond the last full sync's duration, rounded up to the next whole hour:
 *   45 min sync -> 2 h minimum, 75 min sync -> 3 h minimum.
 * Until a full sync has been timed, the minimum defaults to 2 hours.
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php';

afterEach(function () {
    unset($GLOBALS['_test_options']['skwirrel_wc_sync_last_full_duration']);
});

test('interval_seconds maps the multi-hour recurrences', function () {
    expect(Skwirrel_WC_Sync_Action_Scheduler::interval_seconds('hourly'))->toBe(HOUR_IN_SECONDS);
    expect(Skwirrel_WC_Sync_Action_Scheduler::interval_seconds('skwirrel_2_hours'))->toBe(2 * HOUR_IN_SECONDS);
    expect(Skwirrel_WC_Sync_Action_Scheduler::interval_seconds('skwirrel_3_hours'))->toBe(3 * HOUR_IN_SECONDS);
    expect(Skwirrel_WC_Sync_Action_Scheduler::interval_seconds('skwirrel_8_hours'))->toBe(8 * HOUR_IN_SECONDS);
    expect(Skwirrel_WC_Sync_Action_Scheduler::interval_seconds('twicedaily'))->toBe(12 * HOUR_IN_SECONDS);
    expect(Skwirrel_WC_Sync_Action_Scheduler::interval_seconds(''))->toBe(0);
});

test('minimum defaults to 2 hours before any full sync is timed', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_last_full_duration'] = 0;
    expect(Skwirrel_WC_Sync_Action_Scheduler::get_min_interval_seconds())->toBe(2 * HOUR_IN_SECONDS);
});

test('a 45-minute full sync requires a 2-hour minimum', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_last_full_duration'] = 45 * 60;
    expect(Skwirrel_WC_Sync_Action_Scheduler::get_min_interval_seconds())->toBe(2 * HOUR_IN_SECONDS);
});

test('a 75-minute full sync requires a 3-hour minimum', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_last_full_duration'] = 75 * 60;
    expect(Skwirrel_WC_Sync_Action_Scheduler::get_min_interval_seconds())->toBe(3 * HOUR_IN_SECONDS);
});

test('exactly 60 minutes rounds to a 2-hour minimum', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_last_full_duration'] = 60 * 60;
    expect(Skwirrel_WC_Sync_Action_Scheduler::get_min_interval_seconds())->toBe(2 * HOUR_IN_SECONDS);
});

test('a 2-hour full sync requires a 3-hour minimum', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_last_full_duration'] = 2 * HOUR_IN_SECONDS;
    expect(Skwirrel_WC_Sync_Action_Scheduler::get_min_interval_seconds())->toBe(3 * HOUR_IN_SECONDS);
});

test('smallest_interval_at_least picks the tightest allowed recurrence', function () {
    expect(Skwirrel_WC_Sync_Action_Scheduler::smallest_interval_at_least(2 * HOUR_IN_SECONDS))->toBe('skwirrel_2_hours');
    expect(Skwirrel_WC_Sync_Action_Scheduler::smallest_interval_at_least(3 * HOUR_IN_SECONDS))->toBe('skwirrel_3_hours');
    // No 5-hour option exists, so it must round up to the 6-hour recurrence.
    expect(Skwirrel_WC_Sync_Action_Scheduler::smallest_interval_at_least(5 * HOUR_IN_SECONDS))->toBe('skwirrel_6_hours');
});
