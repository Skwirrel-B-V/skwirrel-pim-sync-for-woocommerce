<?php

declare(strict_types=1);

/**
 * Tests for the change gate (is_unchanged) that skips re-syncing products whose Skwirrel
 * `product_updated_on` has not advanced. "If it's just the timestamp, it's not updated" —
 * but a product is only treated as unchanged when the source says so AND the gate is on.
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

test('gate disabled means nothing is ever unchanged', function () {
    $this->upserter->set_change_gate_enabled(false);
    expect($this->upserter->is_unchanged(false, '2026-06-23T10:00:00+02:00', '2026-06-23T10:00:00+02:00'))->toBeFalse();
});

test('a new product is never unchanged even with the gate on', function () {
    $this->upserter->set_change_gate_enabled(true);
    expect($this->upserter->is_unchanged(true, '', '2026-06-23T10:00:00+02:00'))->toBeFalse();
});

test('existing product with identical updated_on is unchanged', function () {
    $this->upserter->set_change_gate_enabled(true);
    expect($this->upserter->is_unchanged(false, '2026-06-23T10:00:00+02:00', '2026-06-23T10:00:00+02:00'))->toBeTrue();
});

test('existing product with an advanced updated_on is changed', function () {
    $this->upserter->set_change_gate_enabled(true);
    expect($this->upserter->is_unchanged(false, '2026-06-23T10:00:00+02:00', '2026-06-23T12:47:13+02:00'))->toBeFalse();
});

test('empty stored or incoming updated_on is treated as changed (safe default)', function () {
    $this->upserter->set_change_gate_enabled(true);
    expect($this->upserter->is_unchanged(false, '', '2026-06-23T10:00:00+02:00'))->toBeFalse();
    expect($this->upserter->is_unchanged(false, '2026-06-23T10:00:00+02:00', ''))->toBeFalse();
});
