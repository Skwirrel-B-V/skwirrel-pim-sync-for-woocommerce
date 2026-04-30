<?php

declare(strict_types=1);

beforeEach(function () {
    $GLOBALS['_test_post_meta'] = [];
    $GLOBALS['_test_options']   = [];
});

test('build_url returns null when product is not Skwirrel-managed', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'endpoint_url' => 'https://essec-test.z04.skwirrel.eu/jsonrpc',
    ];

    expect(Skwirrel_WC_Sync_Pim_Link::build_url(42))->toBeNull();
});

test('build_url returns null when endpoint is empty', function () {
    $GLOBALS['_test_post_meta'][42] = [
        '_skwirrel_product_id' => '10072',
    ];
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'endpoint_url' => '',
    ];

    expect(Skwirrel_WC_Sync_Pim_Link::build_url(42))->toBeNull();
});

test('build_url builds /catalogue/products/edit/{id} URL using host derived from endpoint', function () {
    $GLOBALS['_test_post_meta'][42] = [
        '_skwirrel_product_id' => '10072',
    ];
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'endpoint_url' => 'https://essec-test.z04.skwirrel.eu/jsonrpc',
    ];

    expect(Skwirrel_WC_Sync_Pim_Link::build_url(42))
        ->toBe('https://essec-test.z04.skwirrel.eu/catalogue/products/edit/10072');
});

test('build_url targets the grouped-products path when only _skwirrel_grouped_product_id is set', function () {
    $GLOBALS['_test_post_meta'][99] = [
        '_skwirrel_grouped_product_id' => '5500',
    ];
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'endpoint_url' => 'https://essec-test.z04.skwirrel.eu/jsonrpc',
    ];

    expect(Skwirrel_WC_Sync_Pim_Link::build_url(99))
        ->toBe('https://essec-test.z04.skwirrel.eu/catalogue/grouped-products/edit/5500');
});

test('build_url prefers the simple-product path when both _skwirrel_product_id and grouped ID are present', function () {
    // Variations within a grouped product carry both meta keys; the variation
    // itself is a real product and should link to the products path.
    $GLOBALS['_test_post_meta'][120] = [
        '_skwirrel_product_id'         => '10072',
        '_skwirrel_grouped_product_id' => '5500',
    ];
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'endpoint_url' => 'https://essec-test.z04.skwirrel.eu/jsonrpc',
    ];

    expect(Skwirrel_WC_Sync_Pim_Link::build_url(120))
        ->toBe('https://essec-test.z04.skwirrel.eu/catalogue/products/edit/10072');
});

test('build_url returns null for non-positive product IDs', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'endpoint_url' => 'https://essec-test.z04.skwirrel.eu/jsonrpc',
    ];

    expect(Skwirrel_WC_Sync_Pim_Link::build_url(0))->toBeNull();
    expect(Skwirrel_WC_Sync_Pim_Link::build_url(-1))->toBeNull();
});

test('build_url preserves the configured port when endpoint includes one', function () {
    $GLOBALS['_test_post_meta'][42] = [
        '_skwirrel_product_id' => '10072',
    ];
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'endpoint_url' => 'http://localhost:8080/jsonrpc',
    ];

    expect(Skwirrel_WC_Sync_Pim_Link::build_url(42))
        ->toBe('http://localhost:8080/catalogue/products/edit/10072');
});
