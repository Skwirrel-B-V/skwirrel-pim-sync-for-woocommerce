<?php

declare(strict_types=1);

/**
 * What the connection test reports back.
 *
 * Every decision the two test paths make lives in one pure formatter, so this file is the whole
 * behavioural contract: which tone a result gets, how an unknown or zero product total reads, and
 * that a retried request says so instead of looking like one slow call.
 */

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-jsonrpc-client.php';

/**
 * @param array<string, int> $overrides
 * @return array{duration_ms: int, http_code: int, attempts: int}
 */
function skw_test_meta(array $overrides = []): array
{
    return array_merge(
        ['duration_ms' => 142, 'http_code' => 200, 'attempts' => 1],
        $overrides
    );
}

/**
 * @param array<int, string> $details
 */
function skw_details_string(array $details): string
{
    return implode(' | ', $details);
}

test('a successful test reports timing, status and the API-reported total', function () {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(), 1234, true);

    expect($out['tone'])->toBe('success');
    expect($out['message'])->toBe('Connection successful.');

    $details = skw_details_string($out['details']);
    expect($details)->toContain('142 ms');
    expect($details)->toContain('HTTP 200');
    expect($details)->toContain('JSON-RPC OK');
    expect($details)->toContain('1,234');
});

test('a zero total is a warning, not a success', function () {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(), 0, true);

    expect($out['tone'])->toBe('warning');
    expect($out['message'])->toContain('no products');
    expect(skw_details_string($out['details']))->toContain('Products: 0');
});

test('an unknown total is reported as unavailable and never fabricated', function () {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(), null, true);

    // The test call asks for a single product, so "1" here would be the count of the returned
    // array rather than the catalogue total — the exact wrong number this guards against.
    expect($out['tone'])->toBe('success');
    expect(skw_details_string($out['details']))->toContain('Products: unavailable');
    expect(skw_details_string($out['details']))->not->toContain('Products: 1');
});

test('a transport failure says there was no response instead of showing status 0', function () {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
        skw_test_meta(['http_code' => 0, 'duration_ms' => 30000]),
        null,
        false,
        'cURL error 28: Operation timed out'
    );

    expect($out['tone'])->toBe('error');
    expect($out['message'])->toBe('cURL error 28: Operation timed out');

    $details = skw_details_string($out['details']);
    expect($details)->toContain('no response (transport error)');
    expect($details)->toContain('30,000 ms');
    expect($details)->not->toContain('HTTP 0');
    expect($details)->not->toContain('Status: 0');
});

test('an HTTP error reports the status alongside the message', function () {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
        skw_test_meta(['http_code' => 500]),
        null,
        false,
        'Internal Server Error'
    );

    expect($out['tone'])->toBe('error');
    expect(skw_details_string($out['details']))->toContain('HTTP 500');
});

test('a JSON-RPC rejection is distinguishable from a transport failure', function () {
	$out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
		skw_test_meta(['http_code' => 200, 'jsonrpc_code' => -32000]),
        null,
        false,
        'Invalid API token'
    );

    expect($out['tone'])->toBe('error');
    expect($out['message'])->toBe('Invalid API token');

    $details = skw_details_string($out['details']);
	expect($details)->toContain('HTTP 200');
	expect($details)->toContain('JSON-RPC error -32,000');
    // No pagination block exists on a failure, so an "unavailable" product line would be noise.
    expect($details)->not->toContain('Products');
});

test('a failure with no message still gets a headline', function () {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(['http_code' => 0]), null, false, '   ');

    expect($out['message'])->toBe('Connection failed.');
});

test('retries are reported so a slow result is not mistaken for one slow request', function () {
    $retried = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
        skw_test_meta(['duration_ms' => 31500, 'http_code' => 0, 'attempts' => 3]),
        null,
        false,
        'Operation timed out'
    );
    expect(skw_details_string($retried['details']))->toContain('Attempts: 3');

    $single = Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(), 5, true);
    expect(skw_details_string($single['details']))->not->toContain('Attempts');
});

test('missing meta keys degrade to safe defaults rather than warnings', function () {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result([], null, true);

    expect($out['tone'])->toBe('success');
    $details = skw_details_string($out['details']);
    expect($details)->toContain('0 ms');
    expect($details)->toContain('no response (transport error)');
    expect($details)->not->toContain('Attempts');
});

test('the tone values are stable machine constants, not translated copy', function () {
    $tones = [
        Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(), 7, true)['tone'],
        Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(), 0, true)['tone'],
        Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(), null, false, 'nope')['tone'],
    ];

    expect($tones)->toBe(['success', 'warning', 'error']);
});

test('the formatter cannot leak the auth token because it never receives one', function () {
    $token = 'skw_live_SECRETTOKEN123';

    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
        skw_test_meta(['http_code' => 401]),
        null,
        false,
        'Unauthorized'
    );

    $rendered = $out['message'] . ' ' . skw_details_string($out['details']);
    expect($rendered)->not->toContain($token);
    expect($rendered)->not->toContain('SECRETTOKEN');

    // The signature is the guarantee: measurement, a count, a flag and a message — no credentials,
    // no request headers. Anything the API says still renders verbatim.
    $params = (new ReflectionMethod(Skwirrel_WC_Sync_Admin_Settings::class, 'format_test_result'))->getParameters();
    expect(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $params))
        ->toBe(['meta', 'product_count', 'success', 'error_message']);
});

test('the shared payload pipeline redacts a credential reflected by the API', function () {
    $token  = 'skw_live_SECRETTOKEN123';
    $method = new ReflectionMethod(Skwirrel_WC_Sync_Admin_Settings::class, 'prepare_test_result_payload');

    $out = $method->invoke(
        null,
        [
            'success' => false,
            'error' => ['code' => -32000, 'message' => 'Rejected token ' . $token],
            'meta' => ['duration_ms' => 80, 'http_code' => 200, 'attempts' => 1],
            'product_count' => null,
        ],
        $token
    );

    expect($out['message'])->toBe('Connection failed.');
    expect($out['message'] . ' ' . skw_details_string($out['details']))->not->toContain($token);
    expect(skw_details_string($out['details']))->toContain('JSON-RPC error -32,000');
});

/**
 * A client whose transport is replaced by a canned JSON-RPC result, so the pagination read in
 * test_connection() can be exercised without an HTTP layer.
 */
final class Skw_Canned_Rpc_Client extends Skwirrel_WC_Sync_JsonRpc_Client
{
    /** @var array<string, mixed> */
    private array $canned;

    /** @var array<int, array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $canned
     */
    public function __construct(array $canned)
    {
        parent::__construct('https://example.test/jsonrpc', 'token', 'secret-token');
        $this->canned = $canned;
    }

    public function call(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return $this->canned;
    }
}

test('the product total comes from the pagination block, not the returned array', function () {
    $client = new Skw_Canned_Rpc_Client([
        'success' => true,
        'result' => [
            'products' => [['product_id' => 1]],
            'page' => ['number_of_items' => 4821, 'items_per_page' => 1],
        ],
        'meta' => ['duration_ms' => 90, 'http_code' => 200, 'attempts' => 1],
    ]);

    $result = $client->test_connection();

    expect($result['product_count'])->toBe(4821);
    // The call still asks for a single product; the count must not follow that limit.
    expect($client->calls[0]['params']['limit'])->toBe(1);
});

test('a missing or non-numeric pagination total resolves to unknown', function (mixed $page) {
    $result = (new Skw_Canned_Rpc_Client([
        'success' => true,
        'result' => ['products' => [['product_id' => 1]], 'page' => $page],
    ]))->test_connection();

    expect($result['product_count'])->toBeNull();
})->with([
    'no pagination block' => [null],
    'empty pagination block' => [[]],
    'non-numeric total' => [['number_of_items' => 'many']],
]);

test('a failed call reports no product total at all', function () {
    $result = (new Skw_Canned_Rpc_Client([
        'success' => false,
        'error' => ['code' => -32000, 'message' => 'Invalid API token'],
        'meta' => ['duration_ms' => 60, 'http_code' => 200, 'attempts' => 1],
    ]))->test_connection();

    expect($result['product_count'])->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * Formatter boundaries (added by the QA E2E-test pass)
 *
 * The cases above pin the happy shapes. These pin the edges where the wording
 * flips, and the order the aria-live region announces the metrics in.
 * ---------------------------------------------------------------------------
 */

test('the status wording flips to a bare HTTP code exactly at 400', function (int $code, string $expected, string $forbidden) {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
        skw_test_meta(['http_code' => $code]),
        null,
        false,
        'Rejected'
    );

    $details = skw_details_string($out['details']);
    expect($details)->toContain($expected);
    expect($details)->not->toContain($forbidden);
})->with([
    // 399 is still a response the JSON-RPC layer answered, so the rejection is named as one.
    'just below the boundary' => [399, 'HTTP 399 · JSON-RPC error', 'HTTP 399 ·  '],
    'at the boundary'         => [400, 'HTTP 400', 'JSON-RPC'],
    'above the boundary'      => [503, 'HTTP 503', 'JSON-RPC'],
]);

test('a successful call below 400 reads as JSON-RPC OK', function () {
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(['http_code' => 200]), 3, true);

    expect(skw_details_string($out['details']))->toContain('HTTP 200 · JSON-RPC OK');
});

test('nonsensical measurement is clamped rather than rendered', function () {
    // A negative elapsed time or a zero attempt count can only come from a caller bug, but it must
    // never reach the screen as "-5 ms" or "Attempts: 0".
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
        ['duration_ms' => -5, 'http_code' => 200, 'attempts' => 0],
        7,
        true
    );

    $details = skw_details_string($out['details']);
    expect($details)->toContain('Round-trip: 0 ms');
    expect($details)->not->toContain('-5');
    expect($details)->not->toContain('Attempts');
});

test('the metrics are announced round-trip first, then status, then products', function () {
    // The live region reads the details in array order, so the order is part of the contract.
    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
        skw_test_meta(['attempts' => 2]),
        12,
        true
    );

    expect($out['details'])->toHaveCount(4);
    expect($out['details'][0])->toStartWith('Round-trip:');
    expect($out['details'][1])->toStartWith('Status:');
    expect($out['details'][2])->toStartWith('Products:');
    expect($out['details'][3])->toStartWith('Attempts:');
});

test('an API-supplied error message is passed through verbatim, markup and all', function () {
    // The formatter must not sanitise: escaping is the renderer's job (esc_html server-side,
    // textContent client-side). Silently stripping here would hide that a renderer stopped doing it.
    $hostile = '<img src=x onerror=alert(1)>Token rejected';

    $out = Skwirrel_WC_Sync_Admin_Settings::format_test_result(skw_test_meta(['http_code' => 401]), null, false, $hostile);

    expect($out['message'])->toBe($hostile);
});

/*
 * ---------------------------------------------------------------------------
 * The rendering contract (AC 6, AC 3/NFR-7, AC 7, AC 8)
 *
 * There is no browser or CSS harness in this repo, so what a click does cannot be asserted here.
 * What CAN be asserted is that the shipped source still keeps the promises the story makes about
 * it: no `innerHTML` on a node that carries an API-supplied string, a warning tone that is not
 * colour alone, tabular numerals, one shared formatter behind both test paths, and every new
 * string in the .pot. These are guards against silent regression, not a substitute for the manual
 * wp-env check recorded in the story's completion notes.
 * ---------------------------------------------------------------------------
 */

function skw_admin_settings_source(): string
{
    return (string) file_get_contents(
        __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php'
    );
}

function skw_dashboard_css(): string
{
    return (string) file_get_contents(__DIR__ . '/../../plugin/skwirrel-pim-sync/assets/dashboard.css');
}

/**
 * The body of the inline `setRes()` renderer, sliced out of the admin-settings source.
 */
function skw_set_res_source(): string
{
    $source = skw_admin_settings_source();
    $start  = strpos($source, 'function setRes(');
    $end    = strpos($source, 'if (!sub)', $start === false ? 0 : $start);

    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    return substr($source, (int) $start, (int) $end - (int) $start);
}

test('the result renderer builds DOM nodes and never assigns innerHTML', function () {
    $renderer = skw_set_res_source();

    // The headline can be an API-supplied error string; innerHTML here would be an injection sink.
    expect($renderer)->not->toContain('innerHTML');
	expect($renderer)->toContain('createElement');
	expect($renderer)->toContain('createDocumentFragment');
	expect($renderer)->toContain('textContent');
	expect($renderer)->toContain('aria-busy');
    // Cleared child-by-child rather than with innerHTML = "".
    expect($renderer)->toContain('removeChild');
});

test('the status live region explicitly announces each complete result atomically', function () {
	$dashboard = (string) file_get_contents(
		__DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php'
	);

	expect($dashboard)->toContain('id="skwirrel-test-result"');
	expect($dashboard)->toContain('role="status" aria-live="polite" aria-atomic="true"');
});

test('the tone from the server maps to a class, with the old mapping as the fallback', function () {
    $source = skw_admin_settings_source();

    expect($source)->toContain('warning: "skw-test-warning"');
    // A browser holding a stale cached script must still colour the result.
    expect($source)->toContain('toneClass || (ok ? "skw-test-success" : "skw-test-error")');
});

test('the warning tone is distinguished by more than colour', function () {
    $css = skw_dashboard_css();

    expect($css)->toContain('.skw-test-result.skw-test-warning');

    $warning = substr($css, (int) strpos($css, '.skw-test-result.skw-test-warning'), 400);
    expect($warning)->toContain('font-weight');
    // A glyph, so the tone survives greyscale and colour-blindness (NFR-7).
    expect($warning)->toContain('content:');
});

test('metric numbers render with tabular numerals', function () {
    $css     = skw_dashboard_css();
    $metric  = substr($css, (int) strpos($css, '.skw-test-metric {'), 200);

    expect($metric)->toContain('font-variant-numeric: tabular-nums');
});

test('both test paths use one shared secret-safe formatter pipeline', function () {
    // AC 8's "cannot drift" claim is structural: neither handler may build its own wording. The
    // legacy path redirects and exits, so it cannot be invoked from a test — this is the guard.
    $source = skw_admin_settings_source();

    $ajax   = substr($source, (int) strpos($source, 'public function handle_test_connection_ajax'));
    $legacy = substr(
        $source,
        (int) strpos($source, 'public function handle_test_connection('),
        (int) strpos($source, 'public function handle_test_connection_ajax') - (int) strpos($source, 'public function handle_test_connection(')
    );

    expect($legacy)->toContain('self::prepare_test_result_payload(');
    expect(substr($ajax, 0, 2500))->toContain('self::prepare_test_result_payload(');

    $pipeline = substr(
        $source,
        (int) strpos($source, 'private static function prepare_test_result_payload'),
        (int) strpos($source, 'public function handle_test_connection(') - (int) strpos($source, 'private static function prepare_test_result_payload')
    );
    expect($pipeline)->toContain('self::format_test_result(');
    expect($pipeline)->toContain("strpos( \$error_message, \$auth_token )");
    // The string this story replaces must be gone from both.
    expect($source)->not->toContain('Connection successful — settings saved.');
});

test('every new user-facing string is in the translation template', function (string $msgid) {
    $pot = (string) file_get_contents(
        __DIR__ . '/../../plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot'
    );

    expect($pot)->toContain('msgid "' . $msgid . '"');

    foreach (glob(__DIR__ . '/../../plugin/skwirrel-pim-sync/languages/*.po') ?: [] as $po) {
        expect((string) file_get_contents($po))->toContain('msgid "' . $msgid . '"');
    }
})->with([
    'Connection successful.',
    'Connection works, but the API returned no products.',
    'Connection failed.',
    'Round-trip: %s ms',
    'Status: %s',
    'Products: %s',
    'Products: unavailable',
    'Attempts: %s',
	'no response (transport error)',
	'HTTP %1$s · JSON-RPC error %2$s',
]);

test('the new strings are wrapped with the literal text domain', function () {
    $source = skw_admin_settings_source();

    foreach (['Round-trip: %s ms', 'Products: unavailable', 'no response (transport error)', 'HTTP %1$s · JSON-RPC error %2$s'] as $literal) {
        expect($source)->toContain("__( '" . $literal . "', 'skwirrel-pim-sync' )");
    }
});
