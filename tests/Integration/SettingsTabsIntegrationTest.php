<?php
/**
 * Integration tests for the tabbed settings screen (Story 5.1).
 *
 * The settings screen groups eight field groups into four tabs. The refactor is only safe if the
 * rendered form is unchanged as a *payload*: every input still present, still inside the one
 * `options.php` form, never disabled, never removed from the DOM. That cannot be judged from the
 * source alone — the markup is assembled from a registry, a panel loop and eight renderers — so
 * these tests render the real screen for a real administrator and assert against the HTML.
 *
 * What is asserted is the OUTCOME the browser would submit, not the calls the plugin makes:
 *  - every input `name` the pre-tabs screen rendered is still rendered (fixture below, captured
 *    from the pre-change revision 0f7c3c4);
 *  - every panel sits between the form's open and close tags, with no `disabled` attribute;
 *  - the tab strip is outside the form and its buttons are `type="button"` (a bare button inside
 *    a form submits it);
 *  - every `aria-controls` resolves to a panel that exists, and back via `aria-labelledby`;
 *  - the danger zone is still outside and below the form;
 *  - the element IDs the inline admin script binds to all still exist;
 *  - a full submit round-trips through `sanitize_settings()` with values from all four tabs intact;
 *  - the AC 1 re-home map, the no-JS baseline, the error notice, and a tab registered from outside
 *    (added by the QA E2E-test pass — see the second block at the bottom of this file).
 *
 * NOT covered here (browser-only, deliberately left out rather than faked — there is no E2E
 * harness in this repo):
 *  - the tab strip actually switching panels when clicked, and Left/Right/Home/End moving between
 *    tabs (the *initial* roving-tabindex state the server renders IS asserted; the interaction
 *    that maintains it is not);
 *  - how the focus ring looks;
 *  - `#tab-{slug}` deep-linking and the `history.replaceState` rewrite actually running (the
 *    script that implements them is asserted to be present on the existing handle, which is not
 *    the same as asserting it works);
 *  - native validation of a `required` control inside a hidden panel.
 * Those were verified by hand in wp-env; see the story's completion notes.
 */

declare(strict_types=1);

/**
 * Input names the settings form rendered before the tab refactor.
 *
 * Captured from `render_page_settings()` at revision 0f7c3c4 (pre-change) and resolved against
 * `OPTION_KEY`. If a field is legitimately added or removed later, this list changes with it —
 * deliberately, in the same commit.
 *
 * @return string[]
 */
function skwSettingsBaselineInputNames(): array {
	$keys = [
		'auth_type',
		'batch_size',
		'collection_ids',
		'custom_class_filter_ids',
		'custom_class_filter_mode',
		'custom_collection_id',
		'deprecated_remove_after_syncs',
		'endpoint_url',
		'image_language_custom',
		'image_language_select',
		'include_languages_custom',
		'log_mode_manual',
		'log_mode_scheduled',
		'log_retention',
		'prices_managed_outside_skwirrel',
		'protect_from_deletion',
		'purge_stale_products',
		'related_products_type',
		'retries',
		'show_delete_warning',
		'show_gtin_attribute',
		'show_variant_attribute',
		'status_mapping_default',
		'super_category_id',
		'sync_categories',
		'sync_custom_classes',
		'sync_grouped_products',
		'sync_images',
		'sync_interval',
		'sync_manufacturers',
		'sync_related_products',
		'sync_trade_item_custom_classes',
		'timeout',
		'use_sku_field',
		'use_virtual_product_content',
		'variant_label_field',
		'verbose_logging',
	];

	$names = [];
	foreach ( $keys as $key ) {
		$names[] = 'skwirrel_wc_sync_settings[' . $key . ']';
	}

	// Multi-value field.
	$names[] = 'skwirrel_wc_sync_settings[include_languages_checkboxes][]';

	// The token input only renders when the WP 7.0 Connectors API is NOT handling the credential;
	// with Connectors registered the group shows a link to the connector instead. Pre-existing
	// behaviour, unrelated to tabs — so only demand the field on the branch that renders it.
	if ( ! class_exists( 'Skwirrel_WC_Sync_Connectors' ) || ! Skwirrel_WC_Sync_Connectors::is_registered() ) {
		$names[] = 'skwirrel_wc_sync_settings[auth_token]';
	}

	return $names;
}

/**
 * Render the settings view the way an admin request does.
 */
function skwRenderSettingsScreen(): string {
	ob_start();
	( new Skwirrel_WC_Sync_Admin_Dashboard() )->render( 'settings' );

	return (string) ob_get_clean();
}

/**
 * Render a field-mapping panel through the public settings-tabs filter contract.
 *
 * @param array<string, mixed> $context Render context supplied by the dashboard.
 */
function skwRenderExternalSettingsTab( array $context ): void {
	echo '<div class="skw-fieldgroup"><h3 class="skw-fieldgroup-title">Epic 6 mapping</h3>'
		. '<input type="text" name="skwirrel_wc_sync_settings[mapping_source]" id="mapping_source" required />'
		. '</div>';
}

beforeEach( function (): void {
	// No WP_UnitTestCase factory here — the Integration suite is not actually bound to it.
	$admin = wp_insert_user(
		[
			'user_login' => 'skw_settings_tabs_admin',
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
			// Connection.
			'endpoint_url'         => 'https://example.skwirrel.eu/jsonrpc',
			'timeout'              => 45,
			'retries'              => 4,
			// What to sync.
			'sync_categories'      => true,
			'super_category_id'    => '77',
			'collection_ids'       => '12, 13',
			'custom_collection_id' => '99',
			// How it looks.
			'image_language'       => 'de',
			'include_languages'    => [ 'de-DE', 'de' ],
			// Advanced.
			'sync_interval'        => 'daily',
			'batch_size'           => 50,
			'verbose_logging'      => true,
		]
	);
} );

afterEach( function (): void {
	// The integration suite has no DB isolation — clean up explicitly.
	delete_option( 'skwirrel_wc_sync_settings' );
	if ( $this->admin_id > 0 ) {
		wp_delete_user( $this->admin_id );
	}
	wp_set_current_user( 0 );
	$GLOBALS['wp_settings_errors'] = [];
} );

test( 'every input name the pre-tabs settings form rendered is still rendered', function (): void {
	$html = skwRenderSettingsScreen();

	preg_match_all( '/\bname="(skwirrel_wc_sync_settings\[[^"]+\])"/', $html, $matches );
	$actual = array_map(
		static fn ( string $name ): string => (string) preg_replace(
			'/\[status_mapping\]\[[^\]]+\]/',
			'[status_mapping][*]',
			$name
		),
		$matches[1]
	);
	$actual = array_values( array_unique( $actual ) );
	sort( $actual );

	$expected   = skwSettingsBaselineInputNames();
	$expected[] = 'skwirrel_wc_sync_settings[status_mapping][*]';
	// Added to the API Connection group after the pre-tabs baseline was captured: Story 5.3's
	// optional Context ID. The baseline list stays a record of what the pre-tabs form rendered.
	$expected[] = 'skwirrel_wc_sync_settings[context_id]';
	$expected   = array_values( array_unique( $expected ) );
	sort( $expected );

	expect( $actual )->toBe( $expected );

	// Per-status rows are data-driven; the three built-in statuses always render.
	$mapping_names = array_filter(
		$matches[1],
		static fn ( string $name ): bool => str_starts_with( $name, 'skwirrel_wc_sync_settings[status_mapping][' )
	);
	expect( $mapping_names )->toHaveCount( count( array_unique( $mapping_names ) ) );
	expect( count( $mapping_names ) )->toBeGreaterThanOrEqual( 3 );

	foreach ( $expected as $name ) {
		if ( str_contains( $name, '[status_mapping][*]' ) ) {
			continue;
		}
		expect( $html )->toContain( 'name="' . $name . '"' );
	}
} );

test( 'the four tabs each own a panel, and every panel lives inside the one settings form', function (): void {
	$html = skwRenderSettingsScreen();

	preg_match_all( '/<button\b[^>]*role="tab"[^>]*>/', $html, $tab_matches );
	preg_match_all( '/<div\b[^>]*role="tabpanel"[^>]*>/', $html, $panel_matches );

	expect( $tab_matches[0] )->toHaveCount( 4 );
	expect( $panel_matches[0] )->toHaveCount( 4 );

	$form_open  = strpos( $html, '<form method="post" action="options.php"' );
	$form_close = strpos( $html, '</form>', (int) $form_open );
	expect( $form_open )->not->toBeFalse();
	expect( $form_close )->not->toBeFalse();

	foreach ( [ 'connection', 'what-to-sync', 'how-it-looks', 'advanced' ] as $slug ) {
		$panel_at = strpos( $html, 'id="panel-' . $slug . '"' );
		expect( $panel_at )->not->toBeFalse();
		expect( $panel_at )->toBeGreaterThan( (int) $form_open );
		expect( $panel_at )->toBeLessThan( (int) $form_close );
	}

	// Hidden, never removed and never disabled — a dropped or disabled field is not submitted, and
	// sanitize_settings() reads absent checkboxes as "off".
	expect( $html )->not->toMatch( '/<(?:input|select|textarea)\b[^>]*\bdisabled\b/' );
} );

test( 'the tab strip sits outside the form and cannot submit it', function (): void {
	$html = skwRenderSettingsScreen();

	$strip_at  = strpos( $html, '<div class="skw-tabs"' );
	$form_open = strpos( $html, '<form method="post" action="options.php"' );

	expect( $strip_at )->not->toBeFalse();
	expect( $strip_at )->toBeLessThan( (int) $form_open );

	preg_match_all( '/<button\b[^>]*role="tab"[^>]*>/', $html, $matches );
	foreach ( $matches[0] as $button ) {
		expect( $button )->toContain( 'type="button"' );
	}
} );

test( 'the ARIA wiring resolves in both directions and exactly one tab is selected', function (): void {
	$html = skwRenderSettingsScreen();

	expect( $html )->toContain( 'role="tablist"' );

	preg_match_all( '/aria-controls="(panel-[^"]+)"/', $html, $controls );
	expect( $controls[1] )->toHaveCount( 4 );

	foreach ( $controls[1] as $panel_id ) {
		$slug = substr( $panel_id, strlen( 'panel-' ) );
		expect( $html )->toContain( 'id="' . $panel_id . '"' );
		expect( $html )->toContain( 'aria-labelledby="tab-' . $slug . '"' );
		expect( $html )->toContain( 'id="tab-' . $slug . '"' );
	}

	expect( substr_count( $html, 'aria-selected="true"' ) )->toBe( 1 );
	expect( substr_count( $html, 'aria-selected="false"' ) )->toBe( 3 );
	expect( substr_count( $html, 'tabindex="0"' ) )->toBeGreaterThanOrEqual( 1 );
	expect( substr_count( $html, 'tabindex="-1"' ) )->toBe( 3 );
} );

test( 'the danger zone stays outside and below the settings form', function (): void {
	$html = skwRenderSettingsScreen();

	$form_close = strpos( $html, '</form>' );
	$danger_at  = strpos( $html, 'id="skwirrel-danger-zone"' );

	expect( $danger_at )->not->toBeFalse();
	expect( $danger_at )->toBeGreaterThan( (int) $form_close );
	expect( $html )->toContain( 'id="skwirrel-purge-form"' );
	expect( $html )->toContain( 'id="skwirrel-reset-settings-form"' );
} );

test( 'every element the inline admin script binds by ID still exists', function (): void {
	$html = skwRenderSettingsScreen();

	$ids = [
		'skwirrel-sync-settings-form',
		'skwirrel_subdomain',
		'endpoint_url',
		'skwirrel-test-connection',
		'skwirrel-test-result',
		'image_language_select',
		'image_language_custom_wrap',
		'image_language_custom',
		'skwirrel-refresh-statuses',
		'skwirrel-refresh-statuses-msg',
		'skwirrel-update-slug-resync',
		'skwirrel-purge-form',
		'skwirrel-purge-permanent',
		'skwirrel-reset-settings-form',
	];

	$missing = [];
	foreach ( $ids as $id ) {
		if ( ! str_contains( $html, 'id="' . $id . '"' ) ) {
			$missing[] = $id;
		}
	}

	expect( $missing )->toBe( [] );
} );

test( 'the permalinks group renders its own AJAX control and submits nothing with the form', function (): void {
	$html = skwRenderSettingsScreen();

	// It is a read-only summary plus one select saved over its own AJAX action; it must not have
	// grown a name attribute that would post into the settings option.
	expect( $html )->toContain( 'id="skwirrel-update-slug-resync"' );
	expect( $html )->not->toContain( 'skwirrel_wc_sync_settings[update_slug_on_resync]' );
} );

test( 'saving from a non-default tab leaves unrelated stored settings unchanged', function (): void {
	$settings = Skwirrel_WC_Sync_Admin_Settings::instance();
	$before   = get_option( 'skwirrel_wc_sync_settings' );

	// The active tab lives only in the URL fragment and is never part of the request. This is what
	// the browser posts while a non-default panel is active: controls from all four panels.
	$input = [
		'endpoint_url'                  => 'https://example.skwirrel.eu/jsonrpc',
		'auth_type'                     => 'token',
		'auth_token'                    => '••••••••',
		'timeout'                       => '60',
		'retries'                       => '4',
		'sync_categories'               => '1',
		'super_category_id'             => '77',
		'collection_ids'                => '12, 13',
		'custom_collection_id'          => '99',
		'sync_custom_classes'           => '1',
		'image_language_select'         => 'de',
		'include_languages_checkboxes'  => [ 'de-DE', 'de' ],
		'sync_interval'                 => 'daily',
		'batch_size'                    => '50',
		'verbose_logging'               => '1',
	];

	$out = $settings->sanitize_settings( $input );
	update_option( 'skwirrel_wc_sync_settings', $out );
	$stored = get_option( 'skwirrel_wc_sync_settings' );

	// The edited Connection value changes, while unrelated values stored from every other tab stay put.
	expect( $stored['timeout'] )->toBe( 60 );
	expect( $stored['super_category_id'] )->toBe( $before['super_category_id'] );
	expect( $stored['collection_ids'] )->toBe( $before['collection_ids'] );
	expect( $stored['image_language'] )->toBe( $before['image_language'] );
	expect( $stored['sync_interval'] )->toBe( $before['sync_interval'] );
	expect( $stored['verbose_logging'] )->toBe( $before['verbose_logging'] );

	// And no settings error was raised for a field that was present but sits on another tab.
	$codes = array_column( get_settings_errors( 'skwirrel_wc_sync_settings' ), 'code' );
	expect( $codes )->not->toContain( 'super_category_id_required' );
	expect( $codes )->not->toContain( 'custom_collection_id_required' );
} );

test( 'a tab holding a failing field is marked with a count, not colour alone', function (): void {
	// Category sync on with no super category is the sanitiser rule that fires on What to sync.
	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '12',
		]
	);

	$html = skwRenderSettingsScreen();

	// The marker is rendered server-side, so it is there with JS off too.
	expect( $html )->toContain( 'data-skw-errors="1"' );
	expect( $html )->toContain( 'skw-tab-badge' );
	expect( str_contains( $html, 'field needs attention' ) || str_contains( $html, 'fields need attention' ) )->toBeTrue();

	// The errored tab is the one the server pre-selects.
	expect( $html )->toMatch( '/id="tab-what-to-sync"[^>]*aria-selected="true"|aria-selected="true"[^>]*id="tab-what-to-sync"/s' );
} );

/*
 * ---------------------------------------------------------------------------------------------
 * Gaps found by the QA E2E-test pass (bmad-qa-generate-e2e-tests, story 5.1).
 *
 * The tests above prove the payload is unchanged. These prove the things the acceptance criteria
 * asked for that nothing was checking: the re-home map itself (AC 1), the submit button staying
 * out of the panels (AC 1), the no-JS baseline (AC 5), the error notice (AC 3), the roving
 * tabindex belonging to the selected tab (AC 6), and a tab registered from outside actually
 * rendering (AC 7).
 *
 * These parse the rendered screen with DOM rather than substring-matching it, so "inside panel X"
 * means containment in the tree, not an offset comparison.
 * ---------------------------------------------------------------------------------------------
 */

/**
 * Parse the rendered settings screen into an XPath query object.
 */
function skwSettingsXPath( ?string $html = null ): DOMXPath {
	$html = null === $html ? skwRenderSettingsScreen() : $html;

	$doc      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	// The fragment is a partial document; wrap it so DOM has a single root and a known encoding.
	$doc->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	return new DOMXPath( $doc );
}

test( 'each of the eight field groups sits in exactly the tab the re-home map names', function (): void {
	$xpath = skwSettingsXPath();

	// AC 1's table, by the group's own heading text.
	$map = [
		'API Connection'           => 'connection',
		'Sync Options'             => 'what-to-sync',
		'Product status handling'  => 'what-to-sync',
		'Media & Language'         => 'how-it-looks',
		'Permalinks'               => 'how-it-looks',
		'Scheduling'               => 'advanced',
		'Sync Logs'                => 'advanced',
		'Advanced'                 => 'advanced',
	];

	foreach ( $map as $title => $slug ) {
		$headings = $xpath->query(
			'//h3[contains(concat(" ", normalize-space(@class), " "), " skw-fieldgroup-title ")]'
			. '[normalize-space(text()) = ' . skwXpathLiteral( $title ) . ']'
		);

		expect( $headings->length )->toBe( 1, "field group '{$title}' should render exactly once" );

		$panel = $headings->item( 0 );
		while ( $panel instanceof DOMElement && 'tabpanel' !== $panel->getAttribute( 'role' ) ) {
			$panel = $panel->parentNode;
		}

		expect( $panel )->toBeInstanceOf( DOMElement::class, "field group '{$title}' is not inside a tabpanel" );
		expect( $panel->getAttribute( 'id' ) )->toBe( 'panel-' . $slug, "field group '{$title}' landed on the wrong tab" );
	}

	// And nothing else crept into a panel as a ninth group.
	$groups = $xpath->query( '//div[@role="tabpanel"]//h3[contains(concat(" ", normalize-space(@class), " "), " skw-fieldgroup-title ")]' );
	expect( $groups->length )->toBe( 8 );
} );

/**
 * Quote a string for use as an XPath literal (titles contain no quotes today, but do contain `&`).
 */
function skwXpathLiteral( string $value ): string {
	if ( ! str_contains( $value, "'" ) ) {
		return "'" . $value . "'";
	}

	return 'concat("' . str_replace( "'", '", \'\'\', "', $value ) . '")';
}

test( 'the save button is inside the form but outside every panel, so it shows on every tab', function (): void {
	$xpath = skwSettingsXPath();

	$buttons = $xpath->query( '//form[@id="skwirrel-sync-settings-form"]//button[@type="submit"]' );
	expect( $buttons->length )->toBe( 1 );

	$inside_panel = $xpath->query( '//div[@role="tabpanel"]//button[@type="submit"]' );
	expect( $inside_panel->length )->toBe( 0 );
} );

test( 'with no JavaScript every panel is a visible sequential section', function (): void {
	$xpath = skwSettingsXPath();

	// AC 5: the collapse is applied by script. Server-side, nothing is hidden — otherwise a
	// no-JS admin loses three quarters of the settings screen.
	$panels = $xpath->query( '//div[@role="tabpanel"]' );
	expect( $panels->length )->toBe( 4 );

	foreach ( $panels as $panel ) {
		expect( $panel->hasAttribute( 'hidden' ) )->toBeFalse(
			'panel ' . $panel->getAttribute( 'id' ) . ' is hidden before the script runs'
		);
		expect( $panel->getAttribute( 'style' ) )->not->toContain( 'display' );
		expect( $panel->getAttribute( 'class' ) )->not->toContain( 'hidden' );
	}
} );

test( 'the roving tabindex belongs to the selected tab, and only to it', function (): void {
	$xpath = skwSettingsXPath();

	$tabs = $xpath->query( '//*[@role="tab"]' );
	expect( $tabs->length )->toBe( 4 );

	$selected = 0;
	foreach ( $tabs as $tab ) {
		$is_selected = 'true' === $tab->getAttribute( 'aria-selected' );
		expect( $tab->getAttribute( 'tabindex' ) )->toBe( $is_selected ? '0' : '-1' );
		$selected += $is_selected ? 1 : 0;
	}

	expect( $selected )->toBe( 1 );

	// The tablist is the strip itself, and it is labelled.
	$list = $xpath->query( '//*[@role="tablist"]' );
	expect( $list->length )->toBe( 1 );
	expect( $list->item( 0 )->getAttribute( 'aria-label' ) )->not->toBe( '' );
} );

test( 'a sanitiser error is readable on the page, above the tab strip', function (): void {
	Skwirrel_WC_Sync_Admin_Settings::instance()->sanitize_settings(
		[
			'sync_categories'   => '1',
			'super_category_id' => '',
			'collection_ids'    => '12',
		]
	);

	$html  = skwRenderSettingsScreen();
	$xpath = skwSettingsXPath( $html );

	// AC 3: the message itself must render — before this story nothing on this screen read the
	// settings_errors transient, so the three sanitiser messages were invisible.
	$notice = $xpath->query( '//*[@id="skwirrel-settings-errors"]' );
	expect( $notice->length )->toBe( 1 );
	expect( trim( $notice->item( 0 )->textContent ) )->not->toBe( '' );

	// And it is above the strip, not buried inside a panel the reader may not be on.
	expect( strpos( $html, 'id="skwirrel-settings-errors"' ) )
		->toBeLessThan( (int) strpos( $html, '<div class="skw-tabs"' ) );
	expect( $xpath->query( '//div[@role="tabpanel"]//*[@id="skwirrel-settings-errors"]' )->length )->toBe( 0 );
} );

test( 'a clean render carries no error notice and no badge', function (): void {
	$xpath = skwSettingsXPath();

	expect( $xpath->query( '//*[@id="skwirrel-settings-errors"]' )->length )->toBe( 0 );
	expect( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " skw-tab-badge ")]' )->length )->toBe( 0 );
	expect( $xpath->query( '//*[@role="tab"][@data-skw-errors]' )->length )->toBe( 0 );

	// With nothing wrong, the first registered tab opens.
	$selected = $xpath->query( '//*[@role="tab"][@aria-selected="true"]' );
	expect( $selected->item( 0 )->getAttribute( 'id' ) )->toBe( 'tab-connection' );
} );

test( 'a tab registered with a named external renderer renders in its order position without touching the loop', function (): void {
	$filter = static function ( array $tabs ): array {
		$tabs['field-mapping'] = [
			'label'  => 'Field mapping',
			'order'  => 25,
			'render' => 'skwRenderExternalSettingsTab',
			'fields' => [ 'mapping_source' ],
		];

		return $tabs;
	};

	add_filter( 'skwirrel_wc_sync_settings_tabs', $filter );

	try {
		$xpath = skwSettingsXPath();

		$tabs = $xpath->query( '//*[@role="tab"]' );
		expect( $tabs->length )->toBe( 5 );

		$slugs = [];
		foreach ( $tabs as $tab ) {
			$slugs[] = $tab->getAttribute( 'data-skw-tab' );
		}

		// AC 7: order comes from the registration, not from hash order.
		expect( $slugs )->toBe( [ 'connection', 'what-to-sync', 'field-mapping', 'how-it-looks', 'advanced' ] );

		// The panel exists, is wired to its tab, and holds what the outside renderer echoed.
		$panel = $xpath->query( '//div[@role="tabpanel"][@id="panel-field-mapping"]' );
		expect( $panel->length )->toBe( 1 );
		expect( $panel->item( 0 )->getAttribute( 'aria-labelledby' ) )->toBe( 'tab-field-mapping' );
		expect( $panel->item( 0 )->textContent )->toContain( 'Epic 6 mapping' );

		// And it submits with everything else: inside the one form, not disabled.
		$input = $xpath->query( '//form[@id="skwirrel-sync-settings-form"]//input[@id="mapping_source"]' );
		expect( $input->length )->toBe( 1 );
		expect( $input->item( 0 )->hasAttribute( 'disabled' ) )->toBeFalse();
	} finally {
		remove_filter( 'skwirrel_wc_sync_settings_tabs', $filter );
	}
} );

test( 'an outside tab whose errors fire opens first, ahead of the built-in tabs', function (): void {
	$filter = static function ( array $tabs ): array {
		$tabs['field-mapping'] = [
			'label'  => 'Field mapping',
			'order'  => 5,
			'render' => static function ( array $context ): void {
				echo '<div class="skw-fieldgroup"><p>mapping</p></div>';
			},
			'fields' => [ 'mapping_source' ],
		];

		return $tabs;
	};

	add_filter( 'skwirrel_wc_sync_settings_tabs', $filter );
	add_settings_error( 'skwirrel_wc_sync_settings', 'mapping_source_required', 'Pick a mapping source.', 'error' );

	try {
		$xpath = skwSettingsXPath();

		$selected = $xpath->query( '//*[@role="tab"][@aria-selected="true"]' );
		expect( $selected->length )->toBe( 1 );
		expect( $selected->item( 0 )->getAttribute( 'data-skw-tab' ) )->toBe( 'field-mapping' );
		expect( $selected->item( 0 )->getAttribute( 'data-skw-errors' ) )->toBe( '1' );
	} finally {
		remove_filter( 'skwirrel_wc_sync_settings_tabs', $filter );
	}
} );

test( 'the tab behaviour rides on the existing admin script handle, adding no new asset', function (): void {
	$settings = Skwirrel_WC_Sync_Admin_Settings::instance();

	$GLOBALS['wp_scripts'] = new WP_Scripts();
	set_current_screen( 'toplevel_page_skwirrel-pim-sync' );
	$settings->enqueue_assets( 'toplevel_page_skwirrel-pim-sync' );

	$scripts = wp_scripts();
	expect( $scripts->query( 'skwirrel-pim-sync-admin', 'registered' ) )->not->toBeFalse();

	$inline = (array) ( $scripts->get_data( 'skwirrel-pim-sync-admin', 'after' ) ?: [] );
	$joined = implode( "\n", array_map( 'strval', $inline ) );

	// T3: the ~60 lines of tab behaviour live on the existing handle, not a new one.
	expect( $joined )->toContain( '.skw-tabs' );
	expect( $joined )->toContain( 'data-skw-tab' );
	expect( $joined )->toContain( '#tab-' );          // AC 4 — fragment, never a query var.
	expect( $joined )->toContain( 'replaceState' );
	expect( $joined )->toContain( 'ArrowRight' );     // AC 6 — keyboard roving.
	expect( $joined )->toContain( ':invalid' );       // AC 5/8 — required control on a hidden panel.

	// AC 4's collision: the fragment mechanism must never write a `tab=` query var.
	expect( $joined )->not->toContain( '?tab=' );
	expect( $joined )->not->toContain( '&tab=' );

	foreach ( array_keys( $scripts->registered ) as $handle ) {
		expect( $handle )->not->toContain( 'settings-tabs' );
	}
} );

test( 'the tab styling reuses the skw-* sheet and never touches the legacy form-table sheet', function (): void {
	$dashboard_css = (string) file_get_contents( SKWIRREL_WC_SYNC_PLUGIN_DIR . 'assets/dashboard.css' );
	$admin_css     = (string) file_get_contents( SKWIRREL_WC_SYNC_PLUGIN_DIR . 'assets/admin.css' );

	foreach ( [ '.skw-tabs', '.skw-tab', '.skw-tabpanel', '.skw-tab-badge', '.skw-notice-error' ] as $selector ) {
		expect( $dashboard_css )->toContain( $selector );
	}

	// AC 6: a visible focus ring, not the browser default removed.
	expect( $dashboard_css )->toContain( '.skw-tab:focus-visible' );
	expect( $dashboard_css )->toContain( '@media (forced-colors: active)' );
	expect( $dashboard_css )->toContain( '.skw-tabpanel[hidden]' );

	// T5: admin.css is the legacy form-table sheet and stays out of this.
	expect( $admin_css )->not->toContain( 'skw-tab' );
} );
