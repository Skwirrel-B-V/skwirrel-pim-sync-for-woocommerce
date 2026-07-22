<?php

declare(strict_types=1);

/**
 * Tests for the configurable product status mapping (GH-40 Story 1).
 *
 * Covers Skwirrel_WC_Sync_Product_Mapper::get_status(), get_status_label(),
 * set_status_handling(), note_seen_status(), get_trashed_state() and
 * get_missing_state(). The mapper is loaded by the test bootstrap.
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

// --- Zero-config (legacy) behaviour: unchanged until a mapping is set ---

test('legacy: a "draft" description resolves to draft with no mapping', function () {
    expect($this->mapper->get_status(productWithStatus('Draft - not published')))->toBe('draft');
});

test('unmapped non-draft label defaults to publish', function () {
    expect($this->mapper->get_status(productWithStatus('Foobar')))->toBe('publish');
});

test('trashed upstream defaults to trash', function () {
    expect($this->mapper->get_status(productWithStatus(null, true)))->toBe('trash');
});

test('empty status defaults to publish', function () {
    expect($this->mapper->get_status(productWithStatus(null)))->toBe('publish');
});

// --- get_status_label normalization ---

test('get_status_label normalizes a real label and detects pseudo statuses', function () {
    expect($this->mapper->get_status_label(productWithStatus('Discontinued')))->toBe('discontinued');
    expect($this->mapper->get_status_label(productWithStatus(null, true)))->toBe(Skwirrel_WC_Sync_Product_Mapper::PSEUDO_TRASHED);
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

test('__trashed__ mapping overrides the default trash behaviour', function () {
    $this->mapper->set_status_handling([Skwirrel_WC_Sync_Product_Mapper::PSEUDO_TRASHED => 'draft'], 'publish');
    expect($this->mapper->get_status(productWithStatus(null, true)))->toBe('draft');
});

test('invalid states in the mapping are ignored (fall through to default)', function () {
    $this->mapper->set_status_handling(['foobar' => 'bogus'], 'publish');
    expect($this->mapper->get_status(productWithStatus('Foobar')))->toBe('publish');
});

// --- Pseudo-status accessors used by the purge/variable paths ---

test('get_missing_state defaults to trash and honours the mapping', function () {
    expect($this->mapper->get_missing_state())->toBe('trash');
    $this->mapper->set_status_handling([Skwirrel_WC_Sync_Product_Mapper::PSEUDO_MISSING => 'publish'], 'publish');
    expect($this->mapper->get_missing_state())->toBe('publish');
    $this->mapper->set_status_handling([Skwirrel_WC_Sync_Product_Mapper::PSEUDO_MISSING => 'draft'], 'publish');
    expect($this->mapper->get_missing_state())->toBe('draft');
});

test('get_trashed_state defaults to trash and honours the mapping', function () {
    expect($this->mapper->get_trashed_state())->toBe('trash');
    $this->mapper->set_status_handling([Skwirrel_WC_Sync_Product_Mapper::PSEUDO_TRASHED => 'draft'], 'publish');
    expect($this->mapper->get_trashed_state())->toBe('draft');
});

// --- Label normalization (keys must round-trip through the settings form) ---

test('normalize_status_label lowercases and collapses internal whitespace', function () {
    expect(Skwirrel_WC_Sync_Product_Mapper::normalize_status_label("Out  of\tStock  "))->toBe('out of stock');
});

test('a label with internal double spaces matches its collapsed mapping key', function () {
    // The settings form stores the key collapsed; runtime lookup must produce the same key.
    $this->mapper->set_status_handling(['out of stock' => 'draft'], 'publish');
    expect($this->mapper->get_status(productWithStatus('Out  of  Stock')))->toBe('draft');
});

// --- Discovery ---

test('note_seen_status records real labels (normalized => display) and skips pseudo statuses', function () {
    $this->mapper->note_seen_status(productWithStatus('Discontinued'));
    $this->mapper->note_seen_status(productWithStatus(null, true));  // pseudo → not recorded
    $this->mapper->note_seen_status(productWithStatus(null));        // empty → not recorded

    $seen = get_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, []);
    expect($seen)->toBe(['discontinued' => 'Discontinued']);
});
