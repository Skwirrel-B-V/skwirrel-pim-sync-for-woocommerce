<?php
/**
 * Skwirrel Sync — PIM deep-link builder.
 *
 * Builds the URL that opens the matching product in the Skwirrel PIM web UI.
 * The host is derived from the configured JSON-RPC endpoint and the path is
 * fixed (`/catalogue/products/edit/{product_id}`).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Pim_Link {

	private const URL_PATH_PRODUCT         = '/catalogue/products/edit/';
	private const URL_PATH_GROUPED_PRODUCT = '/catalogue/grouped-products/edit/';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'post_row_actions', [ $this, 'add_row_action' ], 10, 2 );
	}

	/**
	 * Build the deep-link URL for a WooCommerce product.
	 *
	 * Returns null when the product is not Skwirrel-managed or the host cannot
	 * be derived from the configured endpoint URL.
	 */
	public static function build_url( int $wc_product_id ): ?string {
		if ( $wc_product_id <= 0 ) {
			return null;
		}

		$skw_id     = (string) get_post_meta( $wc_product_id, '_skwirrel_product_id', true );
		$grouped_id = (string) get_post_meta( $wc_product_id, '_skwirrel_grouped_product_id', true );

		// Variable/grouped product shells have no _skwirrel_product_id of their own.
		// Skwirrel exposes grouped products on a separate path.
		if ( '' !== $skw_id ) {
			$path       = self::URL_PATH_PRODUCT;
			$product_id = $skw_id;
		} elseif ( '' !== $grouped_id ) {
			$path       = self::URL_PATH_GROUPED_PRODUCT;
			$product_id = $grouped_id;
		} else {
			return null;
		}

		$opts     = get_option( 'skwirrel_wc_sync_settings', [] );
		$endpoint = is_array( $opts ) ? (string) ( $opts['endpoint_url'] ?? '' ) : '';
		$host     = self::derive_host_from_endpoint( $endpoint );
		if ( null === $host ) {
			return null;
		}

		return $host . $path . rawurlencode( $product_id );
	}

	/**
	 * Derive a host (scheme://host[:port]) from the configured JSON-RPC endpoint URL.
	 */
	private static function derive_host_from_endpoint( string $endpoint ): ?string {
		$endpoint = trim( $endpoint );
		if ( '' === $endpoint ) {
			return null;
		}

		$parts = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure host parsing on admin-supplied endpoint, no remote fetch.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$host = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$host .= ':' . $parts['port'];
		}
		return $host;
	}

	/**
	 * Add an "Open in Skwirrel" link to the WP Products list row actions.
	 *
	 * @param array<string,string> $actions Existing row actions.
	 * @return array<string,string>
	 */
	public function add_row_action( array $actions, \WP_Post $post ): array {
		if ( 'product' !== $post->post_type ) {
			return $actions;
		}

		$url = self::build_url( (int) $post->ID );
		if ( null === $url ) {
			return $actions;
		}

		$actions['skwirrel_open_pim'] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html__( 'Open in Skwirrel', 'skwirrel-pim-sync' )
		);

		return $actions;
	}
}
