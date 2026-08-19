<?php
/**
 * Integration tests for the top-level "Skwirrel" admin menu (3.13.0).
 *
 * The screen moved out of the WooCommerce submenu into its own top-level menu. Everything that
 * decides whether that menu shows up in the right place, with the right children, and highlights
 * the right child, is core's — `wp-admin/menu.php`, `wp-admin/includes/menu.php`,
 * `wp-admin/includes/plugin.php` and `wp-admin/menu-header.php`. None of it can be judged from the
 * plugin source alone, so these tests build the admin menu the way an actual admin request does
 * and assert against the resulting globals.
 *
 * What is asserted is the OUTCOME core produces, not the calls the plugin makes:
 *  - the resolved position of our entry in `$menu`, and its order relative to the WooCommerce
 *    cluster and to core's separator2/Appearance, AFTER `custom_menu_order`/`menu_order` have run
 *    (WooCommerce reorders the top level, so the raw positions are not the rendered order);
 *  - the four submenu rows core ends up with, including that the first one is the RENAMED parent;
 *  - that `get_admin_page_parent()` — what core actually calls to decide which top-level menu owns
 *    the current screen — resolves to our menu, with and without the WooCommerce signpost;
 *  - that `highlight_active_tab()` returns a value that is genuinely present in `$submenu`, which
 *    is what `wp-admin/menu-header.php` compares `$submenu_file` against when picking `.current`.
 *
 * NOT covered here (browser-only, deliberately left out rather than faked):
 *  - that the menu renders, that the dashicon paints, and that the `.current` class ends up on the
 *    right row: that needs `_wp_menu_output()` against a real request with an admin header.
 *  - the `#skwirrel-sync-now` fragment actually scrolling to the Sync Now block.
 */

declare(strict_types=1);

/**
 * Make sure WooCommerce's own menu registrations are hooked.
 *
 * WooCommerce only wires `WC_Admin_Menus` when it considers the request an admin request, and the
 * WP test bootstrap is not one, so whether those hooks survive to test time is not something a
 * test may assume. Re-including the class file is WooCommerce's own idempotent entry point: the
 * file short-circuits to `return new WC_Admin_Menus()` when the class already exists, and the
 * constructor is what registers both the `admin_menu` callbacks and the `custom_menu_order` /
 * `menu_order` filters that decide the rendered order of the top level.
 */
function skwAdminMenuEnsureWooCommerce(): void {
	if ( class_exists( 'WC_Admin_Menus', false ) ) {
		foreach ( $GLOBALS['wp_filter']['admin_menu']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WC_Admin_Menus ) {
					return;
				}
			}
		}
	}

	include WC_ABSPATH . 'includes/admin/class-wc-admin-menus.php';
}

/**
 * The globals `wp-admin/menu.php` and `add_menu_page()` write to.
 *
 * @return string[]
 */
function skwAdminMenuGlobals(): array {
	return [ 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_real_parent_file', '_wp_submenu_nopriv', '_wp_menu_nopriv', '_wp_last_object_menu' ];
}

/**
 * Core's menu as it stands with no plugin having registered anything yet.
 *
 * `wp-admin/menu.php` declares `_add_themes_utility_last()` and hands off to
 * `wp-admin/includes/menu.php`, which declares three more functions — so PHP can load that path
 * exactly once per process, while these tests need a fresh build per scenario. It is therefore run
 * once here with every `admin_menu` callback detached, which yields core's own baseline plus the
 * helper functions the rest of the pipeline needs (`sort_menu()` in particular). Each test then
 * restores that baseline and fires `admin_menu` itself.
 *
 * @return array<string, mixed>
 */
function skwAdminMenuCoreBaseline(): array {
	// `wp-admin/menu.php` and the `wp-admin/includes/menu.php` it hands off to are written to run
	// in global scope: they assign to these by plain variable name. Requiring them from inside a
	// function without importing them first builds the whole menu into function locals and throws
	// it away — and leaves core's own `sort_menu()` comparator reading nulls.
	global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages,
		$_wp_real_parent_file, $_wp_submenu_nopriv, $_wp_menu_nopriv, $_wp_last_object_menu,
		$_wp_last_utility_menu, $compat, $menu_order, $default_menu_order;

	static $baseline = null;

	if ( null === $baseline ) {
		skwAdminMenuEnsureWooCommerce();

		// Snapshot at the first thing `admin_menu` does, before any plugin has registered and
		// before core sorts. Taking it after the require instead would capture a `$menu` that
		// core has already reindexed, which throws away the numeric positions the whole ordering
		// contract is built on.
		$capture = static function () use ( &$baseline ): void {
			$baseline = [];
			foreach ( skwAdminMenuGlobals() as $global ) {
				$baseline[ $global ] = $GLOBALS[ $global ] ?? [];
			}
		};
		add_action( 'admin_menu', $capture, -PHP_INT_MAX );

		foreach ( skwAdminMenuGlobals() as $global ) {
			$GLOBALS[ $global ] = [];
		}
		$GLOBALS['_wp_last_object_menu'] = 25;

		require ABSPATH . 'wp-admin/menu.php';

		remove_action( 'admin_menu', $capture, -PHP_INT_MAX );
	}

	return $baseline;
}

/**
 * Re-run the pass that turns the registration order into the RENDERED order.
 *
 * `wp-admin/includes/menu.php` sorts `$menu` by key and then, when a plugin opts in via
 * `custom_menu_order`, hands the slug list to the `menu_order` filter and re-sorts with core's own
 * `sort_menu()` comparator. WooCommerce opts in and moves its own cluster, so raw positions are
 * not what an admin actually sees — anything asserting order has to go through this.
 */
function skwAdminMenuApplyRenderOrder(): void {
	global $menu;

	uksort( $menu, 'strnatcasecmp' );

	if ( ! apply_filters( 'custom_menu_order', false ) || ! function_exists( 'sort_menu' ) ) {
		return;
	}

	$order = [];
	foreach ( $menu as $item ) {
		$order[] = $item[2];
	}

	$GLOBALS['default_menu_order'] = array_flip( $order );
	$GLOBALS['menu_order']         = array_flip( apply_filters( 'menu_order', $order ) );

	usort( $menu, 'sort_menu' );

	unset( $GLOBALS['menu_order'], $GLOBALS['default_menu_order'] );
}

/**
 * Build the admin menu for one scenario: core's baseline, then `admin_menu`, then — optionally —
 * core's ordering pass. The ordering pass reindexes `$menu`, so tests that care about the position
 * a menu registered AT skip it and read the array keys instead.
 */
function skwAdminMenuBuild( bool $apply_render_order = false ): void {
	skwAdminMenuEnsureWooCommerce();

	foreach ( skwAdminMenuCoreBaseline() as $global => $value ) {
		$GLOBALS[ $global ] = $value;
	}

	do_action( 'admin_menu', '' );

	if ( $apply_render_order ) {
		skwAdminMenuApplyRenderOrder();
	}
}

/** The top-level menu slugs, in the order core would render them. */
function skwAdminMenuTopLevelSlugs(): array {
	return array_values( array_map( static fn( $item ) => $item[2], $GLOBALS['menu'] ) );
}

/** Every submenu slug registered under a parent. */
function skwAdminMenuSubSlugs( string $parent ): array {
	return array_values( array_map( static fn( $item ) => $item[2], $GLOBALS['submenu'][ $parent ] ?? [] ) );
}

beforeEach(function () {
	wp_set_current_user( 1 );
	delete_option( Skwirrel_WC_Sync_Admin_Settings::WC_SIGNPOST_OPTION );
	unset( $_GET['tab'] );
	$GLOBALS['pagenow']     = 'admin.php';
	$GLOBALS['typenow']     = '';
	$GLOBALS['parent_file'] = '';
	$GLOBALS['plugin_page'] = null;
});

afterEach(function () {
	delete_option( Skwirrel_WC_Sync_Admin_Settings::WC_SIGNPOST_OPTION );
	unset( $_GET['tab'] );
});

/*
|--------------------------------------------------------------------------
| 1. Menu position
|--------------------------------------------------------------------------
*/

test('the Skwirrel menu is rendered after the WooCommerce cluster and before Appearance', function () {
	skwAdminMenuBuild( true );

	// Guard: core only reorders when a plugin opted in, and it is WooCommerce doing so here. If
	// that ever stops, the order below is just registration order and the test passes for the
	// wrong reason.
	expect( apply_filters( 'custom_menu_order', false ) )->toBeTrue()
		->and( function_exists( 'sort_menu' ) )->toBeTrue();

	$order = skwAdminMenuTopLevelSlugs();

	// Sanity: both clusters we are positioning between must actually be present.
	expect( $order )->toContain( 'woocommerce' )
		->and( $order )->toContain( 'separator2' )
		->and( $order )->toContain( 'themes.php' )
		->and( $order )->toContain( 'skwirrel-pim-sync' );

	$skwirrel = array_search( 'skwirrel-pim-sync', $order, true );

	// After the WooCommerce cluster: WooCommerce itself, its separator, the Products menu it
	// drags along, and every other top-level menu WooCommerce registers on this install.
	$wc_cluster = array_filter(
		$order,
		static fn( $slug ) => 'woocommerce' === $slug
			|| 'separator-woocommerce' === $slug
			|| 'edit.php?post_type=product' === $slug
			|| str_starts_with( $slug, 'wc-' )
			|| str_contains( $slug, 'page=wc-' )
	);
	expect( $wc_cluster )->not->toBeEmpty();

	foreach ( $wc_cluster as $index => $slug ) {
		expect( $index )->toBeLessThan( $skwirrel, "WooCommerce entry '{$slug}' should sort before the Skwirrel menu" );
	}

	// Before core's separator2 and Appearance.
	expect( $skwirrel )->toBeLessThan( array_search( 'separator2', $order, true ) )
		->and( $skwirrel )->toBeLessThan( array_search( 'themes.php', $order, true ) );
});

test('the Skwirrel menu registers at position 58.9 with its own title, capability and icon', function () {
	skwAdminMenuBuild();

	// The key is the position core resolved. Looking it up by slug (rather than reading
	// $menu['58.9']) also proves no collision avoider kicked in and shifted us elsewhere.
	$position = null;
	foreach ( $GLOBALS['menu'] as $key => $item ) {
		if ( 'skwirrel-pim-sync' === $item[2] ) {
			$position = $key;
			break;
		}
	}

	expect( (string) $position )->toBe( '58.9' );

	$entry = $GLOBALS['menu'][ $position ];
	expect( $entry[0] )->toBe( 'Skwirrel' )            // menu title
		->and( $entry[1] )->toBe( 'manage_woocommerce' ) // capability
		->and( $entry[2] )->toBe( 'skwirrel-pim-sync' )  // slug
		->and( $entry[3] )->toBe( 'Skwirrel Sync' )      // page title
		->and( $entry[6] )->toBe( 'none' ); // icon: painted via CSS mask, see print_menu_icon_css()
});

test( 'the menu icon is painted as a recolourable CSS mask', function () {
	wp_set_current_user( 1 );

	ob_start();
	Skwirrel_WC_Sync_Admin_Settings::instance()->print_menu_icon_css();
	$css = ob_get_clean();

	// currentColor + mask is what makes the icon follow the admin colour scheme and the
	// hover/current states; a plain background-image would render flat in every scheme.
	expect( $css )->toContain( '#toplevel_page_skwirrel-pim-sync div.wp-menu-image::before' )
		->and( $css )->toContain( 'background:currentColor' )
		->and( $css )->toContain( 'mask:url("data:image/svg+xml;base64,' );

	preg_match( '/base64,([A-Za-z0-9+\/=]+)/', $css, $m );
	$svg = base64_decode( $m[1], true );
	expect( $svg )->toContain( '<svg' )
		->and( $svg )->toContain( 'viewBox="0 0 20 20"' )
		->and( $svg )->toContain( 'fill-rule="evenodd"' ) // the S is cut out of the plate
		->and( $svg )->toBe( trim( (string) file_get_contents( SKWIRREL_WC_SYNC_PLUGIN_DIR . 'assets/menu-icon.svg' ) ) );
});

test( 'the menu icon css is not printed for users without the capability', function () {
	// No WP_UnitTestCase factory here — the Integration suite is not actually bound to it.
	$subscriber = wp_insert_user( [
		'user_login' => 'skw_menu_icon_subscriber',
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	] );
	wp_set_current_user( is_wp_error( $subscriber ) ? 0 : (int) $subscriber );

	ob_start();
	Skwirrel_WC_Sync_Admin_Settings::instance()->print_menu_icon_css();

	$out = ob_get_clean();

	if ( ! is_wp_error( $subscriber ) ) {
		wp_delete_user( (int) $subscriber );
	}

	expect( $out )->toBe( '' );
});

/*
|--------------------------------------------------------------------------
| 2. Submenu registration
|--------------------------------------------------------------------------
*/

test('the Skwirrel menu has exactly the four expected submenu rows, in order', function () {
	skwAdminMenuBuild();

	$rows = array_map(
		static fn( $item ) => [ $item[0], $item[2] ],
		$GLOBALS['submenu']['skwirrel-pim-sync'] ?? []
	);

	expect( $rows )->toBe( [
		[ 'Status', 'skwirrel-pim-sync' ],
		[ 'Settings', 'admin.php?page=skwirrel-pim-sync&tab=settings' ],
		[ 'Sync logs', 'admin.php?page=skwirrel-pim-sync&tab=debug' ],
		[ 'Sync now', 'admin.php?page=skwirrel-pim-sync#skwirrel-sync-now' ],
	] );
});

test('the first submenu row is the renamed parent, not a second "Skwirrel"', function () {
	skwAdminMenuBuild();

	$labels = array_map( static fn( $item ) => $item[0], $GLOBALS['submenu']['skwirrel-pim-sync'] );

	// Core auto-inserts a duplicate of the parent (labelled "Skwirrel") the moment a submenu is
	// added, unless the parent slug is re-registered first. If that guard ever regresses the menu
	// gains a "Skwirrel > Skwirrel" row.
	expect( $labels )->not->toContain( 'Skwirrel' )
		->and( $labels[0] )->toBe( 'Status' );

	// And only one row points at the parent slug.
	$parent_rows = array_filter( skwAdminMenuSubSlugs( 'skwirrel-pim-sync' ), static fn( $slug ) => 'skwirrel-pim-sync' === $slug );
	expect( $parent_rows )->toHaveCount( 1 );
});

test('the tab links do not steal ownership of the page from our own top-level menu', function () {
	skwAdminMenuBuild();

	// This is what core calls on every admin request to decide which top-level menu owns the
	// screen and therefore which one lights up.
	$GLOBALS['plugin_page'] = 'skwirrel-pim-sync';
	$GLOBALS['parent_file'] = '';

	expect( get_admin_page_parent() )->toBe( 'skwirrel-pim-sync' );

	// The link-only rows are parented to us, not registered as pages under someone else.
	foreach ( [ 'admin.php?page=skwirrel-pim-sync&tab=settings', 'admin.php?page=skwirrel-pim-sync&tab=debug', 'admin.php?page=skwirrel-pim-sync#skwirrel-sync-now' ] as $slug ) {
		expect( $GLOBALS['_parent_pages'][ $slug ] ?? null )->toBe( 'skwirrel-pim-sync' );
	}

	// And no tab link leaked into any other menu's submenu.
	foreach ( $GLOBALS['submenu'] as $parent => $items ) {
		if ( 'skwirrel-pim-sync' === $parent ) {
			continue;
		}
		foreach ( $items as $item ) {
			expect( $item[2] )->not->toContain( 'skwirrel-pim-sync' );
		}
	}
});

/*
|--------------------------------------------------------------------------
| 3. Tab highlighting
|--------------------------------------------------------------------------
*/

test('every tab highlights a submenu row that is actually registered', function (string $tab, string $expected) {
	skwAdminMenuBuild();

	$_GET['tab'] = $tab;

	$result = Skwirrel_WC_Sync_Admin_Settings::instance()->highlight_active_tab( null, 'skwirrel-pim-sync' );

	expect( $result )->toBe( $expected );

	// The whole point of $submenu_file: wp-admin/menu-header.php marks the row whose slug is
	// identical to it. A value that matches nothing highlights nothing.
	expect( skwAdminMenuSubSlugs( 'skwirrel-pim-sync' ) )->toContain( $result );
})->with([
	'no tab (dashboard)' => [ '', 'skwirrel-pim-sync' ],
	'settings tab'       => [ 'settings', 'admin.php?page=skwirrel-pim-sync&tab=settings' ],
	'debug tab'          => [ 'debug', 'admin.php?page=skwirrel-pim-sync&tab=debug' ],
	// History has no submenu row of its own by design; it must fall back to the parent rather
	// than leave the whole menu unhighlighted.
	'history tab'        => [ 'history', 'skwirrel-pim-sync' ],
	'unknown tab'        => [ 'not-a-real-tab', 'skwirrel-pim-sync' ],
	'junk tab'           => [ '../../etc/passwd', 'skwirrel-pim-sync' ],
]);

test('highlight_active_tab leaves other screens alone', function () {
	skwAdminMenuBuild();

	$_GET['tab'] = 'settings';

	$settings = Skwirrel_WC_Sync_Admin_Settings::instance();

	// Another plugin's page with a ?tab= of its own must come back untouched.
	expect( $settings->highlight_active_tab( 'wc-settings', 'woocommerce' ) )->toBe( 'wc-settings' )
		->and( $settings->highlight_active_tab( null, 'woocommerce' ) )->toBeNull()
		->and( $settings->highlight_active_tab( 'themes.php', 'themes.php' ) )->toBe( 'themes.php' );
});

/*
|--------------------------------------------------------------------------
| 4. WooCommerce signpost
|--------------------------------------------------------------------------
*/

test('no signpost appears under WooCommerce on a fresh install', function () {
	delete_option( Skwirrel_WC_Sync_Admin_Settings::WC_SIGNPOST_OPTION );

	skwAdminMenuBuild();

	expect( $GLOBALS['submenu']['woocommerce'] ?? [] )->not->toBeEmpty();

	foreach ( skwAdminMenuSubSlugs( 'woocommerce' ) as $slug ) {
		expect( $slug )->not->toContain( 'skwirrel' );
	}
});

test('an upgraded site gets exactly one signpost under WooCommerce, and keeps its own menu', function () {
	update_option( Skwirrel_WC_Sync_Admin_Settings::WC_SIGNPOST_OPTION, 1 );

	skwAdminMenuBuild();

	$signposts = array_values( array_filter(
		$GLOBALS['submenu']['woocommerce'],
		static fn( $item ) => str_contains( $item[2], 'skwirrel-pim-sync' )
	) );

	expect( $signposts )->toHaveCount( 1 );
	expect( $signposts[0][0] )->toBe( 'Skwirrel' )
		->and( $signposts[0][1] )->toBe( 'manage_woocommerce' )
		->and( $signposts[0][2] )->toBe( 'admin.php?page=skwirrel-pim-sync' )
		// Empty page title == link only. A page title here would make core treat it as a screen
		// of its own living under WooCommerce.
		->and( $signposts[0][3] )->toBe( '' );

	// Our own top-level menu is untouched: still four rows, still owning the page.
	expect( $GLOBALS['submenu']['skwirrel-pim-sync'] )->toHaveCount( 4 );

	$GLOBALS['plugin_page'] = 'skwirrel-pim-sync';
	$GLOBALS['parent_file'] = '';
	expect( get_admin_page_parent() )->toBe( 'skwirrel-pim-sync' );

	// The signpost registers under its own slug, so it cannot displace the page's real parent.
	expect( $GLOBALS['_parent_pages']['skwirrel-pim-sync'] )->toBe( 'skwirrel-pim-sync' );
	expect( $GLOBALS['_parent_pages']['admin.php?page=skwirrel-pim-sync'] ?? null )->toBe( 'woocommerce' );
});
