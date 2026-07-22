<?php

declare(strict_types=1);

/**
 * Tests for the Deprecated product lifecycle (GH-40 Story 2).
 *
 * Covers Skwirrel_WC_Sync_Deprecated_Status::escalate() (the pure counter/threshold math) and
 * that `deprecated` is a first-class mapping target in the mapper.
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-deprecated-status.php';

beforeEach(function () {
    $this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

function deprecatedProduct(?string $description, bool $trashed = false): array {
    $product = ['product_id' => 1];
    if ($trashed) {
        $product['product_trashed_on'] = '2026-07-22 10:00:00';
    }
    if (null !== $description) {
        $product['_product_status'] = ['product_status_description' => $description];
    }
    return $product;
}

// --- escalate() counter/threshold math ---

test('escalate removes immediately when threshold is 0', function () {
    expect(Skwirrel_WC_Sync_Deprecated_Status::escalate(0, 0))->toBe(['count' => 1, 'remove' => true]);
});

test('escalate keeps the product while count is within the threshold', function () {
    expect(Skwirrel_WC_Sync_Deprecated_Status::escalate(0, 3))->toBe(['count' => 1, 'remove' => false]);
    expect(Skwirrel_WC_Sync_Deprecated_Status::escalate(2, 3))->toBe(['count' => 3, 'remove' => false]);
});

test('escalate removes once the counter exceeds the threshold', function () {
    expect(Skwirrel_WC_Sync_Deprecated_Status::escalate(3, 3))->toBe(['count' => 4, 'remove' => true]);
});

// --- deprecated as a mapping target ---

test('a label mapped to deprecated resolves to the deprecated status', function () {
    $this->mapper->set_status_handling(['discontinued' => 'deprecated'], 'publish');
    expect($this->mapper->get_status(deprecatedProduct('Discontinued')))->toBe('deprecated');
});

test('the __trashed__ and __missing__ pseudo statuses accept deprecated', function () {
    $this->mapper->set_status_handling([
        Skwirrel_WC_Sync_Product_Mapper::PSEUDO_TRASHED => 'deprecated',
        Skwirrel_WC_Sync_Product_Mapper::PSEUDO_MISSING => 'deprecated',
    ], 'publish');

    expect($this->mapper->get_trashed_state())->toBe('deprecated');
    expect($this->mapper->get_missing_state())->toBe('deprecated');
    expect($this->mapper->get_status(deprecatedProduct(null, true)))->toBe('deprecated');
});

test('an invalid state is still ignored even with the widened whitelist', function () {
    $this->mapper->set_status_handling(['foo' => 'bogus'], 'publish');
    expect($this->mapper->get_status(deprecatedProduct('Foo')))->toBe('publish');
});
