<?php

declare(strict_types=1);

/**
 * Tests for the content-hash gate (Skwirrel_WC_Sync_Product_Upserter::content_hash). The hash must be
 * a fingerprint of the product CONTENT, not of the API's modification metadata: a re-fetch that only
 * bumps `product_updated_on` (the API does this even when nothing changed) must NOT change the hash,
 * otherwise enforce mode would gain nothing over the timestamp gate.
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-lookup.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-brand-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-taxonomy-manager.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php';

beforeEach(function () {
    $logger           = new Skwirrel_WC_Sync_Logger();
    $mapper           = new Skwirrel_WC_Sync_Product_Mapper();
    $lookup           = new Skwirrel_WC_Sync_Product_Lookup($mapper);
    $brand_sync       = new Skwirrel_WC_Sync_Brand_Sync($logger);
    $taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager($logger);
    $category_sync    = new Skwirrel_WC_Sync_Category_Sync($logger, $mapper);
    $slug_resolver    = new Skwirrel_WC_Sync_Slug_Resolver();

    $this->upserter = new Skwirrel_WC_Sync_Product_Upserter(
        $logger,
        $mapper,
        $lookup,
        $category_sync,
        $brand_sync,
        $taxonomy_manager,
        $slug_resolver
    );

    // content_hash() is private (called from the upsert path); exercise it directly via reflection.
    $this->hash = function (array $product): string {
        $method = new ReflectionMethod(Skwirrel_WC_Sync_Product_Upserter::class, 'content_hash');
        return $method->invoke($this->upserter, $product);
    };
});

test('hashing off returns empty string', function () {
    $this->upserter->set_content_hash_context('off', 'sig');
    expect(($this->hash)(['product_id' => 1, 'product_erp_description' => 'Bolt']))->toBe('');
});

test('a bumped product_updated_on does not change the hash', function () {
    $this->upserter->set_content_hash_context('observe', 'sig-v1');

    $base = [
        'product_id'              => 42,
        'product_erp_description' => 'APPLE IPHONE 17',
        'product_updated_on'      => '2026-06-24T13:33:16+02:00',
        '_etim_features'          => [['code' => 'EF000001', 'value' => 'A']],
    ];
    $rebumped                       = $base;
    $rebumped['product_updated_on'] = '2026-06-24T15:45:09+02:00';

    expect(($this->hash)($rebumped))->toBe(($this->hash)($base));
});

test('a real content change still changes the hash', function () {
    $this->upserter->set_content_hash_context('observe', 'sig-v1');

    $base = [
        'product_id'              => 42,
        'product_erp_description' => 'APPLE IPHONE 17',
        'product_updated_on'      => '2026-06-24T13:33:16+02:00',
    ];
    $changed                            = $base;
    $changed['product_erp_description'] = 'APPLE IPHONE 17 PRO';

    expect(($this->hash)($changed))->not->toBe(($this->hash)($base));
});

test('the settings signature is folded into the hash', function () {
    $product = ['product_id' => 42, 'product_erp_description' => 'Bolt'];

    $this->upserter->set_content_hash_context('observe', 'sig-v1');
    $a = ($this->hash)($product);

    $this->upserter->set_content_hash_context('observe', 'sig-v2');
    $b = ($this->hash)($product);

    expect($a)->not->toBe($b);
});

test('payload_signature computes even when the product hash mode is off', function () {
    // The grouped-product and virtual-content gates (Option A) key off change_gate_enabled, not the
    // product hash mode, so payload_signature() must return a real hash even with mode 'off'.
    $this->upserter->set_content_hash_context('off', 'sig-v1');

    $sig = $this->upserter->payload_signature(['grouped_product_id' => 7, 'grouped_product_name' => 'iPhone 17']);
    expect($sig)->not->toBe('');
});

test('payload_signature ignores product_updated_on and reflects real changes', function () {
    $this->upserter->set_content_hash_context('off', 'sig-v1');

    $base                       = ['grouped_product_id' => 7, 'product_updated_on' => '2026-06-24T13:00:00+02:00'];
    $rebumped                   = $base;
    $rebumped['product_updated_on'] = '2026-06-24T15:45:00+02:00';
    $renamed                    = $base;
    $renamed['grouped_product_name'] = 'Changed';

    expect($this->upserter->payload_signature($rebumped))->toBe($this->upserter->payload_signature($base));
    expect($this->upserter->payload_signature($renamed))->not->toBe($this->upserter->payload_signature($base));
});

test('the content_hash_exclude filter drops additional volatile keys', function () {
    $this->upserter->set_content_hash_context('observe', 'sig-v1');

    $filter = function () {
        return ['_trade_item_prices'];
    };
    add_filter('skwirrel_wc_sync_content_hash_exclude', $filter);

    $base = [
        'product_id'         => 42,
        '_trade_item_prices' => [['net_price' => 100]],
    ];
    $repriced                       = $base;
    $repriced['_trade_item_prices'] = [['net_price' => 250]];

    expect(($this->hash)($repriced))->toBe(($this->hash)($base));

    remove_filter('skwirrel_wc_sync_content_hash_exclude', $filter);
});

// ------------------------------------------------------------------
// Story 6.3 (AC 8) — the change gate sees a mapped content change.
// Verification, not new machinery: _custom_classes is part of the hashed payload.
// ------------------------------------------------------------------

test('a changed custom-class value changes the content hash', function () {
    $this->upserter->set_content_hash_context('observe', 'sig-v1');

    $before = [
        'product_id'       => 1,
        'product_updated_on' => '2026-01-01T00:00:00Z',
        '_custom_classes'  => [
            [
                'custom_class_id'  => 3,
                '_custom_features' => [
                    [ 'custom_feature_id' => 812, 'custom_feature_type' => 'T', 'text_value' => 'Old title' ],
                ],
            ],
        ],
    ];
    $after                  = $before;
    $after['_custom_classes'][0]['_custom_features'][0]['text_value'] = 'New title';

    expect(($this->hash)($before))->not->toBe(($this->hash)($after));
});

test('a custom-class change is still seen when only product_updated_on also moved', function () {
    $this->upserter->set_content_hash_context('observe', 'sig-v1');

    $before = [
        'product_id'         => 1,
        'product_updated_on' => '2026-01-01T00:00:00Z',
        '_custom_classes'    => [
            [ '_custom_features' => [ [ 'custom_feature_id' => 814, 'custom_feature_type' => 'B', 'big_text_value' => 'A' ] ] ],
        ],
    ];
    // Only the volatile key moves: the hash must be identical.
    $touched                       = $before;
    $touched['product_updated_on'] = '2026-02-02T00:00:00Z';
    expect(($this->hash)($before))->toBe(($this->hash)($touched));

    // Now the content moves too: the hash must differ.
    $changed = $touched;
    $changed['_custom_classes'][0]['_custom_features'][0]['big_text_value'] = 'B';
    expect(($this->hash)($touched))->not->toBe(($this->hash)($changed));
});
