<?php
/**
 * Skwirrel File Naming.
 *
 * Decides the file name a downloaded image or document gets in the WordPress uploads
 * folder. One place for all four download paths (new image, new file, image content
 * replace, file content replace), so they can never name files differently.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_File_Naming {

	/** Mode A: `skwirrel-<hash>-<timestamp>.<ext>`. The default; nothing changes for existing sites. */
	public const MODE_CURRENT = 'current';

	/** Mode B: a custom prefix followed by the Skwirrel `file_name`. */
	public const MODE_PREFIX = 'prefix';

	/** Mode C: the Skwirrel `file_name` as is. */
	public const MODE_ORIGINAL = 'original';

	public const SETTING_MODE   = 'file_naming_mode';
	public const SETTING_PREFIX = 'file_naming_prefix';

	/** Longest prefix accepted, so prefix + file name stays well inside a file system's name limit. */
	private const MAX_PREFIX_LENGTH = 40;

	/**
	 * Valid modes.
	 *
	 * @return string[]
	 */
	public static function modes(): array {
		return [ self::MODE_CURRENT, self::MODE_PREFIX, self::MODE_ORIGINAL ];
	}

	/**
	 * Normalise a raw mode value. Anything unknown (or a missing key on an existing site) is mode A.
	 */
	public static function sanitize_mode( mixed $value ): string {
		return is_string( $value ) && in_array( $value, self::modes(), true ) ? $value : self::MODE_CURRENT;
	}

	/**
	 * Normalise a raw prefix value: a safe file name fragment, no path separators, case kept.
	 */
	public static function sanitize_prefix( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( str_replace( [ '/', '\\' ], '', $value ) );
		if ( '' === $value ) {
			return '';
		}
		// Sanitise as part of a file name, then strip the stand-in stem again. This keeps a
		// trailing "-" or "_" (the usual separator) that sanitize_file_name() would otherwise trim.
		$safe = sanitize_file_name( $value . 'x.jpg' );
		$safe = (string) preg_replace( '/x\.jpg$/', '', $safe );
		return substr( $safe, 0, self::MAX_PREFIX_LENGTH );
	}

	/**
	 * Configured mode.
	 */
	public static function get_mode(): string {
		$settings = get_option( 'skwirrel_wc_sync_settings', [] );
		return self::sanitize_mode( is_array( $settings ) ? ( $settings[ self::SETTING_MODE ] ?? null ) : null );
	}

	/**
	 * Configured prefix (mode B).
	 */
	public static function get_prefix(): string {
		$settings = get_option( 'skwirrel_wc_sync_settings', [] );
		return self::sanitize_prefix( is_array( $settings ) ? ( $settings[ self::SETTING_PREFIX ] ?? '' ) : '' );
	}

	/**
	 * Build the final file name for a download.
	 *
	 * @param string $dir         Uploads directory the file will be written to (for the uniqueness check).
	 * @param string $file_name   The Skwirrel `file_name`; may be empty.
	 * @param string $ext         Extension the downloaded bytes were determined to have (without dot).
	 * @param string $url         Source URL (hash for mode A, and passed to the filter).
	 * @param string $context     One of `image`, `file`, `image_replace`, `file_replace`.
	 * @param bool   $force_ext   True when `$ext` comes from the file content (images): a different
	 *                            extension on the Skwirrel name is then replaced rather than trusted.
	 * @return string File name, without directory.
	 */
	public static function build( string $dir, string $file_name, string $ext, string $url, string $context, bool $force_ext = false ): string {
		$name = '';

		if ( self::MODE_CURRENT !== self::get_mode() ) {
			$name = self::derive( $dir, $file_name, $ext, $force_ext );
			if ( '' === $name ) {
				( new Skwirrel_WC_Sync_Logger() )->warning(
					'No usable Skwirrel file name; using the default file name',
					[
						'url'       => $url,
						'file_name' => $file_name,
						'context'   => $context,
					]
				);
			}
		}

		if ( '' === $name ) {
			$name = 'skwirrel-' . substr( hash( 'sha256', $url ), 0, 12 ) . '-' . time() . '.' . $ext;
		}

		return self::finalize( $name, $dir, $url, $context );
	}

	/**
	 * The file name the configured mode gives a Skwirrel `file_name`, or '' when the plugin
	 * naming applies (mode A, or no usable name). Logs nothing and writes nothing.
	 *
	 * @param string $dir       Directory the file lives in or will be written to.
	 * @param string $file_name The Skwirrel `file_name`; may be empty.
	 * @param string $ext       Extension of the file content (without dot).
	 * @param bool   $force_ext True when `$ext` is certain: a different extension on the Skwirrel name is replaced.
	 * @param bool   $unique    Make the name unique in `$dir`. False compares the bare name.
	 */
	public static function derive( string $dir, string $file_name, string $ext, bool $force_ext, bool $unique = true ): string {
		$mode = self::get_mode();
		if ( self::MODE_CURRENT === $mode ) {
			return '';
		}
		$name = self::name_from_skwirrel( $file_name, $ext, $force_ext, self::MODE_PREFIX === $mode ? self::get_prefix() : '' );
		return ( '' !== $name && $unique ) ? wp_unique_filename( $dir, $name ) : $name;
	}

	/**
	 * Run a name through the `skwirrel_wc_sync_upload_filename` filter. A name the filter
	 * changes is made safe and unique again, so a filter can never cause an overwrite.
	 *
	 * @param string $name    File name without directory.
	 * @param string $dir     Directory the file lives in or will be written to.
	 * @param string $url     Source URL the file was downloaded from.
	 * @param string $context `image`, `file`, `image_replace`, `file_replace` or `rename`.
	 */
	public static function finalize( string $name, string $dir, string $url, string $context ): string {
		$filtered = self::filter_name( $name, $url, $context );
		if ( is_string( $filtered ) && '' !== $filtered && $filtered !== $name ) {
			$safe = sanitize_file_name( basename( $filtered ) );
			if ( '' !== $safe ) {
				return wp_unique_filename( $dir, $safe );
			}
		}
		return $name;
	}

	/**
	 * Apply the file name filter without assuming every third-party callback honours its contract.
	 *
	 * @param string $name    File name without directory.
	 * @param string $url     Source URL the file was downloaded from.
	 * @param string $context `image`, `file`, `image_replace`, `file_replace` or `rename`.
	 * @return mixed Filtered value before validation.
	 */
	private static function filter_name( string $name, string $url, string $context ): mixed {
		/**
		 * Filters the file name a downloaded Skwirrel file is saved under.
		 *
		 * @param string $name    File name without directory.
		 * @param string $url     Source URL the file was downloaded from.
		 * @param string $context `image`, `file`, `image_replace` or `file_replace`.
		 */
		return apply_filters( 'skwirrel_wc_sync_upload_filename', $name, $url, $context );
	}

	/**
	 * Safe file name derived from the Skwirrel `file_name`, or '' when it is not usable.
	 */
	private static function name_from_skwirrel( string $file_name, string $ext, bool $force_ext, string $prefix ): string {
		$file_name = trim( basename( str_replace( '\\', '/', $file_name ) ) );
		if ( '' === $file_name ) {
			return '';
		}

		$given_ext = pathinfo( $file_name, PATHINFO_EXTENSION );
		if ( ! preg_match( '/^[a-z0-9]{2,5}$/i', $given_ext ) ) {
			return '';
		}
		$stem = pathinfo( $file_name, PATHINFO_FILENAME );

		// Image bytes decide the extension. jpg and jpeg are the same thing, so keep the
		// customer's spelling; anything else (a .png name on JPEG bytes) is corrected.
		if ( $force_ext && ! self::same_extension( $given_ext, $ext ) ) {
			$given_ext = $ext;
		}

		$name = sanitize_file_name( $prefix . $stem . '.' . $given_ext );
		// Nothing but the extension left (a name of only unusable characters).
		if ( '' === $name || str_starts_with( $name, '.' ) || '' === pathinfo( $name, PATHINFO_FILENAME ) ) {
			return '';
		}
		return $name;
	}

	private static function same_extension( string $a, string $b ): bool {
		$a = strtolower( $a );
		$b = strtolower( $b );
		return $a === $b || ( in_array( $a, [ 'jpg', 'jpeg' ], true ) && in_array( $b, [ 'jpg', 'jpeg' ], true ) );
	}
}
