<?php
/**
 * Skwirrel Sync - Admin Settings UI.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Admin_Settings {

	private const PAGE_SLUG        = 'skwirrel-pim-sync';
	private const OPTION_KEY       = 'skwirrel_wc_sync_settings';
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

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ], 99 );
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
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Skwirrel Sync', 'skwirrel-pim-sync' ),
			__( 'Skwirrel Sync', 'skwirrel-pim-sync' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
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

	public function sanitize_settings( array $input ): array {
		$out                 = [];
		$out['endpoint_url'] = isset( $input['endpoint_url'] ) ? esc_url_raw( self::normalize_endpoint_url( (string) $input['endpoint_url'] ) ) : '';
		$out['auth_type']    = in_array( $input['auth_type'] ?? '', [ 'bearer', 'token' ], true ) ? $input['auth_type'] : 'bearer';
		$token               = $this->sanitize_token( $input['auth_token'] ?? '' );
		if ( ! empty( $token ) ) {
			update_option( self::TOKEN_OPTION_KEY, $token, false );
		}
		$out['auth_token']        = ! empty( $token ) ? self::MASK : '';
		$out['timeout']           = isset( $input['timeout'] ) ? max( 5, min( 120, (int) $input['timeout'] ) ) : 30;
		$out['retries']           = isset( $input['retries'] ) ? max( 0, min( 5, (int) $input['retries'] ) ) : 2;
		$out['sync_interval']     = $input['sync_interval'] ?? '';
		$out['batch_size']        = isset( $input['batch_size'] ) ? max( 1, min( 100, (int) $input['batch_size'] ) ) : 10;
		$out['sync_categories']   = ! empty( $input['sync_categories'] );
		$out['super_category_id'] = isset( $input['super_category_id'] ) ? sanitize_text_field( trim( $input['super_category_id'] ) ) : '';
		if ( $out['sync_categories'] && ( '' === $out['super_category_id'] || 0 >= (int) $out['super_category_id'] ) ) {
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
		if ( empty( $collection_valid ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'collection_ids_required',
				__( 'At least one selection ID greater than 0 is required.', 'skwirrel-pim-sync' ),
				'error'
			);
		}
		$out['custom_collection_id'] = isset( $input['custom_collection_id'] ) ? sanitize_text_field( trim( $input['custom_collection_id'] ) ) : '';
		if ( '' === $out['custom_collection_id'] || 0 >= (int) $out['custom_collection_id'] ) {
			add_settings_error(
				self::OPTION_KEY,
				'custom_collection_id_required',
				__( 'A custom class collection ID greater than 0 is required.', 'skwirrel-pim-sync' ),
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
		$out['prices_managed_outside_skwirrel'] = ! empty( $input['prices_managed_outside_skwirrel'] );
		$out['log_mode_manual']                 = in_array( $input['log_mode_manual'] ?? '', [ 'per_sync', 'per_day' ], true )
			? $input['log_mode_manual']
			: 'per_sync';
		$out['log_mode_scheduled']              = in_array( $input['log_mode_scheduled'] ?? '', [ 'per_sync', 'per_day' ], true )
			? $input['log_mode_scheduled']
			: 'per_day';
		$out['log_retention']                   = in_array( $input['log_retention'] ?? '', [ '12hours', '1day', '2days', '7days', '30days', 'manual' ], true )
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
				'purgeConfirmPermanent' => __( 'WARNING: All Skwirrel products will be PERMANENTLY deleted. This cannot be undone!\n\nAre you sure?', 'skwirrel-pim-sync' ),
				'purgeConfirmTrash'     => __( 'All Skwirrel products will be moved to the trash.\n\nAre you sure?', 'skwirrel-pim-sync' ),
				'clearHistoryConfirm'   => __( 'Delete all sync history?', 'skwirrel-pim-sync' ),
				'resetSettingsConfirm'  => __( 'Reset all Skwirrel sync settings? Endpoint URL, API token, sync schedule and slug rules will be deleted, and all scheduled syncs will be cancelled. Products, media, categories and sync history are kept.\n\nAre you sure?', 'skwirrel-pim-sync' ),
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'slugResyncNonce'       => wp_create_nonce( 'skwirrel_slug_resync_nonce' ),
				'viewLogNonce'          => wp_create_nonce( 'skwirrel_view_log_nonce' ),
				'downloadLogNonce'      => wp_create_nonce( 'skwirrel_download_log_nonce' ),
				'abortSyncNonce'        => wp_create_nonce( 'skwirrel_abort_sync_nonce' ),
				'abortSyncConfirm'      => __( 'Stop the running sync?', 'skwirrel-pim-sync' ),
				'testConnectionNonce'   => wp_create_nonce( 'skwirrel_test_connection_nonce' ),
				'testingLabel'          => __( 'Testing…', 'skwirrel-pim-sync' ),
				'testSubdomainLabel'    => __( 'Enter a subdomain first.', 'skwirrel-pim-sync' ),
				'testFailedLabel'       => __( 'Connection failed.', 'skwirrel-pim-sync' ),
				'testNetworkLabel'      => __( 'Network error.', 'skwirrel-pim-sync' ),
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
			// The Stop-sync button is wired by the global status poller (event delegation), so it keeps
			// working after the banner re-renders and on every admin page.
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

	private function maybe_show_notices(): void {
		// "Settings saved" after WordPress redirects back from options.php.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter set by WP core
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
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
