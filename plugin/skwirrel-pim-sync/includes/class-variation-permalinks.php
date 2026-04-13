<?php
/**
 * Skwirrel Variation Permalinks.
 *
 * Registers rewrite rules for clean variation URLs:
 * /product/{parent-slug}/{variation-slug}/
 *
 * Resolves the URL to the parent product page with the correct variation
 * pre-selected via JavaScript.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Variation_Permalinks {

	/** Query variable name for the variation slug. */
	public const QUERY_VAR = 'skwirrel_variation';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'handle_variation_url' ] );
	}

	/**
	 * Check if variation permalinks are enabled in settings.
	 */
	public static function is_enabled(): bool {
		if ( class_exists( 'Skwirrel_WC_Sync_Permalink_Settings' ) ) {
			$opts = Skwirrel_WC_Sync_Permalink_Settings::get_options();
			return ! empty( $opts['variation_permalink_enabled'] );
		}
		return false;
	}

	/**
	 * Add rewrite rules for variation URLs.
	 *
	 * Maps: /product/{product-slug}/{variation-slug}/ → product page with variation query var.
	 */
	public function add_rewrite_rules(): void {
		$product_base = $this->get_product_permalink_base();

		add_rewrite_rule(
			'^' . preg_quote( $product_base, '/' ) . '/([^/]+)/([^/]+)/?$',
			'index.php?product=$matches[1]&' . self::QUERY_VAR . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Register the variation query variable.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Handle variation URL on template redirect.
	 *
	 * Detects the variation query var, looks up the variation by slug,
	 * and injects inline JS to pre-select it on the product page.
	 */
	public function handle_variation_url(): void {
		$variation_slug = get_query_var( self::QUERY_VAR );
		if ( empty( $variation_slug ) || ! is_singular( 'product' ) ) {
			return;
		}

		$product_id = get_queried_object_id();
		if ( ! $product_id ) {
			return;
		}

		$variation_id = $this->find_variation_by_slug( $variation_slug, $product_id );
		if ( ! $variation_id ) {
			return;
		}

		// Pass the variation ID to the frontend for pre-selection.
		add_action(
			'wp_footer',
			function () use ( $variation_id ) {
				?>
				<script>
				(function() {
					var form = document.querySelector('form.variations_form');
					if (!form) return;
					var variationId = <?php echo (int) $variation_id; ?>;
					form.setAttribute('data-preselected-variation', variationId);
					if (typeof jQuery !== 'undefined') {
						jQuery(form).on('show_variation', function() {
							// Variation display is handled by WooCommerce.
						});
						// Trigger WooCommerce to load this specific variation.
						jQuery(form).trigger('check_variations');
						var $form = jQuery(form);
						if ($form.data('product_variations')) {
							var variations = $form.data('product_variations');
							for (var i = 0; i < variations.length; i++) {
								if (variations[i].variation_id === variationId) {
									var attrs = variations[i].attributes;
									for (var key in attrs) {
										if (attrs.hasOwnProperty(key)) {
											var $select = $form.find('[name="' + key + '"]');
											if ($select.length) {
												$select.val(attrs[key]).trigger('change');
											}
										}
									}
									break;
								}
							}
							$form.trigger('woocommerce_variation_select_change')
								.trigger('check_variations');
						}
					}
				})();
				</script>
				<?php
			},
			99
		);
	}

	/**
	 * Get the URL for a specific variation.
	 *
	 * Returns a clean permalink like /product/{parent-slug}/{variation-slug}/
	 *
	 * @param int $variation_id WC product variation ID.
	 * @return string Full URL, or empty string if not applicable.
	 */
	public static function get_variation_url( int $variation_id ): string {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return '';
		}

		$parent_id = $variation->get_parent_id();
		if ( ! $parent_id ) {
			return '';
		}

		$variation_slug = get_post_field( 'post_name', $variation_id );
		if ( empty( $variation_slug ) ) {
			return '';
		}

		$parent_url = get_permalink( $parent_id );
		if ( ! $parent_url ) {
			return '';
		}

		// Append variation slug to parent URL.
		return trailingslashit( $parent_url ) . $variation_slug . '/';
	}

	/**
	 * Find a variation by its post_name (slug) under a specific parent product.
	 *
	 * @param string $slug      Variation slug.
	 * @param int    $parent_id Parent product ID.
	 * @return int|null Variation ID or null.
	 */
	private function find_variation_by_slug( string $slug, int $parent_id ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'product_variation' AND post_parent = %d AND post_status != 'trash' LIMIT 1",
				$slug,
				$parent_id
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Get the WooCommerce product permalink base (e.g. "product" or "shop").
	 *
	 * @return string
	 */
	private function get_product_permalink_base(): string {
		$permalinks = wc_get_permalink_structure();
		$base       = $permalinks['product_base'] ?? '';

		// Strip leading/trailing slashes and any /%product_cat% placeholder.
		$base = trim( $base, '/' );
		$base = preg_replace( '/%[^%]+%/', '', $base );
		$base = trim( (string) $base, '/' );

		return '' !== $base ? $base : 'product';
	}

	/**
	 * Flush rewrite rules. Call on activation or when permalink settings change.
	 */
	public static function flush_rules(): void {
		if ( self::is_enabled() ) {
			$inst = self::instance();
			$inst->add_rewrite_rules();
		}
		flush_rewrite_rules();
	}
}
