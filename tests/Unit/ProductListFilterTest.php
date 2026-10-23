<?php

declare(strict_types=1);

afterEach(function () {
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

test('managed products have a Skwirrel external ID or, for variable products built from a group, a grouped product ID', function () {
	expect(Skwirrel_WC_Sync_Product_List_Filter::meta_clause('managed'))->toBe([
		'relation' => 'OR',
		['key' => '_skwirrel_external_id', 'compare' => 'EXISTS'],
		['key' => '_skwirrel_grouped_product_id', 'compare' => 'EXISTS'],
	]);
});

test('not managed products have neither', function () {
	expect(Skwirrel_WC_Sync_Product_List_Filter::meta_clause('not_managed'))->toBe([
		'relation' => 'AND',
		['key' => '_skwirrel_external_id', 'compare' => 'NOT EXISTS'],
		['key' => '_skwirrel_grouped_product_id', 'compare' => 'NOT EXISTS'],
	]);
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

test('the managed clause is required together with an existing OR meta query', function () {
	$existing = ['relation' => 'OR', ['key' => 'a', 'value' => '1'], ['key' => 'b', 'value' => '2']];
	$clause   = Skwirrel_WC_Sync_Product_List_Filter::meta_clause('managed');
	expect(Skwirrel_WC_Sync_Product_List_Filter::merge_meta_query($existing, $clause))
		->toBe(['relation' => 'AND', $existing, $clause]);
});

test('without an existing meta query the clause stands alone', function () {
	$clause = Skwirrel_WC_Sync_Product_List_Filter::meta_clause('not_managed');
	expect(Skwirrel_WC_Sync_Product_List_Filter::merge_meta_query([], $clause))->toBe([$clause]);
});
