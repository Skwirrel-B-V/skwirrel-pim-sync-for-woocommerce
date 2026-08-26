<?php

declare(strict_types=1);

/**
 * Tests for the FR-18 stock mapping on variations (Story 6.2).
 *
 * Two things are unit-testable here without a real WooCommerce:
 *
 *  1. The suppression decision — `stock_mapping_governs()` — which decides whether the
 *     legacy `set_manage_stock( false )` / `set_stock_status( 'instock' )` writes in the
 *     variation price branches still fire. It is pure by design so it can be driven
 *     directly on the stub bootstrap.
 *  2. The resolver, against variation-shaped payloads. Variations are themselves Skwirrel
 *     products, so each resolves the mapping against its own product-level `_custom_classes`.
 *
 * Driving the full upsert methods needs real WC stock setters, which the stub bootstrap's
 * WC_Product does not have — that is `tests/Integration/VariationStockIntegrationTest.php`.
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-lookup.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-brand-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-taxonomy-manager.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php';

beforeEach(function () {
    unset($GLOBALS['_test_options']);
    $this->extractor = new Skwirrel_WC_Sync_Custom_Class_Extractor('nl');

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
    unset($GLOBALS['_test_options']);
});

/**
 * Invoke the pure suppression decision.
 */
function governs(string $mapping): bool
{
    $ref = new ReflectionMethod(Skwirrel_WC_Sync_Product_Upserter::class, 'stock_mapping_governs');
    return $ref->invoke(null, $mapping);
}

/**
 * Build a variation-shaped payload carrying one product-level custom feature.
 *
 * @param array<string,mixed> $feature Feature payload.
 * @return array<string,mixed>
 */
function variation_product(array $feature): array
{
    return [
        'product_id'      => 900,
        '_custom_classes' => [
            [
                'custom_class_id'  => 7,
                '_custom_features' => [ $feature ],
            ],
        ],
    ];
}

// ------------------------------------------------------------------
// AC 3 / AC 4 — the suppression decision
// ------------------------------------------------------------------

test('an unconfigured mapping does not govern stock, so legacy writes still fire', function () {
    expect(governs(''))->toBeFalse();
    expect(governs('   '))->toBeFalse();
});

test('a configured mapping governs stock, suppressing the legacy writes', function () {
    expect(governs('1234'))->toBeTrue();
    expect(governs('STOCK_QTY'))->toBeTrue();
});

test('the decision is pure — it reads no options', function () {
    // A stored setting must not change the answer; only the passed-in fact does.
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'stock_quantity_feature' => 'STOCK_QTY',
    ];

    expect(governs(''))->toBeFalse();
});

test('the active-mapping chokepoint reads the setting both variation paths share', function () {
    $ref = new ReflectionMethod($this->upserter, 'stock_mapping_is_active');

    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [];
    expect($ref->invoke($this->upserter))->toBeFalse();

    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'stock_quantity_feature' => 'STOCK_QTY' ];
    expect($ref->invoke($this->upserter))->toBeTrue();

    // Whitespace-only is off, so a stray space cannot silently arm the mapping.
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'stock_quantity_feature' => '  ' ];
    expect($ref->invoke($this->upserter))->toBeFalse();
});

// ------------------------------------------------------------------
// AC 1 / AC 5 — per-variation resolution
// ------------------------------------------------------------------

test('a variation resolves the mapping against its own payload', function () {
    $product = variation_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 7,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(7.0);
});

test('a variation with no mapped value resolves to null, so its stock is left alone', function () {
    $cases = [
        'absent'         => [ 'custom_feature_id' => 999, 'custom_feature_type' => 'N', 'numeric_value' => 5 ],
        'empty'          => [ 'custom_feature_id' => 1234, 'custom_feature_type' => 'N', 'numeric_value' => '' ],
        'non-numeric'    => [ 'custom_feature_id' => 1234, 'custom_feature_type' => 'T', 'text_value' => 'lots' ],
        'not_applicable' => [ 'custom_feature_id' => 1234, 'custom_feature_type' => 'N', 'numeric_value' => 5, 'not_applicable' => true ],
    ];

    foreach ($cases as $label => $feature) {
        expect($this->extractor->resolve_numeric_feature_value(variation_product($feature), '1234'))
            ->toBeNull("case: {$label}");
    }
});

test('a trade-item level value does not resolve for a variation either', function () {
    $product = [
        'product_id'      => 900,
        '_custom_classes' => [],
        '_trade_items'    => [
            [
                '_trade_item_custom_classes' => [
                    [
                        'custom_class_id'  => 7,
                        '_custom_features' => [
                            [
                                'custom_feature_id'   => 1234,
                                'custom_feature_type' => 'N',
                                'numeric_value'       => 5,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

// ------------------------------------------------------------------
// AC 6 — siblings are independent
// ------------------------------------------------------------------

test('siblings resolve independently of one another', function () {
    $resolving = variation_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 3,
    ]);
    $blank     = variation_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => null,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($resolving, '1234'))->toBe(3.0);
    expect($this->extractor->resolve_numeric_feature_value($blank, '1234'))->toBeNull();
    // Order must not matter — neither call carries state into the next.
    expect($this->extractor->resolve_numeric_feature_value($resolving, '1234'))->toBe(3.0);
});
