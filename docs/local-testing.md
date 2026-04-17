# Local testing

Guide for running and writing tests for the Skwirrel PIM sync plugin. For setup (Composer, Node, wp-env), see [`local-development.md`](./local-development.md).

## Two suites, two speeds

| | Unit (`tests/Unit`) | Integration (`tests/Integration`) |
|---|---|---|
| Runs against | Stub WP/WC functions in `tests/bootstrap.php` | Real WordPress + WooCommerce inside wp-env (Docker) |
| Speed | Seconds | Tens of seconds, plus one-time boot |
| Requires Docker? | No | Yes |
| What it covers | Pure PHP logic — mappers, extractors, parsers | `$wpdb` queries, WC data stores, term/post APIs, rewrite rules, full `run_sync()` |
| Runner | `vendor/bin/pest` locally | `pest` inside the wp-env `tests-cli` container |
| Config | `phpunit.xml.dist` | `phpunit-integration.xml.dist` |
| Bootstrap | `tests/bootstrap.php` (stubs) | `tests/Integration/bootstrap.php` (loads real WP + WC) |

Run unit tests constantly while coding. Run integration tests before pushing changes that touch the database, taxonomy, or sync flow.

## Unit tests — fast, no Docker

From the repo root:

```bash
# Everything
vendor/bin/pest

# Just the Unit suite (same as default)
vendor/bin/pest --testsuite=Unit

# Single file
vendor/bin/pest tests/Unit/ProductMapperCategoryTest.php

# Match by name/description
vendor/bin/pest --filter Mapper

# With coverage (requires Xdebug or PCOV)
vendor/bin/pest --coverage
```

Unit tests use a stub bootstrap (`tests/bootstrap.php`) that defines just enough WP/WC surface (`__()`, `esc_html()`, `ABSPATH`, etc.) for the plugin classes to load without a real WordPress install. They run in seconds.

### Writing a unit test

- One file per class: `tests/Unit/{ClassName}Test.php`
- Pest function-style — no PHPUnit classes
- Use `beforeEach()` for shared setup and `expect()` for assertions

```php
<?php
declare(strict_types=1);

beforeEach(function () {
    $this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

test('get_categories extracts from _categories array', function () {
    $product = [
        'product_id' => 1,
        '_categories' => [
            ['category_id' => 10, 'category_name' => 'Screws'],
        ],
    ];

    $result = $this->mapper->get_categories($product);

    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('Screws');
});
```

If you call a WP/WC function that isn't stubbed yet, add a stub in `tests/bootstrap.php` — keep it minimal and only enough for the test to run. If you find yourself stubbing a large surface, that's a signal the scenario belongs in the integration suite instead.

### What to unit-test

Pure-PHP logic where real WP/WC would be noise:

- `Product_Mapper` — field extraction, fallback chains, ETIM parsing, category mapping
- `Etim_Extractor` / `Custom_Class_Extractor` — feature value normalisation
- `JsonRpc_Client` — request building, response parsing, error handling
- `Slug_Resolver` — slug generation from configured fields
- `Purge_Handler` — stale-detection predicates (the pure ones)

## Integration tests — real WP + WC in Docker

Integration tests boot a real WordPress + WooCommerce + MySQL stack via `@wordpress/env`. Each test runs inside a `WP_UnitTestCase` transaction that's rolled back at teardown — no state leaks.

### One-time setup

```bash
# 1. Install JS deps
npm install

# 2. Boot the stack (first run pulls images, ~2 min)
npm run env:start

# 3. Install composer deps *inside* the tests container
#    (wp-phpunit must live in the container's filesystem)
npm run composer:install
```

### Running

```bash
# Unit suite inside the container (same thing as local, just containerised)
npm run test:unit

# Integration suite — requires wp-env running
npm run test:integration

# Both, sequentially
npm run test:all
```

Equivalent direct invocation (no npm wrapper):

```bash
wp-env run tests-cli --env-cwd=wp-content/plugins/skwirrel-pim-sync \
    vendor/bin/pest -c phpunit-integration.xml.dist
```

### Lifecycle commands

```bash
npm run env:stop      # stop containers (DB preserved)
npm run env:clean     # drop both DBs (dev + tests)
npm run env:destroy   # nuke volumes entirely
npm run env:logs      # tail container logs
npm run env:cli ...   # WP-CLI inside the container
```

### Writing an integration test

1. Create `tests/Integration/{Something}Test.php`
2. The Pest binding in `tests/Pest.php` auto-extends every test in `tests/Integration/` from `WP_UnitTestCase`, so you get:
   - `$this->factory->post->create()` and friends
   - WC data stores: `new WC_Product_Simple()`, `->save()`, `wc_get_product()`
   - `update_post_meta()` / `get_post_meta()` against the real DB
   - HTTP mocking via the `pre_http_request` filter
3. Use real fixtures, not stubs. The whole point of this directory is honesty.

```php
<?php
declare(strict_types=1);

beforeEach(function () {
    $this->lookup = new Skwirrel_WC_Sync_Product_Lookup(
        new Skwirrel_WC_Sync_Product_Mapper()
    );
});

test('find_wc_ids_by_skwirrel_ids returns matching products', function () {
    $product = new WC_Product_Simple();
    $product->set_sku('TEST-001');
    $id = $product->save();

    update_post_meta($id, '_skwirrel_product_id', '42');

    $result = $this->lookup->find_wc_ids_by_skwirrel_ids([42]);

    expect($result)->toBe([42 => $id]);
});
```

### What to integration-test

Anything that would require stubbing more than a line or two of WP/WC:

- `Product_Lookup` and `Purge_Handler` DB queries
- `Product_Upserter` — creating/updating real `WC_Product_Simple` / `WC_Product_Variation`
- `Category_Sync` / `Brand_Sync` — term creation, parent-child hierarchy, taxonomy assignment
- `Variation_Permalinks` — rewrite rules
- `Action_Scheduler` scheduling
- End-to-end `Sync_Service::run_sync()` with `pre_http_request` mocking the JSON-RPC endpoint

## Static analysis & code style

Not tests, but part of the same pre-commit gate:

```bash
vendor/bin/phpstan analyse       # level 6, config: phpstan.neon.dist, baseline: phpstan-baseline.neon
vendor/bin/phpcs                 # WordPress standards, config: .phpcs.xml.dist
vendor/bin/phpcbf                # auto-fix code style issues
```

When PHPStan finds new issues, fix them — don't add to the baseline unless there's a concrete reason.

## Pre-commit gate

All three must pass. CI re-runs them on push to `main` and on pull requests (`.github/workflows/ci.yml`).

```bash
vendor/bin/pest              # Unit tests
vendor/bin/phpstan analyse   # Static analysis
vendor/bin/phpcs             # Code style
```

Integration tests are **not** in the default CI run today — they're local-only and should be run before pushing changes that touch DB, taxonomy, rewrites, or the sync pipeline.

## Debugging

- Container logs: `npm run env:logs`
- WP-CLI in the container: `npm run env:cli plugin list`
- Plugin sync logs land in WooCommerce → Status → Logs, source `skwirrel-pim-sync`
- Xdebug is **not** enabled by default. To turn it on, add `"xdebug"` to `.wp-env.json`'s `xdebugMode` (see the [wp-env docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/#xdebug)).
- Corrupted test DB: `npm run env:clean` drops both DBs; `npm run env:destroy && npm run env:start` nukes everything and starts fresh.
