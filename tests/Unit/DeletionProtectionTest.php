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
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-lookup.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-brand-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-taxonomy-manager.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php';

beforeEach(function () {
    unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);

    $logger           = new Skwirrel_WC_Sync_Logger();
    $mapper           = new Skwirrel_WC_Sync_Product_Mapper();
    $lookup           = new Skwirrel_WC_Sync_Product_Lookup($mapper);
    $brand_sync       = new Skwirrel_WC_Sync_Brand_Sync($logger);
    $taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager($logger);
    $category_sync    = new Skwirrel_WC_Sync_Category_Sync($logger, $mapper);
    $slug_resolver    = new Skwirrel_WC_Sync_Slug_Resolver();

    $this->upserter = new Skwirrel_WC_Sync_Product_Upserter(
        $logger,
        $mapper,
        $lookup,
        $category_sync,
        $brand_sync,
        $taxonomy_manager,
        $slug_resolver
    );
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

function invokeReviveGuard(object $upserter, string $current, string $planned): string {
    $ref = new ReflectionMethod($upserter, 'guard_revive_from_trash');
    return $ref->invoke($upserter, $current, $planned);
}

test('a trashed product is only revived when the incoming status is visible', function () {
    // Revive on a visible incoming status.
    expect(invokeReviveGuard($this->upserter, 'trash', 'publish'))->toBe('publish');
    expect(invokeReviveGuard($this->upserter, 'trash', 'draft'))->toBe('draft');
    // Stay trashed on a non-visible status — prevents a trash<->deprecated cycle.
    expect(invokeReviveGuard($this->upserter, 'trash', 'deprecated'))->toBe('trash');
    expect(invokeReviveGuard($this->upserter, 'trash', 'trash'))->toBe('trash');
    // A non-trashed product is unaffected.
    expect(invokeReviveGuard($this->upserter, 'publish', 'deprecated'))->toBe('deprecated');
    expect(invokeReviveGuard($this->upserter, 'deprecated', 'trash'))->toBe('trash');
});

test('do_internal_delete runs the sync-owned delete with the lock bypass active, then clears it', function () {
    $flagDuring = null;
    $GLOBALS['_test_wp_trash_hook'] = function () use (&$flagDuring) {
        $flagDuring = (new ReflectionProperty(Skwirrel_WC_Sync_Delete_Protection::class, 'internal_op'))->getValue();
    };

    Skwirrel_WC_Sync_Delete_Protection::do_internal_delete(123);
    unset($GLOBALS['_test_wp_trash_hook']);

    expect($flagDuring)->toBeTrue();        // the sync's own delete bypasses the lock
    expect((new ReflectionProperty(Skwirrel_WC_Sync_Delete_Protection::class, 'internal_op'))->getValue())->toBeFalse();  // finally{} resets it
});
