<?php
/**
 * Skwirrel Theme API.
 *
 * Public helper functions for theme developers to work with
 * Skwirrel-synced products and variations.
 *
 * Usage in themes:
 *   $url = skwirrel_get_variation_url( $variation_id );
 *   $variation = skwirrel_get_default_variation( $product_id );
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Theme_API {

	/**
	 * Get the clean permalink URL for a variation.
	 *
	 * Returns /product/{parent-slug}/{variation-slug}/ when variation
	 * permalinks are enabled, otherwise falls back to the standard
	 * WooCommerce variation URL with query parameters.
	 *
	 * @param int $variation_id WC product variation ID.
	 * @return string Full URL, or empty string if not a valid variation.
	 */
	public static function get_variation_url( int $variation_id ): string {
		if ( class_exists( 'Skwirrel_WC_Sync_Variation_Permalinks' ) && Skwirrel_WC_Sync_Variation_Permalinks::is_enabled() ) {
			$url = Skwirrel_WC_Sync_Variation_Permalinks::get_variation_url( $variation_id );
			if ( '' !== $url ) {
				return $url;
			}
		}

		// Fallback: standard WooCommerce variation URL.
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return '';
		}

		$parent_url = get_permalink( $variation->get_parent_id() );
		if ( ! $parent_url ) {
			return '';
		}

		$attrs      = $variation->get_attributes();
		$query_args = [];
		foreach ( $attrs as $key => $value ) {
			$query_args[ 'attribute_' . $key ] = $value;
		}

		return add_query_arg( $query_args, $parent_url );
	}

	/**
	 * Get the default (first) variation for a variable product.
	 *
	 * @param int $product_id WC variable product ID.
	 * @return WC_Product_Variation|null The first variation, or null.
	 */
	public static function get_default_variation( int $product_id ): ?WC_Product_Variation {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return null;
		}

		$children = $product->get_children();
		if ( empty( $children ) ) {
			return null;
		}

		$variation = wc_get_product( $children[0] );
		return $variation instanceof WC_Product_Variation ? $variation : null;
	}

	/**
	 * Get the thumbnail HTML for a specific variation.
	 *
	 * @param int    $variation_id WC product variation ID.
	 * @param string $size         Image size (default: woocommerce_thumbnail).
	 * @return string Image HTML, or empty string.
	 */
	public static function get_variation_thumbnail( int $variation_id, string $size = 'woocommerce_thumbnail' ): string {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return '';
		}

		$image_id = $variation->get_image_id();
		if ( ! $image_id ) {
			// Fall back to parent product image.
			$parent   = wc_get_product( $variation->get_parent_id() );
			$image_id = $parent ? $parent->get_image_id() : 0;
		}

		if ( ! $image_id ) {
			return wc_placeholder_img( $size );
		}

		return wp_get_attachment_image( (int) $image_id, $size );
	}

	/**
	 * Get all variations for a product with their URLs.
	 *
	 * @param int $product_id WC variable product ID.
	 * @return array<int, array{id: int, sku: string, url: string, attributes: array<string, string>}>
	 */
	public static function get_all_variations_with_urls( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return [];
		}

		$result = [];
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$result[] = [
				'id'         => $child_id,
				'sku'        => $variation->get_sku(),
				'url'        => self::get_variation_url( $child_id ),
				'attributes' => $variation->get_attributes(),
			];
		}

		return $result;
	}

	/**
	 * Check if a product is managed by Skwirrel.
	 *
	 * @param int $product_id WC product ID.
	 * @return bool
	 */
	public static function is_skwirrel_product( int $product_id ): bool {
		return ! empty( get_post_meta( $product_id, '_skwirrel_product_id', true ) )
			|| ! empty( get_post_meta( $product_id, '_skwirrel_external_id', true ) )
			|| ! empty( get_post_meta( $product_id, '_skwirrel_grouped_product_id', true ) );
	}
}

// Global wrapper functions are in theme-api-functions.php (loaded separately).
