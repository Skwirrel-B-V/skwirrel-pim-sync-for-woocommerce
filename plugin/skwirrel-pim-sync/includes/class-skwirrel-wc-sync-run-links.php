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

	/**
	 * Meta: the outcomes that run recorded for this product — 'created' | 'updated' | 'trashed'.
	 *
	 * Stored as one meta ROW PER OUTCOME, not a single value: a variable parent is marked once
	 * per variation, and one run can legitimately create one variation and update another on the
	 * same parent. Both counters are incremented, so the parent has to answer to both links —
	 * collapsing them into a single value made the other link skip it, and a run whose updates
	 * all landed on freshly created parents opened an empty Updated list. `meta_query` matching a
	 * value is EXISTS-style over the rows, so membership is exactly the right model here.
	 */
	public const RUN_OUTCOME_META = '_skwirrel_run_outcome';

	/** The outcomes a run's count cells can link to. */
	private const OUTCOMES = [ 'created', 'updated', 'trashed' ];

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
	 * Record that a run changed a product, and how. Called from the sync write paths.
	 *
	 * Outcomes accumulate within a run rather than overwriting each other: a variable parent is
	 * marked once per variation, so one run can create one variation and update another on the
	 * same parent, incrementing both counters. The parent then answers to both links. A mark from
	 * a *different* run starts fresh — the previous run's counters are historical and its links
	 * are no longer rendered.
	 *
	 * @param int    $post_id Product/variation ID.
	 * @param string $run_id  The current run's uuid.
	 * @param string $outcome One of created|updated|trashed.
	 */
	public static function mark( int $post_id, string $run_id, string $outcome ): void {
		if ( $post_id <= 0 || '' === $run_id || ! in_array( $outcome, self::OUTCOMES, true ) ) {
			return;
		}
		self::claim_for_run( $post_id, $run_id );
		self::add_outcome( $post_id, $outcome );
	}

	/**
	 * Record that a run trashed a product.
	 *
	 * Now that outcomes are a set, `trashed` simply joins whatever else this run recorded: a
	 * product created into `deprecated` that reaches the removal threshold in the same finalize
	 * (always, at threshold 0) is counted under both Created and Deleted, and answers to both
	 * links. The marker goes on the variable parent for a variation — the product list the links
	 * open cannot render a `product_variation` row, so an orphaned variation trashed while its
	 * parent stays in the feed would otherwise have no row to show at all.
	 *
	 * @param int    $post_id Product/variation ID.
	 * @param string $run_id  The current run's uuid.
	 */
	public static function mark_trashed( int $post_id, string $run_id ): void {
		$post_id = self::linkable_post_id( $post_id );
		if ( $post_id <= 0 || '' === $run_id ) {
			return;
		}
<<<<<<< HEAD
		// One run can mark the same product more than once — every variation marks its variable
		// parent. Keep the strongest outcome: a product this run created stays `created` even when
		// a later write for the same run reports `updated`.
		if ( 'updated' === $outcome
			&& (string) get_post_meta( $post_id, self::RUN_ID_META, true ) === $run_id
			&& 'created' === (string) get_post_meta( $post_id, self::RUN_OUTCOME_META, true ) ) {
			return;
		}
=======
		self::claim_for_run( $post_id, $run_id );
		self::add_outcome( $post_id, 'trashed' );
	}

	/**
	 * The post a run link can actually render for a given id: a variation's variable parent.
	 *
	 * The linked list is scoped to `post_type=product`, so a `product_variation` row can never
	 * appear in it. Callers that already know the parent (the commit path has it in `group_info`)
	 * resolve it themselves; this covers the paths that only hold the variation id.
	 */
	public static function linkable_post_id( int $post_id ): int {
		if ( $post_id <= 0 || 'product_variation' !== get_post_type( $post_id ) ) {
			return $post_id;
		}
		$parent = (int) wp_get_post_parent_id( $post_id );
		return $parent > 0 ? $parent : $post_id;
	}

	/** The run currently stamped on a product ('' when none). */
	private static function run_of( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::RUN_ID_META, true );
	}

	/**
	 * The outcomes currently stamped on a product.
	 *
	 * @return array<int, string>
	 */
	private static function outcomes_of( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::RUN_OUTCOME_META, false );
		return is_array( $stored ) ? array_map( 'strval', $stored ) : [];
	}

	/**
	 * Stamp a product with the current run, clearing another run's outcomes first.
	 */
	private static function claim_for_run( int $post_id, string $run_id ): void {
		if ( self::run_of( $post_id ) === $run_id ) {
			return;
		}
		delete_post_meta( $post_id, self::RUN_OUTCOME_META ); // Belongs to the previous run.
>>>>>>> origin/release/3.12.1
		update_post_meta( $post_id, self::RUN_ID_META, $run_id );
	}

	/** Add an outcome row, unless this run already recorded it. */
	private static function add_outcome( int $post_id, string $outcome ): void {
		if ( in_array( $outcome, self::outcomes_of( $post_id ), true ) ) {
			return;
		}
		add_post_meta( $post_id, self::RUN_OUTCOME_META, $outcome );
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
		// Matching a value is EXISTS-style across the outcome rows, so a parent that this run both
		// created (one variation) and updated (another) is returned by either link.
		if ( in_array( $outcome, self::OUTCOMES, true ) ) {
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

		// WP's "All" view excludes the trash, so a product this run created and then trashed
		// during finalize would be missing from the Created link it is counted under. When a run
		// scope is active and the URL asks for no particular status, include the trash too.
		// The Published/Draft/Trash tabs still win — they set post_status explicitly.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin list filter, no state change.
		if ( ! isset( $_GET['post_status'] ) ) {
			$query->set( 'post_status', self::run_scope_statuses() );
		}
	}

	/**
	 * Post statuses a run-scoped list shows when the URL requests none.
	 *
	 * Selected by `show_in_admin_all_list` — the flag WordPress itself uses to build the products
	 * screen's "All" view, so this set is exactly what that view shows (publish, draft, pending,
	 * private, future, and `deprecated`, which opts in). Deriving it from `exclude_from_search`
	 * was indirect: that flag answers a different question, and every status a run's outcome
	 * links must reach then had to be reasoned about separately.
	 *
	 * `trash` is added on top because WP's "All" deliberately excludes it, while a product this
	 * run created and then trashed during finalize is still counted under Created — the Created
	 * link has to list it. The explicit fallback covers a pathological empty registry.
	 *
	 * @return array<int, string>
	 */
	private static function run_scope_statuses(): array {
		$statuses = array_values( array_map( 'strval', get_post_stati( [ 'show_in_admin_all_list' => true ], 'names' ) ) );
		if ( [] === $statuses ) {
			$statuses = [ 'publish', 'draft', 'pending', 'private', Skwirrel_WC_Sync_Deprecated_Status::STATUS ];
		}
		$statuses[] = 'trash';
		return array_values( array_unique( $statuses ) );
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
