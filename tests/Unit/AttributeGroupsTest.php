<?php

declare(strict_types=1);

afterEach(function () {
	unset($GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Groups::OPTION_KEY]);
});

function skwAttrGroupsConfig(): array {
	return Skwirrel_WC_Sync_Attribute_Groups::normalize([
		'groups' => [
			'technical' => ['name' => 'Technical', 'position' => 10, 'as_tab' => true, 'hidden' => false],
			'internal'  => ['name' => 'Internal', 'position' => 20, 'as_tab' => false, 'hidden' => true],
			'general'   => ['name' => 'General', 'position' => 5, 'as_tab' => false, 'hidden' => false],
		],
		'assignments' => [
			'etim_ef000008' => 'technical',
			'etim_ef000049' => 'technical',
			'cc_cost_price' => 'internal',
			'colour'        => 'general',
		],
	]);
}

// ------------------------------------------------------------------
// normalize()
// ------------------------------------------------------------------

test('normalize returns an empty configuration for garbage input', function ($raw) {
	expect(Skwirrel_WC_Sync_Attribute_Groups::normalize($raw))->toBe(['groups' => [], 'assignments' => []]);
})->with([[null], ['string'], [[]], [['groups' => 'x', 'assignments' => 'y']]]);

test('normalize orders groups by position, then name', function () {
	expect(array_keys(skwAttrGroupsConfig()['groups']))->toBe(['general', 'technical', 'internal']);
});

test('normalize drops nameless groups and assignments to unknown groups', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::normalize([
		'groups' => [
			'a' => ['name' => 'A'],
			'b' => ['name' => '   '],
		],
		'assignments' => ['x' => 'a', 'y' => 'b', 'z' => 'missing'],
	]);

	expect(array_keys($config['groups']))->toBe(['a']);
	expect($config['assignments'])->toBe(['x' => 'a']);
});

test('normalize strips the pa_ prefix from assignment slugs', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::normalize([
		'groups' => ['a' => ['name' => 'A']],
		'assignments' => ['pa_colour' => 'a'],
	]);

	expect($config['assignments'])->toBe(['colour' => 'a']);
});

// ------------------------------------------------------------------
// generate_group_id()
// ------------------------------------------------------------------

test('generate_group_id derives a slug from the name and keeps it unique', function () {
	expect(Skwirrel_WC_Sync_Attribute_Groups::generate_group_id('Technical data', []))->toBe('technical-data');
	expect(Skwirrel_WC_Sync_Attribute_Groups::generate_group_id('Technical data', ['technical-data']))->toBe('technical-data-2');
	expect(Skwirrel_WC_Sync_Attribute_Groups::generate_group_id('Technical data', ['technical-data', 'technical-data-2']))->toBe('technical-data-3');
});

test('generate_group_id falls back to "group" for a name without slug characters', function () {
	expect(Skwirrel_WC_Sync_Attribute_Groups::generate_group_id('!!!', []))->toBe('group');
});

// ------------------------------------------------------------------
// sanitize_submission()
// ------------------------------------------------------------------

test('sanitize_submission adds a new group with the next position', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_submission(
		['new_group' => ['name' => 'Logistics', 'as_tab' => '1']],
		skwAttrGroupsConfig(),
		[]
	);

	expect($config['groups']['logistics'])->toBe(['name' => 'Logistics', 'position' => 30, 'as_tab' => true, 'hidden' => false]);
});

test('sanitize_submission ignores an empty new group row', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_submission(['new_group' => ['name' => '  ']], skwAttrGroupsConfig(), []);

	expect(array_keys($config['groups']))->toBe(['general', 'technical', 'internal']);
});

test('sanitize_submission updates flags and keeps the old name when the name is emptied', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_submission(
		['groups' => ['technical' => ['name' => '', 'position' => '1', 'hidden' => '1']]],
		skwAttrGroupsConfig(),
		[]
	);

	expect($config['groups']['technical'])->toBe(['name' => 'Technical', 'position' => 1, 'as_tab' => false, 'hidden' => true]);
});

test('sanitize_submission deletes a group and releases its attributes', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_submission(
		['groups' => ['technical' => ['name' => 'Technical', 'delete' => '1']]],
		skwAttrGroupsConfig(),
		[]
	);

	expect($config['groups'])->not->toHaveKey('technical');
	expect($config['assignments'])->toBe(['cc_cost_price' => 'internal', 'colour' => 'general']);
});

test('sanitize_submission assigns known attributes and ignores unknown ones', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_submission(
		['assign' => ['colour' => 'technical', 'etim_ef000008' => '', 'not_an_attribute' => 'general', 'size' => 'missing-group']],
		skwAttrGroupsConfig(),
		['colour', 'etim_ef000008', 'etim_ef000049', 'cc_cost_price', 'size']
	);

	expect($config['assignments'])->toBe([
		'cc_cost_price' => 'internal',
		'colour'        => 'technical',
		'etim_ef000049' => 'technical',
	]);
});

// ------------------------------------------------------------------
// filter_rows()
// ------------------------------------------------------------------

function skwAttrGroupsRows(): array {
	return [
		'weight'                  => ['label' => 'Weight', 'value' => '1 kg'],
		'attribute_pa_etim_ef000008' => ['label' => 'Width', 'value' => '10'],
		'attribute_pa_colour'     => ['label' => 'Colour', 'value' => 'Red'],
		'attribute_pa_cc_cost_price' => ['label' => 'Cost price', 'value' => '5'],
		'attribute_material'      => ['label' => 'Material', 'value' => 'Steel'],
		'attribute_pa_brand'      => ['label' => 'Brand', 'value' => 'Acme'],
	];
}

test('filter_rows for Additional information drops hidden and tab groups and clusters the rest', function () {
	$rows = Skwirrel_WC_Sync_Attribute_Groups::filter_rows(skwAttrGroupsRows(), skwAttrGroupsConfig(), null);

	expect(array_keys($rows))->toBe(['weight', 'attribute_material', 'attribute_pa_brand', 'attribute_pa_colour']);
});

test('filter_rows for a tab group keeps only that group', function () {
	$rows = Skwirrel_WC_Sync_Attribute_Groups::filter_rows(skwAttrGroupsRows(), skwAttrGroupsConfig(), 'technical');

	expect(array_keys($rows))->toBe(['attribute_pa_etim_ef000008']);
});

test('filter_rows returns nothing for a hidden or unknown group', function ($gid) {
	expect(Skwirrel_WC_Sync_Attribute_Groups::filter_rows(skwAttrGroupsRows(), skwAttrGroupsConfig(), $gid))->toBe([]);
})->with(['internal', 'missing']);

test('filter_rows leaves rows untouched without groups', function () {
	$empty = Skwirrel_WC_Sync_Attribute_Groups::normalize([]);

	expect(Skwirrel_WC_Sync_Attribute_Groups::filter_rows(skwAttrGroupsRows(), $empty, null))->toBe(skwAttrGroupsRows());
});

// ------------------------------------------------------------------
// layout_for()
// ------------------------------------------------------------------

test('layout_for lists tab groups and keeps Additional information for ungrouped attributes', function () {
	$layout = Skwirrel_WC_Sync_Attribute_Groups::layout_for(['etim_ef000008', 'brand'], false, skwAttrGroupsConfig());

	expect($layout)->toBe(['tabs' => ['technical'], 'additional_information' => true]);
});

test('layout_for drops Additional information when everything is in tabs or hidden', function () {
	$layout = Skwirrel_WC_Sync_Attribute_Groups::layout_for(['etim_ef000008', 'cc_cost_price'], false, skwAttrGroupsConfig());

	expect($layout)->toBe(['tabs' => ['technical'], 'additional_information' => false]);
});

test('layout_for keeps Additional information for weight, dimensions or custom attributes', function () {
	$layout = Skwirrel_WC_Sync_Attribute_Groups::layout_for(['etim_ef000008'], true, skwAttrGroupsConfig());

	expect($layout['additional_information'])->toBeTrue();
});

test('layout_for adds no tab for a hidden group', function () {
	$layout = Skwirrel_WC_Sync_Attribute_Groups::layout_for(['cc_cost_price'], false, skwAttrGroupsConfig());

	expect($layout)->toBe(['tabs' => [], 'additional_information' => false]);
});

// ------------------------------------------------------------------
// assign() / storage
// ------------------------------------------------------------------

test('assign stores and clears a single attribute group', function () {
	Skwirrel_WC_Sync_Attribute_Groups::save_config(skwAttrGroupsConfig());

	Skwirrel_WC_Sync_Attribute_Groups::assign('pa_size', 'general');
	expect(Skwirrel_WC_Sync_Attribute_Groups::get_config()['assignments']['size'])->toBe('general');

	Skwirrel_WC_Sync_Attribute_Groups::assign('size', '');
	expect(Skwirrel_WC_Sync_Attribute_Groups::get_config()['assignments'])->not->toHaveKey('size');
});

test('assign ignores an unknown group', function () {
	Skwirrel_WC_Sync_Attribute_Groups::save_config(skwAttrGroupsConfig());

	Skwirrel_WC_Sync_Attribute_Groups::assign('size', 'missing');

	expect(Skwirrel_WC_Sync_Attribute_Groups::get_config()['assignments'])->not->toHaveKey('size');
});
