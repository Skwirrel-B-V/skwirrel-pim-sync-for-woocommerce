<?php
/**
 * Skwirrel Sync - Verwijderbescherming.
 *
 * Toont waarschuwingen wanneer Skwirrel-beheerde producten of categorieën
 * in WooCommerce worden verwijderd. Skwirrel is leidend: verwijderde items
 * worden bij de volgende sync opnieuw aangemaakt.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Delete_Protection {

	private const FORCE_FULL_SYNC_OPTION = 'skwirrel_wc_sync_force_full_sync';

	private static ?self $instance = null;

	/**
	 * True while the sync itself is performing an owned trash/delete (simple→variation
	 * conversion, grouped-parent replacement). Process-local so it bypasses the delete-lock
	 * ONLY for the sync's own operations, never for a concurrent manual admin delete.
	 */
	private static bool $internal_op = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Waarschuwingsbanner op product-bewerkpagina
		add_action( 'admin_notices', [ $this, 'product_edit_notice' ] );

		// Trash-link aanpassen in productlijst (bevestigingsdialoog)
		add_filter( 'post_row_actions', [ $this, 'modify_product_row_actions' ], 10, 2 );

		// Delete-link aanpassen in categorieënlijst
		add_filter( 'product_cat_row_actions', [ $this, 'modify_category_row_actions' ], 10, 2 );

		// JS bevestigingsdialoog op productlijst en categoriepagina
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Na verwijdering: forceer volledige sync zodat product terugkomt
		add_action( 'wp_trash_post', [ $this, 'on_product_trashed' ] );
		add_action( 'before_delete_post', [ $this, 'on_product_trashed' ] );

		// Na verwijdering categorie: forceer volledige sync
		add_action( 'pre_delete_term', [ $this, 'on_category_deleted' ], 10, 2 );

		// Harde blokkade: weiger trash/delete van Skwirrel-producten zolang een sync draait.
		add_filter( 'pre_trash_post', [ $this, 'maybe_block_trash' ], 10, 2 );
		add_filter( 'pre_delete_post', [ $this, 'maybe_block_delete' ], 10, 2 );

		// Banner zolang een sync draait en de verwijderlock actief is.
		add_action( 'admin_notices', [ $this, 'sync_lock_notice' ] );
	}

	/**
	 * Whether "Protect Skwirrel products from deletion" is enabled.
	 *
	 * Protective by default: returns true until the setting is explicitly disabled.
	 * When enabled, manual deletion of Skwirrel-managed products is blocked while a
	 * sync is running (the delete-lock below). What the sync itself does with removed
	 * or discontinued products is governed by the product status-handling mapping, not
	 * by this flag.
	 */
	public static function is_deletion_protection_enabled(): bool {
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		if ( ! is_array( $opts ) || ! array_key_exists( 'protect_from_deletion', $opts ) ) {
			return true; // Standaard aan.
		}
		return ! empty( $opts['protect_from_deletion'] );
	}

	/**
	 * Whether a sync is actively running (heartbeat still fresh).
	 */
	private function is_sync_running(): bool {
		return Skwirrel_WC_Sync_History::is_heartbeat_fresh();
	}

	/**
	 * Whether the hard delete-lock applies right now (protection on + sync running).
	 */
	private function delete_lock_active(): bool {
		return self::is_deletion_protection_enabled() && $this->is_sync_running();
	}

	/**
	 * Controleer of de waarschuwingsbanners ingeschakeld zijn.
	 * Standaard ingeschakeld (true) totdat de instelling expliciet is uitgeschakeld.
	 */
	private function is_enabled(): bool {
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		if ( ! array_key_exists( 'show_delete_warning', $opts ) ) {
			return true; // Standaard aan bij nieuwe installatie
		}
		return ! empty( $opts['show_delete_warning'] );
	}

	/**
	 * Controleer of een product door Skwirrel wordt beheerd.
	 */
	private function is_skwirrel_product( int $post_id ): bool {
		$ext_id = get_post_meta( $post_id, '_skwirrel_external_id', true );
		if ( ! empty( $ext_id ) ) {
			return true;
		}
		$product_id = get_post_meta( $post_id, '_skwirrel_product_id', true );
		if ( ! empty( $product_id ) ) {
			return true;
		}
		$grouped_id = get_post_meta( $post_id, '_skwirrel_grouped_product_id', true );
		return ! empty( $grouped_id );
	}

	/**
	 * Controleer of een categorie door Skwirrel is aangemaakt.
	 */
	private function is_skwirrel_category( int $term_id ): bool {
		$skwirrel_id = get_term_meta( $term_id, '_skwirrel_category_id', true );
		return ! empty( $skwirrel_id );
	}

	/**
	 * Toon waarschuwingsbanner op de product-bewerkpagina.
	 */
	public function product_edit_notice(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		// Alleen op de individuele product-bewerkpagina
		if ( 'post' !== $screen->base ) {
			return;
		}

		global $post;
		if ( ! $post || ! $this->is_skwirrel_product( $post->ID ) ) {
			return;
		}

		$synced_at  = get_post_meta( $post->ID, '_skwirrel_synced_at', true );
		$synced_str = $synced_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $synced_at ) : '';

		?>
		<div class="notice notice-warning is-dismissible skwirrel-sync-delete-warning">
			<p>
				<strong>Skwirrel Sync:</strong>
				<?php esc_html_e( 'This product is managed by Skwirrel. Changes to product data should be made in Skwirrel.', 'skwirrel-pim-sync' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'If you delete or trash this product, it will be automatically recreated during the next sync.', 'skwirrel-pim-sync' ); ?>
				<?php if ( $synced_str ) : ?>
					<?php /* translators: %s = last sync datetime */ ?>
					<br><small><?php echo esc_html( sprintf( __( 'Last synced: %s', 'skwirrel-pim-sync' ), $synced_str ) ); ?></small>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Voeg CSS class toe aan trash-link voor Skwirrel-producten in productlijst.
	 */
	public function modify_product_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'product' !== $post->post_type || ! $this->is_skwirrel_product( $post->ID ) ) {
			return $actions;
		}

		// Hard lock while a sync runs: remove the trash action entirely (independent
		// of the warning setting) and show the reason in its place.
		if ( $this->delete_lock_active() ) {
			unset( $actions['trash'] );
			$actions['skwirrel_locked'] = '<span class="skwirrel-delete-locked" style="color:#a00;">'
				. esc_html__( 'Delete locked — sync running', 'skwirrel-pim-sync' )
				. '</span>';
			return $actions;
		}

		// Otherwise keep the confirmation-dialog behaviour (gated by the warning setting).
		if ( $this->is_enabled() && isset( $actions['trash'] ) ) {
			$actions['trash'] = str_replace(
				'class="submitdelete"',
				'class="submitdelete skwirrel-protected-trash"',
				$actions['trash']
			);
		}

		return $actions;
	}

	/**
	 * Voeg CSS class toe aan delete-link voor Skwirrel-categorieën.
	 */
	public function modify_category_row_actions( array $actions, object $term ): array {
		if ( ! $this->is_enabled() ) {
			return $actions;
		}

		if ( ! $this->is_skwirrel_category( $term->term_id ) ) {
			return $actions;
		}

		if ( isset( $actions['delete'] ) ) {
			$actions['delete'] = str_replace(
				'class="delete-tag"',
				'class="delete-tag skwirrel-protected-delete"',
				$actions['delete']
			);
		}

		return $actions;
	}

	/**
	 * Enqueue JavaScript bevestigingsdialogen op productlijst en categoriepagina.
	 */
	public function enqueue_scripts(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Hard delete-lock while a sync runs: hide the "Move to Trash" button on the
		// product editor for Skwirrel products. Independent of the warning setting.
		if ( 'post' === $screen->base && 'product' === $screen->post_type && $this->delete_lock_active() ) {
			global $post;
			if ( $post && $this->is_skwirrel_product( $post->ID ) ) {
				wp_register_style( 'skwirrel-pim-sync-delete-lock', false, [], SKWIRREL_WC_SYNC_VERSION );
				wp_enqueue_style( 'skwirrel-pim-sync-delete-lock' );
				wp_add_inline_style( 'skwirrel-pim-sync-delete-lock', '#delete-action{display:none !important;}' );
			}
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( 'edit-product' === $screen->id ) {
			$msg = __( 'This product is managed by Skwirrel and will be recreated during the next sync.\n\nAre you sure you want to trash this product?', 'skwirrel-pim-sync' );
			wp_register_script( 'skwirrel-pim-sync-delete-protection', false, [], SKWIRREL_WC_SYNC_VERSION, true );
			wp_enqueue_script( 'skwirrel-pim-sync-delete-protection' );
			wp_add_inline_script(
				'skwirrel-pim-sync-delete-protection',
				'(function() {'
				. ' var msg = ' . wp_json_encode( $msg ) . ';'
				. ' document.addEventListener("click", function(e) {'
				. '  var link = e.target.closest(".skwirrel-protected-trash");'
				. '  if (!link) return;'
				. '  if (!confirm(msg)) { e.preventDefault(); e.stopPropagation(); }'
				. ' }, true);'
				. '})();'
			);
		}

		if ( 'product_cat' === $screen->taxonomy ) {
			$msg = __( 'This category was created by Skwirrel Sync and will be recreated during the next sync.\n\nAre you sure you want to delete this category?', 'skwirrel-pim-sync' );
			wp_register_script( 'skwirrel-pim-sync-delete-protection-cat', false, [], SKWIRREL_WC_SYNC_VERSION, true );
			wp_enqueue_script( 'skwirrel-pim-sync-delete-protection-cat' );
			wp_add_inline_script(
				'skwirrel-pim-sync-delete-protection-cat',
				'(function() {'
				. ' var msg = ' . wp_json_encode( $msg ) . ';'
				. ' document.addEventListener("click", function(e) {'
				. '  var link = e.target.closest(".skwirrel-protected-delete");'
				. '  if (!link) return;'
				. '  if (!confirm(msg)) { e.preventDefault(); e.stopPropagation(); }'
				. ' }, true);'
				. '})();'
			);
		}
	}

	/**
	 * Short-circuit a trash attempt on a Skwirrel product while a sync runs.
	 *
	 * @param mixed   $check Short-circuit value (null = proceed).
	 * @param \WP_Post $post  Post about to be trashed.
	 * @return mixed False to block the trash, otherwise the unchanged $check.
	 */
	public function maybe_block_trash( $check, $post ) {
		return $this->block_when_locked( $check, $post );
	}

	/**
	 * Short-circuit a (permanent) delete attempt on a Skwirrel product while a sync runs.
	 *
	 * @param mixed    $check Short-circuit value (null = proceed).
	 * @param \WP_Post $post  Post about to be deleted.
	 * @return mixed False to block the delete, otherwise the unchanged $check.
	 */
	public function maybe_block_delete( $check, $post ) {
		return $this->block_when_locked( $check, $post );
	}

	/**
	 * Block trash/delete of a Skwirrel-managed product while the delete-lock is active.
	 *
	 * Returning a non-null value short-circuits wp_trash_post()/wp_delete_post(), so a
	 * concurrent manual deletion cannot race the running sync. Catches bulk actions and
	 * direct URLs too, not just the row/edit buttons.
	 *
	 * @param mixed $short_circuit Current short-circuit value.
	 * @param mixed $post          Post object (or other) passed by the filter.
	 * @return mixed False to block, otherwise the unchanged $short_circuit.
	 */
	private function block_when_locked( $short_circuit, $post ) {
		// The sync's own owned deletes (simple→variation conversion, grouped-parent
		// replacement) run in the sync process and must never be blocked — only concurrent
		// manual admin deletes (a different request, flag unset) should hit the lock.
		if ( self::$internal_op ) {
			return $short_circuit;
		}
		if ( ! ( $post instanceof \WP_Post ) ) {
			return $short_circuit;
		}
		if ( 'product' !== $post->post_type && 'product_variation' !== $post->post_type ) {
			return $short_circuit;
		}
		if ( ! $this->delete_lock_active() || ! $this->is_skwirrel_product( $post->ID ) ) {
			return $short_circuit;
		}

		( new Skwirrel_WC_Sync_Logger() )->info(
			'Deletion of a Skwirrel-managed product blocked: a sync is running (delete-lock active).',
			[ 'post_id' => $post->ID ]
		);
		return false;
	}

	/**
	 * Perform a sync-owned trash/delete that must bypass the delete-lock.
	 *
	 * The sync legitimately trashes/deletes Skwirrel products while converting simples to
	 * variations and replacing grouped parents. Those calls run inside the sync process, so a
	 * process-local flag lets them through while a concurrent manual admin delete (a different
	 * request, flag unset) stays blocked.
	 *
	 * @param int  $post_id Post to remove.
	 * @param bool $force   When true, permanently delete; otherwise move to trash.
	 */
	public static function do_internal_delete( int $post_id, bool $force = false ): void {
		self::$internal_op = true;
		try {
			if ( $force ) {
				wp_delete_post( $post_id, true );
			} else {
				wp_trash_post( $post_id );
			}
		} finally {
			self::$internal_op = false;
		}
	}

	/**
	 * Show an info banner while the delete-lock is active (a sync is running).
	 */
	public function sync_lock_notice(): void {
		if ( ! $this->delete_lock_active() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$on_list = ( 'edit-product' === $screen->id );
		$on_edit = ( 'product' === $screen->post_type && 'post' === $screen->base );
		if ( ! $on_list && ! $on_edit ) {
			return;
		}

		if ( $on_edit ) {
			global $post;
			if ( ! $post || ! $this->is_skwirrel_product( $post->ID ) ) {
				return;
			}
		}

		?>
		<div class="notice notice-info">
			<p>
				<strong>Skwirrel Sync:</strong>
				<?php esc_html_e( 'A Skwirrel sync is running. Deleting Skwirrel-managed products is temporarily disabled to prevent conflicts. You can delete them again once the sync has finished.', 'skwirrel-pim-sync' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Wanneer een Skwirrel-product wordt verwijderd/getrashed in WC,
	 * forceer de volgende geplande sync als volledige sync.
	 */
	public function on_product_trashed( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return;
		}

		if ( ! $this->is_skwirrel_product( $post_id ) ) {
			return;
		}

		update_option( self::FORCE_FULL_SYNC_OPTION, true, false );
		( new Skwirrel_WC_Sync_Logger() )->info(
			'force_full_sync flag set: Skwirrel-managed product trashed in WC — next scheduled sync will run as full to bring it back.',
			[ 'post_id' => $post_id ]
		);
	}

	/**
	 * Wanneer een Skwirrel-categorie wordt verwijderd in WC,
	 * forceer de volgende geplande sync als volledige sync.
	 */
	public function on_category_deleted( int $term_id, string $taxonomy ): void {
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}

		if ( ! $this->is_skwirrel_category( $term_id ) ) {
			return;
		}

		update_option( self::FORCE_FULL_SYNC_OPTION, true, false );
		( new Skwirrel_WC_Sync_Logger() )->info(
			'force_full_sync flag set: Skwirrel-managed product category deleted in WC — next scheduled sync will run as full to bring it back.',
			[ 'term_id' => $term_id ]
		);
	}
}
