<?php
/**
 * Skwirrel Category Sync.
 *
 * Handles WooCommerce product_cat taxonomy operations:
 * - Full category tree sync from Skwirrel API (getCategories)
 * - Per-product category assignment
 * - Category term find/create with parent hierarchy
 * - Tracks seen category IDs for stale-category purge detection
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Category_Sync {

	private Skwirrel_WC_Sync_Logger $logger;

	/** @var string[] Skwirrel category IDs seen during current sync run. */
	private array $seen_category_ids = [];

	/**
	 * @param Skwirrel_WC_Sync_Logger $logger Logger instance.
	 */
	public function __construct( Skwirrel_WC_Sync_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Get the Skwirrel category IDs encountered during this sync run.
	 *
	 * Used by the purge handler to detect stale categories.
	 *
	 * @return string[]
	 */
	public function get_seen_category_ids(): array {
		return $this->seen_category_ids;
	}

	/**
	 * Reset seen category IDs at the start of a new sync run.
	 */
	public function reset_seen_category_ids(): void {
		$this->seen_category_ids = [];
	}

	/**
	 * Sync the full category tree from a Skwirrel super category via getCategories API.
	 *
	 * Creates/updates WooCommerce product_cat terms for the entire tree.
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client  API client.
	 * @param array                           $options Plugin settings.
	 * @param array                           $languages Include languages for API call.
	 */
	public function sync_category_tree( Skwirrel_WC_Sync_JsonRpc_Client $client, array $options, array $languages ): void {
		$super_id = (int) ( $options['super_category_id'] ?? 0 );
		if ( $super_id <= 0 ) {
			return;
		}

		$this->logger->info( 'Syncing category tree', [ 'super_category_id' => $super_id ] );

		$lang = $options['image_language'] ?? 'nl';

		// Recursively fetch the full category tree from the API.
		// The API may only return direct children per call, so we fetch
		// each level and recurse into sub-categories.
		$flat = [];
		$this->fetch_categories_recursive( $client, $super_id, $languages, $lang, $flat );

		if ( empty( $flat ) ) {
			$this->logger->warning( 'No categories found in tree', [ 'super_category_id' => $super_id ] );
			return;
		}

		$this->logger->info( 'Category tree fetched (all levels)', [ 'total_categories' => count( $flat ) ] );

		$tax         = 'product_cat';
		$cat_id_meta = Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META;

		// Build lookup by Skwirrel category ID.
		$by_id = [];
		foreach ( $flat as $cat ) {
			if ( null !== $cat['id'] ) {
				$by_id[ $cat['id'] ] = $cat;
			}
		}

		// Resolve in order: parents before children.
		$resolved      = []; // skwirrel_id => wc_term_id
		$created_count = 0;

		foreach ( $flat as $cat ) {
			$cat_id = $cat['id'] ?? null;
			if ( $cat_id !== null && isset( $resolved[ $cat_id ] ) ) {
				continue;
			}

			$parent_id = $cat['parent_id'] ?? null;
			$wc_parent = 0;

			// Resolve parent first
			if ( $parent_id !== null && isset( $resolved[ $parent_id ] ) ) {
				$wc_parent = $resolved[ $parent_id ];
			} elseif ( $parent_id !== null && $parent_id !== $super_id ) {
				// Parent not yet resolved but exists in our set — find/create it
				if ( isset( $by_id[ $parent_id ] ) ) {
					$wc_parent = $this->find_or_create_category_term(
						$parent_id,
						$by_id[ $parent_id ]['name'],
						$tax,
						$cat_id_meta,
						0
					);
					if ( $wc_parent ) {
						$resolved[ $parent_id ] = $wc_parent;
					}
				}
			}

			$wc_term_id = $this->find_or_create_category_term(
				$cat_id,
				$cat['name'],
				$tax,
				$cat_id_meta,
				$wc_parent
			);

			if ( $wc_term_id && $cat_id !== null ) {
				$resolved[ $cat_id ] = $wc_term_id;
				++$created_count;
			}
		}

		$this->logger->info(
			'Category tree synced',
			[
				'super_category_id' => $super_id,
				'total_categories'  => count( $flat ),
				'resolved'          => $created_count,
			]
		);
	}

	/**
	 * Recursively fetch categories from the API, descending into each child.
	 *
	 * Calls getCategories for a parent category ID, flattens the direct children,
	 * then recurses into each child to fetch deeper levels. Stops when a category
	 * returns no children or has already been seen (cycle protection).
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client    API client.
	 * @param int                             $parent_id Category ID to fetch children for.
	 * @param array                           $languages Include languages for API call.
	 * @param string                          $lang      Preferred language for names.
	 * @param array                           &$flat     Output: flat list (mutated).
	 * @param int                             $depth     Current recursion depth (safety limit).
	 */
	private function fetch_categories_recursive(
		Skwirrel_WC_Sync_JsonRpc_Client $client,
		int $parent_id,
		array $languages,
		string $lang,
		array &$flat,
		int $depth = 0
	): void {
		// Safety: prevent infinite recursion (10 levels should be more than enough)
		if ( $depth > 10 ) {
			$this->logger->warning(
				'Category tree recursion depth limit reached',
				[
					'parent_id' => $parent_id,
					'depth'     => $depth,
				]
			);
			return;
		}

		$params = [
			'category_id'                   => $parent_id,
			'include_children'              => true,
			'include_category_translations' => true,
		];

		if ( ! empty( $languages ) ) {
			$params['include_languages'] = $languages;
		}

		$result = $client->call( 'getCategories', $params );

		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			$this->logger->error(
				'getCategories API error',
				[
					'parent_id' => $parent_id,
					'error'     => $err,
				]
			);
			return;
		}

		$data       = $result['result'] ?? [];
		$categories = $data['categories'] ?? $data;

		$this->logger->verbose(
			'getCategories response',
			[
				'parent_id'       => $parent_id,
				'depth'           => $depth,
				'result_keys'     => is_array( $data ) ? array_keys( $data ) : gettype( $data ),
				'categories_keys' => is_array( $categories ) ? array_slice( array_keys( $categories ), 0, 15 ) : gettype( $categories ),
			]
		);

		if ( ! is_array( $categories ) ) {
			return;
		}

		// The API may return a single root category object — extract children.
		if ( isset( $categories['category_id'] ) || isset( $categories['_children'] ) || isset( $categories['category_name'] ) ) {
			$categories = $categories['_children'] ?? $categories['_categories'] ?? $categories['children'] ?? [];
		}

		if ( empty( $categories ) ) {
			return;
		}

		// Collect IDs already in $flat to avoid duplicates and detect what's new.
		$known_ids = [];
		foreach ( $flat as $existing ) {
			if ( null !== $existing['id'] ) {
				$known_ids[ $existing['id'] ] = true;
			}
		}

		// Flatten this level and collect child IDs for recursive fetching.
		$level_flat = [];
		$this->flatten_category_tree( $categories, $level_flat, $lang );

		$new_child_ids = [];
		foreach ( $level_flat as $cat ) {
			if ( null !== $cat['id'] && ! isset( $known_ids[ $cat['id'] ] ) ) {
				$flat[]                      = $cat;
				$known_ids[ $cat['id'] ]     = true;
				$new_child_ids[ $cat['id'] ] = true;
			} elseif ( null === $cat['id'] ) {
				$flat[] = $cat;
			}
		}

		// Recurse into each new child category to fetch deeper levels.
		foreach ( $new_child_ids as $child_id => $_ ) {
			$this->fetch_categories_recursive( $client, $child_id, $languages, $lang, $flat, $depth + 1 );
		}
	}

	/**
	 * Assign product categories to a WooCommerce product.
	 *
	 * Matches by Skwirrel category ID first (term meta), then by name.
	 * Supports parent/child hierarchy from _categories data.
	 *
	 * @param int                              $wc_product_id WooCommerce product ID.
	 * @param array                            $product       Skwirrel product data.
	 * @param Skwirrel_WC_Sync_Product_Mapper  $mapper        Product mapper instance.
	 */
	public function assign_categories( int $wc_product_id, array $product, Skwirrel_WC_Sync_Product_Mapper $mapper ): void {
		$categories = $mapper->get_categories( $product );
		if ( empty( $categories ) ) {
			return;
		}

		$tax         = 'product_cat';
		$term_ids    = [];
		$cat_id_meta = Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META;

		// Build lookup: skwirrel_id → category entry (for parent resolution)
		$by_skwirrel_id = [];
		foreach ( $categories as $cat ) {
			if ( $cat['id'] !== null ) {
				$by_skwirrel_id[ $cat['id'] ] = $cat;
			}
		}

		// Resolve the full tree in topological order (roots first).
		$resolved = []; // skwirrel_id => wc_term_id

		// Recursive resolver — resolves parent chain before the category itself.
		$resolve = function ( array $cat ) use (
			&$resolve,
			&$resolved,
			&$term_ids,
			$by_skwirrel_id,
			$tax,
			$cat_id_meta
		): int {
			$cat_id = $cat['id'] ?? null;

			// Already resolved?
			if ( $cat_id !== null && isset( $resolved[ $cat_id ] ) ) {
				return $resolved[ $cat_id ];
			}

			$parent_id         = $cat['parent_id'] ?? null;
			$wc_parent_term_id = 0;

			// Resolve parent first (if it exists in our tree)
			if ( $parent_id !== null && isset( $by_skwirrel_id[ $parent_id ] ) ) {
				$wc_parent_term_id = $resolve( $by_skwirrel_id[ $parent_id ] );
			} elseif ( $parent_id !== null || ( $cat['parent_name'] ?? '' ) !== '' ) {
				// Parent not in our tree — look up / create by ID+name
				$wc_parent_term_id = $this->find_or_create_category_term(
					$parent_id,
					$cat['parent_name'] ?? '',
					$tax,
					$cat_id_meta,
					0
				);
				if ( $wc_parent_term_id && $parent_id !== null ) {
					$resolved[ $parent_id ] = $wc_parent_term_id;
				}
			}

			// Resolve the category itself
			$wc_term_id = $this->find_or_create_category_term(
				$cat_id,
				$cat['name'],
				$tax,
				$cat_id_meta,
				$wc_parent_term_id
			);

			$this->logger->verbose(
				'Category resolve step',
				[
					'skwirrel_id'    => $cat_id,
					'name'           => $cat['name'],
					'parent_term_id' => $wc_parent_term_id,
					'wc_term_id'     => $wc_term_id,
				]
			);

			if ( $wc_term_id ) {
				$term_ids[] = $wc_term_id;
				if ( $cat_id !== null ) {
					$resolved[ $cat_id ] = $wc_term_id;
				}
				// Include all ancestors in the product's terms
				if ( $wc_parent_term_id ) {
					$term_ids[] = $wc_parent_term_id;
				}
			}

			return $wc_term_id;
		};

		foreach ( $categories as $cat ) {
			$resolve( $cat );
		}

		$term_ids = array_unique( array_map( 'intval', $term_ids ) );
		if ( ! empty( $term_ids ) ) {
			$result = wp_set_object_terms( $wc_product_id, $term_ids, $tax );
			if ( is_wp_error( $result ) ) {
				$this->logger->warning(
					'wp_set_object_terms failed',
					[
						'wc_product_id' => $wc_product_id,
						'term_ids'      => $term_ids,
						'error'         => $result->get_error_message(),
					]
				);
			} else {
				$this->logger->verbose(
					'Categories assigned',
					[
						'wc_product_id' => $wc_product_id,
						'term_ids'      => $term_ids,
						'names'         => array_column( $categories, 'name' ),
					]
				);
			}
		} else {
			$this->logger->warning(
				'Category assignment produced no term IDs',
				[
					'wc_product_id'  => $wc_product_id,
					'category_count' => count( $categories ),
					'categories'     => array_map(
						static function ( array $c ): array {
							return [
								'id'        => $c['id'] ?? null,
								'name'      => $c['name'] ?? '',
								'parent_id' => $c['parent_id'] ?? null,
							];
						},
						$categories
					),
				]
			);
		}
	}

	/**
	 * Find existing term by Skwirrel category ID (term meta) or name, or create new.
	 *
	 * @param int|null $skwirrel_id    Skwirrel category ID (null if unknown).
	 * @param string   $name           Category name.
	 * @param string   $taxonomy       Taxonomy slug (product_cat).
	 * @param string   $meta_key       Term meta key for Skwirrel ID.
	 * @param int      $parent_term_id WC parent term ID (0 for root).
	 * @return int WC term_id or 0 on failure.
	 */
	public function find_or_create_category_term(
		?int $skwirrel_id,
		string $name,
		string $taxonomy,
		string $meta_key,
		int $parent_term_id
	): int {
		if ( $name === '' && $skwirrel_id === null ) {
			return 0;
		}

		// Track seen category IDs for purge logic
		if ( $skwirrel_id !== null ) {
			$this->seen_category_ids[] = (string) $skwirrel_id;
		}

		// 1. Match by Skwirrel category ID in term meta (reliable)
		if ( $skwirrel_id !== null ) {
			global $wpdb;
			$existing_term_id = $wpdb->get_var(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- term meta lookup by value not supported by WP API
					"SELECT tm.term_id FROM {$wpdb->termmeta} tm
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id AND tt.taxonomy = %s
                 WHERE tm.meta_key = %s AND tm.meta_value = %s
                 LIMIT 1",
					$taxonomy,
					$meta_key,
					(string) $skwirrel_id
				)
			);
			if ( $existing_term_id ) {
				$this->logger->verbose(
					'Category found by meta',
					[
						'skwirrel_id' => $skwirrel_id,
						'term_id'     => (int) $existing_term_id,
						'name'        => $name,
					]
				);
				return (int) $existing_term_id;
			}
			$this->logger->verbose(
				'Category meta lookup missed',
				[
					'skwirrel_id' => $skwirrel_id,
					'name'        => $name,
					'meta_key'    => $meta_key,
				]
			);
		}

		// 2. Fall back to name matching
		if ( $name !== '' ) {
			$term = term_exists( $name, $taxonomy, $parent_term_id ?: 0 );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
				// Store Skwirrel ID for next sync
				if ( $skwirrel_id !== null ) {
					update_term_meta( $term_id, $meta_key, (string) $skwirrel_id );
				}
				$this->logger->verbose(
					'Category found by name',
					[
						'name'           => $name,
						'term_id'        => $term_id,
						'parent_term_id' => $parent_term_id,
					]
				);
				return $term_id;
			}
			$this->logger->verbose(
				'Category name lookup missed',
				[
					'name'           => $name,
					'parent_term_id' => $parent_term_id,
					'term_exists'    => $term,
				]
			);
		}

		// 3. Create new term
		if ( $name === '' ) {
			return 0;
		}
		$args = [];
		if ( $parent_term_id ) {
			$args['parent'] = $parent_term_id;
		}
		$inserted = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $inserted ) ) {
			// Handle "term already exists" race condition
			if ( $inserted->get_error_code() === 'term_exists' ) {
				$term_id = (int) $inserted->get_error_data( 'term_exists' );
				if ( $skwirrel_id !== null && $term_id ) {
					update_term_meta( $term_id, $meta_key, (string) $skwirrel_id );
				}
				return $term_id;
			}
			$this->logger->warning(
				'Failed to create category term',
				[
					'name'  => $name,
					'error' => $inserted->get_error_message(),
				]
			);
			return 0;
		}

		$term_id = (int) $inserted['term_id'];
		if ( $skwirrel_id !== null ) {
			update_term_meta( $term_id, $meta_key, (string) $skwirrel_id );
		}
		$this->logger->verbose(
			'Category term created',
			[
				'term_id'     => $term_id,
				'name'        => $name,
				'skwirrel_id' => $skwirrel_id,
				'parent'      => $parent_term_id,
			]
		);
		return $term_id;
	}

	/**
	 * Recursively flatten a nested category tree into a flat list.
	 *
	 * @param array  $categories Nested category array from API.
	 * @param array  $flat       Output: flat list of ['id', 'name', 'parent_id'].
	 * @param string $lang       Preferred language for category name.
	 */
	private function flatten_category_tree( array $categories, array &$flat, string $lang ): void {
		foreach ( $categories as $cat ) {
			$cat_id = $cat['category_id'] ?? $cat['product_category_id'] ?? $cat['id'] ?? null;
			if ( $cat_id !== null ) {
				$cat_id = (int) $cat_id;
			}

			$name = $this->pick_category_name( $cat, $lang );
			if ( $name === '' && isset( $cat['category_name'] ) ) {
				$name = $cat['category_name'];
			}

			$parent_id = $cat['parent_category_id'] ?? null;
			if ( $parent_id !== null ) {
				$parent_id = (int) $parent_id;
			}

			if ( $name !== '' ) {
				$flat[] = [
					'id'        => $cat_id,
					'name'      => $name,
					'parent_id' => $parent_id,
				];
			}

			// Recurse into children
			$children = $cat['_children'] ?? $cat['_categories'] ?? $cat['children'] ?? [];
			if ( ! empty( $children ) && is_array( $children ) ) {
				$this->flatten_category_tree( $children, $flat, $lang );
			}
		}
	}

	/**
	 * Pick the best category name based on language preference.
	 *
	 * @param array  $cat  Category data from API.
	 * @param string $lang Preferred language code.
	 * @return string Category name, or empty string if none found.
	 */
	private function pick_category_name( array $cat, string $lang ): string {
		$translations = $cat['_category_translations'] ?? [];
		if ( ! empty( $translations ) && is_array( $translations ) ) {
			foreach ( $translations as $t ) {
				$t_lang = $t['language'] ?? '';
				if ( stripos( $t_lang, $lang ) === 0 || stripos( $lang, $t_lang ) === 0 ) {
					$name = $t['category_name'] ?? $t['product_category_name'] ?? $t['name'] ?? '';
					if ( $name !== '' ) {
						return $name;
					}
				}
			}
			// Fallback: first translation with a name
			foreach ( $translations as $t ) {
				$name = $t['category_name'] ?? $t['product_category_name'] ?? $t['name'] ?? '';
				if ( $name !== '' ) {
					return $name;
				}
			}
		}
		return $cat['category_name'] ?? $cat['product_category_name'] ?? $cat['name'] ?? '';
	}
}
