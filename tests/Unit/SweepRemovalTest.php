<?php

declare(strict_types=1);

/**
 * Tests for the scheduled membership sweep's removal decision (Story 2.6).
 *
 * Covers the two pure helpers that decide WHETHER anything is removed:
 *  - Skwirrel_WC_Sync_Purge_Handler::select_missing_ids()   — the sweep diff
 *  - Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal() — the mass-removal bound
 *
 * These are deliberately free of WordPress and the database: a scheduled run retires products
 * silently, so the arithmetic that authorises it is the part that must be pinned.
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-purge-handler.php';

/** Build a sweep membership set (Skwirrel product id => true) from a list of ids. */
function sweepSet(array $ids): array {
    return array_fill_keys($ids, true);
}

// --- select_missing_ids(): the sweep diff ---

test('select_missing_ids returns the post ids whose Skwirrel id left the selection', function () {
    $owned = [
        101 => 5001, // still in the selection
        102 => 5002, // left
        103 => 5003, // still in
    ];

    $missing = Skwirrel_WC_Sync_Purge_Handler::select_missing_ids($owned, sweepSet([5001, 5003]));

    expect($missing)->toBe([102]);
});

test('select_missing_ids returns nothing when every owned product is still in the sweep', function () {
    $owned = [101 => 5001, 102 => 5002];

    expect(Skwirrel_WC_Sync_Purge_Handler::select_missing_ids($owned, sweepSet([5001, 5002, 5999])))
        ->toBe([]);
});

test('select_missing_ids never removes a product without a usable Skwirrel id', function () {
    // A 0 / non-numeric meta value cannot be proven absent from the selection, so it must be
    // left alone rather than swept up as "not in the set".
    $owned = [101 => 0, 102 => 5002];

    expect(Skwirrel_WC_Sync_Purge_Handler::select_missing_ids($owned, sweepSet([5002])))
        ->toBe([]);
});

test('select_missing_ids compares numerically, not by string identity', function () {
    // The SQL hands back meta values as strings; the sweep set is keyed by int.
    $owned = ['101' => '5002'];

    expect(Skwirrel_WC_Sync_Purge_Handler::select_missing_ids($owned, sweepSet([5002])))
        ->toBe([]);
    expect(Skwirrel_WC_Sync_Purge_Handler::select_missing_ids($owned, sweepSet([5003])))
        ->toBe([101]);
});

test('select_missing_ids returns everything when the sweep set is empty', function () {
    // The pure helper answers the arithmetic question honestly; refusing to act on an empty
    // sweep is the caller's job (purge_missing_from_sweep bails before reaching here).
    $owned = [101 => 5001, 102 => 5002];

    expect(Skwirrel_WC_Sync_Purge_Handler::select_missing_ids($owned, []))
        ->toBe([101, 102]);
});

// --- exceeds_mass_removal(): the safety bound ---

test('exceeds_mass_removal allows a removal set under the ratio', function () {
    // 70 of 920 (~7.6%) is a normal week on the reference install.
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(70, 920, 0.25))->toBeFalse();
});

test('exceeds_mass_removal refuses a removal set over the ratio', function () {
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(300, 920, 0.25))->toBeTrue();
});

test('exceeds_mass_removal treats exactly the ratio as allowed', function () {
    // The bound is "exceeds", not "reaches" — 25 of 100 at ratio 0.25 still goes through.
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(25, 100, 0.25))->toBeFalse();
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(26, 100, 0.25))->toBeTrue();
});

test('exceeds_mass_removal is false when there is nothing to remove', function () {
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(0, 920, 0.25))->toBeFalse();
});

test('exceeds_mass_removal is false when no Skwirrel products are owned (no division by zero)', function () {
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(5, 0, 0.25))->toBeFalse();
});

test('a ratio of 0 refuses any removal above the floor', function () {
    // The floor wins below it (see MASS_REMOVAL_FLOOR): a ratio of 0 bounds the magnitude, it does
    // not disable removal of a handful of products. AC 4 makes the floor unconditional.
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(6, 920, 0.0))->toBeTrue();
});

// --- MASS_REMOVAL_FLOOR: a small catalogue is not locked out of every removal (AC 4) ---

test('the floor is 5 products', function () {
    expect(Skwirrel_WC_Sync_Purge_Handler::MASS_REMOVAL_FLOOR)->toBe(5);
});

test('a removal at or below the floor never trips the bound, however small the catalogue', function () {
    // AC 4's worked example: 2 owned products, 1 leaving = 50%, far over the 25% ratio. Without a
    // floor this shop could never retire anything on a schedule.
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(1, 2, 0.25))->toBeFalse();
    // Exactly at the floor is still allowed ("at or below").
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(5, 8, 0.25))->toBeFalse();
});

test('one product above the floor trips the bound again when the ratio is exceeded', function () {
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(6, 8, 0.25))->toBeTrue();
});

test('the floor does not rescue a removal that is within the ratio anyway', function () {
    // Below the floor and below the ratio: still allowed, for the ordinary reason.
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(3, 920, 0.25))->toBeFalse();
});

test('a ratio of 1 or more can never be exceeded, disabling the bound', function () {
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(920, 920, 1.0))->toBeFalse();
});

test('exceeds_mass_removal falls back to the filtered default ratio', function () {
    // 25% of the catalogue is the shipped bound; 30% must trip it, 20% must not.
    expect(Skwirrel_WC_Sync_Purge_Handler::MASS_REMOVAL_RATIO)->toBe(0.25);
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(30, 100))->toBeTrue();
    expect(Skwirrel_WC_Sync_Purge_Handler::exceeds_mass_removal(20, 100))->toBeFalse();
});

test('the default ratio is exposed through get_mass_removal_ratio', function () {
    expect(Skwirrel_WC_Sync_Purge_Handler::get_mass_removal_ratio())
        ->toBe(Skwirrel_WC_Sync_Purge_Handler::MASS_REMOVAL_RATIO);
});
