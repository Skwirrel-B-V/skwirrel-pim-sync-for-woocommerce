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
		if ( ! $wc_id && $skwirrel_product_id !== null && $skwirrel_product_id !== '' && $skwirrel_product_id !== 0 ) {
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

		// Voorkom dubbele SKU bij nieuw product: als een ander product al deze SKU heeft,
		// genereer een unieke SKU met suffix
		$is_new = ! $wc_id;
		if ( $is_new ) {
			$existing_sku_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_sku_id ) {
				$original_sku = $sku;
				$sku          = $sku . '-' . ( $skwirrel_product_id ?? uniqid() );
				$this->logger->warning(
					'Dubbele SKU voorkomen bij nieuw product',
					[
						'original_sku'        => $original_sku,
						'new_sku'             => $sku,
						'existing_wc_id'      => $existing_sku_id,
						'skwirrel_product_id' => $skwirrel_product_id,
					]
				);
			}
		} else {
			// Bestaand product: controleer of SKU is veranderd en geen conflict veroorzaakt
			$existing_sku_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_sku_id && (int) $existing_sku_id !== (int) $wc_id ) {
				$original_sku = $sku;
				$sku          = $sku . '-' . ( $skwirrel_product_id ?? uniqid() );
				$this->logger->warning(
					'SKU conflict bij update, unieke SKU gegenereerd',
					[
						'original_sku'      => $original_sku,
						'new_sku'           => $sku,
						'wc_id'             => $wc_id,
						'conflicting_wc_id' => $existing_sku_id,
					]
				);
			}
		}

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
			if ( $slug !== null ) {
				$wc_product->set_slug( $slug );
			}
		}

		$wc_product->set_short_description( $this->mapper->get_short_description( $product ) );
		$wc_product->set_description( $this->mapper->get_long_description( $product ) );
		$wc_product->set_status( $this->mapper->get_status( $product ) );

		$price = $this->mapper->get_regular_price( $product );
		if ( $this->mapper->is_price_on_request( $product ) ) {
			$wc_product->set_regular_price( '' );
			$wc_product->set_price( '' );
			$wc_product->set_sold_individually( false );
		} elseif ( $price !== null ) {
			$wc_product->set_regular_price( (string) $price );
			$wc_product->set_price( (string) $price );
		}

		$attrs = $this->mapper->get_attributes( $product );

		// Merge custom class attributes (if enabled)
		$cc_options   = $this->get_options();
		$cc_text_meta = [];
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
		}

		$wc_product->save();

		$id = $wc_product->get_id();
		update_post_meta( $id, $this->mapper->get_external_id_meta_key(), $key );
		update_post_meta( $id, $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		update_post_meta( $id, $this->mapper->get_synced_at_meta_key(), time() );

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

		$img_ids = $this->mapper->get_image_attachment_ids( $product, $id );
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
			$this->logger->warning(
				'Document attachments save failed, continuing with sync',
				[
					'wc_id' => $id,
					'error' => $e->getMessage(),
				]
			);
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

		$this->category_sync->assign_categories( $id, $product, $this->mapper );
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
				$attr->set_visible( true );
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
					$this->logger->info( 'Converted simple to variation (trashed old simple)', [
						'old_simple_id' => $existing_simple_id,
						'variable_id'   => $wc_variable_id,
						'sku'           => $sku,
					] );
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
		} elseif ( $price !== null && $price > 0 ) {
			$variation->set_regular_price( (string) $price );
			$variation->set_price( (string) $price );
			$variation->set_stock_status( 'instock' );
			$variation->set_manage_stock( false ); // Don't manage stock, always available
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
				$term = get_term_by( 'slug', $data['slug'], $tax ) ?: get_term_by( 'name', $data['value'], $tax );
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
			$term = get_term_by( 'name', $sku, 'pa_skwirrel_variant' ) ?: get_term_by( 'slug', sanitize_title( $sku ), 'pa_skwirrel_variant' );
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
		$this->category_sync->assign_categories( $wc_variable_id, $product, $this->mapper );

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
	 * @return array{created: int, updated: int, map: array}
	 */
	public function sync_grouped_products_first( Skwirrel_WC_Sync_JsonRpc_Client $client, array $options, array $collection_ids = [] ): array {
		$created              = 0;
		$updated              = 0;
		$skipped              = 0;
		$product_to_group_map = [];
		$batch_size           = (int) ( $options['batch_size'] ?? 10 );
		$params               = [
			'page'                      => 1,
			'limit'                     => $batch_size,
			'include_products'          => true,
			'include_etim_features'     => true,
			'include_etim_translations' => true,
			'include_languages'         => $this->get_include_languages(),
		];

		// Build allowed product IDs from the dynamic selection (post-filter).
		$allowed_product_ids = null;
		if ( ! empty( $collection_ids ) ) {
			$allowed_product_ids = $this->fetch_product_ids_for_selection( $client, $collection_ids[0], $batch_size );
			$this->logger->info(
				'Fetched product IDs for selection filter',
				[
					'dynamic_selection_id' => $collection_ids[0],
					'product_count'        => count( $allowed_product_ids ),
				]
			);
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

			$data   = $result['result'] ?? [];
			$groups = $data['grouped_products'] ?? $data['groups'] ?? $data['products'] ?? [];
			if ( ! is_array( $groups ) ) {
				$groups = [];
			}

			$page_info    = $data['page'] ?? [];
			$current_page = (int) ( $page_info['current_page'] ?? $page );
			$total_pages  = (int) ( $page_info['number_of_pages'] ?? 1 );

			foreach ( $groups as $group ) {
				// Post-filter: skip groups with no members in the selection.
				if ( null !== $allowed_product_ids ) {
					$members   = $group['_products'] ?? $group['products'] ?? [];
					$has_match = false;
					foreach ( $members as $item ) {
						$pid = is_array( $item )
							? ( $item['product_id'] ?? null )
							: (int) $item;
						if ( null !== $pid && isset( $allowed_product_ids[ (int) $pid ] ) ) {
							$has_match = true;
							break;
						}
					}
					if ( ! $has_match ) {
						++$skipped;
						$this->logger->verbose(
							'Grouped product skipped: no members in dynamic selection',
							[ 'grouped_product_id' => $group['grouped_product_id'] ?? $group['id'] ?? '?' ]
						);
						continue;
					}
				}

				try {
					$outcome = $this->create_variable_product_from_group( $group, $product_to_group_map );
					if ( 'created' === $outcome ) {
						++$created;
					} elseif ( 'updated' === $outcome ) {
						++$updated;
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
				'variable_products'     => $created + $updated,
				'product_ids_in_groups' => count( $product_ids_in_groups ),
				'skipped_by_selection'  => $skipped,
				'filtered_by_selection' => null !== $allowed_product_ids,
			]
		);
		return [
			'created' => $created,
			'updated' => $updated,
			'map'     => $product_to_group_map,
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
			foreach ( $products as $p ) {
				$pid = $p['product_id'] ?? $p['id'] ?? null;
				if ( null !== $pid ) {
					$ids[ (int) $pid ] = true;
				}
			}
			if ( count( $products ) < $batch_size ) {
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
		if ( $grouped_id === null || $grouped_id === '' ) {
			return 'skipped';
		}

		$products     = $group['_products'] ?? $group['products'] ?? [];
		$variant_skus = [];

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
			if ( $product_id && $sku !== '' ) {
				$variant_skus[] = $sku;
			}
		}

		$wc_id  = $this->lookup->find_by_grouped_product_id( (int) $grouped_id );
		$is_new = ! $wc_id;

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
		if ( $name === '' ) {
			/* translators: %s = grouped product ID */
			$name = sprintf( __( 'Product %s', 'skwirrel-pim-sync' ), $grouped_id );
		}

		$group_sku = (string) ( $group['grouped_product_code'] ?? $group['internal_product_code'] ?? '' );
		if ( $group_sku !== '' ) {
			$wc_product->set_sku( $group_sku );
		}
		$wc_product->set_name( $name );

		// Set slug for new variable products; optionally update existing if enabled in permalink settings.
		if ( $is_new || $this->slug_resolver->should_update_on_resync() ) {
			$exclude_id = $is_new ? null : $wc_product->get_id();
			$slug       = $this->slug_resolver->resolve_for_group( $group, $exclude_id );
			if ( $slug !== null ) {
				$wc_product->set_slug( $slug );
			}
		}

		$wc_product->set_status( ! empty( $group['product_trashed_on'] ) ? 'trash' : 'publish' );
		$wc_product->set_catalog_visibility( 'visible' );
		$wc_product->set_stock_status( 'instock' ); // Parent must be in stock
		$wc_product->set_manage_stock( false ); // Don't manage stock at parent level

		$etim_features        = $group['_etim_features'] ?? [];
		$etim_variation_codes = [];
		if ( is_array( $etim_features ) ) {
			$raw = isset( $etim_features[0] ) ? $etim_features : array_values( $etim_features );
			foreach ( $raw as $f ) {
				if ( is_array( $f ) && ! empty( $f['etim_feature_code'] ) ) {
					$etim_variation_codes[] = [
						'code'  => $f['etim_feature_code'],
						'order' => (int) ( $f['order'] ?? 999 ),
						'label' => $this->mapper->resolve_etim_feature_label( $f ),
					];
				}
			}
			usort( $etim_variation_codes, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		}

		$attrs = [];
		if ( ! empty( $etim_variation_codes ) ) {
			foreach ( $etim_variation_codes as $pos => $ef ) {
				$code      = $ef['code'];
				$etim_slug = $this->taxonomy_manager->get_etim_attribute_slug( $code );
				$label     = ! empty( $ef['label'] ) ? $ef['label'] : $code;
				$tax       = $this->taxonomy_manager->ensure_product_attribute_exists( $etim_slug, $label );
				$attr      = new WC_Product_Attribute();
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $etim_slug ) );
				$attr->set_name( $tax );
				$attr->set_options( [] );
				$attr->set_position( $pos );
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
			$attr->set_options( array_values( array_unique( $variant_skus ) ) );
			$attr->set_position( 0 );
			$attr->set_visible( true );
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
		$virtual_product_id = $group['virtual_product_id'] ?? null;
		if ( $virtual_product_id ) {
			update_post_meta( $id, '_skwirrel_virtual_product_id', (int) $virtual_product_id );
		}

		foreach ( $products as $item ) {
			$product_id = null;
			$sku        = null;
			$order      = 999;
			if ( is_array( $item ) ) {
				$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : null;
				$sku        = (string) ( $item['internal_product_code'] ?? '' );
				$order      = isset( $item['order'] ) ? (int) $item['order'] : 999;
			}
			if ( $product_id && $sku !== '' ) {
				$info                                      = [
					'grouped_product_id'   => (int) $grouped_id,
					'order'                => $order,
					'sku'                  => $sku,
					'wc_variable_id'       => $id,
					'etim_variation_codes' => $etim_variation_codes,
					'virtual_product_id'   => $virtual_product_id, // Include virtual product ID in map
				];
				$product_to_group_map[ (int) $product_id ] = $info;
				$product_to_group_map[ 'sku:' . $sku ]     = $info;
			}
		}

		// If this group has a virtual product, track it for image assignment
		if ( $virtual_product_id ) {
			$product_to_group_map[ 'virtual:' . (int) $virtual_product_id ] = [
				'wc_variable_id'          => $id,
				'is_virtual_for_variable' => true,
			];
		}

		$this->category_sync->assign_categories( $id, $group, $this->mapper );
		$this->brand_sync->assign_brand( $id, $group );
		if ( ! empty( $this->get_options()['sync_manufacturers'] ) ) {
			$this->brand_sync->assign_manufacturer( $id, $group );
		}

		return $is_new ? 'created' : 'updated';
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
	 * @param array $product Skwirrel product data.
	 * @return array{wc_id: int, outcome: string} WC product ID and 'created'|'updated'|'skipped'.
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

		// Duplicate SKU protection
		$is_new = ! $wc_id;
		if ( $is_new ) {
			$existing_sku_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_sku_id ) {
				$sku = $sku . '-' . ( $skwirrel_product_id ?? uniqid() );
			}
		} else {
			$existing_sku_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_sku_id && (int) $existing_sku_id !== (int) $wc_id ) {
				$sku = $sku . '-' . ( $skwirrel_product_id ?? uniqid() );
			}
		}

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
		$wc_product->set_status( $this->mapper->get_status( $product ) );

		$price = $this->mapper->get_regular_price( $product );
		if ( $this->mapper->is_price_on_request( $product ) ) {
			$wc_product->set_regular_price( '' );
			$wc_product->set_price( '' );
			$wc_product->set_sold_individually( false );
		} elseif ( null !== $price ) {
			$wc_product->set_regular_price( (string) $price );
			$wc_product->set_price( (string) $price );
		}

		$wc_product->save();
		$id = $wc_product->get_id();

		update_post_meta( $id, $this->mapper->get_external_id_meta_key(), $key );
		update_post_meta( $id, $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		update_post_meta( $id, $this->mapper->get_synced_at_meta_key(), time() );

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
			'wc_id'   => $id,
			'outcome' => $is_new ? 'created' : 'updated',
		];
	}

	/**
	 * Phase 1 for variations: Create or update with basic fields + variation attributes.
	 *
	 * Variation attributes are included because they define the variation identity.
	 *
	 * @param array $product    Skwirrel product data.
	 * @param array $group_info Group mapping info.
	 * @return array{wc_id: int, outcome: string}
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
					$this->logger->info( 'Converted simple to variation (trashed old simple)', [
						'old_simple_id' => $existing_simple_id,
						'variable_id'   => $wc_variable_id,
						'sku'           => $sku,
					] );
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
				$term = get_term_by( 'slug', $data['slug'], $tax ) ?: get_term_by( 'name', $data['value'], $tax );
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
			}
			$term = get_term_by( 'name', $sku, 'pa_skwirrel_variant' ) ?: get_term_by( 'slug', sanitize_title( $sku ), 'pa_skwirrel_variant' );
			if ( ! $term ) {
				$insert = wp_insert_term( $sku, 'pa_skwirrel_variant' );
				$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], 'pa_skwirrel_variant' ) : null;
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$variation_attrs['pa_skwirrel_variant'] = $term->slug;
			}
		}

		if ( ! empty( $variation_attrs ) ) {
			$variation->set_attributes( $variation_attrs );
		}

		$variation->update_meta_data( $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		$variation->update_meta_data( $this->mapper->get_external_id_meta_key(), $this->mapper->get_unique_key( $product ) ?? '' );
		$variation->update_meta_data( $this->mapper->get_synced_at_meta_key(), (string) time() );
		$variation->update_meta_data( '_skwirrel_api_response', wp_json_encode( $product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

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
			'wc_id'   => $vid,
			'outcome' => $variation_id ? 'updated' : 'created',
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
		$this->category_sync->assign_categories( $wc_id, $product, $this->mapper );
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

		$attrs        = $this->mapper->get_attributes( $product );
		$cc_options   = $this->get_options();
		$cc_text_meta = [];

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
		}

		// For variations: remove variation-axis attrs, defer non-variation attrs to parent
		if ( $group_info ) {
			$wc_variable_id = $group_info['wc_variable_id'] ?? 0;
			$etim_codes     = $group_info['etim_variation_codes'] ?? [];
			$var_tax_slugs  = [];
			foreach ( $etim_codes as $ef ) {
				$code            = strtoupper( (string) ( $ef['code'] ?? '' ) );
				$slug            = $this->taxonomy_manager->get_etim_attribute_slug( $code );
				$var_tax_slugs[] = $slug;
			}
			foreach ( $var_tax_slugs as $slug ) {
				foreach ( $attrs as $label => $val ) {
					if ( sanitize_title( $label ) === $slug ) {
						unset( $attrs[ $label ] );
					}
				}
			}
			if ( $wc_variable_id && ! empty( $attrs ) ) {
				foreach ( $attrs as $label => $value ) {
					$this->deferred_parent_attrs[ $wc_variable_id ][ $label ][] = (string) $value;
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
			$attr->set_visible( true );
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
	 * Phase 4: Download and assign images + documents to a product.
	 *
	 * @param int   $wc_id   WooCommerce product ID.
	 * @param array $product Skwirrel product data.
	 * @return void
	 */
	public function assign_media( int $wc_id, array $product ): void {
		if ( ! $wc_id ) {
			return;
		}

		$wc_product = wc_get_product( $wc_id );
		if ( ! $wc_product ) {
			return;
		}

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
			$this->logger->warning(
				'Document attachments save failed',
				[
					'wc_id' => $wc_id,
					'error' => $e->getMessage(),
				]
			);
		}
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
	 * Flush all deferred parent attribute term updates.
	 *
	 * Called once after all variations have been processed, to update each parent
	 * variable product's attribute options and term relationships in a single pass.
	 * This avoids WC object cache staleness from rapid incremental updates.
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

			// Step 1: Apply deferred terms collected during variation processing
			$deferred = $this->deferred_parent_terms[ $parent_id ] ?? [];
			$changed  = false;
			foreach ( $deferred as $taxonomy => $term_ids ) {
				if ( ! isset( $attrs[ $taxonomy ] ) || ! $attrs[ $taxonomy ]->is_taxonomy() ) {
					continue;
				}
				$attr     = $attrs[ $taxonomy ];
				$existing = is_array( $attr->get_options() ) ? array_map( 'intval', $attr->get_options() ) : [];
				$merged   = array_unique( array_merge( $existing, array_map( 'intval', $term_ids ) ) );
				$attr->set_options( $merged );
				$attrs[ $taxonomy ] = $attr;
				wp_set_object_terms( $parent_id, $merged, $taxonomy, false );
				$changed = true;
			}

			// Step 2: Safety net — recover terms from child variation post meta.
			// If deferred_parent_terms was empty (e.g. getProducts didn't return
			// _etim_features), we read the actual attribute_pa_* meta from each
			// variation and populate the parent's attribute options from those.
			$variation_ids = $wc_product->get_children();
			foreach ( $attrs as $taxonomy => $attr ) {
				if ( ! $attr->is_taxonomy() || ! $attr->get_variation() ) {
					continue;
				}
				$current_options = is_array( $attr->get_options() ) ? array_map( 'intval', $attr->get_options() ) : [];
				$recovered_ids   = [];
				foreach ( $variation_ids as $vid ) {
					$slug = get_post_meta( $vid, 'attribute_' . $taxonomy, true );
					if ( empty( $slug ) ) {
						continue;
					}
					$term = get_term_by( 'slug', $slug, $taxonomy );
					if ( $term && ! is_wp_error( $term ) && ! in_array( $term->term_id, $current_options, true ) ) { // @phpstan-ignore function.impossibleType
						$recovered_ids[] = $term->term_id;
					}
				}
				if ( ! empty( $recovered_ids ) ) {
					$merged = array_unique( array_merge( $current_options, $recovered_ids ) );
					$attr->set_options( $merged );
					$attrs[ $taxonomy ] = $attr;
					wp_set_object_terms( $parent_id, $merged, $taxonomy, false );
					$changed = true;
					$this->logger->info(
						'Recovered variation terms from child meta',
						[
							'parent_id'     => $parent_id,
							'taxonomy'      => $taxonomy,
							'recovered_ids' => $recovered_ids,
						]
					);
				}
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

				$slug = $this->taxonomy_manager->get_attribute_slug( $label );
				wp_set_object_terms( $parent_id, $term_ids, $tax, false );

				$attr = new WC_Product_Attribute();
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
				$attr->set_name( $tax );
				$attr->set_options( $term_ids );
				$attr->set_position( $position++ );
				$attr->set_visible( true );
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

		$parent_count                = count( $this->deferred_parent_terms ) + count( $this->deferred_parent_attrs );
		$this->deferred_parent_terms = [];
		$this->deferred_parent_attrs = [];
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

			// Check if already approved and enabled.
			if ( $register->is_approved_directory( $base_url . '/test.pdf' ) ) {
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
			'endpoint_url'          => '',
			'auth_type'             => 'bearer',
			'auth_token'            => '',
			'timeout'               => 30,
			'retries'               => 2,
			'batch_size'            => 100,
			'sync_categories'       => true,
			'sync_grouped_products' => false,
			'sync_images'           => true,
			'image_language'        => 'nl',
			'include_languages'     => [ 'nl-NL', 'nl' ],
			'verbose_logging'       => false,
		];
		$saved    = get_option( 'skwirrel_wc_sync_settings', [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
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
}
