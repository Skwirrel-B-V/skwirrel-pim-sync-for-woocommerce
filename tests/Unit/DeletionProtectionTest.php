<?php

declare(strict_types=1);

/**
 * Tests for "Protect Skwirrel products from deletion".
 *
 * As of the GH-40 status-handling work this flag governs ONLY the manual delete-lock
 * (blocking manual deletion of Skwirrel products while a sync is running). What the
 * sync itself does with removed/discontinued products is driven by the status-handling
 * mapping (see ProductStatusMappingTest), not by this flag.
 *
 * Covered here: Skwirrel_WC_Sync_Delete_Protection::is_deletion_protection_enabled() —
 * protective by default (true until the setting is explicitly disabled).
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-delete-protection.php';

beforeEach(function () {
    unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

afterEach(function () {
    unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

function setProtection(?bool $value): void {
    if (null === $value) {
        $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['image_language' => 'nl'];
        return;
    }
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'image_language'        => 'nl',
        'protect_from_deletion' => $value,
    ];
}

test('deletion protection is enabled by default (setting absent)', function () {
    setProtection(null);
    expect(Skwirrel_WC_Sync_Delete_Protection::is_deletion_protection_enabled())->toBeTrue();
});

test('deletion protection respects an explicit disable', function () {
    setProtection(false);
    expect(Skwirrel_WC_Sync_Delete_Protection::is_deletion_protection_enabled())->toBeFalse();
});

test('deletion protection respects an explicit enable', function () {
    setProtection(true);
    expect(Skwirrel_WC_Sync_Delete_Protection::is_deletion_protection_enabled())->toBeTrue();
});
