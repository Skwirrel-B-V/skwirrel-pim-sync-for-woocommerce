<?php

declare(strict_types=1);

/**
 * The required-field registry, and the guarantee that it never disagrees with the rules
 * sanitize_settings() actually enforces.
 */

beforeEach(function () {
    $GLOBALS['wp_settings_errors'] = [];
});

afterEach(function () {
    $GLOBALS['wp_settings_errors'] = [];
});

/**
 * Every combination of the four checkboxes that govern a conditional requirement.
 *
 * @return array<int, array<string, bool>>
 */
function skw_checkbox_combinations(): array
{
    $keys = [
        'sync_categories',
        'sync_custom_classes',
        'sync_trade_item_custom_classes',
        'sync_grouped_products',
    ];

    $combinations = [];
    for ($mask = 0; $mask < (1 << count($keys)); $mask++) {
        $combination = [];
        foreach ($keys as $bit => $key) {
            $combination[$key] = (bool) ($mask & (1 << $bit));
        }
        $combinations[] = $combination;
    }

    return $combinations;
}

test('the unconditionally required fields are always required', function () {
    foreach (skw_checkbox_combinations() as $values) {
        $required = Skwirrel_WC_Sync_Admin_Settings::required_fields($values);

        expect($required['skwirrel_base_url'])->toBeTrue();
        expect($required['collection_ids'])->toBeTrue();
    }
});

test('super_category_id is required exactly when category sync is on', function () {
    foreach (skw_checkbox_combinations() as $values) {
        $required = Skwirrel_WC_Sync_Admin_Settings::required_fields($values);

        expect($required['super_category_id'])->toBe($values['sync_categories']);
    }
});

test('custom_collection_id is required exactly when any consuming feature is on', function () {
    foreach (skw_checkbox_combinations() as $values) {
        $expected = $values['sync_custom_classes']
            || $values['sync_trade_item_custom_classes']
            || $values['sync_grouped_products'];

        $required = Skwirrel_WC_Sync_Admin_Settings::required_fields($values);

        expect($required['custom_collection_id'])->toBe($expected);
    }
});

test('an empty settings array requires only the unconditional fields', function () {
    expect(Skwirrel_WC_Sync_Admin_Settings::required_fields([]))->toBe([
        'skwirrel_base_url' => true,
        'collection_ids' => true,
        'super_category_id' => false,
        'custom_collection_id' => false,
    ]);
});

test('is_field_required matches the registry and is false for an unknown field', function () {
    $values = ['sync_categories' => true];

    expect(Skwirrel_WC_Sync_Admin_Settings::is_field_required($values, 'super_category_id'))->toBeTrue();
    expect(Skwirrel_WC_Sync_Admin_Settings::is_field_required($values, 'custom_collection_id'))->toBeFalse();
    expect(Skwirrel_WC_Sync_Admin_Settings::is_field_required($values, 'auth_token'))->toBeFalse();
});

/*
 * Widened for story 5.3: `context_id` is validated but optional, so the required-field registry is
 * no longer the full set of fields an error can point at. The check that matters is unchanged —
 * every mapped field must be a field the screen actually renders — so it now reads the tab
 * registry, which lists every field ID the settings screen knows, required or not.
 */
test('every error code maps to a field the settings screen knows about', function () {
    $known = array_keys(Skwirrel_WC_Sync_Admin_Settings::required_fields([]));
    foreach (Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs() as $tab) {
        $known = array_merge($known, $tab['fields'] ?? []);
    }

    foreach (Skwirrel_WC_Sync_Admin_Settings::error_field_map() as $code => $field) {
        expect(in_array($field, $known, true))
            ->toBeTrue("error code {$code} maps to unknown field {$field}");
    }
});

/**
 * The regression that matters: a field the registry marks required is exactly a field
 * sanitize_settings() rejects when it is empty. If these two ever drift the form starts
 * lying about what it will accept.
 *
 * skwirrel_base_url is excluded on purpose — it is a JavaScript-only helper that writes the
 * hidden endpoint_url input and has no server-side rule. That gap is recorded in the story's
 * open question, not closed here.
 */
test('the registry and sanitize_settings agree on every checkbox combination', function () {
    $settings = Skwirrel_WC_Sync_Admin_Settings::instance();
    $validated = ['super_category_id', 'collection_ids', 'custom_collection_id'];

    foreach (skw_checkbox_combinations() as $values) {
        $GLOBALS['wp_settings_errors'] = [];

        // Every governed field left empty, so a required field must produce an error.
        $input = $values + [
            'super_category_id' => '',
            'collection_ids' => '',
            'custom_collection_id' => '',
        ];

        $settings->sanitize_settings($input);

        $rejected = Skwirrel_WC_Sync_Admin_Settings::failing_field_ids();
        sort($rejected);

        $expected = array_values(array_filter(
            $validated,
            static fn (string $field): bool
                => Skwirrel_WC_Sync_Admin_Settings::is_field_required($input, $field)
        ));
        sort($expected);

        expect($rejected)->toBe($expected);
    }
});

test('a filled-in value clears the error for a required field', function () {
    $settings = Skwirrel_WC_Sync_Admin_Settings::instance();

    $settings->sanitize_settings([
        'sync_categories' => '1',
        'sync_custom_classes' => '1',
        'super_category_id' => '42',
        'collection_ids' => '123, 456',
        'custom_collection_id' => '5',
    ]);

    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe([]);
    expect(Skwirrel_WC_Sync_Admin_Settings::has_settings_error())->toBeFalse();
});

test('failing_field_ids reports each field once and skips codes with no field', function () {
    add_settings_error('skwirrel_wc_sync_settings', 'collection_ids_required', 'a', 'error');
    add_settings_error('skwirrel_wc_sync_settings', 'collection_ids_required', 'b', 'error');
    add_settings_error('skwirrel_wc_sync_settings', 'something_unmapped', 'c', 'error');

    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe(['collection_ids']);
    expect(Skwirrel_WC_Sync_Admin_Settings::has_settings_error())->toBeTrue();
});

test('has_settings_error ignores non-error severities', function () {
    add_settings_error('skwirrel_wc_sync_settings', 'some_notice', 'heads up', 'updated');

    expect(Skwirrel_WC_Sync_Admin_Settings::has_settings_error())->toBeFalse();
});

/*
 * ---------------------------------------------------------------------------
 * Gap coverage added by the QA E2E pass (bmad-qa-generate-e2e-tests, 2026-08-26).
 * ---------------------------------------------------------------------------
 */

/**
 * AC2 — the registry and the rule table are two views of one thing. A conditional field with no
 * rule entry would render a marker the toggle can never follow; an unconditional field with one
 * would render `data-skw-req-when` pointing at a condition that does not govern it.
 */
test('the rule table covers exactly the conditionally required fields', function () {
    $registry     = Skwirrel_WC_Sync_Admin_Settings::required_fields([]);
    $unconditional = Skwirrel_WC_Sync_Admin_Settings::unconditional_required_fields();
    $rules        = Skwirrel_WC_Sync_Admin_Settings::conditional_required_rules();

    // Every field the registry knows is either unconditional or has a rule — never both, never neither.
    foreach (array_keys($registry) as $field) {
        $is_unconditional = in_array($field, $unconditional, true);
        $has_rule         = isset($rules[$field]);

        expect($is_unconditional xor $has_rule)
            ->toBeTrue("{$field} is neither unconditional nor governed by a rule, or is both");
    }

    // And every rule names at least one key, so a marker can never watch nothing.
    foreach ($rules as $field => $keys) {
        expect($keys)->not->toBeEmpty("{$field} has an empty rule");
        foreach ($keys as $key) {
            expect($key)->toBeString();
            expect(Skwirrel_WC_Sync_Admin_Settings::is_field_required([$key => true], $field))
                ->toBeTrue("{$key} does not actually make {$field} required");
        }
    }
});

/**
 * AC2 — a rule key on its own is enough. Tested per-key so a rule that silently requires two
 * checkboxes at once (an AND where the story specifies an OR) cannot pass.
 */
test('each governing key makes its field required on its own', function () {
    foreach (Skwirrel_WC_Sync_Admin_Settings::conditional_required_rules() as $field => $keys) {
        foreach ($keys as $key) {
            $values = array_fill_keys($keys, false);
            $values[$key] = true;

            expect(Skwirrel_WC_Sync_Admin_Settings::is_field_required($values, $field))
                ->toBeTrue("{$field} is not required by {$key} alone");
        }

        expect(Skwirrel_WC_Sync_Admin_Settings::is_field_required(array_fill_keys($keys, false), $field))
            ->toBeFalse("{$field} is required with every governing key off");
    }
});

/**
 * AC5 — every user-facing string this story added is in the catalogues. The `.po` files wrap long
 * msgids across lines, so the catalogue is unwrapped before matching.
 */
function skw_catalogue_msgids(string $path): string
{
    $raw = (string) file_get_contents($path);

    // Join the continuation lines of a wrapped msgid back into one string.
    return (string) preg_replace('/"[ \t]*\R[ \t]*"/', '', $raw);
}

test('the strings this story added are in the POT and in all seven locales', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';

    $strings = [
        'required',
        'Category sync is enabled but no valid super category ID is set. Please enter a super category ID greater than 0.',
        'A custom class collection ID greater than 0 is required when syncing custom classes or grouped products.',
    ];

    $catalogues = array_merge(
        [$languages . '/skwirrel-pim-sync.pot'],
        glob($languages . '/skwirrel-pim-sync-*.po') ?: []
    );

    // The seven shipped locales plus the POT.
    expect($catalogues)->toHaveCount(8);

    foreach ($catalogues as $catalogue) {
        $content = skw_catalogue_msgids($catalogue);
        foreach ($strings as $string) {
            expect(str_contains($content, 'msgid "' . $string . '"'))
                ->toBeTrue(basename($catalogue) . ' is missing: ' . $string);
        }
    }
});

/**
 * AC5 — and each locale ships a compiled `.mo` beside its `.po`, since only the `.mo` is loaded
 * at runtime. A regenerated `.po` that was never compiled is a translation nobody sees.
 */
test('every locale ships a compiled catalogue next to its source', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';

    $po = glob($languages . '/skwirrel-pim-sync-*.po') ?: [];
    expect($po)->toHaveCount(7);

    foreach ($po as $source) {
        $compiled = preg_replace('/\.po$/', '.mo', $source);

        expect(file_exists($compiled))->toBeTrue(basename($compiled) . ' is missing');
        expect(filemtime($compiled))->toBeGreaterThanOrEqual(
            filemtime($source),
            basename($compiled) . ' is older than its .po — recompile it'
        );
    }
});
