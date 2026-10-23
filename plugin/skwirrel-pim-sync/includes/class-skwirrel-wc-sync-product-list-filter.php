<?php
/**
 * Skwirrel Product List Filter.
 *
 * A dropdown in the filter bar of Products → All Products that shows only products managed in
 * Skwirrel (they carry a Skwirrel external ID), or only the ones that are not. For shops that run
 * the sync next to products made by hand.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_List_Filter {

	public const QUERY_ARG = 'skw_managed';

	public const MANAGED     = 'managed';
	public const NOT_MANAGED = 'not_managed';

	/** Post meta that marks a product as managed in Skwirrel. */
	private const META_EXTERNAL_ID = '_skwirrel_external_id';

	/** Post meta on a variable product that was built from a Skwirrel group. */
	private const META_GROUPED_ID = '_skwirrel_grouped_product_id';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'restrict_manage_posts', [ $this, 'render_dropdown' ], 22 );
		add_action( 'parse_query', [ $this, 'filter_query' ] );
	}

	/**
	 * Whether the sync is set up on this shop: an endpoint is configured. Without it every product
	 * is "not managed", so the dropdown would only be noise.
	 */
	public static function is_sync_set_up(): bool {
		$settings = get_option( 'skwirrel_wc_sync_settings', [] );
		return is_array( $settings ) && '' !== trim( (string) ( $settings['endpoint_url'] ?? '' ) );
	}

	/**
	 * The meta query clause for a chosen value, or [] for "all products" and anything unknown.
	 *
	 * @return array<int|string,mixed>
	 */
	public static function meta_clause( string $value ): array {
		if ( self::MANAGED === $value ) {
			// Synced simple products carry the external ID; the variable products built from a group
			// carry the grouped product ID instead. Both are managed in Skwirrel.
			return [
				'relation' => 'OR',
				[
					'key'     => self::META_EXTERNAL_ID,
					'compare' => 'EXISTS',
				],
				[
					'key'     => self::META_GROUPED_ID,
					'compare' => 'EXISTS',
				],
			];
		}
		if ( self::NOT_MANAGED === $value ) {
			return [
				'relation' => 'AND',
				[
					'key'     => self::META_EXTERNAL_ID,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => self::META_GROUPED_ID,
					'compare' => 'NOT EXISTS',
				],
			];
		}
		return [];
	}

	/**
	 * Add a clause to a meta query so that BOTH must hold, whatever relation the existing one uses.
	 * Appending to an existing top-level OR query would make it "either", and show unrelated products.
	 *
	 * @param array<int|string,mixed> $existing Existing meta query, possibly empty.
	 * @param array<int|string,mixed> $clause   Clause to require.
	 * @return array<int|string,mixed>
	 */
	public static function merge_meta_query( array $existing, array $clause ): array {
		if ( empty( $existing ) ) {
			return [ $clause ];
		}
		return [
			'relation' => 'AND',
			$existing,
			$clause,
		];
	}

	/**
	 * Render the dropdown on the product list.
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_dropdown( string $post_type ): void {
		if ( 'product' !== $post_type || ! self::is_sync_set_up() ) {
			return;
		}
		$selected = $this->requested_value();
		$choices  = [
			''                => __( 'All products', 'skwirrel-pim-sync' ),
			self::MANAGED     => __( 'Managed in Skwirrel', 'skwirrel-pim-sync' ),
			self::NOT_MANAGED => __( 'Not managed in Skwirrel', 'skwirrel-pim-sync' ),
		];
		echo '<select name="' . esc_attr( self::QUERY_ARG ) . '" aria-label="' . esc_attr__( 'Filter by Skwirrel management', 'skwirrel-pim-sync' ) . '">';
		foreach ( $choices as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Restrict the product list query. It adds a clause next to whatever other filters and
	 * searches are active, so they combine; paging and sorting keep the argument in the URL.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function filter_query( \WP_Query $query ): void {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || 'product' !== $query->get( 'post_type' ) || ! self::is_sync_set_up() ) {
			return;
		}
		$clause = self::meta_clause( $this->requested_value() );
		if ( empty( $clause ) ) {
			return;
		}
		$existing = $query->get( 'meta_query' );
		$query->set( 'meta_query', self::merge_meta_query( is_array( $existing ) ? $existing : [], $clause ) );
	}

	private function requested_value(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$value = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';
		return in_array( $value, [ self::MANAGED, self::NOT_MANAGED ], true ) ? $value : '';
	}
}
