# Integration tests

These tests run against a **real** WordPress + WooCommerce + MySQL stack
provisioned by [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env)
(Docker). They exist to cover the parts of the plugin that are impossible to
test honestly with the stub bootstrap in `tests/Unit`:

- direct `$wpdb` queries (`Product_Lookup`, `Purge_Handler`)
- WC product/variation persistence and meta storage
- term creation and taxonomy assignment
- HTTP loopback / Action Scheduler scheduling
- rewrite rules (variation permalinks)
- the full `Sync_Service::run_sync()` flow with a stubbed JSON-RPC endpoint

### Test isolation — read this before adding a test

There is **no transactional rollback**, despite what this file used to claim. `WP_UnitTestCase`
cannot be bound under Pest 3: wp-env's WordPress test framework calls
`PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit removed in version 10. Binding it
fails at `abstract-testcase.php:592`.

Isolation is provided instead by `Skwirrel_Integration_TestCase` (bound via `uses()` in
`bootstrap.php`), which after **every** test deletes:

- every product and variation, plus any post carrying `_skwirrel_*` meta — by wildcard
- every term carrying `_skwirrel_*` term meta
- every `skwirrel_*` option and transient

and once at the **start of every run**, so a database left dirty by an interrupted run heals itself
rather than failing the next one across unrelated files.

Consequences for anything you add here:

- You do **not** need to clean up after your test. The per-file `beforeEach` option deletions that
  predate this are redundant; they are harmless and are being left alone for now.
- Do **not** rely on state surviving between tests — there is no `beforeAll` fixture pattern in this
  suite, and anything you create is gone by the next test.
- If you add a new `skwirrel_*` option or `_skwirrel_*` meta key, cleanup is automatic. The purges
  match by wildcard specifically so nobody has to remember to extend a list.

Registration is deliberately in `bootstrap.php`, not `tests/Pest.php`. Pest evaluates `Pest.php`
before the WordPress test framework is loaded, and its `uses()` / `afterEach()` registrations do not
apply to this suite at all — silently. A base class bound via `uses( ... )->in( __DIR__ )` from the
bootstrap does apply.

## One-time setup

Make sure Docker and Node are installed, then from the plugin root:

```bash
# 1. Install JS deps (just @wordpress/env).
npm install

# 2. Boot the WordPress + WooCommerce stack (first run pulls images, ~2 min).
npm run env:start

# 3. Install composer deps inside the tests container.
#    (Required because wp-phpunit/wp-phpunit must be installed in the
#    container's filesystem so the bootstrap can find it.)
npm run composer:install
```

## Running tests

```bash
# Just unit tests (fast, no Docker — current default).
npm run test:unit

# Just integration tests (requires wp-env running).
npm run test:integration

# Both, sequentially.
npm run test:all
```

Equivalent direct commands (no npm wrapper):

```bash
# Unit
vendor/bin/pest --testsuite=Unit

# Integration (must run inside the wp-env tests container)
wp-env run tests-cli --env-cwd=wp-content/plugins/skwirrel-pim-sync \
    vendor/bin/pest -c phpunit-integration.xml.dist
```

## Writing a new integration test

1. Create the file under `tests/Integration/` ending in `Test.php`.
2. The Pest binding in `tests/Pest.php` automatically extends every test in
   this directory from `WP_UnitTestCase`, so you have access to:
   - `$this->factory->post->create()` and friends
   - WC's data stores: `new WC_Product_Simple()`, `->save()`, `wc_get_product()`
   - `update_post_meta()` / `get_post_meta()` against the real DB
   - HTTP mocking via the `pre_http_request` filter
3. Use real fixtures, not stubs. The whole point of this directory is honesty.

### Example skeleton

```php
<?php
declare(strict_types=1);

beforeEach(function () {
    $this->lookup = new Skwirrel_WC_Sync_Product_Lookup(
        new Skwirrel_WC_Sync_Product_Mapper()
    );
});

test('something with real WC products', function () {
    $product = new WC_Product_Simple();
    $product->set_sku('TEST-001');
    $id = $product->save();

    update_post_meta($id, '_skwirrel_product_id', '42');

    $result = $this->lookup->find_wc_ids_by_skwirrel_ids([42]);

    expect($result)->toBe([42 => $id]);
});
```

## Debugging tips

- View container logs: `npm run env:logs`
- WP-CLI inside the container: `npm run env:cli plugin list`
- xdebug is **not** installed by default — see the
  [`xdebugMode` option](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/#xdebug)
  in `.wp-env.json` if you need step debugging.
- If schema gets corrupted: `npm run env:clean` (drops both DBs).
- Full reset: `npm run env:destroy && npm run env:start`.

## CI

For GitHub Actions, add a workflow step:

```yaml
- name: Start wp-env
  run: npm install && npm run env:start

- name: Run integration tests
  run: npm run test:integration

- name: Stop wp-env
  if: always()
  run: npm run env:stop
```

GitHub-hosted runners already have Docker, so no additional setup is needed.
