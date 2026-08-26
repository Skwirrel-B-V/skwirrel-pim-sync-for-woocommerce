<?php
/**
 * Skwirrel Sync - Admin Settings UI.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Admin_Settings {

	private const PAGE_SLUG  = 'skwirrel-pim-sync';
	private const OPTION_KEY = 'skwirrel_wc_sync_settings';

	private const TOKEN_OPTION_KEY = 'skwirrel_wc_sync_auth_token';
	private const MASK             = '••••••••';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private const BG_SYNC_ACTION        = 'skwirrel_wc_sync_background';
	private const BG_SYNC_TRANSIENT     = 'skwirrel_wc_sync_bg_token';
	private const BG_PURGE_ACTION       = 'skwirrel_wc_sync_purge_all';
	private const BG_PURGE_TRANSIENT    = 'skwirrel_wc_sync_purge_token';
	private const TEST_RESULT_TRANSIENT = 'skwirrel_wc_sync_test_result';

	/**
	 * Bounds for the "Refresh statuses" scan.
	 *
	 * Discovery must walk the whole feed to see a status only later products carry. MAX_PAGES caps
	 * the scan as a whole (batch_size × 200 products); CHUNK_PAGES and BUDGET cap what a single
	 * AJAX request does, so no one request can sit on dozens of serial API calls and be killed by
	 * an FPM/proxy/browser timeout. The browser continues the scan from the returned `next_page`.
	 */
	private const STATUS_SCAN_MAX_PAGES = 200;

	/** Pages one "Refresh statuses" request may scan before handing back a continuation. */
	private const STATUS_SCAN_CHUNK_PAGES = 5;

	/** Wall-clock budget (seconds) for one "Refresh statuses" request. */
	private const STATUS_SCAN_BUDGET = 12;

	/** Transient prefix for the running totals of a scan continuing across requests. */
	private const STATUS_SCAN_TOTALS_TRANSIENT = 'skwirrel_wc_sync_status_scan';

	/** HTTP timeout (seconds) for a discovery call — capped well below the request budget. */
	private const STATUS_SCAN_TIMEOUT = 10;

	/** Retries for a discovery call. One extra attempt; the scan resumes on the next chunk anyway. */
	private const STATUS_SCAN_RETRIES = 1;

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ], 10 );
		add_filter( 'submenu_file', [ $this, 'highlight_active_tab' ], 10, 2 );
		add_action( 'admin_head', [ $this, 'print_menu_icon_css' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_skwirrel_wc_sync_test', [ $this, 'handle_test_connection' ] );
		add_action( 'admin_post_skwirrel_wc_sync_run', [ $this, 'handle_sync_now' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// Background sync/purge handlers use nopriv because the loopback request is unauthenticated.
		// Security: each handler validates a single-use transient token (skwirrel_wc_sync_bg_token / skwirrel_wc_sync_purge_token).
		add_action( 'wp_ajax_' . self::BG_SYNC_ACTION, [ $this, 'handle_background_sync' ] );
		add_action( 'wp_ajax_nopriv_' . self::BG_SYNC_ACTION, [ $this, 'handle_background_sync' ] );
		add_action( 'admin_post_skwirrel_wc_sync_purge', [ $this, 'handle_purge_now' ] );
		add_action( 'admin_post_skwirrel_wc_sync_clear_history', [ $this, 'handle_clear_history' ] );
		add_action( 'admin_post_skwirrel_wc_sync_reset_settings', [ $this, 'handle_reset_settings' ] );
		add_action( 'wp_ajax_' . self::BG_PURGE_ACTION, [ $this, 'handle_background_purge' ] );
		add_action( 'wp_ajax_nopriv_' . self::BG_PURGE_ACTION, [ $this, 'handle_background_purge' ] );
		add_action( 'wp_ajax_skwirrel_wc_sync_save_slug_resync', [ $this, 'handle_save_slug_resync' ] );
		add_action( 'wp_ajax_skwirrel_wc_sync_view_log', [ $this, 'handle_view_log' ] );
		add_action( 'wp_ajax_skwirrel_wc_sync_tail_log', [ $this, 'handle_tail_log' ] );
		add_action( 'wp_ajax_skwirrel_wc_sync_download_log', [ $this, 'handle_download_log' ] );
		add_action( 'wp_ajax_skwirrel_wc_sync_abort', [ $this, 'handle_abort_sync' ] );
		// Reactive sync status: a status endpoint + a poller everywhere. The full banner lives only on
		// the plugin's own pages; other admin pages get a compact, movable, dismissible corner toast.
		add_action( 'wp_ajax_skwirrel_wc_sync_status', [ $this, 'handle_sync_status' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_status_banner_assets' ] );
		add_action( 'admin_footer', [ $this, 'render_status_toast' ] );
		// Inline "Test connection": autosaves the environment/connection settings, then tests them.
		add_action( 'wp_ajax_skwirrel_wc_sync_test_connection', [ $this, 'handle_test_connection_ajax' ] );
		add_action( 'wp_ajax_skwirrel_wc_sync_refresh_statuses', [ $this, 'handle_refresh_statuses' ] );
	}

	/**
	 * Whether a hex colour is dark (perceptual luminance below 50%). Used to keep the white header
	 * text legible: light admin colour schemes fall back to the default dark menu colour.
	 */
	private static function is_dark_hex( string $hex ): bool {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return true;
		}
		$lum = ( 0.2126 * hexdec( substr( $hex, 0, 2 ) ) + 0.7152 * hexdec( substr( $hex, 2, 2 ) ) + 0.0722 * hexdec( substr( $hex, 4, 2 ) ) ) / 255;
		return $lum < 0.5;
	}

	/**
	 * The plugin header background, taken from the current user's admin colour scheme so the header
	 * matches the WP admin menu (no core CSS variable exposes this). Light schemes fall back to the
	 * default dark menu colour so the white header text stays legible.
	 */
	public static function header_background_color(): string {
		$scheme = get_user_option( 'admin_color' );
		$scheme = is_string( $scheme ) && '' !== $scheme ? $scheme : 'fresh';
		$colors = isset( $GLOBALS['_wp_admin_css_colors'][ $scheme ]->colors )
			? (array) $GLOBALS['_wp_admin_css_colors'][ $scheme ]->colors
			: [];
		$base   = isset( $colors[0] ) ? (string) $colors[0] : '';
		return ( '' !== $base && self::is_dark_hex( $base ) ) ? $base : '#1d2327';
	}

	/**
	 * Tab links shown as submenu entries, keyed by the `tab` value they select.
	 *
	 * These are *links*, not pages: they are registered with an empty page title and no callback,
	 * so core renders them via the raw-href branch in wp-admin/menu-header.php and never treats
	 * them as separate admin pages. The whole screen remains the single page `skwirrel-pim-sync`.
	 *
	 * @return array<string, string> tab value => submenu slug (a relative admin URL).
	 */
	private static function tab_submenu_slugs(): array {
		return [
			'settings' => 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings',
			'debug'    => 'admin.php?page=' . self::PAGE_SLUG . '&tab=debug',
		];
	}

	public function add_menu(): void {
		// Position 58.9 lands after the WooCommerce cluster (WooCommerce 55.5, Sales reports 55.6,
		// Payments 56, Marketing 58) and before core's separator2 (59) and Appearance (60).
		add_menu_page(
			__( 'Skwirrel Sync', 'skwirrel-pim-sync' ),
			__( 'Skwirrel', 'skwirrel-pim-sync' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'none',
			58.9
		);

		// Re-registering the parent slug as the first submenu item renames the duplicated
		// entry core would otherwise label "Skwirrel". No callback: the parent already owns it.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Skwirrel Sync', 'skwirrel-pim-sync' ),
			__( 'Status', 'skwirrel-pim-sync' ),
			'manage_woocommerce',
			self::PAGE_SLUG
		);

		$tabs = self::tab_submenu_slugs();

		add_submenu_page( self::PAGE_SLUG, '', __( 'Settings', 'skwirrel-pim-sync' ), 'manage_woocommerce', $tabs['settings'] );
		add_submenu_page( self::PAGE_SLUG, '', __( 'Sync logs', 'skwirrel-pim-sync' ), 'manage_woocommerce', $tabs['debug'] );

		// Pure navigation: jumps to the "Sync Now" block on the status screen. It deliberately
		// does not trigger a sync — an admin menu link must never perform a state change.
		add_submenu_page(
			self::PAGE_SLUG,
			'',
			__( 'Sync now', 'skwirrel-pim-sync' ),
			'manage_woocommerce',
			'admin.php?page=' . self::PAGE_SLUG . '#skwirrel-sync-now'
		);
	}

	/**
	 * The Skwirrel mark as a base64 SVG, used as a CSS mask so the icon inherits the admin
	 * colour scheme (and the hover/current states) like every other menu icon does.
	 *
	 * A data-URI passed to add_menu_page() is painted as an image and cannot be recoloured,
	 * which is why the icon is registered as 'none' and drawn here instead.
	 *
	 * Generated from assets/menu-icon.svg — regenerate both together if the mark changes.
	 */
	private const MENU_ICON_SVG_BASE64 = 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBkPSJNMyAwSDE3QTMgMyAwIDAgMSAyMCAzVjE3QTMgMyAwIDAgMSAxNyAyMEgzQTMgMyAwIDAgMSAwIDE3VjNBMyAzIDAgMCAxIDMgMFpNOC43OSAzLjA1TDExLjE3IDMuMDVMMTEuOTkgMy4yNEwxMi41OCAzLjU1TDEzLjI0IDQuMThMMTMuNTkgNC43N0wxMy43OSA1LjM1TDEzLjgzIDYuODhMMTMuNTUgNy4yN0wxMy4yNCA3LjQyTDExLjk5IDcuNDJMMTEuNjQgNy4yM0wxMS40MSA2LjhMMTEuMzcgNS44MkwxMS4wMiA1LjM5TDEwLjc0IDUuMjdMOS4yMiA1LjI3TDguODMgNS40N0w4LjU1IDUuOUw4LjU1IDcuNzNMOC42NyA3Ljk3TDguOTEgOC4yTDkuMTQgOC4zMkwxMC44MiA4LjM2TDExLjA1IDguNDhMMTEuMjkgOC43NUwxMS4zNyA4Ljk4TDExLjM3IDEwLjUxTDExLjQ4IDEwLjc4TDExLjc2IDExLjA1TDEyLjA3IDExLjE3TDEzLjM2IDExLjIxTDEzLjcxIDExLjQ4TDEzLjgzIDExLjcyTDEzLjgzIDE0LjM0TDEzLjU5IDE1LjE2TDEzLjIgMTUuNzhMMTIuNzcgMTYuMjFMMTIuMjMgMTYuNTZMMTEuNTYgMTYuOEw4LjY3IDE2Ljg0TDcuOTMgMTYuNjRMNy4zIDE2LjI5TDYuNzIgMTUuNzRMNi4zMyAxNS4wOEw2LjE3IDE0LjYxTDYuMDkgMTQuMDZMNi4xMyAxMi41OEw2LjQxIDEyLjE5TDYuNzYgMTIuMDNMNy45MyAxMi4wM0w4LjIgMTIuMTVMOC40IDEyLjM0TDguNTUgMTIuNzNMOC41NSAxMy45OEw4LjcxIDE0LjNMOS4wNiAxNC41N0wxMC43OCAxNC42MUwxMS4yMSAxNC4zNEwxMS40MSAxMy45NUwxMS40MSAxMS44TDExLjI1IDExLjQ1TDExLjA5IDExLjI5TDEwLjc4IDExLjEzTDkuMyAxMS4xM0w4Ljk1IDExLjAyTDguNzEgMTAuNzhMOC41OSAxMC41MUw4LjU5IDkuMDJMOC40NCA4LjYzTDguMjQgOC40NEw4LjAxIDguMzJMNi41NiA4LjI0TDYuMjEgNy45M0w2LjEzIDcuNzdMNi4xMyA1LjUxTDYuMjkgNC45Mkw2LjUyIDQuNDVMNi44OCAzLjk4TDcuNSAzLjQ4TDcuOTcgMy4yNFoiLz48L3N2Zz4=';

	/**
	 * Paint the menu icon. Printed on every admin screen because the menu is on every screen;
	 * inlined rather than enqueued so a 1 KB mask never costs an HTTP request.
	 */
	public function print_menu_icon_css(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$mask = 'url("data:image/svg+xml;base64,' . self::MENU_ICON_SVG_BASE64 . '") no-repeat center / 20px 20px';

		// Masking the ::before rather than the div is deliberate: every admin colour scheme, and
		// core's hover/current rules, set `color` on `div.wp-menu-image::before`. Painting the mask
		// with currentColor there makes the mark follow all of them for free, exactly like a dashicon.
		$css = '#adminmenu #toplevel_page_' . self::PAGE_SLUG . ' div.wp-menu-image::before{'
			. 'content:"";'
			. 'display:inline-block;'
			. 'width:20px;height:20px;'
			. 'padding:7px 0;'  // core's own metric for menu icons; keeps us aligned with the neighbours
			. 'box-sizing:content-box;'
			. 'background:currentColor;'
			. '-webkit-mask:' . $mask . ';'
			. 'mask:' . $mask . ';'
			. '}';

		printf( '<style id="skwirrel-menu-icon">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS, no user input.
	}

	/**
	 * Light up the submenu entry matching the tab currently being viewed.
	 *
	 * Core sets no $submenu_file for this screen, so without this filter it falls back to
	 * matching $plugin_page against the submenu slug — which only ever highlights "Status".
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string      $parent_file  Current parent file.
	 * @return string|null
	 */
	public function highlight_active_tab( $submenu_file, $parent_file ) {
		if ( self::PAGE_SLUG !== $parent_file ) {
			return $submenu_file;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only menu state.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tabs = self::tab_submenu_slugs();

		// Tabs without their own submenu entry (and the dashboard itself) fall back to "Status".
		return $tabs[ $tab ] ?? self::PAGE_SLUG;
	}

	public function register_settings(): void {
		register_setting(
			'skwirrel_wc_sync',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);
		add_action( 'update_option_' . self::OPTION_KEY, [ $this, 'on_settings_updated' ], 10, 2 );
	}

	public function on_settings_updated( $old_value, $value ): void {
		if ( is_array( $value ) ) {
			delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
			Skwirrel_WC_Sync_Action_Scheduler::instance()->schedule();
			$this->bust_settings_cache();
		}
	}

	/**
	 * Invalidate the WP object cache entry for our settings options.
	 *
	 * Sites running aggressive persistent object caches have been observed serving stale
	 * `skwirrel_wc_sync_settings` after an update — admin updates the endpoint URL, the
	 * next page load reads the old value, the next sync still hits the old URL. WordPress
	 * core invalidates the `alloptions` group inside `update_option`, but not every cache
	 * drop-in propagates that across workers reliably. Calling `wp_cache_delete` on the
	 * specific keys plus `alloptions`/`notoptions` covers the gap via the standard cache
	 * API contract — drop-in agnostic, no plugin-specific dependencies.
	 */
	private function bust_settings_cache(): void {
		wp_cache_delete( self::OPTION_KEY, 'options' );
		wp_cache_delete( self::TOKEN_OPTION_KEY, 'options' );
		if ( class_exists( 'Skwirrel_WC_Sync_Connectors' ) ) {
			wp_cache_delete( Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}


	/**
	 * Field IDs that are always required, whatever else is configured.
	 *
	 * @return array<int, string>
	 */
	public static function unconditional_required_fields(): array {
		return [ 'skwirrel_subdomain', 'collection_ids' ];
	}

	/**
	 * Conditionally required fields: field ID => the settings keys that make it required.
	 *
	 * A field is required as soon as any listed key is truthy. The settings screen renders
	 * this rule table alongside the evaluated state, so the live marker toggle reads the
	 * conditions off the markup instead of keeping a second copy of them in JavaScript.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function conditional_required_rules(): array {
		return [
			'super_category_id'    => [ 'sync_categories' ],
			'custom_collection_id' => [ 'sync_custom_classes', 'sync_trade_item_custom_classes', 'sync_grouped_products' ],
		];
	}

	/**
	 * Which settings fields currently need a value.
	 *
	 * The single source of truth behind both the `*` markers on the settings screen and the
	 * rules {@see self::sanitize_settings()} enforces. The two answering differently is the
	 * whole bug class this exists to prevent, so neither side may inline the conditions.
	 *
	 * @param array<string, mixed> $values Stored settings when rendering, raw submitted input when saving.
	 * @return array<string, bool> Required flag keyed by field ID.
	 */
	public static function required_fields( array $values ): array {
		$required = [];

		foreach ( self::unconditional_required_fields() as $field ) {
			$required[ $field ] = true;
		}

		foreach ( self::conditional_required_rules() as $field => $keys ) {
			$required[ $field ] = false;
			foreach ( $keys as $key ) {
				if ( ! empty( $values[ $key ] ) ) {
					$required[ $field ] = true;
					break;
				}
			}
		}

		return $required;
	}

	/**
	 * Whether one field needs a value for the given settings state.
	 *
	 * @param array<string, mixed> $values Stored settings or raw submitted input.
	 * @param string               $field  Field ID.
	 */
	public static function is_field_required( array $values, string $field ): bool {
		return ! empty( self::required_fields( $values )[ $field ] );
	}

	/**
	 * Settings error code => the field the message belongs to.
	 *
	 * Keeps the inline placement of a validation message next to the rule that raises it: a
	 * new `add_settings_error()` call adds its code here and the message lands at its field.
	 * Codes missing from this map still reach the user through the summary at the top of the
	 * screen, so forgetting an entry degrades placement, never visibility.
	 *
	 * @return array<string, string>
	 */
	public static function error_field_map(): array {
		return [
			'super_category_id_required'    => 'super_category_id',
			'collection_ids_required'       => 'collection_ids',
			'custom_collection_id_required' => 'custom_collection_id',
		];
	}

	public function sanitize_settings( array $input ): array {
		$out                 = [];
		$out['endpoint_url'] = isset( $input['endpoint_url'] ) ? esc_url_raw( self::normalize_endpoint_url( (string) $input['endpoint_url'] ) ) : '';
		$out['auth_type']    = in_array( $input['auth_type'] ?? '', [ 'bearer', 'token' ], true ) ? $input['auth_type'] : 'bearer';
		$token               = $this->sanitize_token( $input['auth_token'] ?? '' );
		if ( ! empty( $token ) ) {
			update_option( self::TOKEN_OPTION_KEY, $token, false );
		}
		$out['auth_token'] = ! empty( $token ) ? self::MASK : '';
		$out['timeout']    = isset( $input['timeout'] ) ? max( 5, min( 120, (int) $input['timeout'] ) ) : 30;
		$out['retries']    = isset( $input['retries'] ) ? max( 0, min( 5, (int) $input['retries'] ) ) : 2;
		// Enforce the dynamic minimum rest window server-side: a too-short interval (e.g. forced via a
		// crafted POST) is bumped up to the smallest recurrence that still leaves a full hour of rest.
		$interval = (string) ( $input['sync_interval'] ?? '' );
		if ( '' !== $interval ) {
			$min_seconds = Skwirrel_WC_Sync_Action_Scheduler::get_min_interval_seconds();
			if ( Skwirrel_WC_Sync_Action_Scheduler::interval_seconds( $interval ) < $min_seconds ) {
				$interval = Skwirrel_WC_Sync_Action_Scheduler::smallest_interval_at_least( $min_seconds );
			}
		}
		$out['sync_interval']     = $interval;
		$out['batch_size']        = isset( $input['batch_size'] ) ? max( 1, min( 100, (int) $input['batch_size'] ) ) : 10;
		$out['sync_categories']   = ! empty( $input['sync_categories'] );
		$out['super_category_id'] = isset( $input['super_category_id'] ) ? sanitize_text_field( trim( $input['super_category_id'] ) ) : '';
		if ( self::is_field_required( $input, 'super_category_id' ) && ( '' === $out['super_category_id'] || 0 >= (int) $out['super_category_id'] ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'super_category_id_required',
				__( 'Category sync is enabled but no valid super category ID is set. Please enter a super category ID greater than 0.', 'skwirrel-pim-sync' ),
				'error'
			);
		}
		$out['sync_grouped_products']       = ! empty( $input['sync_grouped_products'] );
		$out['use_virtual_product_content'] = ! empty( $input['use_virtual_product_content'] );
		$out['sync_related_products']       = ! empty( $input['sync_related_products'] );
		$out['related_products_type']       = in_array( $input['related_products_type'] ?? '', [ 'auto', 'cross_sells', 'upsells', 'both' ], true )
			? $input['related_products_type']
			: 'auto';
		$out['variant_label_field']         = in_array( $input['variant_label_field'] ?? '', [ 'internal_product_code', 'product_erp_description', 'product_name' ], true )
			? $input['variant_label_field']
			: 'internal_product_code';
		$out['sync_images']                 = 'yes' === ( $input['sync_images'] ?? 'yes' );
		// Image language: dropdown or custom
		$lang_select = $input['image_language_select'] ?? '';
		$lang_custom = sanitize_text_field( $input['image_language_custom'] ?? '' );
		if ( '_custom' === $lang_select && '' !== $lang_custom ) {
			$out['image_language'] = $lang_custom;
		} elseif ( '' !== $lang_select && '_custom' !== $lang_select ) {
			$out['image_language'] = sanitize_text_field( $lang_select );
		} else {
			// Backward compatibility: accept old direct field
			$out['image_language'] = sanitize_text_field( $input['image_language'] ?? 'nl' );
		}
		// Include languages: merge checkboxes + custom input
		$checked = $input['include_languages_checkboxes'] ?? [];
		if ( ! is_array( $checked ) ) {
			$checked = [];
		}
		$checked      = array_map( 'sanitize_text_field', $checked );
		$custom_raw   = $input['include_languages_custom'] ?? '';
		$custom_parts = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', is_string( $custom_raw ) ? $custom_raw : '', -1, PREG_SPLIT_NO_EMPTY ) ) ) );
		$custom_parts = array_map( 'sanitize_text_field', $custom_parts );
		$merged       = array_values( array_unique( array_merge( $checked, $custom_parts ) ) );
		if ( empty( $merged ) ) {
			// Backward compatibility: accept old direct field
			$inc    = $input['include_languages'] ?? '';
			$parsed = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', is_string( $inc ) ? $inc : '', -1, PREG_SPLIT_NO_EMPTY ) ) ) );
			$merged = ! empty( $parsed ) ? $parsed : [ 'nl-NL', 'nl' ];
		}
		$out['include_languages'] = $merged;
		$out['use_sku_field']     = sanitize_text_field( $input['use_sku_field'] ?? 'internal_product_code' );

		// Collection IDs: comma-separated, keep only values > 0
		$raw_collections       = $input['collection_ids'] ?? '';
		$collection_parts      = preg_split( '/[\s,]+/', is_string( $raw_collections ) ? $raw_collections : '', -1, PREG_SPLIT_NO_EMPTY );
		$collection_valid      = array_filter(
			array_map( 'intval', array_filter( array_map( 'trim', $collection_parts ), 'is_numeric' ) ),
			static fn ( int $v ): bool => $v > 0
		);
		$out['collection_ids'] = implode( ', ', $collection_valid );
		if ( self::is_field_required( $input, 'collection_ids' ) && empty( $collection_valid ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'collection_ids_required',
				__( 'At least one selection ID greater than 0 is required.', 'skwirrel-pim-sync' ),
				'error'
			);
		}
		$out['custom_collection_id'] = isset( $input['custom_collection_id'] ) ? sanitize_text_field( trim( $input['custom_collection_id'] ) ) : '';
		// Only required when a feature that actually uses it is enabled: custom classes,
		// trade-item custom classes, or grouped products (which may use custom variation axes).
		// The condition lives in the required-field registry so the marker on the settings
		// screen and this check can never disagree.
		if ( self::is_field_required( $input, 'custom_collection_id' ) && ( '' === $out['custom_collection_id'] || 0 >= (int) $out['custom_collection_id'] ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'custom_collection_id_required',
				__( 'A custom class collection ID greater than 0 is required when syncing custom classes or grouped products.', 'skwirrel-pim-sync' ),
				'error'
			);
		}
		// Custom classes
		$out['sync_custom_classes']            = ! empty( $input['sync_custom_classes'] );
		$out['sync_trade_item_custom_classes'] = ! empty( $input['sync_trade_item_custom_classes'] );
		$out['custom_class_filter_mode']       = in_array( $input['custom_class_filter_mode'] ?? '', [ 'whitelist', 'blacklist' ], true )
			? $input['custom_class_filter_mode']
			: '';
		$raw_cc_filter                         = $input['custom_class_filter_ids'] ?? '';
		$cc_parts                              = preg_split( '/[\s,]+/', is_string( $raw_cc_filter ) ? $raw_cc_filter : '', -1, PREG_SPLIT_NO_EMPTY );
		$out['custom_class_filter_ids']        = implode( ', ', array_map( 'sanitize_text_field', array_map( 'trim', $cc_parts ) ) );
		$out['custom_class_visibility_mode']   = in_array( $input['custom_class_visibility_mode'] ?? '', [ 'whitelist', 'blacklist' ], true )
			? $input['custom_class_visibility_mode']
			: '';
		$raw_vis                               = $input['custom_class_visibility_ids'] ?? '';
		$vis_parts                             = preg_split( '/[\s,]+/', is_string( $raw_vis ) ? $raw_vis : '', -1, PREG_SPLIT_NO_EMPTY );
		$out['custom_class_visibility_ids']    = implode( ', ', array_map( 'sanitize_text_field', array_map( 'trim', $vis_parts ) ) );

		$out['show_gtin_attribute']             = ! empty( $input['show_gtin_attribute'] );
		$out['show_variant_attribute']          = ! empty( $input['show_variant_attribute'] );
		$out['sync_manufacturers']              = ! empty( $input['sync_manufacturers'] );
		$out['verbose_logging']                 = ! empty( $input['verbose_logging'] );
		$out['purge_stale_products']            = ! empty( $input['purge_stale_products'] );
		$out['show_delete_warning']             = ! empty( $input['show_delete_warning'] );
		$out['protect_from_deletion']           = ! empty( $input['protect_from_deletion'] );
		$out['prices_managed_outside_skwirrel'] = ! empty( $input['prices_managed_outside_skwirrel'] );

		// Product status handling: map each source status (and the __trashed__/__missing__/__none__
		// pseudo-statuses) to a WooCommerce state. Only whitelisted states are stored.
		$valid_states   = [ 'publish', 'draft', 'trash', 'deprecated' ];
		$raw_mapping    = $input['status_mapping'] ?? [];
		$status_mapping = [];
		if ( is_array( $raw_mapping ) ) {
			foreach ( $raw_mapping as $label => $state ) {
				if ( count( $status_mapping ) >= 250 ) {
					break; // Bound the stored map (defence against a crafted/oversized POST).
				}
				// Normalize the key identically to discovery/runtime so it matches at sync time.
				$label = Skwirrel_WC_Sync_Product_Mapper::normalize_status_label( (string) $label );
				if ( '' !== $label && in_array( $state, $valid_states, true ) ) {
					$status_mapping[ $label ] = $state;
				}
			}
		}
		$out['status_mapping']                = $status_mapping;
		$out['status_mapping_default']        = in_array( $input['status_mapping_default'] ?? '', $valid_states, true )
			? $input['status_mapping_default']
			: 'publish';
		$out['deprecated_remove_after_syncs'] = isset( $input['deprecated_remove_after_syncs'] )
			? max( 0, (int) $input['deprecated_remove_after_syncs'] )
			: 3;
		$out['log_mode_manual']               = in_array( $input['log_mode_manual'] ?? '', [ 'per_sync', 'per_day' ], true )
			? $input['log_mode_manual']
			: 'per_sync';
		$out['log_mode_scheduled']            = in_array( $input['log_mode_scheduled'] ?? '', [ 'per_sync', 'per_day' ], true )
			? $input['log_mode_scheduled']
			: 'per_day';
		$out['log_retention']                 = in_array( $input['log_retention'] ?? '', [ '12hours', '1day', '2days', '7days', '30days', 'manual' ], true )
			? $input['log_retention']
			: '7days';
		return $out;
	}

	private function sanitize_token( string $token ): string {
		$token = trim( $token );
		if ( self::MASK === $token || '' === $token ) {
			return (string) get_option( self::TOKEN_OPTION_KEY, '' );
		}
		return $token;
	}

	public static function get_auth_token(): string {
		// Prefer the WP 7.0+ Connectors API store. Falls back to the legacy
		// `skwirrel_wc_sync_auth_token` option for sub-7.0 sites and for the
		// 3.10.0 migration window before maybe_migrate_token() has run.
		if ( class_exists( 'Skwirrel_WC_Sync_Connectors' ) ) {
			$token = Skwirrel_WC_Sync_Connectors::get_token();
			if ( '' !== $token ) {
				return $token;
			}
		}
		return (string) get_option( self::TOKEN_OPTION_KEY, '' );
	}

	/**
	 * Normalize a Skwirrel JSON-RPC endpoint URL.
	 *
	 * Heals values produced when a user pastes a full hostname (e.g. "lixero-tmp.z06.skwirrel.eu")
	 * into the subdomain field — the inline JS would otherwise append a second ".skwirrel.eu/jsonrpc",
	 * yielding "https://lixero-tmp.z06.skwirrel.eu.skwirrel.eu/jsonrpc". Once stored, the doubled value
	 * round-trips through the field on every page load, so the bad URL persists across saves until the
	 * user manually clears it. Collapsing any duplicated trailing ".skwirrel.eu" segments here breaks
	 * that loop both on save and on display.
	 */
	public static function normalize_endpoint_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		// Peel repeated leading "https://" / "http://" — the pre-3.9.0 JS could
		// produce "https://https://…" when a full URL was pasted into the subdomain field.
		while ( (bool) preg_match( '#^https?://https?://#i', $url ) ) {
			$url = (string) preg_replace( '#^https?://#i', '', $url );
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$host = strtolower( (string) $parts['host'] );
		while ( (bool) preg_match( '/\.skwirrel\.eu\.skwirrel\.eu$/i', $host ) ) {
			$host = (string) preg_replace( '/\.skwirrel\.eu$/i', '', $host );
		}
		$scheme = $parts['scheme'] ?? 'https';
		$path   = (string) ( $parts['path'] ?? '' );
		// For Skwirrel hosts the only valid path is /jsonrpc — discard any garbage the user
		// may have pasted (e.g. "/jsonrpc.skwirrel.eu/jsonrpc" from a double-paste mishap).
		if ( (bool) preg_match( '/\.skwirrel\.eu$/i', $host ) ) {
			$path = '/jsonrpc';
		} else {
			$path = rtrim( $path, '/' );
		}
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $scheme . '://' . $host . $path . $query;
	}

	public function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( 'skwirrel_wc_sync_test', '_wpnonce' );

		$opts   = get_option( self::OPTION_KEY, [] );
		$token  = self::get_auth_token();
		$client = new Skwirrel_WC_Sync_JsonRpc_Client(
			$opts['endpoint_url'] ?? '',
			$opts['auth_type'] ?? 'bearer',
			$token,
			(int) ( $opts['timeout'] ?? 30 ),
			(int) ( $opts['retries'] ?? 2 )
		);

		$result = $client->test_connection();

		// Stash the result in a transient instead of the URL so a subsequent
		// settings save (which redirects through options.php and preserves the
		// referer) does not re-show this notice.
		set_transient(
			self::TEST_RESULT_TRANSIENT,
			[
				'success' => ! empty( $result['success'] ),
				'message' => empty( $result['success'] ) ? (string) ( $result['error']['message'] ?? 'Unknown error' ) : '',
			],
			60
		);

		$redirect = add_query_arg(
			[
				'page' => self::PAGE_SLUG,
				'tab'  => 'settings',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * AJAX: autosave the connection settings (endpoint/auth type/token) from the form, then test them.
	 *
	 * The classic "Test connection" tested the *saved* settings, so it failed right after a user typed
	 * a new subdomain/token but had not saved yet. This persists the environment settings first (so the
	 * test — and any later sync — use exactly what the user entered) and returns the result inline.
	 */
	public function handle_test_connection_ajax(): void {
		check_ajax_referer( 'skwirrel_test_connection_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'skwirrel-pim-sync' ) ], 403 );
		}

		$endpoint = isset( $_POST['endpoint_url'] ) ? self::normalize_endpoint_url( esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) ) : '';
		$token_in = isset( $_POST['auth_token'] ) ? trim( (string) wp_unslash( $_POST['auth_token'] ) ) : '';

		if ( '' === $endpoint ) {
			wp_send_json_error( [ 'message' => __( 'Enter a Skwirrel subdomain first.', 'skwirrel-pim-sync' ) ] );
		}

		// Autosave the environment/connection settings so the saved config matches what is tested.
		$opts = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts['endpoint_url'] = $endpoint;
		$opts['auth_type']    = 'token';
		$token                = $this->sanitize_token( $token_in ); // New token, or the stored one when masked/empty.
		update_option( self::TOKEN_OPTION_KEY, $token, false );
		$opts['auth_token'] = '' !== self::get_auth_token() ? self::MASK : '';
		update_option( self::OPTION_KEY, $opts, false );

		$client = new Skwirrel_WC_Sync_JsonRpc_Client(
			$endpoint,
			'token',
			self::get_auth_token(),
			(int) ( $opts['timeout'] ?? 30 ),
			(int) ( $opts['retries'] ?? 2 )
		);
		$result = $client->test_connection();

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( [ 'message' => __( 'Connection successful — settings saved.', 'skwirrel-pim-sync' ) ] );
		}
		wp_send_json_error( [ 'message' => (string) ( $result['error']['message'] ?? __( 'Connection failed.', 'skwirrel-pim-sync' ) ) ] );
	}

	/**
	 * AJAX: discover Skwirrel product statuses on demand.
	 *
	 * Skwirrel exposes no "list statuses" endpoint, so this walks the product feed
	 * (with include_product_status) and records the distinct statuses it finds. The
	 * built-in presets (Draft/Available/Discontinued) are always shown regardless; this
	 * surfaces any additional statuses a tenant has defined without waiting for a full
	 * sync. Returns whether the caller should reload to render the new rows.
	 *
	 * Scanning only page 1 would miss a status used exclusively by products further in and still
	 * report success, so the scan walks the whole feed — but *across requests*, not inside one.
	 * Each call processes at most STATUS_SCAN_CHUNK_PAGES pages and stops early once
	 * STATUS_SCAN_BUDGET seconds have elapsed, then hands the caller the next page to ask for;
	 * the browser drives the continuation. A large catalogue therefore cannot park dozens of
	 * serial API calls in one PHP request, where an FPM, proxy or browser timeout would kill the
	 * refresh with nothing returned. STATUS_SCAN_MAX_PAGES bounds the scan as a whole; when it is
	 * hit the response says how far it got instead of claiming completeness.
	 *
	 * Running totals live in a per-user transient rather than round-tripping through the browser,
	 * so the final message reports what the whole scan actually recorded.
	 */
	public function handle_refresh_statuses(): void {
		check_ajax_referer( 'skwirrel_refresh_statuses_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'skwirrel-pim-sync' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		$page      = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$totals    = 1 === $page ? [
			'added'     => 0,
			'refreshed' => 0,
		] : $this->get_status_scan_totals();
		$opts      = get_option( self::OPTION_KEY, [] );
		$opts      = is_array( $opts ) ? $opts : [];
		$limit     = max( 10, min( 500, (int) ( $opts['batch_size'] ?? 100 ) ) );
		$started   = microtime( true );
		$complete  = true;
		$last_page = false;
		$scanned   = 0;

		do {
			$products = $this->fetch_statuses( $page, $limit );
			if ( is_wp_error( $products ) ) {
				if ( 1 === $page ) {
					$this->clear_status_scan_totals();
					wp_send_json_error( [ 'message' => $products->get_error_message() ] );
				}
				// A later page failed: keep what earlier pages recorded, but do not claim the
				// whole catalogue was scanned.
				$complete = false;
				break;
			}
			$counts               = Skwirrel_WC_Sync_Product_Mapper::record_statuses_from_products( $products );
			$totals['added']     += $counts['added'];
			$totals['refreshed'] += $counts['refreshed'];
			$last_page            = count( $products ) < $limit;
			++$page;
			++$scanned;
			if ( $last_page ) {
				break;
			}
			if ( $page > self::STATUS_SCAN_MAX_PAGES ) {
				$complete = false;
				break;
			}
			// More to do, but not in this request: hand the next page back to the caller.
			if ( $scanned >= self::STATUS_SCAN_CHUNK_PAGES || ( microtime( true ) - $started ) >= self::STATUS_SCAN_BUDGET ) {
				$this->save_status_scan_totals( $totals );
				wp_send_json_success(
					[
						'added'     => $totals['added'],
						'refreshed' => $totals['refreshed'],
						'done'      => false,
						'next_page' => $page,
						'reload'    => false,
						/* translators: %d: number of products inspected so far. */
						'message'   => sprintf( __( 'Scanning… %d products checked', 'skwirrel-pim-sync' ), ( $page - 1 ) * $limit ),
					]
				);
			}
		} while ( true );

		$this->clear_status_scan_totals();

		if ( $totals['added'] > 0 ) {
			/* translators: %d: number of newly discovered product statuses. */
			$message = sprintf( _n( '%d new status found — reloading…', '%d new statuses found — reloading…', $totals['added'], 'skwirrel-pim-sync' ), $totals['added'] );
		} elseif ( $totals['refreshed'] > 0 ) {
			$message = __( 'Status details updated — reloading…', 'skwirrel-pim-sync' );
		} elseif ( $complete ) {
			$message = __( 'No new statuses found. Every status in the catalogue is already listed.', 'skwirrel-pim-sync' );
		} else {
			$message = __( 'No new statuses found so far — the scan stopped before the end of the catalogue.', 'skwirrel-pim-sync' );
		}
		wp_send_json_success(
			[
				'added'     => $totals['added'],
				'refreshed' => $totals['refreshed'],
				'done'      => true,
				'complete'  => $complete,
				'reload'    => $totals['added'] > 0 || $totals['refreshed'] > 0,
				'message'   => $message,
			]
		);
	}

	/** Transient key holding one admin's running status-scan totals. */
	private function status_scan_totals_key(): string {
		return self::STATUS_SCAN_TOTALS_TRANSIENT . '_' . get_current_user_id();
	}

	/**
	 * Running totals for a scan that is continuing across requests.
	 *
	 * @return array{added:int, refreshed:int}
	 */
	private function get_status_scan_totals(): array {
		$stored = get_transient( $this->status_scan_totals_key() );
		if ( ! is_array( $stored ) ) {
			return [
				'added'     => 0,
				'refreshed' => 0,
			];
		}
		return [
			'added'     => (int) ( $stored['added'] ?? 0 ),
			'refreshed' => (int) ( $stored['refreshed'] ?? 0 ),
		];
	}

	/**
	 * @param array{added:int, refreshed:int} $totals Running totals.
	 */
	private function save_status_scan_totals( array $totals ): void {
		set_transient( $this->status_scan_totals_key(), $totals, 15 * MINUTE_IN_SECONDS );
	}

	private function clear_status_scan_totals(): void {
		delete_transient( $this->status_scan_totals_key() );
	}

	/**
	 * Fetch one page of products from the API so their distinct statuses can be recorded.
	 *
	 * Isolated behind one method so the discovery source can later be swapped for a
	 * dedicated endpoint (should Skwirrel add one) without touching the caller/UI.
	 *
	 * @param int $page  1-based page number.
	 * @param int $limit Products per page.
	 * @return array<int, mixed>|WP_Error Raw API products, or an error to surface.
	 */
	private function fetch_statuses( int $page = 1, int $limit = 100 ) {
		$opts     = get_option( self::OPTION_KEY, [] );
		$opts     = is_array( $opts ) ? $opts : [];
		$endpoint = self::normalize_endpoint_url( (string) ( $opts['endpoint_url'] ?? '' ) );
		$token    = self::get_auth_token();
		if ( '' === $endpoint || '' === $token ) {
			return new WP_Error( 'skwirrel_no_config', __( 'Set the Skwirrel endpoint and API token (and save) before refreshing statuses.', 'skwirrel-pim-sync' ) );
		}

		// Deliberately NOT the saved timeout/retries: at their maxima one call could occupy this
		// request for minutes (120s × up to six attempts), which would defeat the chunking above —
		// the browser or an FPM/proxy timeout would kill the refresh with no continuation returned.
		// A discovery scan is a best-effort read, so it fails fast and resumes on the next chunk.
		$client = new Skwirrel_WC_Sync_JsonRpc_Client(
			$endpoint,
			(string) ( $opts['auth_type'] ?? 'token' ),
			$token,
			min( self::STATUS_SCAN_TIMEOUT, max( 5, (int) ( $opts['timeout'] ?? 30 ) ) ),
			min( self::STATUS_SCAN_RETRIES, max( 0, (int) ( $opts['retries'] ?? 2 ) ) )
		);
		$result = $client->call(
			'getProducts',
			[
				'page'                         => max( 1, $page ),
				'limit'                        => $limit,
				'include_product_status'       => true,
				'include_product_translations' => false,
				'include_attachments'          => false,
				'include_trade_items'          => false,
				'include_categories'           => false,
			]
		);

		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'skwirrel_api_error', (string) ( $result['error']['message'] ?? __( 'The request to Skwirrel failed.', 'skwirrel-pim-sync' ) ) );
		}

		$products = $result['result']['products'] ?? [];
		return is_array( $products ) ? $products : [];
	}

	public function handle_sync_now(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( 'skwirrel_wc_sync_run', '_wpnonce' );

		// Show the "sync running" badge from the moment the user clicks.
		set_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS, (string) time(), 60 );

		$redirect = add_query_arg(
			[
				'page' => self::PAGE_SLUG,
				'tab'  => 'sync',
			],
			admin_url( 'admin.php' )
		);

		// Preferred path: enqueue the resumable batched runner via Action Scheduler. One bounded step
		// per async action means no single server time limit (php-fpm request_terminate_timeout, nginx
		// fastcgi_read_timeout, proxy gateway) can kill the whole run, and it resumes automatically —
		// fixing manual full syncs that died part-way and had to be restarted by hand.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			Skwirrel_WC_Sync_Service::start_async( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Fallback (no Action Scheduler): detached loopback request that runs the sync synchronously.
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::BG_SYNC_TRANSIENT . '_' . $token, '1', 120 );

		$url = add_query_arg(
			[
				'action' => self::BG_SYNC_ACTION,
				'token'  => $token,
			],
			admin_url( 'admin-ajax.php' )
		);

		wp_safe_redirect( $redirect );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		wp_remote_post(
			$url,
			[
				'blocking'  => false,
				'timeout'   => 0.01,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			]
		);

		exit;
	}

	public function handle_background_sync(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- uses transient-based token instead of nonce
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		if ( empty( $token ) || 32 !== strlen( $token ) || ! ctype_xdigit( $token ) ) {
			wp_die( 'Invalid request', 403 );
		}
		if ( '1' !== get_transient( self::BG_SYNC_TRANSIENT . '_' . $token ) ) {
			wp_die( 'Invalid or expired token', 403 );
		}
		delete_transient( self::BG_SYNC_TRANSIENT . '_' . $token );

		$service = new Skwirrel_WC_Sync_Service();
		$service->run_sync( false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL );

		delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
		Skwirrel_WC_Sync_History::release_sync_mutex();

		wp_die( '', 200 );
	}

	public function handle_purge_now(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( 'skwirrel_wc_sync_purge', '_wpnonce' );

		$permanent = ! empty( $_POST['skwirrel_purge_empty_trash'] );
		$mode      = $permanent ? 'delete' : 'trash';

		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::BG_PURGE_TRANSIENT . '_' . $token, $mode, 120 );

		$url = add_query_arg(
			[
				'action' => self::BG_PURGE_ACTION,
				'token'  => $token,
			],
			admin_url( 'admin-ajax.php' )
		);

		$redirect = add_query_arg(
			[
				'page'  => self::PAGE_SLUG,
				'tab'   => 'settings',
				'purge' => 'queued',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		wp_remote_post(
			$url,
			[
				'blocking'  => false,
				'timeout'   => 0.01,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			]
		);

		exit;
	}

	public function handle_background_purge(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- uses transient-based token instead of nonce
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		if ( empty( $token ) || 32 !== strlen( $token ) || ! ctype_xdigit( $token ) ) {
			wp_die( 'Invalid request', 403 );
		}
		$mode = get_transient( self::BG_PURGE_TRANSIENT . '_' . $token );
		if ( false === $mode ) {
			wp_die( 'Invalid or expired token', 403 );
		}
		delete_transient( self::BG_PURGE_TRANSIENT . '_' . $token );

		$permanent     = ( 'delete' === $mode );
		$purge_handler = new Skwirrel_WC_Sync_Purge_Handler( new Skwirrel_WC_Sync_Logger() );
		$purge_handler->purge_all( $permanent );

		wp_die( '', 200 );
	}

	public function handle_clear_history(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( 'skwirrel_wc_sync_clear_history', '_wpnonce' );

		$period  = isset( $_POST['history_period'] ) ? sanitize_text_field( wp_unslash( $_POST['history_period'] ) ) : 'all';
		$history = Skwirrel_WC_Sync_History::get_sync_history();

		if ( 'all' === $period ) {
			Skwirrel_WC_Sync_History::delete_log_files_for_entries( $history );
			$history = [];
		} else {
			$days    = (int) $period;
			$cutoff  = time() - ( $days * DAY_IN_SECONDS );
			$kept    = [];
			$removed = [];
			foreach ( $history as $entry ) {
				if ( ! empty( $entry['timestamp'] ) && $entry['timestamp'] >= $cutoff ) {
					$kept[] = $entry;
				} else {
					$removed[] = $entry;
				}
			}
			// Only delete log files not referenced by kept entries.
			$active_files = [];
			foreach ( $kept as $entry ) {
				$f = $entry['log_file'] ?? '';
				if ( '' !== $f ) {
					$active_files[ $f ] = true;
				}
			}
			foreach ( $removed as $entry ) {
				$f = $entry['log_file'] ?? '';
				if ( '' !== $f && ! isset( $active_files[ $f ] ) ) {
					Skwirrel_WC_Sync_History::delete_log_file( $f );
				}
			}
			$history = $kept;
		}

		update_option( 'skwirrel_wc_sync_history', $history, false );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'tab'     => 'sync',
					'history' => 'cleared',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Reset Skwirrel sync settings to a blank state.
	 *
	 * Deletes the main settings option, the auth token, and the runtime/state options the
	 * sync flow accumulates (last sync timestamp, force-full flag, slug-resync flag).
	 * Cancels every queued Action Scheduler job in the `skwirrel-pim-sync` group and
	 * invalidates any persistent object cache entry for the settings option so that
	 * aggressive caches (LiteSpeed Object Cache, Redis with stale propagation) do not
	 * serve the old value on the next request.
	 *
	 * Intentionally leaves products, attachments, categories, brands, and sync history alone:
	 * this is the escape-hatch for when settings refuse to persist, not a product purge —
	 * the existing "Delete all Skwirrel products" button covers that.
	 */
	public function handle_reset_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( 'skwirrel_wc_sync_reset_settings', '_wpnonce' );

		$option_keys = [
			self::OPTION_KEY,
			self::TOKEN_OPTION_KEY,
			'skwirrel_wc_sync_last_sync',
			'skwirrel_wc_sync_force_full_sync',
			'skwirrel_wc_sync_slug_resync_needed',
			'skwirrel_wc_sync_permalinks',
		];
		if ( class_exists( 'Skwirrel_WC_Sync_Connectors' ) ) {
			$option_keys[] = Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION;
		}
		foreach ( $option_keys as $key ) {
			delete_option( $key );
		}

		delete_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
		Skwirrel_WC_Sync_History::release_sync_mutex();
		delete_transient( self::BG_SYNC_TRANSIENT );
		delete_transient( self::BG_PURGE_TRANSIENT );
		delete_transient( self::TEST_RESULT_TRANSIENT );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], 'skwirrel-pim-sync' );
		}

		$this->bust_settings_cache();

		( new Skwirrel_WC_Sync_Logger() )->info( 'Settings reset by admin — all configuration options deleted (including last_sync checkpoint and force_full_sync flag), scheduled jobs cancelled, caches flushed. Next scheduled sync will run as initial full pass.' );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'  => self::PAGE_SLUG,
					'tab'   => 'settings',
					'reset' => 'done',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX handler: save the "update slug on re-sync" toggle.
	 */
	public function handle_save_slug_resync(): void {
		check_ajax_referer( 'skwirrel_slug_resync_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Access denied', 403 );
		}
		$enabled                       = ! empty( $_POST['enabled'] );
		$opts                          = get_option( Skwirrel_WC_Sync_Permalink_Settings::OPTION_KEY, [] );
		$opts['update_slug_on_resync'] = $enabled;
		update_option( Skwirrel_WC_Sync_Permalink_Settings::OPTION_KEY, $opts );
		wp_send_json_success();
	}

	/**
	 * AJAX handler: view a sync log file.
	 */
	public function handle_view_log(): void {
		check_ajax_referer( 'skwirrel_view_log_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Access denied', 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
		$filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
		if ( ! preg_match( '/^sync-(manual|scheduled)-[\d-]+\.log$/', $filename ) ) {
			wp_send_json_error( 'Invalid filename' );
		}

		$path = Skwirrel_WC_Sync_Logger::get_log_directory() . $filename;
		if ( ! file_exists( $path ) ) {
			wp_send_json_error( 'Log file not found' );
		}

		$chunk_size = 100 * 1024; // 100 KB per chunk
		$size       = filesize( $path );
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct read of log file
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			wp_send_json_error( 'Could not open log file' );
		}

		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Direct read of log file
		$content = fread( $fh, $chunk_size );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct read of log file
		fclose( $fh );

		$bytes_read = strlen( $content );
		$new_offset = $offset + $bytes_read;
		$has_more   = $new_offset < $size;

		wp_send_json_success(
			[
				'content'  => $content,
				'offset'   => $new_offset,
				'size'     => $size,
				'has_more' => $has_more,
			]
		);
	}

	/**
	 * AJAX handler: tail the currently active or most recent sync log.
	 *
	 * Unlike handle_view_log, the client does not supply a filename — the server
	 * resolves the active log (or latest if none running) so the live viewer
	 * follows sync runs across page refreshes.
	 */
	public function handle_tail_log(): void {
		check_ajax_referer( 'skwirrel_view_log_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Access denied', 403 );
		}

		$filename = Skwirrel_WC_Sync_Logger::get_active_or_latest_log_filename();
		if ( null === $filename ) {
			wp_send_json_success(
				[
					'filename'   => null,
					'content'    => '',
					'offset'     => 0,
					'size'       => 0,
					'has_more'   => false,
					'is_running' => (bool) get_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS ),
				]
			);
		}

		$path = Skwirrel_WC_Sync_Logger::get_log_directory() . $filename;
		if ( ! file_exists( $path ) ) {
			wp_send_json_error( 'Log file not found' );
		}

		$chunk_size = 256 * 1024;
		$size       = (int) filesize( $path );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
		$client_filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
		if ( '' !== $client_filename && $client_filename !== $filename ) {
			$offset = 0;
		}

		if ( $offset > $size ) {
			$offset = 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct read of log file
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			wp_send_json_error( 'Could not open log file' );
		}

		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Direct read of log file
		$content = fread( $fh, $chunk_size );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct read of log file
		fclose( $fh );

		$bytes_read = strlen( (string) $content );
		$new_offset = $offset + $bytes_read;

		wp_send_json_success(
			[
				'filename'   => $filename,
				'content'    => $content,
				'offset'     => $new_offset,
				'size'       => $size,
				'has_more'   => $new_offset < $size,
				'is_running' => (bool) get_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS ),
			]
		);
	}

	/**
	 * AJAX handler: download a sync log file.
	 */
	public function handle_download_log(): void {
		check_ajax_referer( 'skwirrel_download_log_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'skwirrel-pim-sync' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
		$filename = isset( $_GET['filename'] ) ? sanitize_text_field( wp_unslash( $_GET['filename'] ) ) : '';
		if ( ! preg_match( '/^sync-(manual|scheduled)-[\d-]+\.log$/', $filename ) ) {
			wp_die( esc_html__( 'Invalid filename.', 'skwirrel-pim-sync' ), 400 );
		}

		$path = Skwirrel_WC_Sync_Logger::get_log_directory() . $filename;
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Log file not found.', 'skwirrel-pim-sync' ), 404 );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct file download
		readfile( $path );
		exit;
	}

	/**
	 * AJAX handler: abort the running sync.
	 */
	public function handle_abort_sync(): void {
		check_ajax_referer( 'skwirrel_abort_sync_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Access denied', 403 );
		}

		Skwirrel_WC_Sync_History::request_abort();
		wp_send_json_success();
	}

	/**
	 * AJAX: report whether a sync is in progress + the current banner markup, for the reactive poller.
	 */
	public function handle_sync_status(): void {
		check_ajax_referer( 'skwirrel_sync_status_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Access denied', 403 );
		}
		$in_progress = (bool) get_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
		$summary     = Skwirrel_WC_Sync_Admin_Dashboard::get_current_step_summary();
		wp_send_json_success(
			[
				'in_progress' => $in_progress,
				// Full banner markup for the plugin's own pages; step/counter for the corner toast elsewhere.
				'banner_html' => $in_progress ? Skwirrel_WC_Sync_Admin_Dashboard::get_sync_banner_html() : '',
				'step'        => $in_progress ? $summary['label'] : '',
				'counter'     => $in_progress ? $summary['counter'] : '',
			]
		);
	}

	/**
	 * Render the compact, movable, dismissible sync toast in the corner of every admin page EXCEPT the
	 * plugin's own pages (which show the full in-page banner). Rendered hidden; the poller shows it while
	 * a sync runs and updates the step + counter in place — no page reload.
	 */
	public function render_status_toast(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}
		$live_log_url = add_query_arg( 'tab', 'debug', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '#skwirrel-live-log';
		?>
		<div id="skwirrel-sync-toast" class="skw-toast" hidden>
			<div class="skw-toast-head">
				<span class="skw-toast-title"><?php esc_html_e( 'Skwirrel sync', 'skwirrel-pim-sync' ); ?></span>
				<div class="skw-toast-actions">
					<button type="button" class="skw-toast-move" aria-label="<?php esc_attr_e( 'Move to the other corner', 'skwirrel-pim-sync' ); ?>" title="<?php esc_attr_e( 'Move to the other corner', 'skwirrel-pim-sync' ); ?>">
						<svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M10 3a1 1 0 0 1 .7.29l3 3a1 1 0 1 1-1.4 1.42L11 5.4V9a1 1 0 1 1-2 0V5.41L7.7 7.71A1 1 0 0 1 6.3 6.3l3-3A1 1 0 0 1 10 3Zm0 14a1 1 0 0 1-.7-.29l-3-3a1 1 0 1 1 1.4-1.42L9 14.6V11a1 1 0 1 1 2 0v3.59l1.3-1.3a1 1 0 0 1 1.4 1.42l-3 3A1 1 0 0 1 10 17Z" /></svg>
					</button>
					<button type="button" class="skw-toast-close" aria-label="<?php esc_attr_e( 'Hide for this session', 'skwirrel-pim-sync' ); ?>" title="<?php esc_attr_e( 'Hide for this session', 'skwirrel-pim-sync' ); ?>">&times;</button>
				</div>
			</div>
			<div class="skw-toast-body">
				<span class="skw-toast-step"></span>
				<span class="skw-toast-counter"></span>
			</div>
			<a class="skw-toast-loglink" href="<?php echo esc_url( $live_log_url ); ?>"><?php esc_html_e( 'View live log', 'skwirrel-pim-sync' ); ?> →</a>
		</div>
		<?php
	}

	/**
	 * Enqueue the reactive status poller + banner styles on every admin page (for users who can sync),
	 * so a running sync stays visible and updates in place — no page reload — wherever the user is.
	 */
	public function enqueue_status_banner_assets(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Banner styles. The CSS vars are re-declared on #skwirrel-sync-banner in dashboard.css so the
		// banner renders correctly outside the .skw-dashboard wrapper. Same handle as the dashboard
		// style, so WordPress loads it only once on the plugin's own pages.
		wp_enqueue_style( 'skwirrel-pim-sync-dashboard', SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/dashboard.css', [], SKWIRREL_WC_SYNC_VERSION ); // @phpstan-ignore constant.notFound

		wp_register_script( 'skwirrel-pim-sync-status', false, [], SKWIRREL_WC_SYNC_VERSION, true );
		wp_enqueue_script( 'skwirrel-pim-sync-status' );

		$dashboard_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$completed_html =
			'<div class="skw-progress-banner skw-progress-done">'
			. '<div class="skw-progress-header">'
			. '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>'
			. '<span>' . esc_html__( 'Sync completed.', 'skwirrel-pim-sync' ) . '</span>'
			. '<a href="' . esc_url( $dashboard_url ) . '" class="skw-btn skw-btn-live-log">' . esc_html__( 'View results', 'skwirrel-pim-sync' ) . '</a>'
			. '</div></div>';

		wp_localize_script(
			'skwirrel-pim-sync-status',
			'skwirrelPimSyncStatus',
			[
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'skwirrel_sync_status_nonce' ),
				'abortNonce'     => wp_create_nonce( 'skwirrel_abort_sync_nonce' ),
				'interval'       => 4000,
				'completedHtml'  => $completed_html,
				'completedLabel' => __( 'Sync completed.', 'skwirrel-pim-sync' ),
				'abortConfirm'   => __( 'Stop the running sync?', 'skwirrel-pim-sync' ),
				'stoppingLabel'  => __( 'Stopping…', 'skwirrel-pim-sync' ),
				'errorLabel'     => __( 'Error', 'skwirrel-pim-sync' ),
			]
		);

		wp_add_inline_script( 'skwirrel-pim-sync-status', $this->status_poller_js() );
	}

	/**
	 * The reactive status poller (JS body, no <script> wrapper): polls the status endpoint and swaps
	 * #skwirrel-sync-banner in place, and wires the Stop-sync button via event delegation so it keeps
	 * working after every re-render and on every admin page. Pauses while the tab is hidden.
	 */
	private function status_poller_js(): string {
		return '(function(){'
			. ' var cfg = window.skwirrelPimSyncStatus; if (!cfg) return;'
			. ' var banner = document.getElementById("skwirrel-sync-banner");'
			. ' var toast = document.getElementById("skwirrel-sync-toast");'
			. ' if (!banner && !toast) return;'
			. ' var active = banner ? !!banner.querySelector(".skw-progress-banner") : false;'
			// Toast controls: position preference (persisted) + hide-for-session.
			. ' function lsGet(k){ try { return window.localStorage.getItem(k); } catch(e){ return null; } }'
			. ' function lsSet(k,v){ try { window.localStorage.setItem(k,v); } catch(e){} }'
			. ' function closed(){ try { return window.sessionStorage.getItem("skwirrelToastClosed")==="1"; } catch(e){ return false; } }'
			. ' if (toast) {'
			. '  if (lsGet("skwirrelToastPos")==="top") toast.classList.add("skw-toast-top");'
			. '  var moveBtn = toast.querySelector(".skw-toast-move");'
			. '  var closeBtn = toast.querySelector(".skw-toast-close");'
			. '  if (moveBtn) moveBtn.addEventListener("click", function(){ var t = toast.classList.toggle("skw-toast-top"); lsSet("skwirrelToastPos", t ? "top" : "bottom"); });'
			. '  if (closeBtn) closeBtn.addEventListener("click", function(){ toast.hidden = true; try { window.sessionStorage.setItem("skwirrelToastClosed","1"); } catch(e){} });'
			. ' }'
			// Stop-sync (banner only) via delegation, so it survives re-renders and works everywhere.
			. ' document.addEventListener("click", function(e){'
			. '  var btn = e.target.closest ? e.target.closest(".skw-btn-abort-sync") : null;'
			. '  if (!btn) return;'
			. '  e.preventDefault();'
			. '  if (!window.confirm(cfg.abortConfirm)) return;'
			. '  btn.disabled = true; btn.textContent = cfg.stoppingLabel;'
			. '  var fd = new FormData(); fd.append("action","skwirrel_wc_sync_abort"); fd.append("_nonce", cfg.abortNonce);'
			. '  fetch(cfg.ajaxUrl, {method:"POST", body:fd}).then(function(r){return r.json();}).then(function(d){ if(!d||!d.success){ btn.textContent = cfg.errorLabel; } }).catch(function(){});'
			. ' });'
			. ' function render(d){'
			. '  if (banner) {'
			. '   if (d.in_progress) { banner.innerHTML = d.banner_html; active = true; }'
			. '   else if (active) { active = false; banner.innerHTML = cfg.completedHtml; }'
			. '   return;'
			. '  }'
			. '  if (!toast) return;'
			. '  if (d.in_progress && !closed()) {'
			. '   toast.querySelector(".skw-toast-step").textContent = d.step || "";'
			. '   toast.querySelector(".skw-toast-counter").textContent = d.counter || "";'
			. '   toast.hidden = false; active = true;'
			. '  } else if (!d.in_progress && active) {'
			. '   active = false;'
			. '   toast.querySelector(".skw-toast-step").textContent = cfg.completedLabel;'
			. '   toast.querySelector(".skw-toast-counter").textContent = "";'
			. '   toast.classList.add("skw-toast-done");'
			. '   setTimeout(function(){ toast.hidden = true; toast.classList.remove("skw-toast-done"); }, 6000);'
			. '  }'
			. ' }'
			. ' function poll(){'
			. '  if (document.hidden) { setTimeout(poll, cfg.interval); return; }'
			. '  var fd = new FormData(); fd.append("action","skwirrel_wc_sync_status"); fd.append("_nonce", cfg.nonce);'
			. '  fetch(cfg.ajaxUrl, {method:"POST", body:fd})'
			. '   .then(function(r){ return r.json(); })'
			. '   .then(function(r){ if (r && r.success) render(r.data); })'
			. '   .catch(function(){})'
			. '   .finally(function(){ setTimeout(poll, cfg.interval); });'
			. ' }'
			. ' setTimeout(poll, 600);'
			. '})();';
	}

	public function enqueue_assets( string $hook ): void {
		// Only load plugin page assets on our settings page.
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'skwirrel-pim-sync-admin', SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/admin.css', [], SKWIRREL_WC_SYNC_VERSION ); // @phpstan-ignore constant.notFound
		wp_enqueue_style( 'skwirrel-pim-sync-dashboard', SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/dashboard.css', [], SKWIRREL_WC_SYNC_VERSION ); // @phpstan-ignore constant.notFound
		// Make the plugin header follow the user's admin colour scheme (the WP menu colour).
		wp_add_inline_style( 'skwirrel-pim-sync-dashboard', '.skw-dashboard{--skw-header-bg:' . esc_attr( self::header_background_color() ) . ';}' );
		// Google Fonts URL: pass the plugin version (not null) so Plugin Check
		// is satisfied. Google ignores any extra query params anyway, so this
		// only changes browser cache busting on plugin upgrades.
		wp_enqueue_style( 'skwirrel-pim-sync-inter-font', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', [], SKWIRREL_WC_SYNC_VERSION );

		// Admin page JS (purge confirmation + auto-reload).
		wp_register_script( 'skwirrel-pim-sync-admin', false, [], SKWIRREL_WC_SYNC_VERSION, true );
		wp_enqueue_script( 'skwirrel-pim-sync-admin' );

		wp_localize_script(
			'skwirrel-pim-sync-admin',
			'skwirrelPimSync',
			[
				'purgeConfirmPermanent'  => __( 'WARNING: All Skwirrel products will be PERMANENTLY deleted. This cannot be undone!\n\nAre you sure?', 'skwirrel-pim-sync' ),
				'purgeConfirmTrash'      => __( 'All Skwirrel products will be moved to the trash.\n\nAre you sure?', 'skwirrel-pim-sync' ),
				'clearHistoryConfirm'    => __( 'Delete all sync history?', 'skwirrel-pim-sync' ),
				'resetSettingsConfirm'   => __( 'Reset all Skwirrel sync settings? Endpoint URL, API token, sync schedule and slug rules will be deleted, and all scheduled syncs will be cancelled. Products, media, categories and sync history are kept.\n\nAre you sure?', 'skwirrel-pim-sync' ),
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'slugResyncNonce'        => wp_create_nonce( 'skwirrel_slug_resync_nonce' ),
				'viewLogNonce'           => wp_create_nonce( 'skwirrel_view_log_nonce' ),
				'downloadLogNonce'       => wp_create_nonce( 'skwirrel_download_log_nonce' ),
				'abortSyncNonce'         => wp_create_nonce( 'skwirrel_abort_sync_nonce' ),
				'abortSyncConfirm'       => __( 'Stop the running sync?', 'skwirrel-pim-sync' ),
				'testConnectionNonce'    => wp_create_nonce( 'skwirrel_test_connection_nonce' ),
				'testingLabel'           => __( 'Testing…', 'skwirrel-pim-sync' ),
				'testSubdomainLabel'     => __( 'Enter a subdomain first.', 'skwirrel-pim-sync' ),
				'testFailedLabel'        => __( 'Connection failed.', 'skwirrel-pim-sync' ),
				'testNetworkLabel'       => __( 'Network error.', 'skwirrel-pim-sync' ),
				'refreshStatusesNonce'   => wp_create_nonce( 'skwirrel_refresh_statuses_nonce' ),
				'refreshStatusesLabel'   => __( 'Fetching…', 'skwirrel-pim-sync' ),
				'refreshStatusesError'   => __( 'Could not refresh statuses.', 'skwirrel-pim-sync' ),
				'refreshStatusesUnsaved' => __( 'Statuses updated. Save your changes to see the new rows — the page was not reloaded because this form has unsaved edits.', 'skwirrel-pim-sync' ),
				/*
				 * Contract with the settings tab strip: the IDs of the fields whose validation
				 * failed on this request, in reported order. Each one also carries
				 * `data-skw-error-field="{id}"` on its `.skw-field` wrapper, so a consumer can go
				 * from an ID to the field block, and from there to the `[data-skw-panel]` holding
				 * it, without knowing which tab any field lives on. Empty on a clean render.
				 */
				'errorFields'            => self::failing_field_ids(),
			]
		);

		wp_add_inline_script(
			'skwirrel-pim-sync-admin',
			'(function() {'
			. ' var form = document.getElementById("skwirrel-purge-form");'
			. ' if (form) {'
			. '  form.addEventListener("submit", function(e) {'
			. '   var permanent = document.getElementById("skwirrel-purge-permanent").checked;'
			. '   var msg = permanent ? skwirrelPimSync.purgeConfirmPermanent : skwirrelPimSync.purgeConfirmTrash;'
			. '   if (!confirm(msg)) { e.preventDefault(); }'
			. '  });'
			. ' }'
			. ' var resetForm = document.getElementById("skwirrel-reset-settings-form");'
			. ' if (resetForm) {'
			. '  resetForm.addEventListener("submit", function(e) {'
			. '   if (!confirm(skwirrelPimSync.resetSettingsConfirm)) { e.preventDefault(); }'
			. '  });'
			. ' }'
			. ' var langSelect = document.getElementById("image_language_select");'
			. ' if (langSelect) {'
			. '  langSelect.addEventListener("change", function() {'
			. '   var c = document.getElementById("image_language_custom_wrap");'
			. '   c.style.display = this.value === "_custom" ? "inline-block" : "none";'
			. '   if (this.value !== "_custom") { document.getElementById("image_language_custom").value = ""; }'
			. '  });'
			. ' }'
			. ' var subInput = document.getElementById("skwirrel_subdomain");'
			. ' var urlField = document.getElementById("endpoint_url");'
			. ' function skwNormalizeSubdomain(raw) {'
			. '  var s = (raw || "").trim().toLowerCase();'
			. '  s = s.replace(/^https?:\\/\\//, "");'
			. '  s = s.replace(/\\/.*$/, "");'
			. '  while (/\\.skwirrel\\.eu$/.test(s)) { s = s.replace(/\\.skwirrel\\.eu$/, ""); }'
			. '  return s.replace(/^\\.+|\\.+$/g, "");'
			. ' }'
			. ' function skwApplySubdomain(v) {'
			. '  var clean = skwNormalizeSubdomain(v);'
			. '  if (urlField) urlField.value = clean ? "https://" + clean + ".skwirrel.eu/jsonrpc" : "";'
			. '  var label = clean || "<your-subdomain>";'
			. '  var tokenDomain = document.getElementById("skwirrel-token-domain");'
			. '  var tokenLink = document.getElementById("skwirrel-token-link");'
			. '  if (tokenDomain) tokenDomain.textContent = label;'
			. '  if (tokenLink && clean) tokenLink.href = "https://" + clean + ".skwirrel.eu/data/webservice";'
			. '  var catLink = document.getElementById("skwirrel-categories-link");'
			. '  document.querySelectorAll(".skwirrel-link-domain").forEach(function(el) { el.textContent = label; });'
			. '  if (catLink && clean) catLink.href = "https://" + clean + ".skwirrel.eu/base/categories";'
			. '  var selLink = document.getElementById("skwirrel-selections-link");'
			. '  if (selLink && clean) selLink.href = "https://" + clean + ".skwirrel.eu/data/selections";'
			. ' }'
			. ' if (subInput && urlField) {'
			. '  subInput.addEventListener("input", function() { skwApplySubdomain(this.value); });'
			. '  subInput.addEventListener("blur", function() {'
			. '   var clean = skwNormalizeSubdomain(this.value);'
			. '   if (clean !== this.value) { this.value = clean; }'
			. '   skwApplySubdomain(clean);'
			. '  });'
			. '  subInput.addEventListener("paste", function(e) {'
			. '   var pasted = (e.clipboardData || window.clipboardData).getData("text");'
			. '   var clean = skwNormalizeSubdomain(pasted);'
			. '   e.preventDefault();'
			. '   this.value = clean;'
			. '   skwApplySubdomain(clean);'
			. '  });'
			. ' }'
			. ' var historyBtn = document.getElementById("skwirrel-clear-history-btn");'
			. ' if (historyBtn) {'
			. '  historyBtn.addEventListener("click", function(e) {'
			. '   var period = this.form.history_period.value;'
			. '   if (period === "all" && !confirm(skwirrelPimSync.clearHistoryConfirm)) { e.preventDefault(); }'
			. '  });'
			. ' }'
			// Inline "Test connection": autosave the environment/connection settings, then test them.
			. ' var testBtn = document.getElementById("skwirrel-test-connection");'
			. ' if (testBtn) testBtn.addEventListener("click", function(){'
			. '  var res = document.getElementById("skwirrel-test-result");'
			. '  var subEl = document.getElementById("skwirrel_subdomain");'
			. '  var sub = subEl ? subEl.value.trim() : "";'
			. '  function setRes(txt, cls){ if(res){ res.textContent = txt; res.className = "skw-test-result" + (cls ? " " + cls : ""); } }'
			. '  if (!sub) { setRes(skwirrelPimSync.testSubdomainLabel, "skw-test-error"); if(subEl) subEl.focus(); return; }'
			. '  var tokenEl = document.getElementById("auth_token");'
			. '  var fd = new FormData();'
			. '  fd.append("action", "skwirrel_wc_sync_test_connection");'
			. '  fd.append("_nonce", skwirrelPimSync.testConnectionNonce);'
			. '  fd.append("endpoint_url", "https://" + sub + ".skwirrel.eu/jsonrpc");'
			. '  if (tokenEl) fd.append("auth_token", tokenEl.value);'
			. '  testBtn.disabled = true;'
			. '  setRes(skwirrelPimSync.testingLabel, "");'
			. '  fetch(skwirrelPimSync.ajaxUrl, { method: "POST", body: fd })'
			. '   .then(function(r){ return r.json(); })'
			. '   .then(function(r){'
			. '    var ok = r && r.success;'
			. '    var msg = (r && r.data && r.data.message) ? r.data.message : skwirrelPimSync.testFailedLabel;'
			. '    setRes(msg, ok ? "skw-test-success" : "skw-test-error");'
			. '   })'
			. '   .catch(function(){ setRes(skwirrelPimSync.testNetworkLabel, "skw-test-error"); })'
			. '   .finally(function(){ testBtn.disabled = false; });'
			. ' });'
			// "Refresh statuses from Skwirrel": walk the product feed server-side and record any new
			// statuses, then reload so the table re-renders them as configurable rows. The server
			// scans a bounded chunk per request and returns the next page to ask for, so a large
			// catalogue is covered across several requests instead of one that can time out.
			// A reload would throw away unsaved edits to this form (the AJAX action persists only
			// the discovered status metadata), so a dirty form is never reloaded — the admin is
			// told to save instead, and the new rows appear on that save.
			. ' var settingsForm = document.getElementById("skwirrel-sync-settings-form");'
			. ' var formDirty = false;'
			. ' if (settingsForm) {'
			. '  settingsForm.addEventListener("input", function(){ formDirty = true; });'
			. '  settingsForm.addEventListener("change", function(){ formDirty = true; });'
			. ' }'
			. ' var refreshBtn = document.getElementById("skwirrel-refresh-statuses");'
			. ' if (refreshBtn) refreshBtn.addEventListener("click", function(){'
			. '  var msgEl = document.getElementById("skwirrel-refresh-statuses-msg");'
			. '  function setMsg(t){ if (msgEl) msgEl.textContent = t || ""; }'
			. '  function scan(page){'
			. '   var fd = new FormData();'
			. '   fd.append("action", "skwirrel_wc_sync_refresh_statuses");'
			. '   fd.append("_nonce", skwirrelPimSync.refreshStatusesNonce);'
			. '   fd.append("page", String(page));'
			. '   return fetch(skwirrelPimSync.ajaxUrl, { method: "POST", body: fd })'
			. '    .then(function(r){ return r.json(); })'
			. '    .then(function(r){'
			. '     var msg = (r && r.data && r.data.message) ? r.data.message : skwirrelPimSync.refreshStatusesError;'
			. '     if (r && r.success && r.data && r.data.done === false && r.data.next_page) { setMsg(msg); return scan(r.data.next_page); }'
			. '     if (r && r.success && r.data && r.data.reload) {'
			. '      if (formDirty) { setMsg(skwirrelPimSync.refreshStatusesUnsaved); refreshBtn.disabled = false; return; }'
			. '      setMsg(msg); window.location.reload(); return;'
			. '     }'
			. '     setMsg(msg);'
			. '     refreshBtn.disabled = false;'
			. '    });'
			. '  }'
			. '  refreshBtn.disabled = true;'
			. '  setMsg(skwirrelPimSync.refreshStatusesLabel);'
			. '  scan(1).catch(function(){ setMsg(skwirrelPimSync.refreshStatusesError); refreshBtn.disabled = false; });'
			. ' });'
			// The Stop-sync button is wired by the global status poller (event delegation), so it keeps
			// working after the banner re-renders and on every admin page.
			. '})();'
		);

		// Required-field markers that follow their condition live. The server renders the correct
		// initial state, so with this script absent the markers are still right — they just stop
		// reacting until the next save. Each marker carries the settings keys that govern it
		// (`data-skw-req-when`), so the conditions are never restated here.
		wp_add_inline_script(
			'skwirrel-pim-sync-admin',
			'(function() {'
			. ' var markers = document.querySelectorAll("[data-skw-req][data-skw-req-when]");'
			. ' if (!markers.length) return;'
			. ' var watched = [];'
			. ' function apply(marker) {'
			. '  var keys = (marker.getAttribute("data-skw-req-when") || "").split(" ").filter(Boolean);'
			. '  var required = keys.some(function(name) {'
			. '   var box = document.querySelector(\'input[type=checkbox][name="\' + name + \'"]\');'
			. '   return !!(box && box.checked);'
			. '  });'
			. '  if (required) { marker.removeAttribute("hidden"); } else { marker.setAttribute("hidden", "hidden"); }'
			. '  var input = document.getElementById(marker.getAttribute("data-skw-req"));'
			. '  if (!input) return;'
			. '  if (required) { input.setAttribute("required", "required"); input.setAttribute("aria-required", "true"); }'
			. '  else { input.removeAttribute("required"); input.removeAttribute("aria-required"); }'
			. ' }'
			. ' Array.prototype.forEach.call(markers, function(marker) {'
			. '  var keys = (marker.getAttribute("data-skw-req-when") || "").split(" ").filter(Boolean);'
			. '  keys.forEach(function(name) {'
			. '   var box = document.querySelector(\'input[type=checkbox][name="\' + name + \'"]\');'
			. '   if (box && watched.indexOf(box) === -1) {'
			. '    watched.push(box);'
			. '    box.addEventListener("change", function() {'
			. '     Array.prototype.forEach.call(markers, apply);'
			. '    });'
			. '   }'
			. '  });'
			. ' });'
			. '})();'
		);

		// Settings tab strip (ARIA tabs pattern). Every panel stays in the DOM and inside the
		// one options.php form — the inactive ones are only hidden — so a submit from any tab
		// still carries every field. With this script absent, all panels stay visible and the
		// screen reads exactly as it did before tabs existed.
		wp_add_inline_script(
			'skwirrel-pim-sync-admin',
			'(function() {'
			. ' var strip = document.querySelector(".skw-tabs");'
			. ' if (!strip) return;'
			. ' var tabs = Array.prototype.slice.call(strip.querySelectorAll("[role=tab]"));'
			. ' if (!tabs.length) return;'
			. ' var form = document.getElementById("skwirrel-sync-settings-form");'
			. ' function panelOf(tab) { return document.getElementById(tab.getAttribute("aria-controls")); }'
			. ' function tabBySlug(slug) {'
			. '  for (var i = 0; i < tabs.length; i++) { if (tabs[i].getAttribute("data-skw-tab") === slug) return tabs[i]; }'
			. '  return null;'
			. ' }'
			. ' function activate(tab, opts) {'
			. '  opts = opts || {};'
			. '  tabs.forEach(function(t) {'
			. '   var on = t === tab;'
			. '   t.setAttribute("aria-selected", on ? "true" : "false");'
			. '   t.setAttribute("tabindex", on ? "0" : "-1");'
			. '   var p = panelOf(t);'
			. '   if (!p) return;'
			. '   if (on) { p.removeAttribute("hidden"); } else { p.setAttribute("hidden", "hidden"); }'
			. '  });'
			. '  if (opts.focus) { tab.focus(); }'
			. '  var slug = tab.getAttribute("data-skw-tab");'
			. '  if (opts.hash && slug && window.history && window.history.replaceState) {'
			. '   window.history.replaceState(null, "", window.location.pathname + window.location.search + "#tab-" + slug);'
			. '  }'
			. ' }'
			. ' tabs.forEach(function(tab, index) {'
			. '  tab.addEventListener("click", function() { activate(tab, { focus: true, hash: true }); });'
			. '  tab.addEventListener("keydown", function(e) {'
			. '   var next = -1;'
			. '   if (e.key === "ArrowRight") { next = (index + 1) % tabs.length; }'
			. '   else if (e.key === "ArrowLeft") { next = (index - 1 + tabs.length) % tabs.length; }'
			. '   else if (e.key === "Home") { next = 0; }'
			. '   else if (e.key === "End") { next = tabs.length - 1; }'
			. '   else { return; }'
			. '   e.preventDefault();'
			. '   activate(tabs[next], { focus: true, hash: true });'
			. '  });'
			. ' });'
			// Which tab opens: an errored tab first (the server marks those), then a #tab- deep
			// link, then whatever the server pre-selected, then the first tab.
			. ' var initial = null;'
			. ' for (var i = 0; i < tabs.length; i++) { if (tabs[i].hasAttribute("data-skw-errors")) { initial = tabs[i]; break; } }'
			. ' if (!initial && window.location.hash.indexOf("#tab-") === 0) { initial = tabBySlug(window.location.hash.slice(5)); }'
			. ' if (!initial) { for (var j = 0; j < tabs.length; j++) { if (tabs[j].getAttribute("aria-selected") === "true") { initial = tabs[j]; break; } } }'
			. ' activate(initial || tabs[0], { focus: false, hash: false });'
			// A required control inside a hidden panel is not focusable, so the browser refuses to
			// report it ("An invalid form control ... is not focusable") and the save silently fails.
			// Open the panel that holds the first invalid control before native validation runs.
			. ' if (form) {'
			. '  form.addEventListener("click", function(e) {'
			. '   var btn = e.target.closest ? e.target.closest("button[type=submit], input[type=submit]") : null;'
			. '   if (!btn) return;'
			. '   var invalid = form.querySelector(":invalid");'
			. '   if (!invalid) return;'
			. '   var panel = invalid.closest("[data-skw-panel]");'
			. '   if (!panel) return;'
			. '   var tab = tabBySlug(panel.getAttribute("data-skw-panel"));'
			. '   if (tab && tab.getAttribute("aria-selected") !== "true") { activate(tab, { focus: false, hash: true }); }'
			. '  }, true);'
			. ' }'
			. '})();'
		);

		// Move WP admin notices into the dashboard notices slot.
		wp_add_inline_script(
			'skwirrel-pim-sync-admin',
			'(function() {'
			. ' var slot = document.getElementById("skwirrel-notices");'
			. ' if (!slot) return;'
			. ' var container = document.getElementById("wpbody-content");'
			. ' if (!container) return;'
			. ' var notices = container.querySelectorAll(":scope > .notice, :scope > .updated, :scope > .error, :scope > .update-nag, .wrap > .notice, .wrap > .updated, .wrap > .error, .wrap > .update-nag");'
			. ' notices.forEach(function(n) { slot.appendChild(n); });'
			. '})();'
		);

		// Inline toggle: save "update slug on re-sync" via AJAX.
		wp_add_inline_script(
			'skwirrel-pim-sync-admin',
			'(function() {'
			. ' var sel = document.getElementById("skwirrel-update-slug-resync");'
			. ' if (!sel) return;'
			. ' sel.addEventListener("change", function() {'
			. '  var enabled = this.value === "1";'
			. '  var hint = document.getElementById("skwirrel-slug-resync-hint");'
			. '  var warn = document.getElementById("skwirrel-slug-warning");'
			. '  if (hint) hint.style.display = enabled ? "" : "none";'
			. '  if (warn) warn.style.display = enabled ? "" : "none";'
			. '  var fd = new FormData();'
			. '  fd.append("action", "skwirrel_wc_sync_save_slug_resync");'
			. '  fd.append("_nonce", skwirrelPimSync.slugResyncNonce);'
			. '  fd.append("enabled", enabled ? "1" : "0");'
			. '  fetch(skwirrelPimSync.ajaxUrl, { method: "POST", body: fd })'
			. '   .then(function() {'
			. '    var ok = document.getElementById("skwirrel-slug-saved");'
			. '    if (ok) { ok.style.display = "inline"; setTimeout(function() { ok.style.display = "none"; }, 1500); }'
			. '   });'
			. ' });'
			. '})();'
		);

		// Log viewer modal with chunked rendering + download.
		wp_add_inline_script(
			'skwirrel-pim-sync-admin',
			'(function() {'
			. ' var rafId = null, logFile = "", logOffset = 0, logSize = 0, lineCount = 0;'
			. ' function fmtLine(line) {'
			. '  var e = line.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");'
			. '  if (/^={3,}/.test(line)) return "<span class=\"skw-log-separator\">" + e + "</span>";'
			. '  var m = e.match(/^(\\[\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\])\\[(INFO|WARNING|ERROR|DEBUG)\\](.*)/);'
			. '  if (m) {'
			. '   var msg = m[3].replace(/(\\{[^}]+\\})/g, "<span class=\"skw-log-json\">$1</span>");'
			. '   return "<span class=\"skw-log-ts\">" + m[1] + "</span><span class=\"skw-log-" + m[2].toLowerCase() + "\">[" + m[2] + "]</span>" + msg;'
			. '  }'
			. '  return e;'
			. ' }'
			. ' function renderChunked(raw, pre, onDone) {'
			. '  var lines = raw.split("\\n"), i = 0, batch = 200;'
			. '  var progress = document.getElementById("skwirrel-log-progress");'
			. '  lineCount += lines.length;'
			. '  function step() {'
			. '   var end = Math.min(i + batch, lines.length), html = "";'
			. '   for (; i < end; i++) html += fmtLine(lines[i]) + "\\n";'
			. '   pre.insertAdjacentHTML("beforeend", html);'
			. '   if (progress) progress.textContent = lineCount + " ' . esc_js( __( 'lines', 'skwirrel-pim-sync' ) ) . '";'
			. '   if (i < lines.length) { rafId = requestAnimationFrame(step); }'
			. '   else { rafId = null; if (onDone) onDone(); }'
			. '  }'
			. '  rafId = requestAnimationFrame(step);'
			. ' }'
			. ' function fetchChunk(filename, offset, pre) {'
			. '  var fd = new FormData();'
			. '  fd.append("action", "skwirrel_wc_sync_view_log");'
			. '  fd.append("_nonce", skwirrelPimSync.viewLogNonce);'
			. '  fd.append("filename", filename);'
			. '  fd.append("offset", offset);'
			. '  return fetch(skwirrelPimSync.ajaxUrl, { method: "POST", body: fd })'
			. '   .then(function(r) { return r.json(); })'
			. '   .then(function(r) {'
			. '    if (!r.success) { pre.textContent = r.data || "' . esc_js( __( 'Could not load log', 'skwirrel-pim-sync' ) ) . '"; return; }'
			. '    logOffset = r.data.offset; logSize = r.data.size;'
			. '    var footer = document.getElementById("skwirrel-log-footer");'
			. '    if (footer) footer.style.display = r.data.has_more ? "block" : "none";'
			. '    renderChunked(r.data.content, pre, function() { pre.scrollTop = pre.scrollHeight; });'
			. '   })'
			. '   .catch(function() { pre.textContent = "' . esc_js( __( 'Network error', 'skwirrel-pim-sync' ) ) . '"; });'
			. ' }'
			// Open modal on View button click
			. ' document.addEventListener("click", function(e) {'
			. '  var btn = e.target.closest(".skw-btn-log-view");'
			. '  if (!btn) return;'
			. '  e.preventDefault();'
			. '  logFile = btn.dataset.logFile; logOffset = 0; lineCount = 0;'
			. '  var modal = document.getElementById("skwirrel-log-modal");'
			. '  var pre = document.getElementById("skwirrel-log-content");'
			. '  var title = document.getElementById("skwirrel-log-title");'
			. '  var dlBtn = document.getElementById("skwirrel-log-download");'
			. '  if (!modal || !pre) return;'
			. '  pre.innerHTML = "";'
			. '  if (title) title.textContent = logFile;'
			. '  if (dlBtn) { dlBtn.style.display = "inline-block"; dlBtn.dataset.logFile = logFile; }'
			. '  modal.style.display = "flex";'
			. '  fetchChunk(logFile, 0, pre);'
			. ' });'
			// Load more button
			. ' var moreBtn = document.getElementById("skwirrel-log-more");'
			. ' if (moreBtn) {'
			. '  moreBtn.addEventListener("click", function() {'
			. '   var pre = document.getElementById("skwirrel-log-content");'
			. '   var spinner = document.getElementById("skwirrel-log-spinner");'
			. '   if (!pre) return;'
			. '   if (spinner) spinner.classList.add("is-active");'
			. '   moreBtn.disabled = true;'
			. '   fetchChunk(logFile, logOffset, pre).then(function() {'
			. '    moreBtn.disabled = false;'
			. '    if (spinner) spinner.classList.remove("is-active");'
			. '   });'
			. '  });'
			. ' }'
			// Download button
			. ' var dlBtn = document.getElementById("skwirrel-log-download");'
			. ' if (dlBtn) {'
			. '  dlBtn.addEventListener("click", function() {'
			. '   var f = this.dataset.logFile;'
			. '   if (!f) return;'
			. '   window.location.href = skwirrelPimSync.ajaxUrl'
			. '    + "?action=skwirrel_wc_sync_download_log"'
			. '    + "&_nonce=" + encodeURIComponent(skwirrelPimSync.downloadLogNonce)'
			. '    + "&filename=" + encodeURIComponent(f);'
			. '  });'
			. ' }'
			// Close modal + cancel rendering
			. ' function closeModal() {'
			. '  var modal = document.getElementById("skwirrel-log-modal");'
			. '  if (modal) modal.style.display = "none";'
			. '  if (rafId) { cancelAnimationFrame(rafId); rafId = null; }'
			. ' }'
			. ' document.addEventListener("click", function(e) {'
			. '  if (e.target.id === "skwirrel-log-modal" || e.target.closest(".skw-modal-close")) closeModal();'
			. ' });'
			. ' document.addEventListener("keydown", function(e) { if (e.key === "Escape") closeModal(); });'
			. '})();'
		);

		// The reactive status poller (enqueued on every admin page) now refreshes the sync banner in
		// place — no full-page reload. $current_tab is still needed for the live-log block below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab parameter is display-only
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		// Live log tail — only on the debug tab.
		if ( 'debug' === $current_tab ) {
			$lines_label   = esc_js( __( 'lines', 'skwirrel-pim-sync' ) );
			$network_error = esc_js( __( 'Network error', 'skwirrel-pim-sync' ) );
			$paused_label  = esc_js( __( 'Resume', 'skwirrel-pim-sync' ) );
			$pause_label   = esc_js( __( 'Pause', 'skwirrel-pim-sync' ) );
			$running_label = esc_js( __( 'Sync running', 'skwirrel-pim-sync' ) );
			$idle_label    = esc_js( __( 'Idle', 'skwirrel-pim-sync' ) );
			$waiting_label = esc_js( __( 'Waiting for sync log…', 'skwirrel-pim-sync' ) );

			$live_js =
				'(function() {'
				. ' var pre = document.getElementById("skwirrel-live-log-content");'
				. ' if (!pre) return;'
				. ' var stateEl = document.getElementById("skwirrel-live-log-state");'
				. ' var dotEl = document.querySelector(".skw-live-log-dot");'
				. ' var fileEl = document.getElementById("skwirrel-live-log-filename");'
				. ' var progressEl = document.getElementById("skwirrel-live-log-progress");'
				. ' var pauseBtn = document.getElementById("skwirrel-live-log-pause");'
				. ' var clearBtn = document.getElementById("skwirrel-live-log-clear");'
				. ' var autoBox = document.getElementById("skwirrel-live-log-autoscroll");'
				. ' var dlBtn = document.getElementById("skwirrel-live-log-download");'
				. ' var filename = pre.dataset.filename || "";'
				. ' var offset = 0, lineCount = 0, paused = false, timer = null;'
				. ' function esc(s){return s.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}'
				. ' function fmtLine(line){'
				. '  var e = esc(line);'
				. '  if (/^={3,}/.test(line)) return "<span class=\"skw-log-separator\">" + e + "</span>";'
				. '  var m = e.match(/^(\\[\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\])\\[(INFO|WARNING|ERROR|DEBUG)\\](.*)/);'
				. '  if (m) {'
				. '   var msg = m[3].replace(/(\\{[^}]+\\})/g, "<span class=\"skw-log-json\">$1</span>");'
				. '   return "<span class=\"skw-log-ts\">" + m[1] + "</span><span class=\"skw-log-" + m[2].toLowerCase() + "\">[" + m[2] + "]</span>" + msg;'
				. '  }'
				. '  return e;'
				. ' }'
				. ' function appendChunk(raw){'
				. '  if (!raw) return;'
				. '  var lines = raw.split("\\n");'
				. '  lineCount += lines.length;'
				. '  var html = "";'
				. '  for (var i = 0; i < lines.length; i++) html += fmtLine(lines[i]) + "\\n";'
				. '  pre.insertAdjacentHTML("beforeend", html);'
				. '  if (progressEl) progressEl.textContent = lineCount + " ' . $lines_label . '";'
				. '  if (autoBox && autoBox.checked) pre.scrollTop = pre.scrollHeight;'
				. ' }'
				. ' function poll(){'
				. '  if (paused) { schedule(); return; }'
				. '  var fd = new FormData();'
				. '  fd.append("action", "skwirrel_wc_sync_tail_log");'
				. '  fd.append("_nonce", skwirrelPimSync.viewLogNonce);'
				. '  fd.append("offset", offset);'
				. '  fd.append("filename", filename);'
				. '  fetch(skwirrelPimSync.ajaxUrl, { method: "POST", body: fd })'
				. '   .then(function(r){ return r.json(); })'
				. '   .then(function(r){'
				. '    if (!r || !r.success) return;'
				. '    var d = r.data;'
				. '    if (d.filename && d.filename !== filename) {'
				. '     filename = d.filename; offset = 0; lineCount = 0; pre.innerHTML = "";'
				. '     if (fileEl) fileEl.textContent = filename;'
				. '     pre.dataset.filename = filename;'
				. '     if (dlBtn) dlBtn.disabled = false;'
				. '    }'
				. '    if (d.content) { offset = d.offset; appendChunk(d.content); }'
				. '    else if (d.size !== undefined) { offset = d.size; }'
				. '    if (!filename && fileEl) fileEl.textContent = "— ' . esc_js( __( 'no log yet', 'skwirrel-pim-sync' ) ) . '";'
				. '    if (stateEl) stateEl.textContent = d.is_running ? "' . $running_label . '" : "' . $idle_label . '";'
				. '    if (dotEl) { dotEl.classList.toggle("skw-live-log-dot-running", !!d.is_running); dotEl.classList.toggle("skw-live-log-dot-idle", !d.is_running); }'
				. '   })'
				. '   .catch(function(){ /* transient network error — keep trying */ })'
				. '   .finally(function(){ schedule(); });'
				. ' }'
				. ' function schedule(){ timer = setTimeout(poll, 2000); }'
				. ' if (pauseBtn) pauseBtn.addEventListener("click", function(){'
				. '  paused = !paused;'
				. '  pauseBtn.textContent = paused ? "' . $paused_label . '" : "' . $pause_label . '";'
				. ' });'
				. ' if (clearBtn) clearBtn.addEventListener("click", function(){ pre.innerHTML = ""; lineCount = 0; if (progressEl) progressEl.textContent = ""; });'
				. ' if (dlBtn) dlBtn.addEventListener("click", function(){'
				. '  if (!filename) return;'
				. '  window.location.href = skwirrelPimSync.ajaxUrl'
				. '   + "?action=skwirrel_wc_sync_download_log"'
				. '   + "&_nonce=" + encodeURIComponent(skwirrelPimSync.downloadLogNonce)'
				. '   + "&filename=" + encodeURIComponent(filename);'
				. ' });'
				. ' if (!filename && fileEl) fileEl.textContent = "' . $waiting_label . '";'
				. ' poll();'
				. '})();';

			wp_add_inline_script( 'skwirrel-pim-sync-admin', $live_js );
		}
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'skwirrel-pim-sync' ) );
		}

		$this->maybe_show_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab parameter is display-only
		$active_view   = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$allowed_views = [ 'dashboard', 'sync', 'history', 'settings', 'debug' ];
		if ( ! in_array( $active_view, $allowed_views, true ) ) {
			$active_view = 'dashboard';
		}
		// Legacy: map 'sync' tab to dashboard.
		if ( 'sync' === $active_view ) {
			$active_view = 'dashboard';
		}

		$dashboard = new Skwirrel_WC_Sync_Admin_Dashboard();
		$dashboard->render( $active_view );
	}

	/**
	 * Settings errors recorded for this plugin's option on the current request.
	 *
	 * Read through `get_settings_errors()`, which moves the messages out of the
	 * `settings_errors` transient into the `$wp_settings_errors` global on its first call and
	 * serves every later call from that global — so every reader on this request sees the same
	 * list, whichever one runs first.
	 *
	 * @return array<int, array<string, mixed>> Settings errors.
	 */
	public static function settings_errors_for_option(): array {
		if ( ! function_exists( 'get_settings_errors' ) ) {
			return [];
		}

		return get_settings_errors( self::OPTION_KEY );
	}

	/**
	 * Whether the current request carries at least one error-severity settings message.
	 */
	public static function has_settings_error(): bool {
		foreach ( self::settings_errors_for_option() as $error ) {
			if ( 'error' === ( $error['type'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Field IDs whose validation failed on the current request, in the order reported.
	 *
	 * @return array<int, string>
	 */
	public static function failing_field_ids(): array {
		$map    = self::error_field_map();
		$fields = [];

		foreach ( self::settings_errors_for_option() as $error ) {
			$code  = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : '';
			$field = $map[ $code ] ?? '';
			if ( '' !== $field && ! in_array( $field, $fields, true ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	private function maybe_show_notices(): void {
		// "Settings saved" after WordPress redirects back from options.php — suppressed when the
		// save produced a validation error, because a green confirmation over a value the
		// sanitiser rejected is worse than no feedback at all. The messages themselves are shown
		// as a summary above the tab strip and inline at their field by the settings screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter set by WP core
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] && ! self::has_settings_error() ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'skwirrel-pim-sync' ) . '</p></div>';
		}

		// Connection test result — read once from a transient so a subsequent
		// settings save does not re-show this notice via a stale URL parameter.
		$test_result = get_transient( self::TEST_RESULT_TRANSIENT );
		if ( false !== $test_result ) {
			delete_transient( self::TEST_RESULT_TRANSIENT );
			if ( is_array( $test_result ) && ! empty( $test_result['success'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Connection test successful.', 'skwirrel-pim-sync' ) . '</p></div>';
			} else {
				$msg = is_array( $test_result ) && ! empty( $test_result['message'] )
					? (string) $test_result['message']
					: __( 'Connection failed.', 'skwirrel-pim-sync' );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
		if ( isset( $_GET['sync'] ) && 'queued' === $_GET['sync'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sync started in the background. Results will appear here once the sync is completed. Refresh the page to check the status.', 'skwirrel-pim-sync' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
		if ( isset( $_GET['history'] ) && 'cleared' === $_GET['history'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sync history deleted.', 'skwirrel-pim-sync' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
		if ( isset( $_GET['reset'] ) && 'done' === $_GET['reset'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Skwirrel sync settings reset. All configuration options were deleted, scheduled jobs cancelled, and caches flushed. Products, media, categories and sync history are untouched. Re-enter your subdomain and API token below to continue.', 'skwirrel-pim-sync' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
		if ( isset( $_GET['purge'] ) && 'queued' === $_GET['purge'] ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Purge started in the background. All Skwirrel products, imported media, categories and attributes will be deleted. Refresh the page to check the status.', 'skwirrel-pim-sync' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
		if ( isset( $_GET['sync'] ) && 'done' === $_GET['sync'] ) {
			$last = Skwirrel_WC_Sync_History::get_last_result();
			if ( $last && $last['success'] ) {
				$with_a    = (int) ( $last['with_attributes'] ?? 0 );
				$without_a = (int) ( $last['without_attributes'] ?? 0 );
				$msg       = sprintf(
					/* translators: %1$d = created count, %2$d = updated count, %3$d = failed count */
					esc_html__( 'Sync completed. Created: %1$d, Updated: %2$d, Failed: %3$d', 'skwirrel-pim-sync' ),
					(int) $last['created'],
					(int) $last['updated'],
					(int) $last['failed']
				);
				if ( $with_a + $without_a > 0 ) {
					$msg .= ' ' . sprintf(
						/* translators: %1$d = count with attributes, %2$d = count without attributes */
						esc_html__( '(with attributes: %1$d, without: %2$d)', 'skwirrel-pim-sync' ),
						$with_a,
						$without_a
					);
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sync completed. Check the logs for details.', 'skwirrel-pim-sync' ) . '</p></div>';
			}
		}
	}
}
