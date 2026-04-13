<?php
/**
 * Integration test for Skwirrel_WC_Sync_Product_Lookup.
 *
 * Exercises the real $wpdb queries against a real WordPress + WooCommerce
 * database, which is impossible to test honestly with stubs. Specifically
 * verifies the batch lookup query in find_wc_ids_by_skwirrel_ids() which
 * uses dynamic placeholder interpolation (the one with the
 * phpcs:disable for InterpolatedNotPrepared).
 */

declare(strict_types=1);

beforeEach(function () {
	$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
	$this->lookup = new Skwirrel_WC_Sync_Product_Lookup($this->mapper);
});

/**
 * Helper: create a real WC simple product with a Skwirrel ID meta.
 */
function createSkwirrelProduct(string $sku, int $skwirrel_product_id, ?string $external_id = null): int {
	$product = new WC_Product_Simple();
	$product->set_name('Test product ' . $sku);
	$product->set_sku($sku);
	$product->set_status('publish');
	$product_id = $product->save();

	update_post_meta($product_id, '_skwirrel_product_id', (string) $skwirrel_product_id);
	if ($external_id !== null) {
		update_post_meta($product_id, '_skwirrel_external_id', $external_id);
	}

	return (int) $product_id;
}

test('find_wc_ids_by_skwirrel_ids returns empty array for empty input', function () {
	$result = $this->lookup->find_wc_ids_by_skwirrel_ids([]);
	expect($result)->toBe([]);
});

test('find_wc_ids_by_skwirrel_ids maps a single id correctly', function () {
	$wc_id = createSkwirrelProduct('SKU-001', 1001);

	$result = $this->lookup->find_wc_ids_by_skwirrel_ids([1001]);

	expect($result)->toBeArray();
	expect($result)->toHaveKey(1001);
	expect($result[1001])->toBe($wc_id);
});

test('find_wc_ids_by_skwirrel_ids batches multiple ids in one query', function () {
	$id_a = createSkwirrelProduct('SKU-A', 2001);
	$id_b = createSkwirrelProduct('SKU-B', 2002);
	$id_c = createSkwirrelProduct('SKU-C', 2003);

	$result = $this->lookup->find_wc_ids_by_skwirrel_ids([2001, 2002, 2003]);

	expect($result)->toHaveCount(3);
	expect($result[2001])->toBe($id_a);
	expect($result[2002])->toBe($id_b);
	expect($result[2003])->toBe($id_c);
});

test('find_wc_ids_by_skwirrel_ids skips trashed products', function () {
	$id_live    = createSkwirrelProduct('SKU-LIVE', 3001);
	$id_trashed = createSkwirrelProduct('SKU-TRASHED', 3002);

	wp_trash_post($id_trashed);

	$result = $this->lookup->find_wc_ids_by_skwirrel_ids([3001, 3002]);

	expect($result)->toHaveKey(3001);
	expect($result)->not->toHaveKey(3002);
});

test('find_wc_ids_by_skwirrel_ids returns empty for ids that do not exist', function () {
	createSkwirrelProduct('SKU-EXISTS', 4001);

	$result = $this->lookup->find_wc_ids_by_skwirrel_ids([9991, 9992, 9993]);

	expect($result)->toBe([]);
});

test('find_wc_ids_by_skwirrel_ids only returns products and variations, not other post types', function () {
	$wc_id = createSkwirrelProduct('SKU-PROD', 5001);

	// Create a regular post with the same meta key/value — should be ignored.
	$post_id = wp_insert_post([
		'post_title'  => 'Random post',
		'post_status' => 'publish',
		'post_type'   => 'post',
	]);
	update_post_meta($post_id, '_skwirrel_product_id', '5002');

	$result = $this->lookup->find_wc_ids_by_skwirrel_ids([5001, 5002]);

	expect($result)->toHaveKey(5001);
	expect($result[5001])->toBe($wc_id);
	expect($result)->not->toHaveKey(5002);
});

test('find_wc_ids_by_skwirrel_ids handles a large batch (>100 ids)', function () {
	$expected = [];
	for ($i = 0; $i < 120; $i++) {
		$skwirrel_id          = 6000 + $i;
		$wc_id                = createSkwirrelProduct('SKU-BATCH-' . $i, $skwirrel_id);
		$expected[$skwirrel_id] = $wc_id;
	}

	$result = $this->lookup->find_wc_ids_by_skwirrel_ids(array_keys($expected));

	expect($result)->toHaveCount(120);
	foreach ($expected as $skwirrel_id => $wc_id) {
		expect($result[$skwirrel_id])->toBe($wc_id);
	}
});
