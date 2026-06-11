<?php
/**
 * WordPress 7.0+ Connectors API integration.
 *
 * Registers the Skwirrel PIM API token with the central Connections Screen
 * (Settings → Connectors) so users can manage credentials there instead of
 * the plugin's own settings page. Inert on WP < 7.0 — guarded by
 * function_exists( 'wp_get_connector' ).
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connectors API integration.
 */
final class Skwirrel_WC_Sync_Connectors {

	/** @var string Connector ID — must match [a-z0-9_-]+. */
	public const CONNECTOR_ID = 'skwirrel_pim';

	/** @var string Option key used by the Connectors API to store the API key. */
	public const CREDENTIAL_OPTION = 'connectors_skwirrel_pim_api_key';

	/** @var string Option key tracking which plugin version has applied DB-level migrations. */
	public const DB_VERSION_OPTION = 'skwirrel_wc_sync_db_version';

	/** @var string Plugin version that introduced the Connectors migration. */
	public const MIGRATION_VERSION = '3.10.0';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! self::is_available() ) {
			return;
		}
		add_action( 'wp_connectors_init', [ $this, 'register_connector' ] );
		add_action( 'admin_init', [ $this, 'maybe_migrate_token' ] );
	}

	/**
	 * Whether the WP 7.0+ Connectors API is loaded on this site.
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_get_connector' );
	}

	/**
	 * Whether the Skwirrel connector has been registered with the WP registry.
	 */
	public static function is_registered(): bool {
		return self::is_available()
			&& function_exists( 'wp_is_connector_registered' )
			&& wp_is_connector_registered( self::CONNECTOR_ID );
	}

	/**
	 * Resolve the API token via the Connectors API.
	 *
	 * Returns '' when the API is unavailable, the connector is not registered,
	 * or no credential has been stored. Callers should fall back to the legacy
	 * `skwirrel_wc_sync_auth_token` option in that case.
	 */
	public static function get_token(): string {
		if ( ! self::is_available() ) {
			return '';
		}
		return (string) get_option( self::CREDENTIAL_OPTION, '' );
	}

	/**
	 * Register the Skwirrel PIM connector with the WP registry.
	 *
	 * Called on the `wp_connectors_init` hook (WP 7.0+ only). The mixed-type
	 * registry signature is intentional — the registry class shape may evolve
	 * during the 7.0.x patch series.
	 *
	 * @param mixed $registry WP_Connector_Registry-like object.
	 */
	public function register_connector( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}

		$args = [
			'name'           => __( 'Skwirrel PIM', 'skwirrel-pim-sync' ),
			'description'    => __( 'API token for syncing products from the Skwirrel PIM system to WooCommerce.', 'skwirrel-pim-sync' ),
			// Branded logo on the Connections Screen — WP 7.0 reads `logo_url`
			// (class-wp-connector-registry.php). Uses the bundled Skwirrel mark;
			// the WP.org icon-*.jpg assets are SVN-only and would not resolve on
			// the installed site. Empty string falls back to the default icon.
			'logo_url'       => defined( 'SKWIRREL_WC_SYNC_PLUGIN_URL' ) ? SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/s.png' : '',
			// WP 7.0.0 requires a non-empty `type`. The Connectors admin screen
			// only renders `ai_provider` connectors today; non-AI types register
			// cleanly but have no native UI yet, so we keep the plugin's own
			// token field as the actual UI (see Admin_Settings). 'service' is the
			// honest descriptor and is forward-compatible once core lifts the
			// ai_provider screen restriction.
			'type'           => 'service',
			'authentication' => [
				'method'          => 'api_key',
				'setting_name'    => self::CREDENTIAL_OPTION,
				'credentials_url' => 'https://skwirrel.eu/',
			],
			'plugin'         => [
				'file' => defined( 'SKWIRREL_WC_SYNC_PLUGIN_FILE' ) ? SKWIRREL_WC_SYNC_PLUGIN_FILE : '',
			],
		];

		$registry->register( self::CONNECTOR_ID, $args );
	}

	/**
	 * One-shot migration: copy the legacy auth token into the Connectors store.
	 *
	 * Runs once per install on the first admin pageload after upgrade to 3.10.0.
	 * Idempotent — gated by the `skwirrel_wc_sync_db_version` option.
	 * Does NOT delete the legacy option; that fallback is kept for one minor cycle.
	 */
	public function maybe_migrate_token(): void {
		$db_version = (string) get_option( self::DB_VERSION_OPTION, '' );
		if ( version_compare( $db_version, self::MIGRATION_VERSION, '>=' ) ) {
			return;
		}

		$legacy = (string) get_option( 'skwirrel_wc_sync_auth_token', '' );
		$stored = self::get_token();

		if ( '' !== $legacy && '' === $stored ) {
			update_option( self::CREDENTIAL_OPTION, $legacy, false );
			if ( class_exists( 'Skwirrel_WC_Sync_Logger' ) ) {
				( new Skwirrel_WC_Sync_Logger() )->info(
					'Auth token migrated to WordPress Connections Screen.',
					[ 'connector_id' => self::CONNECTOR_ID ]
				);
			}
		}

		update_option( self::DB_VERSION_OPTION, self::MIGRATION_VERSION, false );
	}
}
