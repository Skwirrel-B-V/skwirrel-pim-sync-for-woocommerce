<?php
/**
 * Integration tests for the optional Context ID (Story 5.3).
 *
 * The unit suite (`tests/Unit/ContextIdTest.php`) pins the resolve rule, the sanitiser and the two
 * call sites a stub bootstrap can reach. Three things it cannot reach are exactly the three that
 * would break a real shop, so they are covered here against a real WordPress + WooCommerce:
 *
 *  1. **What the wire actually carries.** The four `Sync_Service` call sites sit inside a paginated
 *     run the stub bootstrap cannot drive, so the unit suite pins them with a source-level regex and
 *     says so. Here `run_sync()` really runs, the JSON-RPC endpoint is stubbed at
 *     `pre_http_request`, and the assertion is about the request body that left the plugin — a
 *     helper that stopped being called would still pass the regex and fail here.
 *  2. **The field an administrator receives.** AC-1 is a claim about rendered markup, and nothing
 *     asserted it. These tests render the settings screen for a real administrator and parse it with
 *     DOM.
 *  3. **The save path end to end.** The unit suite calls `on_settings_updated()` directly. Here the
 *     real `update_option()` fires the real hook, so a hook that stopped being registered is caught.
 *
 * Also pinned: the two invariants the story's Dev Notes call out as "verify you don't disturb this"
 * and which nothing asserted — the test-connection AJAX round trip must not schedule a full re-sync,
 * and `context_id` must stay OFF the change gate's denylist.
 *
 * NOT covered here (browser-only, and there is no E2E harness in this repo):
 *  - the browser's own handling of the numeric input hints (the attributes ARE asserted);
 *  - what the field and its message look like on screen.
 */

declare(strict_types=1);

/**
 * Render the settings screen the way an administrator receives it.
 */
function skwContextRenderScreen(): string {
	ob_start();
	( new Skwirrel_WC_Sync_Admin_Dashboard() )->render( 'settings' );

	return (string) ob_get_clean();
}

/**
 * Parse rendered markup into an XPath query object.
 */
function skwContextXPath( string $html ): DOMXPath {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
	libxml_clear_errors();

	return new DOMXPath( $doc );
}

/**
 * The single element carrying an id, or null when the markup has none.
 */
function skwContextElementById( DOMXPath $xpath, string $id ): ?DOMElement {
	$found = $xpath->query( '//*[@id="' . $id . '"]' );
	if ( false === $found || 0 === $found->length ) {
		return null;
	}
	$node = $found->item( 0 );

	return $node instanceof DOMElement ? $node : null;
}

/**
 * Overwrite the stored settings, keeping the baseline a sync run needs.
 *
 * @param array<string, mixed> $overrides Settings to merge over the baseline.
 */
function skwContextSetSettings( array $overrides = array() ): void {
	update_option(
		'skwirrel_wc_sync_settings',
		array_merge(
			array(
				'endpoint_url'                   => 'https://context.skwirrel.example/jsonrpc',
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
			),
			$overrides
		)
	);
}

/**
 * Install a JSON-RPC endpoint stub that records every request body it is handed.
 *
 * @param array<string, callable|array<string, mixed>> $responses Method name => result array, or
 *                                                                callable(params): result array.
 */
function skwContextStubApi( array $responses ): void {
	$GLOBALS['__skw_ctx_responses'] = $responses;
	$GLOBALS['__skw_ctx_calls']     = array();

	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) {
			if ( false === strpos( (string) $url, 'context.skwirrel.example' ) ) {
				return $pre;
			}

			$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
			$method = is_array( $body ) ? (string) ( $body['method'] ?? '' ) : '';
			$params = is_array( $body ) && isset( $body['params'] ) ? (array) $body['params'] : array();

			$GLOBALS['__skw_ctx_calls'][] = array(
				'method' => $method,
				'params' => $params,
			);

			$handler = $GLOBALS['__skw_ctx_responses'][ $method ] ?? null;
			$result  = is_callable( $handler ) ? $handler( $params ) : ( $handler ?? array() );

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => is_array( $body ) ? ( $body['id'] ?? 1 ) : 1,
						'result'  => $result,
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);
}

/**
 * Params of the first recorded call to a method that is not the membership sweep or the
 * per-product attribute re-fetch — i.e. the paginated content fetch this story is about.
 *
 * @return array<string, mixed>
 */
function skwContextContentFetchParams( string $method ): array {
	foreach ( (array) ( $GLOBALS['__skw_ctx_calls'] ?? array() ) as $call ) {
		if ( $call['method'] !== $method ) {
			continue;
		}
		$params = (array) $call['params'];
		if ( 'getProductsByFilter' === $method
			&& ( skwIsSweepCall( $params ) || isset( $params['filter']['code'] ) ) ) {
			continue;
		}

		return $params;
	}

	return array();
}

/**
 * A minimal one-product feed that ends pagination on page 2.
 *
 * @return array<string, callable|array<string, mixed>>
 */
function skwContextProductFeed(): array {
	$product = array(
		'product_id'              => 940001,
		'product_type'            => 'STANDARD',
		'external_product_id'     => 'EXT-940001',
		'internal_product_code'   => 'SKU-940001',
		'product_erp_description' => 'Context Widget',
		'_product_status'         => array( 'product_status_description' => 'active' ),
	);

	$page = 0;

	return array(
		'getBrands'           => array( 'brands' => array() ),
		'getCategories'       => array( 'categories' => array() ),
		'getProductsByFilter' => function ( $params ) use ( &$page, $product ) {
			// The per-product attribute re-fetch and the membership sweep are different questions
			// from the paginated content fetch; answer them outside the page counter.
			if ( isset( $params['filter']['code']['type'] ) && 'product_id' === $params['filter']['code']['type'] ) {
				return array( 'products' => array( $product ) );
			}
			if ( skwIsSweepCall( (array) $params ) ) {
				return array(
					'products' => array( array( 'product_id' => $product['product_id'] ) ),
					'page'     => array(
						'current_page'    => 1,
						'number_of_pages' => 1,
					),
				);
			}

			++$page;

			return array( 'products' => 1 === $page ? array( $product ) : array() );
		},
	);
}

beforeEach(
	function (): void {
		$admin          = wp_insert_user(
			array(
				'user_login' => 'skw_context_admin',
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		$this->admin_id = is_wp_error( $admin ) ? 0 : (int) $admin;
		wp_set_current_user( $this->admin_id );

		// Settings errors and the force-full-sync flag are process/DB-global; start clean.
		$GLOBALS['wp_settings_errors'] = array();
		delete_option( 'skwirrel_wc_sync_force_full_sync' );
		delete_option( 'skwirrel_wc_sync_auth_token' );
		delete_option( 'skwirrel_wc_sync_last_sync' );
		update_option( 'skwirrel_wc_sync_auth_token', 'context-token' );

		// `admin_init` does not fire in the test bootstrap, so the settings hooks — including the
		// `update_option_` listener AC-4 depends on — are not registered by default. Registering
		// them here is what makes the save path below the real one; `add_action()` dedupes the
		// identical callback, so repeating it per test adds nothing.
		Skwirrel_WC_Sync_Admin_Settings::instance()->register_settings();

		skwContextSetSettings();
	}
);

afterEach(
	function (): void {
		// Registering the settings hooks is process-global: leaving the sanitise callback on
		// `sanitize_option_skwirrel_wc_sync_settings` would scrub the settings that later test
		// files write straight into the option. Hand the process back as it was found.
		unregister_setting( 'skwirrel_wc_sync', 'skwirrel_wc_sync_settings' );
		remove_action(
			'update_option_skwirrel_wc_sync_settings',
			array( Skwirrel_WC_Sync_Admin_Settings::instance(), 'on_settings_updated' ),
			10
		);

		remove_all_filters( 'pre_http_request' );
		unset( $GLOBALS['__skw_ctx_responses'], $GLOBALS['__skw_ctx_calls'] );
		$GLOBALS['wp_settings_errors'] = array();
		delete_option( 'skwirrel_wc_sync_settings' );
		delete_option( 'skwirrel_wc_sync_force_full_sync' );
		delete_option( 'skwirrel_wc_sync_auth_token' );
		skwPurgeSkwirrelPosts();
		if ( ! empty( $this->admin_id ) ) {
			wp_delete_user( $this->admin_id );
		}
		wp_set_current_user( 0 );
	}
);

/*
 * ---------------------------------------------------------------------------
 * AC-1 — the field an administrator actually receives
 * ---------------------------------------------------------------------------
 */

test(
	'the Context ID renders as an optional whole-number field on the Connection tab',
	function (): void {
		skwContextSetSettings( array( 'context_id' => '7' ) );

		$xpath = skwContextXPath( skwContextRenderScreen() );
		$input = skwContextElementById( $xpath, 'context_id' );

		expect( $input )->not->toBeNull( 'the Context ID field is not rendered' );
		// Deliberately not type="number": that input's value-sanitization algorithm reports a
		// non-numeric value as the empty string, which would hide the rejected value the sanitiser
		// keeps verbatim so it can be corrected. Numeric hints give the keypad without the loss.
		expect( $input->getAttribute( 'type' ) )->toBe( 'text' );
		expect( $input->getAttribute( 'inputmode' ) )->toBe( 'numeric' );
		expect( $input->getAttribute( 'pattern' ) )->toBe( '[0-9]*' );
		expect( $input->getAttribute( 'placeholder' ) )->toBe( '1' );
		expect( $input->getAttribute( 'name' ) )->toBe( 'skwirrel_wc_sync_settings[context_id]' );
		expect( $input->getAttribute( 'value' ) )->toBe( '7' );

		// Genuinely optional — AC-1. A `required` here would block every save on an empty field.
		expect( $input->hasAttribute( 'required' ) )->toBeFalse();
		expect( $input->hasAttribute( 'aria-required' ) )->toBeFalse();

		// It lands on the Connection tab by living in the API Connection group, and the field
		// markup names no tab of its own.
		$panel = $input->parentNode;
		while ( $panel instanceof DOMElement && 'tabpanel' !== $panel->getAttribute( 'role' ) ) {
			$panel = $panel->parentNode;
		}
		expect( $panel )->toBeInstanceOf( DOMElement::class, 'the Context ID is not inside a tabpanel' );
		expect( $panel->getAttribute( 'id' ) )->toBe( 'panel-connection' );
		expect( $input->parentNode->parentNode )->toBeInstanceOf( DOMElement::class );
		expect( $input->parentNode->parentNode->getAttribute( 'class' ) )->toContain( 'skw-field-row' );
	}
);

test(
	'the Context ID has a label and a hint that explains what leaving it empty does',
	function (): void {
		$xpath = skwContextXPath( skwContextRenderScreen() );

		$label = $xpath->query( '//label[@for="context_id"]' );
		expect( $label->length )->toBe( 1 );
		expect( trim( $label->item( 0 )->textContent ) )->not->toBe( '' );

		// No required marker: the field is optional, so nothing may suggest otherwise.
		expect( $xpath->query( '//label[@for="context_id"]//*[@data-skw-req]' )->length )->toBe( 0 );

		// The hint is wired to the input, so a screen reader gets it with the field.
		$hint = skwContextElementById( $xpath, 'context_id-hint' );
		expect( $hint )->not->toBeNull();
		expect( strtolower( $hint->textContent ) )->toContain( 'empty' );

		$described = explode( ' ', skwContextElementById( $xpath, 'context_id' )->getAttribute( 'aria-describedby' ) );
		expect( $described )->toContain( 'context_id-hint' );
	}
);

/*
 * ---------------------------------------------------------------------------
 * AC-5 — a rejected value is reported at its field and stays on screen
 * ---------------------------------------------------------------------------
 */

test(
	'a rejected Context ID comes back in the field with its message, and flags the Connection tab',
	function (): void {
		$stored = Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
			array(
				'context_id'     => 'abc',
				'collection_ids' => '1',
			)
		);
		update_option( 'skwirrel_wc_sync_settings', $stored );

		$html  = skwContextRenderScreen();
		$xpath = skwContextXPath( $html );
		$input = skwContextElementById( $xpath, 'context_id' );

		// The user sees what they typed, so they can correct it — the save is not blocked.
		expect( $input->getAttribute( 'value' ) )->toBe( 'abc' );
		expect( $input->getAttribute( 'aria-invalid' ) )->toBe( 'true' );

		$message = skwContextElementById( $xpath, 'context_id-error' );
		expect( $message )->not->toBeNull( 'the rejected Context ID produced no inline message' );
		expect( $message->getAttribute( 'role' ) )->toBe( 'alert' );
		expect( trim( $message->textContent ) )->not->toBe( '' );

		$block = $xpath->query( '//*[@data-skw-error-field="context_id"]' );
		expect( $block->length )->toBe( 1 );
		expect( $xpath->query( './/*[@id="context_id-error"]', $block->item( 0 ) )->length )->toBe( 1 );

		// Story 5.1's seam: the tab carrying the failing field is flagged and opens first, so the
		// message is not hidden behind an unselected tab.
		expect( $html )->toMatch( '/id="tab-connection"[^>]*aria-selected="true"|aria-selected="true"[^>]*id="tab-connection"/s' );
		expect( $html )->toMatch( '/id="tab-connection"[^>]*skw-tab-error|skw-tab-error[^>]*id="tab-connection"/s' );
	}
);

test(
	'a valid Context ID round-trips through the screen with no error',
	function (): void {
		$stored = Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
			array(
				'context_id'     => ' 12 ',
				'collection_ids' => '1',
			)
		);
		update_option( 'skwirrel_wc_sync_settings', $stored );

		$xpath = skwContextXPath( skwContextRenderScreen() );
		$input = skwContextElementById( $xpath, 'context_id' );

		expect( $input->getAttribute( 'value' ) )->toBe( '12' );
		expect( $input->hasAttribute( 'aria-invalid' ) )->toBeFalse();
		expect( skwContextElementById( $xpath, 'context_id-error' ) )->toBeNull();
	}
);

/*
 * ---------------------------------------------------------------------------
 * AC-4 — the real save path sets (and does not set) the force-full-sync flag
 * ---------------------------------------------------------------------------
 */

test(
	'saving a changed effective context through update_option really sets the flag',
	function ( $before, $after ): void {
		skwContextSetSettings( array( 'context_id' => $before ) );

		// Arranging the "before" state is itself a save; clear what it left behind so the
		// assertions below can only be about the save under test.
		delete_option( 'skwirrel_wc_sync_force_full_sync' );
		$GLOBALS['wp_settings_errors'] = array();

		// The real option write, firing the real `update_option_` hook.
		skwContextSetSettings( array( 'context_id' => $after ) );

		expect( (bool) get_option( 'skwirrel_wc_sync_force_full_sync' ) )->toBeTrue();

		$codes = array_column( Skwirrel_WC_Sync_Admin_Settings::settings_errors_for_option(), 'code' );
		expect( $codes )->toContain( 'context_id_changed' );
	}
)->with(
	array(
		'empty to set'      => array( '', '5' ),
		'set to empty'      => array( '5', '' ),
		'set to different'  => array( '5', '6' ),
		'invalid to valid'  => array( 'abc', '5' ),
	)
);

test(
	'a save that leaves the effective context alone never schedules a full re-sync',
	function ( $before, $after ): void {
		skwContextSetSettings( array( 'context_id' => $before ) );
		delete_option( 'skwirrel_wc_sync_force_full_sync' );
		$GLOBALS['wp_settings_errors'] = array();

		skwContextSetSettings( array( 'context_id' => $after ) );

		expect( get_option( 'skwirrel_wc_sync_force_full_sync' ) )->toBeFalsy();

		// An invalid value still earns its own validation error (AC-5) — what must not appear is
		// the notice announcing a full re-sync, because the context being read did not change.
		$codes = array_column( Skwirrel_WC_Sync_Admin_Settings::settings_errors_for_option(), 'code' );
		expect( $codes )->not->toContain( 'context_id_changed' );
	}
)->with(
	array(
		'padding only'                 => array( '5', ' 5 ' ),
		'invalid to another invalid'   => array( 'abc', 'xyz' ),
		'zero to negative'             => array( '0', '-1' ),
	)
);

test(
	'changing an unrelated setting never schedules a full re-sync',
	function (): void {
		skwContextSetSettings( array( 'context_id' => '5' ) );
		delete_option( 'skwirrel_wc_sync_force_full_sync' );

		skwContextSetSettings(
			array(
				'context_id' => '5',
				'batch_size' => 25,
			)
		);

		expect( get_option( 'skwirrel_wc_sync_force_full_sync' ) )->toBeFalsy();
	}
);

/*
 * ---------------------------------------------------------------------------
 * Dev Notes — two invariants the story depends on and nothing asserted
 * ---------------------------------------------------------------------------
 */

test(
	'a test-connection click preserves the Context ID and does not schedule a full re-sync',
	function (): void {
		skwContextSetSettings( array( 'context_id' => '5' ) );
		delete_option( 'skwirrel_wc_sync_force_full_sync' );

		// handle_test_connection_ajax() writes the option directly, bypassing sanitize_settings().
		// It must keep the read-merge-write shape: anything it does not own survives untouched.
		skwContextStubApi( array( 'getProducts' => array( 'products' => array() ) ) );

		$_POST['_nonce']       = wp_create_nonce( 'skwirrel_test_connection_nonce' );
		$_POST['endpoint_url'] = 'https://context.skwirrel.example/jsonrpc';
		$_POST['auth_token']   = 'context-token';

		// wp_send_json_*() ends the request with wp_die(). Route it through the AJAX die handler
		// and turn that into an exception, so the response ends the handler instead of the process.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static fn (): callable => static function (): void {
				throw new WPAjaxDieContinueException( 'sent' );
			}
		);

		try {
			Skwirrel_WC_Sync_Admin_Settings::instance()->handle_test_connection_ajax();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} finally {
			remove_all_filters( 'wp_die_ajax_handler' );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			unset( $_POST['_nonce'], $_POST['endpoint_url'], $_POST['auth_token'] );
		}

		$opts = (array) get_option( 'skwirrel_wc_sync_settings' );
		expect( $opts['context_id'] ?? null )->toBe( '5' );
		expect( Skwirrel_WC_Sync_Admin_Settings::get_context_ids() )->toBe( array( 5 ) );
		expect( get_option( 'skwirrel_wc_sync_force_full_sync' ) )->toBeFalsy();
	}
);

test(
	'the Context ID is part of the change-gate signature, so a change reprocesses every product',
	function (): void {
		$service   = new Skwirrel_WC_Sync_Service();
		$signature = new ReflectionMethod( Skwirrel_WC_Sync_Service::class, 'compute_sync_signature' );
		$signature->setAccessible( true );

		$base = array( 'collection_ids' => '1' );

		$default = $signature->invoke( $service, $base );
		$five    = $signature->invoke( $service, $base + array( 'context_id' => '5' ) );
		$six     = $signature->invoke( $service, $base + array( 'context_id' => '6' ) );

		// The flag forces a full FETCH; the signature forces a full REPROCESS. Both are needed —
		// context_id must therefore stay off the signature's denylist.
		expect( $default )->not->toBe( $five );
		expect( $five )->not->toBe( $six );
	}
);

/*
 * ---------------------------------------------------------------------------
 * AC-2 / AC-3 — what a real sync run puts on the wire
 * ---------------------------------------------------------------------------
 */

test(
	'a configured Context ID reaches the product fetch of a real sync run',
	function (): void {
		skwContextSetSettings( array( 'context_id' => '9' ) );
		skwContextStubApi( skwContextProductFeed() );

		$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

		expect( $result['success'] )->toBeTrue();

		// getProductsByFilter nests the include flags under `options` — see the Dev Notes on why
		// this is not centralised in the client.
		$params = skwContextContentFetchParams( 'getProductsByFilter' );
		expect( $params )->not->toBe( array(), 'the run made no product content fetch' );
		expect( $params['options']['include_contexts'] )->toBe( array( 9 ) );

		$sweep_params = array();
		foreach ( (array) $GLOBALS['__skw_ctx_calls'] as $call ) {
			if ( 'getProductsByFilter' === $call['method'] && skwIsSweepCall( (array) $call['params'] ) ) {
				$sweep_params = (array) $call['params'];
				break;
			}
		}
		expect( $sweep_params )->not->toBe( array(), 'the run made no membership sweep' );
		expect( $sweep_params['options']['include_contexts'] )->toBe( array( 9 ) );
	}
);

test(
	'an unconfigured Context ID leaves the product fetch on the default context',
	function ( $stored ): void {
		skwContextSetSettings( null === $stored ? array() : array( 'context_id' => $stored ) );
		skwContextStubApi( skwContextProductFeed() );

		$result = ( new Skwirrel_WC_Sync_Service() )->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

		expect( $result['success'] )->toBeTrue();

		// AC-3: byte-for-byte what this install sends today.
		$params = skwContextContentFetchParams( 'getProductsByFilter' );
		expect( $params['options']['include_contexts'] )->toBe( array( 1 ) );
	}
)->with(
	array(
		'never set' => array( null ),
		'empty'     => array( '' ),
		'invalid'   => array( 'abc' ),
	)
);

test(
	'categories are fetched from the same context as the products',
	function (): void {
		skwContextSetSettings(
			array(
				'context_id'        => '9',
				'sync_categories'   => true,
				'super_category_id' => '77',
			)
		);
		skwContextStubApi( skwContextProductFeed() );

		( new Skwirrel_WC_Sync_Service() )->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

		// Products from context 9 filed under context 1's category tree is exactly the mixed
		// catalogue FR-20 exists to prevent.
		$params = skwContextContentFetchParams( 'getCategories' );
		expect( $params )->not->toBe( array(), 'the run fetched no categories' );
		expect( $params['include_contexts'] )->toBe( array( 9 ) );
	}
);

test(
	'the status discovery request uses a configured Context ID without changing the default request',
	function ( $stored, $expected ): void {
		skwContextSetSettings( null === $stored ? array() : array( 'context_id' => $stored ) );
		skwContextStubApi( array( 'getProducts' => array( 'products' => array() ) ) );

		$method = new ReflectionMethod( Skwirrel_WC_Sync_Admin_Settings::class, 'fetch_statuses' );
		$method->invoke( Skwirrel_WC_Sync_Admin_Settings::instance(), 1, 10 );

		$params = skwContextContentFetchParams( 'getProducts' );
		if ( null === $expected ) {
			expect( array_key_exists( 'include_contexts', $params ) )->toBeFalse();
		} else {
			expect( $params['include_contexts'] )->toBe( array( $expected ) );
		}
	}
)->with(
	array(
		'configured' => array( '9', 9 ),
		'unset'      => array( null, null ),
		'empty'      => array( '', null ),
		'invalid'    => array( 'abc', null ),
	)
);
