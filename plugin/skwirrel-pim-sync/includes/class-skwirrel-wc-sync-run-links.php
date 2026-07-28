<?php
/**
 * Skwirrel Sync — run-scoped product deep-links.
 *
 * The sync stamps each product it changes with the run that changed it and the outcome
 * (`created` / `updated`). Combined with the native `post_status` filter this lets the
 * dashboard's per-run count cells link straight to the WooCommerce product list scoped to
 * exactly that run's set — e.g. `edit.php?post_type=product&skwirrel_run=<uuid>&post_status=deprecated`.
 *
 * The marker records only the LAST run that touched a product, so deep-links are exact for
 * recent runs and degrade for older ones as products are re-synced — acceptable for a jump-off.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Run_Links {

	/** Meta: the run (uuid) that last created/updated/hid this product. */
	public const RUN_ID_META = '_skwirrel_run_id';

	/** Meta: the outcome of that last change — 'created' | 'updated' | 'trashed'. */
	public const RUN_OUTCOME_META = '_skwirrel_run_outcome';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'pre_get_posts', [ $this, 'filter_product_list' ] );
	}

	/**
	 * Record which run last changed a product, and how. Called from the sync write paths.
	 *
	 * @param int    $post_id Product/variation ID.
	 * @param string $run_id  The current run's uuid.
	 * @param string $outcome One of created|updated|trashed.
	 */
	public static function mark( int $post_id, string $run_id, string $outcome ): void {
		if ( $post_id <= 0 || '' === $run_id ) {
			return;
		}
		// One run can mark the same product more than once — every variation marks its variable
		// parent. Keep the strongest outcome: a product this run created stays `created` even when
		// a later write for the same run reports `updated`.
		if ( 'updated' === $outcome
			&& (string) get_post_meta( $post_id, self::RUN_ID_META, true ) === $run_id
			&& 'created' === (string) get_post_meta( $post_id, self::RUN_OUTCOME_META, true ) ) {
			return;
		}
		update_post_meta( $post_id, self::RUN_ID_META, $run_id );
		update_post_meta( $post_id, self::RUN_OUTCOME_META, $outcome );
	}

	/**
	 * Record that this run trashed a product, without discarding a create/update it did earlier.
	 *
	 * A product can be created or updated early in a run and then trashed during that same run's
	 * finalize (deprecated escalation, stale purge). The run's Created/Updated counters still
	 * include it, so overwriting the outcome would make those deep-links return fewer products
	 * than the count they were clicked from. Keeping it costs nothing: the Trashed and Deprecated
	 * cells link on post_status, not on the outcome, so they stay exact either way.
	 *
	 * @param int    $post_id Product/variation ID.
	 * @param string $run_id  The current run's uuid.
	 */
	public static function mark_trashed( int $post_id, string $run_id ): void {
		if ( $post_id <= 0 || '' === $run_id ) {
			return;
		}
		$outcome_this_run = (string) get_post_meta( $post_id, self::RUN_ID_META, true ) === $run_id
			? (string) get_post_meta( $post_id, self::RUN_OUTCOME_META, true )
			: '';
		// Already marked by this run as created/updated: the marker points here, so leave both
		// meta values alone. Only `mark()` ever writes those two outcomes.
		if ( in_array( $outcome_this_run, [ 'created', 'updated' ], true ) ) {
			return;
		}
		self::mark( $post_id, $run_id, 'trashed' );
	}

	/**
	 * Scope the admin product list to a run (and optionally an outcome) from the query string.
	 *
	 * Read-only display filter on the native products screen — no nonce applies (same class as the
	 * built-in post_status/author filters WP reads from $_GET).
	 *
	 * @param WP_Query $query The query being prepared.
	 */
	public function filter_product_list( $query ): void {
		global $pagenow;
		// Only the admin products list screen (edit.php?post_type=product) — never the front-end,
		// REST, admin-ajax, or sub-queries.
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}
		if ( 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin list filter, no state change.
		$run = isset( $_GET['skwirrel_run'] ) ? sanitize_text_field( wp_unslash( $_GET['skwirrel_run'] ) ) : '';
		if ( '' === $run ) {
			return;
		}

		$run_clause = [
			'relation' => 'AND',
			[
				'key'   => self::RUN_ID_META,
				'value' => $run,
			],
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin list filter, no state change.
		$outcome = isset( $_GET['skwirrel_outcome'] ) ? sanitize_key( wp_unslash( $_GET['skwirrel_outcome'] ) ) : '';
		if ( in_array( $outcome, [ 'created', 'updated', 'trashed' ], true ) ) {
			$run_clause[] = [
				'key'   => self::RUN_OUTCOME_META,
				'value' => $outcome,
			];
		}

		// A run-scoped view must include trash. A product this run created or updated can have been
		// trashed by the same run's finalize (deprecated escalation, stale purge), and WP's "All"
		// list hides trash — so the count cell and the list it links to would disagree. An explicit
		// post_status in the URL (the Published/Draft/Trash tabs) still wins.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin list filter, no state change.
		if ( ! isset( $_GET['post_status'] ) ) {
			$statuses = array_values( get_post_stati( [ 'show_in_admin_all_list' => true ] ) );
			$query->set( 'post_status', array_merge( $statuses, [ 'trash' ] ) );
		}

		// Nest so the run scoping is always ANDed, even if an existing meta_query uses relation OR.
		$existing   = $query->get( 'meta_query' );
		$meta_query = empty( $existing )
			? $run_clause
			: [
				'relation' => 'AND',
				$existing,
				$run_clause,
			];
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Build a product-list URL scoped to a run, with an extra query arg (post_status or outcome).
	 *
	 * @param string               $run_id Run uuid ('' returns '' — no link).
	 * @param array<string,string> $args   Extra query args (e.g. ['post_status' => 'deprecated']).
	 * @return string Admin URL, or '' when there is no run to link to.
	 */
	public static function list_url( string $run_id, array $args = [] ): string {
		if ( '' === $run_id ) {
			return '';
		}
		return add_query_arg(
			array_merge(
				[
					'post_type'    => 'product',
					'skwirrel_run' => $run_id,
				],
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Count products this run changed that now hold a given post status.
	 *
	 * Used for the per-run "Deprecated" tally: products marked by this run whose status ended up
	 * `deprecated` — whether mapped that way on upsert or set by the deprecated lifecycle.
	 *
	 * @param string $run_id      Run uuid.
	 * @param string $post_status WooCommerce post status to count (e.g. 'deprecated').
	 * @return int
	 */
	public static function count_for_run( string $run_id, string $post_status ): int {
		if ( '' === $run_id ) {
			return 0;
		}
		$ids = get_posts(
			[
				// Parents only — the deep-linked product list can't render product_variation rows,
				// so counting them would make the cell exceed the number of rows the link shows.
				'post_type'      => 'product',
				'post_status'    => $post_status,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded to one run's products.
				'meta_query'     => [
					[
						'key'   => self::RUN_ID_META,
						'value' => $run_id,
					],
				],
			]
		);
		return count( $ids );
	}
}
