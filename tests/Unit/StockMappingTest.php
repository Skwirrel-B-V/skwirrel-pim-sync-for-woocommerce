<?php

declare(strict_types=1);

/**
 * Tests for the FR-18 stock mapping (Story 6.1).
 *
 * The resolver lives on Skwirrel_WC_Sync_Custom_Class_Extractor and is pure: it
 * reads product-level `_custom_classes` only and returns a raw numeric value or
 * null. Null is the NFR-9 contract — the caller must then leave WooCommerce's
 * existing stock exactly as it was, never writing 0 and never flipping
 * manage_stock.
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

    $this->mapper   = $mapper;
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
 * Build a product carrying one product-level custom feature.
 *
 * @param array<string,mixed> $feature Feature payload overrides.
 * @return array<string,mixed>
 */
function stock_product(array $feature): array
{
    return [
        'product_id'      => 42,
        '_custom_classes' => [
            [
                'custom_class_id'   => 7,
                'custom_class_code' => 'logistics',
                '_custom_features'  => [ $feature ],
            ],
        ],
    ];
}

// ------------------------------------------------------------------
// AC 9 — the four pinned cases
// ------------------------------------------------------------------

test('a numeric value resolves for a feature matched by ID', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_code' => 'STOCK_QTY',
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(500.0);
});

test('a numeric value resolves through custom_class_feature_id when the primary alias is empty', function () {
    $product = stock_product([
        'custom_feature_id'       => '',
        'custom_class_feature_id' => 1234,
        'custom_feature_type'     => 'N',
        'numeric_value'           => 500,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(500.0);
});

test('a missing feature resolves to null', function () {
    $product = stock_product([
        'custom_feature_id'   => 999,
        'custom_feature_code' => 'SOMETHING_ELSE',
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

test('a non-numeric value resolves to null', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'T',
        'text_value'          => 'plenty in stock',
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

test('an unconfigured mapping resolves to null without reading the payload', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, ''))->toBeNull();
    expect($this->extractor->resolve_numeric_feature_value($product, '   '))->toBeNull();
});

// ------------------------------------------------------------------
// AC 2 — product-level scope, code matching, not_applicable
// ------------------------------------------------------------------

test('a feature matches by code, case-insensitively', function () {
    $product = stock_product([
        'custom_feature_code' => 'Stock_Qty',
        'custom_feature_type' => 'N',
        'numeric_value'       => 12,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, 'stock_qty'))->toBe(12.0);
    expect($this->extractor->resolve_numeric_feature_value($product, 'STOCK_QTY'))->toBe(12.0);
});

test('a value present only on a trade-item custom class resolves to null', function () {
    $product = [
        'product_id'      => 42,
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
                                'numeric_value'       => 500,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

test('a not_applicable feature resolves to null', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
        'not_applicable'      => true,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

test('an empty numeric value resolves to null', function () {
    foreach ([ null, '' ] as $empty) {
        $product = stock_product([
            'custom_feature_id'   => 1234,
            'custom_feature_type' => 'N',
            'numeric_value'       => $empty,
        ]);

        expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
    }
});

test('a numeric text value resolves for type T', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'T',
        'text_value'          => '250',
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(250.0);
});

test('the raw number is returned, not the formatted display string with its unit', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
        'custom_unit_code'    => 'st',
    ]);

    // format_custom_feature_value() would yield "500 st"; stock needs the bare number.
    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(500.0);
});

// ------------------------------------------------------------------
// AC 1 / AC 4 — the setting and its default
// ------------------------------------------------------------------

test('stock_quantity_feature defaults to an empty string', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [];

    $ref  = new ReflectionMethod($this->upserter, 'get_options');
    $opts = $ref->invoke($this->upserter);

    expect($opts)->toHaveKey('stock_quantity_feature');
    expect($opts['stock_quantity_feature'])->toBe('');
});

test('a stored stock_quantity_feature is surfaced through get_options', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'stock_quantity_feature' => 'STOCK_QTY',
    ];

    $ref  = new ReflectionMethod($this->upserter, 'get_options');
    $opts = $ref->invoke($this->upserter);

    expect($opts['stock_quantity_feature'])->toBe('STOCK_QTY');
});

// ------------------------------------------------------------------
// The mapper delegates, consistent with every other custom-class field
// ------------------------------------------------------------------

test('the mapper delegates the resolver to the extractor', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 88,
    ]);

    expect($this->mapper->get_stock_quantity($product, '1234'))->toBe(88.0);
    expect($this->mapper->get_stock_quantity($product, ''))->toBeNull();
});
