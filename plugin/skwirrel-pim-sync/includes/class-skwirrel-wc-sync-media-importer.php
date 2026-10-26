<?php
/**
 * Skwirrel Media Importer.
 *
 * Downloads images and files from Skwirrel URLs and imports into WP media library.
 * Handles duplicate detection via file URL hash.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Media_Importer {

	private Skwirrel_WC_Sync_Logger $logger;
	private const META_SKWIRREL_URL           = '_skwirrel_source_url';
	private const META_SKWIRREL_HASH          = '_skwirrel_url_hash';
	private const META_SKWIRREL_ATTACHMENT_ID = '_skwirrel_attachment_id';
	private const META_SKWIRREL_FILE_CHECKSUM = '_skwirrel_file_checksum';
	private const META_SKWIRREL_FILE_NAME     = '_skwirrel_file_name';

	/** Set while the attachment carries a Title/Caption that came from Skwirrel, so clearing it there clears it here. */
	private const META_SKWIRREL_TEXTS_SYNCED = '_skwirrel_texts_synced';

	/** Image attachment type codes (from Skwirrel schema: PPI=Picture, PHI=Picture print, LOG=Logo, SCH=Diagram, PRT=Presentation, OTV=Other visual). */
	private const IMAGE_TYPES = [ 'IMG', 'PPI', 'PHI', 'LOG', 'SCH', 'PRT', 'OTV' ];

	/** File extensions that are never images (even if the type code says otherwise). */
	private const NON_IMAGE_EXTENSIONS = [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'zip', 'rar' ];

	/**
	 * Extensions a web server may run as a script. Never accepted anywhere in a file name, also not
	 * in front of an allowed extension (`x.php.pdf` runs as PHP on some Apache set-ups).
	 */
	private const EXECUTABLE_EXTENSION_PATTERN = '/^(php\d*|phtml?|pht|phps|phar|cgi|aspx?|jsp|shtml?)$/i';

	/**
	 * Technical document types accepted for Skwirrel imports on top of the site's WordPress
	 * allowlist (CAD/BIM files that wholesale catalogues carry and core does not list). Only used
	 * for this plugin's imports, never added to the site-wide upload allowlist. Filterable through
	 * `skwirrel_wc_sync_allowed_document_types`.
	 */
	private const EXTRA_DOCUMENT_TYPES = [
		'dwg'      => 'application/acad',
		'dxf'      => 'application/dxf',
		'ifc'      => 'application/x-step',
		'step|stp' => 'application/step',
		'igs|iges' => 'model/iges',
		'rfa'      => 'application/octet-stream',
		'rvt'      => 'application/octet-stream',
		'stl'      => 'model/stl',
		'skp'      => 'application/vnd.sketchup.skp',
		'eps'      => 'application/postscript',
	];

	/**
	 * Extensions the plugin filter may never add: these run script in the browser (svg, html, xml,
	 * xsl, mht, js, swf, hta), so only the site itself may allow them through `upload_mimes`.
	 */
	private const NEVER_EXTRA_EXTENSION_PATTERN = '/^(svgz?|html?|xhtml?|xht|xml|xslt?|mht|mhtml|m?js|swf|hta)$/i';

	/**
	 * Attachment whose Skwirrel file name changed, to be let go once its replacement is imported.
	 * Releasing it earlier would leave nothing to reuse when the download then fails.
	 */
	private int $pending_release = 0;

	/** @var array{attachment_id?: int|string|null, file_name?: string|null}|array{} */
	private array $pending_release_meta = [];

	/** Set when the last import_image()/import_file() call refused the file type (a skip, not a failure). */
	private bool $last_refused = false;

	public function __construct() {
		$this->logger = new Skwirrel_WC_Sync_Logger();
	}

	/**
	 * Import image from URL. Downloads file and creates attachment directly (bypasses upload validation).
	 * Attaches to $parent_id when given (product post ID).
	 * $title = product_attachment_title (alt text + caption). $description = product_attachment_description.
	 *
	 * $api_meta captures stable Skwirrel-side identifiers carried by the API payload:
	 *   - attachment_id: Skwirrel `product_attachment_id` (stable PK across syncs).
	 *   - file_checksum: `file_sha256_checksum` of the source file content.
	 * When supplied, both are persisted as post meta so subsequent syncs can dedup
	 * on the stable ID and detect content changes via checksum comparison.
	 *
	 * @param array{attachment_id?: int|string|null, file_checksum?: string|null, file_name?: string|null, title?: string|null, caption?: string|null} $api_meta
	 * @return int Attachment ID or 0 on failure.
	 */
	public function import_image( string $url, string $title = '', int $parent_id = 0, string $alt_caption = '', string $description = '', array $api_meta = [] ): int {
		$this->last_refused = false;
		$url                = $this->normalize_download_url( $url );
		if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
			return 0;
		}

		$this->pending_release = 0;
		$hash                  = $this->url_hash( $url );
		$existing              = $this->find_valid_existing_attachment( $hash, $api_meta );
		if ( $existing ) {
			// Backfill BEFORE checking for a content update — pre-3.8 attachments
			// have no stored checksum, so the first post-3.8 sync just records
			// the current state. From the second sync onwards, an api/stored
			// checksum mismatch can cleanly trigger replace.
			$this->backfill_api_meta( $existing, $api_meta );
			$this->sync_texts( $existing, $api_meta );
			$this->maybe_replace_image_content( $existing, $url, $alt_caption, $description, $api_meta );
			$this->ensure_post_parent( $existing, $parent_id );
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = $this->download_to_temp( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			$this->logger->warning(
				'Failed to download image',
				[
					'url'   => $url,
					'error' => $tmp->get_error_message(),
				]
			);
			return 0;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize emits a warning on non-image bytes; we already handle the false return below.
		$image_info = @getimagesize( $tmp );
		if ( false === $image_info ) {
			wp_delete_file( $tmp );
			$this->logger->warning( 'Downloaded file is not a valid image', [ 'url' => $url ] );
			return 0;
		}

		$ext_map = [
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_GIF  => 'gif',
			IMAGETYPE_WEBP => 'webp',
		];
		$ext     = $ext_map[ $image_info[2] ] ?? 'jpg';

		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			wp_delete_file( $tmp );
			$this->logger->warning( 'Upload dir error', [ 'error' => $upload_dir['error'] ] );
			return 0;
		}

		$filename = Skwirrel_WC_Sync_File_Naming::build( $upload_dir['path'], (string) ( $api_meta['file_name'] ?? '' ), $ext, $url, 'image', true );
		if ( ! $this->is_allowed_file_name( $filename, 'image' ) ) {
			wp_delete_file( $tmp );
			$this->last_refused = true;
			return 0;
		}
		$dest = $upload_dir['path'] . '/' . $filename;
		if ( ! copy( $tmp, $dest ) ) {
			wp_delete_file( $tmp );
			$this->logger->warning( 'Failed to copy image to uploads', [ 'url' => $url ] );
			return 0;
		}
		wp_delete_file( $tmp );

		$filetype   = wp_check_filetype( $filename, $this->import_mimes() );
		$label      = $alt_caption ? $alt_caption : ( $title ? $title : preg_replace( '/\.[^.]+$/', '', $filename ) );
		$attachment = [
			'post_mime_type' => $filetype['type'],
			'post_title'     => wp_slash( '' !== $this->incoming_text( $api_meta, 'title' ) ? $this->incoming_text( $api_meta, 'title' ) : $label ),
			'post_excerpt'   => wp_slash( $this->incoming_text( $api_meta, 'caption' ) ),
			'post_content'   => wp_slash( $description ),
			'post_status'    => 'inherit',
		];

		$id = $this->insert_attachment( $attachment, $dest, $parent_id, $url );
		if ( 0 === $id ) {
			return 0;
		}

		if ( $alt_caption ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt_caption );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $dest );
		if ( ! is_wp_error( $metadata ) ) { // @phpstan-ignore function.impossibleType
			wp_update_attachment_metadata( $id, $metadata );
		}

		update_post_meta( $id, self::META_SKWIRREL_URL, $url );
		update_post_meta( $id, self::META_SKWIRREL_HASH, $hash );
		$this->persist_api_meta( $id, $api_meta );
		$this->release_pending();

		$this->logger->debug(
			'Imported image',
			[
				'url'                    => $url,
				'attachment_id'          => $id,
				'skwirrel_attachment_id' => $api_meta['attachment_id'] ?? null,
				'file_checksum'          => $api_meta['file_checksum'] ?? null,
			]
		);
		return $id;
	}

	/**
	 * Import file (PDF, etc.) from URL. Downloads and creates attachment directly (bypasses upload validation).
	 * Attaches to $parent_id when given (product post ID).
	 *
	 * @param array{attachment_id?: int|string|null, file_checksum?: string|null, file_name?: string|null, title?: string|null, caption?: string|null} $api_meta See import_image().
	 * @return int Attachment ID or 0 on failure.
	 */
	public function import_file( string $url, string $name = '', int $parent_id = 0, array $api_meta = [] ): int {
		$this->last_refused = false;
		$url                = $this->normalize_download_url( $url );
		if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
			return 0;
		}

		$this->pending_release = 0;
		$hash                  = $this->url_hash( $url );
		$existing              = $this->find_valid_existing_attachment( $hash, $api_meta );
		if ( $existing ) {
			// See backfill rationale in import_image().
			$this->backfill_api_meta( $existing, $api_meta );
			$this->sync_texts( $existing, $api_meta );
			$this->maybe_replace_file_content( $existing, $url, $api_meta );
			$this->ensure_post_parent( $existing, $parent_id );
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = $this->download_to_temp( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			$this->logger->warning(
				'Failed to download file',
				[
					'url'   => $url,
					'error' => $tmp->get_error_message(),
				]
			);
			return 0;
		}

		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$basename = $name ? $name : ( $path ? basename( $path ) : '' );
		$ext      = $basename ? pathinfo( $basename, PATHINFO_EXTENSION ) : '';
		if ( ! preg_match( '/^[a-z0-9]{2,5}$/i', $ext ) ) {
			$ext = 'pdf';
		}

		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			wp_delete_file( $tmp );
			return 0;
		}

		$filename = Skwirrel_WC_Sync_File_Naming::build( $upload_dir['path'], (string) ( $api_meta['file_name'] ?? '' ), $ext, $url, 'file' );
		if ( ! $this->is_allowed_file_name( $filename, 'file' ) ) {
			wp_delete_file( $tmp );
			$this->last_refused = true;
			return 0;
		}
		$dest = $upload_dir['path'] . '/' . sanitize_file_name( $filename );
		if ( ! copy( $tmp, $dest ) ) {
			wp_delete_file( $tmp );
			$this->logger->warning( 'Failed to copy file to uploads', [ 'url' => $url ] );
			return 0;
		}
		wp_delete_file( $tmp );

		$filetype   = wp_check_filetype( $dest, $this->import_mimes() );
		$attachment = [
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
			'post_title'     => wp_slash( '' !== $this->incoming_text( $api_meta, 'title' ) ? $this->incoming_text( $api_meta, 'title' ) : preg_replace( '/\.[^.]+$/', '', $filename ) ),
			'post_excerpt'   => wp_slash( $this->incoming_text( $api_meta, 'caption' ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$id = $this->insert_attachment( $attachment, $dest, $parent_id, $url );
		if ( 0 === $id ) {
			return 0;
		}

		update_post_meta( $id, self::META_SKWIRREL_URL, $url );
		update_post_meta( $id, self::META_SKWIRREL_HASH, $hash );
		$this->persist_api_meta( $id, $api_meta );
		$this->release_pending();

		return $id;
	}

	/**
	 * Ensure the attachment is parented to the given product.
	 *
	 * Only fills in a missing parent — never reassigns. Skwirrel media is
	 * routinely shared across products via dedup, and overwriting an
	 * existing parent would silently move the attachment in the WP media
	 * library admin.
	 */
	private function ensure_post_parent( int $attachment_id, int $parent_id ): void {
		if ( $parent_id <= 0 ) {
			return;
		}
		if ( 0 !== wp_get_post_parent_id( $attachment_id ) ) {
			return;
		}
		wp_update_post(
			[
				'ID'          => $attachment_id,
				'post_parent' => $parent_id,
			]
		);
	}

	/**
	 * Decide whether the existing attachment needs its file content replaced.
	 *
	 * Replacement is triggered only when both sides supply a non-empty
	 * file_sha256_checksum AND they differ. Missing/empty checksums on either
	 * side are interpreted as "no signal" — preferable to false positives
	 * that would re-download every legacy attachment on the first re-sync
	 * after upgrading to 3.8.
	 *
	 * @param array{file_checksum?: string|null, ...} $api_meta
	 */
	private function should_replace_content( int $attachment_id, array $api_meta ): bool {
		$api_cs = isset( $api_meta['file_checksum'] ) ? strtolower( trim( (string) $api_meta['file_checksum'] ) ) : '';
		if ( '' === $api_cs ) {
			return false;
		}
		$stored_cs = (string) get_post_meta( $attachment_id, self::META_SKWIRREL_FILE_CHECKSUM, true );
		if ( '' === $stored_cs ) {
			return false;
		}
		return $api_cs !== $stored_cs;
	}

	/**
	 * Re-download an image attachment when its source content changed.
	 *
	 * The new file lands at a fresh path (under the existing attachment ID),
	 * the previous main file and all generated sub-sizes are removed, and
	 * the attachment metadata + mime type + checksum are refreshed. The WP
	 * attachment ID is preserved so post_parent links and any references
	 * elsewhere keep working.
	 *
	 * Failures (download error, invalid image bytes, copy failure) leave
	 * the existing attachment untouched and only emit a warning — the
	 * sync run as a whole must keep moving.
	 *
	 * @param array{attachment_id?: int|string|null, file_checksum?: string|null, file_name?: string|null, title?: string|null, caption?: string|null} $api_meta
	 */
	private function maybe_replace_image_content( int $attachment_id, string $url, string $alt_caption, string $description, array $api_meta ): void {
		if ( ! $this->should_replace_content( $attachment_id, $api_meta ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = $this->download_to_temp( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			$this->logger->warning(
				'Content-update download failed; keeping existing image',
				[
					'url'           => $url,
					'attachment_id' => $attachment_id,
					'error'         => $tmp->get_error_message(),
				]
			);
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize emits a warning on non-image bytes; we already handle the false return below.
		$image_info = @getimagesize( $tmp );
		if ( false === $image_info ) {
			wp_delete_file( $tmp );
			$this->logger->warning(
				'Content-update bytes are not a valid image; keeping existing',
				[
					'url'           => $url,
					'attachment_id' => $attachment_id,
				]
			);
			return;
		}

		$ext_map = [
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_GIF  => 'gif',
			IMAGETYPE_WEBP => 'webp',
		];
		$ext     = $ext_map[ $image_info[2] ] ?? 'jpg';

		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			wp_delete_file( $tmp );
			$this->logger->warning(
				'Content-update upload dir error',
				[
					'attachment_id' => $attachment_id,
					'error'         => $upload_dir['error'],
				]
			);
			return;
		}
		$filename = Skwirrel_WC_Sync_File_Naming::build( $upload_dir['path'], (string) ( $api_meta['file_name'] ?? '' ), $ext, $url, 'image_replace', true );
		$mimes    = $this->import_mimes();
		if ( ! $this->is_allowed_file_name( $filename, 'image_replace', $attachment_id, $mimes ) ) {
			wp_delete_file( $tmp );
			return;
		}
		$dest = $upload_dir['path'] . '/' . $filename;
		if ( ! copy( $tmp, $dest ) ) {
			wp_delete_file( $tmp );
			$this->logger->warning(
				'Content-update copy failed; keeping existing',
				[
					'url'           => $url,
					'attachment_id' => $attachment_id,
				]
			);
			return;
		}
		wp_delete_file( $tmp );

		$old_path = get_attached_file( $attachment_id );
		$old_meta = wp_get_attachment_metadata( $attachment_id );

		$filetype = wp_check_filetype( $filename, $mimes );
		if ( ! empty( $filetype['type'] ) ) {
			wp_update_post( wp_slash( $this->replacement_post_fields( $attachment_id, $filetype['type'], $description, $api_meta ) ) );
		}
		update_attached_file( $attachment_id, $dest );
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $dest );
		if ( ! is_wp_error( $new_meta ) ) { // @phpstan-ignore function.impossibleType
			wp_update_attachment_metadata( $attachment_id, $new_meta );
		}

		// Only delete the previous main file when it's a different path — when
		// time() collides within the same sync second, copy() will have already
		// overwritten the original and deleting it would wipe the freshly
		// written bytes. Sub-sizes are still cleaned via the metadata.
		$old_path_str = is_string( $old_path ) ? $old_path : '';
		$delete_main  = ( '' !== $old_path_str && $old_path_str !== $dest ) ? $old_path_str : '';
		$old_meta_arr = is_array( $old_meta ) ? $old_meta : [];
		$cleanup_dir  = '' !== $old_path_str ? dirname( $old_path_str ) : '';
		$this->cleanup_old_attachment_files( $delete_main, $old_meta_arr, $cleanup_dir );

		update_post_meta( $attachment_id, self::META_SKWIRREL_URL, $url );
		update_post_meta( $attachment_id, self::META_SKWIRREL_HASH, $this->url_hash( $url ) );
		$this->persist_api_meta( $attachment_id, $api_meta );

		$this->logger->info(
			'Image content replaced (checksum changed)',
			[
				'attachment_id'          => $attachment_id,
				'url'                    => $url,
				'skwirrel_attachment_id' => $api_meta['attachment_id'] ?? null,
				'new_checksum'           => $api_meta['file_checksum'] ?? null,
			]
		);
	}

	/**
	 * Re-download a non-image attachment (PDF, etc.) when its checksum changed.
	 *
	 * Same shape as maybe_replace_image_content() but without the
	 * getimagesize() validation step or sub-size cleanup. The previous main
	 * file is deleted after the new one has been written, so a failed
	 * download or copy never leaves the attachment in a half-written state.
	 *
	 * @param array{attachment_id?: int|string|null, file_checksum?: string|null, file_name?: string|null, title?: string|null, caption?: string|null} $api_meta
	 */
	private function maybe_replace_file_content( int $attachment_id, string $url, array $api_meta ): void {
		if ( ! $this->should_replace_content( $attachment_id, $api_meta ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = $this->download_to_temp( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			$this->logger->warning(
				'Content-update download failed; keeping existing file',
				[
					'url'           => $url,
					'attachment_id' => $attachment_id,
					'error'         => $tmp->get_error_message(),
				]
			);
			return;
		}

		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$basename = $path ? basename( $path ) : '';
		$ext      = $basename ? pathinfo( $basename, PATHINFO_EXTENSION ) : '';
		if ( ! preg_match( '/^[a-z0-9]{2,5}$/i', $ext ) ) {
			$ext = 'pdf';
		}

		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			wp_delete_file( $tmp );
			return;
		}
		$filename = Skwirrel_WC_Sync_File_Naming::build( $upload_dir['path'], (string) ( $api_meta['file_name'] ?? '' ), $ext, $url, 'file_replace' );
		if ( ! $this->is_allowed_file_name( $filename, 'file_replace', $attachment_id ) ) {
			wp_delete_file( $tmp );
			return;
		}
		$dest = $upload_dir['path'] . '/' . sanitize_file_name( $filename );
		if ( ! copy( $tmp, $dest ) ) {
			wp_delete_file( $tmp );
			$this->logger->warning(
				'Content-update copy failed; keeping existing file',
				[
					'url'           => $url,
					'attachment_id' => $attachment_id,
				]
			);
			return;
		}
		wp_delete_file( $tmp );

		$old_path = get_attached_file( $attachment_id );

		$filetype = wp_check_filetype( $dest, $this->import_mimes() );
		wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
			]
		);
		update_attached_file( $attachment_id, $dest );

		// Only delete the old file when it's at a different path — see the
		// matching guard in maybe_replace_image_content() for the details.
		$old_path_str = is_string( $old_path ) ? $old_path : '';
		$delete_main  = ( '' !== $old_path_str && $old_path_str !== $dest ) ? $old_path_str : '';
		$this->cleanup_old_attachment_files( $delete_main, [], '' );

		update_post_meta( $attachment_id, self::META_SKWIRREL_URL, $url );
		update_post_meta( $attachment_id, self::META_SKWIRREL_HASH, $this->url_hash( $url ) );
		$this->persist_api_meta( $attachment_id, $api_meta );

		$this->logger->info(
			'File content replaced (checksum changed)',
			[
				'attachment_id'          => $attachment_id,
				'url'                    => $url,
				'skwirrel_attachment_id' => $api_meta['attachment_id'] ?? null,
				'new_checksum'           => $api_meta['file_checksum'] ?? null,
			]
		);
	}

	/**
	 * Whether a file may be written to uploads under this name: its extension must be allowed for
	 * this site (`wp_check_filetype()`, so `upload_mimes` additions count) or be one of the extra
	 * document types (import_mimes()), and no part of the name
	 * may be a script extension (`x.php.pdf`). Checked on the name as built and as it is stored, so
	 * the outcome never depends on how `sanitize_file_name()` treats a double extension. A refused
	 * name is logged with its extension only, not the URL.
	 *
	 * @param string $filename      File name without directory.
	 * @param string $context       `image`, `file`, `image_replace` or `file_replace`.
	 * @param int    $attachment_id Existing attachment being replaced, or 0.
	 * @param array<string,string>|null $mimes Types from import_mimes() when the caller already has them.
	 */
	private function is_allowed_file_name( string $filename, string $context, int $attachment_id = 0, ?array $mimes = null ): bool {
		$mimes ??= $this->import_mimes();
		foreach ( array_unique( [ $filename, sanitize_file_name( $filename ) ] ) as $name ) {
			$refused = '';
			$parts   = explode( '.', strtolower( $name ) );
			$ext     = count( $parts ) > 1 ? (string) array_pop( $parts ) : '';
			array_shift( $parts );
			foreach ( $parts as $inner ) {
				if ( preg_match( self::EXECUTABLE_EXTENSION_PATTERN, $inner ) ) {
					$refused = $inner;
					break;
				}
			}
			if ( '' === $refused ) {
				$check = wp_check_filetype( $name, $mimes );
				if ( empty( $check['ext'] ) || preg_match( self::EXECUTABLE_EXTENSION_PATTERN, $ext ) ) {
					$refused = '' !== $ext ? $ext : '(none)';
				}
			}
			if ( '' !== $refused ) {
				$this->logger->warning(
					'File type not allowed for import; file not imported',
					[
						'extension'     => $refused,
						'context'       => $context,
						'attachment_id' => $attachment_id > 0 ? $attachment_id : null,
					]
				);
				return false;
			}
		}
		return true;
	}

	/**
	 * File types this plugin may import: the site's allowlist without any per-user rule
	 * (site_mimes(), including `upload_mimes` additions) plus the extra technical document types.
	 * The extra types pass through `skwirrel_wc_sync_allowed_document_types` and are cleaned: an extension must be
	 * 2-5 plain letters/digits (the length import_file() and File_Naming recognise as an extension),
	 * and script and browser-script extensions are dropped, whatever the filter returns.
	 *
	 * @return array<string,string> Extension pattern (`a|b`) => MIME type, as wp_check_filetype() expects.
	 */
	private function import_mimes(): array {
		$extra = $this->filtered_document_types();
		$mimes = $this->site_mimes();
		if ( ! is_array( $extra ) ) {
			return $mimes;
		}
		foreach ( $extra as $pattern => $mime ) {
			if ( ! is_string( $mime ) || '' === $mime ) {
				continue;
			}
			$exts = array_filter(
				explode( '|', strtolower( (string) $pattern ) ),
				fn( string $ext ): bool => 1 === preg_match( '/^[a-z0-9]{2,5}$/', $ext )
					&& ! preg_match( self::EXECUTABLE_EXTENSION_PATTERN, $ext )
					&& ! preg_match( self::NEVER_EXTRA_EXTENSION_PATTERN, $ext )
			);
			if ( ! empty( $exts ) ) {
				$mimes[ implode( '|', $exts ) ] = $mime;
			}
		}
		return $mimes;
	}

	/**
	 * The site's allowed types as core builds them for a user without `unfiltered_html`, whoever
	 * runs the sync. get_allowed_mime_types() cannot do this: it asks about a user, and a
	 * `WP_User( 0 )` still resolves to the current user through is_super_admin(), so a single-product
	 * resync started by a multisite super admin would get html and js. `upload_mimes` still runs, so
	 * the site decides (also on multisite, where core limits this list through that filter).
	 *
	 * @return array<string,string>
	 */
	private function site_mimes(): array {
		$mimes = wp_get_mime_types();
		unset( $mimes['swf'], $mimes['exe'], $mimes['htm|html'], $mimes['js'] );
		/** This filter is documented in wp-includes/functions.php */
		$mimes = apply_filters( 'upload_mimes', $mimes, null );
		return is_array( $mimes ) ? $mimes : [];
	}

	/**
	 * The extra document types after the filter, unvalidated: a third-party callback may return
	 * anything, so import_mimes() checks every entry.
	 */
	private function filtered_document_types(): mixed {
		/**
		 * Filters the extra document types Skwirrel imports accept on top of the site's allowlist.
		 * Extensions must be 2-5 letters/digits. Script extensions and types that run script in the
		 * browser (svg, html, xml, xsl, mht, js, swf, hta) are always dropped from the result. An
		 * entry with exactly the same key as a site type (for example `png`) overrides that type's
		 * MIME type for Skwirrel imports, images included.
		 *
		 * @param array<string,string> $types Extension pattern (`step|stp`) => MIME type.
		 */
		return apply_filters( 'skwirrel_wc_sync_allowed_document_types', self::EXTRA_DOCUMENT_TYPES );
	}

	/**
	 * Create the attachment post for a file already copied to uploads.
	 *
	 * `wp_insert_attachment()` reports a failure as 0 (or a `WP_Error` when asked for one). Either
	 * way the copied file is removed and 0 returned, so the caller stops before it records Skwirrel
	 * meta or lets go of the attachment it was replacing.
	 *
	 * @param array<string,mixed> $attachment Post fields.
	 * @return int Attachment ID, or 0 on failure.
	 */
	private function insert_attachment( array $attachment, string $dest, int $parent_id, string $url ): int {
		$id = wp_insert_attachment( $attachment, $dest, $parent_id );
		if ( is_wp_error( $id ) || (int) $id <= 0 ) { // @phpstan-ignore function.impossibleType
			wp_delete_file( $dest );
			$this->logger->warning(
				'Failed to create attachment',
				[
					'url'   => $url,
					'error' => is_wp_error( $id ) ? $id->get_error_message() : 'wp_insert_attachment() returned 0', // @phpstan-ignore function.impossibleType
				]
			);
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Post fields for an image whose file was replaced. The description and caption are only set
	 * when Skwirrel supplies them: an empty one must not wipe text typed by hand. A caption cleared
	 * in Skwirrel is handled by sync_texts(), which ran before this.
	 *
	 * @param array<string,mixed> $api_meta
	 * @return array{ID: int, post_mime_type: string, post_content?: string, post_excerpt?: string}
	 */
	private function replacement_post_fields( int $attachment_id, string $mime, string $description, array $api_meta ): array {
		$update = [
			'ID'             => $attachment_id,
			'post_mime_type' => $mime,
		];
		if ( '' !== trim( $description ) ) {
			$update['post_content'] = $description;
		}
		if ( '' !== $this->incoming_text( $api_meta, 'caption' ) ) {
			$update['post_excerpt'] = $this->incoming_text( $api_meta, 'caption' );
		}
		return $update;
	}

	/**
	 * Remove the previous main file and any generated image sub-sizes after
	 * a successful in-place replacement. Best-effort: missing files are not
	 * an error — they were probably already cleaned up manually.
	 *
	 * Pass an empty `$old_main_file` to skip deleting the main file (e.g. when
	 * the new file ended up at the same path due to a same-second collision —
	 * sub-sizes still need cleaning when the new dimensions don't match).
	 *
	 * @param string              $old_main_file Path to delete, or empty to skip the main file.
	 * @param array<string,mixed> $old_metadata  Pre-replacement attachment metadata (for sub-size filenames).
	 * @param string              $dir           Directory containing the sub-sizes; required when sub-sizes need cleaning.
	 */
	private function cleanup_old_attachment_files( string $old_main_file, array $old_metadata, string $dir = '' ): void {
		if ( '' !== $old_main_file && file_exists( $old_main_file ) ) {
			wp_delete_file( $old_main_file );
		}
		if ( empty( $old_metadata['sizes'] ) || ! is_array( $old_metadata['sizes'] ) ) {
			return;
		}
		if ( '' === $dir ) {
			return;
		}
		foreach ( $old_metadata['sizes'] as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) {
				continue;
			}
			$size_file = $dir . '/' . $size['file'];
			if ( file_exists( $size_file ) ) {
				wp_delete_file( $size_file );
			}
		}
	}

	/**
	 * Persist Skwirrel-side identifiers from the API payload onto a WP attachment.
	 *
	 * Both keys are written only when the corresponding payload value is non-empty.
	 * Stored values: attachment_id as string-cast int, file_checksum as lowercase
	 * hex (so casing differences in subsequent comparisons don't cause false positives).
	 *
	 * @param array{attachment_id?: int|string|null, file_checksum?: string|null, file_name?: string|null, title?: string|null, caption?: string|null} $api_meta
	 */
	private function persist_api_meta( int $attachment_id, array $api_meta ): void {
		$skwirrel_id = isset( $api_meta['attachment_id'] ) ? (int) $api_meta['attachment_id'] : 0;
		if ( $skwirrel_id > 0 ) {
			update_post_meta( $attachment_id, self::META_SKWIRREL_ATTACHMENT_ID, $skwirrel_id );
		}
		$checksum = isset( $api_meta['file_checksum'] ) ? strtolower( trim( (string) $api_meta['file_checksum'] ) ) : '';
		if ( '' !== $checksum ) {
			update_post_meta( $attachment_id, self::META_SKWIRREL_FILE_CHECKSUM, $checksum );
		}
		$file_name = $this->incoming_file_name( $api_meta );
		if ( '' !== $file_name ) {
			update_post_meta( $attachment_id, self::META_SKWIRREL_FILE_NAME, $file_name );
		}
		if ( '' !== $this->incoming_text( $api_meta, 'title' ) || '' !== $this->incoming_text( $api_meta, 'caption' ) ) {
			update_post_meta( $attachment_id, self::META_SKWIRREL_TEXTS_SYNCED, 1 );
		}
	}

	/**
	 * A text (`title` or `caption`) carried by the payload, or '' when there is none.
	 *
	 * @param array<string,mixed> $api_meta
	 */
	private function incoming_text( array $api_meta, string $key ): string {
		return isset( $api_meta[ $key ] ) ? trim( (string) $api_meta[ $key ] ) : '';
	}

	/**
	 * Keep the attachment Title and Caption in step with Skwirrel on every sync.
	 *
	 * Only acts when Skwirrel supplied a title or a description; with neither, the texts on the
	 * attachment (possibly edited by hand) are left alone. The Title is only replaced by a
	 * non-empty Skwirrel title, so it never falls back to a worse value; the Caption follows the
	 * description, which makes it empty when the description was cleared in Skwirrel.
	 *
	 * @param array<string,mixed> $api_meta
	 */
	private function sync_texts( int $attachment_id, array $api_meta ): void {
		$title   = $this->incoming_text( $api_meta, 'title' );
		$caption = $this->incoming_text( $api_meta, 'caption' );
		if ( '' === $title && '' === $caption ) {
			// Skwirrel supplied nothing now. If it supplied a text before, that text was cleared there:
			// the Caption goes too. Otherwise the texts on the attachment are not ours to touch.
			if ( '' !== (string) get_post_meta( $attachment_id, self::META_SKWIRREL_TEXTS_SYNCED, true ) ) {
				if ( '' !== (string) get_post_field( 'post_excerpt', $attachment_id ) ) {
					wp_update_post(
						[
							'ID'           => $attachment_id,
							'post_excerpt' => '',
						]
					);
				}
				delete_post_meta( $attachment_id, self::META_SKWIRREL_TEXTS_SYNCED );
			}
			return;
		}
		update_post_meta( $attachment_id, self::META_SKWIRREL_TEXTS_SYNCED, 1 );
		$fields = [];
		if ( '' !== $title && (string) get_post_field( 'post_title', $attachment_id ) !== $title ) {
			$fields['post_title'] = $title;
		}
		if ( (string) get_post_field( 'post_excerpt', $attachment_id ) !== $caption ) {
			$fields['post_excerpt'] = $caption;
		}
		if ( ! empty( $fields ) ) {
			wp_update_post( wp_slash( [ 'ID' => $attachment_id ] + $fields ) );
		}
	}

	/**
	 * The Skwirrel file name carried by the payload, or '' when there is none.
	 *
	 * @param array{file_name?: string|null, ...} $api_meta
	 */
	private function incoming_file_name( array $api_meta ): string {
		return isset( $api_meta['file_name'] ) ? trim( (string) $api_meta['file_name'] ) : '';
	}

	/**
	 * Lazy migration for pre-3.8 attachments: fill in `_skwirrel_attachment_id`
	 * and `_skwirrel_file_checksum` from the current API payload only when the
	 * stored value is empty. Crucially, this does NOT overwrite existing meta —
	 * a non-empty stored checksum that differs from the API value is a content-
	 * change signal that should drive maybe_replace_*_content(), not be
	 * silently squashed.
	 *
	 * Limitation: any content change that happened on the Skwirrel side BEFORE
	 * the first post-3.8 sync is invisible to us; the backfill establishes the
	 * baseline, and only future changes get detected.
	 *
	 * @param array{attachment_id?: int|string|null, file_checksum?: string|null, file_name?: string|null, title?: string|null, caption?: string|null} $api_meta
	 */
	private function backfill_api_meta( int $attachment_id, array $api_meta ): void {
		$skwirrel_id = isset( $api_meta['attachment_id'] ) ? (int) $api_meta['attachment_id'] : 0;
		if ( $skwirrel_id > 0 ) {
			$stored_id = (string) get_post_meta( $attachment_id, self::META_SKWIRREL_ATTACHMENT_ID, true );
			if ( '' === $stored_id ) {
				update_post_meta( $attachment_id, self::META_SKWIRREL_ATTACHMENT_ID, $skwirrel_id );
				$this->logger->debug(
					'Backfilled _skwirrel_attachment_id on legacy attachment',
					[
						'attachment_id'          => $attachment_id,
						'skwirrel_attachment_id' => $skwirrel_id,
					]
				);
			}
		}
		$checksum = isset( $api_meta['file_checksum'] ) ? strtolower( trim( (string) $api_meta['file_checksum'] ) ) : '';
		if ( '' !== $checksum ) {
			$stored_checksum = (string) get_post_meta( $attachment_id, self::META_SKWIRREL_FILE_CHECKSUM, true );
			if ( '' === $stored_checksum ) {
				update_post_meta( $attachment_id, self::META_SKWIRREL_FILE_CHECKSUM, $checksum );
				$this->logger->debug(
					'Backfilled _skwirrel_file_checksum on legacy attachment',
					[
						'attachment_id' => $attachment_id,
						'checksum'      => $checksum,
					]
				);
			}
		}
		$file_name = $this->incoming_file_name( $api_meta );
		if ( '' !== $file_name ) {
			$stored_name = (string) get_post_meta( $attachment_id, self::META_SKWIRREL_FILE_NAME, true );
			// Plugin naming ignores the name, so keep the record current there: a later switch
			// to another mode then compares against the name Skwirrel has today, not an old one.
			if ( '' === $stored_name || ( Skwirrel_WC_Sync_File_Naming::MODE_CURRENT === Skwirrel_WC_Sync_File_Naming::get_mode() && $stored_name !== $file_name ) ) {
				update_post_meta( $attachment_id, self::META_SKWIRREL_FILE_NAME, $file_name );
			}
		}
	}

	/**
	 * Whether the last import_image()/import_file() call returned 0 because the file type is not
	 * allowed for import (not on the site's allowlist nor the plugin's extra document types). Such a
	 * refusal is permanent, so callers treat it as a skip rather than a failure that should hold the
	 * product for a retry.
	 */
	public function last_import_was_refused(): bool {
		return $this->last_refused;
	}

	public function is_image_attachment_type( string $code ): bool {
		return in_array( strtoupper( $code ), self::IMAGE_TYPES, true );
	}

	/**
	 * Check if URL points to a file that is definitely not an image (by extension).
	 * Used to override type code classification when the API misclassifies documents.
	 */
	public function url_has_non_image_extension( string $url ): bool {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return false;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::NON_IMAGE_EXTENSIONS, true );
	}

	/**
	 * Check if string is a valid HTTP(S) URL. Uses filter_var with parse_url fallback.
	 */
	private function is_valid_url( string $url ): bool {
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return true;
		}
		$parsed = wp_parse_url( $url );
		return isset( $parsed['scheme'], $parsed['host'] )
			&& in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true );
	}

	/**
	 * Normalize URL from API: replace JSON-escaped \/ with /, trim, then rawurldecode.
	 * Handles both single and double-escaped URLs from JSON.
	 */
	private function normalize_download_url( string $url ): string {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		while ( str_contains( $url, '\\/' ) ) {
			$url = str_replace( '\\/', '/', $url );
		}
		return rawurldecode( $url );
	}

	/**
	 * Download URL to temp file. Uses browser-like User-Agent to avoid 403/404 from strict CDNs.
	 * Download links do not require auth.
	 * @return string|WP_Error Temp file path or error.
	 */
	private function download_to_temp( string $url, int $timeout = 60 ) {
		$args     = [
			'timeout' => $timeout,
			'headers' => [
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			],
		];
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 400 <= $code ) {
			$message = wp_remote_retrieve_response_message( $response );
			return new WP_Error( 'http_' . $code, $message ? $message : 'HTTP ' . $code );
		}
		$body     = wp_remote_retrieve_body( $response );
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$tmp      = wp_tempnam( basename( $url_path ? $url_path : 'download' ) );
		if ( false === $tmp || false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing to wp_tempnam() temp file // @phpstan-ignore identical.alwaysFalse
			return new WP_Error( 'temp', 'Failed to write temp file' );
		}
		return $tmp;
	}

	private function url_hash( string $url ): string {
		return hash( 'sha256', $url );
	}

	/**
	 * Locate an existing WP attachment AND verify its underlying file is still
	 * usable. The default check is a local `file_exists()` against the result
	 * of `get_attached_file()`, but that returns `false` on sites that use a
	 * media-offload plugin (WP Offload Media, S3 Uploads, …) which keeps the
	 * WP attachment record valid while removing the local file after pushing
	 * it to remote storage. Site code can override the default via the
	 * `skwirrel_wc_sync_attachment_is_valid` filter.
	 *
	 * When the local file is missing AND the filter does not declare the
	 * attachment otherwise valid, we deliberately do NOT call
	 * `wp_delete_attachment()`: invoking the WP delete pipeline would trigger
	 * offload-plugin hooks that may purge the remote copy too. Instead we
	 * disconnect the broken record from the Skwirrel-side identifiers
	 * (`_skwirrel_attachment_id`, `_skwirrel_url_hash`,
	 * `_skwirrel_file_checksum`, `_skwirrel_source_url`) so subsequent
	 * lookups miss it and the import path falls through to a fresh download.
	 * The orphan WP record itself stays put — harmless, and recoverable by
	 * an admin if needed.
	 *
	 * @param array{attachment_id?: int|string|null, ...} $api_meta
	 */
	private function find_valid_existing_attachment( string $url_hash, array $api_meta ): int {
		$existing = $this->find_existing_attachment( $url_hash, $api_meta );
		if ( ! $existing ) {
			return 0;
		}
		if ( $this->file_name_changed( $existing, $api_meta ) ) {
			$this->pending_release      = $existing;
			$this->pending_release_meta = $api_meta;
			return 0;
		}

		$file         = get_attached_file( $existing );
		$file_present = is_string( $file ) && '' !== $file && file_exists( $file );

		/**
		 * Allow offload plugins / site code to declare a Skwirrel attachment
		 * valid even when the LOCAL file is missing. Returning a truthy value
		 * here keeps the import path reusing the existing attachment instead
		 * of disconnecting it.
		 *
		 * @param bool        $file_present  Default: result of file_exists() on the local path.
		 * @param int         $attachment_id WP attachment ID under inspection.
		 * @param string|null $local_path    Path returned by get_attached_file(), or null.
		 */
		$is_valid = (bool) apply_filters(
			'skwirrel_wc_sync_attachment_is_valid',
			$file_present,
			$existing,
			is_string( $file ) ? $file : null
		);

		if ( $is_valid ) {
			return $existing;
		}

		// A rename batch is moving files right now: this one may be in transit, not missing.
		if ( get_transient( Skwirrel_WC_Sync_File_Rename_Job::ACTIVE_TRANSIENT ) ) {
			return $existing;
		}

		$this->logger->warning(
			'Skwirrel attachment record points to a missing file; clearing Skwirrel meta so future lookups miss it (record itself preserved for offload-plugin safety)',
			[
				'attachment_id'          => $existing,
				'expected_path'          => is_string( $file ) ? $file : null,
				'skwirrel_attachment_id' => $api_meta['attachment_id'] ?? null,
			]
		);
		delete_post_meta( $existing, self::META_SKWIRREL_ATTACHMENT_ID );
		delete_post_meta( $existing, self::META_SKWIRREL_FILE_CHECKSUM );
		delete_post_meta( $existing, self::META_SKWIRREL_HASH );
		delete_post_meta( $existing, self::META_SKWIRREL_URL );
		return 0;
	}

	/**
	 * Whether Skwirrel now calls this file something else than when it was imported.
	 *
	 * Only matters when file names follow Skwirrel (modes B and C): the stored file then
	 * carries the old name, so the changed name means a new file. Without a stored name
	 * (earlier imports) or without an incoming name there is nothing to compare.
	 *
	 * @param array{file_name?: string|null, ...} $api_meta
	 */
	private function file_name_changed( int $attachment_id, array $api_meta ): bool {
		if ( Skwirrel_WC_Sync_File_Naming::MODE_CURRENT === Skwirrel_WC_Sync_File_Naming::get_mode() ) {
			return false;
		}
		$incoming = $this->incoming_file_name( $api_meta );
		if ( '' === $incoming ) {
			return false;
		}
		$stored = (string) get_post_meta( $attachment_id, self::META_SKWIRREL_FILE_NAME, true );
		return '' !== $stored && $stored !== $incoming;
	}

	/**
	 * Release the attachment that is being replaced after a file name change, now that its
	 * replacement exists.
	 */
	private function release_pending(): void {
		if ( $this->pending_release > 0 ) {
			$this->release_renamed_attachment( $this->pending_release, $this->pending_release_meta );
		}
		$this->pending_release      = 0;
		$this->pending_release_meta = [];
	}

	/**
	 * Let go of an attachment whose Skwirrel file name changed, so the import path adds a new one.
	 *
	 * Same approach as the missing-file path: the Skwirrel identifiers are cleared so no lookup
	 * finds the record again, and the record and its file stay in the media library. It is also
	 * detached from the product it was uploaded to. Cleaning it up is left to the site owner.
	 *
	 * @param array{file_name?: string|null, attachment_id?: int|string|null, ...} $api_meta
	 */
	private function release_renamed_attachment( int $attachment_id, array $api_meta ): void {
		$this->logger->info(
			'Skwirrel file name changed; importing a new file and leaving the old one unattached',
			[
				'attachment_id' => $attachment_id,
				'old_name'      => (string) get_post_meta( $attachment_id, self::META_SKWIRREL_FILE_NAME, true ),
				'new_name'      => $this->incoming_file_name( $api_meta ),
			]
		);
		delete_post_meta( $attachment_id, self::META_SKWIRREL_ATTACHMENT_ID );
		delete_post_meta( $attachment_id, self::META_SKWIRREL_FILE_CHECKSUM );
		delete_post_meta( $attachment_id, self::META_SKWIRREL_HASH );
		delete_post_meta( $attachment_id, self::META_SKWIRREL_URL );
		if ( (int) get_post_field( 'post_parent', $attachment_id ) > 0 ) {
			wp_update_post(
				[
					'ID'          => $attachment_id,
					'post_parent' => 0,
				]
			);
		}
	}

	/**
	 * Locate an existing WP attachment for a Skwirrel media item.
	 *
	 * Lookup priority:
	 *   1. Skwirrel `product_attachment_id` (most stable — survives URL changes
	 *      such as CDN reorganisations or filename rewrites on the Skwirrel side).
	 *   2. SHA-256 hash of the normalised source URL (fallback for attachments
	 *      imported before the attachment_id meta key was introduced).
	 *
	 * @param string                                      $url_hash  SHA-256 of the normalised URL.
	 * @param array{attachment_id?: int|string|null, ...} $api_meta  Skwirrel-side identifiers.
	 */
	private function find_existing_attachment( string $url_hash, array $api_meta ): int {
		$skwirrel_id = isset( $api_meta['attachment_id'] ) ? (int) $api_meta['attachment_id'] : 0;
		if ( $skwirrel_id > 0 ) {
			$found = $this->find_attachment_by_skwirrel_id( $skwirrel_id );
			if ( $found ) {
				return $found;
			}
		}
		return $this->find_attachment_by_hash( $url_hash );
	}

	private function find_attachment_by_skwirrel_id( int $skwirrel_attachment_id ): int {
		if ( $skwirrel_attachment_id <= 0 ) {
			return 0;
		}
		$posts = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => self::META_SKWIRREL_ATTACHMENT_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $skwirrel_attachment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- meta is stored as string by update_post_meta
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	private function find_attachment_by_hash( string $hash ): int {
		$posts = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => self::META_SKWIRREL_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}
}
