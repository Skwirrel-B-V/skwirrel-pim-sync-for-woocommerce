<?php

declare(strict_types=1);

afterEach(function () {
	unset($GLOBALS['_test_options']);
	unset($GLOBALS['_test_wc_products']);
	unset($GLOBALS['_test_permalinks']);
	unset($GLOBALS['_test_post_fields']);
});

// ------------------------------------------------------------------
// is_enabled()
// ------------------------------------------------------------------

test('is_enabled returns false when setting is not present', function () {
	// Default: no variation_permalink_enabled in options.
	expect(Skwirrel_WC_Sync_Variation_Permalinks::is_enabled())->toBeFalse();
});

test('is_enabled returns false when explicitly disabled', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_permalinks'] = [
		'slug_source_field'           => 'product_name',
		'slug_suffix_field'           => '',
		'update_slug_on_resync'       => false,
		'variation_permalink_enabled' => false,
	];

	expect(Skwirrel_WC_Sync_Variation_Permalinks::is_enabled())->toBeFalse();
});

test('is_enabled returns true when enabled in settings', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_permalinks'] = [
		'slug_source_field'           => 'product_name',
		'slug_suffix_field'           => '',
		'update_slug_on_resync'       => false,
		'variation_permalink_enabled' => true,
	];

	expect(Skwirrel_WC_Sync_Variation_Permalinks::is_enabled())->toBeTrue();
});

// ------------------------------------------------------------------
// get_variation_url()
// ------------------------------------------------------------------

test('get_variation_url returns empty string for non-variation product', function () {
	$product = new WC_Product(10);
	$GLOBALS['_test_wc_products'][10] = $product;

	expect(Skwirrel_WC_Sync_Variation_Permalinks::get_variation_url(10))->toBe('');
});

test('get_variation_url returns empty string when product does not exist', function () {
	expect(Skwirrel_WC_Sync_Variation_Permalinks::get_variation_url(999))->toBe('');
});

test('get_variation_url returns empty string when variation has no parent', function () {
	$variation = new WC_Product_Variation(20);
	$GLOBALS['_test_wc_products'][20] = $variation;

	expect(Skwirrel_WC_Sync_Variation_Permalinks::get_variation_url(20))->toBe('');
});

test('get_variation_url returns empty string when variation has no slug', function () {
	$variation = new WC_Product_Variation(20);
	$reflection = new ReflectionObject($variation);
	$reflection->getProperty('parent_id')->setValue($variation, 50);

	$GLOBALS['_test_wc_products'][20] = $variation;
	// No post_name set for variation 20.

	expect(Skwirrel_WC_Sync_Variation_Permalinks::get_variation_url(20))->toBe('');
});

test('get_variation_url returns clean permalink for valid variation', function () {
	$variation = new WC_Product_Variation(20);
	$reflection = new ReflectionObject($variation);
	$reflection->getProperty('parent_id')->setValue($variation, 50);

	$GLOBALS['_test_wc_products'][20]       = $variation;
	$GLOBALS['_test_post_fields'][20]['post_name'] = 'blue-large';
	$GLOBALS['_test_permalinks'][50]        = 'https://example.com/product/widget/';

	$url = Skwirrel_WC_Sync_Variation_Permalinks::get_variation_url(20);

	expect($url)->toBe('https://example.com/product/widget/blue-large/');
});

test('get_variation_url returns empty string when parent has no permalink', function () {
	$variation = new WC_Product_Variation(20);
	$reflection = new ReflectionObject($variation);
	$reflection->getProperty('parent_id')->setValue($variation, 50);

	$GLOBALS['_test_wc_products'][20]       = $variation;
	$GLOBALS['_test_post_fields'][20]['post_name'] = 'blue-large';
	// No permalink set for parent 50.

	expect(Skwirrel_WC_Sync_Variation_Permalinks::get_variation_url(20))->toBe('');
});

// ------------------------------------------------------------------
// QUERY_VAR constant
// ------------------------------------------------------------------

test('QUERY_VAR constant is defined', function () {
	expect(Skwirrel_WC_Sync_Variation_Permalinks::QUERY_VAR)->toBe('skwirrel_variation');
});

// ------------------------------------------------------------------
// register_query_var()
// ------------------------------------------------------------------

test('register_query_var appends the variation query var', function () {
	// Need to reset the singleton to get an instance.
	$reflection = new ReflectionClass(Skwirrel_WC_Sync_Variation_Permalinks::class);
	$instanceProp = $reflection->getProperty('instance');
	$instanceProp->setValue(null, null);

	// Enable variation permalinks so constructor registers hooks.
	$GLOBALS['_test_options']['skwirrel_wc_sync_permalinks'] = [
		'variation_permalink_enabled' => true,
	];

	$instance = Skwirrel_WC_Sync_Variation_Permalinks::instance();
	$result   = $instance->register_query_var(['foo', 'bar']);

	expect($result)->toContain('foo');
	expect($result)->toContain('bar');
	expect($result)->toContain('skwirrel_variation');

	// Clean up singleton.
	$instanceProp->setValue(null, null);
});
