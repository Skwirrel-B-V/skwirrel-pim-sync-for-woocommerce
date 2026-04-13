<?php

declare(strict_types=1);

afterEach(function () {
	unset($GLOBALS['_test_post_meta']);
	unset($GLOBALS['_test_wc_products']);
	unset($GLOBALS['_test_permalinks']);
	unset($GLOBALS['_test_post_fields']);
});

// ------------------------------------------------------------------
// is_skwirrel_product()
// ------------------------------------------------------------------

test('is_skwirrel_product returns true when product has _skwirrel_product_id', function () {
	$GLOBALS['_test_post_meta'][42]['_skwirrel_product_id'] = '12345';

	expect(Skwirrel_WC_Sync_Theme_API::is_skwirrel_product(42))->toBeTrue();
});

test('is_skwirrel_product returns true when product has _skwirrel_external_id', function () {
	$GLOBALS['_test_post_meta'][42]['_skwirrel_external_id'] = 'EXT-001';

	expect(Skwirrel_WC_Sync_Theme_API::is_skwirrel_product(42))->toBeTrue();
});

test('is_skwirrel_product returns true when product has _skwirrel_grouped_product_id', function () {
	$GLOBALS['_test_post_meta'][42]['_skwirrel_grouped_product_id'] = '99';

	expect(Skwirrel_WC_Sync_Theme_API::is_skwirrel_product(42))->toBeTrue();
});

test('is_skwirrel_product returns false when product has none of the meta keys', function () {
	// No meta set for product 42.
	expect(Skwirrel_WC_Sync_Theme_API::is_skwirrel_product(42))->toBeFalse();
});

test('is_skwirrel_product returns false when meta values are empty strings', function () {
	$GLOBALS['_test_post_meta'][42]['_skwirrel_product_id'] = '';
	$GLOBALS['_test_post_meta'][42]['_skwirrel_external_id'] = '';
	$GLOBALS['_test_post_meta'][42]['_skwirrel_grouped_product_id'] = '';

	expect(Skwirrel_WC_Sync_Theme_API::is_skwirrel_product(42))->toBeFalse();
});

test('is_skwirrel_product returns true when only one meta key is set among multiple', function () {
	$GLOBALS['_test_post_meta'][42]['_skwirrel_product_id'] = '';
	$GLOBALS['_test_post_meta'][42]['_skwirrel_external_id'] = 'EXT-002';

	expect(Skwirrel_WC_Sync_Theme_API::is_skwirrel_product(42))->toBeTrue();
});

// ------------------------------------------------------------------
// get_variation_url() — fallback path (no Variation_Permalinks enabled)
// ------------------------------------------------------------------

test('get_variation_url returns empty string for non-variation product', function () {
	$product = new WC_Product(10);
	$GLOBALS['_test_wc_products'][10] = $product;

	expect(Skwirrel_WC_Sync_Theme_API::get_variation_url(10))->toBe('');
});

test('get_variation_url returns empty string when product does not exist', function () {
	expect(Skwirrel_WC_Sync_Theme_API::get_variation_url(999))->toBe('');
});

test('get_variation_url returns query-string URL for valid variation', function () {
	$variation = new WC_Product_Variation(20);
	$reflection = new ReflectionObject($variation);

	$parentProp = $reflection->getProperty('parent_id');
	$parentProp->setValue($variation, 50);

	$attrProp = $reflection->getProperty('attributes');
	$attrProp->setValue($variation, ['pa_color' => 'blue', 'pa_size' => 'large']);

	$GLOBALS['_test_wc_products'][20] = $variation;
	$GLOBALS['_test_permalinks'][50]  = 'https://example.com/product/widget/';

	$url = Skwirrel_WC_Sync_Theme_API::get_variation_url(20);

	expect($url)->toContain('https://example.com/product/widget/');
	expect($url)->toContain('attribute_pa_color=blue');
	expect($url)->toContain('attribute_pa_size=large');
});

test('get_variation_url returns empty string when parent has no permalink', function () {
	$variation = new WC_Product_Variation(20);
	$reflection = new ReflectionObject($variation);

	$parentProp = $reflection->getProperty('parent_id');
	$parentProp->setValue($variation, 50);

	$GLOBALS['_test_wc_products'][20] = $variation;
	// No permalink set for parent 50.

	expect(Skwirrel_WC_Sync_Theme_API::get_variation_url(20))->toBe('');
});

// ------------------------------------------------------------------
// get_default_variation()
// ------------------------------------------------------------------

test('get_default_variation returns null for non-variable product', function () {
	$product = new WC_Product(10);
	$GLOBALS['_test_wc_products'][10] = $product;

	expect(Skwirrel_WC_Sync_Theme_API::get_default_variation(10))->toBeNull();
});

test('get_default_variation returns null when product does not exist', function () {
	expect(Skwirrel_WC_Sync_Theme_API::get_default_variation(999))->toBeNull();
});

test('get_default_variation returns first child variation', function () {
	$variable = new WC_Product_Variable(10);
	$reflection = new ReflectionObject($variable);
	$childrenProp = $reflection->getProperty('children');
	$childrenProp->setValue($variable, [20, 21]);

	$variation = new WC_Product_Variation(20);

	$GLOBALS['_test_wc_products'][10] = $variable;
	$GLOBALS['_test_wc_products'][20] = $variation;

	$result = Skwirrel_WC_Sync_Theme_API::get_default_variation(10);

	expect($result)->toBeInstanceOf(WC_Product_Variation::class);
	expect($result->get_id())->toBe(20);
});

test('get_default_variation returns null when variable product has no children', function () {
	$variable = new WC_Product_Variable(10);
	$GLOBALS['_test_wc_products'][10] = $variable;

	expect(Skwirrel_WC_Sync_Theme_API::get_default_variation(10))->toBeNull();
});

// ------------------------------------------------------------------
// get_all_variations_with_urls()
// ------------------------------------------------------------------

test('get_all_variations_with_urls returns empty array for non-variable product', function () {
	$product = new WC_Product(10);
	$GLOBALS['_test_wc_products'][10] = $product;

	expect(Skwirrel_WC_Sync_Theme_API::get_all_variations_with_urls(10))->toBe([]);
});

test('get_all_variations_with_urls returns variation data', function () {
	$variable = new WC_Product_Variable(10);
	$reflection = new ReflectionObject($variable);
	$childrenProp = $reflection->getProperty('children');
	$childrenProp->setValue($variable, [20]);

	$variation = new WC_Product_Variation(20);
	$varRef = new ReflectionObject($variation);
	$varRef->getProperty('parent_id')->setValue($variation, 10);
	$varRef->getProperty('sku')->setValue($variation, 'VAR-001');
	$varRef->getProperty('attributes')->setValue($variation, ['pa_color' => 'blue']);

	$GLOBALS['_test_wc_products'][10] = $variable;
	$GLOBALS['_test_wc_products'][20] = $variation;
	$GLOBALS['_test_permalinks'][10]  = 'https://example.com/product/widget/';

	$result = Skwirrel_WC_Sync_Theme_API::get_all_variations_with_urls(10);

	expect($result)->toHaveCount(1);
	expect($result[0]['id'])->toBe(20);
	expect($result[0]['sku'])->toBe('VAR-001');
	expect($result[0]['attributes'])->toBe(['pa_color' => 'blue']);
	expect($result[0]['url'])->toContain('attribute_pa_color=blue');
});
