<?php

declare(strict_types=1);

// The attribute-form hooks carry the guarantee that only the WooCommerce attribute form changes
// assignments: a sync calling wc_create_attribute() or a REST request has no form field.

if (!function_exists('is_admin')) {
	function is_admin(): bool {
		return true;
	}
}
if (!function_exists('current_user_can')) {
	function current_user_can(string $capability, ...$args): bool {
		return $GLOBALS['_test_can'] ?? true;
	}
}
if (!function_exists('wp_unslash')) {
	function wp_unslash($value) {
		return is_string($value) ? stripslashes($value) : $value;
	}
}
if (!function_exists('wc_get_attribute_taxonomies')) {
	function wc_get_attribute_taxonomies(): array {
		return array_map(
			static fn (string $slug) => (object) ['attribute_name' => $slug, 'attribute_label' => ucfirst($slug)],
			$GLOBALS['_test_attribute_slugs'] ?? []
		);
	}
}

beforeEach(function () {
	$GLOBALS['_test_attribute_slugs'] = ['kleur', 'breedte', 'width'];
	$GLOBALS['_test_can']             = true;
	$_POST                            = [];
	Skwirrel_WC_Sync_Attribute_Sources::reset_pending();
	Skwirrel_WC_Sync_Attribute_Groups::save_config(['groups' => ['technical' => ['name' => 'Technical']]]);
	$this->groups = Skwirrel_WC_Sync_Attribute_Groups::instance();
});

afterEach(function () {
	$_POST = [];
	unset(
		$GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Groups::OPTION_KEY],
		$GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY],
		$GLOBALS['_test_attribute_slugs'],
		$GLOBALS['_test_can']
	);
	Skwirrel_WC_Sync_Attribute_Sources::reset_pending();
});

function skwAgAssignments(): array {
	return Skwirrel_WC_Sync_Attribute_Groups::get_config()['assignments'];
}

test('adding an attribute without the form field (sync, REST) leaves assignments alone', function () {
	$this->groups->on_attribute_added(1, ['attribute_name' => 'kleur']);

	expect(skwAgAssignments())->toBe([]);
});

test('adding an attribute through the form stores the chosen group', function () {
	$_POST['skwirrel_attribute_group'] = 'technical';

	$this->groups->on_attribute_added(1, ['attribute_name' => 'kleur']);

	expect(skwAgAssignments())->toBe(['kleur' => 'technical']);
});

test('the form field is ignored without the capability or with an unknown group', function () {
	$_POST['skwirrel_attribute_group'] = 'technical';
	$GLOBALS['_test_can']              = false;
	$this->groups->on_attribute_added(1, ['attribute_name' => 'kleur']);

	$GLOBALS['_test_can']              = true;
	$_POST['skwirrel_attribute_group'] = 'no-such-group';
	$this->groups->on_attribute_added(2, ['attribute_name' => 'breedte']);

	expect(skwAgAssignments())->toBe([]);
});

test('updating without the form field keeps the assignment and source when the slug is renamed', function () {
	Skwirrel_WC_Sync_Attribute_Groups::assign('breedte', 'technical');
	$GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY] = [
		'breedte' => ['source' => 'etim', 'class_key' => '', 'class_name' => ''],
	];

	$this->groups->on_attribute_updated(2, ['attribute_name' => 'width'], 'breedte');

	expect(skwAgAssignments())->toBe(['width' => 'technical']);
	expect(array_keys(Skwirrel_WC_Sync_Attribute_Sources::all()))->toBe(['width']);
});

test('updating without the form field and without a rename changes nothing', function () {
	Skwirrel_WC_Sync_Attribute_Groups::assign('kleur', 'technical');

	$this->groups->on_attribute_updated(1, ['attribute_name' => 'kleur'], 'kleur');

	expect(skwAgAssignments())->toBe(['kleur' => 'technical']);
});

test('updating through the form applies the choice, and Automatic clears the assignment', function () {
	Skwirrel_WC_Sync_Attribute_Groups::assign('kleur', 'technical');
	$_POST['skwirrel_attribute_group'] = '';

	$this->groups->on_attribute_updated(1, ['attribute_name' => 'kleur'], 'kleur');

	expect(skwAgAssignments())->toBe([]);
});

test('deleting an attribute removes its assignment and its source', function () {
	Skwirrel_WC_Sync_Attribute_Groups::assign('kleur', '__none');
	$GLOBALS['_test_options'][Skwirrel_WC_Sync_Attribute_Sources::OPTION_KEY] = [
		'kleur' => ['source' => 'etim', 'class_key' => '', 'class_name' => ''],
	];

	$this->groups->on_attribute_deleted(1, 'kleur');

	expect(skwAgAssignments())->toBe([]);
	expect(Skwirrel_WC_Sync_Attribute_Sources::all())->toBe([]);
});
