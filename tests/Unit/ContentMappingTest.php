<?php

declare(strict_types=1);

/**
 * Tests for the FR-19 content field mappings (Story 6.3).
 *
 * Title, short description and long description can each be driven by one product-level
 * Skwirrel custom feature. The NFR-9 contract is that a configured-but-unresolved mapping
 * falls through to the existing source chain — it must never short-circuit a field to ''.
 */

beforeEach(function () {
    unset($GLOBALS['_test_options']);
    $this->mapper    = new Skwirrel_WC_Sync_Product_Mapper();
    $this->extractor = new Skwirrel_WC_Sync_Custom_Class_Extractor('nl');
});

afterEach(function () {
    unset($GLOBALS['_test_options']);
});

/**
 * A product carrying translations plus, optionally, one custom feature.
 *
 * @param array<string,mixed>|null $feature Custom feature payload, or null for none.
 * @return array<string,mixed>
 */
function content_product(?array $feature = null): array
{
    $product = [
        'product_id'              => 55,
        'product_erp_description' => 'ERP title',
        '_product_translations'   => [
            [
                'language'                => 'nl',
                'product_model'           => 'Model NL',
                'product_description'     => 'Korte omschrijving',
                'product_long_description' => 'Lange omschrijving',
            ],
        ],
    ];

    if (null !== $feature) {
        $product['_custom_classes'] = [
            [
                'custom_class_id'  => 3,
                '_custom_features' => [ $feature ],
            ],
        ];
    }

    return $product;
}

// ------------------------------------------------------------------
// AC 2 — a resolved non-empty value wins
// ------------------------------------------------------------------

test('a mapped title overrides the ERP description', function () {
    $product = content_product([
        'custom_feature_id'   => 812,
        'custom_feature_type' => 'T',
        'text_value'          => 'Marketing title',
    ]);

    $this->mapper->set_content_mapping('812', '', '');

    expect($this->mapper->get_name($product))->toBe('Marketing title');
});

test('a mapped short description overrides the translation', function () {
    $product = content_product([
        'custom_feature_id'   => 813,
        'custom_feature_type' => 'T',
        'text_value'          => 'Mapped short',
    ]);

    $this->mapper->set_content_mapping('', '813', '');

    expect($this->mapper->get_short_description($product))->toBe('Mapped short');
});

test('a mapped long description resolves from a B-type big text value', function () {
    // The single most likely way to ship this story doing nothing: B is not handled by
    // format_custom_feature_value(), and a long description is very likely a B feature.
    $product = content_product([
        'custom_feature_id'   => 814,
        'custom_feature_type' => 'B',
        'big_text_value'      => '<p>Een <strong>lange</strong> tekst.</p>',
    ]);

    $this->mapper->set_content_mapping('', '', '814');

    expect($this->mapper->get_long_description($product))->toBe('<p>Een <strong>lange</strong> tekst.</p>');
});

// ------------------------------------------------------------------
// AC 3 — a missing value never clears anything
// ------------------------------------------------------------------

test('a configured mapping that resolves nothing falls through to the existing chain', function () {
    // The feature is present on the product but is a different one — configured, unresolved.
    $product = content_product([
        'custom_feature_id'   => 999,
        'custom_feature_type' => 'T',
        'text_value'          => 'Some other feature',
    ]);

    $this->mapper->set_content_mapping('812', '813', '814');

    expect($this->mapper->get_name($product))->toBe('ERP title');
    expect($this->mapper->get_short_description($product))->toBe('Korte omschrijving');
    expect($this->mapper->get_long_description($product))->toBe('Lange omschrijving');
});

test('an absent, not_applicable and empty feature all fall back — by three different routes', function () {
    $this->mapper->set_content_mapping('812', '', '');

    // Absent: no custom classes at all.
    expect($this->mapper->get_name(content_product()))->toBe('ERP title');

    // not_applicable: matched, then skipped.
    $na = content_product([
        'custom_feature_id'   => 812,
        'custom_feature_type' => 'T',
        'text_value'          => 'Ignored',
        'not_applicable'      => true,
    ]);
    expect($this->mapper->get_name($na))->toBe('ERP title');

    // Empty: matched, resolved, but the value is ''.
    $empty = content_product([
        'custom_feature_id'   => 812,
        'custom_feature_type' => 'T',
        'text_value'          => '',
    ]);
    expect($this->mapper->get_name($empty))->toBe('ERP title');
});

// ------------------------------------------------------------------
// AC 4 — unconfigured is byte-for-byte unchanged
// ------------------------------------------------------------------

test('with no mapping configured a product carrying custom-class data is unaffected', function () {
    // Deliberately a product that DOES carry a resolvable feature, so this proves the mapping
    // is off rather than merely that empty input produces empty output.
    $product = content_product([
        'custom_feature_id'   => 812,
        'custom_feature_type' => 'T',
        'text_value'          => 'Would have won',
    ]);

    // No set_content_mapping() call at all — the mapper's default state.
    expect($this->mapper->get_name($product))->toBe('ERP title');
    expect($this->mapper->get_short_description($product))->toBe('Korte omschrijving');
    expect($this->mapper->get_long_description($product))->toBe('Lange omschrijving');
});

// ------------------------------------------------------------------
// AC 5 — long description is sanitised, not stripped
// ------------------------------------------------------------------

test('long-description markup survives while unsafe markup does not', function () {
    $product = content_product([
        'custom_feature_id'   => 814,
        'custom_feature_type' => 'B',
        'big_text_value'      => '<p>Veilig <em>opgemaakt</em></p><script>alert(1)</script>',
    ]);

    $this->mapper->set_content_mapping('', '', '814');
    $result = $this->mapper->get_long_description($product);

    expect($result)->toContain('<em>opgemaakt</em>');
    expect($result)->not->toContain('<script>');
    expect($result)->not->toContain('alert(1)');
});

test('a mapped long description that sanitises to empty falls back to the normal source', function () {
    $product = content_product([
        'custom_feature_id'   => 814,
        'custom_feature_type' => 'B',
        'big_text_value'      => '<script>alert(1)</script>',
    ]);

    $this->mapper->set_content_mapping('', '', '814');

    expect($this->mapper->get_long_description($product))->toBe('Lange omschrijving');
});

test('whitespace-only mapped content falls back to the normal source', function () {
    $product = content_product([
        'custom_feature_id'   => 812,
        'custom_feature_type' => 'T',
        'text_value'          => " \n\t ",
    ]);

    $this->mapper->set_content_mapping('812', '812', '812');

    expect($this->mapper->get_name($product))->toBe('ERP title');
    expect($this->mapper->get_short_description($product))->toBe('Korte omschrijving');
    expect($this->mapper->get_long_description($product))->toBe('Lange omschrijving');
});

test('surrounding whitespace is trimmed off a resolved value', function () {
    // The trim lives at the shared boundary, so all three getters agree: a padded feature value
    // resolves to its content, and a value that is *only* padding resolves to nothing at all.
    $product = content_product([
        'custom_feature_id'   => 812,
        'custom_feature_type' => 'T',
        'text_value'          => "  Padded title \n",
    ]);

    $this->mapper->set_content_mapping('812', '812', '812');

    expect($this->mapper->get_name($product))->toBe('Padded title');
    expect($this->mapper->get_short_description($product))->toBe('Padded title');
    expect($this->mapper->get_long_description($product))->toBe('Padded title');
});

test('the title is not run through the post sanitiser', function () {
    // Over-sanitising a title would strip legitimate entities; the AC scopes kses to long text.
    $product = content_product([
        'custom_feature_id'   => 812,
        'custom_feature_type' => 'T',
        'text_value'          => 'Hammer & Sons <3',
    ]);

    $this->mapper->set_content_mapping('812', '', '');

    expect($this->mapper->get_name($product))->toBe('Hammer & Sons <3');
});

// ------------------------------------------------------------------
// AC 9 — the three mappings are independent
// ------------------------------------------------------------------

test('configuring only the long description leaves title and short description alone', function () {
    $product = content_product([
        'custom_feature_id'   => 814,
        'custom_feature_type' => 'T',
        'text_value'          => 'Only the long one',
    ]);

    $this->mapper->set_content_mapping('', '', '814');

    expect($this->mapper->get_name($product))->toBe('ERP title');
    expect($this->mapper->get_short_description($product))->toBe('Korte omschrijving');
    expect($this->mapper->get_long_description($product))->toBe('Only the long one');
});

// ------------------------------------------------------------------
// AC 6 — language selection follows the existing image_language chain
// ------------------------------------------------------------------

test('an I-type value picks the language through the existing chain', function () {
    $product = content_product([
        'custom_feature_id'   => 812,
        'custom_feature_type' => 'I',
        'translated_texts'    => [
            [ 'language' => 'de', 'text' => 'Deutscher Titel' ],
            [ 'language' => 'nl-NL', 'text' => 'Nederlandse titel' ],
        ],
    ]);

    // The extractor is constructed with 'nl'; 'nl-NL' matches on the two-letter prefix.
    expect($this->extractor->resolve_text_feature_value($product, '812', 'nl'))->toBe('Nederlandse titel');
    expect($this->extractor->resolve_text_feature_value($product, '812', 'de'))->toBe('Deutscher Titel');
});
