<?php
/**
 * Skwirrel File Redirects.
 *
 * After files are renamed (see Skwirrel_WC_Sync_File_Rename_Job), a visit to the old file URL
 * is sent permanently (301) to the new one. The old paths live as post meta on the renamed
 * attachment, so there is no separate redirect table.
 *
 * Only requests WordPress is asked to answer are seen here: a web server that answers a
 * missing file in the uploads folder with its own 404 never reaches WordPress and needs the
 * rule shown on Settings → Files.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_File_Redirects {

	public const SETTING = 'file_redirect_old_links';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
	}

	/**
	 * Whether old links are redirected. On unless switched off, also on sites saved before this setting existed.
	 */
	public static function is_enabled(): bool {
		$settings = get_option( 'skwirrel_wc_sync_settings', [] );
		return ! is_array( $settings ) || ! array_key_exists( self::SETTING, $settings ) || ! empty( $settings[ self::SETTING ] );
	}

	/**
	 * Redirect a 404 under the uploads folder when the path belongs to a renamed file.
	 */
	public function maybe_redirect(): void {
		if ( ! is_404() || ! self::is_enabled() ) {
			return;
		}
		$uploads = wp_upload_dir();
		$base    = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';

		$relative = self::relative_upload_path( is_string( $request ) ? $request : '', is_string( $base ) ? $base : '' );
		if ( '' === $relative ) {
			return;
		}
		$target = self::find_target( $relative );
		if ( '' === $target ) {
			return;
		}

		$url = trailingslashit( $uploads['baseurl'] ) . self::encode_path( $target );
		// The URL is built from the site's own uploads URL, which may legitimately sit on another host (CDN),
		// so wp_safe_redirect() would refuse it.
		wp_redirect( $url, 301, 'Skwirrel PIM sync' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * The path of a request relative to the uploads folder, or '' when it is not inside it
	 * (or tries to leave it).
	 */
	public static function relative_upload_path( string $request_path, string $uploads_base_path ): string {
		$uploads_base_path = rtrim( $uploads_base_path, '/' ) . '/';
		if ( '/' === $uploads_base_path || ! str_starts_with( $request_path, $uploads_base_path ) ) {
			return '';
		}
		$relative = rawurldecode( substr( $request_path, strlen( $uploads_base_path ) ) );
		if ( '' === $relative || str_contains( $relative, '..' ) || str_contains( $relative, "\0" ) || str_contains( $relative, "\t" ) ) {
			return '';
		}
		return $relative;
	}

	/**
	 * The current relative path of a renamed file, or '' when this path was never renamed.
	 */
	public static function find_target( string $relative ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed prefix lookup on a 404; the result is the redirect target.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 20",
				Skwirrel_WC_Sync_File_Rename_Job::META_OLD_PATH,
				$wpdb->esc_like( $relative . "\t" ) . '%'
			)
		);
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$target = self::target_from_row( (string) $row, $relative );
			if ( '' !== $target ) {
				return $target;
			}
		}
		return '';
	}

	/**
	 * Read the new path out of a stored "old<TAB>new" row, only when its old part is exactly `$relative`.
	 */
	public static function target_from_row( string $row, string $relative ): string {
		if ( ! str_contains( $row, "\t" ) ) {
			return '';
		}
		[ $old, $new ] = explode( "\t", $row, 2 );
		return ( $old === $relative && '' !== $new && $new !== $old ) ? $new : '';
	}

	/**
	 * Percent-encode each path segment, keeping the slashes.
	 */
	private static function encode_path( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}
}
