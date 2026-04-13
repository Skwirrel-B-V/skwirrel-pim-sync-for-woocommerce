<?php
/**
 * Skwirrel Theme API — Global wrapper functions.
 *
 * These functions delegate to Skwirrel_WC_Sync_Theme_API static methods.
 * Available after plugins_loaded.
 *
 * @package Skwirrel_PIM_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'skwirrel_get_variation_url' ) ) {
	/**
	 * Get the clean permalink URL for a variation.
	 *
	 * @param int $variation_id WC product variation ID.
	 * @return string Full URL, or empty string.
	 */
	function skwirrel_get_variation_url( int $variation_id ): string {
		return Skwirrel_WC_Sync_Theme_API::get_variation_url( $variation_id );
	}
}

if ( ! function_exists( 'skwirrel_get_default_variation' ) ) {
	/**
	 * Get the default (first) variation for a variable product.
	 *
	 * @param int $product_id WC variable product ID.
	 * @return WC_Product_Variation|null
	 */
	function skwirrel_get_default_variation( int $product_id ): ?WC_Product_Variation {
		return Skwirrel_WC_Sync_Theme_API::get_default_variation( $product_id );
	}
}

if ( ! function_exists( 'skwirrel_get_variation_thumbnail' ) ) {
	/**
	 * Get the thumbnail HTML for a specific variation.
	 *
	 * @param int    $variation_id WC product variation ID.
	 * @param string $size         Image size.
	 * @return string Image HTML, or empty string.
	 */
	function skwirrel_get_variation_thumbnail( int $variation_id, string $size = 'woocommerce_thumbnail' ): string {
		return Skwirrel_WC_Sync_Theme_API::get_variation_thumbnail( $variation_id, $size );
	}
}

if ( ! function_exists( 'skwirrel_get_all_variations_with_urls' ) ) {
	/**
	 * Get all variations for a product with their URLs.
	 *
	 * @param int $product_id WC variable product ID.
	 * @return array<int, array{id: int, sku: string, url: string, attributes: array<string, string>}>
	 */
	function skwirrel_get_all_variations_with_urls( int $product_id ): array {
		return Skwirrel_WC_Sync_Theme_API::get_all_variations_with_urls( $product_id );
	}
}

if ( ! function_exists( 'skwirrel_is_skwirrel_product' ) ) {
	/**
	 * Check if a product is managed by Skwirrel.
	 *
	 * @param int $product_id WC product ID.
	 * @return bool
	 */
	function skwirrel_is_skwirrel_product( int $product_id ): bool {
		return Skwirrel_WC_Sync_Theme_API::is_skwirrel_product( $product_id );
	}
}
