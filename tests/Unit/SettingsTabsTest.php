<?php

declare(strict_types=1);

beforeEach(function () {
    $GLOBALS['_test_filters'] = [];
});

afterEach(function () {
    $GLOBALS['_test_filters'] = [];
});

test('the registry ships the five settings tabs in a deterministic order', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    expect(array_keys($tabs))->toBe(['connection', 'what-to-sync', 'field-mapping', 'how-it-looks', 'advanced']);
    expect($tabs['connection']['label'])->toBe('Connection');
    expect($tabs['what-to-sync']['label'])->toBe('What to sync');
    expect($tabs['field-mapping']['label'])->toBe('Field mapping');
    expect($tabs['how-it-looks']['label'])->toBe('How it looks');
    expect($tabs['advanced']['label'])->toBe('Advanced');
});

test('every default tab has a renderer that exists on the dashboard class', function () {
    foreach (Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs() as $slug => $tab) {
        expect(method_exists(Skwirrel_WC_Sync_Admin_Dashboard::class, $tab['render']))
            ->toBeTrue("tab {$slug} has no renderer");
    }
});

test('an external tab registers through the filter and lands at its order position', function () {
    add_filter('skwirrel_wc_sync_settings_tabs', function (array $tabs): array {
        $tabs['external-mapping'] = [
            'label'  => 'External mapping',
            'order'  => 25,
            'render' => 'my_render_callback',
            'fields' => ['mapping_source'],
        ];

        return $tabs;
    });

    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    expect(array_keys($tabs))->toBe(['connection', 'what-to-sync', 'field-mapping', 'external-mapping', 'how-it-looks', 'advanced']);
    expect($tabs['external-mapping']['fields'])->toBe(['mapping_source']);
});

test('a tab registered without an order sorts last', function () {
    add_filter('skwirrel_wc_sync_settings_tabs', function (array $tabs): array {
        $tabs['extra'] = ['label' => 'Extra', 'render' => 'cb'];

        return $tabs;
    });

    expect(array_key_last(Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs()))->toBe('extra');
});

test('tabs sharing an order keep their registration order', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::normalize_settings_tabs([
        'b' => ['label' => 'B', 'order' => 10],
        'a' => ['label' => 'A', 'order' => 10],
        'c' => ['label' => 'C', 'order' => 5],
    ]);

    expect(array_keys($tabs))->toBe(['c', 'b', 'a']);
});

test('malformed registrations are dropped rather than rendered', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::normalize_settings_tabs([
        'good'      => ['label' => 'Good', 'order' => 1],
        'no-label'  => ['order' => 2],
        'blank'     => ['label' => '', 'order' => 3],
        'not-array' => 'nope',
        7           => ['label' => 'Numeric key', 'order' => 4],
    ]);

    expect(array_keys($tabs))->toBe(['good']);
});

test('a non-array filter result falls back to the built-in registry', function () {
    add_filter('skwirrel_wc_sync_settings_tabs', function (array $tabs) {
        return 'invalid';
    });

    expect(array_keys(Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs()))
        ->toBe(['connection', 'what-to-sync', 'field-mapping', 'how-it-looks', 'advanced']);
});

test('an empty filtered registry falls back to the built-in panels', function () {
    add_filter('skwirrel_wc_sync_settings_tabs', function (array $tabs): array {
        return [];
    });

    expect(array_keys(Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs()))
        ->toBe(['connection', 'what-to-sync', 'field-mapping', 'how-it-looks', 'advanced']);
});

test('a filter cannot remove or replace a built-in panel and drop its settings from the form', function () {
    add_filter('skwirrel_wc_sync_settings_tabs', function (array $tabs): array {
        unset($tabs['what-to-sync']);
        $tabs['connection']['render'] = 'missing_renderer';
        $tabs['connection']['fields'] = [];
        $tabs['external-mapping'] = [
            'label'  => 'External mapping',
            'order'  => 25,
            'render' => 'my_render_callback',
        ];

        return $tabs;
    });

    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    expect(array_keys($tabs))
        ->toBe(['connection', 'what-to-sync', 'field-mapping', 'external-mapping', 'how-it-looks', 'advanced']);
    expect($tabs['connection']['render'])->toBe('render_settings_panel_connection');
    expect($tabs['connection']['fields'])->toContain('endpoint_url');
});

test('a slug is reduced to safe characters', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::normalize_settings_tabs([
        'Field Mapping!' => ['label' => 'Field mapping'],
    ]);

    expect(array_keys($tabs))->toBe(['fieldmapping']);
});

test('field lists are normalised to strings', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::normalize_settings_tabs([
        'x' => ['label' => 'X', 'fields' => ['ok', '', 42, ['nested']]],
    ]);

    expect($tabs['x']['fields'])->toBe(['ok']);
});

test('the three sanitiser error codes all resolve to the What to sync tab', function (string $code) {
    $tabs   = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();
    $counts = Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab([$code], $tabs);

    expect($counts)->toBe(['what-to-sync' => 1]);
})->with([
    'super_category_id_required',
    'collection_ids_required',
    'custom_collection_id_required',
]);

test('several errors on one tab are counted, not collapsed', function () {
    $tabs   = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();
    $counts = Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(
        ['super_category_id_required', 'collection_ids_required'],
        $tabs
    );

    expect($counts)->toBe(['what-to-sync' => 2]);
});

test('an error code matching a field id directly resolves too', function () {
    $tabs   = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();
    $counts = Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(['auth_token'], $tabs);

    expect($counts)->toBe(['connection' => 1]);
});

test('representative field ids resolve to the tab that actually renders them', function (string $field, string $slug) {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab([$field], $tabs))
        ->toBe([$slug => 1]);
})->with([
    ['endpoint_url', 'connection'],
    ['batch_size', 'what-to-sync'],
    ['title_feature_id', 'field-mapping'],
    ['image_language', 'how-it-looks'],
    ['sync_interval', 'advanced'],
    ['log_retention', 'advanced'],
]);

test('an unknown error code is attributed to no tab', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(['something_else'], $tabs))->toBe([]);
    expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab([''], $tabs))->toBe([]);
});

test('the first tab holding an error opens, overriding the default tab', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    expect(Skwirrel_WC_Sync_Admin_Dashboard::first_settings_tab(['what-to-sync' => 1], $tabs))->toBe('what-to-sync');
});

test('with errors on several tabs the first one in tab order opens', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    $first = Skwirrel_WC_Sync_Admin_Dashboard::first_settings_tab(
        ['advanced' => 1, 'connection' => 2],
        $tabs
    );

    expect($first)->toBe('connection');
});

test('without errors the first registered tab opens', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    expect(Skwirrel_WC_Sync_Admin_Dashboard::first_settings_tab([], $tabs))->toBe('connection');
    expect(Skwirrel_WC_Sync_Admin_Dashboard::first_settings_tab(['what-to-sync' => 0], $tabs))->toBe('connection');
});

test('an empty registry yields no opening tab instead of an error', function () {
    expect(Skwirrel_WC_Sync_Admin_Dashboard::first_settings_tab([], []))->toBe('');
});

// --- Gaps found by the QA E2E-test pass (AC 3, 4, 7) -------------------------------------------

test('an errored slug that is not in the registry cannot steal the opening tab', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    // A stale slug (a tab that was unregistered between the save and the re-render) must not
    // decide the opening tab, and must not blank it either.
    expect(Skwirrel_WC_Sync_Admin_Dashboard::first_settings_tab(['stale-tab' => 3], $tabs))
        ->toBe('connection');
});

test('a filter-registered tab claims its own error codes', function () {
    add_filter('skwirrel_wc_sync_settings_tabs', function (array $tabs): array {
        $tabs['external-mapping'] = [
            'label'  => 'External mapping',
            'order'  => 25,
            'render' => 'cb',
            'fields' => ['mapping_source'],
        ];

        return $tabs;
    });

    $tabs   = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();
    $counts = Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(['mapping_source_required'], $tabs);

    expect($counts)->toBe(['external-mapping' => 1]);
    expect(Skwirrel_WC_Sync_Admin_Dashboard::first_settings_tab($counts, $tabs))->toBe('external-mapping');
});

test('a code claimed by two tabs is counted once, on the first tab in order', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::normalize_settings_tabs([
        'first'  => ['label' => 'First', 'order' => 10, 'fields' => ['shared_field']],
        'second' => ['label' => 'Second', 'order' => 20, 'fields' => ['shared_field']],
    ]);

    expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(['shared_field'], $tabs))
        ->toBe(['first' => 1]);
});

test('_required is only stripped from the end of a code', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::normalize_settings_tabs([
        'x' => ['label' => 'X', 'fields' => ['batch_size']],
    ]);

    // A code that merely contains the word must not be rewritten into a field id.
    expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(['batch_size_required_twice'], $tabs))
        ->toBe([]);
    expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(['batch_size_required'], $tabs))
        ->toBe(['x' => 1]);
});

test('the same field raised twice is announced as one failing field', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(
        ['super_category_id_required', 'super_category_id_required'],
        $tabs
    ))->toBe(['what-to-sync' => 1]);
});

test('a closure renderer survives normalisation, so an outside tab can render itself', function () {
    $renderer = static function (array $context): void {};

    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::normalize_settings_tabs([
        'field-mapping' => ['label' => 'Field mapping', 'render' => $renderer],
    ]);

    expect($tabs['field-mapping']['render'])->toBe($renderer);
    expect(is_callable($tabs['field-mapping']['render']))->toBeTrue();
});

test('every built-in tab declares the field ids its own sanitiser rules can flag', function () {
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();

    // The three codes the sanitiser raises today must each resolve, or the badge silently
    // stops appearing when a field is renamed.
    foreach (['super_category_id_required', 'collection_ids_required', 'custom_collection_id_required'] as $code) {
        expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab([$code], $tabs))->not->toBe([]);
    }

    // And no tab ships an empty field list, which would make it unroutable by construction.
    foreach ($tabs as $slug => $tab) {
        expect($tab['fields'])->not->toBe([], "tab {$slug} declares no fields");
    }
});
