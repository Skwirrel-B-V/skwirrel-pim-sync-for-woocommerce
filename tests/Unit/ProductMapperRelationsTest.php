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

test('get_related_product_ids extracts from _related_products with relation_type', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [
			['related_product_id' => 10, 'relation_type' => 'CROSS_SELL'],
			['related_product_id' => 20, 'relation_type' => 'HAS_ACCESSORY'],
			['related_product_id' => 30, 'relation_type' => 'UPSELL'],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20]);
	expect($result['upsells'])->toBe([30]);
});

test('get_related_product_ids maps SUCCESSOR to upsells in auto mode', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [
			['related_product_id' => 10, 'relation_type' => 'SUCCESSOR'],
			['related_product_id' => 20, 'relation_type' => 'IS_SIMILAR_TO'],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([20]);
	expect($result['upsells'])->toBe([10]);
});

test('get_related_product_ids defaults unknown types to cross-sells', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [
			['related_product_id' => 10, 'relation_type' => 'SPARE'],
			['related_product_id' => 20, 'relation_type' => ''],
			['related_product_id' => 30],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20, 30]);
	expect($result['upsells'])->toBe([]);
});

test('get_related_product_ids deduplicates IDs', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [
			['related_product_id' => 10, 'relation_type' => 'CROSS_SELL'],
			['related_product_id' => 10, 'relation_type' => 'HAS_ACCESSORY'],
			['related_product_id' => 20, 'relation_type' => 'CROSS_SELL'],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20]);
});

test('get_related_product_ids forces all to upsells when setting is upsells', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
		'image_language' => 'nl',
		'include_languages' => ['nl-NL', 'nl'],
		'use_sku_field' => 'internal_product_code',
		'related_products_type' => 'upsells',
	];

	$product = [
		'product_id' => 1,
		'_related_products' => [
			['related_product_id' => 10, 'relation_type' => 'CROSS_SELL'],
			['related_product_id' => 20, 'relation_type' => 'HAS_ACCESSORY'],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([]);
	expect($result['upsells'])->toBe([10, 20]);

	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

test('get_related_product_ids forces all to both when setting is both', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
		'image_language' => 'nl',
		'include_languages' => ['nl-NL', 'nl'],
		'use_sku_field' => 'internal_product_code',
		'related_products_type' => 'both',
	];

	$product = [
		'product_id' => 1,
		'_related_products' => [
			['related_product_id' => 10, 'relation_type' => 'UPSELL'],
			['related_product_id' => 20, 'relation_type' => 'CROSS_SELL'],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20]);
	expect($result['upsells'])->toBe([10, 20]);

	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

test('get_related_product_ids forces all to cross_sells when setting is cross_sells', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
		'image_language' => 'nl',
		'include_languages' => ['nl-NL', 'nl'],
		'use_sku_field' => 'internal_product_code',
		'related_products_type' => 'cross_sells',
	];

	$product = [
		'product_id' => 1,
		'_related_products' => [
			['related_product_id' => 10, 'relation_type' => 'UPSELL'],
			['related_product_id' => 20, 'relation_type' => 'SUCCESSOR'],
		],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result['cross_sells'])->toBe([10, 20]);
	expect($result['upsells'])->toBe([]);

	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

test('get_related_product_ids returns empty for empty _related_products', function () {
	$product = [
		'product_id' => 1,
		'_related_products' => [],
	];

	$result = $this->mapper->get_related_product_ids($product);

	expect($result)->toBe(['cross_sells' => [], 'upsells' => []]);
});
