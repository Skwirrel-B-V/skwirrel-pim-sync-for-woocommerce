<?php
/**
 * Skwirrel Sync — Deprecated product status.
 *
 * Registers a custom `deprecated` WooCommerce post status used by the product
 * status-handling mapping: products mapped to "Deprecated" are hidden (like draft)
 * but sit in a dedicated, reviewable bucket until the deprecated-lifecycle escalation
 * moves them to the trash after a configurable number of full syncs.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Deprecated_Status {

	/** The custom post status slug. */
	public const STATUS = 'deprecated';

	/** Per-product counter meta: full-sync passes elapsed while in the deprecated status. */
	public const COUNT_META = '_skwirrel_deprecated_sync_count';

	/** Per-product marker meta: sync-started-at of the run that last advanced the counter (resume idempotency). */
	public const TICKED_META = '_skwirrel_deprecated_ticked_at';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_status' ] );
	}

	/**
	 * Register the hidden `deprecated` post status.
	 *
	 * Non-public (so WooCommerce treats it like draft — hidden from the shop and not
	 * purchasable) but listed in the admin status filter for the product list.
	 */
	public function register_status(): void {
		register_post_status(
			self::STATUS,
			[
				'label'                     => _x( 'Deprecated', 'product status', 'skwirrel-pim-sync' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of deprecated products. */
				'label_count'               => _n_noop(
					'Deprecated <span class="count">(%s)</span>',
					'Deprecated <span class="count">(%s)</span>',
					'skwirrel-pim-sync'
				),
			]
		);
	}

	/**
	 * Advance the deprecated counter and decide whether the product is now due for removal.
	 *
	 * Pure: called once per full-sync escalation pass for each deprecated product. The counter
	 * starts at 0 (absent meta), so a threshold of 0 removes on the first pass and a threshold of
	 * N keeps the product visible-as-deprecated for N passes.
	 *
	 * @param int $count     Current stored counter (0 when the meta is absent).
	 * @param int $threshold Configured deprecated_remove_after_syncs (0 = immediate).
	 * @return array{count: int, remove: bool}
	 */
	public static function escalate( int $count, int $threshold ): array {
		$next = $count + 1;
		return [
			'count'  => $next,
			'remove' => $next > $threshold,
		];
	}
}
