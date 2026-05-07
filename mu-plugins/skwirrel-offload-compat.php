<?php
/**
 * Plugin Name: Skwirrel Sync — Offload Media compatibility
 * Description: Keeps the Skwirrel PIM sync from disconnecting attachments whose local file was removed by a media-offload plugin (WP Offload Media, S3 Uploads, etc.). Hooks `skwirrel_wc_sync_attachment_is_valid` and considers an attachment valid whenever `wp_get_attachment_url()` resolves — i.e. the offload plugin's URL filter chain is producing a usable URL even though the local file is gone.
 * Version: 1.0.0
 * Author: Skwirrel B.V.
 * Author URI: https://skwirrel.eu
 * Requires PHP: 8.3
 *
 * Drop this file in wp-content/mu-plugins/ — it runs automatically and cannot
 * be deactivated through the admin UI. Requires the Skwirrel PIM sync plugin
 * (>= 3.8.0) for the `skwirrel_wc_sync_attachment_is_valid` filter.
 *
 * Why this is a separate mu-plugin instead of being baked into the main
 * plugin: the main plugin defaults to a strict local file_exists() check
 * because that catches genuinely broken records (admin cleanup, half-failed
 * sync, etc.) on the 95%+ of installs that don't run an offload plugin.
 * Sites that DO run one opt in by activating this shim — no setting, no UI,
 * no auto-detect heuristics that go stale every time an offload plugin ships
 * a new version.
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'skwirrel_wc_sync_attachment_is_valid',
	/**
	 * Veto the broken-record cleanup when an offload plugin is producing a
	 * usable URL for the attachment, even though the local file is missing.
	 *
	 * @param bool        $local_present True when the local file exists on disk.
	 * @param int         $attachment_id WP attachment post ID.
	 * @param string|null $local_path    Path returned by get_attached_file(), or null.
	 */
	static function ( bool $local_present, int $attachment_id, ?string $local_path ): bool {
		if ( $local_present ) {
			return true;
		}
		// $local_path is unused — kept in the signature so future filters in
		// this chain (or theme code) can still rely on the documented hook
		// shape and inspect it themselves.
		unset( $local_path );

		// `wp_get_attachment_url()` runs the full URL filter chain (offload
		// plugins hook into it). A non-empty result that does NOT match the
		// local uploads URL implies the offload plugin is serving the file
		// from remote storage and we should keep the WP attachment record.
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		// If the resolved URL still points at the local uploads dir AND the
		// local file is missing, we're dealing with a genuinely broken record
		// (no offload plugin took over). Let the main plugin disconnect it.
		$uploads = wp_get_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		if ( '' !== $baseurl && str_starts_with( $url, $baseurl ) ) {
			return false;
		}

		return true;
	},
	10,
	3
);
