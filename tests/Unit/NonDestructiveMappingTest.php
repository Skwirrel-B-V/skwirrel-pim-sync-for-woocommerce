<?php

declare(strict_types=1);

/**
 * The stub-safe half of the NFR-9 pin (Story 6.4).
 *
 * Only one question is genuinely answerable at the unit level: **does this payload resolve a
 * value, yes or no?** That is a pure function of the payload and the configured mapping, and it
 * is the decision every non-destructive branch downstream hangs off — if a resolver ever starts
 * returning a value where it used to return "nothing", the whole guarantee falls over regardless
 * of how careful the write sites are.
 *
 * Everything about *what happens to an existing WooCommerce value* lives in
 * `tests/Integration/NonDestructiveMappingIntegrationTest.php`. It cannot live here: the unit
 * bootstrap's WC_Product stub has no stock or content setters, so there is nowhere for a
 * pre-existing value to live, and adding setters would only prove the stub remembers what it was
 * told — the exact vacuous shape this story exists to prevent.
 */

beforeEach(function () {
    unset($GLOBALS['_test_options']);
    $this->extractor = new Skwirrel_WC_Sync_Custom_Class_Extractor('nl');
});

afterEach(function () {
    unset($GLOBALS['_test_options']);
});

/**
 * A product carrying one product-level custom feature, in one of the four AC shapes.
 *
 * @param string $shape absent | empty | malformed | valid
 * @param string $type  Custom feature type.
 * @return array<string,mixed>
 */
function ndm_unit_product(string $shape, string $type = 'N'): array
{
    $product = [ 'product_id' => 500 ];

    if ('absent' === $shape) {
        return $product;
    }

    $value_key = 'N' === $type ? 'numeric_value' : ('B' === $type ? 'big_text_value' : 'text_value');
    $feature   = [
        'custom_feature_id'   => 1234,
        'custom_feature_type' => $type,
    ];

    if ('empty' === $shape) {
        $feature[$value_key] = '';
    } elseif ('malformed' === $shape) {
        $feature[$value_key]       = 'op aanvraag';
        $feature['not_applicable'] = true;
    } else {
        $feature[$value_key] = 'N' === $type ? 42 : 'Mapped value';
    }

    $product['_custom_classes'] = [
        [
            'custom_class_id'  => 9,
            '_custom_features' => [ $feature ],
        ],
    ];

    return $product;
}

// ------------------------------------------------------------------
// "Nothing to say" is the safe answer, by every route to it
// ------------------------------------------------------------------

test('the numeric resolver says "nothing" for absent, empty and malformed payloads', function (string $shape) {
    expect($this->extractor->resolve_numeric_feature_value(ndm_unit_product($shape), '1234'))
        ->toBeNull("shape: {$shape}");
})->with([ 'absent', 'empty', 'malformed' ]);

test('the text resolver says "nothing" for absent, empty and malformed payloads', function (string $shape) {
    expect($this->extractor->resolve_text_feature_value(ndm_unit_product($shape, 'T'), '1234', 'nl'))
        ->toBe('', "shape: {$shape}");
})->with([ 'absent', 'empty', 'malformed' ]);

test('a non-numeric value is "nothing" to the numeric resolver but a real value to the text one', function () {
    // The same payload, read by the two resolvers, must give the two different right answers:
    // "op aanvraag" is not a stock quantity, but it is a legitimate piece of prose.
    $product = [
        'product_id'      => 500,
        '_custom_classes' => [
            [
                'custom_class_id'  => 9,
                '_custom_features' => [
                    [
                        'custom_feature_id'   => 1234,
                        'custom_feature_type' => 'T',
                        'text_value'          => 'op aanvraag',
                    ],
                ],
            ],
        ],
    ];

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
    expect($this->extractor->resolve_text_feature_value($product, '1234', 'nl'))->toBe('op aanvraag');
});

// ------------------------------------------------------------------
// The controls — these prove the resolvers can say "something" at all
// ------------------------------------------------------------------

test('the controls resolve, so a "nothing" answer above is a real result', function () {
    expect($this->extractor->resolve_numeric_feature_value(ndm_unit_product('valid'), '1234'))->toBe(42.0);
    expect($this->extractor->resolve_text_feature_value(ndm_unit_product('valid', 'T'), '1234', 'nl'))->toBe('Mapped value');
    expect($this->extractor->resolve_text_feature_value(ndm_unit_product('valid', 'B'), '1234', 'nl'))->toBe('Mapped value');
});

// ------------------------------------------------------------------
// Scope: product level only
// ------------------------------------------------------------------

test('a value under trade-item custom classes is "nothing" to both resolvers', function () {
    $product = [
        'product_id'      => 500,
        '_trade_items'    => [
            [
                '_trade_item_custom_classes' => [
                    [
                        'custom_class_id'  => 9,
                        '_custom_features' => [
                            [
                                'custom_feature_id'   => 1234,
                                'custom_feature_type' => 'N',
                                'numeric_value'       => 42,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
    expect($this->extractor->resolve_text_feature_value($product, '1234', 'nl'))->toBe('');
});

// ------------------------------------------------------------------
// An unconfigured mapping resolves nothing even from a payload full of values
// ------------------------------------------------------------------

test('an unconfigured mapping resolves nothing from a payload that does carry a value', function () {
    // Deliberately a resolvable payload, so this proves the mapping is off rather than that
    // empty input produced empty output.
    $product = ndm_unit_product('valid');

    expect($this->extractor->resolve_numeric_feature_value($product, ''))->toBeNull();
    expect($this->extractor->resolve_text_feature_value($product, '', 'nl'))->toBe('');
});
