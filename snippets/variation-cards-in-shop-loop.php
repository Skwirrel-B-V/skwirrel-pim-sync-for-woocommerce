<?php
/**
 * Example: Show variation cards on WooCommerce archive/category pages.
 *
 * This snippet shows each variation as a separate product card in the shop loop,
 * with its own image, price, and direct link.
 *
 * HOW TO USE:
 * Add this code to your theme's functions.php or a custom plugin.
 * Adjust the HTML/CSS to match your theme's product card structure.
 *
 * REQUIRES:
 * - Skwirrel PIM sync for WooCommerce 3.0.0+
 * - Grouped products synced with variations
 *
 * @package Skwirrel_PIM_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replace variable products with their individual variations in the shop loop.
 *
 * Hooks into woocommerce_after_shop_loop_item to render variation cards
 * beneath the parent product card. Hides the parent card via CSS.
 */
add_action( 'woocommerce_after_shop_loop_item', 'skwirrel_show_variation_cards', 20 );

function skwirrel_show_variation_cards(): void {
	global $product;

	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return;
	}

	// Only for Skwirrel-managed products.
	if ( ! function_exists( 'skwirrel_is_skwirrel_product' ) || ! skwirrel_is_skwirrel_product( $product->get_id() ) ) {
		return;
	}

	if ( ! function_exists( 'skwirrel_get_all_variations_with_urls' ) ) {
		return;
	}

	$variations = skwirrel_get_all_variations_with_urls( $product->get_id() );
	if ( empty( $variations ) ) {
		return;
	}

	foreach ( $variations as $var_data ) {
		$variation = wc_get_product( $var_data['id'] );
		if ( ! $variation ) {
			continue;
		}

		$url       = $var_data['url'];
		$thumbnail = function_exists( 'skwirrel_get_variation_thumbnail' )
			? skwirrel_get_variation_thumbnail( $var_data['id'] )
			: '';
		$price     = $variation->get_price_html();
		$name      = $variation->get_name();
		$attrs     = implode( ', ', array_values( $var_data['attributes'] ) );

		?>
		<div class="skwirrel-variation-card">
			<a href="<?php echo esc_url( $url ); ?>" class="skwirrel-variation-link">
				<?php if ( $thumbnail ) : ?>
					<div class="skwirrel-variation-image"><?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endif; ?>
				<h3 class="skwirrel-variation-title"><?php echo esc_html( $name ); ?></h3>
				<?php if ( $attrs ) : ?>
					<p class="skwirrel-variation-attrs"><?php echo esc_html( $attrs ); ?></p>
				<?php endif; ?>
				<?php if ( $price ) : ?>
					<span class="skwirrel-variation-price"><?php echo $price; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php endif; ?>
			</a>
		</div>
		<?php
	}
}

/**
 * Optional: Hide the parent variable product card when variations are shown.
 *
 * Uncomment the CSS rule below if you want to completely replace the parent
 * product card with individual variation cards.
 */
/*
add_action( 'wp_head', function() {
	if ( is_shop() || is_product_category() || is_product_tag() ) {
		echo '<style>
			.product.product-type-variable > .woocommerce-loop-product__link { display: none; }
			.product.product-type-variable > .button { display: none; }
		</style>';
	}
});
*/
