<?php

declare(strict_types=1);

/**
 * Tests for the single-sourced SKU identity/collision resolver (F7 duplicate fix).
 *
 * resolve_sku_identity() decides, for a simple-product upsert, whether to create new,
 * reuse an existing product, or skip — and must NEVER mint a `-{product_id}` suffixed
 * SKU on the new-product collision path (the mechanism that produced duplicates like
 * `4250366870007-14768`).
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-lookup.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-brand-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-taxonomy-manager.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php';

// Stub WC's SKU lookup, backed by a per-test map (sku => product id). Not in bootstrap.
if (!function_exists('wc_get_product_id_by_sku')) {
    function wc_get_product_id_by_sku(string $sku): int {
        return (int) ($GLOBALS['_test_sku_map'][$sku] ?? 0);
    }
}

beforeEach(function () {
    unset($GLOBALS['_test_options'], $GLOBALS['_test_sku_map'], $GLOBALS['_test_wc_products']);

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
    unset($GLOBALS['_test_options'], $GLOBALS['_test_sku_map'], $GLOBALS['_test_wc_products']);
});

/**
 * Register a product in the SKU map + product store with the given type.
 */
function registerProduct(int $id, string $sku, string $type = 'simple'): void {
    $GLOBALS['_test_sku_map'][$sku] = $id;
    $GLOBALS['_test_wc_products'][$id] = 'variable' === $type
        ? new WC_Product_Variable($id)
        : new WC_Product($id);
}

/**
 * Invoke the private resolve_sku_identity() helper via reflection.
 *
 * @return array{wc_id: int, is_new: bool, sku: string, skip: bool}
 */
function invokeResolveSku(object $upserter, int $wc_id, string $sku, $skwirrel_product_id): array {
    $ref = new ReflectionMethod($upserter, 'resolve_sku_identity');
    return $ref->invoke($upserter, $wc_id, $sku, $skwirrel_product_id);
}

test('new product with no SKU collision is created as-is', function () {
    $result = invokeResolveSku($this->upserter, 0, '4250366870007', 14768);

    expect($result['skip'])->toBeFalse();
    expect($result['is_new'])->toBeTrue();
    expect($result['wc_id'])->toBe(0);
    expect($result['sku'])->toBe('4250366870007'); // never suffixed
});

test('new product colliding with an existing SIMPLE product reuses it (never duplicates)', function () {
    registerProduct(555, '4250366870007', 'simple');

    $result = invokeResolveSku($this->upserter, 0, '4250366870007', 14768);

    expect($result['skip'])->toBeFalse();
    expect($result['is_new'])->toBeFalse();       // becomes an update of the existing product
    expect($result['wc_id'])->toBe(555);          // reused, not a new product
    expect($result['sku'])->toBe('4250366870007'); // NOT '4250366870007-14768'
});

test('new product colliding with a VARIABLE product is skipped, not suffixed (F7)', function () {
    registerProduct(900, '4250366870007', 'variable');

    $result = invokeResolveSku($this->upserter, 0, '4250366870007', 14768);

    expect($result['skip'])->toBeTrue();           // grouped path owns it; no duplicate simple
    expect($result['wc_id'])->toBe(0);
    expect($result['sku'])->toBe('4250366870007'); // no '-14768' suffix minted
});

test('updating an existing product keeps its SKU when it owns it', function () {
    registerProduct(42, 'SKU-A', 'simple');

    // wc_id 42 is the product being updated; the SKU resolves to itself → no conflict.
    $result = invokeResolveSku($this->upserter, 42, 'SKU-A', 14768);

    expect($result['skip'])->toBeFalse();
    expect($result['is_new'])->toBeFalse();
    expect($result['wc_id'])->toBe(42);
    expect($result['sku'])->toBe('SKU-A');
});

test('updating a product whose SKU is taken by a DIFFERENT product suffixes to avoid a clash', function () {
    registerProduct(99, 'SKU-A', 'simple'); // a different product already owns SKU-A

    // Updating product 42, which now wants SKU-A (owned by 99) → suffix to stay unique.
    $result = invokeResolveSku($this->upserter, 42, 'SKU-A', 14768);

    expect($result['skip'])->toBeFalse();
    expect($result['is_new'])->toBeFalse();
    expect($result['wc_id'])->toBe(42);
    expect($result['sku'])->toBe('SKU-A-14768');
});
