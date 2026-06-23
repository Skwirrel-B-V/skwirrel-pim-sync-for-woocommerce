<?php
/**
 * Skwirrel Sync Service.
 *
 * Orchestrates product sync: fetches from API, maps, upserts to WooCommerce.
 * Supports full sync and delta sync (updated_on filter).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Service {

	private Skwirrel_WC_Sync_Logger $logger;
	private Skwirrel_WC_Sync_Product_Mapper $mapper;
	private Skwirrel_WC_Sync_Purge_Handler $purge_handler;
	private Skwirrel_WC_Sync_Category_Sync $category_sync;
	private Skwirrel_WC_Sync_Brand_Sync $brand_sync;
	private Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager;
	private Skwirrel_WC_Sync_Product_Upserter $upserter;

	public function __construct() {
		$this->logger           = new Skwirrel_WC_Sync_Logger();
		$this->mapper           = new Skwirrel_WC_Sync_Product_Mapper();
		$lookup                 = new Skwirrel_WC_Sync_Product_Lookup( $this->mapper );
		$this->purge_handler    = new Skwirrel_WC_Sync_Purge_Handler( $this->logger );
		$this->category_sync    = new Skwirrel_WC_Sync_Category_Sync( $this->logger );
		$this->brand_sync       = new Skwirrel_WC_Sync_Brand_Sync( $this->logger );
		$this->taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager( $this->logger );
		$this->upserter         = new Skwirrel_WC_Sync_Product_Upserter(
			$this->logger,
			$this->mapper,
			$lookup,
			$this->category_sync,
			$this->brand_sync,
			$this->taxonomy_manager,
			new Skwirrel_WC_Sync_Slug_Resolver()
		);
	}

	/**
	 * Run sync in phases. Returns summary array.
	 *
	 * Phases:
	 * 1. Fetch — paginate through API, store product data in database queue
	 * 2. Products — create/update WC products (basic fields only)
	 * 3. Taxonomy — assign categories, brands, manufacturers
	 * 4. Attributes — assign ETIM + custom class attributes
	 * 5. Media — download images + documents (slowest phase)
	 * 6. Cleanup — flush deferred attrs, purge stale, persist history
	 *
	 * @param bool   $delta   Use delta sync (updated_on >= last sync) if possible.
	 * @param string $trigger What initiated the sync: 'manual' or 'scheduled'.
	 * @return array{success: bool, created: int, updated: int, failed: int, error?: string}
	 */
	public function run_sync( bool $delta = false, string $trigger = Skwirrel_WC_Sync_History::TRIGGER_MANUAL ): array {
		// Mutex: refuse to start a second run while another one's heartbeat
		// is still fresh. The mutex is refreshed at every phase update
		// (HEARTBEAT_TTL = 60s); a stale timestamp implies the prior run died
		// without cleanup, so acquire_sync_mutex() lets the new run take over.
		// Without this check two concurrent runs race the shared queue table,
		// the per-product `_skwirrel_synced_at` meta and ultimately the purge
		// step — at worst trashing every Skwirrel-managed product because
		// Run B truncated Run A's queue before any synced_at could be written.
		//
		// Owned exclusively by SYNC_MUTEX. SYNC_IN_PROGRESS is the UI badge
		// (pre-set by handle_sync_now() so the dashboard shows "Sync running"
		// from the moment the user clicks). The two were the same key until
		// 3.10.0; the collision broke every manual "Sync now" since 3.8.0.
		if ( ! Skwirrel_WC_Sync_History::acquire_sync_mutex() ) {
			return [
				'success' => false,
				'error'   => __( 'Another sync is already running; refusing to start a second concurrent run.', 'skwirrel-pim-sync' ),
				'created' => 0,
				'updated' => 0,
				'failed'  => 0,
			];
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running sync requires no time limit; @ guards against disable_functions
		}

		// Raise PHP memory limit — API responses with all includes can be very large.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$sync_started_at = time();
		$this->category_sync->reset_seen_category_ids();
		Skwirrel_WC_Sync_History::clear_abort();
		Skwirrel_WC_Sync_History::sync_heartbeat();
		Skwirrel_WC_Sync_History::clear_sync_progress();

		// Per-sync log file: cleanup old logs, then start a new one.
		$options_for_log = $this->get_options();
		Skwirrel_WC_Sync_Logger::cleanup_old_logs( $options_for_log['log_retention'] ?? '7days' );
		$log_mode     = Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED === $trigger
			? ( $options_for_log['log_mode_scheduled'] ?? 'per_day' )
			: ( $options_for_log['log_mode_manual'] ?? 'per_sync' );
		$log_filename = $this->logger->start_sync_log( $trigger, $log_mode );

		// Register shutdown handler to catch fatal errors (e.g. OOM) and record them.
		$shutdown_trigger    = $trigger;
		$shutdown_log_file   = $log_filename;
		$shutdown_registered = true;
		// Populated once the run id is generated (below). On a fatal crash the
		// handler uses it to delete this run's queue rows, which the normal
		// end-of-run cleanup never reaches.
		$shutdown_run_id = null;
		register_shutdown_function(
			static function () use ( $shutdown_trigger, $shutdown_log_file, &$shutdown_registered, &$shutdown_run_id ): void {
				if ( ! $shutdown_registered ) { // @phpstan-ignore booleanNot.alwaysFalse (by-reference variable is set to false in finally block)
					return; // Sync completed normally, nothing to do.
				}
				$error = error_get_last();
				if ( null === $error || ! in_array( $error['type'], [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
					return;
				}
				// Remove this run's queue rows — a fatal error bypasses the
				// try/finally cleanup, so without this they orphan forever.
				if ( null !== $shutdown_run_id ) { // @phpstan-ignore notIdentical.alwaysFalse (by-reference variable is assigned the run id inside the try block)
					Skwirrel_WC_Sync_Queue::delete_run( $shutdown_run_id );
				}
				// Record a failed sync so the dashboard shows the crash.
				Skwirrel_WC_Sync_History::update_last_result(
					false,
					0,
					0,
					0,
					$error['message'],
					0,
					0,
					0,
					0,
					$shutdown_trigger,
					$shutdown_log_file
				);
			}
		);

		// Initialised before the try so the catch blocks below can report
		// progress-so-far even when a crash happens before the main loop.
		$created   = 0;
		$updated   = 0;
		$unchanged = 0;
		$failed    = 0;

		try {
			global $wpdb;

			// Free memory accumulated by WordPress/WooCommerce boot and clear query log.
			self::free_wpdb_memory();
			wp_cache_flush();
			$this->logger->info(
				'Sync memory baseline',
				[ 'memory_mb' => round( memory_get_usage( true ) / 1048576, 1 ) ]
			);

			$client = $this->get_client();
			if ( ! $client ) {
				$this->logger->error( 'Sync aborted: invalid configuration' );
				$this->logger->stop_sync_log();
				return [
					'success' => false,
					'error'   => 'Invalid configuration',
					'created' => 0,
					'updated' => 0,
					'failed'  => 0,
				];
			}

			$options     = $this->get_options();
			$delta_since = get_option( Skwirrel_WC_Sync_History::OPTION_LAST_SYNC, '' );

			// Change gate: skip products whose Skwirrel `product_updated_on` has not advanced since
			// the last sync — but only when the output-affecting settings (and plugin version) are
			// unchanged, so a settings change (or the first run) still forces a full reprocess.
			$sync_sig     = $this->compute_sync_signature( $options );
			$gate_enabled = (
				'' !== $sync_sig
				&& get_option( 'skwirrel_wc_sync_last_sync_sig', '' ) === $sync_sig
				// A pending slug-resync must reprocess everything: unchanged rows skip slug
				// resolution, yet update_last_result() clears the flag on success — gating here
				// would drop the "slugs need resync" state without ever updating the slugs.
				&& ! get_option( 'skwirrel_wc_sync_slug_resync_needed' )
			);
			$this->upserter->set_change_gate_enabled( $gate_enabled );
			$this->logger->info(
				'Change gate',
				[
					'enabled' => $gate_enabled,
					'reason'  => $gate_enabled ? 'settings unchanged — unchanged products will be skipped' : 'first run or settings changed — full reprocess',
				]
			);

			$collection_ids = $this->get_collection_ids();
			if ( empty( $collection_ids ) ) {
				$this->logger->error( 'Sync aborted: no selection IDs configured' );
				$this->logger->stop_sync_log();
				return [
					'success' => false,
					'error'   => 'No selection IDs configured. A selection ID is required.',
					'created' => 0,
					'updated' => 0,
					'failed'  => 0,
				];
			}

			$custom_collection_id = $options['custom_collection_id'] ?? '';
			if ( empty( $custom_collection_id ) ) {
				$this->logger->error( 'Sync aborted: no custom class collection ID configured' );
				$this->logger->stop_sync_log();
				return [
					'success' => false,
					'error'   => 'No custom class collection ID configured. This field is required.',
					'created' => 0,
					'updated' => 0,
					'failed'  => 0,
				];
			}
			if ( ! empty( $options['sync_categories'] ) ) {
				$super_cat_id = (int) ( $options['super_category_id'] ?? 0 );
				if ( $super_cat_id <= 0 ) {
					$this->logger->error( 'Sync aborted: sync_categories is enabled but no valid super category ID configured' );
					$this->logger->stop_sync_log();
					return [
						'success' => false,
						'error'   => 'Category sync is enabled but no super category ID configured. A super category ID greater than 0 is required.',
						'created' => 0,
						'updated' => 0,
						'failed'  => 0,
					];
				}
			}

			$batch_size = (int) ( $options['batch_size'] ?? 10 );

			// Build API include flags — keep the fetch lightweight (no ETIM/custom classes).
			// Attributes are fetched per-product in Phase 3 to avoid OOM on large catalogues.
			$api_includes = [
				'include_product_status'       => true,
				'include_product_translations' => true,
				'include_attachments'          => true,
				'include_trade_items'          => true,
				'include_trade_item_prices'    => true,
				'include_categories'           => ! empty( $options['sync_categories'] ),
				'include_product_groups'       => ! empty( $options['sync_categories'] ) || ! empty( $options['sync_grouped_products'] ),
				'include_grouped_products'     => ! empty( $options['sync_grouped_products'] ),
				'include_related_products'     => ! empty( $options['sync_related_products'] ),
				'include_languages'            => $this->get_include_languages(),
				'include_contexts'             => [ 1 ],
			];

			$sync_cc    = ! empty( $options['sync_custom_classes'] );
			$sync_ti_cc = ! empty( $options['sync_trade_item_custom_classes'] );
			if ( $sync_cc || $sync_ti_cc ) {
				$api_includes['include_custom_collection_id'] = [ (int) $custom_collection_id ];
			}
			if ( $sync_cc ) {
				$api_includes['include_custom_classes'] = true;
				$cc_filter_mode                         = $options['custom_class_filter_mode'] ?? '';
				$cc_raw                                 = $options['custom_class_filter_ids'] ?? '';
				$cc_parsed                              = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_raw );
				if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
					$api_includes['include_custom_class_id'] = $cc_parsed['ids'];
				}
			}
			if ( $sync_ti_cc ) {
				$api_includes['include_trade_item_custom_classes'] = true;
				$cc_filter_mode                                    = $cc_filter_mode ?? ( $options['custom_class_filter_mode'] ?? '' );
				$cc_raw    = $cc_raw ?? ( $options['custom_class_filter_ids'] ?? '' );
				$cc_parsed = $cc_parsed ?? Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_raw );
				if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
					$api_includes['include_trade_item_custom_class_id'] = $cc_parsed['ids'];
				}
			}

			// Attribute includes — used in Phase 3 per-product fetch only (ETIM + custom classes).
			$attr_includes = [
				'include_etim'              => true,
				'include_etim_translations' => true,
				'include_languages'         => $this->get_include_languages(),
				'include_contexts'          => [ 1 ],
			];
			// Grouped products may use custom features as variation axes, so custom
			// classes must be available in Phase 3 for variation attribute assignment.
			if ( ! empty( $options['sync_grouped_products'] ) || $sync_cc ) {
				$attr_includes['include_custom_classes']       = true;
				$attr_includes['include_custom_collection_id'] = [ (int) $custom_collection_id ];
			}

			// Determine whether to use getProducts (fast, full sync) or getProductsByFilter (filtered).
			$use_filter        = false;
			$filter            = [];
			$initial_delta_run = false;
			if ( $delta && ! empty( $delta_since ) && $gate_enabled ) {
				$use_filter           = true;
				$filter['updated_on'] = [
					'datetime' => $delta_since,
					'operator' => '>=',
				];
			} elseif ( $delta && ! empty( $delta_since ) && ! $gate_enabled ) {
				// Output-affecting settings (or the plugin version / slug options) changed since the
				// last run, so the change gate is off. A delta `updated_on` filter would only fetch
				// upstream-changed products, leaving the rest of the catalog on the OLD settings.
				// Drop the filter for this run → a full pass that re-applies the new settings to every
				// product (each is still committed individually; the gate re-engages next run).
				$this->logger->info( 'Settings/version change detected — running this delta as a full pass so the new settings apply to every product.' );
			} elseif ( $delta && empty( $delta_since ) ) {
				// Delta requested but no checkpoint yet — first delta run after install,
				// reset, purge, or a string of failed runs. The API call falls through
				// to "everything in selection" (no updated_on filter). We do NOT seed
				// last_sync here: it is written only on provable completion (end of run,
				// stamped with $sync_started_at) so an interrupted run can never advance
				// the checkpoint past products it never committed (the F4 image-loss bug).
				$initial_delta_run = true;
				$this->logger->info(
					'Delta sync requested but no checkpoint exists — running as initial full pass; last_sync will be seeded on completion.'
				);
			}
			// `dynamic_selection_id` is a single-int filter on the Skwirrel side,
			// so multiple configured selections become one API call per selection.
			// The actual filter value is set inside the per-selection loop below.
			$use_filter = true;

			$this->logger->info(
				'Sync started',
				[
					'delta'          => $delta,
					'delta_since'    => $delta_since,
					'initial_delta'  => $initial_delta_run,
					'batch_size'     => $batch_size,
					'api_method'     => 'getProductsByFilter',
					'collection_ids' => $collection_ids,
					'filter'         => $filter,
				]
			);

			// Pre-sync: category tree, brands, custom classes, grouped products
			$this->check_abort();
			if ( ! empty( $options['sync_categories'] ) ) {
				$this->category_sync->sync_category_tree( $client, $options, $this->get_include_languages() );
			}
			$this->brand_sync->sync_all_brands( $client );
			if ( ! empty( $options['sync_custom_classes'] ) || ! empty( $options['sync_trade_item_custom_classes'] ) ) {
				$this->taxonomy_manager->sync_all_custom_classes( $client, $options, $this->get_include_languages() );
			}

			// Free object cache accumulated during pre-sync (categories, brands, custom classes).
			self::free_wpdb_memory();
			wp_cache_flush();

			$product_to_group_map = [];
			if ( ! empty( $options['sync_grouped_products'] ) ) {
				$grouped_result       = $this->upserter->sync_grouped_products_first( $client, $options, $collection_ids );
				$product_to_group_map = $grouped_result['map'];
				$created             += $grouped_result['created'];
				$updated             += $grouped_result['updated'];
			}

			// Free object cache from grouped products phase.
			self::free_wpdb_memory();
			wp_cache_flush();
			$this->logger->info(
				'Pre-sync complete, starting fetch',
				[ 'memory_mb' => round( memory_get_usage( true ) / 1048576, 1 ) ]
			);

			// =====================================================================
			$this->check_abort();
			// Phase: Fetch — paginate through API, store in database queue
			// =====================================================================
			Skwirrel_WC_Sync_History::update_phase_progress(
				Skwirrel_WC_Sync_History::PHASE_FETCH,
				0,
				0,
				__( 'Fetching products from API…', 'skwirrel-pim-sync' )
			);

			// Ensure sync queue table exists. Each run inserts only its own
			// rows (tagged with sync_run_id) and removes them via
			// $queue->cleanup() at end-of-run; no global truncate here, see
			// Skwirrel_WC_Sync_Queue::truncate() for the rationale.
			if ( ! Skwirrel_WC_Sync_Queue::table_exists() ) {
				Skwirrel_WC_Sync_Queue::create_table();
			}
			$sync_run_id = wp_generate_uuid4();
			$queue       = new Skwirrel_WC_Sync_Queue( $sync_run_id );
			$fetched     = 0;

			// Expose the run id to the shutdown handler so a fatal crash can
			// still drop this run's rows.
			$shutdown_run_id = $sync_run_id;

			// Sweep rows abandoned by earlier interrupted runs. The mutex
			// guarantees single concurrency, so anything not tagged with the
			// current run id is dead. This is the backstop that survives even
			// hard kills, where neither finally nor the shutdown handler runs.
			$orphans = $queue->cleanup_orphans();
			if ( $orphans > 0 ) {
				$this->logger->warning(
					'Removed orphaned queue rows from previous interrupted sync run(s)',
					[ 'rows' => $orphans ]
				);
			}

			// One API call per configured selection ID — `dynamic_selection_id`
			// is a single-int filter on Skwirrel's side, so a comma-separated
			// `collection_ids` setting becomes one paginated fetch per id.
			foreach ( $collection_ids as $selection_id ) {
				$filter['dynamic_selection_id'] = (int) $selection_id;
				$page                           = 1;

				$result = $this->fetch_products_page( $client, $use_filter, $filter, $api_includes, $batch_size, $page );
				if ( ! $result['success'] ) {
					$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
					$this->logger->error( 'Sync API error', array_merge( $err, [ 'selection_id' => $selection_id ] ) );
					$this->logger->stop_sync_log();
					$queue->cleanup();
					Skwirrel_WC_Sync_History::update_last_result( false, $created, $updated, $failed, $err['message'] ?? '', 0, 0, 0, 0, $trigger, $log_filename );
					return [
						'success' => false,
						'error'   => $err['message'] ?? 'API error',
						'created' => 0,
						'updated' => 0,
						'failed'  => 0,
					];
				}

				$data     = $result['result'] ?? [];
				$products = $data['products'] ?? [];

				do {
					$page_count = count( $products );
					$this->logger->verbose(
						'Fetching batch',
						[
							'selection_id' => $selection_id,
							'page'         => $page,
							'count'        => $page_count,
						]
					);

					foreach ( $products as $product ) {
						$skwirrel_product_id = $product['product_id'] ?? $product['id'] ?? null;

						// Virtual products → queue for phase 4 (media on parent)
						$virtual_info = null;
						if ( null !== $skwirrel_product_id ) {
							$virtual_info = $product_to_group_map[ 'virtual:' . (int) $skwirrel_product_id ] ?? null;
						}
						if ( $virtual_info && ! empty( $virtual_info['is_virtual_for_variable'] ) ) {
							$queue->insert_virtual_item( $product, (int) $virtual_info['wc_variable_id'] );
							++$fetched;
							continue;
						}

						// Skip non-grouped VIRTUAL products
						if ( 'VIRTUAL' === ( $product['product_type'] ?? '' ) ) {
							continue;
						}

						// Resolve group info
						$sku_for_lookup = (string) ( $product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? $this->mapper->get_sku( $product ) );
						$group_info     = null;
						if ( null !== $skwirrel_product_id && '' !== $skwirrel_product_id ) {
							$group_info = $product_to_group_map[ (int) $skwirrel_product_id ] ?? null;
						}
						if ( ! $group_info && '' !== $sku_for_lookup ) {
							$group_info = $product_to_group_map[ 'sku:' . $sku_for_lookup ] ?? null;
						}

						$queue->insert_item( $product, $group_info );
						++$fetched;
					}

					// Free API response data and flush wpdb query log to reclaim memory.
					unset( $products, $data, $result );
					self::free_wpdb_memory();

					Skwirrel_WC_Sync_History::update_phase_progress(
						Skwirrel_WC_Sync_History::PHASE_FETCH,
						$fetched,
						0,
						/* translators: %d = number of products fetched so far */
						sprintf( __( 'Fetching products from API… (%d found)', 'skwirrel-pim-sync' ), $fetched )
					);

					if ( $page_count < $batch_size ) {
						break;
					}

					++$page;
					$result = $this->fetch_products_page( $client, $use_filter, $filter, $api_includes, $batch_size, $page );
					if ( ! $result['success'] ) {
						// Fail-fast on a partial fetch. Continuing here would let the
						// run reach the purge step and trash every product that
						// happened to live on the un-fetched pages, and would also
						// advance `last_sync` so those products silently disappear
						// from future delta syncs. The whole run must be recorded
						// as failed.
						$err = $result['error'] ?? [ 'message' => 'Pagination failed' ];
						$this->logger->error(
							'Pagination failed; aborting sync',
							array_merge(
								$err,
								[
									'selection_id' => $selection_id,
									'page'         => $page,
								]
							)
						);
						$this->logger->stop_sync_log();
						$queue->cleanup();
						Skwirrel_WC_Sync_History::update_last_result(
							false,
							$created,
							$updated,
							$failed,
							sprintf(
								/* translators: 1: API error message, 2: page number, 3: selection id */
								__( 'Pagination failed at page %2$d (selection %3$d): %1$s', 'skwirrel-pim-sync' ),
								(string) ( $err['message'] ?? 'API error' ),
								$page,
								(int) $selection_id
							),
							0,
							0,
							0,
							0,
							$trigger,
							$log_filename
						);
						return [
							'success' => false,
							'error'   => sprintf(
								/* translators: 1: API error message, 2: page number, 3: selection id */
								__( 'Pagination failed at page %2$d (selection %3$d): %1$s', 'skwirrel-pim-sync' ),
								(string) ( $err['message'] ?? 'API error' ),
								$page,
								(int) $selection_id
							),
							'created' => 0,
							'updated' => 0,
							'failed'  => 0,
						];
					}
					$data     = $result['result'] ?? [];
					$products = $data['products'] ?? [];
				} while ( ! empty( $products ) );
			}

			if ( $delta && 0 === $fetched ) {
				$this->logger->info( 'Delta sync: no products updated since last sync (across all configured selections)' );
				$this->logger->stop_sync_log();
				Skwirrel_WC_Sync_History::update_last_result( true, 0, 0, 0, '', 0, 0, 0, 0, $trigger, $log_filename );
				return [
					'success' => true,
					'created' => 0,
					'updated' => 0,
					'failed'  => 0,
				];
			}

			$total         = $queue->count_items( false );
			$virtual_total = $queue->count_items( true );
			$this->logger->info( "Fetch complete: {$total} products + {$virtual_total} virtual items to process in phases" );

			// =====================================================================
			$this->check_abort();
			// Per-product commit — each product is fully created/updated, categorised,
			// attributed and given its media in ONE pass before moving to the next, so an
			// interrupted run leaves only un-started products incomplete (never bare or
			// duplicated). Replaces the former separate Phase 1–4 global loops.
			// =====================================================================
			$with_attrs    = 0;
			$without_attrs = 0;
			// Set if any product committed only partially (an aspect failed). Used to hold the
			// delta checkpoint so a partial row is re-pulled and retried on the next run.
			$partial_commit = false;

			Skwirrel_WC_Sync_History::update_phase_progress(
				Skwirrel_WC_Sync_History::PHASE_PRODUCTS,
				0,
				$total,
				__( 'Creating & syncing products…', 'skwirrel-pim-sync' )
			);

			$processed = 0;
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( $row = $queue->get_next_for_phase( 4 ) ) {
				$outcome         = 'skipped';
				$wc_id           = 0;
				$pending_publish = false;
				$aspect_failed   = false;
				try {
					$result_item = $row->group_info
						? $this->upserter->create_or_update_variation(
							apply_filters( 'skwirrel_wc_sync_product_before_variation', $row->product, $row->group_info ),
							$row->group_info
						)
						: $this->upserter->create_or_update_product( $row->product );

					$wc_id           = $result_item['wc_id'];
					$outcome         = $result_item['outcome'];
					$pending_publish = (bool) ( $result_item['pending_publish'] ?? false );

					if ( 'created' === $outcome ) {
						++$created;
					} elseif ( 'updated' === $outcome ) {
						++$updated;
					} elseif ( 'unchanged' === $outcome ) {
						++$unchanged;
					} else {
						++$failed;
					}
				} catch ( Throwable $e ) {
					++$failed;
					$outcome = 'failed';
					$this->logger->error(
						'Product create/update failed',
						[
							'product' => $row->product['internal_product_code'] ?? $row->product['product_id'] ?? '?',
							'error'   => $e->getMessage(),
						]
					);
				}

				// Persist identity + outcome so the deferred relations pass can find this row.
				$queue->update_after_phase1( $row->id, $wc_id, $outcome );

				// 'unchanged' products (and 'skipped') get none of the per-product aspect work —
				// this is where skipping the attribute API refetch + media saves the time.
				if ( $wc_id && 'skipped' !== $outcome && 'unchanged' !== $outcome ) {
					// --- Taxonomy: categories, brands, manufacturers (parent for variations) ---
					try {
						$tax_target = $row->group_info['wc_variable_id'] ?? $wc_id;
						$this->upserter->assign_taxonomy( $tax_target, $row->product );
					} catch ( Throwable $e ) {
						$aspect_failed = true;
						$this->logger->warning(
							'Taxonomy assignment failed',
							[
								'wc_id' => $wc_id,
								'error' => $e->getMessage(),
							]
						);
					}

					// --- Attributes: re-fetch ETIM + custom classes, then assign ---
					try {
						$attr_fetch_ok = true;
						$attr_product  = $this->fetch_product_attributes( $client, $row->product, $attr_includes, $attr_fetch_ok );

						if ( ! $attr_fetch_ok ) {
							// The attribute refetch (a separate API call) failed and returned the lightweight
							// payload with no ETIM/custom classes. Do NOT assign from it (that would clear
							// existing attributes); mark the commit partial so the gate timestamp isn't stamped
							// and the row is retried next run.
							$aspect_failed = true;
							unset( $attr_product );
						} else {
							/**
							 * Fires after the attribute-enriched payload is fetched, before WC attribute assignment.
							 *
							 * Allows third-party code (e.g. site-specific MU-plugins) to persist the enriched
							 * payload — including `_etim` and `_custom_classes` — as post meta for custom
							 * frontend rendering, alongside the standard WooCommerce attribute table.
							 *
							 * @param int                       $wc_id        WC product or variation ID being synced.
							 * @param array<string, mixed>      $attr_product Enriched product payload.
							 * @param array<string, mixed>|null $group_info   Group mapping (with `wc_variable_id`) or null for simple products.
							 */
							do_action( 'skwirrel_wc_sync_after_attributes_fetched', $wc_id, $attr_product, $row->group_info );

							$attr_count = $this->upserter->assign_attributes( $wc_id, $attr_product, $row->group_info );
							unset( $attr_product );
							if ( $attr_count > 0 ) {
								++$with_attrs;
							} else {
								++$without_attrs;
							}
						}
					} catch ( Throwable $e ) {
						$aspect_failed = true;
						$this->logger->warning(
							'Attribute assignment failed',
							[
								'wc_id' => $wc_id,
								'error' => $e->getMessage(),
							]
						);
					}

					// --- Media: images, downloads, documents (slowest step) ---
					try {
						// assign_media() returns false on a swallowed image/download failure (the importer
						// logs and returns 0 rather than throwing) — treat that as a partial commit so the
						// product is not published bare / gated unretried.
						if ( ! $this->upserter->assign_media( $wc_id, $row->product ) ) {
							$aspect_failed = true;
						}
					} catch ( Throwable $e ) {
						$aspect_failed = true;
						$this->logger->warning(
							'Media assignment failed',
							[
								'wc_id' => $wc_id,
								'error' => $e->getMessage(),
							]
						);
					}
				}

				// Publish a product that was held as draft during creation — but ONLY if every
				// aspect succeeded. Publishing after a taxonomy/attribute/media failure would put a
				// bare product live and defeat the draft-until-complete safety; leave it draft and
				// let the next run (it reprocesses — see below) publish it once fully committed.
				if ( $pending_publish && $wc_id && 'skipped' !== $outcome && ! $aspect_failed ) {
					try {
						$new_product = wc_get_product( $wc_id );
						if ( $new_product ) {
							$new_product->set_status( 'publish' );
							$new_product->save();
						}
					} catch ( Throwable $e ) {
						$aspect_failed = true;
						$this->logger->warning(
							'Final publish failed',
							[
								'wc_id' => $wc_id,
								'error' => $e->getMessage(),
							]
						);
					}
				}

					// Stamp the change-gate timestamp ONLY now that the product is fully committed (all aspects
					// + the held-draft publish succeeded). create_or_update_*() no longer stamp it optimistically,
					// so a partial commit — OR a hard crash/kill mid-product — simply leaves it unstamped and the
					// next run reprocesses it (the gate sees no/old timestamp). A partial commit also holds the
					// delta checkpoint (below) so a scheduled delta re-pulls and retries the row.
				if ( $wc_id && in_array( $outcome, [ 'created', 'updated' ], true ) ) {
					if ( $aspect_failed ) {
						$partial_commit = true;
					} else {
						update_post_meta( $wc_id, $this->mapper->get_updated_on_meta_key(), (string) ( $row->product['product_updated_on'] ?? '' ) );
					}
				}

				// Per-product checkpoint: this product is fully committed (resumable on interruption).
				$queue->mark_phase_completed( $row->id, 4 );
				self::free_wpdb_memory();
				wp_cache_flush();
				++$processed;

				if ( 0 === $processed % 25 || $total === $processed ) {
					Skwirrel_WC_Sync_History::update_phase_progress(
						Skwirrel_WC_Sync_History::PHASE_PRODUCTS,
						$processed,
						$total,
						__( 'Creating & syncing products…', 'skwirrel-pim-sync' )
					);
					$this->check_abort();
				}
			}

			// Flush deferred parent attribute terms (after ALL variations are committed above).
			$this->upserter->flush_parent_attribute_terms();

			// =====================================================================
			$this->check_abort();
			// Deferred: virtual products — apply content & media to the parent variable product.
			// (Regular products already got their media inside the per-product loop above.)
			// =====================================================================
			// Count only the variable-product parents finalized here — regular products already
			// got their media inside the per-product loop above, so this step is virtuals-only.
			$virtual_done = 0;

			if ( $virtual_total > 0 ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_MEDIA,
					$virtual_done,
					$virtual_total,
					__( 'Finalizing variable products…', 'skwirrel-pim-sync' )
				);
			}

			// Virtual products: apply content & images/documents to parent variable product
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( $row = $queue->get_next_virtual() ) {
				try {
					if ( ! empty( $options['use_virtual_product_content'] ) ) {
						$this->upserter->apply_virtual_product_content( $row->virtual_parent_id, $row->product );
					}
					// A swallowed image/download failure on the variable parent's media must also
					// hold the checkpoint — otherwise a delta won't return this virtual product
					// again unless Skwirrel changes it, leaving the parent without its media.
					if ( ! $this->upserter->assign_media( $row->virtual_parent_id, $row->product ) ) {
						$partial_commit = true;
					}
				} catch ( Throwable $e ) {
					$partial_commit = true;
					$this->logger->warning(
						'Virtual product processing failed',
						[
							'wc_variable_id' => $row->virtual_parent_id,
							'error'          => $e->getMessage(),
						]
					);
				}

				$queue->mark_phase_completed( $row->id, 4 );
				self::free_wpdb_memory();
				wp_cache_flush();
				++$virtual_done;

				if ( 0 === $virtual_done % 10 || $virtual_done === $virtual_total ) {
					Skwirrel_WC_Sync_History::update_phase_progress(
						Skwirrel_WC_Sync_History::PHASE_MEDIA,
						$virtual_done,
						$virtual_total,
						__( 'Finalizing variable products…', 'skwirrel-pim-sync' )
					);
					$this->check_abort();
				}
			}

			// =====================================================================
			$this->check_abort();
			// Phase 5: Relations — cross-sells & upsells
			// =====================================================================
			if ( ! empty( $options['sync_related_products'] ) ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_RELATIONS,
					0,
					$total,
					__( 'Linking related products…', 'skwirrel-pim-sync' )
				);

				$rel_i = 0;
				// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				while ( $row = $queue->get_next_for_phase( 5 ) ) {
					// Unchanged rows normally skip relations, but a product carrying
					// `_skwirrel_pending_relations` (targets that didn't exist on an earlier run) must
					// still retry — a now-created target can resolve even though this product itself
					// did not change; the page payload still holds its related-product data.
					$is_unchanged_row = 'unchanged' === $row->outcome;
					// `_skwirrel_pending_relations` is stored as an array — test emptiness directly,
					// never string-cast it (that emits an "Array to string conversion" warning).
					$pending_rel     = $is_unchanged_row ? get_post_meta( $row->wc_id, '_skwirrel_pending_relations', true ) : '';
					$retry_relations = ! empty( $pending_rel );
					if ( $row->wc_id && 'skipped' !== $row->outcome && ( ! $is_unchanged_row || $retry_relations ) ) {
						try {
							$this->upserter->assign_relations( $row->wc_id, $row->product );
						} catch ( Throwable $e ) {
							$this->logger->warning(
								'Relations assignment failed',
								[
									'wc_id' => $row->wc_id,
									'error' => $e->getMessage(),
								]
							);
						}
					}

					$queue->mark_phase_completed( $row->id, 5 );
					self::free_wpdb_memory();
					wp_cache_flush();
					++$rel_i;

					if ( 0 === $rel_i % 50 || $rel_i === $total ) {
						Skwirrel_WC_Sync_History::update_phase_progress(
							Skwirrel_WC_Sync_History::PHASE_RELATIONS,
							$rel_i,
							$total,
							__( 'Linking related products…', 'skwirrel-pim-sync' )
						);
						$this->check_abort();
					}
				}
			}

			// Clean up queue — all products processed
			$queue->cleanup();

			// =====================================================================
			$this->check_abort();
			// Phase 6: Cleanup — purge stale, persist history
			// =====================================================================
			Skwirrel_WC_Sync_History::update_phase_progress(
				Skwirrel_WC_Sync_History::PHASE_CLEANUP,
				0,
				1,
				__( 'Cleaning up…', 'skwirrel-pim-sync' )
			);

			$trashed            = 0;
			$categories_removed = 0;
			if ( ! empty( $options['purge_stale_products'] ) ) {
				if ( $delta ) {
					$this->logger->verbose( 'Purge skipped: delta sync (only during full sync)' );
				} else {
					$trashed = $this->purge_handler->purge_stale_products( $sync_started_at, $this->mapper );
					if ( ! empty( $options['sync_categories'] ) ) {
						$categories_removed = $this->purge_handler->purge_stale_categories( $this->category_sync->get_seen_category_ids() );
					}
				}
			}

			// Advance the delta checkpoint only now that the whole run has provably completed.
			// Stamp it with the run *start* time so products changed upstream *during* this run
			// are still caught by the next delta. A crash before this point leaves last_sync
			// untouched → the next run re-pulls and idempotently re-commits (no silent skip).
			//
			// If any product committed only partially, HOLD the checkpoint. Clearing that row's
			// `_skwirrel_updated_on` alone wouldn't rescue a delta run — the API delta filter
			// (`product_updated_on >= last_sync`) would never return a row whose upstream timestamp
			// didn't change. Leaving last_sync put makes the next delta re-pull the changed set: the
			// partial row reprocesses (gate sees its cleared timestamp) while the rest are skipped
			// cheaply as unchanged. Persistent failures keep re-pulling — surfaced via this warning.
			//
			// The settings signature is held on the SAME condition. If a settings-change full pass
			// commits a product only partially, persisting the new signature here would re-enable the
			// gate next run and skip that product as 'unchanged' — leaving the new settings unapplied.
			// Holding both means the next run sees a signature mismatch, stays in full-pass mode, and
			// finishes applying the change before the gate re-engages.
			if ( $partial_commit ) {
				$this->logger->warning(
					'Holding delta checkpoint + settings signature: at least one product committed only partially (an aspect failed); it will be retried on the next run.'
				);
			} else {
				update_option( Skwirrel_WC_Sync_History::OPTION_LAST_SYNC, gmdate( 'Y-m-d\TH:i:s\Z', $sync_started_at ) );
				update_option( 'skwirrel_wc_sync_last_sync_sig', $sync_sig );
			}
			Skwirrel_WC_Sync_History::update_last_result( true, $created, $updated, $failed, '', $with_attrs, $without_attrs, $trashed, $categories_removed, $trigger, $log_filename, $unchanged );

			$this->logger->info(
				'Sync completed',
				[
					'created'            => $created,
					'updated'            => $updated,
					'unchanged'          => $unchanged,
					'failed'             => $failed,
					'trashed'            => $trashed,
					'categories_removed' => $categories_removed,
					'with_attributes'    => $with_attrs,
					'without_attributes' => $without_attrs,
				]
			);

			return [
				'success'            => true,
				'created'            => $created,
				'updated'            => $updated,
				'unchanged'          => $unchanged,
				'failed'             => $failed,
				'trashed'            => $trashed,
				'categories_removed' => $categories_removed,
			];

		} catch ( \RuntimeException $e ) {
			// Abort requested by user — record as a non-error cancellation.
			Skwirrel_WC_Sync_History::update_last_result( false, $created, $updated, $failed, $e->getMessage(), 0, 0, 0, 0, $trigger, $log_filename, $unchanged );
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'created' => $created,
				'updated' => $updated,
				'failed'  => $failed,
			];
		} catch ( \Throwable $e ) {
			// Any other failure (type error, OOM-adjacent error, third-party
			// hook throwing, …). Without this catch it would propagate past
			// the queue cleanup and leave this run's rows orphaned.
			$this->logger->error( 'Sync failed with an unexpected error', [ 'error' => $e->getMessage() ] );
			Skwirrel_WC_Sync_History::update_last_result( false, $created, $updated, $failed, $e->getMessage(), 0, 0, 0, 0, $trigger, $log_filename, $unchanged );
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'created' => $created,
				'updated' => $updated,
				'failed'  => $failed,
			];
		} finally {
			$shutdown_registered = false; // Disable shutdown handler — sync completed.
			// Single cleanup point for this run's rows. Idempotent: the success
			// and per-error paths above may already have cleaned up, in which
			// case this deletes nothing. Covers every thrown-exception path,
			// including the RuntimeException/Throwable catches above.
			if ( isset( $queue ) ) {
				$queue->cleanup();
			}
			$this->logger->stop_sync_log();
			// Belt-and-braces release — covers the config-error early returns
			// that bypass update_last_result(). delete_transient is idempotent.
			Skwirrel_WC_Sync_History::release_sync_mutex();
		}
	}

	/**
	 * Upsert single product. Delegates to ProductUpserter.
	 *
	 * @param array $product Skwirrel product data.
	 * @return string 'created'|'updated'|'skipped'
	 */
	public function upsert_product( array $product ): string {
		return $this->upserter->upsert_product( $product );
	}

	/**
	 * Sync a single product by its Skwirrel product_id.
	 *
	 * Fetches the product from the API using getProducts with a product_ids filter,
	 * then upserts it into WooCommerce including categories, brands, and attributes.
	 *
	 * @param int $skwirrel_product_id Skwirrel product_id.
	 * @return array{success: bool, outcome?: string, error?: string}
	 */
	public function sync_single_product( int $skwirrel_product_id ): array {
		$client = $this->get_client();
		if ( ! $client ) {
			return [
				'success' => false,
				'error'   => 'Invalid API configuration',
			];
		}

		$options     = $this->get_options();
		$req_options = [
			'include_product_status'       => true,
			'include_product_translations' => true,
			'include_attachments'          => true,
			'include_trade_items'          => true,
			'include_trade_item_prices'    => true,
			'include_categories'           => ! empty( $options['sync_categories'] ),
			'include_product_groups'       => ! empty( $options['sync_categories'] ) || ! empty( $options['sync_grouped_products'] ),
			'include_grouped_products'     => ! empty( $options['sync_grouped_products'] ),
			'include_related_products'     => ! empty( $options['sync_related_products'] ),
			'include_etim'                 => true,
			'include_etim_translations'    => true,
			'include_languages'            => $this->get_include_languages(),
			'include_contexts'             => [ 1 ],
		];

		$sync_cc              = ! empty( $options['sync_custom_classes'] );
		$sync_ti_cc           = ! empty( $options['sync_trade_item_custom_classes'] );
		$custom_collection_id = $options['custom_collection_id'] ?? '';
		if ( $sync_cc ) {
			$req_options['include_custom_classes']       = true;
			$req_options['include_custom_collection_id'] = [ (int) $custom_collection_id ];
		}
		if ( $sync_ti_cc ) {
			$req_options['include_trade_item_custom_classes'] = true;
			$req_options['include_custom_collection_id']      = [ (int) $custom_collection_id ];
		}

		$this->logger->info(
			'Single product sync: fetching product from API',
			[ 'skwirrel_product_id' => $skwirrel_product_id ]
		);

		$result = $client->call(
			'getProductsByFilter',
			[
				'filter'  => [
					'code' => [
						'type'  => 'product_id',
						'codes' => [ (string) $skwirrel_product_id ],
					],
				],
				'options' => $req_options,
				'page'    => 1,
				'limit'   => 1,
			]
		);

		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			$this->logger->error(
				'Single product sync: API error',
				[
					'skwirrel_product_id' => $skwirrel_product_id,
					'error'               => $err,
				]
			);
			return [
				'success' => false,
				'error'   => $err['message'],
			];
		}

		$data     = $result['result'] ?? [];
		$products = $data['products'] ?? [];

		$this->logger->info(
			'Single product sync: API returned products',
			[
				'skwirrel_product_id' => $skwirrel_product_id,
				'products_returned'   => count( $products ),
			]
		);

		$product = $products[0] ?? null;

		if ( null === $product ) {
			return [
				'success' => false,
				'error'   => 'Product not found in Skwirrel API',
			];
		}

		// Log raw category data from API response for diagnostics.
		$this->logger->verbose(
			'Single product raw API _categories',
			[
				'product_id'      => $product['product_id'] ?? '?',
				'has__categories' => isset( $product['_categories'] ),
				'_categories'     => $product['_categories'] ?? null,
				'_product_groups' => $product['_product_groups'] ?? null,
			]
		);

		try {
			$outcome = $this->upserter->upsert_product( $product );

			// Assign related products if enabled.
			if ( ! empty( $options['sync_related_products'] ) && 'skipped' !== $outcome ) {
				$lookup = new Skwirrel_WC_Sync_Product_Lookup( $this->mapper );
				$wc_id  = $lookup->find_by_skwirrel_product_id( $skwirrel_product_id );
				if ( $wc_id ) {
					$this->upserter->assign_relations( $wc_id, $product );
				}
			}

			$this->logger->info(
				'Single product sync completed',
				[
					'skwirrel_product_id' => $skwirrel_product_id,
					'outcome'             => $outcome,
				]
			);

			return [
				'success' => true,
				'outcome' => $outcome,
			];
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Single product sync failed',
				[
					'skwirrel_product_id' => $skwirrel_product_id,
					'error'               => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Sync a single grouped product: find it in the API, update the variable product shell,
	 * and upsert all member products as variations.
	 *
	 * Paginates through getGroupedProducts to find the group definition, then fetches
	 * all member products via getProductsByFilter and upserts them as variations.
	 *
	 * @param int $grouped_product_id Skwirrel grouped_product_id.
	 * @return array{success: bool, created?: int, updated?: int, failed?: int, error?: string}
	 */
	public function sync_single_grouped_product( int $grouped_product_id ): array {
		$client = $this->get_client();
		if ( ! $client ) {
			return [
				'success' => false,
				'error'   => 'Invalid API configuration',
			];
		}

		$options = $this->get_options();

		$this->logger->info(
			'Single grouped product sync: searching for group in API',
			[ 'grouped_product_id' => $grouped_product_id ]
		);

		// Phase 1: Find the group definition by paginating through getGroupedProducts.
		$group      = null;
		$batch_size = (int) ( $options['batch_size'] ?? 10 );
		$page       = 1;
		$params     = [
			'include_products'          => true,
			'include_etim_features'     => true,
			'include_etim_translations' => true,
			'include_languages'         => $this->get_include_languages(),
		];

		do {
			$params['page']  = $page;
			$params['limit'] = $batch_size;
			$result          = $client->call( 'getGroupedProducts', $params );

			if ( ! $result['success'] ) {
				$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
				$this->logger->error(
					'Single grouped product sync: getGroupedProducts failed',
					[
						'grouped_product_id' => $grouped_product_id,
						'error'              => $err,
					]
				);
				return [
					'success' => false,
					'error'   => $err['message'],
				];
			}

			$data        = $result['result'] ?? [];
			$groups      = $data['grouped_products'] ?? $data['groups'] ?? $data['products'] ?? [];
			$page_info   = $data['page'] ?? [];
			$total_pages = (int) ( $page_info['number_of_pages'] ?? 1 );
			unset( $result, $data, $page_info );

			if ( is_array( $groups ) ) {
				foreach ( $groups as $g ) {
					$gid = $g['grouped_product_id'] ?? $g['id'] ?? null;
					if ( null !== $gid && (int) $gid === $grouped_product_id ) {
						$group = $g;
						break 2;
					}
				}
			}

			if ( $page >= $total_pages ) {
				break;
			}
			++$page;
		} while ( true );

		if ( null === $group ) {
			return [
				'success' => false,
				'error'   => 'Grouped product not found in Skwirrel API',
			];
		}

		$this->logger->info(
			'Single grouped product sync: group found, updating variable product shell',
			[ 'grouped_product_id' => $grouped_product_id ]
		);

		// Phase 2: Update the variable product shell.
		$product_to_group_map = [];
		try {
			$this->upserter->create_variable_product_from_group( $group, $product_to_group_map );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Single grouped product sync: variable product creation failed',
				[
					'grouped_product_id' => $grouped_product_id,
					'error'              => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}

		// Phase 3: Collect member product IDs and fetch them from the API.
		$member_product_ids = [];
		$virtual_product_id = $group['virtual_product_id'] ?? null;
		$members            = $group['_products'] ?? $group['products'] ?? [];
		foreach ( $members as $item ) {
			$pid = is_array( $item ) ? ( $item['product_id'] ?? null ) : (int) $item;
			if ( null !== $pid ) {
				$member_product_ids[] = (string) $pid;
			}
		}

		// Include virtual product in fetch if present.
		if ( $virtual_product_id && ! in_array( (string) $virtual_product_id, $member_product_ids, true ) ) {
			$member_product_ids[] = (string) $virtual_product_id;
		}

		if ( empty( $member_product_ids ) ) {
			$this->logger->info(
				'Single grouped product sync: no member products in group',
				[ 'grouped_product_id' => $grouped_product_id ]
			);
			return [
				'success' => true,
				'created' => 0,
				'updated' => 0,
				'failed'  => 0,
			];
		}

		$req_options = [
			'include_product_status'       => true,
			'include_product_translations' => true,
			'include_attachments'          => true,
			'include_trade_items'          => true,
			'include_trade_item_prices'    => true,
			'include_categories'           => ! empty( $options['sync_categories'] ),
			'include_product_groups'       => ! empty( $options['sync_categories'] ) || ! empty( $options['sync_grouped_products'] ),
			'include_grouped_products'     => true,
			'include_related_products'     => ! empty( $options['sync_related_products'] ),
			'include_etim'                 => true,
			'include_etim_translations'    => true,
			'include_languages'            => $this->get_include_languages(),
			'include_contexts'             => [ 1 ],
		];

		$sync_cc              = ! empty( $options['sync_custom_classes'] );
		$sync_ti_cc           = ! empty( $options['sync_trade_item_custom_classes'] );
		$custom_collection_id = $options['custom_collection_id'] ?? '';
		if ( $sync_cc ) {
			$req_options['include_custom_classes']       = true;
			$req_options['include_custom_collection_id'] = [ (int) $custom_collection_id ];
		}
		if ( $sync_ti_cc ) {
			$req_options['include_trade_item_custom_classes'] = true;
			$req_options['include_custom_collection_id']      = [ (int) $custom_collection_id ];
		}

		$this->logger->info(
			'Single grouped product sync: fetching member products',
			[
				'grouped_product_id' => $grouped_product_id,
				'member_count'       => count( $member_product_ids ),
			]
		);

		$result = $client->call(
			'getProductsByFilter',
			[
				'filter'  => [
					'code' => [
						'type'  => 'product_id',
						'codes' => $member_product_ids,
					],
				],
				'options' => $req_options,
				'page'    => 1,
				'limit'   => count( $member_product_ids ),
			]
		);

		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			$this->logger->error(
				'Single grouped product sync: member products fetch failed',
				[
					'grouped_product_id' => $grouped_product_id,
					'error'              => $err,
				]
			);
			return [
				'success' => false,
				'error'   => $err['message'],
			];
		}

		$products = $result['result']['products'] ?? [];
		unset( $result );

		// Phase 4: Upsert each member product as a variation.
		$created = 0;
		$updated = 0;
		$failed  = 0;

		foreach ( $products as $product ) {
			$skwirrel_product_id = $product['product_id'] ?? $product['id'] ?? null;

			// Handle virtual product: apply content & media to the parent variable product.
			if ( null !== $skwirrel_product_id && null !== $virtual_product_id
				&& (int) $skwirrel_product_id === (int) $virtual_product_id
			) {
				$virtual_info = $product_to_group_map[ 'virtual:' . (int) $virtual_product_id ] ?? null;
				if ( $virtual_info && ! empty( $virtual_info['wc_variable_id'] ) ) {
					try {
						if ( ! empty( $options['use_virtual_product_content'] ) ) {
							$this->upserter->apply_virtual_product_content( (int) $virtual_info['wc_variable_id'], $product );
						}
						$this->upserter->assign_media( (int) $virtual_info['wc_variable_id'], $product );
					} catch ( Throwable $e ) {
						$this->logger->warning(
							'Single grouped product sync: virtual product processing failed',
							[ 'error' => $e->getMessage() ]
						);
					}
				}
				continue;
			}

			// Look up group_info for this product.
			$group_info = null;
			if ( null !== $skwirrel_product_id ) {
				$group_info = $product_to_group_map[ (int) $skwirrel_product_id ] ?? null;
			}
			if ( ! $group_info ) {
				$sku = (string) ( $product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? '' );
				if ( '' !== $sku ) {
					$group_info = $product_to_group_map[ 'sku:' . $sku ] ?? null;
				}
			}

			if ( ! $group_info ) {
				$this->logger->warning(
					'Single grouped product sync: product not in group map, skipping',
					[ 'product_id' => $skwirrel_product_id ]
				);
				++$failed;
				continue;
			}

			try {
				$result_item = $this->upserter->create_or_update_variation( $product, $group_info );
				$wc_id       = $result_item['wc_id'];
				$outcome     = $result_item['outcome'];

				if ( 'created' === $outcome ) {
					++$created;
				} elseif ( 'updated' === $outcome ) {
					++$updated;
				}

				// Taxonomy: assign to parent variable product.
				$tax_target = $group_info['wc_variable_id'] ?? $wc_id;
				$this->upserter->assign_taxonomy( $tax_target, $product );

				// Attributes.
				$attr_includes = [];
				if ( ! empty( $options['sync_custom_classes'] ) ) {
					$attr_includes['include_custom_classes']       = true;
					$attr_includes['include_custom_collection_id'] = [ (int) $custom_collection_id ];
				}
				if ( ! empty( $options['sync_trade_item_custom_classes'] ) ) {
					$attr_includes['include_trade_item_custom_classes'] = true;
					$attr_includes['include_custom_collection_id']      = [ (int) $custom_collection_id ];
				}
				if ( ! empty( $attr_includes ) ) {
					$attr_includes['include_etim']              = true;
					$attr_includes['include_etim_translations'] = true;
					$attr_includes['include_languages']         = $this->get_include_languages();
					$attr_includes['include_contexts']          = [ 1 ];
					$attr_product                               = $this->fetch_product_attributes( $client, $product, $attr_includes );
				} else {
					$attr_product = $product;
				}

				/** This action is documented in the Phase 3 attribute loop of run_sync(). */
				do_action( 'skwirrel_wc_sync_after_attributes_fetched', $wc_id, $attr_product, $group_info );

				$this->upserter->assign_attributes( $wc_id, $attr_product, $group_info );

				// Media.
				$this->upserter->assign_media( $wc_id, $product );

				// Relations.
				if ( ! empty( $options['sync_related_products'] ) ) {
					$this->upserter->assign_relations( $wc_id, $product );
				}
			} catch ( Throwable $e ) {
				++$failed;
				$this->logger->error(
					'Single grouped product sync: variation upsert failed',
					[
						'product_id' => $skwirrel_product_id,
						'error'      => $e->getMessage(),
					]
				);
			}
		}

		// Flush deferred parent attribute terms.
		$this->upserter->flush_parent_attribute_terms();

		$this->logger->info(
			'Single grouped product sync completed',
			[
				'grouped_product_id' => $grouped_product_id,
				'created'            => $created,
				'updated'            => $updated,
				'failed'             => $failed,
			]
		);

		return [
			'success' => true,
			'created' => $created,
			'updated' => $updated,
			'failed'  => $failed,
		];
	}

	private function get_client(): ?Skwirrel_WC_Sync_JsonRpc_Client {
		$opts  = $this->get_options();
		$url   = $opts['endpoint_url'] ?? '';
		$auth  = $opts['auth_type'] ?? 'bearer';
		$token = Skwirrel_WC_Sync_Admin_Settings::get_auth_token();
		if ( empty( $url ) || empty( $token ) ) {
			return null;
		}
		return new Skwirrel_WC_Sync_JsonRpc_Client(
			$url,
			$auth,
			$token,
			(int) ( $opts['timeout'] ?? 30 ),
			(int) ( $opts['retries'] ?? 2 )
		);
	}

	/**
	 * Fetch a page of products from the API.
	 *
	 * Uses getProducts (faster) for full sync or getProductsByFilter when a filter is needed.
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client       API client.
	 * @param bool                            $use_filter   Whether to use getProductsByFilter.
	 * @param array                           $filter       Filter params for getProductsByFilter.
	 * @param array                           $api_includes Include flags.
	 * @param int                             $batch_size   Products per page.
	 * @param int                             $page         Page number.
	 * @return array API result array.
	 */
	private function fetch_products_page(
		Skwirrel_WC_Sync_JsonRpc_Client $client,
		bool $use_filter,
		array $filter,
		array $api_includes,
		int $batch_size,
		int $page
	): array {
		if ( $use_filter ) {
			return $client->call(
				'getProductsByFilter',
				[
					'filter'  => $filter,
					'options' => $api_includes,
					'page'    => $page,
					'limit'   => $batch_size,
				]
			);
		}

		// Full sync: use getProducts with include flags as top-level params (faster API endpoint).
		return $client->call(
			'getProducts',
			array_merge(
				$api_includes,
				[
					'page'  => $page,
					'limit' => $batch_size,
				]
			)
		);
	}

	private function get_options(): array {
		$defaults = [
			'endpoint_url'          => '',
			'auth_type'             => 'bearer',
			'auth_token'            => '',
			'timeout'               => 30,
			'retries'               => 2,
			'batch_size'            => 10,
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
	 * Build a signature of the settings that affect sync OUTPUT (plus the plugin version).
	 *
	 * When this signature differs from the one stored at the end of the previous run, the change
	 * gate is disabled so every product reprocesses once — otherwise a settings change (e.g. turning
	 * on categories) would be silently skipped for products whose `product_updated_on` is unchanged.
	 *
	 * @param array<string, mixed> $options Plugin settings.
	 * @return string md5 signature.
	 */
	private function compute_sync_signature( array $options ): string {
		// Denylist, not allowlist: hash EVERY setting except those that demonstrably do not change
		// what gets synced (connection/auth, performance, logging, scheduling). This way a newly-added
		// output setting is covered automatically instead of slipping past the gate until someone
		// remembers to allowlist it. NB: collection_ids IS included — a selection change must force a
		// full pass so products newly in scope (but unchanged upstream) are still fetched + imported.
		$ignore   = array_flip(
			[
				'endpoint_url',
				'auth_type',
				'auth_token',
				'timeout',
				'retries',
				'batch_size',
				'sync_interval',
				'verbose_logging',
				'log_retention',
				'log_mode_manual',
				'log_mode_scheduled',
				'show_delete_warning',
			]
		);
		$relevant = array_diff_key( $options, $ignore );
		ksort( $relevant );
		// Slug/permalink settings live in a separate option but also affect output (e.g.
		// update_slug_on_resync), so a change there must likewise force a full reprocess.
		$relevant['__permalinks'] = get_option( 'skwirrel_wc_sync_permalinks', [] );
		$relevant['__version']    = defined( 'SKWIRREL_WC_SYNC_VERSION' ) ? SKWIRREL_WC_SYNC_VERSION : '';
		return md5( (string) wp_json_encode( $relevant ) );
	}

	/**
	 * Get collection IDs from settings. Returns array of int IDs, or empty array for "sync all".
	 */
	private function get_collection_ids(): array {
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		$raw  = $opts['collection_ids'] ?? '';
		if ( '' === $raw || ! is_string( $raw ) ) {
			return [];
		}
		$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_map( 'intval', array_filter( $parts, 'is_numeric' ) ) );
	}

	private function get_include_languages(): array {
		$opts  = get_option( 'skwirrel_wc_sync_settings', [] );
		$langs = $opts['include_languages'] ?? [ 'nl-NL', 'nl' ];
		if ( ! empty( $langs ) && is_array( $langs ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $langs ) ) );
		}
		return [ 'nl-NL', 'nl' ];
	}

	/**
	 * Check if the user requested an abort. Throws RuntimeException to exit the sync.
	 *
	 * @throws \RuntimeException When abort is requested.
	 */
	/**
	 * Re-fetch a single product with attribute includes (ETIM + custom classes).
	 *
	 * Returns the original product array merged with the freshly fetched attribute data.
	 * If the API call fails, returns the original product as-is (attributes will be empty).
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client          API client.
	 * @param array                           $product         Original product data (from queue).
	 * @param array                           $attr_includes   Attribute-specific include flags.
	 * @return array Product array with attribute data merged in.
	 */
	private function fetch_product_attributes( Skwirrel_WC_Sync_JsonRpc_Client $client, array $product, array $attr_includes, bool &$ok = true ): array {
		$ok         = true;
		$product_id = $product['product_id'] ?? $product['id'] ?? null;
		if ( null === $product_id ) {
			return $product;
		}

		$result = $client->call(
			'getProductsByFilter',
			[
				'filter'  => [
					'code' => [
						'type'  => 'product_id',
						'codes' => [ (string) $product_id ],
					],
				],
				'options' => $attr_includes,
				'page'    => 1,
				'limit'   => 1,
			]
		);

		if ( ! $result['success'] ) {
			$ok = false;
			$this->logger->warning( 'Attribute fetch failed for product', [ 'product_id' => $product_id ] );
			return $product;
		}

		$fetched = $result['result']['products'][0] ?? null;
		unset( $result );

		if ( null === $fetched ) {
			$ok = false;
			return $product;
		}

		// Merge attribute fields into the original product.
		if ( isset( $fetched['_etim'] ) ) {
			$product['_etim'] = $fetched['_etim'];
		}
		if ( isset( $fetched['_custom_classes'] ) ) {
			$product['_custom_classes'] = $fetched['_custom_classes'];
		}
		if ( isset( $fetched['_trade_items'] ) ) {
			// Trade item custom classes are nested inside _trade_items.
			foreach ( $fetched['_trade_items'] as $i => $ti ) {
				if ( isset( $ti['_custom_classes'] ) && isset( $product['_trade_items'][ $i ] ) ) {
					$product['_trade_items'][ $i ]['_custom_classes'] = $ti['_custom_classes'];
				}
			}
		}
		unset( $fetched );

		return $product;
	}

	private function check_abort(): void {
		Skwirrel_WC_Sync_History::sync_heartbeat();
		if ( Skwirrel_WC_Sync_History::is_abort_requested() ) {
			$this->logger->info( 'Sync aborted by user' );
			throw new \RuntimeException( 'Sync aborted by user' );
		}
	}

	/**
	 * Free accumulated wpdb memory between operations.
	 *
	 * Clears $wpdb->queries[] (which stores every query when SAVEQUERIES is on)
	 * and flushes last_result/last_query/col_info to reclaim memory.
	 */
	private static function free_wpdb_memory(): void {
		global $wpdb;
		$wpdb->queries = [];
		$wpdb->flush();
	}
}
