<?php

declare(strict_types=1);

/**
 * The Context ID: one resolve rule feeding every JSON-RPC call site, and the guarantee that an
 * install which never sets it keeps sending exactly the request it sends today.
 */

// The save hook also re-arms the scheduler; stub the Action Scheduler API it reaches for so the
// save path runs end to end without a live WooCommerce. Guarded, so a sibling test file that
// already declared these wins.
if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void {}
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): void {}
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, string $group = ''): bool
    {
        return true;
    }
}
if (!function_exists('as_next_scheduled_action')) {
    function as_next_scheduled_action(string $hook, array $args = [], string $group = '') {
        return false;
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, $args = []) {
        return false;
    }
}

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-jsonrpc-client.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-history.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-queue.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-lookup.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-brand-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-taxonomy-manager.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php';

/**
 * A client that records the params it is handed and never talks to the network. Every call fails,
 * which makes the caller log and return — enough to observe the request it would have sent.
 */
final class Skw_Recording_Rpc_Client extends Skwirrel_WC_Sync_JsonRpc_Client
{
    /** @var array<int, array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('https://example.test/jsonrpc', 'token', 'x');
    }

    public function call(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return ['success' => false, 'error' => ['code' => -1, 'message' => 'stub']];
    }

    /**
     * Params of the first recorded call to $method.
     *
     * @return array<string, mixed>
     */
    public function params_for(string $method): array
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                return $call['params'];
            }
        }

        return [];
    }
}

function skw_set_context_setting(?string $value): void
{
    $settings = [
        'image_language' => 'nl',
        'include_languages' => ['nl-NL', 'nl'],
        'use_sku_field' => 'internal_product_code',
    ];
    if (null !== $value) {
        $settings['context_id'] = $value;
    }
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = $settings;
}


/**
 * Drive the public entry point, which is where the run's frozen settings copy is read.
 */
function skw_sync_category_tree(Skw_Recording_Rpc_Client $client, array $options): void
{
    $sync = new Skwirrel_WC_Sync_Category_Sync(new Skwirrel_WC_Sync_Logger(), new Skwirrel_WC_Sync_Product_Mapper());
    $sync->sync_category_tree($client, $options + ['super_category_id' => 12], []);
}

/**
 * The sanitiser rejects an empty `collection_ids` unconditionally, so every input here carries a
 * valid one — this story's assertions are about the Context ID, not about that rule.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function skw_sanitize(array $input): array
{
    return Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings($input + ['collection_ids' => '123']);
}

function skw_make_upserter(): Skwirrel_WC_Sync_Product_Upserter
{
    $logger = new Skwirrel_WC_Sync_Logger();
    $mapper = new Skwirrel_WC_Sync_Product_Mapper();

    return new Skwirrel_WC_Sync_Product_Upserter(
        $logger,
        $mapper,
        new Skwirrel_WC_Sync_Product_Lookup($mapper),
        new Skwirrel_WC_Sync_Category_Sync($logger, $mapper),
        new Skwirrel_WC_Sync_Brand_Sync($logger),
        new Skwirrel_WC_Sync_Taxonomy_Manager($logger),
        new Skwirrel_WC_Sync_Slug_Resolver()
    );
}

beforeEach(function () {
    $GLOBALS['wp_settings_errors'] = [];
    $GLOBALS['_test_options'] = [];
});

afterEach(function () {
    $GLOBALS['wp_settings_errors'] = [];
    $GLOBALS['_test_options'] = [];
});

/*
 * ---------------------------------------------------------------------------
 * AC-5 / AC-3 — the resolve rule
 * ---------------------------------------------------------------------------
 */

test('a positive whole number resolves to a single-element context list', function (string $stored, int $expected) {
    skw_set_context_setting($stored);

    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBe([$expected]);
})->with([
    ['1', 1],
    ['2', 2],
    ['42', 42],
    ['  7  ', 7],
]);

test('an unset, empty or invalid Context ID resolves to null so call sites keep their current behaviour', function ($stored) {
    skw_set_context_setting($stored);

    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBeNull();
})->with([
    'never set' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
    'non-numeric' => ['abc'],
    'zero' => ['0'],
    'negative' => ['-3'],
    'decimal' => ['1.5'],
    'trailing text' => ['2 shops'],
    'larger than PHP can represent' => [(string) PHP_INT_MAX . '0'],
]);

test('get_context_ids survives a corrupt settings option', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = 'not-an-array';

    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * AC-5 — invalid input is reported, stored verbatim, and inert
 * ---------------------------------------------------------------------------
 */

test('an invalid Context ID raises a settings error and is stored exactly as typed', function (string $typed) {
    $out = skw_sanitize(['context_id' => $typed]);

    expect($out['context_id'])->toBe(trim($typed));
    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe(['context_id']);
    expect(Skwirrel_WC_Sync_Admin_Settings::has_settings_error())->toBeTrue();

    // Stored, but never resolved — so no call site ever sends it.
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = $out;
    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBeNull();
})->with([
    'non-numeric' => ['abc'],
    'zero' => ['0'],
    'negative' => ['-3'],
    'decimal' => ['1.5'],
    'larger than PHP can represent' => [(string) PHP_INT_MAX . '0'],
]);

test('an empty or valid Context ID raises no settings error', function ($typed, string $stored) {
    $out = skw_sanitize(['context_id' => $typed]);

    expect($out['context_id'])->toBe($stored);
    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe([]);
})->with([
    'empty' => ['', ''],
    'valid' => ['4', '4'],
    'padded' => [' 4 ', '4'],
]);

test('an omitted Context ID field stores an empty string and raises no error', function () {
    $out = skw_sanitize([]);

    expect($out['context_id'])->toBe('');
    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe([]);
});

test('the Context ID is optional — it is not in the required-field registry', function () {
    $required = Skwirrel_WC_Sync_Admin_Settings::required_fields([]);

    expect(array_key_exists('context_id', $required))->toBeFalse();
    expect(Skwirrel_WC_Sync_Admin_Settings::is_field_required([], 'context_id'))->toBeFalse();
});

/*
 * ---------------------------------------------------------------------------
 * AC-2 / AC-3 — what actually reaches the API
 * ---------------------------------------------------------------------------
 */

test('getCategories carries the configured context', function () {
    skw_set_context_setting('9');
    $client = new Skw_Recording_Rpc_Client();

    skw_sync_category_tree($client, ['context_id' => '9', 'image_language' => 'nl']);

    expect($client->params_for('getCategories')['include_contexts'])->toBe([9]);
});

test('getCategories keeps sending the default context when none is configured', function () {
    skw_set_context_setting(null);
    $client = new Skw_Recording_Rpc_Client();

    skw_sync_category_tree($client, ['image_language' => 'nl']);

    expect($client->params_for('getCategories')['include_contexts'])->toBe([1]);
});

test('the category tree follows the run\'s frozen context, not a mid-run change', function () {
    skw_set_context_setting('9');
    $client = new Skw_Recording_Rpc_Client();

    // The run started on context 9; an admin saves 4 before this queued step executes.
    skw_set_context_setting('4');
    skw_sync_category_tree($client, ['context_id' => '9', 'image_language' => 'nl']);

    // Products and their category IDs come from 9, so the tree must too.
    expect($client->params_for('getCategories')['include_contexts'])->toBe([9]);
});

test('a run with no context configured still asks for the default category context', function () {
    skw_set_context_setting('9');
    $client = new Skw_Recording_Rpc_Client();

    skw_sync_category_tree($client, ['context_id' => '', 'image_language' => 'nl']);

    expect($client->params_for('getCategories')['include_contexts'])->toBe([1]);
});

test('getGroupedProducts carries the configured context', function () {
    skw_set_context_setting('9');
    $client = new Skw_Recording_Rpc_Client();

    skw_make_upserter()->sync_grouped_products_first($client, ['batch_size' => 10]);

    expect($client->params_for('getGroupedProducts')['include_contexts'])->toBe([9]);
});

test('getGroupedProducts sends no context parameter at all when none is configured', function ($stored) {
    skw_set_context_setting($stored);
    $client = new Skw_Recording_Rpc_Client();

    skw_make_upserter()->sync_grouped_products_first($client, ['batch_size' => 10]);

    // The asymmetry is the point: this call site has never sent include_contexts, so an
    // unconfigured install must not start sending it.
    expect(array_key_exists('include_contexts', $client->params_for('getGroupedProducts')))->toBeFalse();
})->with([
    'never set' => [null],
    'empty' => [''],
    'invalid' => ['abc'],
]);

test('the selection membership sweep carries the configured context', function () {
    skw_set_context_setting('9');
    $client = new Skw_Recording_Rpc_Client();

    skw_make_upserter()->fetch_product_ids_page_for_selection($client, 12, 1);

    expect($client->params_for('getProductsByFilter')['options']['include_contexts'])->toBe([9]);
});

test('the selection membership sweep keeps its existing empty options when no context is configured', function ($stored) {
    skw_set_context_setting($stored);
    $client = new Skw_Recording_Rpc_Client();

    skw_make_upserter()->fetch_product_ids_page_for_selection($client, 12, 1);

    expect($client->params_for('getProductsByFilter')['options'])->toBe([]);
})->with([
    'never set' => [null],
    'empty' => [''],
    'invalid' => ['abc'],
]);

test('the connection test probes the configured context', function () {
    skw_set_context_setting('9');
    $client = new Skw_Recording_Rpc_Client();

    $client->test_connection(Skwirrel_WC_Sync_Admin_Settings::get_context_ids());

    expect($client->params_for('getProducts')['include_contexts'])->toBe([9]);
});

test('the connection test sends no context parameter when none is configured', function ($stored) {
    skw_set_context_setting($stored);
    $client = new Skw_Recording_Rpc_Client();

    $client->test_connection(Skwirrel_WC_Sync_Admin_Settings::get_context_ids());

    expect(array_key_exists('include_contexts', $client->params_for('getProducts')))->toBeFalse();
})->with([
    'never set' => [null],
    'empty' => [''],
    'invalid' => ['abc'],
]);

test('both Test Connection handlers hand a context to the probe, each from its own source', function () {
    // The handlers build a live HTTP client, so the wiring is pinned at the source level.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php'
    );

    // The legacy admin_post path tests what is saved, because that is all it has.
    expect(substr_count($source, 'test_connection( self::get_context_ids() )'))->toBe(1);
    // The AJAX path tests what is in the form: it autosaves the posted value first, then probes
    // with that, so editing the Context ID and pressing Test connection before Save is honest.
    expect(substr_count($source, 'test_connection( self::context_ids_from_options( $opts ) )'))->toBe(1);
    expect(preg_match('/->test_connection\(\s*\)/', $source))->toBe(0);
});

test('the AJAX test posts and validates the Context ID from the form', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php'
    );

    // The browser sends it...
    expect($source)->toContain('fd.append("context_id", ctxEl.value);');
    // ...the handler validates it before storing, using the one resolve rule...
    expect($source)->toContain("null === self::resolve_context_ids( \$context_in )");
    // ...and an absent field leaves the stored value alone, so a cached older script cannot
    // silently clear a configured context.
    expect($source)->toContain("if ( isset( \$_POST['context_id'] ) ) {");

    // The handler autosaves, so a bail-out after the token write would leave a new credential
    // stored against the old endpoint and context while reporting failure.
    $ajax = substr($source, (int) strpos($source, 'public function handle_test_connection_ajax'));
    $ajax = substr($ajax, 0, (int) strpos($ajax, "\n\tpublic function ", 1));
    expect(strpos($ajax, 'wp_send_json_error'))->toBeLessThan(strpos($ajax, 'update_option( self::TOKEN_OPTION_KEY'));
});

/*
 * ---------------------------------------------------------------------------
 * A run freezes the context it sweeps with
 * ---------------------------------------------------------------------------
 */

test('a run frozen to a context keeps sweeping that context after the live setting changes', function () {
    skw_set_context_setting('9');
    $upserter = skw_make_upserter();
    $upserter->set_run_options(['context_id' => '9']);

    // An administrator saves a different context mid-run.
    skw_set_context_setting('4');

    $client = new Skw_Recording_Rpc_Client();
    $upserter->fetch_product_ids_page_for_selection($client, 12, 1);

    // Membership from two contexts in one sweep would drive removals against a mixed set.
    expect($client->params_for('getProductsByFilter')['options']['include_contexts'])->toBe([9]);
});

test('an upserter with no frozen run falls back to the live context', function () {
    skw_set_context_setting('4');
    $client = new Skw_Recording_Rpc_Client();

    $upserter = skw_make_upserter();
    $upserter->set_run_options(null);
    $upserter->fetch_product_ids_page_for_selection($client, 12, 1);

    expect($client->params_for('getProductsByFilter')['options']['include_contexts'])->toBe([4]);
});

test('a frozen run with no context configured sweeps without the parameter', function () {
    skw_set_context_setting('9');
    $upserter = skw_make_upserter();
    $upserter->set_run_options(['context_id' => '']);

    $client = new Skw_Recording_Rpc_Client();
    $upserter->fetch_product_ids_page_for_selection($client, 12, 1);

    expect($client->params_for('getProductsByFilter')['options'])->toBe([]);
});

test('the grouped-product fetch follows the same frozen context', function () {
    skw_set_context_setting('4');
    $upserter = skw_make_upserter();
    $upserter->set_run_options(['context_id' => '9', 'batch_size' => 10]);

    $client = new Skw_Recording_Rpc_Client();
    $upserter->sync_grouped_products_first($client, ['batch_size' => 10]);

    expect($client->params_for('getGroupedProducts')['include_contexts'])->toBe([9]);
});

test('each resumable step re-applies the run context to the upserter', function () {
    // run_step() builds a fresh upserter every Action Scheduler step; the freeze has to be
    // re-applied there or the second half of a run drifts to the live option.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php'
    );

    expect($source)->toContain('$this->upserter->set_run_options( $ctx[\'options\'] );');
});

/**
 * Every call site reads the one helper, and none is left on a hardcoded literal.
 *
 * The four sites inside Sync_Service sit deep in a paginated run that the stub bootstrap cannot
 * drive, so they are pinned at the source level instead of left uncovered.
 */
test('no call site is left on a hardcoded context literal', function () {
    $includes = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes';
    $files = glob($includes . '/*.php') ?: [];

    $wired = 0;
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        expect(preg_match('/include_contexts\'\s*\]?\s*=>?\s*=?\s*\[\s*1\s*\]/', $source))
            ->toBe(0, basename($file) . ' still hardcodes the context');

        $wired += preg_match_all(
            '/include_contexts.{0,20}Skwirrel_WC_Sync_Admin_Settings::get_context_ids\(\) \?\? \[ 1 \]/',
            $source
        );
    }

    // One site left resolving inline: sync_single_product(), a one-shot admin re-sync of a single
    // item that issues exactly one context-bearing call, so there is nothing for it to disagree
    // with. Everything else reads the run's frozen settings copy (getCategories, begin_run) or
    // resolves once per operation and reuses it (sync_single_grouped_product, membership sweep,
    // grouped fetch).
    expect($wired)->toBe(1);
});

test('a rejected Context ID is rendered in a control that can actually show it', function () {
    // The sanitiser keeps "abc" verbatim so it can be seen and corrected. A type="number" input
    // applies its own value-sanitization algorithm and reports a non-numeric value as empty, which
    // would hand the administrator a blank box with an error beside it.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php'
    );

    $field = substr($source, (int) strpos($source, 'id="context_id"') - 200, 400);

    expect($field)->toContain('type="text"');
    expect($field)->toContain('inputmode="numeric"');
    expect($field)->not->toContain('type="number" id="context_id"');
    // No constraint validation either: a pattern would stop the browser submitting at all, so the
    // sanitiser never runs and the administrator gets a generic tooltip instead of the message
    // naming the context that stays in use.
    expect($field)->not->toContain('pattern=');
    expect($field)->not->toContain('required');
});

test('a single grouped re-sync resolves its context once and reuses it', function () {
    // Three kinds of call in one operation — the paginated group lookup, the member-product fetch
    // and the per-product attribute re-fetch. Re-reading the option before each would let a save
    // mid-operation find the group in one context and build its variations from another.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php'
    );

    // It is the last public method in the file, so fall back to the next method of any visibility
    // and then to end-of-file rather than slicing to a false offset.
    $start = (int) strpos($source, 'public function sync_single_grouped_product');
    $end   = PHP_INT_MAX;
    foreach ([ "\n\tpublic function ", "\n\tprivate function ", "\n\tprotected function " ] as $needle) {
        $at = strpos($source, $needle, $start + 1);
        if (false !== $at) {
            $end = min($end, $at);
        }
    }
    $method = PHP_INT_MAX === $end ? substr($source, $start) : substr($source, $start, $end - $start);

    expect(substr_count($method, 'Skwirrel_WC_Sync_Admin_Settings::get_context_ids()'))->toBe(1);
    expect(substr_count($method, '$context_ids ?? [ 1 ]'))->toBe(2);
});

/*
 * ---------------------------------------------------------------------------
 * A rejected value is visible, and genuinely inert
 * ---------------------------------------------------------------------------
 */

test('a rejected value never moves the context the plugin syncs with', function (string $typed) {
    // Configured and working on context 5.
    $stored = skw_sanitize(['context_id' => '5']);
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = $stored;
    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBe([5]);

    // An admin now types something invalid and saves.
    $out = skw_sanitize(['context_id' => $typed]);
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = $out;

    // Seen and correctable...
    expect($out['context_id'])->toBe(trim($typed));
    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe(['context_id']);

    // ...but the sync keeps reading context 5. Falling back to the default context here would
    // retarget the shop, and with stale purging on, trash the catalogue it stopped matching.
    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBe([5]);
})->with([
    'non-numeric' => ['abc'],
    'zero' => ['0'],
    'negative' => ['-3'],
    'decimal' => ['1.5'],
    'larger than PHP can represent' => [(string) PHP_INT_MAX . '0'],
]);

test('a rejected value arms no full sync, because nothing it controls changed', function () {
    $before = skw_sanitize(['context_id' => '5']);
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = $before;

    $after = skw_sanitize(['context_id' => '0']);
    Skwirrel_WC_Sync_Admin_Settings::instance()->on_settings_updated($before, $after);

    expect(array_key_exists('skwirrel_wc_sync_force_full_sync', $GLOBALS['_test_options']))->toBeFalse();
});

test('the message names the context that stays in use', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = skw_sanitize(['context_id' => '5']);
    $GLOBALS['wp_settings_errors'] = [];

    skw_sanitize(['context_id' => 'abc']);

    $messages = implode(' ', array_column(Skwirrel_WC_Sync_Admin_Settings::settings_errors_for_option(), 'message'));
    expect($messages)->toContain('keeps using context 5');
});

test('clearing the field is a valid choice, not a rejection', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = skw_sanitize(['context_id' => '5']);

    $out = skw_sanitize(['context_id' => '']);
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = $out;

    expect(Skwirrel_WC_Sync_Admin_Settings::failing_field_ids())->toBe([]);
    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBeNull();
});

test('an install saved before the effective key existed keeps its stored context', function () {
    // No context_id_effective at all — the value was written by an older version.
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['context_id' => '8'];

    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBe([8]);
});

test('a legacy install carrying an invalid stored value reads as the default context', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['context_id' => 'abc'];

    expect(Skwirrel_WC_Sync_Admin_Settings::get_context_ids())->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * AC-4 — a changed effective context forces a full sync
 * ---------------------------------------------------------------------------
 */

function skw_save_settings(?string $old, ?string $new): void
{
    $to_array = static fn (?string $v): array => null === $v ? [] : ['context_id' => $v];

    Skwirrel_WC_Sync_Admin_Settings::instance()->on_settings_updated($to_array($old), $to_array($new));
}

test('a changed effective context sets the force-full-sync flag and tells the admin', function (?string $old, ?string $new) {
    skw_save_settings($old, $new);

    expect($GLOBALS['_test_options']['skwirrel_wc_sync_force_full_sync'] ?? null)->toBeTrue();

    $codes = array_column(Skwirrel_WC_Sync_Admin_Settings::settings_errors_for_option(), 'code');
    expect($codes)->toContain('context_id_changed');

    // The notice explains what will happen; it is not an error the form should flag.
    expect(Skwirrel_WC_Sync_Admin_Settings::has_settings_error())->toBeFalse();
})->with([
    'empty to set' => ['', '5'],
    'unset to set' => [null, '5'],
    'set to empty' => ['5', ''],
    'set to unset' => ['5', null],
    'set to different' => ['5', '6'],
    'invalid to valid' => ['abc', '6'],
    'valid to invalid' => ['6', 'abc'],
]);

test('an unchanged effective context leaves the flag alone and shows nothing', function (?string $old, ?string $new) {
    skw_save_settings($old, $new);

    expect(array_key_exists('skwirrel_wc_sync_force_full_sync', $GLOBALS['_test_options']))->toBeFalse();
    expect(Skwirrel_WC_Sync_Admin_Settings::settings_errors_for_option())->toBe([]);
})->with([
    'plain re-save, unset' => [null, null],
    'plain re-save, empty' => ['', ''],
    'plain re-save, set' => ['5', '5'],
    'unset to empty' => [null, ''],
    'padding only' => ['5', ' 5 '],
    'invalid to a different invalid' => ['abc', 'xyz'],
    'invalid to empty' => ['abc', ''],
    'zero to negative' => ['0', '-1'],
]);

/*
 * ---------------------------------------------------------------------------
 * AC-6 — the strings ship translatable
 * ---------------------------------------------------------------------------
 */

test('the strings this story added are in the POT and in all seven locales', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';

    $strings = [
        'Context ID',
        'The context ID must be a whole number greater than 0. Synchronisation keeps using context %s until you correct this.',
        'The context ID must be a whole number greater than 0. Leave it empty to use the Skwirrel default context.',
        'The context ID changed, so the next synchronisation imports your whole catalogue again. This makes sure every product and category comes from the new context.',
    ];

    $catalogues = array_merge(
        [$languages . '/skwirrel-pim-sync.pot'],
        glob($languages . '/skwirrel-pim-sync-*.po') ?: []
    );

    expect($catalogues)->toHaveCount(8);

    foreach ($catalogues as $catalogue) {
        $content = (string) preg_replace('/"[ \t]*\R[ \t]*"/', '', (string) file_get_contents($catalogue));
        foreach ($strings as $string) {
            expect(str_contains($content, 'msgid "' . $string . '"'))
                ->toBeTrue(basename($catalogue) . ' is missing: ' . $string);
        }
    }
});
