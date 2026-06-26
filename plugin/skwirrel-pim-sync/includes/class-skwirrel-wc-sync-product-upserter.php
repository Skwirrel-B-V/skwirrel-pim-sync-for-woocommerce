<?php
/**
 * Skwirrel Product Upserter.
 *
 * Handles all product upsert operations: creating/updating simple products,
 * variable products (from grouped products), and variations.
 * Extracted from Skwirrel_WC_Sync_Service for separation of concerns.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_Upserter {

	private Skwirrel_WC_Sync_Logger $logger;
	private Skwirrel_WC_Sync_Product_Mapper $mapper;
	private Skwirrel_WC_Sync_Product_Lookup $lookup;
	private Skwirrel_WC_Sync_Category_Sync $category_sync;
	private Skwirrel_WC_Sync_Brand_Sync $brand_sync;
	private Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager;
	private Skwirrel_WC_Sync_Slug_Resolver $slug_resolver;

	/** @var array<int, array<string, int[]>> Deferred parent term updates: parent_id => [taxonomy => [term_id, ...]] */
	private array $deferred_parent_terms = [];

	/** @var array<int, array<string, string[]>> Deferred non-variation attributes: parent_id => [label => [value, ...]] */
	private array $deferred_parent_attrs = [];

	/** @var array<int, array<string, bool>> Deferred attribute visibility: parent_id => [label => visible] */
	private array $deferred_parent_attr_visibility = [];

	/** When true, a re-sync skips products whose Skwirrel `product_updated_on` has not advanced. */
	private bool $change_gate_enabled = false;

	/** Post meta storing the content-hash of the last fully-committed payload (for the JSON-diff gate). */
	public const CONTENT_HASH_META = '_skwirrel_content_hash';

	/** Post meta on a variable parent: content-hash of the last fully-built group definition (group gate). */
	public const GROUP_HASH_META = '_skwirrel_group_hash';

	/** Post meta on a variable parent: content-hash of the last fully-applied virtual product (virtual gate). */
	public const VIRTUAL_CONTENT_HASH_META = '_skwirrel_virtual_content_hash';

	/** Content-hash mode for this run: 'off' | 'observe' (compute+report, no skip) | 'enforce' (skip on match). */
	private string $content_hash_mode = 'off';

	/** Settings signature folded into the content hash so a settings/version change invalidates all hashes. */
	private string $content_hash_sig = '';

	/**
	 * Payload keys ALWAYS stripped before hashing: pure modification metadata, never content. Leaving
	 * `product_updated_on` in would make the content hash a mirror of the timestamp gate (the API bumps
	 * it on re-fetch even when the product content is identical), so enforce mode would gain nothing over
	 * the timestamp gate. Site-specific volatile keys can be added on top via the
	 * `skwirrel_wc_sync_content_hash_exclude` filter.
	 */
	private const HASH_EXCLUDE_KEYS = [ 'product_updated_on' ];

	/**
	 *
	 * @param Skwirrel_WC_Sync_Logger          $logger          Logger instance.
	 * @param Skwirrel_WC_Sync_Product_Mapper   $mapper          Product field mapper.
	 * @param Skwirrel_WC_Sync_Product_Lookup   $lookup          Product lookup helper.
	 * @param Skwirrel_WC_Sync_Category_Sync    $category_sync   Category sync handler.
	 * @param Skwirrel_WC_Sync_Brand_Sync       $brand_sync      Brand sync handler.
	 * @param Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager Taxonomy/attribute manager.
	 * @param Skwirrel_WC_Sync_Slug_Resolver    $slug_resolver   Product slug resolver.
	 */
	public function __construct(
		Skwirrel_WC_Sync_Logger $logger,
		Skwirrel_WC_Sync_Product_Mapper $mapper,
		Skwirrel_WC_Sync_Product_Lookup $lookup,
		Skwirrel_WC_Sync_Category_Sync $category_sync,
		Skwirrel_WC_Sync_Brand_Sync $brand_sync,
		Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager,
		Skwirrel_WC_Sync_Slug_Resolver $slug_resolver
	) {
		$this->logger           = $logger;
		$this->mapper           = $mapper;
		$this->lookup           = $lookup;
		$this->category_sync    = $category_sync;
		$this->brand_sync       = $brand_sync;
		$this->taxonomy_manager = $taxonomy_manager;
		$this->slug_resolver    = $slug_resolver;
	}

	/**
	 * Enable/disable the change gate for the current run. When enabled, an existing product whose
	 * Skwirrel `product_updated_on` matches the stored value is reported 'unchanged' and skipped.
	 * The service disables it for the first run / after a settings change so everything reprocesses.
	 *
	 * @param bool $enabled Whether to skip unchanged products this run.
	 */
	public function set_change_gate_enabled( bool $enabled ): void {
		$this->change_gate_enabled = $enabled;
	}

	/**
	 * Configure content-hash change detection for the current run.
	 *
	 * @param string $mode 'off', 'observe' (compute + report match/mismatch, no behavior change), or
	 *                     'enforce' (authoritative: skip an existing product when its stored hash equals
	 *                     the incoming one). In enforce mode the hash supersedes the timestamp gate.
	 * @param string $sig  Settings signature to fold in, so a settings/version change → all hashes differ.
	 */
	public function set_content_hash_context( string $mode, string $sig ): void {
		$this->content_hash_mode = in_array( $mode, [ 'off', 'observe', 'enforce' ], true ) ? $mode : 'off';
		$this->content_hash_sig  = $sig;
	}

	/**
	 * Content hash of a payload for the JSON-diff gate: md5 of the settings signature + a key-sorted
	 * JSON of the payload. Key-sorting makes it independent of API key order; folding in the signature
	 * makes a settings/version change invalidate every product's hash (so output re-applies). Returns
	 * '' when hashing is off. Modification-metadata keys in HASH_EXCLUDE_KEYS are always stripped first
	 * (so the hash reflects content, not the API's re-fetch timestamp); additional site-specific volatile
	 * keys can be dropped via the `skwirrel_wc_sync_content_hash_exclude` filter (returns an array of keys).
	 *
	 * @param array<string, mixed> $product Raw payload (already includes ETIM/custom classes/etc).
	 */
	private function content_hash( array $product ): string {
		if ( 'off' === $this->content_hash_mode ) {
			return '';
		}
		return $this->payload_signature( $product );
	}

	/**
	 * Content fingerprint of any payload, computed UNCONDITIONALLY (independent of content_hash_mode).
	 * The grouped-product and virtual-content gates key off `change_gate_enabled` rather than the
	 * product-level observe/enforce hash mode, so they need the raw signature even when the product hash
	 * is 'off'. Same recipe as content_hash(): strip metadata keys, key-sort, fold in the settings sig.
	 *
	 * @param array<string, mixed> $payload Payload to fingerprint.
	 */
	public function payload_signature( array $payload ): string {
		$exclude_keys = array_merge(
			self::HASH_EXCLUDE_KEYS,
			(array) apply_filters( 'skwirrel_wc_sync_content_hash_exclude', [], $payload )
		);
		foreach ( $exclude_keys as $key ) {
			unset( $payload[ $key ] );
		}
		self::ksort_recursive( $payload );
		return md5( $this->content_hash_sig . '|' . (string) wp_json_encode( $payload ) );
	}

	/**
	 * Recursively ksort an array in place so its JSON encoding is independent of key order.
	 *
	 * @param array<mixed> $arr
	 */
	private static function ksort_recursive( array &$arr ): void {
		foreach ( $arr as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_recursive( $value );
			}
		}
		unset( $value );
		ksort( $arr );
	}

	/**
	 * Decide whether an existing product can be skipped as unchanged.
	 *
	 * Pure decision (no side effects) so it is unit-testable: only an existing product (not new),
	 * with the gate enabled and a non-empty incoming `product_updated_on` that equals the stored
	 * value, counts as unchanged. Anything missing/different is treated as changed.
	 *
	 * @param bool   $is_new              Whether the product is being created.
	 * @param string $stored_updated_on   `_skwirrel_updated_on` currently on the WC product.
	 * @param string $incoming_updated_on `product_updated_on` from the fresh payload.
	 * @return bool True when the product is unchanged and may be skipped.
	 */
	public function is_unchanged( bool $is_new, string $stored_updated_on, string $incoming_updated_on ): bool {
		if ( ! $this->change_gate_enabled || $is_new ) {
			return false;
		}
		if ( '' === $stored_updated_on || '' === $incoming_updated_on ) {
			return false;
		}
		return $stored_updated_on === $incoming_updated_on;
	}

	/**
	 * Upsert single product. Returns 'created'|'updated'|'skipped'.
	 *
	 * Lookup chain (eerste match wint):
	 * 1. SKU -> wc_get_product_id_by_sku() (snelste, WC index)
	 * 2. _skwirrel_external_id meta -> find_by_external_id() (betrouwbare API key)
	 * 3. _skwirrel_product_id meta -> find_by_skwirrel_product_id() (stabiele Skwirrel ID)
	 *
	 * @param array $product Skwirrel product data.
	 * @return string 'created'|'updated'|'skipped'
	 */
	public function upsert_product( array $product ): string {
		$key = $this->mapper->get_unique_key( $product );
		if ( ! $key ) {
			$this->logger->warning( 'Product has no unique key, skipping', [ 'product_id' => $product['product_id'] ?? '?' ] );
			return 'skipped';
		}

		$sku                 = $this->mapper->get_sku( $product );
		$skwirrel_product_id = $product['product_id'] ?? null;

		// Stap 1: Zoek op SKU (snelste via WC index)
		$wc_id = wc_get_product_id_by_sku( $sku );

		// Als SKU matcht met een variable product, sla over — dit simple product
		// mag niet het variable product overschrijven
		if ( $wc_id ) {
			$existing = wc_get_product( $wc_id );
			if ( $existing && $existing->is_type( 'variable' ) ) {
				$this->logger->verbose(
					'SKU matcht met variable product, zoek verder',
					[
						'sku'            => $sku,
						'wc_variable_id' => $wc_id,
					]
				);
				$wc_id = 0; // Niet matchen, maar SKU NIET veranderen — we zoeken verder
			}
		}

		// Stap 2: Zoek op _skwirrel_external_id meta
		if ( ! $wc_id ) {
			$wc_id = $this->lookup->find_by_external_id( $key );
		}

		// Stap 3: Zoek op _skwirrel_product_id meta (meest stabiele identifier)
		if ( ! $wc_id && null !== $skwirrel_product_id && '' !== $skwirrel_product_id && 0 !== $skwirrel_product_id ) {
			$wc_id = $this->lookup->find_by_skwirrel_product_id( (int) $skwirrel_product_id );
			if ( $wc_id ) {
				$this->logger->info(
					'Product gevonden via _skwirrel_product_id fallback',
					[
						'skwirrel_product_id' => $skwirrel_product_id,
						'wc_id'               => $wc_id,
					]
				);
			}
		}

		// Voorkom dubbele SKU — single-sourced; hergebruik-of-sla-over i.p.v. een duplicaat aanmaken (F7).
		$identity = $this->resolve_sku_identity( (int) $wc_id, $sku, $skwirrel_product_id );
		if ( $identity['skip'] ) {
			return 'skipped';
		}
		$wc_id  = $identity['wc_id'];
		$is_new = $identity['is_new'];
		$sku    = $identity['sku'];

		$this->logger->verbose(
			'Upsert product',
			[
				'product'      => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
				'sku'          => $sku,
				'key'          => $key,
				'wc_id'        => $wc_id,
				'is_new'       => $is_new,
				'lookup_chain' => $is_new ? 'geen match (nieuw)' : 'gevonden',
			]
		);

		if ( $is_new ) {
			$wc_product = new WC_Product_Simple();
		} else {
			$wc_product = wc_get_product( $wc_id );
			if ( ! $wc_product ) {
				$this->logger->warning( 'WC product not found', [ 'wc_id' => $wc_id ] );
				return 'skipped';
			}
			// Bestaand product dat variable is mag niet overschreven worden als simple
			if ( $wc_product->is_type( 'variable' ) ) {
				$this->logger->warning(
					'Bestaand product is variable, kan niet overschrijven als simple',
					[
						'wc_id'               => $wc_id,
						'sku'                 => $sku,
						'skwirrel_product_id' => $skwirrel_product_id,
					]
				);
				return 'skipped';
			}
		}

		$wc_product->set_sku( $sku );
		$wc_product->set_name( $this->mapper->get_name( $product ) );

		// Set slug for new products; optionally update existing if enabled in permalink settings.
		if ( $is_new || $this->slug_resolver->should_update_on_resync() ) {
			$exclude_id = $is_new ? null : $wc_product->get_id();
			$slug       = $this->slug_resolver->resolve( $product, $exclude_id );
			if ( null !== $slug ) {
				$wc_product->set_slug( $slug );
			}
		}

		$wc_product->set_short_description( $this->mapper->get_short_description( $product ) );
		$wc_product->set_description( $this->mapper->get_long_description( $product ) );
		// Incomplete = currently draft AND missing the gate stamp (a partial-run retry). A legacy
		// <3.11 product also lacks the stamp but is already published — never re-hold it as draft.
		$is_incomplete = ! $is_new && '' === (string) get_post_meta( $wc_id, $this->mapper->get_updated_on_meta_key(), true ) && 'draft' === $wc_product->get_status();
		$status_plan   = $this->resolve_initial_status( $is_new, $is_incomplete, $this->mapper->get_status( $product ) );
		$wc_product->set_status( $status_plan['status'] );

		$price = $this->mapper->get_regular_price( $product );
		if ( $this->mapper->is_price_on_request( $product ) ) {
			$wc_product->set_regular_price( '' );
			$wc_product->set_price( '' );
			$wc_product->set_sold_individually( false );
		} elseif ( null !== $price ) {
			$wc_product->set_regular_price( (string) $price );
			$wc_product->set_price( (string) $price );
		} elseif ( ! empty( $this->get_options()['prices_managed_outside_skwirrel'] ) ) {
			// No PIM price; prices are managed by an external system (e.g. ERP).
			// Leave price fields untouched so the external sync's values survive.
			$this->logger->verbose(
				'Product has no PIM price, preserving existing (external price sync)',
				[
					'sku'        => $sku,
					'product_id' => $product['product_id'] ?? '?',
				]
			);
		} else {
			// No price available - set to 0 and log warning.
			$this->logger->warning(
				'Product has no price, setting to 0',
				[
					'sku'             => $sku,
					'product_id'      => $product['product_id'] ?? '?',
					'has_trade_items' => ! empty( $product['_trade_items'] ?? [] ),
				]
			);
			$wc_product->set_regular_price( '0' );
			$wc_product->set_price( '0' );
		}

		$attrs = $this->mapper->get_attributes( $product );

		// Merge custom class attributes (if enabled)
		$cc_options    = $this->get_options();
		$cc_text_meta  = [];
		$cc_visibility = [];
		if ( ! empty( $cc_options['sync_custom_classes'] ) || ! empty( $cc_options['sync_trade_item_custom_classes'] ) ) {
			$cc_filter_mode = $cc_options['custom_class_filter_mode'] ?? '';
			$cc_parsed      = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_options['custom_class_filter_ids'] ?? '' );
			$include_ti     = ! empty( $cc_options['sync_trade_item_custom_classes'] );

			$cc_attrs = $this->mapper->get_custom_class_attributes(
				$product,
				$include_ti,
				$cc_filter_mode,
				$cc_parsed['ids'],
				$cc_parsed['codes']
			);
			// Merge: custom class attrs after ETIM attrs (ETIM takes precedence on name conflict)
			foreach ( $cc_attrs as $name => $value ) {
				if ( ! isset( $attrs[ $name ] ) ) {
					$attrs[ $name ] = $value;
				}
			}

			$cc_text_meta = $this->mapper->get_custom_class_text_meta(
				$product,
				$include_ti,
				$cc_filter_mode,
				$cc_parsed['ids'],
				$cc_parsed['codes']
			);

			$cc_visibility = $this->build_cc_visibility_map( $product, $cc_options );
		}

		$wc_product->save();

		$id = $wc_product->get_id();
		update_post_meta( $id, $this->mapper->get_external_id_meta_key(), $key );
		update_post_meta( $id, $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		update_post_meta( $id, $this->mapper->get_synced_at_meta_key(), time() );
		// NB: _skwirrel_updated_on (the change-gate key) is stamped only AFTER the product is fully
		// committed (all aspects + publish) — by the caller (batch loop) / end of this method
		// (single) — so a partial commit or crash never marks an incomplete product as "synced".

		// Store raw API response for debugging.
		update_post_meta( $id, '_skwirrel_api_response', wp_json_encode( $product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

		// Store searchable identifiers.
		$mpc = $product['manufacturer_product_code'] ?? '';
		if ( '' !== $mpc ) {
			update_post_meta( $id, '_manufacturer_product_code', $mpc );
		}
		$gtin = $product['product_gtin'] ?? '';
		if ( '' !== $gtin ) {
			update_post_meta( $id, '_product_gtin', $gtin );
		}

		// Track media completeness: a swallowed image-import failure (importer returns 0) or a
		// download/document save error keeps this product held as draft and unstamped, so the next
		// sync reprocesses it instead of leaving a bare product live / gated 'unchanged'.
		$media_complete = true;
		$img_ids        = $this->mapper->get_image_attachment_ids( $product, $id );
		if ( ! empty( $img_ids ) ) {
			$wc_product->set_image_id( $img_ids[0] );           // First image = featured
			$wc_product->set_gallery_image_ids( array_slice( $img_ids, 1 ) ); // All others = gallery
			$wc_product->save();
		}

		try {
			$downloads = $this->mapper->get_downloadable_files( $product, $id );
			if ( ! empty( $downloads ) ) {
				$this->ensure_uploads_approved_download_directory();
				$wc_product->set_downloadable( true );
				$wc_product->set_downloads( $this->format_downloads( $downloads ) );
				$wc_product->save();
			}
		} catch ( \Throwable $e ) {
			$media_complete = false;
			$this->logger->warning(
				'Downloadable files save failed, continuing with sync',
				[
					'wc_id' => $id,
					'error' => $e->getMessage(),
				]
			);
		}

		try {
			$documents = $this->mapper->get_document_attachments( $product, $id );
			update_post_meta( $id, '_skwirrel_document_attachments', $documents );
		} catch ( \Throwable $e ) {
			$media_complete = false;
			$this->logger->warning(
				'Document attachments save failed, continuing with sync',
				[
					'wc_id' => $id,
					'error' => $e->getMessage(),
				]
			);
		}

		// Swallowed image/file/document import failures (importer returns 0, no throw) must also
		// keep the product held as draft/unstamped, so check the combined count after all media.
		if ( $this->mapper->get_last_media_failure_count() > 0 ) {
			$media_complete = false;
		}

		// Save custom class text meta (T/B types)
		if ( ! empty( $cc_text_meta ) ) {
			foreach ( $cc_text_meta as $meta_key => $meta_value ) {
				update_post_meta( $id, $meta_key, $meta_value );
			}
			$this->logger->verbose(
				'Custom class text meta saved',
				[
					'wc_id'     => $id,
					'meta_keys' => array_keys( $cc_text_meta ),
				]
			);
		}

		$this->category_sync->assign_categories( $id, $product );
		$this->brand_sync->assign_brand( $id, $product );
		if ( ! empty( $this->get_options()['sync_manufacturers'] ) ) {
			$this->brand_sync->assign_manufacturer( $id, $product );
		}

		// Save attributes as global WooCommerce taxonomy-based attributes
		// so they appear in layered navigation and product filters.
		if ( ! empty( $attrs ) ) {
			$wc_attrs = [];
			$position = 0;
			foreach ( $attrs as $name => $value ) {
				$term_data = $this->taxonomy_manager->ensure_attribute_term( $name, (string) $value );
				if ( ! $term_data ) {
					continue;
				}
				$tax  = $term_data['taxonomy'];
				$slug = $this->taxonomy_manager->get_attribute_slug( $name );

				wp_set_object_terms( $id, [ $term_data['term_id'] ], $tax, false );

				$attr = new WC_Product_Attribute();
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
				$attr->set_name( $tax );
				$attr->set_options( [ $term_data['term_id'] ] );
				$attr->set_position( $position++ );
				$visible = $this->get_attribute_visibility( $name, $cc_visibility );
				$attr->set_visible( $visible );
				$attr->set_variation( false );
				$wc_attrs[ $tax ] = $attr;
			}
			if ( ! empty( $wc_attrs ) ) {
				$wc_product->set_attributes( $wc_attrs );
				$wc_product->save();
				clean_post_cache( $id );
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $id );
				}
			}
			$this->logger->verbose(
				'Attributes saved as global taxonomies',
				[
					'wc_id'      => $id,
					'attr_count' => count( $wc_attrs ),
					'names'      => array_keys( $attrs ),
				]
			);
		}

		// Publish a held-draft product AND stamp the change-gate timestamp ONLY when every aspect
		// (including media) succeeded. On a partial commit leave it draft and unstamped so the next
		// sync retries — never a bare product live, never gated 'unchanged' while incomplete.
		if ( $media_complete ) {
			if ( $status_plan['pending_publish'] ) {
				$wc_product->set_status( 'publish' );
				$wc_product->save();
			}
			update_post_meta( $id, $this->mapper->get_updated_on_meta_key(), (string) ( $product['product_updated_on'] ?? '' ) );
		}

		return $is_new ? 'created' : 'updated';
	}

	/**
	 * Voeg product uit getProducts toe als variation aan variable product.
	 *
	 * @param array $product    Skwirrel product data.
	 * @param array $group_info Group mapping info (wc_variable_id, etim_variation_codes, etc.).
	 * @return string 'created'|'updated'|'skipped'
	 */
	public function upsert_product_as_variation( array $product, array $group_info ): string {
		$wc_variable_id = $group_info['wc_variable_id'] ?? 0;
		$sku            = $group_info['sku'] ?? $this->mapper->get_sku( $product );
		if ( ! $wc_variable_id ) {
			return $this->upsert_product( $product );
		}

		$variation_id = $this->lookup->find_variation_by_sku( $wc_variable_id, $sku );
		if ( ! $variation_id ) {
			// Check if a simple product with this SKU exists — convert it to a variation.
			$existing_simple_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_simple_id ) {
				$existing_simple = wc_get_product( $existing_simple_id );
				if ( $existing_simple && $existing_simple->is_type( 'simple' ) ) {
					// Clear SKU on the old simple product so the variation can use it.
					$existing_simple->set_sku( '' );
					$existing_simple->save();
					wp_trash_post( $existing_simple_id );
					$this->logger->info(
						'Converted simple to variation (trashed old simple)',
						[
							'old_simple_id' => $existing_simple_id,
							'variable_id'   => $wc_variable_id,
							'sku'           => $sku,
						]
					);
				}
			}
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $wc_variable_id );
		} else {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				return 'skipped';
			}
		}

		$variation->set_sku( $sku );
		$variation->set_status( 'publish' ); // Ensure variation is enabled
		$variation->set_catalog_visibility( 'visible' ); // Make visible in catalog

		$price = $this->mapper->get_regular_price( $product );
		if ( $this->mapper->is_price_on_request( $product ) ) {
			$variation->set_regular_price( '' );
			$variation->set_price( '' );
			$variation->set_stock_status( 'outofstock' ); // Price on request = out of stock
		} elseif ( null !== $price && $price > 0 ) {
			$variation->set_regular_price( (string) $price );
			$variation->set_price( (string) $price );
			$variation->set_stock_status( 'instock' );
			$variation->set_manage_stock( false ); // Don't manage stock, always available
		} elseif ( ! empty( $this->get_options()['prices_managed_outside_skwirrel'] ) ) {
			// No PIM price; prices are managed by an external system (e.g. ERP).
			// Leave price/stock fields untouched so the external sync's values survive.
			$this->logger->verbose(
				'Variation has no PIM price, preserving existing (external price sync)',
				[
					'sku'        => $sku,
					'product_id' => $product['product_id'] ?? '?',
				]
			);
		} else {
			// No price available - set to 0 and log warning
			$this->logger->warning(
				'Variation has no price, setting to 0',
				[
					'sku'             => $sku,
					'product_id'      => $product['product_id'] ?? '?',
					'has_trade_items' => ! empty( $product['_trade_items'] ?? [] ),
				]
			);
			$variation->set_regular_price( '0' );
			$variation->set_price( '0' );
			$variation->set_stock_status( 'instock' );
			$variation->set_manage_stock( false );
		}

		$variation_attrs = [];
		$etim_codes      = $group_info['etim_variation_codes'] ?? [];
		$etim_values     = [];
		if ( ! empty( $etim_codes ) ) {
			$lang        = $this->get_include_languages();
			$lang        = ! empty( $lang ) ? $lang[0] : ( get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl' );
			$etim_values = $this->mapper->get_etim_feature_values_for_codes( $product, $etim_codes, $lang );
			$this->logger->verbose(
				'Variation eTIM lookup',
				[
					'sku'                => $sku,
					'etim_codes'         => array_column( $etim_codes, 'code' ),
					'etim_values_found'  => array_keys( $etim_values ),
					'has_product_etim'   => isset( $product['_etim'] ),
					'has_product_groups' => ! empty( $product['_product_groups'] ?? [] ),
				]
			);
			foreach ( $etim_codes as $ef ) {
				$code = strtoupper( (string) ( $ef['code'] ?? '' ) );
				$data = $etim_values[ $code ] ?? null;
				if ( ! $data ) {
					continue;
				}
				$slug  = $this->taxonomy_manager->get_etim_attribute_slug( $code );
				$tax   = wc_attribute_taxonomy_name( $slug );
				$label = ! empty( $data['label'] ) ? $data['label'] : $code;
				if ( ! taxonomy_exists( $tax ) ) {
					$this->taxonomy_manager->ensure_product_attribute_exists( $slug, $label );
				} else {
					$this->taxonomy_manager->maybe_update_attribute_label( $slug, $label );
				}
				$term_by_slug = get_term_by( 'slug', $data['slug'], $tax );
				$term         = false !== $term_by_slug ? $term_by_slug : get_term_by( 'name', $data['value'], $tax );
				if ( ! $term || is_wp_error( $term ) ) { // @phpstan-ignore function.impossibleType
					$insert = wp_insert_term( $data['value'], $tax, [ 'slug' => $data['slug'] ] );
					$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], $tax ) : null;
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$variation_attrs[ $tax ] = $term->slug;
					// Track term for deferred parent update (applied after all variations are processed)
					$this->deferred_parent_terms[ $wc_variable_id ][ $tax ][] = $term->term_id;
				}
			}
		}
		if ( empty( $variation_attrs ) ) {
			// Fallback to pa_skwirrel_variant only when parent uses pa_skwirrel_variant (no eTIM attributes)
			$parent_uses_etim = ! empty( $etim_codes );
			if ( $parent_uses_etim ) {
				$this->logger->warning(
					'Variation has no eTIM values; parent expects eTIM attributes',
					[
						'sku'            => $sku,
						'wc_variable_id' => $wc_variable_id,
						'etim_codes'     => array_column( $etim_codes, 'code' ),
					]
				);
				if ( defined( 'SKWIRREL_WC_SYNC_DEBUG_ETIM' ) && SKWIRREL_WC_SYNC_DEBUG_ETIM ) {
					$dump    = wp_upload_dir();
					$sub_dir = $dump['basedir'] . '/skwirrel-pim-sync';
					wp_mkdir_p( $sub_dir );
					$file = $sub_dir . '/skwirrel-etim-debug-' . $sku . '.json';
					if ( wp_is_writable( $sub_dir ) ) {
						file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- debug-only, writes to uploads/skwirrel-pim-sync/
							$file,
							wp_json_encode(
								[
									'sku'             => $sku,
									'product_keys'    => array_keys( $product ),
									'_etim'           => $product['_etim'] ?? null,
									'_etim_features'  => $product['_etim_features'] ?? null,
									'_product_groups' => array_map(
										function ( $g ) {
											return array_intersect_key( $g, array_flip( [ 'product_group_name', '_etim', '_etim_features' ] ) );
										},
										$product['_product_groups'] ?? []
									),
								],
								JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
							)
						);
					}
				}
			}
			$term_by_name = get_term_by( 'name', $sku, 'pa_skwirrel_variant' );
			$term         = false !== $term_by_name ? $term_by_name : get_term_by( 'slug', sanitize_title( $sku ), 'pa_skwirrel_variant' );
			if ( ! $term ) {
				$insert = wp_insert_term( $sku, 'pa_skwirrel_variant' );
				$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], 'pa_skwirrel_variant' ) : null;
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$variation_attrs['pa_skwirrel_variant'] = $term->slug;
			}
		}
		if ( defined( 'SKWIRREL_WC_SYNC_DEBUG_ETIM' ) && SKWIRREL_WC_SYNC_DEBUG_ETIM ) {
			$this->write_variation_debug( $sku, $etim_codes, $etim_values, $product, $variation_attrs );
		}

		// Set variation attributes BEFORE saving
		if ( ! empty( $variation_attrs ) ) {
			$variation->set_attributes( $variation_attrs );
		}

		$img_ids = $this->mapper->get_image_attachment_ids( $product, $wc_variable_id );
		if ( ! empty( $img_ids ) ) {
			$variation->set_image_id( $img_ids[0] );
		}

		$variation->update_meta_data( $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		$variation->update_meta_data( $this->mapper->get_external_id_meta_key(), $this->mapper->get_unique_key( $product ) ?? '' );
		$variation->update_meta_data( $this->mapper->get_synced_at_meta_key(), (string) time() );
		$variation->update_meta_data( '_skwirrel_api_response', wp_json_encode( $product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

		// Store grouped_product_id on variation for single-product sync lookup.
		$grouped_pid = $group_info['grouped_product_id'] ?? null;
		if ( null !== $grouped_pid ) {
			$variation->update_meta_data( Skwirrel_WC_Sync_Product_Lookup::GROUPED_PRODUCT_ID_META, (string) (int) $grouped_pid );
		}

		// Save variation first to get ID
		$variation->save();
		$vid = $variation->get_id();

		// Explicitly persist variation attributes in post meta
		// WooCommerce variations use ONLY post meta (not term relationships)
		if ( $vid && ! empty( $variation_attrs ) ) {
			foreach ( $variation_attrs as $tax => $term_slug ) {
				// Update post meta with the term slug
				update_post_meta( $vid, 'attribute_' . $tax, wp_slash( $term_slug ) );
			}

			// Log what we're saving
			$this->logger->verbose(
				'Variation attributes saved to meta',
				[
					'sku'        => $sku,
					'vid'        => $vid,
					'attributes' => $variation_attrs,
				]
			);

			// Verify immediately
			$verified = [];
			foreach ( $variation_attrs as $tax => $expected ) {
				$verified[ $tax ] = get_post_meta( $vid, 'attribute_' . $tax, true );
			}

			if ( $verified !== $variation_attrs ) {
				$this->logger->error(
					'Variation attribute meta verification failed',
					[
						'sku'      => $sku,
						'vid'      => $vid,
						'expected' => $variation_attrs,
						'verified' => $verified,
					]
				);
			} else {
				$this->logger->verbose(
					'Variation attribute meta verification SUCCESS',
					[
						'sku'      => $sku,
						'vid'      => $vid,
						'verified' => $verified,
					]
				);
			}

			clean_post_cache( $vid );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $vid );
			}
		}

		// Sync parent product to update available variations
		$wc_product = wc_get_product( $wc_variable_id );
		if ( $wc_product && $wc_product->is_type( 'variable' ) ) {
			try {
				WC_Product_Variable::sync( $wc_variable_id );
				WC_Product_Variable::sync_stock_status( $wc_variable_id );
				clean_post_cache( $wc_variable_id );
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $wc_variable_id );
				}
			} catch ( Throwable $e ) {
				$this->logger->warning(
					'Parent sync failed, continuing',
					[
						'wc_variable_id' => $wc_variable_id,
						'error'          => $e->getMessage(),
					]
				);
			}
		}

		// Assign brand and manufacturer from variation product to parent variable product.
		// The grouped product data from getGroupedProducts usually lacks these fields,
		// so we propagate from the first variation that has them.
		$this->brand_sync->assign_brand( $wc_variable_id, $product );
		$cc_options = $this->get_options();
		if ( ! empty( $cc_options['sync_manufacturers'] ) ) {
			$this->brand_sync->assign_manufacturer( $wc_variable_id, $product );
		}

		// Assign categories from variation product to parent variable product.
		// Same issue: getGroupedProducts lacks _categories, but individual
		// variation products from getProducts do have them.
		$this->category_sync->assign_categories( $wc_variable_id, $product );

		// Collect non-variation ETIM + custom class attributes for parent product
		$non_var_attrs = $this->mapper->get_attributes( $product );
		if ( ! empty( $cc_options['sync_custom_classes'] ) || ! empty( $cc_options['sync_trade_item_custom_classes'] ) ) {
			$cc_filter_mode = $cc_options['custom_class_filter_mode'] ?? '';
			$cc_parsed      = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_options['custom_class_filter_ids'] ?? '' );
			$include_ti     = ! empty( $cc_options['sync_trade_item_custom_classes'] );
			$cc_attrs       = $this->mapper->get_custom_class_attributes(
				$product,
				$include_ti,
				$cc_filter_mode,
				$cc_parsed['ids'],
				$cc_parsed['codes']
			);
			foreach ( $cc_attrs as $name => $value ) {
				if ( ! isset( $non_var_attrs[ $name ] ) ) {
					$non_var_attrs[ $name ] = $value;
				}
			}
		}

		// Remove attributes already used as variation axes
		$variation_tax_slugs = array_keys( $variation_attrs );
		foreach ( $variation_tax_slugs as $tax ) {
			// Strip 'pa_' prefix and match against ETIM slug patterns
			$slug = str_replace( 'pa_', '', $tax );
			foreach ( $non_var_attrs as $label => $val ) {
				if ( sanitize_title( $label ) === $slug ) {
					unset( $non_var_attrs[ $label ] );
				}
			}
		}

		// Defer non-variation attributes for parent (merged in flush_parent_attribute_terms)
		if ( ! empty( $non_var_attrs ) ) {
			foreach ( $non_var_attrs as $label => $value ) {
				$this->deferred_parent_attrs[ $wc_variable_id ][ $label ][] = (string) $value;
			}
		}

		do_action( 'skwirrel_wc_sync_after_variation_save', $variation->get_id(), $variation_attrs, $product );

		return $variation_id ? 'updated' : 'created';
	}

	/**
	 * Stap 1: Haal grouped products op, maak variable producten aan (zonder variations).
	 * Retourneert map: product_id => [grouped_product_id, order, sku, wc_variable_id].
	 *
	 * Groups are post-filtered against the dynamic selection: only groups
	 * containing at least one product in the selection are processed.
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client         JSON-RPC client instance.
	 * @param array                           $options        Plugin settings array.
	 * @param array<int>                      $collection_ids Selection IDs to filter by.
	 * @return array{created: int, updated: int, unchanged: int, map: array}
	 */
	public function sync_grouped_products_first( Skwirrel_WC_Sync_JsonRpc_Client $client, array $options, array $collection_ids = [] ): array {
		$created              = 0;
		$updated              = 0;
		$unchanged            = 0;
		$skipped              = 0;
		$product_to_group_map = [];
		$batch_size           = (int) ( $options['batch_size'] ?? 10 );
		$params               = [
			'page'                      => 1,
			'limit'                     => $batch_size,
			'include_products'          => true,
			'include_etim_features'     => true,
			'include_etim_translations' => true,
			'include_custom_features'   => true,
			'include_languages'         => $this->get_include_languages(),
		];

		// Build allowed product IDs from every configured dynamic selection.
		// `dynamic_selection_id` is a single-int filter on the API, so multiple
		// configured selections become one prefilter call per id; the resulting
		// ID maps are merged so a grouped product is kept whenever ANY of its
		// members lives in ANY of the configured selections.
		$allowed_product_ids = null;
		if ( ! empty( $collection_ids ) ) {
			$allowed_product_ids = [];
			foreach ( $collection_ids as $selection_id ) {
				$ids_for_selection    = $this->fetch_product_ids_for_selection( $client, (int) $selection_id, $batch_size );
				$allowed_product_ids += $ids_for_selection;
				$this->logger->info(
					'Fetched product IDs for selection filter',
					[
						'dynamic_selection_id' => (int) $selection_id,
						'product_count'        => count( $ids_for_selection ),
					]
				);
			}
		}

		$page = 1;
		do {
			$params['page']  = $page;
			$params['limit'] = $batch_size;
			$result          = $client->call( 'getGroupedProducts', $params );

			if ( ! $result['success'] ) {
				$this->logger->warning( 'getGroupedProducts failed', $result['error'] ?? [] );
				break;
			}

			$data         = $result['result'] ?? [];
			$groups       = $data['grouped_products'] ?? $data['groups'] ?? $data['products'] ?? [];
			$page_info    = $data['page'] ?? [];
			$current_page = (int) ( $page_info['current_page'] ?? $page );
			$total_pages  = (int) ( $page_info['number_of_pages'] ?? 1 );
			unset( $result, $data, $page_info );
			self::free_wpdb_memory();
			wp_cache_flush();
			if ( ! is_array( $groups ) ) {
				$groups = [];
			}

			foreach ( $groups as $group ) {
				// Post-filter: keep only members that are in the dynamic selection.
				if ( null !== $allowed_product_ids ) {
					$members_key = isset( $group['_products'] ) ? '_products' : 'products';
					$members     = $group[ $members_key ] ?? [];
					$filtered    = [];
					foreach ( $members as $item ) {
						$pid = is_array( $item )
							? ( $item['product_id'] ?? null )
							: (int) $item;
						if ( null !== $pid && isset( $allowed_product_ids[ (int) $pid ] ) ) {
							$filtered[] = $item;
						}
					}
					if ( empty( $filtered ) ) {
						++$skipped;
						$this->logger->verbose(
							'Grouped product skipped: no members in dynamic selection',
							[ 'grouped_product_id' => $group['grouped_product_id'] ?? $group['id'] ?? '?' ]
						);
						continue;
					}
					$group[ $members_key ] = $filtered;
				}

				try {
					$outcome = $this->create_variable_product_from_group( $group, $product_to_group_map );
					if ( 'created' === $outcome ) {
						++$created;
					} elseif ( 'updated' === $outcome ) {
						++$updated;
					} elseif ( 'unchanged' === $outcome ) {
						++$unchanged;
					}
				} catch ( Throwable $e ) {
					$this->logger->error(
						'Grouped product sync failed',
						[
							'grouped_product_id' => $group['grouped_product_id'] ?? $group['id'] ?? '?',
							'error'              => $e->getMessage(),
						]
					);
				}
			}

			if ( empty( $groups ) || $current_page >= $total_pages ) {
				break;
			}
			++$page;
		} while ( true );

		$product_ids_in_groups = array_filter( array_keys( $product_to_group_map ), 'is_int' );
		$this->logger->info(
			'Grouped products loaded',
			[
				'variable_products'     => $created + $updated + $unchanged,
				'product_ids_in_groups' => count( $product_ids_in_groups ),
				'skipped_by_selection'  => $skipped,
				'unchanged'             => $unchanged,
				'filtered_by_selection' => null !== $allowed_product_ids,
			]
		);
		return [
			'created'   => $created,
			'updated'   => $updated,
			'unchanged' => $unchanged,
			'map'       => $product_to_group_map,
		];
	}

	/**
	 * Fetch all product IDs belonging to a dynamic selection.
	 *
	 * Used to post-filter grouped products: only groups containing at least
	 * one product from the selection should be synced.
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client               API client.
	 * @param int                             $dynamic_selection_id  Selection ID.
	 * @param int                             $batch_size            Products per page.
	 * @return array<int, true> Product IDs as keys for fast isset() lookup.
	 */
	private function fetch_product_ids_for_selection( Skwirrel_WC_Sync_JsonRpc_Client $client, int $dynamic_selection_id, int $batch_size ): array {
		$ids  = [];
		$page = 1;
		do {
			$result = $client->call(
				'getProductsByFilter',
				[
					'filter'  => [ 'dynamic_selection_id' => $dynamic_selection_id ],
					'options' => [],
					'page'    => $page,
					'limit'   => $batch_size,
				]
			);
			if ( ! $result['success'] ) {
				$this->logger->warning(
					'Failed to fetch product IDs for selection filter',
					[
						'dynamic_selection_id' => $dynamic_selection_id,
						'error'                => $result['error'] ?? [],
					]
				);
				break;
			}
			$products = $result['result']['products'] ?? [];
			$count    = count( $products );
			foreach ( $products as $p ) {
				$pid = $p['product_id'] ?? $p['id'] ?? null;
				if ( null !== $pid ) {
					$ids[ (int) $pid ] = true;
				}
			}
			unset( $result, $products );
			self::free_wpdb_memory();
			if ( $count < $batch_size ) {
				break;
			}
			++$page;
		} while ( true );

		return $ids;
	}

	/**
	 * Maak variable product aan (zonder variations). Vul product_to_group_map voor later.
	 *
	 * @param array $group              Grouped product data from API.
	 * @param array &$product_to_group_map Reference to the product-to-group mapping array.
	 * @return string 'created'|'updated'|'skipped'
	 */
	public function create_variable_product_from_group( array $group, array &$product_to_group_map ): string {
		$grouped_id = $group['grouped_product_id'] ?? $group['id'] ?? null;
		if ( null === $grouped_id || '' === $grouped_id ) {
			return 'skipped';
		}

		$products       = $group['_products'] ?? $group['products'] ?? [];
		$variant_labels = [];

		foreach ( $products as $item ) {
			$product_id = null;
			$sku        = null;
			$order      = 999;
			if ( is_array( $item ) ) {
				$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : null;
				$sku        = (string) ( $item['internal_product_code'] ?? '' );
				$order      = isset( $item['order'] ) ? (int) $item['order'] : 999;
			} else {
				$product_id = (int) $item;
				$sku        = '';
			}
			if ( $product_id && '' !== $sku ) {
				$variant_labels[] = is_array( $item ) ? $this->get_variant_label( $item ) : $sku;
			}
		}

		// Groups with only 1 member should sync as simple product, not variable.
		if ( count( $products ) <= 1 ) {
			$existing_wc_id = $this->lookup->find_by_grouped_product_id( (int) $grouped_id );
			if ( $existing_wc_id ) {
				$this->logger->info(
					'Converting single-variant group to simple product: removing existing variable product',
					[
						'grouped_product_id' => $grouped_id,
						'wc_variable_id'     => $existing_wc_id,
					]
				);
				wp_delete_post( $existing_wc_id, true );
			}
			$this->logger->verbose(
				'Group has 1 member, will sync as simple product',
				[ 'grouped_product_id' => $grouped_id ]
			);
			return 'skipped';
		}

		$wc_id  = $this->lookup->find_by_grouped_product_id( (int) $grouped_id );
		$is_new = ! $wc_id;

		// Variation axis codes derive purely from the group payload and are needed to populate
		// $product_to_group_map on every run (variations and the virtual product route through it),
		// so compute them up front — before the gate that may skip the WC rebuild.
		$etim_variation_codes   = $this->extract_etim_variation_codes( $group );
		$custom_variation_codes = $this->extract_custom_variation_codes( $group );
		$virtual_product_id     = $group['virtual_product_id'] ?? null;

		// Change gate (Option A): an existing parent whose group definition is byte-identical to the
		// last fully-built one needs no rebuild. We still populate the map below, but skip the WC save,
		// taxonomy assignment and meta writes — and report 'unchanged' instead of a phantom 'updated'.
		// Compute the hash unconditionally so it is persisted even when the gate is disabled (first run
		// after install, a version bump, or any output-affecting settings change); only the early skip
		// is gated, otherwise the next run would have no stored hash and rebuild every parent once more.
		$group_hash = $this->payload_signature( $group );
		if ( $this->change_gate_enabled && ! $is_new && '' !== $group_hash
			&& (string) get_post_meta( (int) $wc_id, self::GROUP_HASH_META, true ) === $group_hash ) {
			update_post_meta( (int) $wc_id, $this->mapper->get_synced_at_meta_key(), time() );
			$this->build_group_map( $products, (int) $wc_id, (int) $grouped_id, $etim_variation_codes, $custom_variation_codes, $virtual_product_id, $product_to_group_map );
			return 'unchanged';
		}

		if ( $is_new ) {
			$wc_product = new WC_Product_Variable();
		} else {
			$wc_product = wc_get_product( $wc_id );
			if ( ! $wc_product || ! $wc_product->is_type( 'variable' ) ) {
				$wc_product = new WC_Product_Variable();
				wp_delete_post( $wc_id, true );
				$is_new = true;
			}
		}

		$name = (string) ( $group['grouped_product_name'] ?? $group['grouped_product_code'] ?? $group['name'] ?? '' );
		if ( '' === $name ) {
			/* translators: %s = grouped product ID */
			$name = sprintf( __( 'Product %s', 'skwirrel-pim-sync' ), $grouped_id );
		}

		$group_sku = (string) ( $group['grouped_product_code'] ?? $group['internal_product_code'] ?? '' );
		if ( '' !== $group_sku ) {
			$wc_product->set_sku( $group_sku );
		}
		$wc_product->set_name( $name );

		// Set slug for new variable products; optionally update existing if enabled in permalink settings.
		if ( $is_new || $this->slug_resolver->should_update_on_resync() ) {
			$exclude_id = $is_new ? null : $wc_product->get_id();
			$slug       = $this->slug_resolver->resolve_for_group( $group, $exclude_id );
			if ( null !== $slug ) {
				$wc_product->set_slug( $slug );
			}
		}

		$wc_product->set_status( ! empty( $group['product_trashed_on'] ) ? 'trash' : 'publish' );
		$wc_product->set_catalog_visibility( 'visible' );
		$wc_product->set_stock_status( 'instock' ); // Parent must be in stock
		$wc_product->set_manage_stock( false ); // Don't manage stock at parent level

		// $etim_variation_codes and $custom_variation_codes were computed before the change gate above.

		// Preserve existing parent term options. Pre-sync only registers axis structure;
		// the canonical term-list is rebuilt by flush_parent_attribute_terms() at the end of
		// Phase 3 from the actual current children. Wiping options here would leave the
		// frontend variation filter empty for the entire duration of the sync.
		$existing_attrs = $is_new ? [] : $wc_product->get_attributes();

		$attrs    = [];
		$attr_pos = 0;
		if ( ! empty( $etim_variation_codes ) ) {
			foreach ( $etim_variation_codes as $ef ) {
				$code      = $ef['code'];
				$etim_slug = $this->taxonomy_manager->get_etim_attribute_slug( $code );
				$label     = ! empty( $ef['label'] ) ? $ef['label'] : $code;
				$tax       = $this->taxonomy_manager->ensure_product_attribute_exists( $etim_slug, $label );
				$attr      = new WC_Product_Attribute();
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $etim_slug ) );
				$attr->set_name( $tax );
				$attr->set_options( self::get_existing_attr_options( $existing_attrs, $tax ) );
				$attr->set_position( $attr_pos++ );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$attrs[ $tax ] = $attr;
			}
		}
		if ( ! empty( $custom_variation_codes ) ) {
			foreach ( $custom_variation_codes as $cf ) {
				$feature_id = $cf['id'];
				$slug       = $this->taxonomy_manager->get_custom_attribute_slug( (string) $feature_id );
				$label      = ! empty( $cf['label'] ) ? $cf['label'] : (string) $feature_id;
				$tax        = $this->taxonomy_manager->ensure_product_attribute_exists( $slug, $label );
				$attr       = new WC_Product_Attribute();
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
				$attr->set_name( $tax );
				$attr->set_options( self::get_existing_attr_options( $existing_attrs, $tax ) );
				$attr->set_position( $attr_pos++ );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$attrs[ $tax ] = $attr;
			}
		}
		if ( empty( $attrs ) ) {
			$this->taxonomy_manager->ensure_variant_taxonomy_exists();
			$attr = new WC_Product_Attribute();
			$attr->set_id( wc_attribute_taxonomy_id_by_name( 'skwirrel_variant' ) );
			$attr->set_name( 'pa_skwirrel_variant' );
			$attr->set_options( array_values( array_unique( $variant_labels ) ) );
			$attr->set_position( 0 );
			$attr->set_visible( ! empty( $this->get_options()['show_variant_attribute'] ) );
			$attr->set_variation( true );
			$attrs['pa_skwirrel_variant'] = $attr;
		}
		$wc_product->set_attributes( $attrs );
		$wc_product->save();

		$id = $wc_product->get_id();
		update_post_meta( $id, Skwirrel_WC_Sync_Product_Lookup::GROUPED_PRODUCT_ID_META, (int) $grouped_id );
		update_post_meta( $id, $this->mapper->get_synced_at_meta_key(), time() );
		update_post_meta( $id, '_skwirrel_api_response', wp_json_encode( $group, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

		// Store virtual_product_id if present (this product has images for the variable product)
		if ( $virtual_product_id ) {
			update_post_meta( $id, '_skwirrel_virtual_product_id', (int) $virtual_product_id );
		}

		// Stamp the group gate hash so the next identical run can skip this rebuild. Always stamped
		// (the hash is computed unconditionally above), so a gate-disabled rebuild still leaves a
		// hash for the next run to compare against.
		if ( '' !== $group_hash ) {
			update_post_meta( $id, self::GROUP_HASH_META, $group_hash );
		}

		$this->build_group_map( $products, (int) $id, (int) $grouped_id, $etim_variation_codes, $custom_variation_codes, $virtual_product_id, $product_to_group_map );

		$this->category_sync->assign_categories( $id, $group );
		$this->brand_sync->assign_brand( $id, $group );
		if ( ! empty( $this->get_options()['sync_manufacturers'] ) ) {
			$this->brand_sync->assign_manufacturer( $id, $group );
		}

		return $is_new ? 'created' : 'updated';
	}

	/**
	 * Extract ETIM variation axes from a group payload, sorted by `order`. Pure function of the payload.
	 *
	 * @param array<string,mixed> $group Grouped-product payload.
	 * @return list<array{code: string, order: int, label: string}>
	 */
	private function extract_etim_variation_codes( array $group ): array {
		$etim_features = $group['_etim_features'] ?? [];
		$codes         = [];
		if ( is_array( $etim_features ) ) {
			$raw = isset( $etim_features[0] ) ? $etim_features : array_values( $etim_features );
			foreach ( $raw as $f ) {
				if ( is_array( $f ) && ! empty( $f['etim_feature_code'] ) ) {
					$codes[] = [
						'code'  => $f['etim_feature_code'],
						'order' => (int) ( $f['order'] ?? 999 ),
						'label' => $this->mapper->resolve_etim_feature_label( $f ),
					];
				}
			}
			usort( $codes, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		}
		return $codes;
	}

	/**
	 * Extract custom-feature variation axes from a group payload, sorted by `order`. Pure function.
	 * Schema: { custom_feature_id: int, order?: int, label?: string }.
	 *
	 * @param array<string,mixed> $group Grouped-product payload.
	 * @return list<array{id: int, order: int, label: string}>
	 */
	private function extract_custom_variation_codes( array $group ): array {
		$custom_features = $group['_custom_features'] ?? [];
		$codes           = [];
		if ( is_array( $custom_features ) ) {
			$raw = isset( $custom_features[0] ) ? $custom_features : array_values( $custom_features );
			foreach ( $raw as $f ) {
				if ( is_array( $f ) && ! empty( $f['custom_feature_id'] ) ) {
					$codes[] = [
						'id'    => (int) $f['custom_feature_id'],
						'order' => (int) ( $f['order'] ?? 999 ),
						'label' => (string) ( $f['label'] ?? '' ),
					];
				}
			}
			usort( $codes, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		}
		return $codes;
	}

	/**
	 * Populate $product_to_group_map for one group's members + virtual product. Built on EVERY run
	 * (even when the parent rebuild is gate-skipped) because the product loop routes variations and the
	 * virtual item through this map.
	 *
	 * @param array<int,mixed>                                       $products             Group member items.
	 * @param int                                                    $wc_variable_id       WC variable parent ID.
	 * @param int                                                    $grouped_id           Skwirrel grouped product ID.
	 * @param list<array{code: string, order: int, label: string}>  $etim_variation_codes ETIM axes.
	 * @param list<array{id: int, order: int, label: string}>       $custom_variation_codes Custom axes.
	 * @param int|string|null                                        $virtual_product_id   Virtual product ID, if any.
	 * @param array<int|string,mixed>                                $product_to_group_map Map to populate (by reference).
	 */
	private function build_group_map( array $products, int $wc_variable_id, int $grouped_id, array $etim_variation_codes, array $custom_variation_codes, $virtual_product_id, array &$product_to_group_map ): void {
		foreach ( $products as $item ) {
			$product_id = null;
			$sku        = null;
			$order      = 999;
			if ( is_array( $item ) ) {
				$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : null;
				$sku        = (string) ( $item['internal_product_code'] ?? '' );
				$order      = isset( $item['order'] ) ? (int) $item['order'] : 999;
			}
			if ( $product_id && '' !== $sku ) {
				$info                                      = [
					'grouped_product_id'     => $grouped_id,
					'order'                  => $order,
					'sku'                    => $sku,
					'wc_variable_id'         => $wc_variable_id,
					'etim_variation_codes'   => $etim_variation_codes,
					'custom_variation_codes' => $custom_variation_codes,
					'virtual_product_id'     => $virtual_product_id,
				];
				$product_to_group_map[ (int) $product_id ] = $info;
				$product_to_group_map[ 'sku:' . $sku ]     = $info;
			}
		}

		// If this group has a virtual product, track it for image assignment.
		if ( $virtual_product_id ) {
			$product_to_group_map[ 'virtual:' . (int) $virtual_product_id ] = [
				'wc_variable_id'          => $wc_variable_id,
				'is_virtual_for_variable' => true,
			];
		}
	}

	// =========================================================================
	// Phased sync methods
	// =========================================================================

	/**
	 * Phase 1: Create or update a simple product with basic fields only.
	 *
	 * Does NOT assign categories, brands, attributes, or media — those are
	 * handled by separate phase methods.
	 *
	 * @param array<string, mixed> $product Skwirrel product data.
	 * @return array{wc_id: int, outcome: string, pending_publish?: bool, content_hash?: string, hash_status?: 'na'|'new'|'match'|'mismatch'} WC product ID, 'created'|'updated'|'skipped'|'unchanged', whether the caller must publish it once fully committed, and the content-hash gate telemetry.
	 */
	public function create_or_update_product( array $product ): array {
		$key = $this->mapper->get_unique_key( $product );
		if ( ! $key ) {
			$this->logger->warning( 'Product has no unique key, skipping', [ 'product_id' => $product['product_id'] ?? '?' ] );
			return [
				'wc_id'   => 0,
				'outcome' => 'skipped',
			];
		}

		$sku                 = $this->mapper->get_sku( $product );
		$skwirrel_product_id = $product['product_id'] ?? null;

		// Lookup chain: SKU → external_id → product_id
		$wc_id = wc_get_product_id_by_sku( $sku );
		if ( $wc_id ) {
			$existing = wc_get_product( $wc_id );
			if ( $existing && $existing->is_type( 'variable' ) ) {
				$wc_id = 0;
			}
		}
		if ( ! $wc_id ) {
			$wc_id = $this->lookup->find_by_external_id( $key );
		}
		if ( ! $wc_id && null !== $skwirrel_product_id && '' !== $skwirrel_product_id && 0 !== $skwirrel_product_id ) {
			$wc_id = $this->lookup->find_by_skwirrel_product_id( (int) $skwirrel_product_id );
		}

		// Duplicate SKU protection — single-sourced; reuse-or-skip instead of minting duplicates (F7).
		$identity = $this->resolve_sku_identity( (int) $wc_id, $sku, $skwirrel_product_id );
		if ( $identity['skip'] ) {
			return [
				'wc_id'   => 0,
				'outcome' => 'skipped',
			];
		}
		$wc_id  = $identity['wc_id'];
		$is_new = $identity['is_new'];
		$sku    = $identity['sku'];

		if ( $is_new ) {
			$wc_product = new WC_Product_Simple();
		} else {
			$wc_product = wc_get_product( $wc_id );
			if ( ! $wc_product ) {
				return [
					'wc_id'   => 0,
					'outcome' => 'skipped',
				];
			}
			if ( $wc_product->is_type( 'variable' ) ) {
				return [
					'wc_id'   => 0,
					'outcome' => 'skipped',
				];
			}
		}

		// Change gate: an existing product whose Skwirrel `product_updated_on` has not advanced is
		// unchanged — skip the re-save/attributes/media, but still stamp synced_at so the stale-purge
		// never trashes it.
		$incoming_updated_on = (string) ( $product['product_updated_on'] ?? '' );
		$stored_updated_on   = $is_new ? '' : (string) get_post_meta( $wc_id, $this->mapper->get_updated_on_meta_key(), true );

		// Content-hash gate. Computed for off/observe/enforce; the hash is STORED only on full commit
		// (by the caller), so a partial/failed product has no stored hash and is never skipped.
		$incoming_hash = $this->content_hash( $product );
		$hash_status   = 'na';
		if ( '' !== $incoming_hash && ! $is_new ) {
			$stored_hash = (string) get_post_meta( $wc_id, self::CONTENT_HASH_META, true );
			$hash_status = '' === $stored_hash ? 'new' : ( $stored_hash === $incoming_hash ? 'match' : 'mismatch' );
		}

		if ( 'enforce' === $this->content_hash_mode ) {
			// Hash is authoritative: skip only on a real match, otherwise reprocess (supersedes the timestamp gate).
			if ( ! $is_new && 'match' === $hash_status ) {
				update_post_meta( $wc_id, $this->mapper->get_synced_at_meta_key(), time() );
				return [
					'wc_id'        => (int) $wc_id,
					'outcome'      => 'unchanged',
					'content_hash' => $incoming_hash,
					'hash_status'  => $hash_status,
				];
			}
		} elseif ( $this->is_unchanged( $is_new, $stored_updated_on, $incoming_updated_on ) ) {
			update_post_meta( $wc_id, $this->mapper->get_synced_at_meta_key(), time() );
			return [
				'wc_id'        => (int) $wc_id,
				'outcome'      => 'unchanged',
				'content_hash' => $incoming_hash,
				'hash_status'  => $hash_status,
			];
		}
		// "Incomplete" = an existing product this plugin created as draft and then failed to finish.
		// It is detectable by being *currently draft* AND missing the gate stamp. A legacy product
		// upgrading from <3.11 also lacks the stamp but is already published/complete — it must NOT be
		// re-held as draft (a transient aspect failure would otherwise take a live product offline).
		$is_incomplete = ! $is_new && '' === $stored_updated_on && 'draft' === $wc_product->get_status();

		$wc_product->set_sku( $sku );
		$wc_product->set_name( $this->mapper->get_name( $product ) );

		if ( $is_new || $this->slug_resolver->should_update_on_resync() ) {
			$exclude_id = $is_new ? null : $wc_product->get_id();
			$slug       = $this->slug_resolver->resolve( $product, $exclude_id );
			if ( null !== $slug ) {
				$wc_product->set_slug( $slug );
			}
		}

		$wc_product->set_short_description( $this->mapper->get_short_description( $product ) );
		$wc_product->set_description( $this->mapper->get_long_description( $product ) );
		$status_plan = $this->resolve_initial_status( $is_new, $is_incomplete, $this->mapper->get_status( $product ) );
		$wc_product->set_status( $status_plan['status'] );

		$price = $this->mapper->get_regular_price( $product );
		if ( $this->mapper->is_price_on_request( $product ) ) {
			$wc_product->set_regular_price( '' );
			$wc_product->set_price( '' );
			$wc_product->set_sold_individually( false );
		} elseif ( null !== $price ) {
			$wc_product->set_regular_price( (string) $price );
			$wc_product->set_price( (string) $price );
		} elseif ( ! empty( $this->get_options()['prices_managed_outside_skwirrel'] ) ) {
			// No PIM price; prices are managed by an external system (e.g. ERP).
			// Leave price fields untouched so the external sync's values survive.
			$this->logger->verbose(
				'Product has no PIM price, preserving existing (external price sync)',
				[
					'sku'        => $sku,
					'product_id' => $product['product_id'] ?? '?',
				]
			);
		} else {
			// No price available - set to 0 and log warning.
			$this->logger->warning(
				'Product has no price, setting to 0',
				[
					'sku'             => $sku,
					'product_id'      => $product['product_id'] ?? '?',
					'has_trade_items' => ! empty( $product['_trade_items'] ?? [] ),
				]
			);
			$wc_product->set_regular_price( '0' );
			$wc_product->set_price( '0' );
		}

		$wc_product->save();
		$id = $wc_product->get_id();

		update_post_meta( $id, $this->mapper->get_external_id_meta_key(), $key );
		update_post_meta( $id, $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		update_post_meta( $id, $this->mapper->get_synced_at_meta_key(), time() );
		// NB: _skwirrel_updated_on (the change-gate key) is stamped only AFTER the product is fully
		// committed (all aspects + publish) — by the caller (batch loop) / end of this method
		// (single) — so a partial commit or crash never marks an incomplete product as "synced".

		// Store raw API response for debugging.
		update_post_meta( $id, '_skwirrel_api_response', wp_json_encode( $product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

		// Store searchable identifiers.
		$mpc = $product['manufacturer_product_code'] ?? '';
		if ( '' !== $mpc ) {
			update_post_meta( $id, '_manufacturer_product_code', $mpc );
		}
		$gtin = $product['product_gtin'] ?? '';
		if ( '' !== $gtin ) {
			update_post_meta( $id, '_product_gtin', $gtin );
		}

		return [
			'wc_id'           => $id,
			'outcome'         => $is_new ? 'created' : 'updated',
			'pending_publish' => $status_plan['pending_publish'],
			'content_hash'    => $incoming_hash,
			'hash_status'     => $hash_status,
		];
	}

	/**
	 * Phase 1 for variations: Create or update with basic fields + variation attributes.
	 *
	 * Variation attributes are included because they define the variation identity.
	 *
	 * @param array<string, mixed> $product    Skwirrel product data.
	 * @param array<string, mixed> $group_info Group mapping info.
	 * @return array{wc_id: int, outcome: string, content_hash?: string, hash_status?: 'na'|'new'|'match'|'mismatch'}
	 */
	public function create_or_update_variation( array $product, array $group_info ): array {
		$wc_variable_id = $group_info['wc_variable_id'] ?? 0;
		$sku            = $group_info['sku'] ?? $this->mapper->get_sku( $product );
		if ( ! $wc_variable_id ) {
			return $this->create_or_update_product( $product );
		}

		$variation_id = $this->lookup->find_variation_by_sku( $wc_variable_id, $sku );
		if ( ! $variation_id ) {
			// Check if a simple product with this SKU exists — convert it to a variation.
			$existing_simple_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_simple_id ) {
				$existing_simple = wc_get_product( $existing_simple_id );
				if ( $existing_simple && $existing_simple->is_type( 'simple' ) ) {
					$existing_simple->set_sku( '' );
					$existing_simple->save();
					wp_trash_post( $existing_simple_id );
					$this->logger->info(
						'Converted simple to variation (trashed old simple)',
						[
							'old_simple_id' => $existing_simple_id,
							'variable_id'   => $wc_variable_id,
							'sku'           => $sku,
						]
					);
				}
			}
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $wc_variable_id );
		} else {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				return [
					'wc_id'   => 0,
					'outcome' => 'skipped',
				];
			}
		}

		// Change gate: an existing variation whose Skwirrel `product_updated_on` has not advanced is
		// unchanged — skip the re-save, but still stamp synced_at so the stale-purge never trashes it.
		$incoming_updated_on = (string) ( $product['product_updated_on'] ?? '' );
		$incoming_hash       = $this->content_hash( $product );
		$hash_status         = 'na';
		if ( $variation_id ) {
			$stored_updated_on = (string) get_post_meta( $variation_id, $this->mapper->get_updated_on_meta_key(), true );
			if ( '' !== $incoming_hash ) {
				$stored_hash = (string) get_post_meta( $variation_id, self::CONTENT_HASH_META, true );
				$hash_status = '' === $stored_hash ? 'new' : ( $stored_hash === $incoming_hash ? 'match' : 'mismatch' );
			}

			if ( 'enforce' === $this->content_hash_mode ) {
				if ( 'match' === $hash_status ) {
					update_post_meta( $variation_id, $this->mapper->get_synced_at_meta_key(), time() );
					return [
						'wc_id'        => (int) $variation_id,
						'outcome'      => 'unchanged',
						'content_hash' => $incoming_hash,
						'hash_status'  => $hash_status,
					];
				}
			} elseif ( $this->is_unchanged( false, $stored_updated_on, $incoming_updated_on ) ) {
				update_post_meta( $variation_id, $this->mapper->get_synced_at_meta_key(), time() );
				return [
					'wc_id'        => (int) $variation_id,
					'outcome'      => 'unchanged',
					'content_hash' => $incoming_hash,
					'hash_status'  => $hash_status,
				];
			}
		}

		$variation->set_sku( $sku );
		$variation->set_status( 'publish' );
		$variation->set_catalog_visibility( 'visible' );

		$price = $this->mapper->get_regular_price( $product );
		if ( $this->mapper->is_price_on_request( $product ) ) {
			$variation->set_regular_price( '' );
			$variation->set_price( '' );
			$variation->set_stock_status( 'outofstock' );
		} elseif ( null !== $price && $price > 0 ) {
			$variation->set_regular_price( (string) $price );
			$variation->set_price( (string) $price );
			$variation->set_stock_status( 'instock' );
			$variation->set_manage_stock( false );
		} elseif ( ! empty( $this->get_options()['prices_managed_outside_skwirrel'] ) ) {
			// No PIM price; prices are managed by an external system (e.g. ERP).
			// Leave price/stock fields untouched so the external sync's values survive.
			$this->logger->verbose(
				'Variation has no PIM price, preserving existing (external price sync)',
				[
					'sku'        => $sku,
					'product_id' => $product['product_id'] ?? '?',
				]
			);
		} else {
			$variation->set_regular_price( '0' );
			$variation->set_price( '0' );
			$variation->set_stock_status( 'instock' );
			$variation->set_manage_stock( false );
		}

		// Variation attributes (identity) — must be set before save
		$variation_attrs = [];
		$etim_codes      = $group_info['etim_variation_codes'] ?? [];
		$etim_values     = [];
		if ( ! empty( $etim_codes ) ) {
			$lang_arr    = $this->get_include_languages();
			$lang        = ! empty( $lang_arr ) ? $lang_arr[0] : ( get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl' );
			$etim_values = $this->mapper->get_etim_feature_values_for_codes( $product, $etim_codes, $lang );
			foreach ( $etim_codes as $ef ) {
				$code = strtoupper( (string) ( $ef['code'] ?? '' ) );
				$data = $etim_values[ $code ] ?? null;
				if ( ! $data ) {
					continue;
				}
				$slug  = $this->taxonomy_manager->get_etim_attribute_slug( $code );
				$tax   = wc_attribute_taxonomy_name( $slug );
				$label = ! empty( $data['label'] ) ? $data['label'] : $code;
				if ( ! taxonomy_exists( $tax ) ) {
					$this->taxonomy_manager->ensure_product_attribute_exists( $slug, $label );
				} else {
					$this->taxonomy_manager->maybe_update_attribute_label( $slug, $label );
				}
				$term_by_slug = get_term_by( 'slug', $data['slug'], $tax );
				$term         = false !== $term_by_slug ? $term_by_slug : get_term_by( 'name', $data['value'], $tax );
				if ( ! $term || is_wp_error( $term ) ) { // @phpstan-ignore function.impossibleType
					$insert = wp_insert_term( $data['value'], $tax, [ 'slug' => $data['slug'] ] );
					$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], $tax ) : null;
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$variation_attrs[ $tax ]                                  = $term->slug;
					$this->deferred_parent_terms[ $wc_variable_id ][ $tax ][] = $term->term_id;
				}
			}
		}

		// Custom feature variation attributes (matched by custom_feature_id).
		$custom_codes  = $group_info['custom_variation_codes'] ?? [];
		$custom_values = [];
		if ( ! empty( $custom_codes ) ) {
			$lang_arr      = $this->get_include_languages();
			$lang          = ! empty( $lang_arr ) ? $lang_arr[0] : ( get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl' );
			$custom_values = $this->mapper->get_custom_feature_values_for_ids( $product, $custom_codes, $lang );
			foreach ( $custom_codes as $cf ) {
				$feature_id = (int) ( $cf['id'] ?? 0 );
				$data       = $custom_values[ $feature_id ] ?? null;
				if ( ! $data ) {
					continue;
				}
				$slug  = $this->taxonomy_manager->get_custom_attribute_slug( (string) $feature_id );
				$tax   = wc_attribute_taxonomy_name( $slug );
				$label = ! empty( $data['label'] ) ? $data['label'] : ( ! empty( $cf['label'] ) ? $cf['label'] : (string) $feature_id );
				if ( ! taxonomy_exists( $tax ) ) {
					$this->taxonomy_manager->ensure_product_attribute_exists( $slug, $label );
				} else {
					$this->taxonomy_manager->maybe_update_attribute_label( $slug, $label );
				}
				$term_by_slug = get_term_by( 'slug', $data['slug'], $tax );
				$term         = false !== $term_by_slug ? $term_by_slug : get_term_by( 'name', $data['value'], $tax );
				if ( ! $term || is_wp_error( $term ) ) { // @phpstan-ignore function.impossibleType
					$insert = wp_insert_term( $data['value'], $tax, [ 'slug' => $data['slug'] ] );
					$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], $tax ) : null;
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$variation_attrs[ $tax ]                                  = $term->slug;
					$this->deferred_parent_terms[ $wc_variable_id ][ $tax ][] = $term->term_id;
				}
			}
		}

		if ( empty( $variation_attrs ) ) {
			$parent_uses_axes = ! empty( $etim_codes ) || ! empty( $custom_codes );
			if ( $parent_uses_axes ) {
				$this->logger->warning(
					'Variation has no variation attribute values; parent expects structured attributes',
					[
						'sku'            => $sku,
						'wc_variable_id' => $wc_variable_id,
						'etim_codes'     => array_column( $etim_codes, 'code' ),
						'custom_ids'     => array_column( $custom_codes, 'id' ),
					]
				);
			}
			$variant_label = $this->get_variant_label( $product );
			$term_by_name  = get_term_by( 'name', $variant_label, 'pa_skwirrel_variant' );
			$term          = false !== $term_by_name ? $term_by_name : get_term_by( 'slug', sanitize_title( $variant_label ), 'pa_skwirrel_variant' );
			if ( ! $term ) {
				$insert = wp_insert_term( $variant_label, 'pa_skwirrel_variant' );
				$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], 'pa_skwirrel_variant' ) : null;
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$variation_attrs['pa_skwirrel_variant'] = $term->slug;
			}
		}

		if ( ! empty( $variation_attrs ) ) {
			$variation->set_attributes( $variation_attrs );
		}

		// Generate a deterministic slug from attribute values.
		if ( ! empty( $variation_attrs ) ) {
			$is_new_variation = ! $variation->get_id();
			if ( $is_new_variation || $this->slug_resolver->should_update_on_resync() ) {
				$exclude_id     = $is_new_variation ? null : $variation->get_id();
				$variation_slug = $this->slug_resolver->resolve_for_variation( $variation_attrs, $sku, $wc_variable_id, $exclude_id );
				if ( '' !== $variation_slug ) {
					$variation->set_slug( $variation_slug );
				}
			}
		}

		$variation->update_meta_data( $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		$variation->update_meta_data( $this->mapper->get_external_id_meta_key(), $this->mapper->get_unique_key( $product ) ?? '' );
		$variation->update_meta_data( $this->mapper->get_synced_at_meta_key(), (string) time() );
		$variation->update_meta_data( '_skwirrel_api_response', wp_json_encode( $product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

		// Store grouped_product_id on variation for single-product sync lookup.
		$grouped_pid = $group_info['grouped_product_id'] ?? null;
		if ( null !== $grouped_pid ) {
			$variation->update_meta_data( Skwirrel_WC_Sync_Product_Lookup::GROUPED_PRODUCT_ID_META, (string) (int) $grouped_pid );
		}

		$variation->save();
		$vid = $variation->get_id();

		// Persist variation attributes in post meta
		if ( $vid && ! empty( $variation_attrs ) ) {
			foreach ( $variation_attrs as $tax => $term_slug ) {
				update_post_meta( $vid, 'attribute_' . $tax, wp_slash( $term_slug ) );
			}
			clean_post_cache( $vid );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $vid );
			}
		}

		// Sync parent variable product
		$wc_product = wc_get_product( $wc_variable_id );
		if ( $wc_product && $wc_product->is_type( 'variable' ) ) {
			try {
				WC_Product_Variable::sync( $wc_variable_id );
				WC_Product_Variable::sync_stock_status( $wc_variable_id );
				clean_post_cache( $wc_variable_id );
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $wc_variable_id );
				}
			} catch ( Throwable $e ) {
				$this->logger->warning( 'Parent sync failed', [ 'error' => $e->getMessage() ] );
			}
		}

		do_action( 'skwirrel_wc_sync_after_variation_save', $vid, $variation_attrs, $product );

		return [
			'wc_id'        => $vid,
			'outcome'      => $variation_id ? 'updated' : 'created',
			'content_hash' => $incoming_hash,
			'hash_status'  => $hash_status,
		];
	}

	/**
	 * Phase 2: Assign categories, brands, and manufacturers to a product.
	 *
	 * @param int   $wc_id   WooCommerce product ID.
	 * @param array $product Skwirrel product data.
	 * @return void
	 */
	public function assign_taxonomy( int $wc_id, array $product ): void {
		if ( ! $wc_id ) {
			return;
		}
		$this->category_sync->assign_categories( $wc_id, $product );
		$this->brand_sync->assign_brand( $wc_id, $product );
		$options = $this->get_options();
		if ( ! empty( $options['sync_manufacturers'] ) ) {
			$this->brand_sync->assign_manufacturer( $wc_id, $product );
		}
	}

	/**
	 * Phase 3: Assign attributes (ETIM + custom class) to a product.
	 *
	 * For variations, collects non-variation attrs for the parent (deferred).
	 *
	 * @param int        $wc_id      WooCommerce product ID.
	 * @param array      $product    Skwirrel product data.
	 * @param array|null $group_info Group mapping info (null for simple products).
	 * @return int Number of attributes assigned.
	 */
	public function assign_attributes( int $wc_id, array $product, ?array $group_info = null ): int {
		if ( ! $wc_id ) {
			return 0;
		}

		$attrs         = $this->mapper->get_attributes( $product );
		$cc_options    = $this->get_options();
		$cc_text_meta  = [];
		$cc_visibility = [];

		if ( ! empty( $cc_options['sync_custom_classes'] ) || ! empty( $cc_options['sync_trade_item_custom_classes'] ) ) {
			$cc_filter_mode = $cc_options['custom_class_filter_mode'] ?? '';
			$cc_parsed      = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_options['custom_class_filter_ids'] ?? '' );
			$include_ti     = ! empty( $cc_options['sync_trade_item_custom_classes'] );

			$cc_attrs = $this->mapper->get_custom_class_attributes(
				$product,
				$include_ti,
				$cc_filter_mode,
				$cc_parsed['ids'],
				$cc_parsed['codes']
			);
			foreach ( $cc_attrs as $name => $value ) {
				if ( ! isset( $attrs[ $name ] ) ) {
					$attrs[ $name ] = $value;
				}
			}

			$cc_text_meta = $this->mapper->get_custom_class_text_meta(
				$product,
				$include_ti,
				$cc_filter_mode,
				$cc_parsed['ids'],
				$cc_parsed['codes']
			);

			$cc_visibility = $this->build_cc_visibility_map( $product, $cc_options );
		}

		// For variations: remove variation-axis attrs, defer non-variation attrs to parent.
		// Also set custom feature variation attributes (custom class data is available from Phase 3 fetch).
		if ( $group_info ) {
			$wc_variable_id = $group_info['wc_variable_id'] ?? 0;
			$etim_codes     = $group_info['etim_variation_codes'] ?? [];
			$custom_codes   = $group_info['custom_variation_codes'] ?? [];

			// Resolve custom feature values first so we know which labels to exclude from $attrs.
			$custom_values      = [];
			$custom_axis_labels = [];
			if ( $wc_variable_id && ! empty( $custom_codes ) && ! empty( $product['_custom_classes'] ) ) {
				$lang_arr      = $this->get_include_languages();
				$lang          = ! empty( $lang_arr ) ? $lang_arr[0] : ( get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl' );
				$custom_values = $this->mapper->get_custom_feature_values_for_ids( $product, $custom_codes, $lang );
				foreach ( $custom_values as $data ) {
					if ( ! empty( $data['label'] ) ) {
						$custom_axis_labels[] = $data['label'];
					}
				}
			}

			// Remove variation-axis attributes from $attrs (they are set as variation attrs, not product attrs).
			$var_tax_slugs = [];
			foreach ( $etim_codes as $ef ) {
				$code            = strtoupper( (string) ( $ef['code'] ?? '' ) );
				$slug            = $this->taxonomy_manager->get_etim_attribute_slug( $code );
				$var_tax_slugs[] = $slug;
			}
			foreach ( $custom_codes as $cf ) {
				$feature_id      = (int) ( $cf['id'] ?? 0 );
				$slug            = $this->taxonomy_manager->get_custom_attribute_slug( (string) $feature_id );
				$var_tax_slugs[] = $slug;
			}
			foreach ( $attrs as $label => $val ) {
				// Match by taxonomy slug (ETIM) or by translated label (custom features).
				$label_slug = sanitize_title( $label );
				if ( in_array( $label_slug, $var_tax_slugs, true ) || in_array( $label, $custom_axis_labels, true ) ) {
					unset( $attrs[ $label ] );
				}
			}

			// Set custom feature variation attributes on the variation.
			foreach ( $custom_codes as $cf ) {
				$feature_id = (int) ( $cf['id'] ?? 0 );
				$data       = $custom_values[ $feature_id ] ?? null;
				if ( ! $data ) {
					continue;
				}
				$slug  = $this->taxonomy_manager->get_custom_attribute_slug( (string) $feature_id );
				$tax   = wc_attribute_taxonomy_name( $slug );
				$label = ! empty( $data['label'] ) ? $data['label'] : ( ! empty( $cf['label'] ) ? $cf['label'] : (string) $feature_id );
				if ( ! taxonomy_exists( $tax ) ) {
					$this->taxonomy_manager->ensure_product_attribute_exists( $slug, $label );
				} else {
					$this->taxonomy_manager->maybe_update_attribute_label( $slug, $label );
				}
				$term_by_slug = get_term_by( 'slug', $data['slug'], $tax );
				$term         = false !== $term_by_slug ? $term_by_slug : get_term_by( 'name', $data['value'], $tax );
				if ( ! $term || is_wp_error( $term ) ) { // @phpstan-ignore function.impossibleType
					$insert = wp_insert_term( $data['value'], $tax, [ 'slug' => $data['slug'] ] );
					$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], $tax ) : null;
				}
				if ( $term && ! is_wp_error( $term ) ) {
					update_post_meta( $wc_id, 'attribute_' . $tax, wp_slash( $term->slug ) );
					$this->deferred_parent_terms[ $wc_variable_id ][ $tax ][] = $term->term_id;
				}
			}

			if ( $wc_variable_id && ! empty( $attrs ) ) {
				foreach ( $attrs as $label => $value ) {
					$this->deferred_parent_attrs[ $wc_variable_id ][ $label ][] = (string) $value;
					if ( ! isset( $this->deferred_parent_attr_visibility[ $wc_variable_id ][ $label ] ) ) {
						$this->deferred_parent_attr_visibility[ $wc_variable_id ][ $label ] = $this->get_attribute_visibility( $label, $cc_visibility );
					}
				}
				return count( $attrs );
			}
		}

		// Save custom class text meta
		if ( ! empty( $cc_text_meta ) ) {
			foreach ( $cc_text_meta as $meta_key => $meta_value ) {
				update_post_meta( $wc_id, $meta_key, $meta_value );
			}
		}

		if ( empty( $attrs ) ) {
			return 0;
		}

		$wc_product = wc_get_product( $wc_id );
		if ( ! $wc_product ) {
			return 0;
		}

		$wc_attrs = [];
		$position = 0;
		foreach ( $attrs as $name => $value ) {
			$term_data = $this->taxonomy_manager->ensure_attribute_term( $name, (string) $value );
			if ( ! $term_data ) {
				continue;
			}
			$tax  = $term_data['taxonomy'];
			$slug = $this->taxonomy_manager->get_attribute_slug( $name );

			wp_set_object_terms( $wc_id, [ $term_data['term_id'] ], $tax, false );

			$attr = new WC_Product_Attribute();
			$attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
			$attr->set_name( $tax );
			$attr->set_options( [ $term_data['term_id'] ] );
			$attr->set_position( $position++ );
			$visible = $this->get_attribute_visibility( $name, $cc_visibility );
			$attr->set_visible( $visible );
			$attr->set_variation( false );
			$wc_attrs[ $tax ] = $attr;
		}
		if ( ! empty( $wc_attrs ) ) {
			$wc_product->set_attributes( $wc_attrs );
			$wc_product->save();
			clean_post_cache( $wc_id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $wc_id );
			}
		}

		return count( $wc_attrs );
	}

	/**
	 * Apply virtual product content (name, descriptions, images) to a variable product.
	 *
	 * @param int                $wc_variable_id   WooCommerce variable product ID.
	 * @param array<string,mixed> $virtual_product Skwirrel virtual product data.
	 * @return void
	 */
	public function apply_virtual_product_content( int $wc_variable_id, array $virtual_product ): void {
		if ( ! $wc_variable_id ) {
			return;
		}

		$wc_product = wc_get_product( $wc_variable_id );
		if ( ! $wc_product instanceof WC_Product_Variable ) {
			return;
		}

		/**
		 * Filter virtual product data before applying to the variable product.
		 *
		 * Return false to skip applying virtual product content entirely.
		 *
		 * @param array|false         $virtual_product  Full virtual product API data, or false to skip.
		 * @param int                 $wc_variable_id   WC variable product ID.
		 * @param WC_Product_Variable $wc_product       WC variable product object.
		 */
		$virtual_product = apply_filters( 'skwirrel_wc_sync_before_virtual_content', $virtual_product, $wc_variable_id, $wc_product );
		if ( false === $virtual_product ) {
			return;
		}

		$changed = false;

		// Name: only overwrite if virtual product has a name.
		$name = $this->mapper->get_name( $virtual_product );
		if ( '' !== $name ) {
			$wc_product->set_name( $name );
			$changed = true;
		}

		// Short description.
		$short_desc = $this->mapper->get_short_description( $virtual_product );
		if ( '' !== $short_desc ) {
			$wc_product->set_short_description( $short_desc );
			$changed = true;
		}

		// Long description.
		$long_desc = $this->mapper->get_long_description( $virtual_product );
		if ( '' !== $long_desc ) {
			$wc_product->set_description( $long_desc );
			$changed = true;
		}

		if ( $changed ) {
			$wc_product->save();
		}

		// Categories and brands from virtual product.
		$this->category_sync->assign_categories( $wc_variable_id, $virtual_product );
		$this->brand_sync->assign_brand( $wc_variable_id, $virtual_product );

		$options = $this->get_options();
		if ( ! empty( $options['sync_manufacturers'] ) ) {
			$this->brand_sync->assign_manufacturer( $wc_variable_id, $virtual_product );
		}

		$this->logger->info(
			'Applied virtual product content to variable product',
			[
				'wc_variable_id'     => $wc_variable_id,
				'virtual_product_id' => $virtual_product['product_id'] ?? '?',
				'name'               => '' !== $name ? $name : '(kept existing)',
			]
		);
	}

	/**
	 * Gate (Option A) + apply a virtual product's content and media to its variable parent. When the
	 * change gate is on and the virtual payload is byte-identical to the last fully-applied one, the
	 * whole apply (content + media) is skipped — mirroring how an unchanged simple product skips its
	 * media. The gate hash is stamped only on a FULLY successful apply, so a media failure never lets
	 * the next run skip an incomplete parent.
	 *
	 * @param int                 $wc_variable_id  WC variable parent ID.
	 * @param array<string,mixed> $virtual_product Skwirrel virtual product payload.
	 * @param bool                $apply_content   Whether to apply name/descriptions (use_virtual_product_content).
	 * @return string 'unchanged' (gate-skipped) | 'applied' (fully applied) | 'partial' (media failed, not stamped).
	 */
	public function sync_virtual_to_parent( int $wc_variable_id, array $virtual_product, bool $apply_content ): string {
		// Compute unconditionally so the hash is stamped even on a gate-disabled apply; only the early
		// skip is gated, else the next run finds no stored hash and re-applies every parent once more.
		$hash = $this->payload_signature( $virtual_product );
		if ( $this->change_gate_enabled && '' !== $hash
			&& (string) get_post_meta( $wc_variable_id, self::VIRTUAL_CONTENT_HASH_META, true ) === $hash ) {
			return 'unchanged';
		}

		if ( $apply_content ) {
			$this->apply_virtual_product_content( $wc_variable_id, $virtual_product );
		}

		if ( ! $this->assign_media( $wc_variable_id, $virtual_product ) ) {
			return 'partial';
		}

		if ( '' !== $hash ) {
			update_post_meta( $wc_variable_id, self::VIRTUAL_CONTENT_HASH_META, $hash );
		}
		return 'applied';
	}

	/**
	 * Assign images, downloadable files, and documents to a WC product.
	 *
	 * @param int   $wc_id   WooCommerce product ID.
	 * @param array $product Skwirrel product data.
	 * @return bool True when all requested media was applied; false when an image failed to import
	 *              or a download/document save threw (lets the caller treat the row as incomplete).
	 */
	public function assign_media( int $wc_id, array $product ): bool {
		if ( ! $wc_id ) {
			return false;
		}

		$wc_product = wc_get_product( $wc_id );
		if ( ! $wc_product ) {
			return false;
		}

		$complete = true;

		$img_ids = $this->mapper->get_image_attachment_ids( $product, $wc_id );
		if ( ! empty( $img_ids ) ) {
			$wc_product->set_image_id( $img_ids[0] );
			$wc_product->set_gallery_image_ids( array_slice( $img_ids, 1 ) );
			$wc_product->save();
		}
		try {
			$downloads = $this->mapper->get_downloadable_files( $product, $wc_id );
			if ( ! empty( $downloads ) ) {
				$this->ensure_uploads_approved_download_directory();
				$wc_product->set_downloadable( true );
				$wc_product->set_downloads( $this->format_downloads( $downloads ) );
				$wc_product->save();
			}
		} catch ( \Throwable $e ) {
			$complete = false;
			$this->logger->warning(
				'Downloadable files save failed',
				[
					'wc_id' => $wc_id,
					'error' => $e->getMessage(),
				]
			);
		}

		try {
			$documents = $this->mapper->get_document_attachments( $product, $wc_id );
			update_post_meta( $wc_id, '_skwirrel_document_attachments', $documents );
		} catch ( \Throwable $e ) {
			$complete = false;
			$this->logger->warning(
				'Document attachments save failed',
				[
					'wc_id' => $wc_id,
					'error' => $e->getMessage(),
				]
			);
		}

		// Image/file/document imports are swallowed (the importer returns 0 and logs rather than
		// throwing), so check the combined failure count explicitly — any missing media must mark the
		// product incomplete (so it isn't gate-stamped / published) instead of silently complete.
		$media_failures = $this->mapper->get_last_media_failure_count();
		if ( $media_failures > 0 ) {
			$complete = false;
			$this->logger->warning(
				'Some product media failed to import',
				[
					'wc_id'    => $wc_id,
					'failures' => $media_failures,
				]
			);
		}

		return $complete;
	}

	/**
	 * Assign related product relations (cross-sells and/or upsells) to a WC product.
	 *
	 * Resolves Skwirrel product IDs to WC IDs, filters self-references, and stores
	 * unresolved IDs in meta for retry on the next sync run.
	 *
	 * @param int                  $wc_id   WooCommerce product ID.
	 * @param array<string, mixed> $product Raw API product data.
	 * @return void
	 */
	public function assign_relations( int $wc_id, array $product ): void {
		if ( ! $wc_id ) {
			return;
		}

		$opts       = get_option( 'skwirrel_wc_sync_settings', [] );
		$type       = $opts['related_products_type'] ?? 'cross_sells';
		$sync_cross = in_array( $type, [ 'cross_sells', 'both', 'auto' ], true );
		$sync_up    = in_array( $type, [ 'upsells', 'both', 'auto' ], true );

		$relations        = $this->mapper->get_related_product_ids( $product );
		$all_skwirrel_ids = array_values(
			array_unique(
				array_merge( $relations['cross_sells'], $relations['upsells'] )
			)
		);

		// Also retry previously unresolved IDs.
		$pending = get_post_meta( $wc_id, '_skwirrel_pending_relations', true );
		if ( is_array( $pending ) && ! empty( $pending ) ) {
			$all_skwirrel_ids = array_values(
				array_unique(
					array_merge( $all_skwirrel_ids, $pending )
				)
			);
			// Merge pending IDs back into the relation buckets they belong to.
			$pending_cross = array_values( array_unique( array_merge( $relations['cross_sells'], $pending ) ) );
			$pending_up    = array_values( array_unique( array_merge( $relations['upsells'], $pending ) ) );
			if ( 'upsells' === $type ) {
				$relations['upsells'] = $pending_up;
			} elseif ( 'both' === $type ) {
				$relations['cross_sells'] = $pending_cross;
				$relations['upsells']     = $pending_up;
			} else {
				$relations['cross_sells'] = $pending_cross;
			}
		}

		// Batch resolve Skwirrel IDs → WC IDs. Empty input returns an empty
		// map; we deliberately keep going so an empty $relations bucket can
		// clear the corresponding WC list when Skwirrel removed the relation.
		$id_map = empty( $all_skwirrel_ids )
			? []
			: $this->lookup->find_wc_ids_by_skwirrel_ids( $all_skwirrel_ids );

		// For variations, set relations on the parent variable product.
		$target_id  = $wc_id;
		$wc_product = wc_get_product( $wc_id );
		if ( ! $wc_product ) {
			return;
		}
		if ( $wc_product->is_type( 'variation' ) ) {
			$parent_id = $wc_product->get_parent_id();
			if ( $parent_id ) {
				$target_id  = $parent_id;
				$wc_product = wc_get_product( $parent_id );
				if ( ! $wc_product ) {
					return;
				}
			}
		}

		// Resolve IDs and filter self-references.
		$resolve = function ( array $skwirrel_ids ) use ( $id_map, $target_id ): array {
			$wc_ids = [];
			foreach ( $skwirrel_ids as $sid ) {
				if ( isset( $id_map[ $sid ] ) ) {
					$resolved = $id_map[ $sid ];
					// Resolve to parent if the matched product is a variation.
					$matched = wc_get_product( $resolved );
					if ( $matched && $matched->is_type( 'variation' ) && $matched->get_parent_id() ) {
						$resolved = $matched->get_parent_id();
					}
					if ( $resolved !== $target_id ) {
						$wc_ids[] = $resolved;
					}
				}
			}
			return array_values( array_unique( $wc_ids ) );
		};

		$cross_sell_wc_ids = $resolve( $relations['cross_sells'] );
		$upsell_wc_ids     = $resolve( $relations['upsells'] );

		// Always write the buckets the run is configured to sync — passing []
		// clears existing relations when Skwirrel removed them at the source.
		// Buckets we're not syncing are deliberately untouched (admin may
		// have set them manually).
		if ( $sync_cross ) {
			$wc_product->set_cross_sell_ids( $cross_sell_wc_ids );
		}
		if ( $sync_up ) {
			$wc_product->set_upsell_ids( $upsell_wc_ids );
		}
		if ( $sync_cross || $sync_up ) {
			$wc_product->save();
		}

		// Track unresolved IDs for retry on next sync.
		$unresolved = array_values( array_diff( $all_skwirrel_ids, array_keys( $id_map ) ) );
		if ( ! empty( $unresolved ) ) {
			update_post_meta( $wc_id, '_skwirrel_pending_relations', $unresolved );
		} else {
			delete_post_meta( $wc_id, '_skwirrel_pending_relations' );
		}

		$this->logger->verbose(
			'Relations assigned',
			[
				'wc_id'       => $wc_id,
				'target_id'   => $target_id,
				'cross_sells' => count( $cross_sell_wc_ids ),
				'upsells'     => count( $upsell_wc_ids ),
				'unresolved'  => count( $unresolved ),
			]
		);
	}

	/**
	 * Resolve SKU-based identity + collision for a simple-product upsert.
	 *
	 * Single-sources the decision shared by create_or_update_product() and upsert_product()
	 * so the two near-identical methods can never drift. Given the wc_id resolved by the lookup
	 * chain (0 = no identity match yet) and the desired SKU, it decides:
	 *
	 *  - skip  : the SKU is owned by an existing *variable* product — this simple payload is that
	 *            product's variable/variation representation (the simple<->1-member-group
	 *            oscillation, F7). Minting a suffixed simple here is exactly what produced
	 *            duplicates like `4250366870007-14768`; skip instead and let the grouped-product
	 *            path own it.
	 *  - reuse : the SKU is owned by an existing *simple* product whose identity meta we failed to
	 *            match — reuse it (update in place) rather than minting a duplicate.
	 *  - keep  : no collision (or, on the update path, a clash against a *different* product, in
	 *            which case the SKU is suffixed to avoid a unique-SKU violation).
	 *
	 * @param int             $wc_id               WC id resolved by the lookup chain (0 if none).
	 * @param string          $sku                 Desired SKU for this product.
	 * @param int|string|null $skwirrel_product_id Skwirrel product_id (used for the update-path suffix).
	 * @return array{wc_id: int, is_new: bool, sku: string, skip: bool}
	 */
	private function resolve_sku_identity( int $wc_id, string $sku, $skwirrel_product_id ): array {
		$is_new = ! $wc_id;

		if ( $is_new ) {
			$existing_sku_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_sku_id ) {
				$existing = wc_get_product( $existing_sku_id );
				if ( $existing && $existing->is_type( 'variable' ) ) {
					// SKU owned by a variable product — do not mint a duplicate suffixed simple (F7).
					$this->logger->warning(
						'SKU owned by a variable product; skipping to avoid a duplicate simple (F7)',
						[
							'sku'                 => $sku,
							'wc_variable_id'      => (int) $existing_sku_id,
							'skwirrel_product_id' => $skwirrel_product_id,
						]
					);
					return [
						'wc_id'  => 0,
						'is_new' => true,
						'sku'    => $sku,
						'skip'   => true,
					];
				}
				// SKU owned by an existing simple product we missed on meta — reuse it, never duplicate.
				$this->logger->info(
					'Reusing existing product by SKU instead of minting a duplicate (F7)',
					[
						'sku'                 => $sku,
						'reused_wc_id'        => (int) $existing_sku_id,
						'skwirrel_product_id' => $skwirrel_product_id,
					]
				);
				return [
					'wc_id'  => (int) $existing_sku_id,
					'is_new' => false,
					'sku'    => $sku,
					'skip'   => false,
				];
			}
		} else {
			$existing_sku_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_sku_id && (int) $existing_sku_id !== (int) $wc_id ) {
				// Desired SKU taken by a *different* product — suffix to avoid a unique-SKU clash.
				$original_sku = $sku;
				$sku          = $sku . '-' . ( $skwirrel_product_id ?? uniqid() );
				$this->logger->warning(
					'SKU conflict on update; generated a unique SKU',
					[
						'original_sku'      => $original_sku,
						'new_sku'           => $sku,
						'wc_id'             => $wc_id,
						'conflicting_wc_id' => (int) $existing_sku_id,
					]
				);
			}
		}

		return [
			'wc_id'  => $wc_id,
			'is_new' => $is_new,
			'sku'    => $sku,
			'skip'   => false,
		];
	}

	/**
	 * Decide the post status to set when first writing a product, and whether the caller
	 * must flip it to its real status after the per-product commit completes.
	 *
	 * A product that is not yet proven fully committed — a brand-new one, OR an existing one left
	 * incomplete by an earlier partial run (no stored `_skwirrel_updated_on`) — whose real status is
	 * 'publish' is written as 'draft' first, so a run that dies mid-commit (e.g. during image
	 * download) never leaves a bare, *published* product on the storefront; the caller publishes it
	 * only once it is fully committed. Already-complete existing products — and new draft/trashed
	 * ones — keep their real status (we never unpublish a live product mid-resync).
	 *
	 * @param bool   $is_new        Whether the product is being created (vs updated).
	 * @param bool   $is_incomplete Existing product with no stored timestamp (a partial-run retry).
	 * @param string $final_status  The product's real target status (publish|draft|trash).
	 * @return array{status: string, pending_publish: bool}
	 */
	private function resolve_initial_status( bool $is_new, bool $is_incomplete, string $final_status ): array {
		if ( ( $is_new || $is_incomplete ) && 'publish' === $final_status ) {
			return [
				'status'          => 'draft',
				'pending_publish' => true,
			];
		}
		return [
			'status'          => $final_status,
			'pending_publish' => false,
		];
	}

	/**
	 * Write variation debug information to log file.
	 *
	 * @param string $sku             Product SKU.
	 * @param array  $etim_codes      ETIM variation codes from group.
	 * @param array  $etim_values     Resolved ETIM feature values.
	 * @param array  $product         Skwirrel product data.
	 * @param array  $variation_attrs Resolved variation attributes (taxonomy => slug).
	 * @return void
	 */
	private function write_variation_debug( string $sku, array $etim_codes, array $etim_values, array $product, array $variation_attrs ): void {
		$upload = wp_upload_dir();
		$dir    = $upload['basedir'] . '/skwirrel-pim-sync';
		wp_mkdir_p( $dir );
		if ( ! wp_is_writable( $dir ) ) {
			return;
		}
		$file = $dir . '/skwirrel-variation-debug.log';
		$line = sprintf(
			"[%s] SKU=%s | etim_codes=%s | etim_values_found=%s | has__etim=%s | variation_attrs=%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			$sku,
			wp_json_encode( array_column( $etim_codes, 'code' ) ),
			wp_json_encode( array_keys( $etim_values ) ),
			isset( $product['_etim'] ) ? 'yes' : 'no',
			wp_json_encode( $variation_attrs )
		);
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- debug-only, writes to uploads/skwirrel-pim-sync/
	}

	/**
	 * Read term-IDs already set on a parent attribute taxonomy.
	 *
	 * Returns the existing options for the given taxonomy, or `[]` if the
	 * attribute isn't present or isn't a taxonomy. Used by the variable-product
	 * shell-rebuild to preserve term-options between syncs — flush computes the
	 * canonical list from current children at the end of Phase 3.
	 *
	 * @param array<string, WC_Product_Attribute> $existing_attrs Result of WC_Product::get_attributes().
	 * @return array<int, int>
	 */
	private static function get_existing_attr_options( array $existing_attrs, string $taxonomy ): array {
		if ( ! isset( $existing_attrs[ $taxonomy ] ) ) {
			return [];
		}
		$attr = $existing_attrs[ $taxonomy ];
		if ( ! $attr->is_taxonomy() ) {
			return [];
		}
		return array_values( array_map( 'intval', $attr->get_options() ) );
	}

	/**
	 * Flush all deferred parent attribute term updates.
	 *
	 * Authoritative rebuild: for each variation taxonomy on the parent, the term-list
	 * is set to exactly the term IDs whose slugs appear in the current children's
	 * `attribute_pa_*` post meta. Stale terms (from removed variants) are dropped;
	 * new terms (from added/updated variants) are picked up. The deferred_parent_terms
	 * collected during Phase 3 are guaranteed to be a subset of this set, since
	 * variations write their own meta in the same phase — so we don't need to merge
	 * them in separately.
	 *
	 * @return void
	 */
	public function flush_parent_attribute_terms(): void {
		if ( empty( $this->deferred_parent_terms ) && empty( $this->deferred_parent_attrs ) ) {
			return;
		}

		// Collect all parent IDs that need variation attribute processing
		$all_parent_ids = array_unique(
			array_merge(
				array_keys( $this->deferred_parent_terms ),
				array_keys( $this->deferred_parent_attrs )
			)
		);

		foreach ( $all_parent_ids as $parent_id ) {
			clean_post_cache( $parent_id );
			wc_delete_product_transients( $parent_id );

			$wc_product = wc_get_product( $parent_id );
			if ( ! $wc_product || ! $wc_product->is_type( 'variable' ) ) {
				$this->logger->warning(
					'Deferred parent term flush: product not found or not variable',
					[ 'parent_id' => $parent_id ]
				);
				continue;
			}

			$attrs = $wc_product->get_attributes();
			if ( empty( $attrs ) ) {
				$this->logger->warning(
					'Deferred parent term flush: no attributes on parent',
					[ 'parent_id' => $parent_id ]
				);
				continue;
			}

			// Authoritative rebuild: for each variation taxonomy on the parent, the
			// term-list is derived from the current children's attribute_pa_* post meta.
			// Stale terms from removed variants are dropped; new terms from added or
			// updated variants are picked up. The deferred_parent_terms entries from
			// Phase 3 are a subset of this set (variations write their own meta in the
			// same phase) so we don't need a separate merge step.
			$variation_ids = $wc_product->get_children();
			$changed       = false;
			foreach ( $attrs as $taxonomy => $attr ) {
				if ( ! $attr->is_taxonomy() || ! $attr->get_variation() ) {
					continue;
				}

				$canonical_ids = [];
				foreach ( $variation_ids as $vid ) {
					$slug = get_post_meta( $vid, 'attribute_' . $taxonomy, true );
					if ( '' === $slug || null === $slug || ( is_array( $slug ) && empty( $slug ) ) ) {
						continue;
					}
					$term = get_term_by( 'slug', (string) $slug, $taxonomy );
					if ( $term && ! is_wp_error( $term ) ) { // @phpstan-ignore function.impossibleType
						$canonical_ids[ (int) $term->term_id ] = (int) $term->term_id;
					}
				}
				$canonical_ids = array_values( $canonical_ids );

				$current_options  = array_values( array_map( 'intval', $attr->get_options() ) );
				$canonical_sorted = $canonical_ids;
				$current_sorted   = $current_options;
				sort( $canonical_sorted );
				sort( $current_sorted );

				if ( $canonical_sorted === $current_sorted ) {
					continue;
				}

				$attr->set_options( $canonical_ids );
				$attrs[ $taxonomy ] = $attr;
				wp_set_object_terms( $parent_id, $canonical_ids, $taxonomy, false );
				$changed = true;

				$this->logger->verbose(
					'Parent term-list rebuilt from current children',
					[
						'parent_id'      => $parent_id,
						'taxonomy'       => $taxonomy,
						'previous_count' => count( $current_options ),
						'new_count'      => count( $canonical_ids ),
					]
				);
			}

			if ( $changed ) {
				$wc_product->set_attributes( $attrs );
				$wc_product->save();
				$this->logger->verbose(
					'Parent product saved with attribute terms',
					[
						'parent_id'       => $parent_id,
						'attribute_count' => count( $attrs ),
					]
				);
			}
		}

		// Merge non-variation attributes onto parent variable products as global taxonomies
		foreach ( $this->deferred_parent_attrs as $parent_id => $attr_map ) {
			$wc_product = wc_get_product( $parent_id );
			if ( ! $wc_product || ! $wc_product->is_type( 'variable' ) ) {
				continue;
			}

			$attrs    = $wc_product->get_attributes();
			$position = count( $attrs );

			foreach ( $attr_map as $label => $values ) {
				$unique_values = array_values( array_unique( array_filter( $values ) ) );
				if ( empty( $unique_values ) ) {
					continue;
				}

				// Register as global taxonomy attribute (same approach as simple products)
				$term_ids = [];
				$tax      = null;
				foreach ( $unique_values as $value ) {
					$term_data = $this->taxonomy_manager->ensure_attribute_term( $label, $value );
					if ( ! $term_data ) {
						continue;
					}
					$tax        = $term_data['taxonomy'];
					$term_ids[] = $term_data['term_id'];
				}
				if ( empty( $term_ids ) || null === $tax ) {
					continue;
				}

				// Skip if this attribute already exists as a variation axis
				if ( isset( $attrs[ $tax ] ) ) {
					continue;
				}

				$slug    = $this->taxonomy_manager->get_attribute_slug( $label );
				$visible = $this->deferred_parent_attr_visibility[ $parent_id ][ $label ] ?? true;
				wp_set_object_terms( $parent_id, $term_ids, $tax, false );

				$attr = new WC_Product_Attribute();
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
				$attr->set_name( $tax );
				$attr->set_options( $term_ids );
				$attr->set_position( $position++ );
				$attr->set_visible( $visible );
				$attr->set_variation( false );
				$attrs[ $tax ] = $attr;
			}

			$wc_product->set_attributes( $attrs );
			$wc_product->save();
			clean_post_cache( $parent_id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $parent_id );
			}

			$this->logger->verbose(
				'Non-variation attributes merged onto parent as global taxonomies',
				[
					'parent_id'  => $parent_id,
					'attr_count' => count( $attr_map ),
					'labels'     => array_keys( $attr_map ),
				]
			);
		}

		$parent_count                          = count( $this->deferred_parent_terms ) + count( $this->deferred_parent_attrs );
		$this->deferred_parent_terms           = [];
		$this->deferred_parent_attrs           = [];
		$this->deferred_parent_attr_visibility = [];
		$this->logger->info( 'Flushed parent attribute terms', [ 'parents_updated' => $parent_count ] );
	}

	/**
	 * Ensure the WP uploads directory is registered as an approved download directory.
	 *
	 * WooCommerce 6.5+ requires downloadable file URLs to be in an approved
	 * directory. This method auto-registers the uploads base URL so that
	 * files imported by the sync do not fail validation.
	 *
	 * Only runs once per request.
	 */
	private function ensure_uploads_approved_download_directory(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		if ( ! class_exists( '\Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register' ) ) {
			return;
		}

		try {
			$register = wc_get_container()->get( \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class );
			$uploads  = wp_get_upload_dir();
			$base_url = $uploads['baseurl'] ?? '';

			if ( '' === $base_url ) {
				return;
			}

			// Check if already approved and enabled. The Register class exposes
			// is_valid_path() (WC 6.5+); the older is_approved_directory() name
			// does not exist and throws, which would silently skip the add/enable
			// below and cause every downloadable file to fail validation.
			if ( $register->is_valid_path( $base_url . '/test.pdf' ) ) {
				return;
			}

			// Add or enable: add_approved_directory returns existing ID if already present
			// but does NOT enable it. We must enable it separately if it was disabled.
			$id = $register->add_approved_directory( $base_url );
			$register->enable_by_id( $id );
			$this->logger->info( 'Auto-approved uploads as download directory', [ 'url' => $base_url ] );
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'Failed to auto-approve uploads download directory',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Format downloadable files for WooCommerce.
	 *
	 * @param array $files Array of file data with 'name' and 'file' keys.
	 * @return array Formatted downloads array keyed by string index.
	 */
	private function format_downloads( array $files ): array {
		$downloads = [];
		foreach ( $files as $i => $f ) {
			$downloads[ (string) $i ] = [
				'name' => $f['name'],
				'file' => $f['file'],
			];
		}
		return $downloads;
	}

	/**
	 * Get plugin options with defaults.
	 *
	 * @return array Plugin settings merged with defaults.
	 */
	private function get_options(): array {
		$defaults = [
			'endpoint_url'                    => '',
			'auth_type'                       => 'bearer',
			'auth_token'                      => '',
			'timeout'                         => 30,
			'retries'                         => 2,
			'batch_size'                      => 100,
			'sync_categories'                 => true,
			'sync_grouped_products'           => false,
			'sync_images'                     => true,
			'image_language'                  => 'nl',
			'include_languages'               => [ 'nl-NL', 'nl' ],
			'verbose_logging'                 => false,
			'prices_managed_outside_skwirrel' => false,
		];
		$saved    = get_option( 'skwirrel_wc_sync_settings', [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Get the variant label for a product based on the variant_label_field setting.
	 *
	 * Used when no ETIM variation axes are available, to determine the label
	 * shown in the variant dropdown instead of the raw SKU.
	 *
	 * @param array $product Skwirrel product data (full product or group item).
	 * @return string Variant label (falls back to internal_product_code).
	 */
	private function get_variant_label( array $product ): string {
		$options = $this->get_options();
		$field   = $options['variant_label_field'] ?? 'internal_product_code';

		$label = '';
		switch ( $field ) {
			case 'product_erp_description':
				$label = (string) ( $product['product_erp_description'] ?? '' );
				break;
			case 'product_name':
				$label = $this->mapper->get_name( $product );
				break;
		}

		return '' !== $label ? $label : (string) ( $product['internal_product_code'] ?? '' );
	}

	/**
	 * Get the visibility for a custom class attribute label.
	 *
	 * @param string              $label          Attribute label.
	 * @param array<string, bool> $visibility_map Visibility map (label => visible).
	 * @return bool Whether the attribute should be visible on the product page.
	 */
	private function get_cc_attribute_visibility( string $label, array $visibility_map ): bool {
		if ( empty( $visibility_map ) ) {
			return true;
		}
		return $visibility_map[ $label ] ?? true;
	}

	/**
	 * Determine attribute visibility, including GTIN setting override.
	 *
	 * @param string              $label          Attribute label.
	 * @param array<string, bool> $visibility_map Custom class visibility map.
	 * @return bool Whether the attribute should be visible on the product page.
	 */
	private function get_attribute_visibility( string $label, array $visibility_map ): bool {
		if ( 'GTIN' === $label ) {
			return ! empty( $this->get_options()['show_gtin_attribute'] );
		}
		return $this->get_cc_attribute_visibility( $label, $visibility_map );
	}

	/**
	 * Build the custom class attribute visibility map for a product.
	 *
	 * @param array $product    Skwirrel product data.
	 * @param array $cc_options Plugin settings.
	 * @return array<string, bool> label => visible
	 */
	private function build_cc_visibility_map( array $product, array $cc_options ): array {
		$vis_mode = $cc_options['custom_class_visibility_mode'] ?? '';
		if ( '' === $vis_mode ) {
			return [];
		}

		$cc_filter_mode = $cc_options['custom_class_filter_mode'] ?? '';
		$cc_parsed      = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_options['custom_class_filter_ids'] ?? '' );
		$vis_parsed     = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_options['custom_class_visibility_ids'] ?? '' );
		$include_ti     = ! empty( $cc_options['sync_trade_item_custom_classes'] );

		return $this->mapper->get_custom_class_attribute_visibility(
			$product,
			$include_ti,
			$cc_filter_mode,
			$cc_parsed['ids'],
			$cc_parsed['codes'],
			$vis_mode,
			$vis_parsed['ids'],
			$vis_parsed['codes']
		);
	}

	/**
	 * Get include_languages from settings. Returns array of language codes for API calls.
	 *
	 * @return array<string> Language codes (e.g. ['nl-NL', 'nl']).
	 */
	private function get_include_languages(): array {
		$opts  = get_option( 'skwirrel_wc_sync_settings', [] );
		$langs = $opts['include_languages'] ?? [ 'nl-NL', 'nl' ];
		if ( ! empty( $langs ) && is_array( $langs ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $langs ) ) );
		}
		return [ 'nl-NL', 'nl' ];
	}

	/**
	 * Free accumulated wpdb memory between operations.
	 */
	private static function free_wpdb_memory(): void {
		global $wpdb;
		$wpdb->queries = [];
		$wpdb->flush();
	}
}
