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

	/** Run-state option (autoload off): the resumable state machine's persisted context. */
	public const OPTION_RUN_STATE = 'skwirrel_wc_sync_run_state';

	/** Option (autoload off) holding the product→group map for the active run (only used in the fetch step). */
	private const OPTION_GROUP_MAP = 'skwirrel_wc_sync_run_groupmap';

	/** Default wall-clock budget (seconds) for a single batched step before it yields to the next action. */
	private const DEFAULT_STEP_SECONDS = 20;

	/** Consecutive no-progress step actions tolerated before a run is declared failed (poison-loop guard). */
	private const MAX_STALL = 6;

	/**
	 * Run sync to completion in the current PHP process (synchronous driver).
	 *
	 * Kept as the public entry point for backwards compatibility and for environments
	 * where Action Scheduler is unavailable (or WP-CLI). It initialises the resumable
	 * state machine and drives every step to completion in-process. The same step
	 * methods are used by the Action Scheduler path (one step per async action), so the
	 * sequencing logic lives in exactly one place (run_step()).
	 *
	 * @param bool   $delta   Use delta sync (updated_on >= last sync) if possible.
	 * @param string $trigger What initiated the sync: 'manual' or 'scheduled'.
	 * @return array{success: bool, created: int, updated: int, failed: int, error?: string}
	 */
	public function run_sync( bool $delta = false, string $trigger = Skwirrel_WC_Sync_History::TRIGGER_MANUAL ): array {
		$begin = $this->begin_run( $delta, $trigger );
		if ( ! $begin['ok'] ) {
			return $begin['result'];
		}
		$ctx = $begin['ctx'];

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running sync requires no time limit; @ guards against disable_functions
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		// Drive every step to completion. A huge per-step deadline means each step runs
		// in full here (no yielding); the loop still needs multiple iterations because
		// each step returns 'continue' at its phase transition.
		$status = 'continue';
		while ( 'continue' === $status ) {
			try {
				$status = $this->run_step( $ctx, microtime( true ) + 3600 );
			} catch ( \RuntimeException $e ) {
				// User-requested abort.
				$this->fail_run( $ctx, $e->getMessage() );
				return $this->result_from_ctx( $ctx, false, $e->getMessage() );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Sync failed with an unexpected error', [ 'error' => $e->getMessage() ] );
				$this->fail_run( $ctx, $e->getMessage() );
				return $this->result_from_ctx( $ctx, false, $e->getMessage() );
			}
			// Persist only while more work remains. On a terminal step the run state was already
			// cleared by finalize/fail_run — re-saving here would resurrect a stale 'done' run.
			if ( 'continue' === $status ) {
				self::save_run_state( $ctx );
			}
		}

		return $this->result_from_ctx( $ctx, 'done' === $status );
	}

	/**
	 * Initialise a new run: validate configuration, compute the change-gate signature,
	 * build API include flags, prepare the queue, and persist the initial state.
	 *
	 * Does NOT perform any pre-sync API work — that happens in the (re-entrant) 'init'
	 * step so it benefits from the timeout-resilient batched model.
	 *
	 * @return array{ok: bool, ctx?: array, result?: array}
	 */
	private function begin_run( bool $delta, string $trigger ): array {
		// Concurrency guard for run start: refuse a second run while another's heartbeat is fresh.
		if ( ! Skwirrel_WC_Sync_History::acquire_sync_mutex() ) {
			return [
				'ok'     => false,
				'result' => [
					'success' => false,
					'error'   => __( 'Another sync is already running; refusing to start a second concurrent run.', 'skwirrel-pim-sync' ),
					'created' => 0,
					'updated' => 0,
					'failed'  => 0,
				],
			];
		}

		$this->category_sync->reset_seen_category_ids();
		Skwirrel_WC_Sync_History::clear_abort();
		Skwirrel_WC_Sync_History::sync_heartbeat();
		Skwirrel_WC_Sync_History::clear_sync_progress();

		$options = $this->get_options();
		Skwirrel_WC_Sync_Logger::cleanup_old_logs( $options['log_retention'] ?? '7days' );
		$log_mode     = Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED === $trigger
			? ( $options['log_mode_scheduled'] ?? 'per_day' )
			: ( $options['log_mode_manual'] ?? 'per_sync' );
		$log_filename = $this->logger->start_sync_log( $trigger, $log_mode );

		$this->logger->info( 'Sync memory baseline', [ 'memory_mb' => round( memory_get_usage( true ) / 1048576, 1 ) ] );

		$client = $this->get_client();
		if ( ! $client ) {
			return $this->begin_fail( 'Invalid configuration', $trigger, $log_filename );
		}

		$delta_since = get_option( Skwirrel_WC_Sync_History::OPTION_LAST_SYNC, '' );

		// Change gate: skip products whose `product_updated_on` has not advanced — but only when
		// output-affecting settings (and plugin version) are unchanged.
		$sync_sig     = $this->compute_sync_signature( $options );
		$gate_enabled = (
			'' !== $sync_sig
			&& get_option( 'skwirrel_wc_sync_last_sync_sig', '' ) === $sync_sig
			&& ! get_option( 'skwirrel_wc_sync_slug_resync_needed' )
		);
		$this->logger->info(
			'Change gate',
			[
				'enabled' => $gate_enabled,
				'reason'  => $gate_enabled ? 'settings unchanged — unchanged products will be skipped' : 'first run or settings changed — full reprocess',
			]
		);

		// Content-hash change detection mode for this run. Default 'observe' (compute + report match
		// rates, no behavior change) so it can be validated on staging before flipping to 'enforce'
		// (skip on hash match). Override via the `skwirrel_wc_sync_content_hash_mode` filter or the
		// `content_hash_mode` setting. Stable for the whole run (steps read it from ctx).
		$hash_mode = (string) ( $options['content_hash_mode'] ?? 'observe' );
		$hash_mode = (string) apply_filters( 'skwirrel_wc_sync_content_hash_mode', $hash_mode );
		if ( ! in_array( $hash_mode, [ 'off', 'observe', 'enforce' ], true ) ) {
			$hash_mode = 'observe';
		}

		// Crash-safety: invalidate the stored signature for the DURATION of this run, so an
		// interrupted run forces the next one into a full reprocess. A clean finalize re-stamps it.
		update_option( 'skwirrel_wc_sync_last_sync_sig', '' );

		// Delta filter: only when delta is requested, a checkpoint exists, AND the change gate is on
		// (settings unchanged). Otherwise fetch the full selection so new settings — or the first
		// delta run — re-apply to every product (the gate then skips unchanged ones during commit).
		$fetch_filter = [];
		if ( $delta && '' !== $delta_since && $gate_enabled ) {
			$fetch_filter['updated_on'] = [
				'datetime' => $delta_since,
				'operator' => '>=',
			];
		} elseif ( $delta && '' !== $delta_since && ! $gate_enabled ) {
			$this->logger->info( 'Settings/version change detected — running this delta as a full pass so the new settings apply to every product.' );
		} elseif ( $delta && '' === $delta_since ) {
			$this->logger->info( 'Delta sync requested but no checkpoint exists — running as initial full pass; last_sync will be seeded on completion.' );
		}

		$collection_ids = $this->get_collection_ids();
		if ( empty( $collection_ids ) ) {
			return $this->begin_fail( 'No selection IDs configured. A selection ID is required.', $trigger, $log_filename );
		}

		$custom_collection_id = $options['custom_collection_id'] ?? '';
		if ( empty( $custom_collection_id ) ) {
			return $this->begin_fail( 'No custom class collection ID configured. This field is required.', $trigger, $log_filename );
		}
		if ( ! empty( $options['sync_categories'] ) ) {
			$super_cat_id = (int) ( $options['super_category_id'] ?? 0 );
			if ( $super_cat_id <= 0 ) {
				return $this->begin_fail( 'Category sync is enabled but no super category ID configured. A super category ID greater than 0 is required.', $trigger, $log_filename );
			}
		}

		$batch_size = (int) ( $options['batch_size'] ?? 10 );

		// Build API include flags — keep the fetch lightweight (no ETIM/custom classes).
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

		$sync_cc       = ! empty( $options['sync_custom_classes'] );
		$sync_ti_cc    = ! empty( $options['sync_trade_item_custom_classes'] );
		$cc_filter_mode = '';
		$cc_raw         = '';
		$cc_parsed      = [];
		if ( $sync_cc || $sync_ti_cc ) {
			$api_includes['include_custom_collection_id'] = [ (int) $custom_collection_id ];
			$cc_filter_mode                               = $options['custom_class_filter_mode'] ?? '';
			$cc_raw                                       = $options['custom_class_filter_ids'] ?? '';
			$cc_parsed                                    = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_raw );
		}
		if ( $sync_cc ) {
			$api_includes['include_custom_classes'] = true;
			if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
				$api_includes['include_custom_class_id'] = $cc_parsed['ids'];
			}
		}
		if ( $sync_ti_cc ) {
			$api_includes['include_trade_item_custom_classes'] = true;
			if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
				$api_includes['include_trade_item_custom_class_id'] = $cc_parsed['ids'];
			}
		}

		// Attributes (ETIM + custom classes) are fetched IN the paginated batch call, not per product.
		// The old design fetched them one product at a time (1 + N API round-trips) to avoid holding a
		// fully-included catalogue in memory — but the DB queue now caps memory at one page at a time,
		// so including them in the page fetch is safe and turns N+1 calls into ~N/batch_size calls.
		$api_includes['include_etim']              = true;
		$api_includes['include_etim_translations'] = true;
		if ( ! empty( $options['sync_grouped_products'] ) ) {
			// Grouped products may use custom features as variation axes — ensure they are present even
			// when neither custom-class sync toggle is on.
			$api_includes['include_custom_classes']       = true;
			$api_includes['include_custom_collection_id'] = [ (int) $custom_collection_id ];
		}

		// Ensure queue table exists and sweep rows left by previous (dead) runs. Safe here:
		// begin_run only runs at a fresh start (the caller verified no fresh run holds the
		// mutex), so anything in the table belongs to an interrupted run and is dead.
		if ( ! Skwirrel_WC_Sync_Queue::table_exists() ) {
			Skwirrel_WC_Sync_Queue::create_table();
		}
		$sync_run_id = wp_generate_uuid4();
		$orphans     = ( new Skwirrel_WC_Sync_Queue( $sync_run_id ) )->cleanup_orphans();
		if ( $orphans > 0 ) {
			$this->logger->warning( 'Removed orphaned queue rows from previous interrupted sync run(s)', [ 'rows' => $orphans ] );
		}

		self::clear_group_map();

		$ctx = [
			'run_id'           => $sync_run_id,
			'step'             => 'init',
			'delta'            => $delta,
			'trigger'          => $trigger,
			'started_at'       => time(),
			'sync_sig'         => $sync_sig,
			'gate_enabled'     => $gate_enabled,
			'log_file'         => $log_filename,
			'options'          => $options,
			'api_includes'     => $api_includes,
			'batch_size'       => $batch_size,
			'collection_ids'   => $collection_ids,
			'fetch_filter'     => $fetch_filter,
			'sel_index'        => 0,
			'page'             => 1,
			'fetched'          => 0,
			'total'            => 0,
			'virtual_total'    => 0,
			'processed'        => 0,
			'virtual_done'     => 0,
			'rel_done'         => 0,
			'created'          => 0,
			'updated'          => 0,
			'unchanged'        => 0,
			'failed'           => 0,
			'with_attrs'       => 0,
			'without_attrs'    => 0,
			'partial_commit'   => false,
			'seen_categories'  => [],
			'stall'            => 0,
			'last_progress'    => 0,
			'hash_mode'        => $hash_mode,
			'hash_match'       => 0,
			'hash_mismatch'    => 0,
			'hash_new'         => 0,
		];

		$this->logger->info(
			'Sync started',
			[
				'delta'          => $delta,
				'delta_since'    => $delta_since,
				'batch_size'     => $batch_size,
				'collection_ids' => $collection_ids,
				'run_id'         => $sync_run_id,
			]
		);

		self::save_run_state( $ctx );

		return [
			'ok'  => true,
			'ctx' => $ctx,
		];
	}

	/**
	 * Helper: record a configuration failure during begin_run and release the run.
	 *
	 * @return array{ok: false, result: array}
	 */
	private function begin_fail( string $message, string $trigger, string $log_filename ): array {
		$this->logger->error( 'Sync aborted: ' . $message );
		$this->logger->stop_sync_log();
		Skwirrel_WC_Sync_History::update_last_result( false, 0, 0, 0, $message, 0, 0, 0, 0, $trigger, $log_filename );
		Skwirrel_WC_Sync_History::release_sync_mutex();
		self::clear_run_state();
		return [
			'ok'     => false,
			'result' => [
				'success' => false,
				'error'   => $message,
				'created' => 0,
				'updated' => 0,
				'failed'  => 0,
			],
		];
	}

	/**
	 * Execute a single step of the resumable state machine.
	 *
	 * @param array $ctx      Run context (mutated in place; persisted by the caller).
	 * @param float $deadline microtime(true) value after which the step should yield.
	 * @return string 'continue' (more work — re-invoke), 'done', or 'failed'.
	 */
	public function run_step( array &$ctx, float $deadline ): string {
		// Re-attach to this run's log file (no-op on the synchronous path where it is already open).
		$this->logger->resume_sync_log( (string) ( $ctx['log_file'] ?? '' ) );
		// Apply the change gate for every step's upserter operations (grouped products, commits, …),
		// matching the pre-refactor behavior where it was set once up front.
		$this->upserter->set_change_gate_enabled( (bool) $ctx['gate_enabled'] );
		$this->upserter->set_content_hash_context( (string) ( $ctx['hash_mode'] ?? 'off' ), (string) ( $ctx['sync_sig'] ?? '' ) );
		Skwirrel_WC_Sync_History::sync_heartbeat();

		switch ( $ctx['step'] ) {
			case 'init':
				return $this->step_init( $ctx );
			case 'fetch':
				return $this->step_fetch( $ctx, $deadline );
			case 'process':
				return $this->step_process( $ctx, $deadline );
			case 'virtual':
				return $this->step_virtual( $ctx, $deadline );
			case 'relations':
				return $this->step_relations( $ctx, $deadline );
			case 'finalize':
				return $this->step_finalize( $ctx );
			default:
				return 'done';
		}
	}

	/**
	 * Step: pre-sync. Idempotent (re-entrant on resume) — category/brand/custom-class syncs are
	 * upserts and grouped-product shells are looked up by key, so re-running is safe.
	 */
	private function step_init( array &$ctx ): string {
		$client = $this->get_client();
		if ( ! $client ) {
			return $this->fail_run( $ctx, 'Invalid configuration' );
		}
		$options = $ctx['options'];

		self::free_wpdb_memory();
		wp_cache_flush();

		$this->check_abort();
		if ( ! empty( $options['sync_categories'] ) ) {
			$this->category_sync->sync_category_tree( $client, $options, $this->get_include_languages() );
		}
		$this->brand_sync->sync_all_brands( $client );
		if ( ! empty( $options['sync_custom_classes'] ) || ! empty( $options['sync_trade_item_custom_classes'] ) ) {
			$this->taxonomy_manager->sync_all_custom_classes( $client, $options, $this->get_include_languages() );
		}

		self::free_wpdb_memory();
		wp_cache_flush();

		$product_to_group_map = [];
		if ( ! empty( $options['sync_grouped_products'] ) ) {
			$grouped_result       = $this->upserter->sync_grouped_products_first( $client, $options, $ctx['collection_ids'] );
			$product_to_group_map = $grouped_result['map'];
			$ctx['created']      += $grouped_result['created'];
			$ctx['updated']      += $grouped_result['updated'];
		}
		self::save_group_map( $ctx['run_id'], $product_to_group_map );

		self::free_wpdb_memory();
		wp_cache_flush();
		$this->merge_seen_categories( $ctx );
		$this->logger->info( 'Pre-sync complete, starting fetch', [ 'memory_mb' => round( memory_get_usage( true ) / 1048576, 1 ) ] );

		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_FETCH,
			0,
			0,
			__( 'Fetching products from API…', 'skwirrel-pim-sync' )
		);

		$ctx['step'] = 'fetch';
		return 'continue';
	}

	/**
	 * Step: fetch products into the queue, resuming from the persisted selection/page cursor.
	 *
	 * One API call per configured selection ID (`dynamic_selection_id` is a single-int filter),
	 * paginated. Yields to the next action when the time budget is hit; the cursor
	 * (sel_index/page) is persisted so the next invocation continues seamlessly.
	 */
	private function step_fetch( array &$ctx, float $deadline ): string {
		$client = $this->get_client();
		if ( ! $client ) {
			return $this->fail_run( $ctx, 'Invalid configuration' );
		}
		$queue          = new Skwirrel_WC_Sync_Queue( $ctx['run_id'] );
		$group_map      = self::load_group_map( $ctx['run_id'] );
		$collection_ids = $ctx['collection_ids'];
		$batch_size     = (int) $ctx['batch_size'];

		while ( $ctx['sel_index'] < count( $collection_ids ) ) {
			$this->check_abort();
			$selection_id            = (int) $collection_ids[ $ctx['sel_index'] ];
			$filter                  = array_merge( (array) ( $ctx['fetch_filter'] ?? [] ), [ 'dynamic_selection_id' => $selection_id ] );
			$page                    = (int) $ctx['page'];

			$result = $this->fetch_products_page( $client, true, $filter, $ctx['api_includes'], $batch_size, $page );
			if ( ! $result['success'] ) {
				// Fail-fast on a partial fetch: continuing would let the purge step trash every
				// product on the un-fetched pages, and would advance last_sync past them.
				$err = $result['error'] ?? [ 'message' => 'Pagination failed' ];
				$msg = sprintf(
					/* translators: 1: API error message, 2: page number, 3: selection id */
					__( 'Fetch failed at page %2$d (selection %3$d): %1$s', 'skwirrel-pim-sync' ),
					(string) ( $err['message'] ?? 'API error' ),
					$page,
					$selection_id
				);
				$this->logger->error( 'Fetch failed; aborting sync', array_merge( (array) $err, [ 'selection_id' => $selection_id, 'page' => $page ] ) );
				return $this->fail_run( $ctx, $msg );
			}

			$products   = $result['result']['products'] ?? [];
			$page_count = count( $products );
			$this->logger->verbose( 'Fetching batch', [ 'selection_id' => $selection_id, 'page' => $page, 'count' => $page_count ] );

			foreach ( $products as $product ) {
				$skwirrel_product_id = $product['product_id'] ?? $product['id'] ?? null;

				$virtual_info = null;
				if ( null !== $skwirrel_product_id ) {
					$virtual_info = $group_map[ 'virtual:' . (int) $skwirrel_product_id ] ?? null;
				}
				if ( $virtual_info && ! empty( $virtual_info['is_virtual_for_variable'] ) ) {
					$queue->insert_virtual_item( $product, (int) $virtual_info['wc_variable_id'] );
					++$ctx['fetched'];
					continue;
				}

				if ( 'VIRTUAL' === ( $product['product_type'] ?? '' ) ) {
					continue;
				}

				$sku_for_lookup = (string) ( $product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? $this->mapper->get_sku( $product ) );
				$group_info     = null;
				if ( null !== $skwirrel_product_id && '' !== $skwirrel_product_id ) {
					$group_info = $group_map[ (int) $skwirrel_product_id ] ?? null;
				}
				if ( ! $group_info && '' !== $sku_for_lookup ) {
					$group_info = $group_map[ 'sku:' . $sku_for_lookup ] ?? null;
				}

				$queue->insert_item( $product, $group_info );
				++$ctx['fetched'];
			}

			unset( $products, $result );
			self::free_wpdb_memory();

			Skwirrel_WC_Sync_History::update_phase_progress(
				Skwirrel_WC_Sync_History::PHASE_FETCH,
				$ctx['fetched'],
				0,
				/* translators: %d = number of products fetched so far */
				sprintf( __( 'Fetching products from API… (%d found)', 'skwirrel-pim-sync' ), $ctx['fetched'] )
			);

			if ( $page_count < $batch_size ) {
				// This selection is exhausted — advance to the next one.
				++$ctx['sel_index'];
				$ctx['page'] = 1;
			} else {
				$ctx['page'] = $page + 1;
			}

			if ( microtime( true ) >= $deadline ) {
				return 'continue';
			}
		}

		// All selections fetched.
		if ( $ctx['delta'] && 0 === $ctx['fetched'] ) {
			$this->logger->info( 'Delta sync: no products updated since last sync (across all configured selections)' );
			// Clean no-op completion — restore the signature we invalidated at run start (the checkpoint
			// is intentionally left unchanged, matching the pre-refactor behavior).
			update_option( 'skwirrel_wc_sync_last_sync_sig', $ctx['sync_sig'] );
			Skwirrel_WC_Sync_History::update_last_result( true, 0, 0, 0, '', 0, 0, 0, 0, $ctx['trigger'], $ctx['log_file'], 0 );
			( new Skwirrel_WC_Sync_Queue( $ctx['run_id'] ) )->cleanup();
			$this->finish_run( $ctx );
			return 'done';
		}

		$ctx['total']         = $queue->count_items( false );
		$ctx['virtual_total'] = $queue->count_items( true );
		$this->logger->info( "Fetch complete: {$ctx['total']} products + {$ctx['virtual_total']} virtual items to process in phases" );

		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_PRODUCTS,
			0,
			$ctx['total'],
			__( 'Creating & syncing products…', 'skwirrel-pim-sync' )
		);

		$ctx['step'] = 'process';
		return 'continue';
	}

	/**
	 * Step: per-product commit (create/update + taxonomy + attributes + media), time-boxed.
	 *
	 * Each product is fully committed in ONE pass before moving to the next, so an interruption
	 * leaves only un-started products incomplete. Deferred parent attribute terms are flushed at
	 * the end of every action (not just the final one) because they accumulate on a per-process
	 * upserter instance and would otherwise be lost when the run spans multiple actions.
	 */
	private function step_process( array &$ctx, float $deadline ): string {
		$queue = new Skwirrel_WC_Sync_Queue( $ctx['run_id'] );

		$done = false;
		while ( microtime( true ) < $deadline ) {
			$row = $queue->get_next_for_phase( 4 );
			if ( null === $row ) {
				$done = true;
				break;
			}
			$this->commit_product_row( $row, $queue, $ctx );
			++$ctx['processed'];

			if ( 0 === $ctx['processed'] % 25 || $ctx['total'] === $ctx['processed'] ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_PRODUCTS,
					$ctx['processed'],
					$ctx['total'],
					__( 'Creating & syncing products…', 'skwirrel-pim-sync' )
				);
				$this->check_abort();
			}
		}

		$this->merge_seen_categories( $ctx );
		// Flush deferred parent attribute terms accumulated by THIS action's upserter instance.
		$this->upserter->flush_parent_attribute_terms();

		if ( $done ) {
			Skwirrel_WC_Sync_History::update_phase_progress(
				Skwirrel_WC_Sync_History::PHASE_PRODUCTS,
				$ctx['processed'],
				$ctx['total'],
				__( 'Creating & syncing products…', 'skwirrel-pim-sync' )
			);
			$ctx['step'] = 'virtual';
			if ( $ctx['virtual_total'] > 0 ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_MEDIA,
					0,
					$ctx['virtual_total'],
					__( 'Finalizing variable products…', 'skwirrel-pim-sync' )
				);
			}
		}
		return 'continue';
	}

	/**
	 * Commit one product (or variation) row: create/update, taxonomy, attributes, media,
	 * held-draft publish, change-gate stamp and per-product checkpoint. Mutates $ctx counters.
	 */
	private function commit_product_row( object $row, Skwirrel_WC_Sync_Queue $queue, array &$ctx ): void {
		$outcome         = 'skipped';
		$wc_id           = 0;
		$pending_publish = false;
		$aspect_failed   = false;
		$content_hash    = '';
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
			$content_hash    = (string) ( $result_item['content_hash'] ?? '' );

			// Content-hash match telemetry (validation signal — see step_finalize summary).
			$hash_status = $result_item['hash_status'] ?? 'na';
			if ( 'match' === $hash_status ) {
				++$ctx['hash_match'];
			} elseif ( 'mismatch' === $hash_status ) {
				++$ctx['hash_mismatch'];
			} elseif ( 'new' === $hash_status ) {
				++$ctx['hash_new'];
			}

			if ( 'created' === $outcome ) {
				++$ctx['created'];
			} elseif ( 'updated' === $outcome ) {
				++$ctx['updated'];
			} elseif ( 'unchanged' === $outcome ) {
				++$ctx['unchanged'];
			} else {
				++$ctx['failed'];
			}
		} catch ( Throwable $e ) {
			++$ctx['failed'];
			$outcome = 'failed';
			$this->logger->error(
				'Product create/update failed',
				[
					'product' => $row->product['internal_product_code'] ?? $row->product['product_id'] ?? '?',
					'error'   => $e->getMessage(),
				]
			);
		}

		$queue->update_after_phase1( $row->id, $wc_id, $outcome );

		if ( $wc_id && 'skipped' !== $outcome && 'unchanged' !== $outcome ) {
			// --- Taxonomy: categories, brands, manufacturers (parent for variations) ---
			try {
				$tax_target = $row->group_info['wc_variable_id'] ?? $wc_id;
				$this->upserter->assign_taxonomy( $tax_target, $row->product );
			} catch ( Throwable $e ) {
				$aspect_failed = true;
				$this->logger->warning( 'Taxonomy assignment failed', [ 'wc_id' => $wc_id, 'error' => $e->getMessage() ] );
			}

			// --- Attributes: ETIM + custom classes are already on the row (included in the batch
			// fetch), so assign directly — no per-product API round-trip. ---
			try {
				/** This action is documented in sync_single_grouped_product(). */
				do_action( 'skwirrel_wc_sync_after_attributes_fetched', $wc_id, $row->product, $row->group_info );

				$attr_count = $this->upserter->assign_attributes( $wc_id, $row->product, $row->group_info );
				if ( $attr_count > 0 ) {
					++$ctx['with_attrs'];
				} else {
					++$ctx['without_attrs'];
				}
			} catch ( Throwable $e ) {
				$aspect_failed = true;
				$this->logger->warning( 'Attribute assignment failed', [ 'wc_id' => $wc_id, 'error' => $e->getMessage() ] );
			}

			// --- Media: images, downloads, documents (slowest step) ---
			try {
				if ( ! $this->upserter->assign_media( $wc_id, $row->product ) ) {
					$aspect_failed = true;
				}
			} catch ( Throwable $e ) {
				$aspect_failed = true;
				$this->logger->warning( 'Media assignment failed', [ 'wc_id' => $wc_id, 'error' => $e->getMessage() ] );
			}
		}

		// Publish a held draft only if every aspect succeeded.
		if ( $pending_publish && $wc_id && 'skipped' !== $outcome && ! $aspect_failed ) {
			try {
				$new_product = wc_get_product( $wc_id );
				if ( $new_product ) {
					$new_product->set_status( 'publish' );
					$new_product->save();
				}
			} catch ( Throwable $e ) {
				$aspect_failed = true;
				$this->logger->warning( 'Final publish failed', [ 'wc_id' => $wc_id, 'error' => $e->getMessage() ] );
			}
		}

		// Stamp the change-gate timestamp AND content hash ONLY now that the product is fully committed,
		// so a partial commit never lets the next run skip an incomplete product.
		if ( $wc_id && in_array( $outcome, [ 'created', 'updated' ], true ) ) {
			if ( $aspect_failed ) {
				$ctx['partial_commit'] = true;
			} else {
				update_post_meta( $wc_id, $this->mapper->get_updated_on_meta_key(), (string) ( $row->product['product_updated_on'] ?? '' ) );
				if ( '' !== $content_hash ) {
					update_post_meta( $wc_id, Skwirrel_WC_Sync_Product_Upserter::CONTENT_HASH_META, $content_hash );
				}
			}
		}

		// Per-product checkpoint: this product is fully committed (resumable on interruption).
		$queue->mark_phase_completed( $row->id, 4 );
		self::free_wpdb_memory();
		wp_cache_flush();
	}

	/**
	 * Step: virtual products — apply content & media to the parent variable product, time-boxed.
	 */
	private function step_virtual( array &$ctx, float $deadline ): string {
		$queue   = new Skwirrel_WC_Sync_Queue( $ctx['run_id'] );
		$options = $ctx['options'];

		$done = false;
		while ( microtime( true ) < $deadline ) {
			$row = $queue->get_next_virtual();
			if ( null === $row ) {
				$done = true;
				break;
			}
			try {
				if ( ! empty( $options['use_virtual_product_content'] ) ) {
					$this->upserter->apply_virtual_product_content( $row->virtual_parent_id, $row->product );
				}
				if ( ! $this->upserter->assign_media( $row->virtual_parent_id, $row->product ) ) {
					$ctx['partial_commit'] = true;
				}
			} catch ( Throwable $e ) {
				$ctx['partial_commit'] = true;
				$this->logger->warning( 'Virtual product processing failed', [ 'wc_variable_id' => $row->virtual_parent_id, 'error' => $e->getMessage() ] );
			}

			$queue->mark_phase_completed( $row->id, 4 );
			self::free_wpdb_memory();
			wp_cache_flush();
			++$ctx['virtual_done'];

			if ( 0 === $ctx['virtual_done'] % 10 || $ctx['virtual_done'] === $ctx['virtual_total'] ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_MEDIA,
					$ctx['virtual_done'],
					$ctx['virtual_total'],
					__( 'Finalizing variable products…', 'skwirrel-pim-sync' )
				);
				$this->check_abort();
			}
		}

		if ( $done ) {
			$ctx['step'] = 'relations';
			if ( ! empty( $options['sync_related_products'] ) ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_RELATIONS,
					0,
					$ctx['total'],
					__( 'Linking related products…', 'skwirrel-pim-sync' )
				);
			}
		}
		return 'continue';
	}

	/**
	 * Step: relations — cross-sells & upsells (phase 5), time-boxed. Skipped when disabled.
	 */
	private function step_relations( array &$ctx, float $deadline ): string {
		$options = $ctx['options'];
		if ( empty( $options['sync_related_products'] ) ) {
			$ctx['step'] = 'finalize';
			return 'continue';
		}

		$queue = new Skwirrel_WC_Sync_Queue( $ctx['run_id'] );

		$done = false;
		while ( microtime( true ) < $deadline ) {
			$row = $queue->get_next_for_phase( 5 );
			if ( null === $row ) {
				$done = true;
				break;
			}
			$is_unchanged_row = 'unchanged' === $row->outcome;
			$pending_rel      = $is_unchanged_row ? get_post_meta( $row->wc_id, '_skwirrel_pending_relations', true ) : '';
			$retry_relations  = ! empty( $pending_rel );
			if ( $row->wc_id && 'skipped' !== $row->outcome && ( ! $is_unchanged_row || $retry_relations ) ) {
				try {
					$this->upserter->assign_relations( $row->wc_id, $row->product );
				} catch ( Throwable $e ) {
					$this->logger->warning( 'Relations assignment failed', [ 'wc_id' => $row->wc_id, 'error' => $e->getMessage() ] );
				}
			}

			$queue->mark_phase_completed( $row->id, 5 );
			self::free_wpdb_memory();
			wp_cache_flush();
			++$ctx['rel_done'];

			if ( 0 === $ctx['rel_done'] % 50 || $ctx['rel_done'] === $ctx['total'] ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_RELATIONS,
					$ctx['rel_done'],
					$ctx['total'],
					__( 'Linking related products…', 'skwirrel-pim-sync' )
				);
				$this->check_abort();
			}
		}

		if ( $done ) {
			$ctx['step'] = 'finalize';
		}
		return 'continue';
	}

	/**
	 * Step: cleanup — purge stale products/categories, advance the delta checkpoint, persist history.
	 */
	private function step_finalize( array &$ctx ): string {
		$options = $ctx['options'];
		$queue   = new Skwirrel_WC_Sync_Queue( $ctx['run_id'] );

		// All products processed — remove this run's queue rows.
		$queue->cleanup();

		$this->check_abort();
		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_CLEANUP,
			0,
			1,
			__( 'Cleaning up…', 'skwirrel-pim-sync' )
		);

		$trashed            = 0;
		$categories_removed = 0;
		if ( ! empty( $options['purge_stale_products'] ) ) {
			if ( $ctx['delta'] ) {
				$this->logger->verbose( 'Purge skipped: delta sync (only during full sync)' );
			} else {
				$trashed = $this->purge_handler->purge_stale_products( $ctx['started_at'], $this->mapper );
				if ( ! empty( $options['sync_categories'] ) ) {
					$categories_removed = $this->purge_handler->purge_stale_categories( $ctx['seen_categories'] );
				}
			}
		}

		// Advance the delta checkpoint only now that the run has provably completed. If any product
		// committed only partially, hold the checkpoint AND invalidate the signature so the next run
		// does a full reprocess and retries the partial row(s).
		if ( $ctx['partial_commit'] ) {
			update_option( 'skwirrel_wc_sync_last_sync_sig', '' );
			$this->logger->warning( 'Partial commit (an aspect failed) — invalidating the change-gate signature so the next run does a full reprocess, and holding the delta checkpoint; the affected product(s) will be retried.' );
		} else {
			update_option( Skwirrel_WC_Sync_History::OPTION_LAST_SYNC, gmdate( 'Y-m-d\TH:i:s\Z', $ctx['started_at'] ) );
			update_option( 'skwirrel_wc_sync_last_sync_sig', $ctx['sync_sig'] );
		}

		Skwirrel_WC_Sync_History::update_last_result( true, $ctx['created'], $ctx['updated'], $ctx['failed'], '', $ctx['with_attrs'], $ctx['without_attrs'], $trashed, $categories_removed, $ctx['trigger'], $ctx['log_file'], $ctx['unchanged'] );
		$ctx['trashed']            = $trashed;
		$ctx['categories_removed'] = $categories_removed;

		$this->logger->info(
			'Sync completed',
			[
				'created'            => $ctx['created'],
				'updated'            => $ctx['updated'],
				'unchanged'          => $ctx['unchanged'],
				'failed'             => $ctx['failed'],
				'trashed'            => $trashed,
				'categories_removed' => $categories_removed,
				'with_attributes'    => $ctx['with_attrs'],
				'without_attributes' => $ctx['without_attrs'],
			]
		);

		// Content-hash validation summary. In 'observe' mode this is how you confirm the hash is stable
		// before switching to 'enforce': for genuinely-unchanged products it should report 'match'.
		// A high 'mismatch' count on a re-run of an unchanged catalogue means volatile payload fields
		// (exclude them via skwirrel_wc_sync_content_hash_exclude) or that the upstream truly changed.
		if ( 'off' !== ( $ctx['hash_mode'] ?? 'off' ) ) {
			$this->logger->info(
				'Content-hash diff summary',
				[
					'mode'     => $ctx['hash_mode'],
					'match'    => $ctx['hash_match'],
					'mismatch' => $ctx['hash_mismatch'],
					'new'      => $ctx['hash_new'],
				]
			);
		}

		$this->finish_run( $ctx );
		return 'done';
	}

	/**
	 * Merge the category IDs seen during this process into the persisted set (needed for
	 * stale-category purge, which must span all step actions of the run).
	 */
	private function merge_seen_categories( array &$ctx ): void {
		$seen                   = $this->category_sync->get_seen_category_ids();
		$ctx['seen_categories'] = array_values( array_unique( array_merge( $ctx['seen_categories'] ?? [], (array) $seen ) ) );
	}

	/**
	 * Record a run as failed, clean up its queue/state, and release the run.
	 *
	 * @return string Always 'failed'.
	 */
	private function fail_run( array &$ctx, string $message ): string {
		( new Skwirrel_WC_Sync_Queue( $ctx['run_id'] ) )->cleanup();
		Skwirrel_WC_Sync_History::update_last_result( false, $ctx['created'], $ctx['updated'], $ctx['failed'], $message, 0, 0, 0, 0, $ctx['trigger'], $ctx['log_file'], $ctx['unchanged'] );
		$ctx['step'] = 'failed';
		$this->finish_run( $ctx );
		return 'failed';
	}

	/**
	 * Common end-of-run teardown: stop the log, clear run state/group map, release the mutex.
	 */
	private function finish_run( array $ctx ): void {
		$this->logger->stop_sync_log();
		self::clear_run_state();
		self::clear_group_map();
		Skwirrel_WC_Sync_History::release_sync_mutex();
		Skwirrel_WC_Sync_History::clear_sync_in_progress();
	}

	/**
	 * Build the public result array from the run context.
	 *
	 * @return array{success: bool, created: int, updated: int, unchanged: int, failed: int, error?: string}
	 */
	private function result_from_ctx( array $ctx, bool $success, string $error = '' ): array {
		$result = [
			'success'   => $success,
			'created'   => $ctx['created'],
			'updated'   => $ctx['updated'],
			'unchanged' => $ctx['unchanged'],
			'failed'    => $ctx['failed'],
		];
		if ( isset( $ctx['trashed'] ) ) {
			$result['trashed']            = $ctx['trashed'];
			$result['categories_removed'] = $ctx['categories_removed'] ?? 0;
		}
		if ( '' !== $error ) {
			$result['error'] = $error;
		}
		return $result;
	}

	// ---------------------------------------------------------------------
	// Action Scheduler (batched) entry points + state persistence helpers.
	// ---------------------------------------------------------------------

	/**
	 * Start (or resume) a sync via Action Scheduler — one bounded step per async action, so no
	 * single server time limit can kill the whole run and it resumes automatically.
	 *
	 * Falls back to a synchronous run when Action Scheduler is unavailable.
	 *
	 * @return array{started: bool, resumed?: bool, reason?: string}
	 */
	public static function start_async( bool $delta, string $trigger ): array {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// No Action Scheduler — run synchronously in this request.
			( new self() )->run_sync( $delta, $trigger );
			return [ 'started' => true ];
		}

		$lock = self::db_lock();
		try {
			$state = self::load_run_state();
			if ( is_array( $state ) && ! empty( $state['run_id'] ) ) {
				if ( Skwirrel_WC_Sync_History::is_heartbeat_fresh() ) {
					// A run is alive and a step is (or will be) chained — do not start a second.
					return [ 'started' => false, 'reason' => 'already_running' ];
				}
				// State exists but the heartbeat lapsed (a step fatally died / AS stalled): resume it.
				Skwirrel_WC_Sync_History::sync_heartbeat();
				Skwirrel_WC_Sync_Action_Scheduler::enqueue_step( (string) $state['run_id'] );
				return [ 'started' => true, 'resumed' => true ];
			}

			// Fresh start.
			$begin = ( new self() )->begin_run( $delta, $trigger );
			if ( ! $begin['ok'] ) {
				return [ 'started' => false, 'reason' => 'config_error' ];
			}
			Skwirrel_WC_Sync_Action_Scheduler::enqueue_step( (string) $begin['ctx']['run_id'] );
			return [ 'started' => true ];
		} finally {
			self::db_unlock( $lock );
		}
	}

	/**
	 * Action Scheduler hook handler: execute one bounded step, then chain the next.
	 *
	 * @param string $run_id The run this step belongs to.
	 */
	public static function run_async_step( string $run_id ): void {
		$state = self::load_run_state();
		if ( ! is_array( $state ) || ( $state['run_id'] ?? '' ) !== $run_id ) {
			// Stale/duplicate action for a run that already finished or was superseded.
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		// Poison-loop guard: a step that fatally dies (e.g. OOM) bypasses the catch + save below, so
		// the next action re-runs the SAME step with the SAME state. Detect "this action started from
		// the exact same place as the previous one" (same step + same progress watermark) and fail the
		// run after MAX_STALL such no-progress retries rather than looping forever. Covers every step,
		// including init/finalize.
		$watermark = (int) $state['fetched'] + (int) $state['processed'] + (int) $state['virtual_done'] + (int) $state['rel_done'];
		$sig       = $state['step'] . ':' . $watermark;
		if ( $sig === ( $state['last_progress_sig'] ?? '' ) ) {
			$state['stall'] = (int) ( $state['stall'] ?? 0 ) + 1;
		} else {
			$state['stall'] = 0;
		}
		$state['last_progress_sig'] = $sig;

		if ( (int) $state['stall'] >= self::MAX_STALL ) {
			( new self() )->fail_run( $state, __( 'Sync stalled: a step made no progress across several attempts and was aborted (check the log for a fatal error or a problem product).', 'skwirrel-pim-sync' ) );
			return;
		}
		// Persist the stall bookkeeping now, so a fatal during run_step() still leaves the incremented
		// counter for the next action to observe.
		self::save_run_state( $state );

		$svc      = new self();
		$deadline = microtime( true ) + (float) apply_filters( 'skwirrel_wc_sync_step_seconds', self::DEFAULT_STEP_SECONDS );

		try {
			$status = $svc->run_step( $state, $deadline );
		} catch ( \RuntimeException $e ) {
			$svc->fail_run( $state, $e->getMessage() );
			return;
		} catch ( \Throwable $e ) {
			( new Skwirrel_WC_Sync_Logger() )->error( 'Sync step failed with an unexpected error', [ 'error' => $e->getMessage() ] );
			$svc->fail_run( $state, $e->getMessage() );
			return;
		}

		if ( 'continue' === $status ) {
			self::save_run_state( $state );
			Skwirrel_WC_Sync_Action_Scheduler::enqueue_step( $run_id );
		}
		// On 'done'/'failed' the step already cleared the run state and released the mutex.
	}

	/** Load the persisted run state, or null when no run is active. */
	public static function load_run_state(): ?array {
		$state = get_option( self::OPTION_RUN_STATE, null );
		return is_array( $state ) ? $state : null;
	}

	/** Persist the run state (autoload off — it changes on every step). */
	public static function save_run_state( array $ctx ): void {
		update_option( self::OPTION_RUN_STATE, $ctx, false );
	}

	/** Remove the persisted run state. */
	public static function clear_run_state(): void {
		delete_option( self::OPTION_RUN_STATE );
	}

	/** Persist the product→group map for the run (only consumed during the fetch step). */
	private static function save_group_map( string $run_id, array $map ): void {
		update_option( self::OPTION_GROUP_MAP, [ 'run_id' => $run_id, 'map' => $map ], false );
	}

	/** Load the product→group map for the run (empty array if none / mismatched run). */
	private static function load_group_map( string $run_id ): array {
		$stored = get_option( self::OPTION_GROUP_MAP, [] );
		if ( is_array( $stored ) && ( $stored['run_id'] ?? '' ) === $run_id && is_array( $stored['map'] ?? null ) ) {
			return $stored['map'];
		}
		return [];
	}

	/** Remove the persisted group map. */
	private static function clear_group_map(): void {
		delete_option( self::OPTION_GROUP_MAP );
	}

	/**
	 * Acquire a short-lived MySQL advisory lock so the start critical section is atomic across
	 * concurrent loopback/AS requests. Returns the lock name on success, or '' if unavailable.
	 */
	private static function db_lock(): string {
		global $wpdb;
		$name = $wpdb->prefix . 'skwirrel_sync_start';
		// 0s timeout: if another request holds it we proceed without it (the heartbeat check still guards).
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 0 ) );
		return ( '1' === (string) $got ) ? $name : '';
	}

	/** Release a MySQL advisory lock acquired by db_lock(). */
	private static function db_unlock( string $name ): void {
		if ( '' === $name ) {
			return;
		}
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
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
