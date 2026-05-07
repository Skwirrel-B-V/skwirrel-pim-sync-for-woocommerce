<?php

declare(strict_types=1);

beforeEach(function () {
	$this->extractor = new Skwirrel_WC_Sync_Custom_Class_Extractor( 'nl' );
	$GLOBALS['_test_options'] = [];
});

afterEach(function () {
	$GLOBALS['_test_options'] = [];
});

// Helper to build a feature payload with translations.
function cc_feature(array $overrides): array {
	return array_merge( [
		'custom_feature_code' => 'CF_' . strtoupper( bin2hex( random_bytes( 3 ) ) ),
		'custom_feature_type' => 'L',
		'_custom_feature_translations' => [
			[ 'language' => 'nl', 'custom_feature_description' => 'Label NL' ],
		],
	], $overrides );
}

function cc_class(array $features, array $overrides = []): array {
	return array_merge( [
		'custom_class_id'   => 1,
		'custom_class_code' => 'cc_test',
		'_custom_features'  => $features,
	], $overrides );
}

// ------------------------------------------------------------------
// collect_custom_classes()
// ------------------------------------------------------------------

test('collect_custom_classes returns product-level _custom_classes', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [ cc_feature( [ 'custom_feature_code' => 'CF1' ] ) ] ),
		],
	];

	expect( $this->extractor->collect_custom_classes( $product ) )->toHaveCount( 1 );
});

test('collect_custom_classes skips classes with empty _custom_features', function () {
	$product = [
		'_custom_classes' => [
			[ 'custom_class_id' => 1, '_custom_features' => [] ],
			cc_class( [ cc_feature( [] ) ] ),
		],
	];

	expect( $this->extractor->collect_custom_classes( $product ) )->toHaveCount( 1 );
});

test('collect_custom_classes ignores trade-items by default', function () {
	$product = [
		'_trade_items' => [
			[
				'_trade_item_custom_classes' => [ cc_class( [ cc_feature( [] ) ] ) ],
			],
		],
	];

	expect( $this->extractor->collect_custom_classes( $product ) )->toBe( [] );
});

test('collect_custom_classes pulls trade-item classes when include_trade_items=true', function () {
	$product = [
		'_trade_items' => [
			[
				'_trade_item_custom_classes' => [ cc_class( [ cc_feature( [] ) ] ) ],
			],
		],
	];

	expect( $this->extractor->collect_custom_classes( $product, true ) )->toHaveCount( 1 );
});

// ------------------------------------------------------------------
// parse_custom_class_filter() — static parsing of raw setting
// ------------------------------------------------------------------

test('parse_custom_class_filter splits numeric IDs from string codes', function () {
	$result = Skwirrel_WC_Sync_Custom_Class_Extractor::parse_custom_class_filter( '12, 34, mechanical, electrical' );

	expect( $result['ids'] )->toBe( [ 12, 34 ] );
	expect( $result['codes'] )->toBe( [ 'mechanical', 'electrical' ] );
});

test('parse_custom_class_filter lowercases codes', function () {
	$result = Skwirrel_WC_Sync_Custom_Class_Extractor::parse_custom_class_filter( 'Mechanical, ELECTRICAL' );

	expect( $result['codes'] )->toBe( [ 'mechanical', 'electrical' ] );
});

test('parse_custom_class_filter handles whitespace and mixed separators', function () {
	$result = Skwirrel_WC_Sync_Custom_Class_Extractor::parse_custom_class_filter( "1\t2\n,3   foo" );

	expect( $result['ids'] )->toBe( [ 1, 2, 3 ] );
	expect( $result['codes'] )->toBe( [ 'foo' ] );
});

test('parse_custom_class_filter returns empty arrays for empty input', function () {
	$result = Skwirrel_WC_Sync_Custom_Class_Extractor::parse_custom_class_filter( '' );

	expect( $result['ids'] )->toBe( [] );
	expect( $result['codes'] )->toBe( [] );
});

// ------------------------------------------------------------------
// filter_custom_classes() — whitelist / blacklist
// ------------------------------------------------------------------

test('filter_custom_classes returns all when mode is empty', function () {
	$classes = [ cc_class( [ cc_feature( [] ) ], [ 'custom_class_id' => 1 ] ) ];

	$result = $this->extractor->filter_custom_classes( $classes, '', [ 999 ], [ 'foo' ] );

	expect( $result )->toHaveCount( 1 );
});

test('filter_custom_classes returns all when both filter lists empty', function () {
	$classes = [ cc_class( [ cc_feature( [] ) ], [ 'custom_class_id' => 1 ] ) ];

	$result = $this->extractor->filter_custom_classes( $classes, 'whitelist', [], [] );

	expect( $result )->toHaveCount( 1 );
});

test('filter_custom_classes whitelist keeps matching IDs only', function () {
	$classes = [
		cc_class( [ cc_feature( [] ) ], [ 'custom_class_id' => 1 ] ),
		cc_class( [ cc_feature( [] ) ], [ 'custom_class_id' => 2 ] ),
	];

	$result = $this->extractor->filter_custom_classes( $classes, 'whitelist', [ 1 ], [] );

	expect( $result )->toHaveCount( 1 );
	expect( $result[0]['custom_class_id'] )->toBe( 1 );
});

test('filter_custom_classes blacklist removes matching codes', function () {
	$classes = [
		cc_class( [ cc_feature( [] ) ], [ 'custom_class_id' => 1, 'custom_class_code' => 'keep' ] ),
		cc_class( [ cc_feature( [] ) ], [ 'custom_class_id' => 2, 'custom_class_code' => 'drop' ] ),
	];

	$result = $this->extractor->filter_custom_classes( $classes, 'blacklist', [], [ 'drop' ] );

	expect( $result )->toHaveCount( 1 );
	expect( $result[0]['custom_class_code'] )->toBe( 'keep' );
});

// ------------------------------------------------------------------
// get_custom_class_attributes() — feature value formatting per type
// ------------------------------------------------------------------

test('formats type A using translated value description', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_A',
					'custom_feature_type' => 'A',
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Materiaal' ],
					],
					'_custom_values' => [
						[ '_custom_value_translations' => [
							[ 'language' => 'nl', 'custom_value_description' => 'Staal' ],
						] ],
					],
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product ) )->toBe( [ 'Materiaal' => 'Staal' ] );
});

test('formats type A falls back to custom_value_code when no value translation', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_A',
					'custom_feature_type' => 'A',
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Materiaal' ],
					],
					'custom_value_code' => 'STEEL',
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product ) )->toBe( [ 'Materiaal' => 'STEEL' ] );
});

test('formats type M as comma-joined translated descriptions', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_M',
					'custom_feature_type' => 'M',
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Tags' ],
					],
					'_custom_values' => [
						[ '_custom_value_translations' => [
							[ 'language' => 'nl', 'custom_value_description' => 'Eerste' ],
						] ],
						[ '_custom_value_translations' => [
							[ 'language' => 'nl', 'custom_value_description' => 'Tweede' ],
						] ],
					],
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product ) )->toBe( [ 'Tags' => 'Eerste, Tweede' ] );
});

test('formats type L true as Ja and false as Nee', function () {
	$product_true = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_T',
					'custom_feature_type' => 'L',
					'logical_value'       => true,
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Geschikt' ],
					],
				] ),
			] ),
		],
	];
	$product_false = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_F',
					'custom_feature_type' => 'L',
					'logical_value'       => false,
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Geschikt' ],
					],
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product_true ) )->toBe( [ 'Geschikt' => 'Ja' ] );
	expect( $this->extractor->get_custom_class_attributes( $product_false ) )->toBe( [ 'Geschikt' => 'Nee' ] );
});

test('formats type N with unit abbreviation', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_N',
					'custom_feature_type' => 'N',
					'numeric_value'       => 42,
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Lengte' ],
					],
					'_custom_unit_translations' => [
						[ 'language' => 'nl', 'custom_unit_abbreviation' => 'mm' ],
					],
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product ) )->toBe( [ 'Lengte' => '42 mm' ] );
});

test('formats type R range with em-dash separator and unit', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_R',
					'custom_feature_type' => 'R',
					'range_min'           => 5,
					'range_max'           => 15,
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Bereik' ],
					],
					'_custom_unit_translations' => [
						[ 'language' => 'nl', 'custom_unit_abbreviation' => 'V' ],
					],
				] ),
			] ),
		],
	];

	$result = $this->extractor->get_custom_class_attributes( $product );
	// Note: this format uses em-dash ' – ' (not regular '-')
	expect( $result['Bereik'] )->toBe( '5 – 15 V' );
});

test('formats type D as raw date_value string', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_D',
					'custom_feature_type' => 'D',
					'date_value'          => '2025-06-15',
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Datum' ],
					],
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product ) )->toBe( [ 'Datum' => '2025-06-15' ] );
});

test('formats type T as raw text_value', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_T',
					'custom_feature_type' => 'T',
					'text_value'          => 'Free-form text',
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Notitie' ],
					],
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product ) )->toBe( [ 'Notitie' => 'Free-form text' ] );
});

test('formats type I picking text by exact language', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_I',
					'custom_feature_type' => 'I',
					'translated_texts'    => [
						[ 'language' => 'nl', 'text' => 'Nederlandse tekst' ],
						[ 'language' => 'en', 'text' => 'English text' ],
					],
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Beschrijving' ],
					],
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product ) )->toBe( [ 'Beschrijving' => 'Nederlandse tekst' ] );
});

test('skips type B (meta type) from attribute output', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_B',
					'custom_feature_type' => 'B',
					'big_text_value'      => 'long story',
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Verhaal' ],
					],
				] ),
				cc_feature( [
					'custom_feature_code' => 'CF_L',
					'custom_feature_type' => 'L',
					'logical_value'       => true,
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Boolean' ],
					],
				] ),
			] ),
		],
	];

	$result = $this->extractor->get_custom_class_attributes( $product );
	// only the L feature should appear; the B feature is meta-only
	expect( $result )->toHaveCount( 1 );
	expect( $result )->toHaveKey( 'Boolean' );
});

test('skips not_applicable features', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_NA',
					'custom_feature_type' => 'L',
					'logical_value'       => true,
					'not_applicable'      => true,
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Skipped' ],
					],
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_attributes( $product ) )->toBe( [] );
});

test('deduplicates features by custom_feature_code', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'DUPE',
					'custom_feature_type' => 'L',
					'logical_value'       => true,
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'First' ],
					],
				] ),
				cc_feature( [
					'custom_feature_code' => 'DUPE',
					'custom_feature_type' => 'L',
					'logical_value'       => false,
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Duplicate' ],
					],
				] ),
			] ),
		],
	];

	$result = $this->extractor->get_custom_class_attributes( $product );
	expect( $result )->toBe( [ 'First' => 'Ja' ] );
});

// ------------------------------------------------------------------
// get_custom_class_text_meta() — long text features as meta keys
// ------------------------------------------------------------------

test('returns type B feature as _skwirrel_cc_{code} meta key', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'STORY',
					'custom_feature_type' => 'B',
					'big_text_value'      => 'long-form description',
				] ),
			] ),
		],
	];

	$meta = $this->extractor->get_custom_class_text_meta( $product );

	expect( $meta )->toHaveKey( '_skwirrel_cc_story' );
	expect( $meta['_skwirrel_cc_story'] )->toBe( 'long-form description' );
});

test('text_meta skips A/M/L attribute types (only B is meta)', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'CF_L',
					'custom_feature_type' => 'L',
					'logical_value'       => true,
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_text_meta( $product ) )->toBe( [] );
});

test('text_meta skips B feature with empty big_text_value', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_code' => 'EMPTY',
					'custom_feature_type' => 'B',
					'big_text_value'      => '',
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_custom_class_text_meta( $product ) )->toBe( [] );
});

// ------------------------------------------------------------------
// get_custom_feature_values_for_ids() — variation axis lookup by feature ID
// ------------------------------------------------------------------

test('get_custom_feature_values_for_ids returns empty for empty input', function () {
	$product = [ '_custom_classes' => [] ];

	expect( $this->extractor->get_custom_feature_values_for_ids( $product, [] ) )->toBe( [] );
});

test('get_custom_feature_values_for_ids matches by custom_feature_id and returns label/value/slug', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				[
					'custom_feature_id'   => 42,
					'custom_feature_code' => 'CF_42',
					'custom_feature_type' => 'A',
					'custom_value_code'   => 'RED_DARK',
					'_custom_feature_translations' => [
						[ 'language' => 'nl', 'custom_feature_description' => 'Kleur' ],
					],
					'_custom_values' => [
						[ '_custom_value_translations' => [
							[ 'language' => 'nl', 'custom_value_description' => 'Donkerrood' ],
						] ],
					],
				],
			] ),
		],
	];

	$result = $this->extractor->get_custom_feature_values_for_ids( $product, [ [ 'id' => 42 ] ], 'nl' );

	expect( $result )->toHaveKey( 42 );
	expect( $result[42]['label'] )->toBe( 'Kleur' );
	expect( $result[42]['value'] )->toBe( 'Donkerrood' );
	expect( $result[42]['slug'] )->toBe( 'donkerrood' );
});

// ------------------------------------------------------------------
// get_grouped_class_features() — features grouped under their class
// ------------------------------------------------------------------

test('get_grouped_class_features groups features under their class with name', function () {
	$product = [
		'_custom_classes' => [
			cc_class(
				[
					cc_feature( [
						'custom_feature_code' => 'CF_L',
						'custom_feature_type' => 'L',
						'logical_value'       => true,
						'_custom_feature_translations' => [
							[ 'language' => 'nl', 'custom_feature_description' => 'Geschikt' ],
						],
					] ),
				],
				[
					'custom_class_id'   => 99,
					'custom_class_code' => 'mech',
					'custom_class_name' => 'Mechanical',
				]
			),
		],
	];

	$result = $this->extractor->get_grouped_class_features( $product );

	expect( $result )->toHaveCount( 1 );
	expect( $result[0]['class_id'] )->toBe( 99 );
	expect( $result[0]['class_code'] )->toBe( 'mech' );
	expect( $result[0]['class_name'] )->toBe( 'Mechanical' );
	expect( $result[0]['features'] )->toBe( [ [ 'label' => 'Geschikt', 'value' => 'Ja' ] ] );
});

test('get_grouped_class_features prefers translated class name over root field', function () {
	$product = [
		'_custom_classes' => [
			cc_class(
				[
					cc_feature( [
						'custom_feature_code' => 'CF_L',
						'custom_feature_type' => 'L',
						'logical_value'       => true,
					] ),
				],
				[
					'custom_class_name' => 'Fallback',
					'_custom_class_translations' => [
						[ 'language' => 'nl', 'custom_class_description' => 'Vertaalde naam' ],
					],
				]
			),
		],
	];

	$result = $this->extractor->get_grouped_class_features( $product );

	expect( $result[0]['class_name'] )->toBe( 'Vertaalde naam' );
});

test('get_grouped_class_features omits classes with no qualifying features', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [
				cc_feature( [
					'custom_feature_type' => 'B', // meta-only, won't be in grouped attributes
					'big_text_value'      => 'x',
				] ),
			] ),
		],
	];

	expect( $this->extractor->get_grouped_class_features( $product ) )->toBe( [] );
});

// ------------------------------------------------------------------
// get_attribute_visibility_map() — vis filter independent of sync filter
// ------------------------------------------------------------------

test('get_attribute_visibility_map returns empty when vis_mode is empty', function () {
	$product = [
		'_custom_classes' => [
			cc_class( [ cc_feature( [ 'custom_feature_type' => 'L', 'logical_value' => true ] ) ] ),
		],
	];

	$result = $this->extractor->get_attribute_visibility_map( $product, false, '', [], [], '', [], [] );

	expect( $result )->toBe( [] );
});

test('get_attribute_visibility_map marks whitelisted classes as visible', function () {
	$product = [
		'_custom_classes' => [
			cc_class(
				[
					cc_feature( [
						'custom_feature_code' => 'CF_VIS',
						'custom_feature_type' => 'L',
						'logical_value'       => true,
						'_custom_feature_translations' => [
							[ 'language' => 'nl', 'custom_feature_description' => 'Visible' ],
						],
					] ),
				],
				[ 'custom_class_id' => 7 ]
			),
			cc_class(
				[
					cc_feature( [
						'custom_feature_code' => 'CF_HID',
						'custom_feature_type' => 'L',
						'logical_value'       => true,
						'_custom_feature_translations' => [
							[ 'language' => 'nl', 'custom_feature_description' => 'Hidden' ],
						],
					] ),
				],
				[ 'custom_class_id' => 8 ]
			),
		],
	];

	$result = $this->extractor->get_attribute_visibility_map(
		$product,
		false,   // include_trade_items
		'',      // sync_filter_mode
		[],
		[],
		'whitelist',
		[ 7 ],
		[]
	);

	expect( $result )->toBe( [ 'Visible' => true, 'Hidden' => false ] );
});

// ------------------------------------------------------------------
// resolve_custom_feature_label()
// ------------------------------------------------------------------

test('resolve_custom_feature_label uses provided lang over default', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'image_language' => 'nl' ];
	$feature = [
		'custom_feature_code' => 'CF',
		'_custom_feature_translations' => [
			[ 'language' => 'nl', 'custom_feature_description' => 'Dutch' ],
			[ 'language' => 'en', 'custom_feature_description' => 'English' ],
		],
	];

	expect( $this->extractor->resolve_custom_feature_label( $feature, 'en' ) )->toBe( 'English' );
});

test('resolve_custom_feature_label falls back to feature_code when no translation', function () {
	$feature = [
		'custom_feature_code' => 'CF_FALLBACK',
		'_custom_feature_translations' => [],
	];

	expect( $this->extractor->resolve_custom_feature_label( $feature, 'nl' ) )->toBe( 'CF_FALLBACK' );
});
