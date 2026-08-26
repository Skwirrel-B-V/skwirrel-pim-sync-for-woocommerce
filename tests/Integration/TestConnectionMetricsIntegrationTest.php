<?php
/**
 * Integration tests for what "Test connection" reports back (Story 5.4).
 *
 * The unit suite covers the pure formatter exhaustively. What it cannot cover is everything either
 * side of it, because the stub bootstrap has no HTTP layer, no options table and no admin render:
 *
 *  1. **The measurement itself.** `call()` must attach `meta` on *every* return path, and `attempts`
 *     must be the number of requests that really left the plugin — a fabricated `attempts => 3` in a
 *     unit test proves the wording, not the counting. Here the transport is stubbed at
 *     `pre_http_request`, so a WP_Error, a 500, a body that is not JSON and a JSON-RPC rejection are
 *     each driven through the real client.
 *  2. **The AJAX payload an administrator's browser receives** — tone, details, that zero products
 *     stays a *success* response, that the auth token appears nowhere in it, and that the handler
 *     writes nothing beyond the two options it already autosaved (AC 5).
 *  3. **The legacy notice** — the transient payload rendered on the real settings screen, with the
 *     tone mapped to a WordPress notice class and every API-supplied string escaped (AC 8).
 *
 * NOT covered here (no browser/CSS harness in this repo — see the static guards in
 * `tests/Unit/TestConnectionMetricsTest.php` for what is asserted about the shipped source):
 *  - the click actually rendering spans, and the aria-live region announcing them;
 *  - how the amber warning tone looks;
 *  - `handle_test_connection()` end to end — it finishes with `wp_safe_redirect()` + `exit`, which
 *    cannot be invoked from a test process. Its *render* half is covered below, and the unit suite
 *    pins that it formats through the same shared formatter.
 */

declare(strict_types=1);

/**
 * Raised instead of the `die` inside `wp_send_json_*()` so the request can be inspected.
 */
final class Skw_Metrics_Ajax_Halt extends Exception {}

/**
 * Install a JSON-RPC transport stub and record what left the plugin.
 *
 * @param callable $responder callable(array $args): array|WP_Error — the canned HTTP response.
 */
function skwMetricsStubTransport( callable $responder ): void {
	if ( isset( $GLOBALS['__skw_metrics_transport_filter'] ) && is_callable( $GLOBALS['__skw_metrics_transport_filter'] ) ) {
		remove_filter( 'pre_http_request', $GLOBALS['__skw_metrics_transport_filter'], 10 );
	}

	$GLOBALS['__skw_metrics_requests'] = array();
	$GLOBALS['__skw_metrics_responder'] = $responder;
	$GLOBALS['__skw_metrics_transport_filter'] = function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'metrics.skwirrel.example' ) ) {
			return $pre;
		}

		$GLOBALS['__skw_metrics_requests'][] = $args;

		return ( $GLOBALS['__skw_metrics_responder'] )( $args );
	};

	add_filter( 'pre_http_request', $GLOBALS['__skw_metrics_transport_filter'], 10, 3 );
}

/**
 * A canned HTTP 200 JSON-RPC response body.
 *
 * @param array<string, mixed> $payload The JSON-RPC envelope minus jsonrpc/id.
 * @return array<string, mixed>
 */
function skwMetricsResponse( array $payload, int $code = 200 ): array {
	return array(
		'headers'  => array(),
		'cookies'  => array(),
		'filename' => null,
		'body'     => wp_json_encode( array_merge( array( 'jsonrpc' => '2.0', 'id' => 1 ), $payload ) ),
		'response' => array(
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Error',
		),
	);
}

/**
 * A `getProducts` result carrying a pagination block.
 *
 * @return array<string, mixed>
 */
function skwMetricsProductsResult( ?int $total, int $returned = 1 ): array {
	$result = array(
		'products' => array_fill( 0, $returned, array( 'product_id' => 7 ) ),
	);
	if ( null !== $total ) {
		$result['page'] = array(
			'number_of_items' => $total,
			'items_per_page'  => 1,
			'number_of_pages' => $total,
			'current_page'    => 1,
		);
	}

	return $result;
}

/**
 * Build a client from the stored settings, the way both test paths do.
 */
function skwMetricsClient( int $retries = 0 ): Skwirrel_WC_Sync_JsonRpc_Client {
	return new Skwirrel_WC_Sync_JsonRpc_Client(
		'https://metrics.skwirrel.example/jsonrpc',
		'token',
		(string) get_option( 'skwirrel_wc_sync_auth_token', '' ),
		5,
		$retries
	);
}

/**
 * Run the AJAX handler and return the decoded JSON body plus the raw text.
 *
 * @param array<string, string> $post Extra POST fields.
 * @return array{raw: string, json: array<string, mixed>}
 */
function skwMetricsCallAjax( array $post = array() ): array {
	$nonce = wp_create_nonce( 'skwirrel_test_connection_nonce' );

	$_POST                 = array_merge(
		array(
			'_nonce'       => $nonce,
			'endpoint_url' => 'https://metrics.skwirrel.example/jsonrpc',
			'auth_token'   => '',
		),
		$post
	);
	$_REQUEST              = $_POST;

	// `wp_send_json_*()` ends the request: `die` when it thinks this is not an AJAX request, and the
	// AJAX die handler when it does. Claim the AJAX context and swap the handler for an exception,
	// which is the only way to observe the payload rather than lose the test process to it.
	$doing_ajax_filter = static fn (): bool => true;
	$ajax_die_filter   = static function () {
		return function ( $message = '', $title = '', $args = array() ): void {
			throw new Skw_Metrics_Ajax_Halt( (string) $message );
		};
	};

	add_filter( 'wp_doing_ajax', $doing_ajax_filter );
	add_filter(
		'wp_die_ajax_handler',
		$ajax_die_filter
	);

	ob_start();
	try {
		Skwirrel_WC_Sync_Admin_Settings::instance()->handle_test_connection_ajax();
	} catch ( Skw_Metrics_Ajax_Halt $halt ) {
		unset( $halt );
	} finally {
		$raw = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', $doing_ajax_filter );
		remove_filter( 'wp_die_ajax_handler', $ajax_die_filter );
		$_POST    = array();
		$_REQUEST = array();
	}

	$decoded = json_decode( $raw, true );

	return array(
		'raw'  => $raw,
		'json' => is_array( $decoded ) ? $decoded : array(),
	);
}

/**
 * Render the settings screen an administrator receives, notices included.
 */
function skwMetricsRenderSettingsScreen(): string {
	$_GET['tab'] = 'settings';
	ob_start();
	Skwirrel_WC_Sync_Admin_Settings::instance()->render_page();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );

	return $html;
}

beforeEach(
	function (): void {
		$admin          = wp_insert_user(
			array(
				'user_login' => 'skw_metrics_admin',
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		$this->admin_id = is_wp_error( $admin ) ? 0 : (int) $admin;
		wp_set_current_user( $this->admin_id );

		update_option( 'skwirrel_wc_sync_auth_token', 'skw_live_METRICSSECRET', false );
		update_option(
			'skwirrel_wc_sync_settings',
			array(
				'endpoint_url' => 'https://metrics.skwirrel.example/jsonrpc',
				'auth_type'    => 'token',
				'auth_token'   => '••••••••',
				'timeout'      => 5,
				'retries'      => 0,
			),
			false
		);
		delete_transient( 'skwirrel_wc_sync_test_result' );
	}
);

afterEach(
	function (): void {
		if ( isset( $GLOBALS['__skw_metrics_transport_filter'] ) && is_callable( $GLOBALS['__skw_metrics_transport_filter'] ) ) {
			remove_filter( 'pre_http_request', $GLOBALS['__skw_metrics_transport_filter'], 10 );
		}
		unset( $GLOBALS['__skw_metrics_requests'], $GLOBALS['__skw_metrics_responder'], $GLOBALS['__skw_metrics_transport_filter'] );
		delete_transient( 'skwirrel_wc_sync_test_result' );
		delete_option( 'skwirrel_wc_sync_settings' );
		delete_option( 'skwirrel_wc_sync_auth_token' );
		$_POST    = array();
		$_REQUEST = array();
		if ( ! empty( $this->admin_id ) ) {
			wp_delete_user( $this->admin_id );
		}
		wp_set_current_user( 0 );
	}
);

/*
 * ---------------------------------------------------------------------------
 * AC 1 / AC 4 — the client measures every return path
 * ---------------------------------------------------------------------------
 */

test( 'a successful call carries measurement and the API-reported total', function (): void {
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse( array( 'result' => skwMetricsProductsResult( 4821 ) ) )
	);

	$result = skwMetricsClient()->test_connection();

	expect( $result['success'] )->toBeTrue();
	expect( $result['meta']['http_code'] )->toBe( 200 );
	expect( $result['meta']['attempts'] )->toBe( 1 );
	expect( $result['meta']['duration_ms'] )->toBeInt()->toBeGreaterThanOrEqual( 0 );
	// One product came back; the catalogue holds 4821. The line must report the catalogue.
	expect( $result['product_count'] )->toBe( 4821 );
	expect( $GLOBALS['__skw_metrics_requests'] )->toHaveCount( 1 );
} );

test( 'a JSON-RPC rejection still reports the HTTP status it was rejected with', function (): void {
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse(
			array( 'error' => array( 'code' => -32000, 'message' => 'Invalid API token' ) )
		)
	);

	$result = skwMetricsClient()->test_connection();

	expect( $result['success'] )->toBeFalse();
	expect( $result['meta']['http_code'] )->toBe( 200 );
	expect( $result['meta']['attempts'] )->toBe( 1 );
	expect( $result['product_count'] )->toBeNull();

	// The JSON-RPC code is protocol status, not transport measurement, so the client never puts it
	// in `meta` — the payload step adds it. Assert through that step, or the status line would read
	// a bare "JSON-RPC error" with the code the admin needs silently dropped.
	$response = skwMetricsCallAjax();

	expect( $response['json']['data']['tone'] )->toBe( 'error' );
	expect( implode( ' | ', $response['json']['data']['details'] ) )->toContain( 'JSON-RPC error -32,000' );
} );

test( 'an HTTP error response reports its status and is not retried', function (): void {
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse(
			array( 'error' => array( 'code' => 500, 'message' => 'Internal Server Error' ) ),
			500
		)
	);

	$result = skwMetricsClient( 2 )->test_connection();

	expect( $result['success'] )->toBeFalse();
	expect( $result['meta']['http_code'] )->toBe( 500 );
	// A 500 is an answer, not a lost request: retrying it would triple the wait for nothing.
	expect( $result['meta']['attempts'] )->toBe( 1 );
	expect( $GLOBALS['__skw_metrics_requests'] )->toHaveCount( 1 );
} );

test( 'a body that is not JSON still comes back measured', function (): void {
	skwMetricsStubTransport(
		fn (): array => array(
			'headers'  => array(),
			'body'     => '<html>maintenance</html>',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		)
	);

	$result = skwMetricsClient()->test_connection();

	expect( $result['success'] )->toBeFalse();
	// Without meta on this branch the screen would show a blank metric line.
	expect( $result['meta'] )->toHaveKeys( array( 'duration_ms', 'http_code', 'attempts' ) );
	expect( $result['meta']['http_code'] )->toBe( 200 );
	expect( $result['error']['message'] )->toBe( 'Invalid JSON response' );
} );

test( 'a transport failure counts every attempt and reports no HTTP response', function (): void {
	skwMetricsStubTransport(
		fn (): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
	);

	$result = skwMetricsClient( 2 )->test_connection();

	expect( $result['success'] )->toBeFalse();
	// The count is the real number of requests made, not a constant.
	expect( $result['meta']['attempts'] )->toBe( 3 );
	expect( $GLOBALS['__skw_metrics_requests'] )->toHaveCount( 3 );
	// No response at all — status 0, which the formatter must not print as a status.
	expect( $result['meta']['http_code'] )->toBe( 0 );

	$formatted = Skwirrel_WC_Sync_Admin_Settings::format_test_result(
		$result['meta'],
		$result['product_count'],
		false,
		$result['error']['message']
	);
	$details   = implode( ' | ', $formatted['details'] );
	expect( $details )->toContain( 'no response (transport error)' );
	expect( $details )->toContain( 'Attempts: 3' );
	expect( $details )->not->toContain( 'HTTP 0' );
} );

/*
 * ---------------------------------------------------------------------------
 * AC 2 — an absent pagination block never becomes a number
 * ---------------------------------------------------------------------------
 */

test( 'an API build without a pagination block reports the total as unknown', function (): void {
	// Two products come back and the response has no `page` key: counting the array would report
	// a total of 2 for a catalogue of unknown size.
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse( array( 'result' => skwMetricsProductsResult( null, 2 ) ) )
	);

	$result = skwMetricsClient()->test_connection();

	expect( $result['success'] )->toBeTrue();
	expect( $result['product_count'] )->toBeNull();

	$formatted = Skwirrel_WC_Sync_Admin_Settings::format_test_result( $result['meta'], $result['product_count'], true );
	expect( implode( ' | ', $formatted['details'] ) )->toContain( 'Products: unavailable' );
} );

/*
 * ---------------------------------------------------------------------------
 * AC 1, 3, 4, 5 — the payload the browser receives
 * ---------------------------------------------------------------------------
 */

test( 'a successful test answers with tone, headline and metric lines', function (): void {
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse( array( 'result' => skwMetricsProductsResult( 1234 ) ) )
	);

	$response = skwMetricsCallAjax();

	expect( $response['json']['success'] )->toBeTrue();
	expect( $response['json']['data']['tone'] )->toBe( 'success' );
	expect( $response['json']['data']['message'] )->toBe( 'Connection successful.' );

	$details = implode( ' | ', $response['json']['data']['details'] );
	expect( $details )->toContain( 'Round-trip:' );
	expect( $details )->toContain( 'HTTP 200 · JSON-RPC OK' );
	expect( $details )->toContain( '1,234' );
} );

test( 'zero products stays a success response and carries the warning in the tone', function (): void {
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse( array( 'result' => array( 'products' => array(), 'page' => array( 'number_of_items' => 0 ) ) ) )
	);

	$response = skwMetricsCallAjax();

	// Flipping success here would make the browser render a working connection as a failure.
	expect( $response['json']['success'] )->toBeTrue();
	expect( $response['json']['data']['tone'] )->toBe( 'warning' );
	expect( $response['json']['data']['message'] )->toContain( 'no products' );
	expect( implode( ' | ', $response['json']['data']['details'] ) )->toContain( 'Products: 0' );
} );

test( 'a failed test answers with an error tone and still reports timing and status', function (): void {
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse(
			array( 'error' => array( 'code' => -32000, 'message' => 'Invalid API token' ) )
		)
	);

	$response = skwMetricsCallAjax();

	expect( $response['json']['success'] )->toBeFalse();
	expect( $response['json']['data']['tone'] )->toBe( 'error' );
	expect( $response['json']['data']['message'] )->toBe( 'Invalid API token' );

	$details = implode( ' | ', $response['json']['data']['details'] );
	expect( $details )->toContain( 'Round-trip:' );
	expect( $details )->toContain( 'Status:' );
} );

test( 'the auth token is sent to the API but never appears in the response', function (): void {
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse( array( 'result' => skwMetricsProductsResult( 5 ) ) )
	);

	$response = skwMetricsCallAjax();

	// The guard is only meaningful if the token really was in play on this request.
	$headers = $GLOBALS['__skw_metrics_requests'][0]['headers'];
	expect( $headers['X-Skwirrel-Api-Token'] )->toBe( 'skw_live_METRICSSECRET' );

	expect( $response['raw'] )->not->toContain( 'skw_live_METRICSSECRET' );
	expect( $response['raw'] )->not->toContain( 'METRICSSECRET' );
	expect( $response['raw'] )->not->toContain( 'X-Skwirrel-Api-Token' );
} );

test( 'an API error that reflects the auth token is redacted before the JSON response', function (): void {
	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse(
			array( 'error' => array( 'code' => -32000, 'message' => 'Rejected token skw_live_METRICSSECRET' ) )
		)
	);

	$response = skwMetricsCallAjax();

	expect( $response['json']['success'] )->toBeFalse();
	expect( $response['json']['data']['message'] )->toBe( 'Connection failed.' );
	expect( $response['raw'] )->not->toContain( 'skw_live_METRICSSECRET' );
	expect( $response['raw'] )->not->toContain( 'METRICSSECRET' );
} );

test( 'the test writes nothing beyond the settings it already autosaved', function (): void {
	global $wpdb;

	skwMetricsStubTransport(
		fn (): array => skwMetricsResponse( array( 'result' => skwMetricsProductsResult( 5 ) ) )
	);

	$before = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
	skwMetricsCallAjax();
	$after  = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );

	// No new option, and no new transient either — transients land in this table without a
	// persistent object cache, so a stray `set_transient()` would show up right here.
	expect( array_values( array_diff( $after, $before ) ) )->toBe( array() );
} );

/*
 * ---------------------------------------------------------------------------
 * AC 8 — the legacy admin notice renders the same metrics
 * ---------------------------------------------------------------------------
 */

test( 'a warning result renders as a WordPress warning notice with its metric lines', function (): void {
	set_transient(
		'skwirrel_wc_sync_test_result',
		array(
			'success' => true,
			'tone'    => 'warning',
			'message' => 'Connection works, but the API returned no products.',
			'details' => array( 'Round-trip: 142 ms', 'Status: HTTP 200 · JSON-RPC OK', 'Products: 0' ),
		),
		60
	);

	$html = skwMetricsRenderSettingsScreen();

	expect( $html )->toContain( 'notice-warning' );
	expect( $html )->toContain( 'Connection works, but the API returned no products.' );
	expect( $html )->toContain( 'Round-trip: 142 ms' );
	expect( $html )->toContain( 'Products: 0' );
	expect( $html )->toContain( 'skw-test-metric' );
	// Read once: a later render must not resurrect it.
	expect( get_transient( 'skwirrel_wc_sync_test_result' ) )->toBeFalse();
} );

test( 'an API-supplied error message is escaped in the notice', function (): void {
	set_transient(
		'skwirrel_wc_sync_test_result',
		array(
			'success' => false,
			'tone'    => 'error',
			'message' => '<img src=x onerror=alert(1)>Token rejected',
			'details' => array( 'Status: <b>HTTP 401</b>' ),
		),
		60
	);

	$html = skwMetricsRenderSettingsScreen();

	expect( $html )->toContain( 'notice-error' );
	expect( $html )->not->toContain( '<img src=x onerror' );
	expect( $html )->not->toContain( 'Status: <b>HTTP 401</b>' );
	expect( $html )->toContain( 'Token rejected' );
} );

test( 'a transient written before this change still renders', function (): void {
	// A result stashed by the previous release has no tone and no details.
	set_transient(
		'skwirrel_wc_sync_test_result',
		array( 'success' => false, 'message' => 'Connection failed.' ),
		60
	);

	$html = skwMetricsRenderSettingsScreen();

	expect( $html )->toContain( 'notice-error' );
	expect( $html )->toContain( 'Connection failed.' );
} );

test( 'a successful legacy result renders as a success notice', function (): void {
	set_transient(
		'skwirrel_wc_sync_test_result',
		array(
			'success' => true,
			'tone'    => 'success',
			'message' => 'Connection successful.',
			'details' => array( 'Round-trip: 90 ms', 'Status: HTTP 200 · JSON-RPC OK', 'Products: 4,821' ),
		),
		60
	);

	$html = skwMetricsRenderSettingsScreen();

	expect( $html )->toContain( 'notice-success' );
	expect( $html )->toContain( 'Products: 4,821' );
	expect( $html )->not->toContain( 'notice-warning' );
} );

/*
 * ---------------------------------------------------------------------------
 * AC 5 (denied paths) — the guards on `handle_test_connection_ajax()`
 * ---------------------------------------------------------------------------
 *
 * Every test above hands the handler a valid nonce and an administrator, so the two guards it
 * opens with have never been exercised:
 *
 *     check_ajax_referer( 'skwirrel_test_connection_nonce', '_nonce' );
 *     if ( ! current_user_can( 'manage_woocommerce' ) ) { … 403 … }
 *
 * What they protect is larger than the settings write. `endpoint_url` is read straight from
 * `$_POST`, autosaved, and then *requested* — so without these guards a POST from an unprivileged
 * or unauthenticated caller would both overwrite the stored connection and make the site issue a
 * server-side HTTP request to a URL of the caller's choosing. Each test below therefore asserts all
 * three properties: the request is refused, nothing is written, and nothing leaves the server.
 */

/**
 * Call the AJAX handler with a POST body given verbatim — no nonce is injected.
 *
 * Deliberately not built on `skwMetricsCallAjax()`, which always supplies a fresh valid nonce:
 * these tests need the nonce to be absent, forged, or minted for a *different* user.
 *
 * @param array<string, string> $post The exact `$_POST` to present.
 * @return array{raw: string, json: array<string, mixed>, halted: bool}
 */
function skwMetricsCallAjaxVerbatim( array $post ): array {
	$_POST    = $post;
	$_REQUEST = $post;

	$doing_ajax_filter = static fn (): bool => true;
	$ajax_die_filter   = static function () {
		return function ( $message = '', $title = '', $args = array() ): void {
			throw new Skw_Metrics_Ajax_Halt( (string) $message );
		};
	};

	add_filter( 'wp_doing_ajax', $doing_ajax_filter );
	add_filter( 'wp_die_ajax_handler', $ajax_die_filter );

	$halted = false;
	ob_start();
	try {
		Skwirrel_WC_Sync_Admin_Settings::instance()->handle_test_connection_ajax();
	} catch ( Skw_Metrics_Ajax_Halt $halt ) {
		$halted = true;
		unset( $halt );
	} finally {
		$raw = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', $doing_ajax_filter );
		remove_filter( 'wp_die_ajax_handler', $ajax_die_filter );
		$_POST    = array();
		$_REQUEST = array();
	}

	$decoded = json_decode( $raw, true );

	return array(
		'raw'    => $raw,
		'json'   => is_array( $decoded ) ? $decoded : array(),
		'halted' => $halted,
	);
}

/**
 * Intercept *every* outbound HTTP request, record its URL, and block it.
 *
 * Broader than `skwMetricsStubTransport()`, which only answers one host. A denied request must
 * reach no host at all, so this records anything the handler tries and returns a WP_Error rather
 * than letting a real request escape the test process.
 *
 * @return callable A remover to call once the assertion is done.
 */
function skwMetricsBlockAllRequests(): callable {
	$GLOBALS['__skw_metrics_blocked'] = array();

	$filter = static function ( $pre, $args, $url ) {
		$GLOBALS['__skw_metrics_blocked'][] = (string) $url;

		return new WP_Error( 'skw_test_blocked', 'Blocked by the test harness.' );
	};

	add_filter( 'pre_http_request', $filter, 1, 3 );

	return static function () use ( $filter ): void {
		remove_filter( 'pre_http_request', $filter, 1 );
	};
}

/**
 * The connection state a denied request must leave exactly as it found it.
 *
 * @return array<string, mixed>
 */
function skwMetricsConnectionState(): array {
	return array(
		'settings' => get_option( 'skwirrel_wc_sync_settings' ),
		'token'    => get_option( 'skwirrel_wc_sync_auth_token' ),
	);
}

test( 'a request carrying no nonce at all is refused, writes nothing and calls nothing', function (): void {
	$unblock = skwMetricsBlockAllRequests();
	$before  = skwMetricsConnectionState();

	// No `_nonce` key whatsoever — the shape an off-site form post would have.
	$response = skwMetricsCallAjaxVerbatim(
		array(
			'endpoint_url' => 'https://attacker.example/jsonrpc',
			'auth_token'   => 'attacker-supplied',
		)
	);

	$unblock();

	expect( $response['halted'] )->toBeTrue();
	expect( $response['json']['success'] ?? null )->not->toBeTrue();
	expect( skwMetricsConnectionState() )->toBe( $before );
	expect( $GLOBALS['__skw_metrics_blocked'] )->toBe( array() );
} );

test( 'a request carrying a forged nonce is refused, writes nothing and calls nothing', function (): void {
	$unblock = skwMetricsBlockAllRequests();
	$before  = skwMetricsConnectionState();

	$response = skwMetricsCallAjaxVerbatim(
		array(
			'_nonce'       => 'not-a-real-nonce',
			'endpoint_url' => 'https://attacker.example/jsonrpc',
			'auth_token'   => 'attacker-supplied',
		)
	);

	$unblock();

	expect( $response['halted'] )->toBeTrue();
	expect( $response['json']['success'] ?? null )->not->toBeTrue();
	expect( skwMetricsConnectionState() )->toBe( $before );
	expect( $GLOBALS['__skw_metrics_blocked'] )->toBe( array() );
} );

test( 'a signed-in subscriber holding a valid nonce is refused with 403', function (): void {
	$subscriber = wp_insert_user(
		array(
			'user_login' => 'skw_metrics_subscriber',
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	$subscriber_id = is_wp_error( $subscriber ) ? 0 : (int) $subscriber;
	expect( $subscriber_id )->toBeGreaterThan( 0 );

	// Become the subscriber *before* minting the nonce: a nonce is per-user, so one created as the
	// administrator would fail `check_ajax_referer()` first and this would silently re-test the
	// nonce guard instead of the capability guard it is named for.
	wp_set_current_user( $subscriber_id );
	expect( current_user_can( 'manage_woocommerce' ) )->toBeFalse();

	$unblock = skwMetricsBlockAllRequests();
	$before  = skwMetricsConnectionState();

	$response = skwMetricsCallAjaxVerbatim(
		array(
			'_nonce'       => wp_create_nonce( 'skwirrel_test_connection_nonce' ),
			'endpoint_url' => 'https://attacker.example/jsonrpc',
			'auth_token'   => 'attacker-supplied',
		)
	);

	$unblock();
	wp_set_current_user( $this->admin_id );
	wp_delete_user( $subscriber_id );

	expect( $response['halted'] )->toBeTrue();
	expect( $response['json']['success'] ?? null )->toBeFalse();
	expect( $response['json']['data']['message'] ?? '' )->toBe( 'Access denied.' );
	expect( skwMetricsConnectionState() )->toBe( $before );
	expect( $GLOBALS['__skw_metrics_blocked'] )->toBe( array() );
} );

test( 'a refused request never becomes a server-side request to the URL it supplied', function (): void {
	$unblock = skwMetricsBlockAllRequests();

	skwMetricsCallAjaxVerbatim(
		array(
			'_nonce'       => 'not-a-real-nonce',
			'endpoint_url' => 'https://ssrf-target.example/internal',
			'auth_token'   => '',
		)
	);

	$unblock();

	// The endpoint is attacker-controlled POST data that the handler autosaves and then requests.
	// Neither may happen for a caller that failed the guards.
	expect( $GLOBALS['__skw_metrics_blocked'] )->toBe( array() );
	expect( get_option( 'skwirrel_wc_sync_settings' )['endpoint_url'] ?? '' )
		->toBe( 'https://metrics.skwirrel.example/jsonrpc' );
} );
