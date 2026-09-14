<?php

declare(strict_types=1);

/**
 * `sync_etim` — lets a shop opt out of ETIM. Off means no ETIM attributes on products and no
 * `include_etim` on getProducts / getProductsByFilter, unless grouped products still need the
 * payload for their variation axes.
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-history.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php';

afterEach(function () {
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

function skw_etim_product(): array {
	return [
		'product_id' => 1,
		'_etim'      => [
			[
				'_etim_features' => [
					[
						'etim_feature_code'          => 'EF001234',
						'etim_feature_type'          => 'L',
						'logical_value'              => true,
						'not_applicable'             => false,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Draadloos'],
						],
					],
				],
			],
		],
	];
}

test('ETIM payload is requested when the setting was never saved (default on)', function () {
	expect(Skwirrel_WC_Sync_Service::needs_etim_payload([]))->toBeTrue();
});

test('ETIM payload is requested or skipped per sync_etim and grouped products', function (bool $sync_etim, bool $grouped, bool $expected) {
	$options = ['sync_etim' => $sync_etim, 'sync_grouped_products' => $grouped];

	expect(Skwirrel_WC_Sync_Service::needs_etim_payload($options))->toBe($expected);
})->with([
	'etim on, grouped off'  => [true, false, true],
	'etim on, grouped on'   => [true, true, true],
	'etim off, grouped off' => [false, false, false],
	// Variation axis values come from the member product's own `_etim`.
	'etim off, grouped on'  => [false, true, true],
]);

test('get_attributes adds ETIM features when sync_etim is unset', function () {
	$attrs = (new Skwirrel_WC_Sync_Product_Mapper())->get_attributes(skw_etim_product());

	expect($attrs)->not->toBe([]);
});

test('get_attributes skips ETIM features when sync_etim is off, even if the payload has them', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['sync_etim' => false];

	$product                 = skw_etim_product();
	$product['product_gtin'] = '8711893004885';

	$attrs = (new Skwirrel_WC_Sync_Product_Mapper())->get_attributes($product);

	expect($attrs)->toBe(['GTIN' => '8711893004885']);
});

test('sanitize_settings stores sync_etim from the checkbox', function () {
	$settings = Skwirrel_WC_Sync_Admin_Settings::instance();

	expect($settings->sanitize_settings(['sync_etim' => '1'])['sync_etim'])->toBeTrue();
	expect($settings->sanitize_settings([])['sync_etim'])->toBeFalse();
});

test('the sync_etim strings are in the POT and in all seven locales', function () {
	$languages  = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';
	$strings    = [
		'Sync ETIM features',
		'Sync ETIM features: adds ETIM features as product attributes. When off, ETIM data is not requested — unless grouped products are on, which need it for their variation axes.',
	];
	$catalogues = array_merge(
		[$languages . '/skwirrel-pim-sync.pot'],
		glob($languages . '/skwirrel-pim-sync-*.po') ?: []
	);

	expect($catalogues)->toHaveCount(8);

	foreach ($catalogues as $catalogue) {
		// Wrapped msgids span lines; join them before matching.
		$content = (string) preg_replace('/"[ \t]*\R[ \t]*"/', '', (string) file_get_contents($catalogue));
		foreach ($strings as $string) {
			expect(str_contains($content, 'msgid "' . $string . '"'))
				->toBeTrue(basename($catalogue) . ' is missing: ' . $string);
		}
	}
});
