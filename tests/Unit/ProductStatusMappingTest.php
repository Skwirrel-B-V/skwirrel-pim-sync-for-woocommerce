<?php

declare(strict_types=1);

/**
 * Tests for the configurable product status mapping (GH-40 Story 1).
 *
 * Covers Skwirrel_WC_Sync_Product_Mapper::get_status(), get_status_label(),
 * set_status_handling(), note_seen_status(), extract_status(),
 * get_seen_statuses() and record_statuses_from_products(). The mapper is loaded
 * by the test bootstrap.
 */

beforeEach(function () {
    unset($GLOBALS['_test_options'][Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION]);
    $this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

afterEach(function () {
    unset($GLOBALS['_test_options'][Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION]);
});

function productWithStatus(?string $description, bool $trashed = false): array {
    $product = ['product_id' => 1];
    if ($trashed) {
        $product['product_trashed_on'] = '2026-07-22 10:00:00';
    }
    if (null !== $description) {
        $product['_product_status'] = ['product_status_description' => $description];
    }
    return $product;
}

// A product carrying the full _product_status sub-object (id + internal code + editable description),
// mirroring the real getProducts payload when include_product_status = true.
function productWithInternalStatus(string $code, ?int $id, string $description): array {
    $status = ['product_status_internal' => $code, 'product_status_description' => $description];
    if (null !== $id) {
        $status['product_status_id'] = $id;
    }
    return ['product_id' => 1, '_product_status' => $status];
}

// --- Zero-config (legacy) behaviour: unchanged until a mapping is set ---

test('legacy: a "draft" description resolves to draft with no mapping', function () {
    expect($this->mapper->get_status(productWithStatus('Draft - not published')))->toBe('draft');
});

test('unmapped non-draft label defaults to publish', function () {
    expect($this->mapper->get_status(productWithStatus('Foobar')))->toBe('publish');
});

test('product_trashed_on is ignored: with no status the product is publish, not trash', function () {
    expect($this->mapper->get_status(productWithStatus(null, true)))->toBe('publish');
});

test('empty status defaults to publish', function () {
    expect($this->mapper->get_status(productWithStatus(null)))->toBe('publish');
});

// --- get_status_label normalization ---

test('get_status_label normalizes a real label and returns PSEUDO_NONE for no status', function () {
    expect($this->mapper->get_status_label(productWithStatus('Discontinued')))->toBe('discontinued');
    expect($this->mapper->get_status_label(productWithStatus(null, true)))->toBe(Skwirrel_WC_Sync_Product_Mapper::PSEUDO_NONE);
    expect($this->mapper->get_status_label(productWithStatus('   ')))->toBe(Skwirrel_WC_Sync_Product_Mapper::PSEUDO_NONE);
});

// --- Configured mapping wins ---

test('a mapped label resolves to the configured state (case-insensitive)', function () {
    $this->mapper->set_status_handling(['discontinued' => 'draft'], 'publish');
    expect($this->mapper->get_status(productWithStatus('Discontinued')))->toBe('draft');
});

test('the configured default applies to unmapped labels', function () {
    $this->mapper->set_status_handling([], 'draft');
    expect($this->mapper->get_status(productWithStatus('Foobar')))->toBe('draft');
});

test('the "No status set" row is configurable and defaults to publish', function () {
    expect($this->mapper->get_status(productWithStatus(null)))->toBe('publish');
    $this->mapper->set_status_handling([Skwirrel_WC_Sync_Product_Mapper::PSEUDO_NONE => 'draft'], 'publish');
    expect($this->mapper->get_status(productWithStatus(null)))->toBe('draft');
});

test('invalid states in the mapping are ignored (fall through to default)', function () {
    $this->mapper->set_status_handling(['foobar' => 'bogus'], 'publish');
    expect($this->mapper->get_status(productWithStatus('Foobar')))->toBe('publish');
});

// --- Label normalization (keys must round-trip through the settings form) ---

test('normalize_status_label lowercases and collapses internal whitespace', function () {
    expect(Skwirrel_WC_Sync_Product_Mapper::normalize_status_label("Out  of\tStock  "))->toBe('out of stock');
});

test('normalize_status_label strips square brackets so form-array keys parse cleanly', function () {
    expect(Skwirrel_WC_Sync_Product_Mapper::normalize_status_label('Foo [bar]'))->toBe('foo bar');
    // A bracketed label still round-trips: stored key (bracket-free) matches at runtime.
    $this->mapper->set_status_handling(['foo bar' => 'trash'], 'publish');
    expect($this->mapper->get_status(productWithStatus('Foo [bar]')))->toBe('trash');
});

test('a label with internal double spaces matches its collapsed mapping key', function () {
    // The settings form stores the key collapsed; runtime lookup must produce the same key.
    $this->mapper->set_status_handling(['out of stock' => 'draft'], 'publish');
    expect($this->mapper->get_status(productWithStatus('Out  of  Stock')))->toBe('draft');
});

// --- Discovery ---

test('note_seen_status records new tenant statuses (structured) and skips pseudo/preset statuses', function () {
    $this->mapper->note_seen_status(productWithInternalStatus('DISCONTINUED', 3, 'Discontinued')); // preset → not recorded
    $this->mapper->note_seen_status(productWithStatus(null, true));  // pseudo → not recorded
    $this->mapper->note_seen_status(productWithStatus(null));        // empty → not recorded
    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Nabestelling')); // tenant status → recorded

    $seen = get_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, []);
    expect($seen)->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Nabestelling'],
    ]);
});

// --- Code-based keying + built-in presets ---

test('extract_status keys on the internal code, keeping id + editable label for display', function () {
    $rec = Skwirrel_WC_Sync_Product_Mapper::extract_status(productWithInternalStatus('DISCONTINUED', 3, 'Uitgefaseerd'));
    expect($rec['key'])->toBe('discontinued');
    expect($rec['id'])->toBe(3);
    expect($rec['code'])->toBe('DISCONTINUED');
    expect($rec['label'])->toBe('Uitgefaseerd');
});

test('get_status_label matches on the internal code regardless of the description text', function () {
    // Same code, different (localised/edited) descriptions -> identical map key.
    expect($this->mapper->get_status_label(productWithInternalStatus('DISCONTINUED', 3, 'Discontinued')))->toBe('discontinued');
    expect($this->mapper->get_status_label(productWithInternalStatus('DISCONTINUED', 3, 'Uitgefaseerd')))->toBe('discontinued');
});

test('the built-in presets cover DRAFT/AVAILABLE/DISCONTINUED keyed by normalized code', function () {
    $known = Skwirrel_WC_Sync_Product_Mapper::KNOWN_STATUSES;
    expect(array_keys($known))->toBe(['draft', 'available', 'discontinued']);
    expect($known['discontinued']['id'])->toBe(3);
    expect($known['discontinued']['default'])->toBe('deprecated'); // DISCONTINUED retires gradually
});

test('record_statuses_from_products records new non-preset statuses and returns the count', function () {
    $products = [
        productWithInternalStatus('AVAILABLE', 2, 'Available'),    // preset → skipped
        productWithInternalStatus('BACKORDER', 7, 'Backorder'),
        productWithInternalStatus('BACKORDER', 7, 'Backorder'),    // duplicate → counted once
        ['product_id' => 9, 'product_trashed_on' => '2026-01-01'], // pseudo → skipped
    ];
    expect(Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products($products))->toBe(1);
    expect(Skwirrel_WC_Sync_Product_Mapper::get_seen_statuses())->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Backorder'],
    ]);
});

test('get_seen_statuses tolerates the legacy string format and hides presets', function () {
    update_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, [
        'discontinued' => 'Discontinued', // legacy string + preset → hidden
        'backorder'    => 'Backorder',     // legacy string → surfaced with empty id/code
    ]);
    expect(Skwirrel_WC_Sync_Product_Mapper::get_seen_statuses())->toBe([
        'backorder' => ['id' => null, 'code' => '', 'label' => 'Backorder'],
    ]);
});
