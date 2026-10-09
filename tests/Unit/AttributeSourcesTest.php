<?php

declare(strict_types=1);

beforeEach(function () {
	Skwirrel_WC_Sync_Attribute_Sources::reset_pending();
	Skwirrel_WC_Sync_Attribute_Sources::set_run('');
	unset($GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY]);
});

afterEach(function () {
	Skwirrel_WC_Sync_Attribute_Sources::reset_pending();
	unset($GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY]);
});

test('product attributes are recorded as identifier, ETIM or custom class', function () {
	Skwirrel_WC_Sync_Attribute_Sources::record_product_attributes(
		['GTIN' => '123', 'Manufacturer' => 'Acme', 'Breedte' => '10'],
		['Voorraad' => '7', 'Breedte' => 'ignored, ETIM took this label'],
		['Voorraad' => ['class_key' => 'LOGISTICS', 'class_name' => 'Logistiek']],
		'sanitize_title'
	);

	expect(Skwirrel_WC_Sync_Attribute_Sources::current())->toBe([
		'gtin'         => ['source' => 'identifier', 'class_key' => '', 'class_name' => ''],
		'manufacturer' => ['source' => 'identifier', 'class_key' => '', 'class_name' => ''],
		'breedte'      => ['source' => 'etim', 'class_key' => '', 'class_name' => ''],
		'voorraad'     => ['source' => 'custom_class', 'class_key' => 'logistics', 'class_name' => 'Logistiek'],
	]);
});

test('flush merges into the stored map and writes nothing when unchanged', function () {
	$GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY] = [
		'kleur' => ['source' => 'etim', 'class_key' => '', 'class_name' => ''],
	];

	Skwirrel_WC_Sync_Attribute_Sources::record('pa_breedte', 'etim');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(array_keys(Skwirrel_WC_Sync_Attribute_Sources::all()))->toBe(['breedte', 'kleur']);

	$GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY]['marker'] = 'untouched';
	Skwirrel_WC_Sync_Attribute_Sources::record('kleur', 'etim');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect($GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY]['marker'])->toBe('untouched');
});

test('unknown sources and empty slugs are ignored', function () {
	Skwirrel_WC_Sync_Attribute_Sources::record('kleur', 'bogus');
	Skwirrel_WC_Sync_Attribute_Sources::record('', 'etim');

	expect(Skwirrel_WC_Sync_Attribute_Sources::current())->toBe([]);
});

test('source_of falls back to slug prefixes for variation axes and the variant', function ($slug, $source) {
	expect(Skwirrel_WC_Sync_Attribute_Sources::source_of($slug, [])['source'] ?? null)->toBe($source);
})->with([
	['pa_etim_ef000008', 'etim'],
	['cc_42', 'custom_class'],
	['skwirrel_variant', 'variant'],
	['kleur', null],
]);

test('the extractor maps each custom class attribute label to the class whose value is used', function () {
	$extractor = new Skwirrel_WC_Sync_Custom_Class_Extractor('nl');
	$feature   = fn (string $code, string $label) => [
		'custom_feature_code'          => $code,
		'custom_feature_type'          => 'L',
		'logical_value'                => true,
		'_custom_feature_translations' => [['language' => 'nl', 'custom_feature_description' => $label]],
	];
	$product = ['_custom_classes' => [
		['custom_class_id' => 1, 'custom_class_code' => 'LOGISTICS', 'custom_class_name' => 'Logistiek', '_custom_features' => [$feature('QTY', 'Op voorraad')]],
		['custom_class_id' => 2, 'custom_class_code' => 'INTERNAL', '_custom_features' => [$feature('QTY', 'Op voorraad'), $feature('COST', 'Kostprijs bekend')]],
	]];

	expect($extractor->get_attribute_class_map($product))->toBe([
		'Op voorraad'      => ['class_key' => 'LOGISTICS', 'class_name' => 'Logistiek'],
		'Kostprijs bekend' => ['class_key' => 'INTERNAL', 'class_name' => 'INTERNAL'],
	]);
});

test('when two custom features share a label, the class of the later one wins, like the attribute value', function () {
	$extractor = new Skwirrel_WC_Sync_Custom_Class_Extractor('nl');
	$feature   = fn (string $code) => [
		'custom_feature_code'          => $code,
		'custom_feature_type'          => 'L',
		'logical_value'                => true,
		'_custom_feature_translations' => [['language' => 'nl', 'custom_feature_description' => 'Op voorraad']],
	];
	$product = ['_custom_classes' => [
		['custom_class_id' => 1, 'custom_class_code' => 'LOCATION_A', '_custom_features' => [$feature('QTY_A')]],
		['custom_class_id' => 2, 'custom_class_code' => 'LOCATION_B', '_custom_features' => [$feature('QTY_B')]],
	]];

	expect(array_keys($extractor->get_custom_class_attributes($product)))->toBe(['Op voorraad']);
	expect($extractor->get_attribute_class_map($product)['Op voorraad']['class_key'])->toBe('LOCATION_B');
});

test('forget removes an entry and rename moves it to the new slug', function () {
	$GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY] = [
		'kleur'   => ['source' => 'etim', 'class_key' => '', 'class_name' => ''],
		'breedte' => ['source' => 'etim', 'class_key' => '', 'class_name' => ''],
	];

	Skwirrel_WC_Sync_Attribute_Sources::forget('pa_kleur');
	Skwirrel_WC_Sync_Attribute_Sources::rename('breedte', 'width');

	expect(array_keys(Skwirrel_WC_Sync_Attribute_Sources::all()))->toBe(['width']);
});

test('forget also drops an entry recorded earlier in the same request', function () {
	Skwirrel_WC_Sync_Attribute_Sources::record('kleur', 'etim');
	Skwirrel_WC_Sync_Attribute_Sources::forget('kleur');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(Skwirrel_WC_Sync_Attribute_Sources::all())->toBe([]);
});

test('a slug fed from different sources keeps the same winner whatever the product order', function ($first, $second) {
	Skwirrel_WC_Sync_Attribute_Sources::record('breedte', ...$first);
	Skwirrel_WC_Sync_Attribute_Sources::record('breedte', ...$second);
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(Skwirrel_WC_Sync_Attribute_Sources::all()['breedte']['source'])->toBe('etim');
})->with([
	'ETIM first'         => [['etim'], ['custom_class', 'LOGISTICS', 'Logistiek']],
	'custom class first' => [['custom_class', 'LOGISTICS', 'Logistiek'], ['etim']],
]);

test('between two custom classes the lowest class key wins, across the requests of one run too', function () {
	Skwirrel_WC_Sync_Attribute_Sources::set_run('run-1');
	Skwirrel_WC_Sync_Attribute_Sources::record('voorraad', 'custom_class', 'WAREHOUSE_B', 'B');
	Skwirrel_WC_Sync_Attribute_Sources::flush();
	Skwirrel_WC_Sync_Attribute_Sources::record('voorraad', 'custom_class', 'WAREHOUSE_A', 'A');
	Skwirrel_WC_Sync_Attribute_Sources::flush();
	Skwirrel_WC_Sync_Attribute_Sources::record('voorraad', 'custom_class', 'WAREHOUSE_B', 'B');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(Skwirrel_WC_Sync_Attribute_Sources::all()['voorraad']['class_key'])->toBe('warehouse_a');
});

test('the same source refreshes the entry, so a renamed class shows its new name', function () {
	Skwirrel_WC_Sync_Attribute_Sources::record('voorraad', 'custom_class', 'LOGISTICS', 'Logistiek');
	Skwirrel_WC_Sync_Attribute_Sources::flush();
	Skwirrel_WC_Sync_Attribute_Sources::record('voorraad', 'custom_class', 'LOGISTICS', 'Logistics');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(Skwirrel_WC_Sync_Attribute_Sources::all()['voorraad']['class_name'])->toBe('Logistics');
});

test('within one run a higher-ranked source recorded in an earlier step keeps winning', function () {
	Skwirrel_WC_Sync_Attribute_Sources::set_run('run-1');
	Skwirrel_WC_Sync_Attribute_Sources::record('breedte', 'etim');
	Skwirrel_WC_Sync_Attribute_Sources::flush();
	Skwirrel_WC_Sync_Attribute_Sources::record('breedte', 'custom_class', 'LOGISTICS', 'Logistiek');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(Skwirrel_WC_Sync_Attribute_Sources::all()['breedte']['source'])->toBe('etim');
});

test('a later run replaces a source an earlier run recorded, e.g. after ETIM sync is switched off', function () {
	Skwirrel_WC_Sync_Attribute_Sources::set_run('run-1');
	Skwirrel_WC_Sync_Attribute_Sources::record('breedte', 'etim');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	Skwirrel_WC_Sync_Attribute_Sources::set_run('run-2');
	Skwirrel_WC_Sync_Attribute_Sources::record('breedte', 'custom_class', 'LOGISTICS', 'Logistiek');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(Skwirrel_WC_Sync_Attribute_Sources::all()['breedte'])->toBe(['source' => 'custom_class', 'class_key' => 'logistics', 'class_name' => 'Logistiek']);
});

test('a later run also replaces a custom class that no longer feeds the attribute', function () {
	Skwirrel_WC_Sync_Attribute_Sources::set_run('run-1');
	Skwirrel_WC_Sync_Attribute_Sources::record('voorraad', 'custom_class', 'WAREHOUSE_A', 'A');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	Skwirrel_WC_Sync_Attribute_Sources::set_run('run-2');
	Skwirrel_WC_Sync_Attribute_Sources::record('voorraad', 'custom_class', 'WAREHOUSE_B', 'B');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(Skwirrel_WC_Sync_Attribute_Sources::all()['voorraad']['class_key'])->toBe('warehouse_b');
});

test('a sync outside a run (one product) records what that product has now', function () {
	Skwirrel_WC_Sync_Attribute_Sources::set_run('run-1');
	Skwirrel_WC_Sync_Attribute_Sources::record('breedte', 'etim');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	Skwirrel_WC_Sync_Attribute_Sources::set_run('');
	Skwirrel_WC_Sync_Attribute_Sources::record('breedte', 'custom_class', 'LOGISTICS', 'Logistiek');
	Skwirrel_WC_Sync_Attribute_Sources::flush();

	expect(Skwirrel_WC_Sync_Attribute_Sources::all()['breedte']['source'])->toBe('custom_class');
});
