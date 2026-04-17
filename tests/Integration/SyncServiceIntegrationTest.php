<?php
/**
 * Integration test for Skwirrel_WC_Sync_Service::run_sync().
 *
 * Exercises the full sync pipeline (fetch → products → taxonomy → attributes →
 * media → cleanup) against a real WordPress + WooCommerce stack, with the
 * JSON-RPC endpoint stubbed via the `pre_http_request` filter.
 *
 * This is the template for further sync-related integration tests: add
 * scenarios (delta sync, variable product, API error, etc.) by varying the
 * stubbed API responses in the `$api_responses` map.
 */

declare(strict_types=1);

beforeEach(function () {
	delete_option('skwirrel_wc_sync_settings');
	delete_option('skwirrel_wc_sync_auth_token');
	delete_option('skwirrel_wc_sync_last_sync');
	delete_option('skwirrel_wc_sync_last_result');
	delete_option('skwirrel_wc_sync_history');

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

	// Track recorded API calls so assertions can verify the sync hit the expected methods.
	$GLOBALS['__test_api_calls'] = [];
});

afterEach(function () {
	remove_all_filters('pre_http_request');
	unset($GLOBALS['__test_api_responses'], $GLOBALS['__test_api_calls']);
});

/**
 * Install a JSON-RPC endpoint stub.
 *
 * @param array<string, callable|array> $responses Map of JSON-RPC method name
 *     to either an array (returned verbatim as the `result` field) or a
 *     callable(params): array that returns the `result` field.
 */
function stubSkwirrelApi(array $responses): void {
	$GLOBALS['__test_api_responses'] = $responses;

	add_filter('pre_http_request', function ($pre, $args, $url) {
		if (strpos($url, 'test.skwirrel.example') === false) {
			return $pre;
		}

		$body   = json_decode((string) ($args['body'] ?? ''), true);
		$method = $body['method'] ?? '';
		$params = $body['params'] ?? [];
		$id     = $body['id'] ?? 1;

		$GLOBALS['__test_api_calls'][] = ['method' => $method, 'params' => $params];

		$responses = $GLOBALS['__test_api_responses'] ?? [];
		$handler   = $responses[$method] ?? null;
		$result    = is_callable($handler) ? $handler($params) : ($handler ?? []);

		return [
			'headers'  => [],
			'body'     => wp_json_encode([
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			]),
			'response' => ['code' => 200, 'message' => 'OK'],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3);
}

test('run_sync creates a new WooCommerce product from a Skwirrel API response', function () {
	$skwirrel_product = [
		'product_id'              => 900001,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-900001',
		'internal_product_code'   => 'SKU-900001',
		'product_erp_description' => 'Test Widget 900001',
		'_product_status'         => [
			'product_status_description' => 'active',
		],
	];

	// getProductsByFilter returns different payloads per page:
	// - page 1 with dynamic_selection_id filter → the product
	// - page 2 → empty (ends pagination)
	// - per-product refetch for attributes → minimal payload
	$fetch_call_count = 0;
	stubSkwirrelApi([
		'getBrands'            => ['brands' => []],
		'getProductsByFilter'  => function ($params) use (&$fetch_call_count, $skwirrel_product) {
			$is_attr_fetch = isset($params['filter']['code']['type'])
				&& $params['filter']['code']['type'] === 'product_id';

			if ($is_attr_fetch) {
				return ['products' => [$skwirrel_product]];
			}

			++$fetch_call_count;
			if ($fetch_call_count === 1) {
				return ['products' => [$skwirrel_product]];
			}
			return ['products' => []];
		},
	]);

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);

	expect($result['success'])->toBeTrue();
	expect($result['created'])->toBe(1);
	expect($result['updated'])->toBe(0);
	expect($result['failed'])->toBe(0);

	// Verify the product actually landed in WooCommerce.
	$matches = wc_get_products([
		'sku'    => 'SKU-900001',
		'limit'  => 1,
		'status' => ['publish', 'draft'],
	]);
	expect($matches)->toHaveCount(1);

	$wc_product = $matches[0];
	expect($wc_product->get_name())->toBe('Test Widget 900001');
	expect($wc_product->get_sku())->toBe('SKU-900001');
	expect(get_post_meta($wc_product->get_id(), '_skwirrel_product_id', true))->toBe('900001');
	// Note: the upserter stores the prefixed unique key (see Product_Mapper::get_unique_key).
	expect(get_post_meta($wc_product->get_id(), '_skwirrel_external_id', true))->toBe('ext:EXT-900001');
});

test('run_sync updates an existing product matched by external_id instead of creating a duplicate', function () {
	// Seed: a WC product already exists with the Skwirrel external id.
	$existing = new WC_Product_Simple();
	$existing->set_name('Old Name');
	$existing->set_sku('SKU-900002');
	$existing->set_status('publish');
	$existing_id = $existing->save();
	update_post_meta($existing_id, '_skwirrel_external_id', 'EXT-900002');
	update_post_meta($existing_id, '_skwirrel_product_id', '900002');

	$skwirrel_product = [
		'product_id'              => 900002,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-900002',
		'internal_product_code'   => 'SKU-900002',
		'product_erp_description' => 'Updated Name',
		'_product_status'         => ['product_status_description' => 'active'],
	];

	$fetch_call_count = 0;
	stubSkwirrelApi([
		'getBrands'           => ['brands' => []],
		'getProductsByFilter' => function ($params) use (&$fetch_call_count, $skwirrel_product) {
			$is_attr_fetch = isset($params['filter']['code']['type'])
				&& $params['filter']['code']['type'] === 'product_id';
			if ($is_attr_fetch) {
				return ['products' => [$skwirrel_product]];
			}
			++$fetch_call_count;
			return $fetch_call_count === 1
				? ['products' => [$skwirrel_product]]
				: ['products' => []];
		},
	]);

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);

	expect($result['success'])->toBeTrue();
	expect($result['created'])->toBe(0);
	expect($result['updated'])->toBe(1);

	// Same WC id, updated name — no duplicate created.
	$refreshed = wc_get_product($existing_id);
	expect($refreshed)->not->toBeFalse();
	expect($refreshed->get_name())->toBe('Updated Name');

	$all_with_sku = wc_get_products([
		'sku'    => 'SKU-900002',
		'limit'  => 10,
		'status' => ['publish', 'draft'],
	]);
	expect($all_with_sku)->toHaveCount(1);
});

test('run_sync aborts cleanly when no collection_ids are configured', function () {
	update_option('skwirrel_wc_sync_settings', array_merge(
		(array) get_option('skwirrel_wc_sync_settings'),
		['collection_ids' => '']
	));

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);

	expect($result['success'])->toBeFalse();
	expect($result['error'])->toContain('selection');
	expect($result['created'])->toBe(0);
});

test('run_sync propagates API errors and records a failed sync', function () {
	add_filter('pre_http_request', function ($pre, $args, $url) {
		if (strpos($url, 'test.skwirrel.example') === false) {
			return $pre;
		}
		return [
			'headers'  => [],
			'body'     => wp_json_encode([
				'jsonrpc' => '2.0',
				'id'      => 1,
				'error'   => ['code' => -32000, 'message' => 'Simulated API failure'],
			]),
			'response' => ['code' => 200, 'message' => 'OK'],
			'cookies'  => [],
			'filename' => null,
		];
	}, 10, 3);

	$service = new Skwirrel_WC_Sync_Service();
	$result  = $service->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);

	expect($result['success'])->toBeFalse();
	expect($result['created'])->toBe(0);
	expect($result['updated'])->toBe(0);
});
