<?php
/**
 * Integration tests for required-field markers and inline validation errors (Story 5.2).
 *
 * The markers and messages are assembled from a registry, a set of render helpers and eight field
 * renderers, so what matters — what a browser and a screen reader actually receive — can only be
 * judged from the rendered screen. These tests render it for a real administrator and parse the
 * result with DOM rather than substring-matching it.
 *
 * NOT covered here (browser-only, and there is no E2E harness in this repo):
 *  - the inline script toggling a marker when a checkbox is ticked (the server-rendered initial
 *    state IS asserted, for both states; the script that maintains it is only asserted present);
 *  - how the marker and the message look, and whether the error colour passes contrast.
 * Those were verified by hand in wp-env; see the story's completion notes.
 */

declare(strict_types=1);

/**
 * Render the settings screen the way an administrator receives it.
 */
function skwRenderRequiredFieldsScreen(): string {
	ob_start();
	( new Skwirrel_WC_Sync_Admin_Dashboard() )->render( 'settings' );

	return (string) ob_get_clean();
}

/**
 * Parse rendered markup into an XPath query object.
 */
function skwRequiredFieldsXPath( string $html ): DOMXPath {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
	libxml_clear_errors();

	return new DOMXPath( $doc );
}

/**
 * The single element carrying an id, or null when the markup has none.
 */
function skwRequiredFieldsElementById( DOMXPath $xpath, string $id ): ?DOMElement {
	$found = $xpath->query( '//*[@id="' . $id . '"]' );
	if ( false === $found || 0 === $found->length ) {
		return null;
	}
	$node = $found->item( 0 );

	return $node instanceof DOMElement ? $node : null;
}

beforeEach( function (): void {
	$admin = wp_insert_user(
		[
			'user_login' => 'skw_required_fields_admin',
			'user_pass'  => wp_generate_password(),
			'role'       => 'administrator',
		]
	);
	$this->admin_id = is_wp_error( $admin ) ? 0 : (int) $admin;
	wp_set_current_user( $this->admin_id );

	// Settings errors are process-global; start every test from a clean slate.
	$GLOBALS['wp_settings_errors'] = [];

	update_option(
		'skwirrel_wc_sync_settings',
		[
			'endpoint_url'         => 'https://example.skwirrel.eu/jsonrpc',
			'sync_categories'      => false,
			'super_category_id'    => '',
			'collection_ids'       => '12, 13',
			'custom_collection_id' => '',
		]
	);
} );

afterEach( function (): void {
	$GLOBALS['wp_settings_errors'] = [];
	delete_option( 'skwirrel_wc_sync_settings' );
	if ( ! empty( $this->admin_id ) ) {
		wp_delete_user( $this->admin_id );
	}
	wp_set_current_user( 0 );
} );

test( 'an unconditionally required field is marked and announced as required', function (): void {
	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );

	foreach ( [ 'skwirrel_subdomain', 'collection_ids' ] as $field ) {
		$input = skwRequiredFieldsElementById( $xpath, $field );
		expect( $input )->not->toBeNull( $field . ' is not rendered' );
		expect( $input->getAttribute( 'aria-required' ) )->toBe( 'true' );
		expect( $input->hasAttribute( 'required' ) )->toBeTrue();

		$marker = $xpath->query( '//*[@data-skw-req="' . $field . '"]' );
		expect( $marker->length )->toBe( 1 );
		expect( $marker->item( 0 )->hasAttribute( 'hidden' ) )->toBeFalse();
	}
} );

test( 'the required marker carries a literal character and a name, not colour alone', function (): void {
	$xpath  = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$marker = $xpath->query( '//*[@data-skw-req="collection_ids"]' )->item( 0 );

	expect( $marker )->toBeInstanceOf( DOMElement::class );
	expect( trim( $marker->textContent ) )->toContain( '*' );

	// The asterisk is hidden from assistive technology and paired with a text name.
	$hidden = $xpath->query( './/*[@aria-hidden="true"]', $marker );
	expect( $hidden->length )->toBe( 1 );
	expect( trim( $hidden->item( 0 )->textContent ) )->toBe( '*' );

	$name = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]', $marker );
	expect( $name->length )->toBe( 1 );
	expect( trim( $name->item( 0 )->textContent ) )->not->toBe( '' );
} );

test( 'super_category_id is not required while category sync is off', function (): void {
	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$input = skwRequiredFieldsElementById( $xpath, 'super_category_id' );

	expect( $input )->not->toBeNull();
	expect( $input->hasAttribute( 'required' ) )->toBeFalse();
	expect( $input->hasAttribute( 'aria-required' ) )->toBeFalse();

	$marker = $xpath->query( '//*[@data-skw-req="super_category_id"]' )->item( 0 );
	expect( $marker->hasAttribute( 'hidden' ) )->toBeTrue();
} );

test( 'super_category_id becomes required as soon as category sync is on', function (): void {
	$opts                    = (array) get_option( 'skwirrel_wc_sync_settings' );
	$opts['sync_categories'] = true;
	update_option( 'skwirrel_wc_sync_settings', $opts );

	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$input = skwRequiredFieldsElementById( $xpath, 'super_category_id' );

	expect( $input->hasAttribute( 'required' ) )->toBeTrue();
	expect( $input->getAttribute( 'aria-required' ) )->toBe( 'true' );
	expect( $xpath->query( '//*[@data-skw-req="super_category_id"]' )->item( 0 )->hasAttribute( 'hidden' ) )->toBeFalse();
} );

test( 'custom_collection_id follows each of the three features that consume it', function (): void {
	foreach ( [ 'sync_custom_classes', 'sync_trade_item_custom_classes', 'sync_grouped_products' ] as $key ) {
		$opts         = (array) get_option( 'skwirrel_wc_sync_settings' );
		$opts[ $key ] = true;
		update_option( 'skwirrel_wc_sync_settings', $opts );

		$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
		expect( skwRequiredFieldsElementById( $xpath, 'custom_collection_id' )->hasAttribute( 'required' ) )
			->toBeTrue( $key . ' does not make custom_collection_id required' );

		unset( $opts[ $key ] );
		update_option( 'skwirrel_wc_sync_settings', $opts );
	}

	// With all three off it is optional again.
	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	expect( skwRequiredFieldsElementById( $xpath, 'custom_collection_id' )->hasAttribute( 'required' ) )->toBeFalse();
} );

test( 'the marker names the settings keys that govern it so the toggle needs no second copy', function (): void {
	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );

	$super = $xpath->query( '//*[@data-skw-req="super_category_id"]' )->item( 0 );
	expect( $super->getAttribute( 'data-skw-req-when' ) )->toBe( 'skwirrel_wc_sync_settings[sync_categories]' );

	$custom = $xpath->query( '//*[@data-skw-req="custom_collection_id"]' )->item( 0 );
	expect( $custom->getAttribute( 'data-skw-req-when' ) )->toBe(
		'skwirrel_wc_sync_settings[sync_custom_classes] '
		. 'skwirrel_wc_sync_settings[sync_trade_item_custom_classes] '
		. 'skwirrel_wc_sync_settings[sync_grouped_products]'
	);

	// Every name it points at resolves to a checkbox that is actually on the screen.
	foreach ( [ $super, $custom ] as $marker ) {
		foreach ( explode( ' ', $marker->getAttribute( 'data-skw-req-when' ) ) as $name ) {
			$box = $xpath->query( '//input[@type="checkbox"][@name="' . $name . '"]' );
			expect( $box->length )->toBe( 1, $name . ' resolves to no checkbox' );
		}
	}

	// An unconditionally required field has nothing to follow.
	$always = $xpath->query( '//*[@data-skw-req="collection_ids"]' )->item( 0 );
	expect( $always->hasAttribute( 'data-skw-req-when' ) )->toBeFalse();
} );

test( 'a validation message renders at its field and is announced with it', function (): void {
	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '12',
		]
	);

	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$input = skwRequiredFieldsElementById( $xpath, 'super_category_id' );

	expect( $input->getAttribute( 'aria-invalid' ) )->toBe( 'true' );

	$described = explode( ' ', $input->getAttribute( 'aria-describedby' ) );
	expect( $described )->toContain( 'super_category_id-error' );

	// Every id it points at exists, so the announcement is not silently empty.
	foreach ( $described as $id ) {
		expect( skwRequiredFieldsElementById( $xpath, $id ) )->not->toBeNull( $id . ' is referenced but not rendered' );
	}

	$message = skwRequiredFieldsElementById( $xpath, 'super_category_id-error' );
	expect( $message->getAttribute( 'role' ) )->toBe( 'alert' );
	expect( trim( $message->textContent ) )->toContain( 'super category ID' );

	// And it sits inside the field block it belongs to, not at the top of the page.
	$block = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " skw-field ")][.//*[@id="super_category_id-error"]]' );
	expect( $block->length )->toBeGreaterThan( 0 );
	expect( $block->item( $block->length - 1 )->getAttribute( 'data-skw-error-field' ) )->toBe( 'super_category_id' );
} );

test( 'a field that passed validation carries no invalid state', function (): void {
	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '12',
		]
	);

	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );

	$passing = skwRequiredFieldsElementById( $xpath, 'collection_ids' );
	expect( $passing->hasAttribute( 'aria-invalid' ) )->toBeFalse();
	expect( skwRequiredFieldsElementById( $xpath, 'collection_ids-error' ) )->toBeNull();
	expect( $xpath->query( '//*[@data-skw-error-field="collection_ids"]' )->length )->toBe( 0 );
} );

test( 'the same message appears once as a summary and once at the field', function (): void {
	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '',
		]
	);

	$html  = skwRenderRequiredFieldsScreen();
	$xpath = skwRequiredFieldsXPath( $html );

	// Both messages reach the summary, including ones that also render inline.
	$summary = skwRequiredFieldsElementById( $xpath, 'skwirrel-settings-errors' );
	expect( $summary )->not->toBeNull();
	expect( $summary->textContent )->toContain( 'super category ID' );
	expect( $summary->textContent )->toContain( 'selection ID' );
	expect( $xpath->query( '//*[@id="setting-error-super_category_id_required"]' )->length )->toBe( 1 );
	expect( $xpath->query( '//*[@id="setting-error-collection_ids_required"]' )->length )->toBe( 1 );

	// And each renders exactly once inline, at its own field.
	expect( $xpath->query( '//*[@id="super_category_id-error"]' )->length )->toBe( 1 );
	expect( $xpath->query( '//*[@id="collection_ids-error"]' )->length )->toBe( 1 );
} );

test( 'every message for one field renders inline and is described by the input', function (): void {
	add_settings_error(
		'skwirrel_wc_sync_settings',
		'collection_ids_required',
		'The first selection problem.',
		'error'
	);
	add_settings_error(
		'skwirrel_wc_sync_settings',
		'collection_ids_required',
		'The second selection problem.',
		'error'
	);

	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$input = skwRequiredFieldsElementById( $xpath, 'collection_ids' );

	expect( skwRequiredFieldsElementById( $xpath, 'collection_ids-error' )->textContent )
		->toContain( 'first selection problem' );
	expect( skwRequiredFieldsElementById( $xpath, 'collection_ids-error-2' )->textContent )
		->toContain( 'second selection problem' );

	$described = explode( ' ', $input->getAttribute( 'aria-describedby' ) );
	expect( $described )->toContain( 'collection_ids-error' );
	expect( $described )->toContain( 'collection_ids-error-2' );
	expect( $described )->toContain( 'collection_ids-hint' );
} );

test( 'an error code with no field mapping still reaches the user through the summary', function (): void {
	add_settings_error(
		'skwirrel_wc_sync_settings',
		'a_rule_nobody_mapped_yet',
		'Something else was rejected.',
		'error'
	);

	$xpath   = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$summary = skwRequiredFieldsElementById( $xpath, 'skwirrel-settings-errors' );

	expect( $summary )->not->toBeNull();
	expect( $summary->textContent )->toContain( 'Something else was rejected.' );
} );

test( 'the failing field ids are exposed to the tab strip as a seam', function (): void {
	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '',
		]
	);

	expect( Skwirrel_WC_Sync_Admin_Settings::failing_field_ids() )
		->toBe( [ 'super_category_id', 'collection_ids' ] );

	// Each id resolves to a marked field block, which resolves to the panel holding it.
	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	foreach ( Skwirrel_WC_Sync_Admin_Settings::failing_field_ids() as $field ) {
		$block = $xpath->query( '//*[@data-skw-error-field="' . $field . '"]' );
		expect( $block->length )->toBe( 1, $field . ' has no marked field block' );
		expect( $xpath->query( 'ancestor::*[@data-skw-panel]', $block->item( 0 ) )->length )
			->toBeGreaterThan( 0, $field . ' is not inside a tab panel' );
	}
} );

test( 'the success notice is suppressed while a validation error stands', function (): void {
	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '12',
		]
	);

	expect( Skwirrel_WC_Sync_Admin_Settings::has_settings_error() )->toBeTrue();

	$_GET['settings-updated'] = 'true';
	$_GET['tab']              = 'settings';
	ob_start();
	Skwirrel_WC_Sync_Admin_Settings::instance()->render_page();
	$html = (string) ob_get_clean();
	unset( $_GET['settings-updated'], $_GET['tab'] );

	expect( $html )->not->toContain( 'Settings saved.' );
	expect( $html )->toContain( 'super category ID' );
} );

test( 'the success notice still shows when the save was clean', function (): void {
	$_GET['settings-updated'] = 'true';
	$_GET['tab']              = 'settings';
	ob_start();
	Skwirrel_WC_Sync_Admin_Settings::instance()->render_page();
	$html = (string) ob_get_clean();
	unset( $_GET['settings-updated'], $_GET['tab'] );

	expect( $html )->toContain( 'Settings saved.' );
} );

/*
 * ---------------------------------------------------------------------------
 * Gap coverage added by the QA E2E pass (bmad-qa-generate-e2e-tests, 2026-08-26).
 *
 * Each test below closes an acceptance-criteria clause that the story's own
 * suite left unasserted. The clause is named in the test's comment.
 * ---------------------------------------------------------------------------
 */

/**
 * AC1 — "the fields that today use a bare HTML5 `required` attribute … are brought into that
 * same treatment rather than left inconsistent."
 *
 * The regression this guards: someone adds `required` straight onto a new input. The form then
 * blocks a submit for a reason no marker announces and no registry knows about.
 */
test( 'no control blocks submit without a marker and a registry entry behind it', function (): void {
	$xpath    = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$registry = Skwirrel_WC_Sync_Admin_Settings::required_fields( (array) get_option( 'skwirrel_wc_sync_settings' ) );

	$controls = $xpath->query( '//input[@required] | //select[@required] | //textarea[@required]' );
	expect( $controls->length )->toBeGreaterThan( 0, 'no required control rendered at all' );

	foreach ( $controls as $control ) {
		$id = $control->getAttribute( 'id' );
		expect( $id )->not->toBe( '', 'a required control has no id, so no marker can address it' );

		expect( array_key_exists( $id, $registry ) )
			->toBeTrue( $id . ' is required in markup but unknown to the registry' );
		expect( $registry[ $id ] )->toBeTrue( $id . ' carries required while the registry says it is optional' );
		expect( $control->getAttribute( 'aria-required' ) )->toBe( 'true', $id . ' is required but not announced' );

		$marker = $xpath->query( '//*[@data-skw-req="' . $id . '"]' );
		expect( $marker->length )->toBe( 1, $id . ' is required but carries no marker' );
		expect( $marker->item( 0 )->hasAttribute( 'hidden' ) )->toBeFalse( $id . '\'s marker is hidden while the field is required' );
	}
} );

/**
 * AC1 / NFR-7 — the `*` is a literal character with an accessible name, on every marked field
 * and not just the one the story's suite sampled.
 */
test( 'every marker on the screen is a named character, not colour alone', function (): void {
	$opts = (array) get_option( 'skwirrel_wc_sync_settings' );
	// Turn everything on so all four markers are visible at once.
	foreach ( [ 'sync_categories', 'sync_custom_classes', 'sync_trade_item_custom_classes', 'sync_grouped_products' ] as $key ) {
		$opts[ $key ] = true;
	}
	update_option( 'skwirrel_wc_sync_settings', $opts );

	$xpath   = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$markers = $xpath->query( '//*[@data-skw-req]' );

	expect( $markers->length )->toBe( 4, 'the four registry fields should each render one marker' );

	foreach ( $markers as $marker ) {
		$field = $marker->getAttribute( 'data-skw-req' );

		$hidden = $xpath->query( './/*[@aria-hidden="true"]', $marker );
		expect( $hidden->length )->toBe( 1, $field . ' has no character hidden from assistive technology' );
		expect( trim( $hidden->item( 0 )->textContent ) )->toBe( '*', $field . '\'s marker is not a literal asterisk' );

		$name = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]', $marker );
		expect( $name->length )->toBe( 1, $field . '\'s marker has no accessible name' );
		expect( trim( $name->item( 0 )->textContent ) )->not->toBe( '', $field . '\'s accessible name is empty' );

		// And the marker sits inside the label of the field it marks, so it is announced with it.
		$label = $xpath->query( 'ancestor::label[@for="' . $field . '"]', $marker );
		expect( $label->length )->toBe( 1, $field . '\'s marker is not inside that field\'s label' );
	}
} );

/**
 * AC2 — "`custom_collection_id`'s label no longer reads '(optional)' while `sanitize_settings()`
 * may reject it as missing."
 */
test( 'no field that validation can reject calls itself optional in its label', function (): void {
	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );

	foreach ( array_keys( Skwirrel_WC_Sync_Admin_Settings::required_fields( [] ) ) as $field ) {
		$label = $xpath->query( '//label[@for="' . $field . '"]' );
		expect( $label->length )->toBe( 1, $field . ' has no label' );
		expect( str_contains( strtolower( $label->item( 0 )->textContent ), 'optional' ) )
			->toBeFalse( $field . ' still advertises itself as optional' );
	}
} );

/**
 * AC2 — the off state is the full off state: no marker, no `required`, and no `aria-required`
 * either. The story's suite asserted only the `required` attribute for this field.
 */
test( 'custom_collection_id carries no required state at all while nothing consumes it', function (): void {
	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$input = skwRequiredFieldsElementById( $xpath, 'custom_collection_id' );

	expect( $input )->not->toBeNull();
	expect( $input->hasAttribute( 'required' ) )->toBeFalse();
	expect( $input->hasAttribute( 'aria-required' ) )->toBeFalse();
	expect( $xpath->query( '//*[@data-skw-req="custom_collection_id"]' )->item( 0 )->hasAttribute( 'hidden' ) )->toBeTrue();

	// And the full on state, for the same field, is the mirror image.
	$opts                        = (array) get_option( 'skwirrel_wc_sync_settings' );
	$opts['sync_custom_classes'] = true;
	update_option( 'skwirrel_wc_sync_settings', $opts );

	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$input = skwRequiredFieldsElementById( $xpath, 'custom_collection_id' );

	expect( $input->hasAttribute( 'required' ) )->toBeTrue();
	expect( $input->getAttribute( 'aria-required' ) )->toBe( 'true' );
	expect( $xpath->query( '//*[@data-skw-req="custom_collection_id"]' )->item( 0 )->hasAttribute( 'hidden' ) )->toBeFalse();
} );

/**
 * AC3 / T4 — "If the field already has a `.skw-field-hint`, reference both ids in
 * `aria-describedby`." A message that silently replaces the hint would still pass the story's
 * existing assertion, which only checks that the error id is among the referenced ids.
 */
test( 'an inline message is added to the field description, not swapped in for the hint', function (): void {
	// Clean render first: a required field with a hint already describes itself by that hint.
	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	expect( skwRequiredFieldsElementById( $xpath, 'collection_ids' )->getAttribute( 'aria-describedby' ) )
		->toBe( 'collection_ids-hint' );

	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '12',
		]
	);

	$xpath     = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$described = explode( ' ', skwRequiredFieldsElementById( $xpath, 'super_category_id' )->getAttribute( 'aria-describedby' ) );

	expect( $described )->toContain( 'super_category_id-error' );
	expect( $described )->toContain( 'super_category_id-hint' );
	// The message is announced before the hint that explains where to find the value.
	expect( array_search( 'super_category_id-error', $described, true ) )
		->toBeLessThan( array_search( 'super_category_id-hint', $described, true ) );

	foreach ( $described as $id ) {
		expect( skwRequiredFieldsElementById( $xpath, $id ) )->not->toBeNull( $id . ' is referenced but not rendered' );
	}
} );

/**
 * AC3 — the third validation rule, which the story's suite never exercised through the screen.
 * All three mapped codes must land at their own field, not just the two that were sampled.
 */
test( 'every mapped error code renders at the field it names', function (): void {
	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'      => '1',
			'sync_grouped_products' => '1',
			'super_category_id'    => '',
			'collection_ids'       => '',
			'custom_collection_id' => '',
		]
	);

	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );

	foreach ( Skwirrel_WC_Sync_Admin_Settings::error_field_map() as $code => $field ) {
		$message = skwRequiredFieldsElementById( $xpath, $field . '-error' );
		expect( $message )->not->toBeNull( $code . ' produced no inline message at ' . $field );
		expect( $message->getAttribute( 'role' ) )->toBe( 'alert', $field . '\'s message is not announced' );
		expect( trim( $message->textContent ) )->not->toBe( '', $field . '\'s message is empty' );

		$input = skwRequiredFieldsElementById( $xpath, $field );
		expect( $input->getAttribute( 'aria-invalid' ) )->toBe( 'true', $field . ' is not marked invalid' );

		$block = $xpath->query( '//*[@data-skw-error-field="' . $field . '"]' );
		expect( $block->length )->toBe( 1, $field . ' has no marked field block' );
		expect( $xpath->query( './/*[@id="' . $field . '-error"]', $block->item( 0 ) )->length )
			->toBe( 1, $field . '\'s message is not inside its own field block' );
	}
} );

/**
 * AC3 / Dev Notes fact 2 — validation is not blocking, and that is deliberate: the rejected
 * value stays in the field so the store owner can see what was wrong and correct it. A future
 * change that starts discarding rejected input would break the fix loop this story depends on.
 */
test( 'a rejected value is kept in the field so it can be corrected', function (): void {
	$settings = Skwirrel_WC_Sync_Admin_Settings::instance();

	$saved = $settings->sanitize_settings(
		[
			'endpoint_url'      => 'https://example.skwirrel.eu/jsonrpc',
			'sync_categories'   => '1',
			'super_category_id' => '0',
			'collection_ids'    => '12',
		]
	);

	// The save went through despite the error — that is the documented behaviour.
	expect( Skwirrel_WC_Sync_Admin_Settings::has_settings_error() )->toBeTrue();
	expect( $saved['super_category_id'] )->toBe( '0' );

	update_option( 'skwirrel_wc_sync_settings', $saved );

	$xpath = skwRequiredFieldsXPath( skwRenderRequiredFieldsScreen() );
	$input = skwRequiredFieldsElementById( $xpath, 'super_category_id' );

	expect( $input->getAttribute( 'value' ) )->toBe( '0' );
	expect( $input->getAttribute( 'aria-invalid' ) )->toBe( 'true' );
} );

/**
 * AC4 / T5 — the seam has two halves and the story's suite asserted only the DOM half. This is
 * the other half: the ids a tab strip reads out of `window.skwirrelPimSync`.
 */
test( 'the failing field ids reach the browser through the localized seam', function (): void {
	$settings = Skwirrel_WC_Sync_Admin_Settings::instance();

	$settings->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '',
		]
	);

	$GLOBALS['wp_scripts'] = new WP_Scripts();
	set_current_screen( 'toplevel_page_skwirrel-pim-sync' );
	$settings->enqueue_assets( 'toplevel_page_skwirrel-pim-sync' );

	$data = (string) wp_scripts()->get_data( 'skwirrel-pim-sync-admin', 'data' );
	expect( $data )->toContain( 'errorFields' );

	// The localized value is the same list, in the same order, the DOM markers carry.
	foreach ( Skwirrel_WC_Sync_Admin_Settings::failing_field_ids() as $field ) {
		expect( str_contains( $data, $field ) )
			->toBeTrue( $field . ' is missing from the localized seam' );
	}
	expect( Skwirrel_WC_Sync_Admin_Settings::failing_field_ids() )
		->toBe( [ 'super_category_id', 'collection_ids' ] );
} );

/**
 * AC4 / T5 — and it is empty on a clean render, so a consumer never opens a tab for an error
 * that is not there.
 */
test( 'the localized seam is empty when nothing failed', function (): void {
	$settings = Skwirrel_WC_Sync_Admin_Settings::instance();

	$GLOBALS['wp_scripts'] = new WP_Scripts();
	set_current_screen( 'toplevel_page_skwirrel-pim-sync' );
	$settings->enqueue_assets( 'toplevel_page_skwirrel-pim-sync' );

	$data = (string) wp_scripts()->get_data( 'skwirrel-pim-sync-admin', 'data' );

	expect( Skwirrel_WC_Sync_Admin_Settings::failing_field_ids() )->toBe( [] );
	expect( $data )->toContain( '"errorFields":[]' );
} );

/**
 * AC2 / T3 — the live toggle. There is no browser harness here, so what is asserted is the
 * property that makes the toggle trustworthy: it reads its conditions off the markup and holds
 * no copy of them. A second copy in JavaScript is exactly the drift this story exists to stop.
 */
test( 'the marker toggle reads its conditions off the markup and keeps no copy of them', function (): void {
	$settings = Skwirrel_WC_Sync_Admin_Settings::instance();

	$GLOBALS['wp_scripts'] = new WP_Scripts();
	set_current_screen( 'toplevel_page_skwirrel-pim-sync' );
	$settings->enqueue_assets( 'toplevel_page_skwirrel-pim-sync' );

	$inline = (array) ( wp_scripts()->get_data( 'skwirrel-pim-sync-admin', 'after' ) ?: [] );
	$joined = implode( "\n", array_map( 'strval', $inline ) );

	// It rides the existing handle — no new asset, per the story's "do not add a JS file".
	expect( wp_scripts()->query( 'skwirrel-pim-sync-admin', 'registered' ) )->not->toBeFalse();
	foreach ( array_keys( wp_scripts()->registered ) as $handle ) {
		expect( $handle )->not->toContain( 'required-fields' );
	}

	// It finds markers by attribute and follows the keys they name.
	expect( $joined )->toContain( 'data-skw-req-when' );
	expect( $joined )->toContain( 'data-skw-req' );
	expect( $joined )->toContain( 'addEventListener("change"' );
	expect( $joined )->toContain( 'aria-required' );

	// And it holds none of the conditions itself: no governing settings key appears in any script.
	foreach ( array_merge( ...array_values( Skwirrel_WC_Sync_Admin_Settings::conditional_required_rules() ) ) as $key ) {
		expect( str_contains( $joined, $key ) )
			->toBeFalse( $key . ' is restated in JavaScript instead of read from the markup' );
	}
} );

/**
 * T6 / AC1 — the two new classes exist in the sheet the screen actually loads, and the hidden
 * marker is hidden by CSS as well as by the attribute.
 */
test( 'the marker and message styles ship in the dashboard sheet', function (): void {
	$dashboard_css = (string) file_get_contents( SKWIRREL_WC_SYNC_PLUGIN_DIR . 'assets/dashboard.css' );

	foreach ( [ '.skw-req', '.skw-req[hidden]', '.skw-field-error' ] as $selector ) {
		expect( $dashboard_css )->toContain( $selector );
	}
} );
