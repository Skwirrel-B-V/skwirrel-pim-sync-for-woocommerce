<?php

declare(strict_types=1);

/**
 * Tests for the FR-18 stock mapping (Story 6.1).
 *
 * The resolver lives on Skwirrel_WC_Sync_Custom_Class_Extractor and is pure: it
 * reads product-level `_custom_classes` only and returns a raw numeric value or
 * null. Null is the NFR-9 contract — the caller must then leave WooCommerce's
 * existing stock exactly as it was, never writing 0 and never flipping
 * manage_stock.
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-lookup.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-brand-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-taxonomy-manager.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php';

beforeEach(function () {
    unset($GLOBALS['_test_options']);
    $this->extractor = new Skwirrel_WC_Sync_Custom_Class_Extractor('nl');

    $logger           = new Skwirrel_WC_Sync_Logger();
    $mapper           = new Skwirrel_WC_Sync_Product_Mapper();
    $lookup           = new Skwirrel_WC_Sync_Product_Lookup($mapper);
    $brand_sync       = new Skwirrel_WC_Sync_Brand_Sync($logger);
    $taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager($logger);
    $category_sync    = new Skwirrel_WC_Sync_Category_Sync($logger, $mapper);
    $slug_resolver    = new Skwirrel_WC_Sync_Slug_Resolver();

    $this->mapper   = $mapper;
    $this->upserter = new Skwirrel_WC_Sync_Product_Upserter(
        $logger,
        $mapper,
        $lookup,
        $category_sync,
        $brand_sync,
        $taxonomy_manager,
        $slug_resolver
    );
});

afterEach(function () {
    unset($GLOBALS['_test_options']);
});

/**
 * Build a product carrying one product-level custom feature.
 *
 * @param array<string,mixed> $feature Feature payload overrides.
 * @return array<string,mixed>
 */
function stock_product(array $feature): array
{
    return [
        'product_id'      => 42,
        '_custom_classes' => [
            [
                'custom_class_id'   => 7,
                'custom_class_code' => 'logistics',
                '_custom_features'  => [ $feature ],
            ],
        ],
    ];
}

// ------------------------------------------------------------------
// AC 9 — the four pinned cases
// ------------------------------------------------------------------

test('a numeric value resolves for a feature matched by ID', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_code' => 'STOCK_QTY',
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(500.0);
});

test('a numeric value resolves through custom_class_feature_id when the primary alias is empty', function () {
    $product = stock_product([
        'custom_feature_id'       => '',
        'custom_class_feature_id' => 1234,
        'custom_feature_type'     => 'N',
        'numeric_value'           => 500,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(500.0);
});

test('a missing feature resolves to null', function () {
    $product = stock_product([
        'custom_feature_id'   => 999,
        'custom_feature_code' => 'SOMETHING_ELSE',
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

test('a non-numeric value resolves to null', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'T',
        'text_value'          => 'plenty in stock',
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

test('an unconfigured mapping resolves to null without reading the payload', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, ''))->toBeNull();
    expect($this->extractor->resolve_numeric_feature_value($product, '   '))->toBeNull();
});

// ------------------------------------------------------------------
// AC 2 — product-level scope, code matching, not_applicable
// ------------------------------------------------------------------

test('a feature matches by code, case-insensitively', function () {
    $product = stock_product([
        'custom_feature_code' => 'Stock_Qty',
        'custom_feature_type' => 'N',
        'numeric_value'       => 12,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, 'stock_qty'))->toBe(12.0);
    expect($this->extractor->resolve_numeric_feature_value($product, 'STOCK_QTY'))->toBe(12.0);
});

test('a value present only on a trade-item custom class resolves to null', function () {
    $product = [
        'product_id'      => 42,
        '_custom_classes' => [],
        '_trade_items'    => [
            [
                '_trade_item_custom_classes' => [
                    [
                        'custom_class_id'  => 7,
                        '_custom_features' => [
                            [
                                'custom_feature_id'   => 1234,
                                'custom_feature_type' => 'N',
                                'numeric_value'       => 500,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

test('a not_applicable feature resolves to null', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
        'not_applicable'      => true,
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
});

test('an empty numeric value resolves to null', function () {
    foreach ([ null, '' ] as $empty) {
        $product = stock_product([
            'custom_feature_id'   => 1234,
            'custom_feature_type' => 'N',
            'numeric_value'       => $empty,
        ]);

        expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBeNull();
    }
});

test('a numeric text value resolves for type T', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'T',
        'text_value'          => '250',
    ]);

    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(250.0);
});

test('the raw number is returned, not the formatted display string with its unit', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 500,
        'custom_unit_code'    => 'st',
    ]);

    // format_custom_feature_value() would yield "500 st"; stock needs the bare number.
    expect($this->extractor->resolve_numeric_feature_value($product, '1234'))->toBe(500.0);
});

// ------------------------------------------------------------------
// AC 1 / AC 4 — the setting and its default
// ------------------------------------------------------------------

test('stock_quantity_feature defaults to an empty string', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [];

    $ref  = new ReflectionMethod($this->upserter, 'get_options');
    $opts = $ref->invoke($this->upserter);

    expect($opts)->toHaveKey('stock_quantity_feature');
    expect($opts['stock_quantity_feature'])->toBe('');
});

test('a stored stock_quantity_feature is surfaced through get_options', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'stock_quantity_feature' => 'STOCK_QTY',
    ];

    $ref  = new ReflectionMethod($this->upserter, 'get_options');
    $opts = $ref->invoke($this->upserter);

    expect($opts['stock_quantity_feature'])->toBe('STOCK_QTY');
});

// ------------------------------------------------------------------
// A run freezes the mapping it writes stock with
// ------------------------------------------------------------------

test('a frozen run keeps its own stock mapping after the live setting changes', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'stock_quantity_feature' => 'STOCK_QTY',
    ];
    $this->upserter->set_run_options(['stock_quantity_feature' => 'STOCK_QTY']);

    // An administrator retargets (or clears) the mapping while the run is between steps.
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'stock_quantity_feature' => '',
    ];

    $ref = new ReflectionMethod($this->upserter, 'stock_mapping_setting');

    expect($ref->invoke($this->upserter))->toBe('STOCK_QTY');
});

test('an upserter with no frozen run reads the live stock mapping', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'stock_quantity_feature' => 'LIVE_QTY',
    ];
    $this->upserter->set_run_options(null);

    $ref = new ReflectionMethod($this->upserter, 'stock_mapping_setting');

    expect($ref->invoke($this->upserter))->toBe('LIVE_QTY');
});

test('a frozen run that turned the mapping off leaves stock alone for the whole run', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'stock_quantity_feature' => 'STOCK_QTY',
    ];
    $this->upserter->set_run_options(['stock_quantity_feature' => '']);

    $ref = new ReflectionMethod($this->upserter, 'stock_mapping_is_active');

    expect($ref->invoke($this->upserter))->toBeFalse();
});

test('a frozen run keeps the languages it started with', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'include_languages' => [ 'fr-BE', 'fr' ],
        'image_language'    => 'fr',
    ];
    $this->upserter->set_run_options([
        'include_languages' => [ 'nl-NL', 'nl' ],
        'image_language'    => 'nl',
    ]);

    $ref = new ReflectionMethod($this->upserter, 'get_include_languages');

    // These pick the ETIM and custom-class variation terms, so two siblings of the same variable
    // product must never be resolved in different languages because a save landed between them.
    expect($ref->invoke($this->upserter))->toBe(['nl-NL', 'nl']);
});

test('the upserter reads no setting behind the run\'s back', function () {
    // One read of the live option is allowed: the fallback inside get_options() itself, for the
    // callers that are not a run. Every other setting has to come through it.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php'
    );

    expect(substr_count($source, "get_option( 'skwirrel_wc_sync_settings'"))->toBe(1);
    expect($source)->toContain('$saved    = null === $this->run_options ? get_option(');
});

// ------------------------------------------------------------------
// The mapper delegates, consistent with every other custom-class field
// ------------------------------------------------------------------

test('the mapper delegates the resolver to the extractor', function () {
    $product = stock_product([
        'custom_feature_id'   => 1234,
        'custom_feature_type' => 'N',
        'numeric_value'       => 88,
    ]);

    expect($this->mapper->get_stock_quantity($product, '1234'))->toBe(88.0);
    expect($this->mapper->get_stock_quantity($product, ''))->toBeNull();
});

// ------------------------------------------------------------------
// Class qualifier — the same feature in more than one class
// ------------------------------------------------------------------

/**
 * Build a product with one stock class per location, all carrying the same quantity feature.
 *
 * @return array<string,mixed>
 */
function two_location_product(): array
{
    $class = fn (int $id, string $code, mixed $qty) => [
        'custom_class_id'   => $id,
        'custom_class_code' => $code,
        '_custom_features'  => [
            [
                'custom_feature_id'   => 4,
                'custom_feature_code' => 'FUSE5_QTY_ONHAND',
                'custom_feature_type' => 'N',
                'numeric_value'       => $qty,
            ],
        ],
    ];

    return [
        'product_id'      => 42,
        '_custom_classes' => [
            $class(3, 'USPC_FUSE5_HENDRIK_IDO_AMBACHT', 7),
            $class(2, 'USPC_FUSE5_DALLAS', 25),
        ],
    ];
}

test('without a class, a feature shared by several classes resolves from the first class', function () {
    expect($this->extractor->resolve_numeric_feature_value(two_location_product(), 'FUSE5_QTY_ONHAND'))->toBe(7.0);
});

test('a class selects which class the feature is read from', function () {
    expect($this->extractor->resolve_numeric_feature_value(two_location_product(), 'FUSE5_QTY_ONHAND', 'USPC_FUSE5_DALLAS'))->toBe(25.0);
});

test('a class matches by ID or by code, case-insensitively', function (string $class, string $feature, float $expected) {
    expect($this->extractor->resolve_numeric_feature_value(two_location_product(), $feature, $class))->toBe($expected);
})->with([
    'class ID'              => ['2', 'FUSE5_QTY_ONHAND', 25.0],
    'lowercase class code'  => ['uspc_fuse5_dallas', 'FUSE5_QTY_ONHAND', 25.0],
    'class code, feature ID'=> ['USPC_FUSE5_DALLAS', '4', 25.0],
    'class ID, feature ID'  => ['3', '4', 7.0],
    'padded class'          => ['  USPC_FUSE5_DALLAS ', 'FUSE5_QTY_ONHAND', 25.0],
]);

test('a chosen class never falls back to another class', function () {
    $product = two_location_product();
    $product['_custom_classes'][1]['_custom_features'][0]['not_applicable'] = true;

    expect($this->extractor->resolve_numeric_feature_value($product, 'FUSE5_QTY_ONHAND', 'USPC_FUSE5_DALLAS'))->toBeNull();
});

test('a class the product does not have resolves to null', function (string $class) {
    expect($this->extractor->resolve_numeric_feature_value(two_location_product(), 'FUSE5_QTY_ONHAND', $class))->toBeNull();
})->with(['NOWHERE', '99', '0', '-2', '2.5']);

test('the mapper reads stock from the chosen class', function () {
    expect($this->mapper->get_stock_quantity(two_location_product(), 'FUSE5_QTY_ONHAND', 'USPC_FUSE5_DALLAS'))->toBe(25.0);
});

test('the extractor lists every class that holds a feature, in payload order', function () {
    expect($this->extractor->classes_with_feature(two_location_product(), 'FUSE5_QTY_ONHAND'))
        ->toBe(['USPC_FUSE5_HENDRIK_IDO_AMBACHT', 'USPC_FUSE5_DALLAS']);
});

test('the ambiguity warning goes through the logger the mapper was given', function () {
    $logger = new class () extends Skwirrel_WC_Sync_Logger {
        /** @var array<int, array{0: string, 1: array<string, mixed>}> */
        public array $warnings = [];

        public function warning(string $message, array $context = []): void
        {
            $this->warnings[] = [$message, $context];
        }
    };
    $mapper = new Skwirrel_WC_Sync_Product_Mapper($logger);

    $mapper->get_stock_quantity(two_location_product(), 'FUSE5_QTY_ONHAND');

    expect($logger->warnings)->toHaveCount(1);
    expect($logger->warnings[0][1]['classes'])->toBe(['USPC_FUSE5_HENDRIK_IDO_AMBACHT', 'USPC_FUSE5_DALLAS']);
});
