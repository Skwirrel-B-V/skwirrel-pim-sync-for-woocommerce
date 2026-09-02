<?php

declare(strict_types=1);

/**
 * Tests for the prices_managed_outside_skwirrel setting.
 *
 * Verifies that the option key is registered with a sensible default and
 * that values stored in the WP options array are surfaced through the
 * upserter's get_options() helper, which gates the price-preservation
 * branch in upsert_product_as_variation()/create_or_update_variation().
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-lookup.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-brand-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-taxonomy-manager.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php';

beforeEach(function () {
    unset($GLOBALS['_test_options']);

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
});

afterEach(function () {
    unset($GLOBALS['_test_options']);
});

/**
 * Invoke the private get_options() helper via reflection.
 */
function invokeGetOptions(object $upserter): array {
    $ref = new ReflectionMethod($upserter, 'get_options');
    return $ref->invoke($upserter);
}

test('prices_managed_outside_skwirrel defaults to false', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [];

    $opts = invokeGetOptions($this->upserter);

    expect($opts)->toHaveKey('prices_managed_outside_skwirrel');
    expect($opts['prices_managed_outside_skwirrel'])->toBeFalse();
});

test('prices_managed_outside_skwirrel returns true when enabled in settings', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'prices_managed_outside_skwirrel' => true,
    ];

    $opts = invokeGetOptions($this->upserter);

    expect($opts['prices_managed_outside_skwirrel'])->toBeTrue();
});

test('saved settings are merged over defaults without losing the new key', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
        'image_language'   => 'de',
        'verbose_logging'  => true,
    ];

    $opts = invokeGetOptions($this->upserter);

    // Saved values win.
    expect($opts['image_language'])->toBe('de');
    expect($opts['verbose_logging'])->toBeTrue();
    // New key still defaults to false.
    expect($opts['prices_managed_outside_skwirrel'])->toBeFalse();
});

test('non-array stored option falls back to defaults gracefully', function () {
    // Simulate corrupted option (string instead of array).
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = 'corrupt-string';

    $opts = invokeGetOptions($this->upserter);

    expect($opts)->toBeArray();
    expect($opts['prices_managed_outside_skwirrel'])->toBeFalse();
});

// ------------------------------------------------------------------
// The setting's actual contract: no price write, in any branch
// ------------------------------------------------------------------

test('the external-price gate is read from one helper', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php'
    );

    // One place decides it, so the four write paths cannot drift apart.
    expect(substr_count($source, "get_options()['prices_managed_outside_skwirrel']"))->toBe(1);
    expect($source)->toContain('private function prices_are_external(): bool');
    expect(substr_count($source, '$external_prices  = $this->prices_are_external();'))->toBe(4);
});

test('no price setter is left outside the external-price gate', function () {
    // The contract is absolute: when prices are managed outside Skwirrel the plugin writes no
    // price field at all — not the PIM price, not the price-on-request blank, not the
    // missing-price zero. Every setter must therefore sit under an $external_prices branch.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php'
    );
    $lines = explode("\n", $source);

    $writes = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/->set_(regular_)?price\(/', $line)) {
            $writes[] = $i;
        }
    }
    expect(count($writes))->toBeGreaterThan(0);

    $ungated = [];
    foreach ($writes as $at) {
        // Walk back far enough to span a whole price block (the missing-price branch carries a
        // multi-line warning call between the gate and its setters), but not into the next one.
        $window = implode("\n", array_slice($lines, max(0, $at - 30), 31));
        if (!str_contains($window, '$external_prices')) {
            $ungated[] = $at + 1;
        }
    }

    // Collected rather than asserted one at a time, so a regression names every offending line.
    expect($ungated)->toBe([], 'price setters not under the external-price gate: ' . implode(', ', $ungated));
});

