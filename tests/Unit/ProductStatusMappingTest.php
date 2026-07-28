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

test('legacy: the description is still consulted when the internal code has no "draft" hint', function () {
    // Pre-3.12 only product_status_description decided this. A tenant status coded
    // PENDING_REVIEW but described "Draft - not published" must keep resolving to draft,
    // otherwise upgrading publishes products the old behaviour held back.
    expect($this->mapper->get_status(productWithInternalStatus('PENDING_REVIEW', 9, 'Draft - not published')))->toBe('draft');
});

test('the draft fallback also fires on the internal code alone', function () {
    expect($this->mapper->get_status(productWithInternalStatus('DRAFT_PENDING', 9, 'Nog niet vrijgegeven')))->toBe('draft');
});

test('a configured mapping wins over the legacy draft fallback', function () {
    $this->mapper->set_status_handling(['pending_review' => 'publish'], 'draft');
    expect($this->mapper->get_status(productWithInternalStatus('PENDING_REVIEW', 9, 'Draft - not published')))->toBe('publish');
});

// --- Upgrade path: pre-3.12 mappings were keyed on the description, not the internal code ---

test('a mapping saved under the pre-3.12 description key still applies', function () {
    // Upgrading with `discontinued => trash` saved must keep applying to a status whose code is
    // END_OF_LIFE and whose description is "Discontinued" — otherwise the product silently falls
    // back to the global default (publish) until an admin notices and saves the new row.
    $this->mapper->set_status_handling(['discontinued' => 'trash'], 'publish');
    expect($this->mapper->get_status(productWithInternalStatus('END_OF_LIFE', 8, 'Discontinued')))->toBe('trash');
});

test('the code-keyed mapping wins over the legacy description key', function () {
    $this->mapper->set_status_handling(['end_of_life' => 'draft', 'discontinued' => 'trash'], 'publish');
    expect($this->mapper->get_status(productWithInternalStatus('END_OF_LIFE', 8, 'Discontinued')))->toBe('draft');
});

test('the legacy fallback does not fire when the description matches the code', function () {
    // No separate legacy key exists here, so nothing extra is consulted.
    $this->mapper->set_status_handling(['backorder' => 'draft'], 'publish');
    expect($this->mapper->get_status(productWithInternalStatus('BACKORDER', 7, 'Backorder')))->toBe('draft');
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

test('note_seen_status records tenant statuses and skips products with no status', function () {
    $this->mapper->note_seen_status(productWithStatus(null, true));  // pseudo → not recorded
    $this->mapper->note_seen_status(productWithStatus(null));        // empty → not recorded
    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Nabestelling')); // tenant status → recorded

    $seen = get_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, []);
    expect($seen)->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Nabestelling'],
    ]);
});

test('a renamed built-in preset is recorded so the settings table can show its live metadata', function () {
    // A tenant may rename DRAFT/AVAILABLE/DISCONTINUED or use different numeric ids; the table
    // would otherwise keep showing the hardcoded English label and id forever.
    $this->mapper->note_seen_status(productWithInternalStatus('DISCONTINUED', 42, 'Uitgefaseerd'));

    expect(Skwirrel_WC_Sync_Product_Mapper::get_seen_status('discontinued'))
        ->toBe(['id' => 42, 'code' => 'DISCONTINUED', 'label' => 'Uitgefaseerd']);
    // ...but it is still not rendered as a *discovered* row — the preset row carries it.
    expect(Skwirrel_WC_Sync_Product_Mapper::get_seen_statuses())->toBe([]);
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

test('record_statuses_from_products records every status and returns the counts', function () {
    $products = [
        productWithInternalStatus('AVAILABLE', 2, 'Available'),    // preset → recorded (live metadata)
        productWithInternalStatus('BACKORDER', 7, 'Backorder'),
        productWithInternalStatus('BACKORDER', 7, 'Backorder'),    // duplicate → counted once
        ['product_id' => 9, 'product_trashed_on' => '2026-01-01'], // no status at all → skipped
    ];
    expect(Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products($products))
        ->toBe(['added' => 2, 'refreshed' => 0]);
    // Presets are never rendered as discovered rows, however they were recorded.
    expect(Skwirrel_WC_Sync_Product_Mapper::get_seen_statuses())->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Backorder'],
    ]);
    expect(Skwirrel_WC_Sync_Product_Mapper::get_seen_status('available'))
        ->toBe(['id' => 2, 'code' => 'AVAILABLE', 'label' => 'Available']);
});

// --- Metadata refresh: a tenant may rename a status while keeping its internal code ---

test('record_statuses_from_products refreshes a renamed status instead of keeping the stale label', function () {
    Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products([
        productWithInternalStatus('BACKORDER', 7, 'Backorder'),
    ]);

    $result = Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products([
        productWithInternalStatus('BACKORDER', 7, 'Nabestelling'), // renamed upstream
    ]);

    expect($result)->toBe(['added' => 0, 'refreshed' => 1]);
    expect(Skwirrel_WC_Sync_Product_Mapper::get_seen_statuses())->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Nabestelling'],
    ]);
});

test('record_statuses_from_products reports nothing when the stored record is already current', function () {
    $products = [productWithInternalStatus('BACKORDER', 7, 'Backorder')];
    Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products($products);
    expect(Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products($products))
        ->toBe(['added' => 0, 'refreshed' => 0]);
});

test('note_seen_status refreshes a renamed status during a sync', function () {
    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Backorder'));
    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Nabestelling'));

    expect(get_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, []))->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Nabestelling'],
    ]);
});

test('note_seen_status upgrades a legacy string record to the structured shape', function () {
    update_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, ['backorder' => 'Backorder']);

    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Backorder'));

    expect(get_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, []))->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Backorder'],
    ]);
});

test('a status carried only by upstream-removed products is still discovered', function () {
    // product_trashed_on stopped forcing a trash, so such a product is classified by its active
    // status like any other — that status must be configurable, not silently on the default.
    $product = productWithInternalStatus('BACKORDER', 7, 'Backorder');
    $product['product_trashed_on'] = '2026-07-22 10:00:00';

    $this->mapper->note_seen_status($product);
    expect(get_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, []))->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Backorder'],
    ]);

    unset($GLOBALS['_test_options'][Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION]);
    expect(Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products([$product]))
        ->toBe(['added' => 1, 'refreshed' => 0]);
});

test('an omitted product_status_id does not overwrite the stored one', function () {
    // A feed that returns the id on some pages but not others would otherwise flip the record
    // on every run and report a refresh forever.
    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Backorder'));
    $without_id = productWithInternalStatus('BACKORDER', null, 'Backorder');

    expect(Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products([$without_id]))
        ->toBe(['added' => 0, 'refreshed' => 0]);
    expect(Skwirrel_WC_Sync_Product_Mapper::get_seen_statuses())->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Backorder'],
    ]);
});

test('unmapped_state is the single rule shared by the sync and the settings table', function () {
    // The row the settings table pre-selects must be the state the sync already applies.
    expect(Skwirrel_WC_Sync_Product_Mapper::unmapped_state('pending_review', 'Draft - not published', 'publish'))->toBe('draft');
    expect(Skwirrel_WC_Sync_Product_Mapper::unmapped_state('draft_pending', 'Nog niet vrijgegeven', 'publish'))->toBe('draft');
    expect(Skwirrel_WC_Sync_Product_Mapper::unmapped_state('backorder', 'Nabestelling', 'publish'))->toBe('publish');
    expect(Skwirrel_WC_Sync_Product_Mapper::unmapped_state('backorder', 'Nabestelling', 'draft'))->toBe('draft');
});

test('note_seen_status refreshes a status at most once per process', function () {
    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Backorder'));
    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Nabestelling'));
    // A third product carrying yet another label must not trigger another write — otherwise an
    // inconsistent feed would cost one update_option() per product.
    $this->mapper->note_seen_status(productWithInternalStatus('BACKORDER', 7, 'Backorder'));

    expect(get_option(Skwirrel_WC_Sync_Product_Mapper::SEEN_STATUSES_OPTION, []))->toBe([
        'backorder' => ['id' => 7, 'code' => 'BACKORDER', 'label' => 'Nabestelling'],
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
