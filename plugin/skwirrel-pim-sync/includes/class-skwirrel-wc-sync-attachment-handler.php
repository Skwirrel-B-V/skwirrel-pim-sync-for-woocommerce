<?php
/**
 * Skwirrel → WooCommerce Attachment Handler.
 *
 * Handles all attachment/image/document-related logic extracted from Product Mapper.
 * Processes Skwirrel product attachments: images, downloadable files, and documents.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Attachment_Handler {

	private Skwirrel_WC_Sync_Logger $logger;
	private Skwirrel_WC_Sync_Media_Importer $media_importer;
	/** @phpstan-ignore property.onlyWritten */
	private string $image_language;

	/** Number of image attachments with a valid URL that failed to import on the last call. */
	private int $last_image_failures = 0;

	/** Number of downloadable files with a valid URL that failed to import on the last call. */
	private int $last_file_failures = 0;

	/** Number of document attachments with a valid URL that failed to import on the last call. */
	private int $last_doc_failures = 0;

	public function __construct( string $image_language = 'nl' ) {
		$this->logger         = new Skwirrel_WC_Sync_Logger();
		$this->media_importer = new Skwirrel_WC_Sync_Media_Importer();
		$this->image_language = $image_language;
	}

	/**
	 * Get product_attachment_title and product_attachment_description for the configured image language.
	 * Language pattern: ^[a-z]{2}(-[A-Z]{2}){0,1}$ (e.g. nl, nl-NL).
	 */
	private function get_attachment_meta_for_language( array $att ): array {
		$t = $this->pick_attachment_translation( $att );
		if ( [] === $t ) {
			return [
				'title'       => $att['file_name'] ?? '',
				'description' => '',
			];
		}
		return [
			'title'       => (string) ( $t['product_attachment_title'] ?? $att['file_name'] ?? '' ),
			'description' => (string) ( $t['product_attachment_description'] ?? '' ),
		];
	}

	/**
	 * Pick the `_attachment_translations` entry for the configured image language.
	 *
	 * The exact → two-letter-prefix → first-entry chain, extracted so images and documents
	 * share one implementation rather than drifting apart. Only the *selection* lives here;
	 * each caller layers its own fallback tail on top, because documents need attachment-level
	 * `product_attachment_title` to sit between the translation and `file_name` while the image
	 * path collapses straight to `file_name`.
	 *
	 * Language pattern: ^[a-z]{2}(-[A-Z]{2}){0,1}$ (e.g. nl, nl-NL).
	 *
	 * @param array<string, mixed> $att Raw API attachment.
	 * @return array<string, mixed> The winning translation entry, or [] when there are none.
	 */
	private function pick_attachment_translation( array $att ): array {
		$lang         = get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl';
		$translations = $att['_attachment_translations'] ?? [];
		if ( empty( $translations ) || ! is_array( $translations ) ) {
			return [];
		}
		foreach ( $translations as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$tlang = (string) ( $t['language'] ?? '' );
			if ( 0 === strcasecmp( $tlang, (string) $lang ) ) {
				return $t;
			}
		}
		foreach ( $translations as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$tlang = (string) ( $t['language'] ?? '' );
			if ( strlen( (string) $lang ) >= 2 && strlen( $tlang ) >= 2 && 0 === strcasecmp( substr( $tlang, 0, 2 ), substr( (string) $lang, 0, 2 ) ) ) {
				return $t;
			}
		}
		$list  = array_values( $translations );
		$first = $list[0] ?? [];
		return is_array( $first ) ? $first : [];
	}

	/**
	 * Resolve the human-readable display name for a document link (FR-23).
	 *
	 * Fallback chain, each candidate trimmed and rejected when empty so a translation entry that
	 * exists with a blank title falls through rather than winning:
	 * translated `product_attachment_title` → attachment-level `product_attachment_title` →
	 * `file_name` → URL basename → the literal `Document`.
	 *
	 * Never returns an empty string. That matters more than it looks: `get_documents_for_product()`
	 * drops any document whose name is empty, so a nameless document does not render badly — it
	 * vanishes from the tab entirely.
	 *
	 * Public so it is unit-testable without the network, the way
	 * {@see Skwirrel_WC_Sync_Media_Importer::is_image_attachment_type()} is. The returned string is
	 * raw: escaping stays the consumer's job, exactly as today.
	 *
	 * @param array<string, mixed> $att Raw API attachment.
	 * @param string               $url Normalised source URL, used for the basename fallback.
	 */
	public function resolve_document_name( array $att, string $url ): string {
		$translation = $this->pick_attachment_translation( $att );

		$candidates = [
			(string) ( $translation['product_attachment_title'] ?? '' ),
			(string) ( $att['product_attachment_title'] ?? '' ),
			(string) ( $att['file_name'] ?? '' ),
		];
		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$basename = is_string( $path ) && '' !== $path ? trim( basename( $path ) ) : '';

		return '' !== $basename ? $basename : 'Document';
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
	private function normalize_attachment_url( string $url ): string {
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
	 * Get attachment IDs for images (featured + gallery).
	 * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
	 */
	public function get_image_attachment_ids( array $product, int $product_id = 0 ): array {
		$this->last_image_failures = 0;
		$opts                      = get_option( 'skwirrel_wc_sync_settings', [] );
		if ( isset( $opts['sync_images'] ) && ! $opts['sync_images'] ) {
			return [];
		}

		$attachments = $product['_attachments'] ?? $product['attachments'] ?? [];
		$ids         = [];
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['for_internal_use'] ) ) {
				continue;
			}
			$code = $att['product_attachment_type_code'] ?? '';
			if ( ! $this->media_importer->is_image_attachment_type( $code ) ) {
				continue;
			}
			$url = $this->normalize_attachment_url( $att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '' );
			if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
				$this->logger->debug(
					'Skipping image attachment: no valid URL',
					[
						'product'    => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
						'code'       => $code,
						'source_url' => $att['source_url'] ?? null,
					]
				);
				continue;
			}
			// Skip files that are clearly not images (e.g. PDFs with an image type code).
			if ( $this->media_importer->url_has_non_image_extension( $url ) ) {
				$this->logger->debug(
					'Skipping non-image file in image pipeline',
					[
						'url'  => $url,
						'code' => $code,
					]
				);
				continue;
			}
			$order    = $att['product_attachment_order'] ?? $att['order'] ?? 999;
			$meta     = $this->get_attachment_meta_for_language( $att );
			$api_meta = $this->build_api_meta( $att );
			$id       = $this->media_importer->import_image( $url, $att['file_name'] ?? '', $product_id, $meta['title'], $meta['description'], $api_meta );
			if ( ! $id ) {
				// Valid image URL that the importer could not download/store — a real failure
				// (network/copy/upload), not a skip. Count it so the caller can hold the change gate.
				++$this->last_image_failures;
			} elseif ( ! in_array( $id, array_column( $ids, 'id' ), true ) ) {
				$ids[] = [
					'id'    => $id,
					'order' => $order,
				];
			}
		}
		usort( $ids, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		$result = array_map( fn( $x ) => $x['id'], $ids );
		if ( ! empty( $attachments ) && empty( $result ) ) {
			$this->logger->debug(
				'Product has attachments but no image imports succeeded',
				[
					'product'          => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
					'attachment_count' => count( $attachments ),
					'sample'           => array_slice( $attachments, 0, 2 ),
				]
			);
		}
		return $result;
	}

	/**
	 * Number of image attachments with a valid URL that failed to import during the most recent
	 * get_image_attachment_ids() call (download/copy/upload errors — not skips). 0 means complete.
	 */
	public function get_last_image_failure_count(): int {
		return $this->last_image_failures;
	}

	/**
	 * Total media-import failures (images + downloadable files + documents) across the most recent
	 * get_image_attachment_ids() / get_downloadable_files() / get_document_attachments() calls.
	 * 0 means every requested file was imported. import_file()/import_image() return 0 (not throw)
	 * on a download/copy/insert error, so this is the only way the caller learns media is incomplete.
	 */
	public function get_last_media_failure_count(): int {
		return $this->last_image_failures + $this->last_file_failures + $this->last_doc_failures;
	}

	/**
	 * Get downloadable file URLs/names for product (MAN, DAT, etc.).
	 * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
	 */
	public function get_downloadable_files( array $product, int $product_id = 0 ): array {
		$this->last_file_failures = 0;
		$attachments              = $product['_attachments'] ?? $product['attachments'] ?? [];
		$files                    = [];
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['for_internal_use'] ) ) {
				continue;
			}
			$code = $att['product_attachment_type_code'] ?? '';
			$url  = $this->normalize_attachment_url( $att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '' );
			if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
				continue;
			}
			// Skip true images; rescue misclassified documents (image type code but non-image extension).
			if ( $this->media_importer->is_image_attachment_type( $code ) && ! $this->media_importer->url_has_non_image_extension( $url ) ) {
				continue;
			}
			$name     = $att['file_name'] ?? 'Download';
			$api_meta = $this->build_api_meta( $att );
			$id       = $this->media_importer->import_file( $url, $name, $product_id, $api_meta );
			if ( ! $id ) {
				// Valid file URL the importer could not download/store — a real failure.
				++$this->last_file_failures;
				continue;
			}
			$guid = wp_get_attachment_url( $id );
			if ( $guid ) {
				$files[] = [
					'name' => $name,
					'file' => $guid,
				];
			}
		}
		return $files;
	}

	/**
	 * Get document attachments (PDF, etc.) for product tab and dashboard.
	 * Uses same non-image sources as downloadable files but returns full metadata for display.
	 * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
	 * @return array<int, array{id: int, url: string, name: string, type: string, type_label: string}>
	 */
	public function get_document_attachments( array $product, int $product_id = 0 ): array {
		$this->last_doc_failures = 0;
		$raw                     = $product['_attachments'] ?? $product['attachments'] ?? [];
		$attachments             = is_array( $raw ) && isset( $raw[0] ) ? $raw : ( is_array( $raw ) ? array_values( $raw ) : [] );
		$docs                    = [];
		foreach ( $attachments as $att ) {
			if ( ! is_array( $att ) ) {
				continue;
			}
			if ( ! empty( $att['for_internal_use'] ) ) {
				continue;
			}
			$code = (string) ( $att['product_attachment_type_code'] ?? $att['attachment_type_code'] ?? '' );
			$url  = $this->normalize_attachment_url( $att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '' );
			if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
				continue;
			}
			// Skip true images; rescue misclassified documents (image type code but non-image extension).
			if ( $this->media_importer->is_image_attachment_type( $code ) && ! $this->media_importer->url_has_non_image_extension( $url ) ) {
				continue;
			}
			// Two different names, deliberately. The human title is for the link text only;
			// import_file() uses its $name argument solely to derive the stored file's extension,
			// and a title like "Montagehandleiding" has none — which would silently store every
			// document as .pdf and corrupt .dwg/.xlsx/.zip attachments. Keep file_name flowing
			// there; when it is empty, import_file() falls back to the URL basename itself.
			$name        = $this->resolve_document_name( $att, $url );
			$import_name = (string) ( $att['file_name'] ?? '' );
			$api_meta    = $this->build_api_meta( $att );
			$id          = $this->media_importer->import_file( $url, $import_name, $product_id, $api_meta );
			if ( ! $id ) {
				// Valid document URL the importer could not download/store — a real failure.
				++$this->last_doc_failures;
				continue;
			}
			$guid = wp_get_attachment_url( $id );
			if ( $guid ) {
				$docs[] = [
					'id'         => $id,
					'url'        => $guid,
					'name'       => $name,
					'type'       => $code,
					'type_label' => $this->get_document_type_label( $code ),
				];
			}
		}
		return $docs;
	}

	/** Document type codes from Skwirrel: MAN=Manual, DAT=Datasheet, etc. */
	private function get_document_type_label( string $code ): string {
		$labels = [
			'MAN' => __( 'Manual', 'skwirrel-pim-sync' ),
			'DAT' => __( 'Datasheet', 'skwirrel-pim-sync' ),
			'CER' => __( 'Certificate', 'skwirrel-pim-sync' ),
			'WAR' => __( 'Warranty', 'skwirrel-pim-sync' ),
			'OTV' => __( 'Other document', 'skwirrel-pim-sync' ),
		];
		return $labels[ strtoupper( $code ) ] ?? $code;
	}

	/**
	 * Extract Skwirrel-side identifiers from a `_attachments[]` payload entry.
	 *
	 * Returns the shape expected by Skwirrel_WC_Sync_Media_Importer::import_image()
	 * / import_file() so the importer can persist the stable attachment_id and
	 * file_checksum onto the WordPress attachment for cross-sync deduplication.
	 *
	 * @param array<string,mixed> $att Single Skwirrel _attachments[] entry.
	 * @return array{attachment_id: int|null, file_checksum: string|null}
	 */
	private function build_api_meta( array $att ): array {
		return [
			'attachment_id' => isset( $att['product_attachment_id'] ) ? (int) $att['product_attachment_id'] : null,
			'file_checksum' => isset( $att['file_sha256_checksum'] ) ? (string) $att['file_sha256_checksum'] : null,
		];
	}
}
