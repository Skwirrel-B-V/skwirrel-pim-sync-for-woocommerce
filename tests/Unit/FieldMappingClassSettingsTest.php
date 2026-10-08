<?php

declare(strict_types=1);

/**
 * Saving the field mappings with an optional custom class next to each feature.
 *
 * The class is optional — empty means "first class with a value". The feature is what turns a
 * mapping on, so a class without a feature is a mistake the save must report at the feature
 * field rather than silently ignore.
 */

beforeEach(function () {
    $GLOBALS['wp_settings_errors'] = [];
});

afterEach(function () {
    $GLOBALS['wp_settings_errors'] = [];
});

/**
 * Save the settings form with otherwise valid input.
 *
 * @param array<string,string> $mapping Field mapping input.
 * @return array<string,mixed> Sanitised settings.
 */
function save_field_mapping(array $mapping): array
{
    return Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(['collection_ids' => '1'] + $mapping);
}

test('a class is saved next to its feature', function () {
    $out = save_field_mapping([
        'stock_quantity_feature' => 'FUSE5_QTY_ONHAND',
        'stock_quantity_class'   => ' USPC_FUSE5_DALLAS ',
    ]);

    expect($out['stock_quantity_class'])->toBe('USPC_FUSE5_DALLAS');
    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe([]);
});

test('a class without a feature is reported at the feature field', function (string $feature_key, string $class_key) {
    $out = save_field_mapping([$class_key => 'USPC_FUSE5_DALLAS']);

    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe([$feature_key]);
    expect($out[$class_key])->toBe('USPC_FUSE5_DALLAS');
})->with([
    ['stock_quantity_feature', 'stock_quantity_class'],
    ['title_feature_id', 'title_class_id'],
    ['short_description_feature_id', 'short_description_class_id'],
    ['long_description_feature_id', 'long_description_class_id'],
]);

test('a feature without a class saves cleanly', function () {
    save_field_mapping(['title_feature_id' => 'TITLE']);

    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe([]);
});
