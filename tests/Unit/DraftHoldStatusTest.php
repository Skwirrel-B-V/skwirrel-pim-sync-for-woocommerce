<?php

declare(strict_types=1);

/**
 * Tests for resolve_initial_status() — the draft-until-complete safety.
 *
 * A *new* product whose real status is 'publish' must be written as 'draft' first and
 * flagged for publishing once the per-product commit finishes, so a run that dies mid-commit
 * never leaves a bare, published product on the storefront. Existing products — and new
 * draft/trashed ones — keep their real status (we never unpublish a live product).
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-lookup.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-brand-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-taxonomy-manager.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php';

beforeEach(function () {
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

/**
 * Invoke the private resolve_initial_status() helper via reflection.
 *
 * @return array{status: string, pending_publish: bool}
 */
function invokeResolveStatus(object $upserter, bool $is_new, string $final_status): array {
    $ref = new ReflectionMethod($upserter, 'resolve_initial_status');
    return $ref->invoke($upserter, $is_new, $final_status);
}

test('new publishable product is held as draft and flagged for publish', function () {
    $result = invokeResolveStatus($this->upserter, true, 'publish');

    expect($result['status'])->toBe('draft');
    expect($result['pending_publish'])->toBeTrue();
});

test('existing publishable product keeps publish and is never re-held', function () {
    $result = invokeResolveStatus($this->upserter, false, 'publish');

    expect($result['status'])->toBe('publish');
    expect($result['pending_publish'])->toBeFalse();
});

test('new draft product stays draft with no pending publish', function () {
    $result = invokeResolveStatus($this->upserter, true, 'draft');

    expect($result['status'])->toBe('draft');
    expect($result['pending_publish'])->toBeFalse();
});

test('new trashed product stays trashed with no pending publish', function () {
    $result = invokeResolveStatus($this->upserter, true, 'trash');

    expect($result['status'])->toBe('trash');
    expect($result['pending_publish'])->toBeFalse();
});
