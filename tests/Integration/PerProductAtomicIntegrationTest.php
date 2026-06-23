<?php
/**
 * Integration tests for the per-product-atomic sync (3.11.0).
 *
 * Verifies, against a real WordPress + WooCommerce stack, the two behaviours the
 * rewrite is responsible for:
 *   1. a newly-created product is held as draft during the per-product commit and
 *      flipped to 'publish' only once fully committed (draft-until-complete);
 *   2. a product whose SKU already exists is reused, never duplicated with a
 *      suffixed SKU (F7), and `_skwirrel_external_id` stays free of duplicates.
 *
 * The JSON-RPC endpoint is stubbed via `pre_http_request`.
 */

declare(strict_types=1);

beforeEach(function () {
	foreach (['settings', 'auth_token', 'last_sync', 'last_result', 'history', 'last_sync_sig'] as $k) {
		delete_option("skwirrel_wc_sync_{$k}");
	}

	update_option('skwirrel_wc_sync_settings', [
		'endpoint_url'                   => 'https://test.skwirrel.example/jsonrpc',
		'auth_type'                      => 'bearer',
		'timeout'                        => 5,
		'retries'                        => 0,
		'batch_size'                     => 10,
		'collection_ids'                 => '1',
		'custom_collection_id'           => '1',
		'sync_categories'                => false,
		'sync_grouped_products'          => false,
		'sync_custom_classes'            => false,
		'sync_trade_item_custom_classes' => false,
		'sync_images'                    => false,
		'sync_related_products'          => false,
	]);
	update_option('skwirrel_wc_sync_auth_token', 'test-token-123');
});

afterEach(function () {
	remove_all_filters('pre_http_request');
});

/**
 * Stub the JSON-RPC endpoint with a single product returned on page 1 of
 * getProductsByFilter (and on the per-product attribute refetch), empty after.
 *
 * @param array<string, mixed> $product Skwirrel product payload.
 */
function atomicStubSingleProduct(array $product): void {
	add_filter('pre_http_request', function ($pre, $args, $url) use ($product) {
		if (strpos($url, 'test.skwirrel.example') === false) {
			return $pre;
		}
		$body   = json_decode((string) ($args['body'] ?? ''), true);
		$method = $body['method'] ?? '';
		$params = $body['params'] ?? [];
		$id     = $body['id'] ?? 1;

		$result = [];
		if ('getBrands' === $method) {
			$result = ['brands' => []];
		} elseif ('getProductsByFilter' === $method) {
			$is_attr_fetch = ($params['filter']['code']['type'] ?? '') === 'product_id';
			if ($is_attr_fetch) {
				$result = ['products' => [$product]];
			} else {
				// Page-based (not a call counter) so the stub returns the product on page 1 of
				// every run — needed for tests that run the sync more than once.
				$page   = (int) ($params['page'] ?? 1);
				$result = ['products' => 1 === $page ? [$product] : []];
			}
		}

		return [
			'headers'  => [],
			'body'     => wp_json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]),
			'response' => ['code' => 200, 'message' => 'OK'],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3);
}

test('a newly-created product is published (held as draft, then flipped on completion)', function () {
	atomicStubSingleProduct([
		'product_id'              => 910001,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-910001',
		'internal_product_code'   => 'SKU-910001',
		'product_erp_description' => 'Atomic Widget',
		'_product_status'         => ['product_status_description' => 'active'],
	]);

	$result = (new Skwirrel_WC_Sync_Service())->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);

	expect($result['success'])->toBeTrue();
	expect($result['created'])->toBe(1);

	$matches = wc_get_products(['sku' => 'SKU-910001', 'limit' => 1, 'status' => ['publish', 'draft']]);
	expect($matches)->toHaveCount(1);
	// The product was created as draft and flipped to publish only after the full
	// per-product commit — a stuck 'draft' here would mean the flip never ran.
	expect($matches[0]->get_status())->toBe('publish');
});

test('a product whose SKU already exists is reused, never duplicated with a suffixed SKU (F7)', function () {
	// Seed a WC product carrying the SKU but WITHOUT any Skwirrel identity meta,
	// so the meta lookups miss and only the SKU match can reconcile it.
	$existing = new WC_Product_Simple();
	$existing->set_name('Pre-existing');
	$existing->set_sku('SKU-910002');
	$existing->set_status('publish');
	$existing_id = $existing->save();

	atomicStubSingleProduct([
		'product_id'              => 910002,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-910002',
		'internal_product_code'   => 'SKU-910002',
		'product_erp_description' => 'Reconciled Widget',
		'_product_status'         => ['product_status_description' => 'active'],
	]);

	$result = (new Skwirrel_WC_Sync_Service())->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);

	expect($result['success'])->toBeTrue();
	expect($result['created'])->toBe(0);   // reused, not created
	expect($result['updated'])->toBe(1);

	// Exactly one product owns the SKU, and no suffixed `-910002` duplicate exists.
	$same_sku = wc_get_products(['sku' => 'SKU-910002', 'limit' => 10, 'status' => ['publish', 'draft']]);
	expect($same_sku)->toHaveCount(1);
	expect($same_sku[0]->get_id())->toBe($existing_id);
	expect(wc_get_product_id_by_sku('SKU-910002-910002'))->toBe(0);

	// The reconciled product now carries the Skwirrel identity meta.
	expect(get_post_meta($existing_id, '_skwirrel_product_id', true))->toBe('910002');

	// Duplicate-key canary: no _skwirrel_external_id appears on more than one product.
	global $wpdb;
	$dupes = $wpdb->get_var(
		"SELECT COUNT(*) FROM (
			SELECT meta_value FROM {$wpdb->postmeta}
			WHERE meta_key = '_skwirrel_external_id'
			GROUP BY meta_value HAVING COUNT(*) > 1
		) d"
	);
	expect((int) $dupes)->toBe(0);
});

test('a second full sync reports the product as unchanged when product_updated_on has not advanced', function () {
	atomicStubSingleProduct([
		'product_id'              => 920001,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-920001',
		'internal_product_code'   => 'SKU-920001',
		'product_erp_description' => 'Gate Widget',
		'product_updated_on'      => '2026-06-23T10:00:00+02:00',
		'_product_status'         => ['product_status_description' => 'active'],
	]);

	$service = new Skwirrel_WC_Sync_Service();

	// Run 1: first run (no stored settings signature yet) → gate disabled → product is created.
	$r1 = $service->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);
	expect($r1['success'])->toBeTrue();
	expect($r1['created'])->toBe(1);
	expect($r1['unchanged'] ?? 0)->toBe(0);

	$wc_id      = wc_get_product_id_by_sku('SKU-920001');
	$modified_1 = get_post_field('post_modified_gmt', $wc_id);

	// Run 2: signature matches and product_updated_on is identical → unchanged, not updated,
	// and the product is not re-saved (post_modified unchanged), but synced_at still advances.
	$synced_before = (int) get_post_meta($wc_id, '_skwirrel_synced_at', true);
	sleep(1);
	$r2 = $service->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);

	expect($r2['success'])->toBeTrue();
	expect($r2['created'])->toBe(0);
	expect($r2['updated'])->toBe(0);
	expect($r2['unchanged'])->toBe(1);

	expect(get_post_field('post_modified_gmt', $wc_id))->toBe($modified_1); // not re-saved
	expect((int) get_post_meta($wc_id, '_skwirrel_synced_at', true))->toBeGreaterThan($synced_before); // still seen (purge-safe)
});

test('an initial delta run advances last_sync only after completing', function () {
	delete_option('skwirrel_wc_sync_last_sync');

	atomicStubSingleProduct([
		'product_id'              => 910003,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-910003',
		'internal_product_code'   => 'SKU-910003',
		'product_erp_description' => 'Delta Widget',
		'_product_status'         => ['product_status_description' => 'active'],
	]);

	// Delta requested with no checkpoint → runs as an initial full pass.
	$result = (new Skwirrel_WC_Sync_Service())->run_sync(true, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED);

	expect($result['success'])->toBeTrue();
	// last_sync is seeded only now that the run completed (not up-front).
	expect(get_option('skwirrel_wc_sync_last_sync'))->not->toBeEmpty();
});
