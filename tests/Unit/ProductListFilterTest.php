<?php

declare(strict_types=1);

afterEach(function () {
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

test('managed products are those that have a Skwirrel external ID', function () {
	expect(Skwirrel_WC_Sync_Product_List_Filter::meta_clause('managed'))
		->toBe(['key' => '_skwirrel_external_id', 'compare' => 'EXISTS']);
});

test('not managed products are those without one', function () {
	expect(Skwirrel_WC_Sync_Product_List_Filter::meta_clause('not_managed'))
		->toBe(['key' => '_skwirrel_external_id', 'compare' => 'NOT EXISTS']);
});

test('all products and unknown values add no clause', function (string $value) {
	expect(Skwirrel_WC_Sync_Product_List_Filter::meta_clause($value))->toBe([]);
})->with(['', 'all', 'managed; DROP TABLE']);

test('the sync is set up once an endpoint is configured', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['endpoint_url' => 'https://x.skwirrel.eu/jsonrpc'];
	expect(Skwirrel_WC_Sync_Product_List_Filter::is_sync_set_up())->toBeTrue();
});

test('without an endpoint the sync is not set up', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['endpoint_url' => '  '];
	expect(Skwirrel_WC_Sync_Product_List_Filter::is_sync_set_up())->toBeFalse();
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
	expect(Skwirrel_WC_Sync_Product_List_Filter::is_sync_set_up())->toBeFalse();
});
