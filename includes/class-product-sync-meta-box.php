<?php
/**
 * Skwirrel Product Sync Meta Box.
 *
 * Adds a "Skwirrel" meta box to the WooCommerce product edit screen
 * allowing single-product sync directly from the product editor.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_Sync_Meta_Box {

	/** AJAX action name. */
	private const AJAX_ACTION = 'skwirrel_wc_sync_single_product';

	/** AJAX action for fetching raw API JSON. */
	private const AJAX_FETCH_JSON = 'skwirrel_wc_fetch_api_json';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_ajax_sync' ] );
		add_action( 'wp_ajax_' . self::AJAX_FETCH_JSON, [ $this, 'handle_ajax_fetch_json' ] );
	}

	/**
	 * Register meta box on the product edit screen, positioned above Publish.
	 */
	public function register_meta_box(): void {
		add_meta_box(
			'skwirrel-product-sync',
			__( 'Skwirrel', 'skwirrel-pim-sync' ),
			[ $this, 'render_meta_box' ],
			'product',
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * Shows Skwirrel product ID and last sync timestamp if available,
	 * plus a "Sync this product" button and "Show API response" toggle.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ): void {
		$skwirrel_product_id = get_post_meta( $post->ID, '_skwirrel_product_id', true );
		$external_id         = get_post_meta( $post->ID, '_skwirrel_external_id', true );
		$synced_at           = get_post_meta( $post->ID, '_skwirrel_synced_at', true );

		// Only show sync button for Skwirrel-managed products.
		if ( empty( $skwirrel_product_id ) && empty( $external_id ) ) {
			echo '<p class="description">' . esc_html__( 'This product is not managed by Skwirrel.', 'skwirrel-pim-sync' ) . '</p>';
			return;
		}

		wp_nonce_field( self::AJAX_ACTION, 'skwirrel_sync_nonce', false );
		?>
		<div class="skwirrel-product-sync-box">
			<?php if ( $skwirrel_product_id ) : ?>
				<p>
					<strong><?php esc_html_e( 'Product ID:', 'skwirrel-pim-sync' ); ?></strong>
					<?php echo esc_html( (string) $skwirrel_product_id ); ?>
				</p>
			<?php endif; ?>
			<?php if ( $synced_at ) : ?>
				<p>
					<strong><?php esc_html_e( 'Last synced:', 'skwirrel-pim-sync' ); ?></strong>
					<?php
					$date_format = get_option( 'date_format', 'Y-m-d' );
					$time_format = get_option( 'time_format', 'H:i' );
					echo esc_html( wp_date( $date_format . ' ' . $time_format, (int) $synced_at ) );
					?>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary" id="skwirrel-sync-product-btn">
					<?php esc_html_e( 'Sync this product', 'skwirrel-pim-sync' ); ?>
				</button>
				<span class="spinner" id="skwirrel-sync-spinner" style="float: none; margin-top: 0;"></span>
			</p>
			<div id="skwirrel-sync-result" style="display: none; margin-top: 8px;"></div>

			<?php if ( $skwirrel_product_id ) : ?>
				<hr style="margin: 12px 0;" />
				<p>
					<button type="button" class="button" id="skwirrel-fetch-json-btn">
						<?php esc_html_e( 'Show API response', 'skwirrel-pim-sync' ); ?>
					</button>
					<span class="spinner" id="skwirrel-json-spinner" style="float: none; margin-top: 0;"></span>
				</p>
				<div id="skwirrel-json-output" style="display: none; margin-top: 8px;">
					<pre style="background: #f0f0f1; border: 1px solid #c3c4c7; padding: 8px; max-height: 400px; overflow: auto; font-size: 11px; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word;"><code id="skwirrel-json-code"></code></pre>
				</div>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			var btn = document.getElementById('skwirrel-sync-product-btn');
			var spinner = document.getElementById('skwirrel-sync-spinner');
			var resultDiv = document.getElementById('skwirrel-sync-result');
			if (!btn) return;

			btn.addEventListener('click', function() {
				btn.disabled = true;
				spinner.classList.add('is-active');
				resultDiv.style.display = 'none';

				var data = new FormData();
				data.append('action', '<?php echo esc_js( self::AJAX_ACTION ); ?>');
				data.append('wc_product_id', '<?php echo esc_js( (string) $post->ID ); ?>');
				data.append('_wpnonce', document.getElementById('skwirrel_sync_nonce').value);

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
				.then(function(response) { return response.json(); })
				.then(function(response) {
					spinner.classList.remove('is-active');
					btn.disabled = false;
					resultDiv.style.display = 'block';

					if (response.success) {
						resultDiv.innerHTML = '<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>';
					} else {
						var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Sync failed.', 'skwirrel-pim-sync' ) ); ?>';
						resultDiv.innerHTML = '<div class="notice notice-error inline"><p>' + msg + '</p></div>';
					}
				})
				.catch(function() {
					spinner.classList.remove('is-active');
					btn.disabled = false;
					resultDiv.style.display = 'block';
					resultDiv.innerHTML = '<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Network error. Please try again.', 'skwirrel-pim-sync' ) ); ?></p></div>';
				});
			});

			/* Show API response toggle */
			var jsonBtn = document.getElementById('skwirrel-fetch-json-btn');
			var jsonSpinner = document.getElementById('skwirrel-json-spinner');
			var jsonOutput = document.getElementById('skwirrel-json-output');
			var jsonCode = document.getElementById('skwirrel-json-code');
			var jsonLoaded = false;

			if (!jsonBtn) return;

			jsonBtn.addEventListener('click', function() {
				if (jsonLoaded) {
					var visible = jsonOutput.style.display !== 'none';
					jsonOutput.style.display = visible ? 'none' : 'block';
					jsonBtn.textContent = visible
						? '<?php echo esc_js( __( 'Show API response', 'skwirrel-pim-sync' ) ); ?>'
						: '<?php echo esc_js( __( 'Hide API response', 'skwirrel-pim-sync' ) ); ?>';
					return;
				}

				jsonBtn.disabled = true;
				jsonSpinner.classList.add('is-active');

				var data = new FormData();
				data.append('action', '<?php echo esc_js( self::AJAX_FETCH_JSON ); ?>');
				data.append('wc_product_id', '<?php echo esc_js( (string) $post->ID ); ?>');
				data.append('_wpnonce', document.getElementById('skwirrel_sync_nonce').value);

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
				.then(function(response) { return response.json(); })
				.then(function(response) {
					jsonSpinner.classList.remove('is-active');
					jsonBtn.disabled = false;

					if (response.success) {
						jsonCode.textContent = JSON.stringify(response.data.json, null, 2);
						jsonOutput.style.display = 'block';
						jsonBtn.textContent = '<?php echo esc_js( __( 'Hide API response', 'skwirrel-pim-sync' ) ); ?>';
						jsonLoaded = true;
					} else {
						var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Failed to fetch API data.', 'skwirrel-pim-sync' ) ); ?>';
						jsonCode.textContent = msg;
						jsonOutput.style.display = 'block';
					}
				})
				.catch(function() {
					jsonSpinner.classList.remove('is-active');
					jsonBtn.disabled = false;
					jsonCode.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'skwirrel-pim-sync' ) ); ?>';
					jsonOutput.style.display = 'block';
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handle AJAX request for single-product sync.
	 */
	public function handle_ajax_sync(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'skwirrel-pim-sync' ) ], 403 );
		}

		check_ajax_referer( self::AJAX_ACTION, '_wpnonce' );

		$wc_product_id = isset( $_POST['wc_product_id'] ) ? absint( $_POST['wc_product_id'] ) : 0;
		if ( ! $wc_product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'skwirrel-pim-sync' ) ] );
		}

		$skwirrel_product_id = get_post_meta( $wc_product_id, '_skwirrel_product_id', true );
		if ( empty( $skwirrel_product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'No Skwirrel product ID found for this product.', 'skwirrel-pim-sync' ) ] );
		}

		$service = new Skwirrel_WC_Sync_Service();
		$result  = $service->sync_single_product( (int) $skwirrel_product_id );

		if ( $result['success'] ) {
			$outcome = $result['outcome'] ?? 'unknown';
			/* translators: %s: sync outcome (created/updated) */
			$message = sprintf( __( 'Product synced successfully (%s).', 'skwirrel-pim-sync' ), $outcome );
			wp_send_json_success(
				[
					'message' => $message,
					'outcome' => $outcome,
				]
			);
		} else {
			wp_send_json_error( [ 'message' => $result['error'] ?? __( 'Sync failed.', 'skwirrel-pim-sync' ) ] );
		}
	}

	/**
	 * Handle AJAX request to fetch raw API JSON for a product.
	 */
	public function handle_ajax_fetch_json(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'skwirrel-pim-sync' ) ], 403 );
		}

		check_ajax_referer( self::AJAX_ACTION, '_wpnonce' );

		$wc_product_id = isset( $_POST['wc_product_id'] ) ? absint( $_POST['wc_product_id'] ) : 0;
		if ( ! $wc_product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'skwirrel-pim-sync' ) ] );
		}

		$skwirrel_product_id = get_post_meta( $wc_product_id, '_skwirrel_product_id', true );
		if ( empty( $skwirrel_product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'No Skwirrel product ID found for this product.', 'skwirrel-pim-sync' ) ] );
		}

		$opts     = get_option( 'skwirrel_wc_sync_settings', [] );
		$endpoint = $opts['endpoint_url'] ?? '';
		$timeout  = (int) ( $opts['timeout'] ?? 30 );
		$retries  = (int) ( $opts['retries'] ?? 2 );

		if ( empty( $endpoint ) ) {
			wp_send_json_error( [ 'message' => __( 'API endpoint not configured.', 'skwirrel-pim-sync' ) ] );
		}

		$logger = new Skwirrel_WC_Sync_Logger();
		$client = new Skwirrel_WC_Sync_JsonRpc_Client( $endpoint, $logger, $timeout, $retries );

		$languages = $opts['include_languages'] ?? [ 'nl-NL', 'nl' ];
		if ( ! empty( $languages ) && is_array( $languages ) ) {
			$languages = array_values( array_filter( array_map( 'sanitize_text_field', $languages ) ) );
		} else {
			$languages = [ 'nl-NL', 'nl' ];
		}
		$req_options = [
			'include_product_status'       => true,
			'include_product_translations' => true,
			'include_attachments'          => true,
			'include_trade_items'          => true,
			'include_trade_item_prices'    => true,
			'include_categories'           => ! empty( $opts['sync_categories'] ),
			'include_product_groups'       => ! empty( $opts['sync_categories'] ) || ! empty( $opts['sync_grouped_products'] ),
			'include_grouped_products'     => ! empty( $opts['sync_grouped_products'] ),
			'include_etim'                 => true,
			'include_etim_translations'    => true,
			'include_languages'            => $languages,
			'include_contexts'             => [ 1 ],
		];

		if ( ! empty( $opts['sync_custom_classes'] ) ) {
			$req_options['include_custom_classes'] = true;
		}
		if ( ! empty( $opts['sync_trade_item_custom_classes'] ) ) {
			$req_options['include_trade_item_custom_classes'] = true;
		}

		$result = $client->call(
			'getProductsByFilter',
			[
				'filter'  => [
					'code' => [
						'type'  => 'product_id',
						'codes' => [ (string) $skwirrel_product_id ],
					],
				],
				'options' => $req_options,
				'page'    => 1,
				'limit'   => 1,
			]
		);

		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			wp_send_json_error( [ 'message' => $err['message'] ?? 'API error' ] );
		}

		$data    = $result['result'] ?? [];
		$product = $data['products'][0] ?? null;

		if ( null === $product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found in Skwirrel API.', 'skwirrel-pim-sync' ) ] );
		}

		wp_send_json_success( [ 'json' => $product ] );
	}
}
