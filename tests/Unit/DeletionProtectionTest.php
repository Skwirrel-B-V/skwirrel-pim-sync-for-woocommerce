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

/** Minimal stand-in for the WC_Product the revive guard touches (id + slug only). */
final class ReviveGuardProductStub {
    public function __construct(private int $id = 0, private string $slug = '') {}
    public function get_id(): int {
        return $this->id;
    }
    public function get_slug(): string {
        return $this->slug;
    }
    public function set_slug(string $slug): void {
        $this->slug = $slug;
    }
}

function invokeReviveGuard(object $upserter, string $current, string $planned, ?object $product = null): string {
    $ref = new ReflectionMethod($upserter, 'guard_revive_from_trash');
    return $ref->invoke($upserter, $product ?? new ReviveGuardProductStub(), $current, $planned);
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

test('reviving takes the post out of the trash through WordPress and restores its slug', function () {
    // Saving a new status over a trashed post leaves WP's trash metadata behind and keeps the
    // "__trashed" suffix on post_name — the revived product would live on the wrong permalink.
    $GLOBALS['_test_untrashed']            = [];
    $GLOBALS['_test_untrash_slug'][77]     = 'blue-widget';
    $product                               = new ReviveGuardProductStub(77, 'blue-widget__trashed');

    expect(invokeReviveGuard($this->upserter, 'trash', 'publish', $product))->toBe('publish');
    expect($GLOBALS['_test_untrashed'])->toBe([77]);
    // The in-memory product still held the trashed slug; the WC data store would write it back.
    expect($product->get_slug())->toBe('blue-widget');
});

test('a product that stays trashed is not untrashed', function () {
    $GLOBALS['_test_untrashed'] = [];
    $product                    = new ReviveGuardProductStub(78, 'gone__trashed');

    expect(invokeReviveGuard($this->upserter, 'trash', 'deprecated', $product))->toBe('trash');
    expect($GLOBALS['_test_untrashed'])->toBe([]);
});

test('trashing a Skwirrel product in WC invalidates its change gates', function () {
    // The forced full sync finds the trashed product again, but is_unchanged() would return
    // "unchanged" on a matching stored timestamp and return before the revive logic — the
    // product would sit in the trash until its upstream timestamp happened to move.
    $GLOBALS['_test_post_types'][55]                                                              = 'product';
    $GLOBALS['_test_post_meta'][55]['_skwirrel_external_id']                                      = 'EXT-1';
    $GLOBALS['_test_post_meta'][55][Skwirrel_WC_Sync_Product_Mapper::UPDATED_ON_META]             = '2026-07-01 10:00:00';
    $GLOBALS['_test_post_meta'][55][Skwirrel_WC_Sync_Product_Upserter::CONTENT_HASH_META]         = 'abc123';
    $GLOBALS['_test_post_meta'][55][Skwirrel_WC_Sync_Product_Upserter::GROUP_HASH_META]           = 'grp123';
    $GLOBALS['_test_post_meta'][55][Skwirrel_WC_Sync_Product_Upserter::VIRTUAL_CONTENT_HASH_META] = 'vir123';
    unset($GLOBALS['_test_options'][ 'skwirrel_wc_sync_force_full_sync' ]);

    Skwirrel_WC_Sync_Delete_Protection::instance()->on_product_trashed(55);

    // All four gates: each one returns "unchanged" on its own, before the revive logic.
    expect($GLOBALS['_test_post_meta'][55])->toBe(['_skwirrel_external_id' => 'EXT-1']);
    expect(get_option('skwirrel_wc_sync_force_full_sync'))->toBeTrue();
});

test('invalidate_change_gates clears every gate and ignores an invalid id', function () {
    // The group gate matters most for a variable parent: create_variable_product_from_group()
    // returns "unchanged" on a matching _skwirrel_group_hash before it can untrash the parent.
    $GLOBALS['_test_post_meta'][90] = [
        Skwirrel_WC_Sync_Product_Mapper::UPDATED_ON_META             => '2026-07-01 10:00:00',
        Skwirrel_WC_Sync_Product_Upserter::CONTENT_HASH_META         => 'abc',
        Skwirrel_WC_Sync_Product_Upserter::GROUP_HASH_META           => 'grp',
        Skwirrel_WC_Sync_Product_Upserter::VIRTUAL_CONTENT_HASH_META => 'vir',
        '_skwirrel_external_id'                                      => 'EXT-9',
    ];

    Skwirrel_WC_Sync_Product_Upserter::invalidate_change_gates(90);
    Skwirrel_WC_Sync_Product_Upserter::invalidate_change_gates(0);

    expect($GLOBALS['_test_post_meta'][90])->toBe(['_skwirrel_external_id' => 'EXT-9']);
});

test('trashing a product WooCommerce does not manage leaves its meta alone', function () {
    $GLOBALS['_test_post_types'][56]                                                  = 'product';
    $GLOBALS['_test_post_meta'][56]                                                   = [
        Skwirrel_WC_Sync_Product_Mapper::UPDATED_ON_META => '2026-07-01 10:00:00',
    ];

    Skwirrel_WC_Sync_Delete_Protection::instance()->on_product_trashed(56);

    expect($GLOBALS['_test_post_meta'][56])->toHaveKey(Skwirrel_WC_Sync_Product_Mapper::UPDATED_ON_META);
});

/** Stand-in exposing just the status the drift check reads. */
final class DriftProductStub {
    public function __construct(private string $status) {}
    public function get_status(): string {
        return $this->status;
    }
}

function invokeStatusDrifted(object $upserter, string $current, array $product): bool {
    $ref = new ReflectionMethod($upserter, 'status_drifted');
    return $ref->invoke($upserter, new DriftProductStub($current), $product);
}

test('a product already in its mapped state is not treated as drifted', function () {
    // The change gates may skip it: the payload is unchanged and so is the WooCommerce status.
    $product = productWithStatus('Available');
    expect(invokeStatusDrifted($this->upserter, 'publish', $product))->toBeFalse();
});

test('a manually republished product counts as drifted so the gate cannot skip it', function () {
    // Mapped to deprecated but an admin put it back to publish. The Skwirrel payload never
    // changes, so without this the mapped state would never be restored and the product would
    // stay out of the deprecated escalation lifecycle indefinitely.
    $this->upserter->set_change_gate_enabled(true);
    $product = productWithStatus('Discontinued');
    (new ReflectionProperty($this->upserter, 'mapper'))->getValue($this->upserter)
        ->set_status_handling(['discontinued' => 'deprecated'], 'publish');

    expect(invokeStatusDrifted($this->upserter, 'publish', $product))->toBeTrue();
    expect(invokeStatusDrifted($this->upserter, 'deprecated', $product))->toBeFalse();
});

test('a trashed product mapped to a non-visible state is not drifted', function () {
    // Otherwise every run would reprocess it just to leave it in the trash again.
    (new ReflectionProperty($this->upserter, 'mapper'))->getValue($this->upserter)
        ->set_status_handling(['discontinued' => 'deprecated'], 'publish');
    $product = productWithStatus('Discontinued');

    expect(invokeStatusDrifted($this->upserter, 'trash', $product))->toBeFalse();
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
