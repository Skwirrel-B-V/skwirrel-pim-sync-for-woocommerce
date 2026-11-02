<?php
/**
 * Skwirrel Uninstaller.
 *
 * Removes what the plugin stored when it is deleted from Plugins (uninstall.php). Deactivating
 * the plugin keeps everything.
 *
 * Removed: every option and transient with the `skwirrel_wc_sync_` prefix (settings, auth token,
 * the Connectors API key `connectors_skwirrel_pim_api_key`,
 * history, permalink slugs, attribute groups and sources, rename job, run state), the sync queue
 * table, scheduled actions, and the file name metas `_skwirrel_file_name` and
 * `_skwirrel_previous_file_name`.
 *
 * Kept: products, imported attachments and their Skwirrel keys (deleting the plugin must not
 * empty the shop), and `_skwirrel_old_upload_path`, so old links of renamed files can be
 * redirected again when the plugin is installed later.
 *
 * This file is loaded by uninstall.php without the rest of the plugin, so it uses WordPress only.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Uninstaller {

	/** Post meta removed on uninstall. `_skwirrel_old_upload_path` is deliberately not in here. */
	public const META_KEYS = [ '_skwirrel_file_name', '_skwirrel_previous_file_name' ];

	/** Options without the `skwirrel_wc_sync_` prefix: the Connectors API key (Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION). */
	private const EXTRA_OPTIONS = [ 'connectors_skwirrel_pim_api_key' ];

	/** Scheduled hooks of the plugin (Action Scheduler and WP-Cron). */
	private const HOOKS = [ 'skwirrel_wc_sync_run', 'skwirrel_wc_sync_step', 'skwirrel_wc_sync_rename_batch' ];

	/** Action Scheduler group of the plugin's actions. */
	private const AS_GROUP = 'skwirrel-pim-sync';

	/**
	 * Clean up every site: each site of a multisite network, or the single site.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::cleanup_site();
			return;
		}
		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::cleanup_site();
			restore_current_blog();
		}
	}

	/**
	 * Clean up the current site.
	 */
	public static function cleanup_site(): void {
		global $wpdb;

		self::unschedule();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time cleanup on uninstall.
		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'skwirrel_wc_sync_' ) . '%',
				$wpdb->esc_like( '_transient_skwirrel_wc_sync_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_skwirrel_wc_sync_' ) . '%',
				$wpdb->esc_like( '_site_transient_skwirrel_wc_sync_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_skwirrel_wc_sync_' ) . '%'
			)
		);
		foreach ( is_array( $options ) ? $options : [] as $option ) {
			delete_option( (string) $option );
		}

		foreach ( self::EXTRA_OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::META_KEYS as $key ) {
			delete_post_meta_by_key( $key );
		}

		$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'skwirrel_sync_queue' ) );
		// phpcs:enable

		wp_cache_flush();
	}

	/**
	 * Remove pending scheduled actions of the plugin.
	 */
	private static function unschedule(): void {
		foreach ( self::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], self::AS_GROUP );
		}
	}
}
