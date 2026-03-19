<?php

declare(strict_types=1);

// ------------------------------------------------------------------
// get_related_product_ids()
// ------------------------------------------------------------------

beforeEach(function () {
	$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

test('get_related_product_ids returns empty when no relation fields exist', function () {
	$product = ['product_id' => 1];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result)->toBe(['cross_sells' => [], 'upsells' => []]);
});

test('get_related_product_ids extracts from _related_products array of objects', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [
			['product_id' => 10],
			['product_id' => 20],
			['product_id' => 30],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20, 30]);
	expect($result['upsells'])->toBe([]);
});

test('get_related_product_ids extracts from flat numeric array', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [10, 20, 30],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20, 30]);
});

test('get_related_product_ids extracts from related_products (no underscore)', function () {
	$product = [
		'product_id' => 1,
		'related_products' => [
			['related_product_id' => 10],
			['related_product_id' => 20],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20]);
});

test('get_related_product_ids extracts from _accessories', function () {
	$product = [
		'product_id' => 1,
		'_accessories' => [
			['id' => 42],
			['id' => 43],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([42, 43]);
});

test('get_related_product_ids deduplicates IDs', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [10, 20, 10, 20, 30],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20, 30]);
});

test('get_related_product_ids maps to upsells when setting is upsells', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
		'image_language' => 'nl',
		'include_languages' => ['nl-NL', 'nl'],
		'use_sku_field' => 'internal_product_code',
		'related_products_type' => 'upsells',
	];

	$product = [
		'product_id' => 1,
		'_related_products' => [10, 20],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([]);
	expect($result['upsells'])->toBe([10, 20]);

	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

test('get_related_product_ids maps to both when setting is both', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
		'image_language' => 'nl',
		'include_languages' => ['nl-NL', 'nl'],
		'use_sku_field' => 'internal_product_code',
		'related_products_type' => 'both',
	];

	$product = [
		'product_id' => 1,
		'_related_products' => [10, 20],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20]);
	expect($result['upsells'])->toBe([10, 20]);

	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

test('get_related_product_ids uses first non-empty candidate field', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [10],
		'_accessories' => [99], // Should be ignored because _related_products is found first
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10]);
});
