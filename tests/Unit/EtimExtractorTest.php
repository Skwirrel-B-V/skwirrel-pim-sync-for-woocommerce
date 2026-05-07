<?php

declare(strict_types=1);

beforeEach(function () {
	$this->extractor = new Skwirrel_WC_Sync_Etim_Extractor( 'nl' );
	// Reset per-test option overrides.
	$GLOBALS['_test_options'] = [];
});

afterEach(function () {
	$GLOBALS['_test_options'] = [];
});

// ------------------------------------------------------------------
// collect_etim_items() — finding ETIM data across the payload
// ------------------------------------------------------------------

test('collect_etim_items returns a single ETIM block from product._etim object form', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[ 'etim_feature_code' => 'EF000001', 'etim_feature_type' => 'A' ],
			],
		],
	];

	$result = $this->extractor->collect_etim_items( $product );

	expect( $result )->toHaveCount( 1 );
	expect( $result[0]['_etim_features'] )->toHaveCount( 1 );
});

test('collect_etim_items returns multiple ETIM blocks when product._etim is a list', function () {
	$product = [
		'_etim' => [
			[ '_etim_features' => [ [ 'etim_feature_code' => 'A1' ] ] ],
			[ '_etim_features' => [ [ 'etim_feature_code' => 'A2' ] ] ],
		],
	];

	$result = $this->extractor->collect_etim_items( $product );

	expect( $result )->toHaveCount( 2 );
});

test('collect_etim_items skips ETIM blocks with empty _etim_features', function () {
	$product = [
		'_etim' => [
			[ '_etim_features' => [] ],
			[ '_etim_features' => [ [ 'etim_feature_code' => 'A1' ] ] ],
		],
	];

	$result = $this->extractor->collect_etim_items( $product );

	expect( $result )->toHaveCount( 1 );
});

test('collect_etim_items falls back to product._etim_features when product._etim is empty', function () {
	$product = [
		'_etim_features' => [
			[ 'etim_feature_code' => 'EF000001' ],
		],
	];

	$result = $this->extractor->collect_etim_items( $product );

	expect( $result )->toHaveCount( 1 );
	expect( $result[0]['_etim_features'] )->toHaveCount( 1 );
});

test('collect_etim_items pulls features from _product_groups[]._etim', function () {
	$product = [
		'_product_groups' => [
			[
				'_etim' => [
					'_etim_features' => [ [ 'etim_feature_code' => 'PG1' ] ],
				],
			],
		],
	];

	$result = $this->extractor->collect_etim_items( $product );

	expect( $result )->toHaveCount( 1 );
});

test('collect_etim_items recursively finds nested _etim_features as last resort', function () {
	$product = [
		'_some_wrapper' => [
			'_inner' => [
				'_etim_features' => [
					[ 'etim_feature_code' => 'NESTED' ],
				],
			],
		],
	];

	$result = $this->extractor->collect_etim_items( $product );

	expect( $result )->toHaveCount( 1 );
});

test('collect_etim_items returns empty array when no ETIM data anywhere', function () {
	expect( $this->extractor->collect_etim_items( [ 'product_id' => 1 ] ) )->toBe( [] );
});

// ------------------------------------------------------------------
// get_etim_attributes() — full pipeline
// ------------------------------------------------------------------

test('get_etim_attributes returns label => value map for type A features', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF000001',
					'etim_feature_type' => 'A',
					'etim_value_code'   => 'EV000001',
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Kleur' ],
					],
					'_etim_value_translations' => [
						[ 'language' => 'nl', 'etim_value_description' => 'Rood' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result )->toBe( [ 'Kleur' => 'Rood' ] );
});

test('get_etim_attributes uses image_language from settings to pick translation', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'image_language' => 'fr' ];
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF000001',
					'etim_feature_type' => 'A',
					'etim_value_code'   => 'EV000001',
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Kleur' ],
						[ 'language' => 'fr', 'etim_feature_description' => 'Couleur' ],
					],
					'_etim_value_translations' => [
						[ 'language' => 'nl', 'etim_value_description' => 'Rood' ],
						[ 'language' => 'fr', 'etim_value_description' => 'Rouge' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result )->toBe( [ 'Couleur' => 'Rouge' ] );
});

test('get_etim_attributes falls back to feature code when no translation matches', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF000001',
					'etim_feature_type' => 'A',
					'etim_value_code'   => 'EV000001',
					'_etim_feature_translations' => [],
					'_etim_value_translations' => [],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result )->toHaveKey( 'EF000001' );
	expect( $result['EF000001'] )->toBe( 'EV000001' );
});

test('get_etim_attributes skips features marked not_applicable', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF000001',
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'not_applicable'    => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Skipped' ],
					],
				],
				[
					'etim_feature_code' => 'EF000002',
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Kept' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result )->toBe( [ 'Kept' => 'Ja' ] );
});

test('get_etim_attributes deduplicates features by etim_feature_code', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF000001',
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'First' ],
					],
				],
				[
					'etim_feature_code' => 'EF000001',
					'etim_feature_type' => 'L',
					'logical_value'     => false,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Duplicate' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result )->toHaveCount( 1 );
	expect( $result )->toBe( [ 'First' => 'Ja' ] );
});

// ------------------------------------------------------------------
// Type-specific value formatting (all 6 ETIM types)
// ------------------------------------------------------------------

test('formats type N (numeric) feature with unit abbreviation', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_LEN',
					'etim_feature_type' => 'N',
					'numeric_value'     => 42,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Lengte' ],
					],
					'_etim_unit_translations' => [
						[ 'language' => 'nl', 'etim_unit_abbreviation' => 'mm' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Lengte'] )->toBe( '42 mm' );
});

test('formats type N (numeric) feature falling back to unit description when no abbreviation', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_LEN',
					'etim_feature_type' => 'N',
					'numeric_value'     => 5,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Aantal' ],
					],
					'_etim_unit_translations' => [
						[ 'language' => 'nl', 'etim_unit_description' => 'stuks' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Aantal'] )->toBe( '5 stuks' );
});

test('formats type L (logical) true as Ja', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_BOOL',
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Waterdicht' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Waterdicht'] )->toBe( 'Ja' );
});

test('formats type L (logical) false as Nee', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_BOOL',
					'etim_feature_type' => 'L',
					'logical_value'     => false,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Waterdicht' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Waterdicht'] )->toBe( 'Nee' );
});

test('treats empty etim_feature_type as logical when logical_value is set', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_NOTYPE',
					'etim_feature_type' => '',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Beschikbaar' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Beschikbaar'] )->toBe( 'Ja' );
});

test('formats type R (range) with min, max and unit', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_RANGE',
					'etim_feature_type' => 'R',
					'range_min'         => 10,
					'range_max'         => 20,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Bereik' ],
					],
					'_etim_unit_translations' => [
						[ 'language' => 'nl', 'etim_unit_abbreviation' => 'V' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Bereik'] )->toBe( '10 - 20 V' );
});

test('formats type R (range) with only min as bare value', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_RMIN',
					'etim_feature_type' => 'R',
					'range_min'         => 5,
					'range_max'         => null,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Min' ],
					],
					'_etim_unit_translations' => [
						[ 'language' => 'nl', 'etim_unit_abbreviation' => 'kg' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	// no separator when one bound is missing
	expect( $result['Min'] )->toBe( '5 kg' );
});

test('formats type C (class) with etim_value_code as fallback when no translation', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_CLASS',
					'etim_feature_type' => 'C',
					'etim_value_code'   => 'CLS001',
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Klasse' ],
					],
					'_etim_value_translations' => [],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Klasse'] )->toBe( 'CLS001' );
});

test('formats type M (modelling) with translated value description', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_MODEL',
					'etim_feature_type' => 'M',
					'etim_value_code'   => 'MOD001',
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Model' ],
					],
					'_etim_value_translations' => [
						[ 'language' => 'nl', 'etim_value_description' => 'Variant A' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Model'] )->toBe( 'Variant A' );
});

// ------------------------------------------------------------------
// Translation pick — exact, prefix, fallback
// ------------------------------------------------------------------

test('language pick prefers exact match over prefix match', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'image_language' => 'nl-BE' ];
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_LANG',
					'etim_feature_type' => 'A',
					'etim_value_code'   => 'V1',
					'_etim_feature_translations' => [
						[ 'language' => 'nl-NL', 'etim_feature_description' => 'NL-NL label' ],
						[ 'language' => 'nl-BE', 'etim_feature_description' => 'NL-BE label' ],
					],
					'_etim_value_translations' => [
						[ 'language' => 'nl-BE', 'etim_value_description' => 'NL-BE val' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result )->toHaveKey( 'NL-BE label' );
	expect( $result['NL-BE label'] )->toBe( 'NL-BE val' );
});

test('language pick falls back to 2-char prefix match', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'image_language' => 'nl-BE' ];
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_LANG',
					'etim_feature_type' => 'A',
					'etim_value_code'   => 'V1',
					'_etim_feature_translations' => [
						[ 'language' => 'nl-NL', 'etim_feature_description' => 'Dutch label' ],
					],
					'_etim_value_translations' => [
						[ 'language' => 'nl-NL', 'etim_value_description' => 'Dutch value' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['Dutch label'] )->toBe( 'Dutch value' );
});

test('language pick falls back to first translation when no match at all', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'image_language' => 'de' ];
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_LANG',
					'etim_feature_type' => 'A',
					'etim_value_code'   => 'V1',
					'_etim_feature_translations' => [
						[ 'language' => 'fr', 'etim_feature_description' => 'French only' ],
					],
					'_etim_value_translations' => [
						[ 'language' => 'fr', 'etim_value_description' => 'Français' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result['French only'] )->toBe( 'Français' );
});

// ------------------------------------------------------------------
// resolve_etim_feature_label()
// ------------------------------------------------------------------

test('resolve_etim_feature_label returns code when no translations exist', function () {
	$feature = [
		'etim_feature_code' => 'EF_NONE',
		'_etim_feature_translations' => [],
	];

	expect( $this->extractor->resolve_etim_feature_label( $feature, 'nl' ) )->toBe( 'EF_NONE' );
});

test('resolve_etim_feature_label uses provided language over default', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'image_language' => 'nl' ];
	$feature = [
		'etim_feature_code' => 'EF',
		'_etim_feature_translations' => [
			[ 'language' => 'nl', 'etim_feature_description' => 'Dutch' ],
			[ 'language' => 'en', 'etim_feature_description' => 'English' ],
		],
	];

	expect( $this->extractor->resolve_etim_feature_label( $feature, 'en' ) )->toBe( 'English' );
});

// ------------------------------------------------------------------
// get_etim_feature_values_for_codes() — variation axis lookup
// ------------------------------------------------------------------

test('get_etim_feature_values_for_codes returns empty for empty code list', function () {
	$product = [
		'_etim' => [ '_etim_features' => [ [ 'etim_feature_code' => 'A' ] ] ],
	];

	expect( $this->extractor->get_etim_feature_values_for_codes( $product, [] ) )->toBe( [] );
});

test('get_etim_feature_values_for_codes filters to requested codes only', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_KEEP',
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Keep' ],
					],
				],
				[
					'etim_feature_code' => 'EF_DROP',
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Drop' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_feature_values_for_codes(
		$product,
		[ [ 'code' => 'EF_KEEP' ] ],
		'nl'
	);

	expect( $result )->toHaveCount( 1 );
	expect( $result )->toHaveKey( 'EF_KEEP' );
});

test('get_etim_feature_values_for_codes returns label, value, and slug for each match', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_COLOR',
					'etim_feature_type' => 'A',
					'etim_value_code'   => 'EV_RED',
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'Kleur' ],
					],
					'_etim_value_translations' => [
						[ 'language' => 'nl', 'etim_value_description' => 'Rood Donker' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_feature_values_for_codes(
		$product,
		[ [ 'code' => 'EF_COLOR' ] ],
		'nl'
	);

	expect( $result['EF_COLOR']['label'] )->toBe( 'Kleur' );
	expect( $result['EF_COLOR']['value'] )->toBe( 'Rood Donker' );
	expect( $result['EF_COLOR']['slug'] )->toBe( 'rood-donker' );
});

test('get_etim_feature_values_for_codes matches code case-insensitively', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_LOWER',
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'X' ],
					],
				],
			],
		],
	];

	// Caller passes lowercase, payload has uppercase — class normalizes both via strtoupper.
	$result = $this->extractor->get_etim_feature_values_for_codes(
		$product,
		[ [ 'code' => 'ef_lower' ] ],
		'nl'
	);

	expect( $result )->toHaveKey( 'EF_LOWER' );
});

test('get_etim_feature_values_for_codes accepts etim_feature_code key as well as code', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				[
					'etim_feature_code' => 'EF_X',
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'X' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_feature_values_for_codes(
		$product,
		[ [ 'etim_feature_code' => 'EF_X' ] ],
		'nl'
	);

	expect( $result )->toHaveKey( 'EF_X' );
});

// ------------------------------------------------------------------
// normalize_etim_features() — code preservation from associative key
// ------------------------------------------------------------------

test('normalize preserves etim_feature_code when payload uses associative keys', function () {
	$product = [
		'_etim' => [
			'_etim_features' => [
				// associative form: key is the code, value lacks etim_feature_code
				'EF_FROM_KEY' => [
					'etim_feature_type' => 'L',
					'logical_value'     => true,
					'_etim_feature_translations' => [
						[ 'language' => 'nl', 'etim_feature_description' => 'KeyDerived' ],
					],
				],
			],
		],
	];

	$result = $this->extractor->get_etim_attributes( $product );

	expect( $result )->toHaveKey( 'KeyDerived' );
	expect( $result['KeyDerived'] )->toBe( 'Ja' );
});
