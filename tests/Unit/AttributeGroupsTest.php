<?php

declare(strict_types=1);

beforeEach(function () {
	Skwirrel_WC_Sync_Attribute_Sources::reset_pending();
});

afterEach(function () {
	unset($GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Groups::OPTION_KEY]);
	unset($GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY]);
	Skwirrel_WC_Sync_Attribute_Sources::reset_pending();
});

function skwAgSources(): array {
	$e = fn (string $source, string $key = '', string $name = '') => ['source' => $source, 'class_key' => $key, 'class_name' => $name];
	return [
		'breedte'      => $e('etim'),
		'kleur'        => $e('etim'),
		'gtin'         => $e('identifier'),
		'manufacturer' => $e('identifier'),
		'voorraad'     => $e('custom_class', 'logistics', 'Logistiek'),
		'kostprijs'    => $e('custom_class', 'internal', 'Intern'),
	];
}

function skwAgSlugs(): array {
	return ['breedte', 'kleur', 'gtin', 'manufacturer', 'voorraad', 'kostprijs', 'etim_ef000008', 'cc_42', 'skwirrel_variant', 'handmatig'];
}

function skwAgContext(array $config = [], bool $etim = true): array {
	return Skwirrel_WC_Sync_Attribute_Groups::build_context($config, skwAgSources(), skwAgSlugs(), $etim);
}

// ------------------------------------------------------------------
// Automatic groups
// ------------------------------------------------------------------

test('automatic groups follow the sources, in default order', function () {
	$ctx = skwAgContext();

	expect(array_keys($ctx['groups']))->toBe([
		'src-identifiers', 'src-etim', 'src-cc-internal', 'src-cc-logistics', 'src-cc', 'src-variant',
	]);
	expect($ctx['groups']['src-etim']['name'])->toBe('ETIM');
	expect($ctx['groups']['src-cc-logistics']['name'])->toBe('Logistiek');
	expect($ctx['groups']['src-etim']['automatic'])->toBeTrue();
});

test('attributes resolve to their automatic group, including variation axes by prefix', function ($slug, $expected) {
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug($slug, skwAgContext()))->toBe($expected);
})->with([
	['breedte', 'src-etim'],
	['pa_kleur', 'src-etim'],
	['etim_ef000008', 'src-etim'],
	['gtin', 'src-identifiers'],
	['voorraad', 'src-cc-logistics'],
	['cc_42', 'src-cc'],
	['skwirrel_variant', 'src-variant'],
	['handmatig', null],
]);

test('the ETIM group only exists while ETIM is synced', function () {
	$ctx = skwAgContext([], false);

	expect($ctx['groups'])->not->toHaveKey('src-etim');
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('breedte', $ctx))->toBeNull();
});

test('stored overrides rename and configure an automatic group', function () {
	$ctx = skwAgContext(['groups' => ['src-etim' => ['name' => 'Specifications', 'position' => 1, 'as_tab' => true]]]);

	expect(array_key_first($ctx['groups']))->toBe('src-etim');
	expect($ctx['groups']['src-etim']['name'])->toBe('Specifications');
	expect($ctx['groups']['src-etim']['as_tab'])->toBeTrue();
});

// ------------------------------------------------------------------
// Custom groups, includes and manual assignments
// ------------------------------------------------------------------

test('a custom group takes over the automatic groups it includes', function () {
	$ctx = skwAgContext(['groups' => [
		'technical' => ['name' => 'Technical', 'position' => 5, 'includes' => ['src-etim', 'src-cc-logistics', 'src-missing']],
	]]);

	expect($ctx['groups']['technical']['includes'])->toBe(['src-etim', 'src-cc-logistics']);
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('breedte', $ctx))->toBe('technical');
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('voorraad', $ctx))->toBe('technical');
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('kostprijs', $ctx))->toBe('src-cc-internal');
});

test('a manual assignment wins over includes, and NO_GROUP keeps an attribute ungrouped', function () {
	$ctx = skwAgContext([
		'groups'      => ['technical' => ['name' => 'Technical', 'includes' => ['src-etim']]],
		'assignments' => ['kleur' => 'src-identifiers', 'breedte' => '__none', 'handmatig' => 'technical', 'gtin' => 'deleted-group'],
	]);

	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('kleur', $ctx))->toBe('src-identifiers');
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('breedte', $ctx))->toBeNull();
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('handmatig', $ctx))->toBe('technical');
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('gtin', $ctx))->toBe('src-identifiers');
});

// ------------------------------------------------------------------
// normalize() / generate_group_id()
// ------------------------------------------------------------------

test('normalize returns an empty configuration for garbage input', function ($raw) {
	expect(Skwirrel_WC_Sync_Attribute_Groups::normalize($raw))->toBe(['groups' => [], 'assignments' => []]);
})->with([[null], ['string'], [[]], [['groups' => 'x', 'assignments' => 'y']]]);

test('normalize drops nameless custom groups but keeps nameless automatic overrides', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::normalize(['groups' => [
		'custom'   => ['name' => ' '],
		'src-etim' => ['name' => '', 'hidden' => 1],
	]]);

	expect(array_keys($config['groups']))->toBe(['src-etim']);
	expect($config['groups']['src-etim']['hidden'])->toBeTrue();
});

test('generate_group_id derives a unique slug and never claims the automatic prefix', function () {
	expect(Skwirrel_WC_Sync_Attribute_Groups::generate_group_id('Technical data', []))->toBe('technical-data');
	expect(Skwirrel_WC_Sync_Attribute_Groups::generate_group_id('Technical data', ['technical-data']))->toBe('technical-data-2');
	expect(Skwirrel_WC_Sync_Attribute_Groups::generate_group_id('Src etim', []))->toBe('group-src-etim');
	expect(Skwirrel_WC_Sync_Attribute_Groups::generate_group_id('!!!', []))->toBe('group');
});

// ------------------------------------------------------------------
// sanitize_groups_submission()
// ------------------------------------------------------------------

test('saving an automatic group stores only what differs from its defaults', function () {
	$ctx    = skwAgContext();
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_groups_submission(
		['groups' => ['src-etim' => ['name' => 'ETIM', 'position' => '100', 'as_tab' => '1']]],
		[],
		$ctx
	);

	expect($config['groups']['src-etim'])->toBe(['name' => '', 'position' => null, 'as_tab' => true, 'hidden' => false, 'admin_hidden' => false, 'includes' => []]);
});

test('a new custom group gets the next position and only valid includes', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_groups_submission(
		['new_group' => ['name' => 'Technical', 'includes' => ['src-etim', 'src-bogus']]],
		[],
		skwAgContext()
	);

	expect($config['groups']['technical'])->toBe(['name' => 'Technical', 'position' => 410, 'as_tab' => false, 'hidden' => false, 'admin_hidden' => false, 'includes' => ['src-etim']]);
});

test('an empty new group row adds nothing', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_groups_submission(['new_group' => ['name' => '  ']], [], skwAgContext());

	expect($config['groups'])->toBe([]);
});

test('deleting a custom group sends its manual assignments back to automatic', function () {
	$existing = [
		'groups'      => ['technical' => ['name' => 'Technical', 'position' => 1]],
		'assignments' => ['kleur' => 'technical', 'breedte' => '__none', 'gtin' => 'src-etim'],
	];
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_groups_submission(
		['groups' => ['technical' => ['name' => 'Technical', 'delete' => '1']]],
		$existing,
		skwAgContext($existing)
	);

	expect($config['groups'])->toBe([]);
	expect($config['assignments'])->toBe(['breedte' => '__none', 'gtin' => 'src-etim']);
});

test('automatic groups cannot be deleted', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_groups_submission(
		['groups' => ['src-etim' => ['name' => 'ETIM', 'delete' => '1', 'hidden' => '1']]],
		[],
		skwAgContext()
	);

	expect($config['groups']['src-etim']['hidden'])->toBeTrue();
});

// ------------------------------------------------------------------
// apply_bulk_assignment()
// ------------------------------------------------------------------

test('bulk assignment moves known attributes and ignores unknown ones', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::apply_bulk_assignment(
		['kleur', 'pa_breedte', 'nope'],
		'src-identifiers',
		[],
		skwAgContext(),
		skwAgSlugs()
	);

	expect($config['assignments'])->toBe(['breedte' => 'src-identifiers', 'kleur' => 'src-identifiers']);
});

test('bulk assignment back to automatic removes the manual assignment', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::apply_bulk_assignment(
		['kleur'],
		'__auto',
		['assignments' => ['kleur' => '__none', 'gtin' => '__none']],
		skwAgContext(),
		skwAgSlugs()
	);

	expect($config['assignments'])->toBe(['gtin' => '__none']);
});

test('bulk assignment to an unknown group changes nothing', function () {
	$config = Skwirrel_WC_Sync_Attribute_Groups::apply_bulk_assignment(['kleur'], 'missing', [], skwAgContext(), skwAgSlugs());

	expect($config['assignments'])->toBe([]);
});

// ------------------------------------------------------------------
// filter_rows() / layout_for()
// ------------------------------------------------------------------

function skwAgRows(): array {
	return [
		'weight'                  => ['label' => 'Weight', 'value' => '1 kg'],
		'attribute_pa_breedte'    => ['label' => 'Breedte', 'value' => '10'],
		'attribute_pa_gtin'       => ['label' => 'GTIN', 'value' => '123'],
		'attribute_pa_kostprijs'  => ['label' => 'Kostprijs', 'value' => '5'],
		'attribute_material'      => ['label' => 'Material', 'value' => 'Steel'],
		'attribute_pa_handmatig'  => ['label' => 'Handmatig', 'value' => 'x'],
		'attribute_pa_voorraad'   => ['label' => 'Voorraad', 'value' => '7'],
	];
}

function skwAgDisplayContext(): array {
	return skwAgContext(['groups' => [
		'src-etim'        => ['as_tab' => true],
		'src-cc-internal' => ['hidden' => true],
	]]);
}

test('Additional information drops hidden and tab groups and clusters the rest in group order', function () {
	$rows = Skwirrel_WC_Sync_Attribute_Groups::filter_rows(skwAgRows(), skwAgDisplayContext(), null);

	expect(array_keys($rows))->toBe(['weight', 'attribute_material', 'attribute_pa_handmatig', 'attribute_pa_gtin', 'attribute_pa_voorraad']);
});

test('a tab keeps only its own group', function () {
	$rows = Skwirrel_WC_Sync_Attribute_Groups::filter_rows(skwAgRows(), skwAgDisplayContext(), 'src-etim');

	expect(array_keys($rows))->toBe(['attribute_pa_breedte']);
});

test('a hidden or unknown group renders nothing', function ($gid) {
	expect(Skwirrel_WC_Sync_Attribute_Groups::filter_rows(skwAgRows(), skwAgDisplayContext(), $gid))->toBe([]);
})->with(['src-cc-internal', 'missing']);

test('layout lists tab groups and drops Additional information when nothing is left', function () {
	$ctx = skwAgDisplayContext();

	expect(Skwirrel_WC_Sync_Attribute_Groups::layout_for(['pa_breedte', 'pa_kostprijs'], false, $ctx))
		->toBe(['tabs' => ['src-etim'], 'additional_information' => false]);
	expect(Skwirrel_WC_Sync_Attribute_Groups::layout_for(['pa_breedte', 'pa_gtin'], false, $ctx))
		->toBe(['tabs' => ['src-etim'], 'additional_information' => true]);
	expect(Skwirrel_WC_Sync_Attribute_Groups::layout_for(['pa_breedte'], true, $ctx)['additional_information'])->toBeTrue();
});

// ------------------------------------------------------------------
// Storage
// ------------------------------------------------------------------

test('assign stores, changes and clears a manual assignment', function () {
	Skwirrel_WC_Sync_Attribute_Groups::assign('pa_size', 'technical');
	expect(Skwirrel_WC_Sync_Attribute_Groups::get_config()['assignments'])->toBe(['size' => 'technical']);

	Skwirrel_WC_Sync_Attribute_Groups::assign('size', '__none');
	expect(Skwirrel_WC_Sync_Attribute_Groups::get_config()['assignments'])->toBe(['size' => '__none']);

	Skwirrel_WC_Sync_Attribute_Groups::assign('size', '');
	expect(Skwirrel_WC_Sync_Attribute_Groups::get_config()['assignments'])->toBe([]);
});

// ------------------------------------------------------------------
// Hide in product editor
// ------------------------------------------------------------------

test('hide in product editor is saved for automatic and custom groups', function () {
	$existing = ['groups' => ['internal' => ['name' => 'Internal']]];
	$config   = Skwirrel_WC_Sync_Attribute_Groups::sanitize_groups_submission(
		[
			'groups'    => [
				'src-cc-internal' => ['name' => 'Intern', 'admin_hidden' => '1'],
				'internal'        => ['name' => 'Internal', 'admin_hidden' => '1'],
			],
			'new_group' => ['name' => 'Logistics', 'admin_hidden' => '1'],
		],
		$existing,
		skwAgContext($existing)
	);

	expect($config['groups']['src-cc-internal']['admin_hidden'])->toBeTrue();
	expect($config['groups']['internal']['admin_hidden'])->toBeTrue();
	expect($config['groups']['logistics']['admin_hidden'])->toBeTrue();
	expect(skwAgContext($config)['groups']['src-cc-internal']['admin_hidden'])->toBeTrue();
});

test('the product editor hides only attributes whose resolved group is editor-hidden', function () {
	$ctx = skwAgContext([
		'groups'      => [
			'src-cc-internal' => ['admin_hidden' => true],
			'backoffice'      => ['name' => 'Back office', 'admin_hidden' => true, 'includes' => ['src-identifiers']],
		],
		'assignments' => ['gtin' => 'src-etim', 'handmatig' => 'backoffice'],
	]);

	expect(Skwirrel_WC_Sync_Attribute_Groups::editor_hidden_taxonomies(
		['pa_kostprijs', 'pa_gtin', 'pa_manufacturer', 'pa_breedte', 'pa_handmatig', 'pa_kostprijs'],
		$ctx
	))->toBe(['pa_kostprijs', 'pa_manufacturer', 'pa_handmatig']);
});

test('editor hiding and product page hiding are independent', function () {
	$ctx = skwAgContext(['groups' => ['src-etim' => ['admin_hidden' => true]]]);

	expect(Skwirrel_WC_Sync_Attribute_Groups::editor_hidden_taxonomies(['pa_breedte'], $ctx))->toBe(['pa_breedte']);
	expect(array_keys(Skwirrel_WC_Sync_Attribute_Groups::filter_rows(['attribute_pa_breedte' => []], $ctx, null)))->toBe(['attribute_pa_breedte']);
});

// ------------------------------------------------------------------
// Review fixes
// ------------------------------------------------------------------

test('saving keeps includes of automatic groups that are absent right now', function () {
	$existing = ['groups' => ['technical' => ['name' => 'Technical', 'includes' => ['src-etim', 'src-cc-logistics']]]];
	$ctx      = skwAgContext($existing, false); // ETIM sync off: src-etim has no checkbox.

	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_groups_submission(
		['groups' => ['technical' => ['name' => 'Technical', 'includes' => ['src-identifiers']]]],
		$existing,
		$ctx
	);

	expect($config['groups']['technical']['includes'])->toBe(['src-identifiers', 'src-etim']);
	expect(skwAgContext($config)['groups']['technical']['includes'])->toBe(['src-identifiers', 'src-etim']);
});

test('long custom class codes sharing a prefix get distinct automatic group IDs', function () {
	$long = 'warehouse-location-netherlands-amsterdam-';
	$a    = Skwirrel_WC_Sync_Attribute_Groups::source_group_for(['source' => 'custom_class', 'class_key' => $long . 'north', 'class_name' => '']);
	$b    = Skwirrel_WC_Sync_Attribute_Groups::source_group_for(['source' => 'custom_class', 'class_key' => $long . 'south', 'class_name' => '']);

	expect($a['id'])->not->toBe($b['id']);
	expect(strlen($a['id']) <= 40)->toBeTrue();
	expect(str_starts_with($a['id'], 'src-cc-'))->toBeTrue();
	expect(Skwirrel_WC_Sync_Attribute_Groups::source_group_for(['source' => 'custom_class', 'class_key' => 'logistics', 'class_name' => ''])['id'])->toBe('src-cc-logistics');
});

test('saving keeps manual assignments to automatic groups that are absent right now', function () {
	$existing = [
		'groups'      => ['technical' => ['name' => 'Technical']],
		'assignments' => ['kleur' => 'src-etim', 'gtin' => 'technical', 'kostprijs' => 'gone-custom-group'],
	];

	$config = Skwirrel_WC_Sync_Attribute_Groups::sanitize_groups_submission(
		['groups' => ['technical' => ['name' => 'Technical']]],
		$existing,
		skwAgContext($existing, false)
	);

	expect($config['assignments'])->toBe(['gtin' => 'technical', 'kleur' => 'src-etim']);
	expect(Skwirrel_WC_Sync_Attribute_Groups::group_for_slug('kleur', skwAgContext($config)))->toBe('src-etim');
});

test('custom class codes that sanitize to the same slug get distinct automatic group IDs', function () {
	$a = Skwirrel_WC_Sync_Attribute_Groups::source_group_for(['source' => 'custom_class', 'class_key' => 'a.b', 'class_name' => '']);
	$b = Skwirrel_WC_Sync_Attribute_Groups::source_group_for(['source' => 'custom_class', 'class_key' => 'a-b', 'class_name' => '']);

	expect($a['id'])->not->toBe($b['id']);
	expect($b['id'])->toBe('src-cc-a-b');
});

// ------------------------------------------------------------------
// Telling apart groups that share a name
// ------------------------------------------------------------------

test('class_codes maps each custom class group to its class code', function () {
	$e     = fn (string $source, string $key = '', string $name = '') => ['source' => $source, 'class_key' => $key, 'class_name' => $name];
	$codes = Skwirrel_WC_Sync_Attribute_Groups::class_codes([
		'diagonaal'  => $e('custom_class', 'genormaliseerd_tablet_beeldscherm', 'Beeldscherm'),
		'helderheid' => $e('custom_class', 'genormaliseerd_tablet_beeldscherm', 'Beeldscherm'),
		'resolutie'  => $e('custom_class', 'genormaliseerd_monitor_beeldscherm', 'Beeldscherm'),
		'kleur'      => $e('etim'),
		'losse'      => $e('custom_class'),
	]);

	$id = fn (string $code) => Skwirrel_WC_Sync_Attribute_Groups::source_group_for($e('custom_class', $code, 'Beeldscherm'))['id'];
	expect($codes)->toBe([
		$id('genormaliseerd_tablet_beeldscherm')  => 'genormaliseerd_tablet_beeldscherm',
		$id('genormaliseerd_monitor_beeldscherm') => 'genormaliseerd_monitor_beeldscherm',
	]);
});

test('only groups whose name is not unique get a suffix: the class code, or else the group ID', function () {
	$groups = [
		'src-cc-tablet_scherm'  => ['name' => 'Beeldscherm'],
		'src-cc-monitor_scherm' => ['name' => 'Beeldscherm'],
		'beeldscherm'           => ['name' => ' beeldscherm '],
		'src-etim'              => ['name' => 'ETIM'],
		'src-cc-camera'         => ['name' => 'Camera'],
	];
	$codes  = [
		'src-cc-tablet_scherm'  => 'tablet_scherm',
		'src-cc-monitor_scherm' => 'monitor_scherm',
		'src-cc-camera'         => 'camera',
	];

	expect(Skwirrel_WC_Sync_Attribute_Groups::name_suffixes($groups, $codes))->toBe([
		'src-cc-tablet_scherm'  => 'tablet_scherm',
		'src-cc-monitor_scherm' => 'monitor_scherm',
		'beeldscherm'           => 'beeldscherm',
		'src-etim'              => '',
		'src-cc-camera'         => '',
	]);
});

test('choice_label appends a suffix only when there is one', function () {
	expect(Skwirrel_WC_Sync_Attribute_Groups::choice_label('Camera', ''))->toBe('Camera');
	expect(Skwirrel_WC_Sync_Attribute_Groups::choice_label('Camera', 'genormaliseerd_tablet_camera'))->toBe('Camera · genormaliseerd_tablet_camera');
});

// ------------------------------------------------------------------
// Attribute list sorting
// ------------------------------------------------------------------

function skwAgSortRows(): array {
	$r = fn (string $label, string $source, string $group) => ['label' => $label, 'source' => $source, 'group_label' => $group];
	return [
		'kleur'   => $r('Kleur', 'ETIM', 'Technisch'),
		'breedte' => $r('Breedte', 'ETIM', 'Afmetingen'),
		'gtin'    => $r('GTIN', 'Identifier', ''),
		'maat10'  => $r('Maat 10', 'Custom class: Kleding', 'Technisch'),
		'maat9'   => $r('maat 9', 'Custom class: Kleding', 'Afmetingen'),
	];
}

test('sanitize_sort allows only known columns and directions', function ($orderby, $order, $expected) {
	expect(Skwirrel_WC_Sync_Attribute_Groups::sanitize_sort($orderby, $order))->toBe($expected);
})->with([
	['group', 'desc', ['orderby' => 'group', 'order' => 'desc']],
	['SLUG', 'ASC', ['orderby' => 'slug', 'order' => 'asc']],
	['label; drop', 'sideways', ['orderby' => 'attribute', 'order' => 'asc']],
	['', '', ['orderby' => 'attribute', 'order' => 'asc']],
]);

test('sort_rows sorts by attribute name naturally and case-insensitively', function () {
	expect(array_keys(Skwirrel_WC_Sync_Attribute_Groups::sort_rows(skwAgSortRows(), 'attribute', 'asc')))
		->toBe(['breedte', 'gtin', 'kleur', 'maat9', 'maat10']);
	expect(array_keys(Skwirrel_WC_Sync_Attribute_Groups::sort_rows(skwAgSortRows(), 'attribute', 'desc')))
		->toBe(['maat10', 'maat9', 'kleur', 'gtin', 'breedte']);
});

test('sort_rows sorts by slug', function () {
	expect(array_keys(Skwirrel_WC_Sync_Attribute_Groups::sort_rows(skwAgSortRows(), 'slug', 'asc')))
		->toBe(['breedte', 'gtin', 'kleur', 'maat9', 'maat10']);
});

test('sort_rows keeps ties in their incoming order in both directions', function () {
	expect(array_keys(Skwirrel_WC_Sync_Attribute_Groups::sort_rows(skwAgSortRows(), 'source', 'asc')))
		->toBe(['maat10', 'maat9', 'kleur', 'breedte', 'gtin']);
	expect(array_keys(Skwirrel_WC_Sync_Attribute_Groups::sort_rows(skwAgSortRows(), 'group', 'desc')))
		->toBe(['kleur', 'maat10', 'breedte', 'maat9', 'gtin']);
});

test('sort_rows falls back to the attribute name for an unknown column', function () {
	expect(array_keys(Skwirrel_WC_Sync_Attribute_Groups::sort_rows(skwAgSortRows(), 'price', 'asc')))
		->toBe(['breedte', 'gtin', 'kleur', 'maat9', 'maat10']);
});

test('summary counts the groups and the ones shown as a tab', function () {
	$ctx = skwAgContext(['groups' => ['src-etim' => ['name' => '', 'as_tab' => true], 'tech' => ['name' => 'Technisch', 'as_tab' => true]]]);

	expect(Skwirrel_WC_Sync_Attribute_Groups::summary($ctx['groups']))->toBe(['groups' => 7, 'tabs' => 2]);
	expect(Skwirrel_WC_Sync_Attribute_Groups::summary([]))->toBe(['groups' => 0, 'tabs' => 0]);
});

test('an inactive group gets a readable name when its source is known, else its ID', function () {
	$sources = ['voorraad' => ['source' => 'custom_class', 'class_key' => 'logistics', 'class_name' => 'Logistiek']];

	expect(Skwirrel_WC_Sync_Attribute_Groups::inactive_group_name('src-cc-logistics', $sources))->toBe('Logistiek')
		->and(Skwirrel_WC_Sync_Attribute_Groups::inactive_group_name('src-etim', $sources))->toBe(Skwirrel_WC_Sync_Attribute_Groups::source_group_for(['source' => 'etim', 'class_key' => '', 'class_name' => ''])['name'])
		->and(Skwirrel_WC_Sync_Attribute_Groups::inactive_group_name('src-cc-gone', $sources))->toBe('src-cc-gone');
});
