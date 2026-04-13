<?php
/**
 * Skwirrel Product Sync Meta Box.
 *
 * Adds a "Skwirrel" meta box to the WooCommerce product edit screen
 * allowing single-product sync directly from the product editor.
 * Also adds an "API Response" meta box showing the stored Skwirrel JSON.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_Sync_Meta_Box {

	/** AJAX action name. */
	private const AJAX_ACTION = 'skwirrel_wc_sync_single_product';

	/** AJAX action for fetching variation API responses. */
	private const AJAX_ACTION_VARIATIONS = 'skwirrel_wc_fetch_variation_responses';

	/** Whether inline styles have been rendered on this page load. */
	private static bool $styles_rendered = false;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_ajax_sync' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_VARIATIONS, [ $this, 'handle_ajax_variation_responses' ] );
	}

	/**
	 * Register meta boxes on the product edit screen.
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'skwirrel-product-sync',
			__( 'Skwirrel', 'skwirrel-pim-sync' ),
			[ $this, 'render_meta_box' ],
			'product',
			'side',
			'high'
		);

		// Only add API response box for Skwirrel-managed products.
		global $post;
		if ( $post && (
			get_post_meta( $post->ID, '_skwirrel_product_id', true )
			|| get_post_meta( $post->ID, '_skwirrel_external_id', true )
			|| get_post_meta( $post->ID, '_skwirrel_grouped_product_id', true )
		) ) {
			add_meta_box(
				'skwirrel-api-response',
				__( 'Skwirrel API Response', 'skwirrel-pim-sync' ),
				[ $this, 'render_api_response_meta_box' ],
				'product',
				'normal',
				'low'
			);
		}
	}

	/**
	 * Render the sync meta box content.
	 *
	 * Shows Skwirrel product ID and last sync timestamp if available,
	 * plus a "Sync this product" button.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ): void {
		$skwirrel_product_id = get_post_meta( $post->ID, '_skwirrel_product_id', true );
		$external_id         = get_post_meta( $post->ID, '_skwirrel_external_id', true );
		$synced_at           = get_post_meta( $post->ID, '_skwirrel_synced_at', true );

		$grouped_id = get_post_meta( $post->ID, '_skwirrel_grouped_product_id', true );

		// Only show sync button for Skwirrel-managed products.
		if ( empty( $skwirrel_product_id ) && empty( $external_id ) && empty( $grouped_id ) ) {
			echo '<p class="description">' . esc_html__( 'This product is not managed by Skwirrel.', 'skwirrel-pim-sync' ) . '</p>';
			return;
		}

		// Variable product shells (grouped products): show info + sync button.
		if ( empty( $skwirrel_product_id ) && ! empty( $grouped_id ) ) {
			$virtual_product_id = get_post_meta( $post->ID, '_skwirrel_virtual_product_id', true );
			wp_nonce_field( self::AJAX_ACTION, 'skwirrel_sync_nonce', false );
			?>
			<div class="skwirrel-product-sync-box">
				<p>
					<strong><?php esc_html_e( 'Grouped product ID:', 'skwirrel-pim-sync' ); ?></strong>
					<?php echo esc_html( (string) $grouped_id ); ?>
				</p>
				<?php if ( $virtual_product_id ) : ?>
					<p>
						<strong><?php esc_html_e( 'Virtual product ID:', 'skwirrel-pim-sync' ); ?></strong>
						<?php echo esc_html( (string) $virtual_product_id ); ?>
						<span class="description">(<?php esc_html_e( 'content & images', 'skwirrel-pim-sync' ); ?>)</span>
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
				<p class="description" style="margin-top: 8px;">
					<a href="#skwirrel-api-response" onclick="document.getElementById('skwirrel-api-response').scrollIntoView({behavior:'smooth'});return false;"><?php esc_html_e( 'View API response', 'skwirrel-pim-sync' ); ?> &darr;</a>
				</p>
			</div>
			<script>
			(function() {
				function showNotice(container, type, text) {
					container.textContent = '';
					var wrap = document.createElement('div');
					wrap.className = 'notice notice-' + type + ' inline';
					var p = document.createElement('p');
					p.textContent = text;
					wrap.appendChild(p);
					container.appendChild(wrap);
				}

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
							showNotice(resultDiv, 'success', response.data.message);
						} else {
							var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Sync failed.', 'skwirrel-pim-sync' ) ); ?>';
							showNotice(resultDiv, 'error', msg);
						}
					})
					.catch(function() {
						spinner.classList.remove('is-active');
						btn.disabled = false;
						resultDiv.style.display = 'block';
						showNotice(resultDiv, 'error', '<?php echo esc_js( __( 'Network error. Please try again.', 'skwirrel-pim-sync' ) ); ?>');
					});
				});
			})();
			</script>
			<?php
			return;
		}

		// Variation: show link to parent variable product.
		$parent_id = wp_get_post_parent_id( $post->ID );
		if ( $parent_id && get_post_meta( $parent_id, '_skwirrel_grouped_product_id', true ) ) {
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
					<strong><?php esc_html_e( 'Parent product:', 'skwirrel-pim-sync' ); ?></strong>
					<a href="<?php echo esc_url( get_edit_post_link( $parent_id ) ?? '' ); ?>"><?php echo esc_html( get_the_title( $parent_id ) ); ?></a>
				</p>
			</div>
			<?php
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
		</div>
		<script>
		(function() {
			function showNotice(container, type, text) {
				container.textContent = '';
				var wrap = document.createElement('div');
				wrap.className = 'notice notice-' + type + ' inline';
				var p = document.createElement('p');
				p.textContent = text;
				wrap.appendChild(p);
				container.appendChild(wrap);
			}

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
						showNotice(resultDiv, 'success', response.data.message);
					} else {
						var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Sync failed.', 'skwirrel-pim-sync' ) ); ?>';
						showNotice(resultDiv, 'error', msg);
					}
				})
				.catch(function() {
					spinner.classList.remove('is-active');
					btn.disabled = false;
					resultDiv.style.display = 'block';
					showNotice(resultDiv, 'error', '<?php echo esc_js( __( 'Network error. Please try again.', 'skwirrel-pim-sync' ) ); ?>');
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render the API Response meta box.
	 *
	 * Shows the stored raw JSON from the last Skwirrel sync.
	 * For grouped products, also offers lazy-loaded variation responses.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_api_response_meta_box( $post ): void {
		$json       = get_post_meta( $post->ID, '_skwirrel_api_response', true );
		$grouped_id = get_post_meta( $post->ID, '_skwirrel_grouped_product_id', true );
		$is_grouped = ! empty( $grouped_id );

		if ( empty( $json ) ) {
			echo '<p class="description">' . esc_html__( 'No API response stored yet. Sync this product first.', 'skwirrel-pim-sync' ) . '</p>';
			return;
		}

		$decoded = json_decode( $json );
		if ( null !== $decoded ) {
			$json = (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		}

		$this->render_api_response_styles();

		if ( $is_grouped ) {
			echo '<details open class="skw-api-section">';
			echo '<summary class="skw-api-section-header">' . esc_html__( 'Grouped Product Response', 'skwirrel-pim-sync' ) . '</summary>';
			$this->render_json_block( $json );
			echo '</details>';

			echo '<details class="skw-api-section" style="margin-top: 12px;">';
			echo '<summary class="skw-api-section-header">' . esc_html__( 'Variation Responses', 'skwirrel-pim-sync' ) . '</summary>';
			echo '<div id="skw-variation-responses">';
			echo '<p><button type="button" class="button" id="skw-load-variations-btn">';
			echo esc_html__( 'Load variation API responses', 'skwirrel-pim-sync' );
			echo '</button>';
			echo '<span class="spinner" id="skw-variations-spinner" style="float: none; margin-top: 0;"></span></p>';
			echo '<div id="skw-variations-container"></div>';
			echo '</div>';
			echo '</details>';

			wp_nonce_field( self::AJAX_ACTION_VARIATIONS, 'skwirrel_variations_nonce', false );
			$this->render_variation_loader_script( $post->ID );
		} else {
			$this->render_json_block( $json );
		}
	}

	/**
	 * Render a prettified, syntax-highlighted JSON block.
	 *
	 * @param string $json Pretty-printed JSON string.
	 */
	private function render_json_block( string $json ): void {
		$escaped     = esc_html( $json );
		$highlighted = $this->highlight_json( $escaped );
		echo '<pre class="skw-api-json-block"><code>' . $highlighted . '</code></pre>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped by esc_html + span-wrapping
	}

	/**
	 * Emit inline CSS for the API response meta box (once per page load).
	 */
	private function render_api_response_styles(): void {
		if ( self::$styles_rendered ) {
			return;
		}
		self::$styles_rendered = true;
		?>
		<style>
			.skw-api-section { margin-bottom: 0; }
			.skw-api-section-header {
				cursor: pointer;
				padding: 8px 0;
				font-weight: 600;
				font-size: 13px;
				user-select: none;
			}
			.skw-api-section-header:hover { color: #2271b1; }
			.skw-api-json-block {
				background: #f0f0f1;
				border: 1px solid #c3c4c7;
				padding: 10px;
				max-height: 500px;
				overflow: auto;
				font-size: 12px;
				line-height: 1.4;
				white-space: pre-wrap;
				word-wrap: break-word;
				margin: 8px 0;
			}
			.skw-json-key { color: #881391; }
			.skw-json-str { color: #1a1aa6; }
			.skw-json-num { color: #098658; }
			.skw-json-bool { color: #0451a5; font-weight: 600; }
		</style>
		<?php
	}

	/**
	 * Apply lightweight syntax highlighting to HTML-escaped JSON.
	 *
	 * @param string $escaped_json esc_html()-escaped JSON string.
	 * @return string JSON with span-wrapped tokens.
	 */
	private function highlight_json( string $escaped_json ): string {
		// Keys: "key":
		$result = preg_replace(
			'/(&quot;[^&]*?&quot;)\s*:/',
			'<span class="skw-json-key">$1</span>:',
			$escaped_json
		);
		if ( null === $result ) {
			return $escaped_json;
		}
		// String values (not already wrapped in a span)
		$result = preg_replace(
			'/(?<!<span class="skw-json-key">)(?<!:)\s*(&quot;[^&]*?&quot;)(?!\s*:)/',
			' <span class="skw-json-str">$1</span>',
			$result
		) ?? $result;
		// Numbers
		$result = preg_replace(
			'/(?<=: )(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/',
			'<span class="skw-json-num">$1</span>',
			$result
		) ?? $result;
		// Booleans & null
		$result = preg_replace(
			'/(?<=: )(true|false|null)\b/',
			'<span class="skw-json-bool">$1</span>',
			$result
		) ?? $result;
		return $result;
	}

	/**
	 * Emit inline JS for lazy-loading variation API responses via AJAX.
	 *
	 * @param int $post_id WC variable product ID.
	 */
	private function render_variation_loader_script( int $post_id ): void {
		?>
		<script>
		(function() {
			var btn = document.getElementById('skw-load-variations-btn');
			var spinner = document.getElementById('skw-variations-spinner');
			var container = document.getElementById('skw-variations-container');
			if (!btn) return;

			function highlightJson(text) {
				var esc = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
				esc = esc.replace(/(&quot;[^&]*?&quot;)\s*:/g, '<span class="skw-json-key">$1</span>:');
				esc = esc.replace(/(?<=: )(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g, '<span class="skw-json-num">$1</span>');
				esc = esc.replace(/(?<=: )(true|false|null)\b/g, '<span class="skw-json-bool">$1</span>');
				return esc;
			}

			btn.addEventListener('click', function() {
				btn.disabled = true;
				spinner.classList.add('is-active');

				var data = new FormData();
				data.append('action', '<?php echo esc_js( self::AJAX_ACTION_VARIATIONS ); ?>');
				data.append('wc_product_id', '<?php echo esc_js( (string) $post_id ); ?>');
				data.append('_wpnonce', document.getElementById('skwirrel_variations_nonce').value);

				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
				.then(function(r) { return r.json(); })
				.then(function(response) {
					spinner.classList.remove('is-active');
					btn.style.display = 'none';

					if (!response.success || !response.data.variations.length) {
						container.textContent = '<?php echo esc_js( __( 'No variation API responses found.', 'skwirrel-pim-sync' ) ); ?>';
						return;
					}

					var html = '';
					response.data.variations.forEach(function(v) {
						html += '<details class="skw-api-section" style="margin-top: 8px;">';
						html += '<summary class="skw-api-section-header"><?php echo esc_js( __( 'Variation:', 'skwirrel-pim-sync' ) ); ?> ' + v.sku + '</summary>';
						html += '<pre class="skw-api-json-block"><code>' + highlightJson(v.json) + '</code></pre>';
						html += '</details>';
					});
					container.innerHTML = html;
				})
				.catch(function() {
					spinner.classList.remove('is-active');
					btn.disabled = false;
					container.textContent = '<?php echo esc_js( __( 'Failed to load variation responses.', 'skwirrel-pim-sync' ) ); ?>';
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handle AJAX request for variation API responses.
	 */
	public function handle_ajax_variation_responses(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'skwirrel-pim-sync' ) ], 403 );
		}

		check_ajax_referer( self::AJAX_ACTION_VARIATIONS, '_wpnonce' );

		$wc_product_id = isset( $_POST['wc_product_id'] ) ? absint( $_POST['wc_product_id'] ) : 0;
		if ( ! $wc_product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'skwirrel-pim-sync' ) ] );
		}

		$variation_ids = wc_get_products(
			[
				'parent' => $wc_product_id,
				'type'   => 'variation',
				'limit'  => 50,
				'return' => 'ids',
			]
		);

		$variations = [];
		foreach ( $variation_ids as $vid ) {
			$v_product = wc_get_product( $vid );
			$v_sku     = $v_product ? $v_product->get_sku() : '#' . $vid;
			$v_json    = get_post_meta( $vid, '_skwirrel_api_response', true );

			if ( empty( $v_json ) ) {
				continue;
			}

			$decoded = json_decode( $v_json );
			if ( null !== $decoded ) {
				$v_json = (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			}

			$variations[] = [
				'id'   => $vid,
				'sku'  => $v_sku,
				'json' => $v_json,
			];
		}

		wp_send_json_success( [ 'variations' => $variations ] );
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

		$service    = new Skwirrel_WC_Sync_Service();
		$grouped_id = get_post_meta( $wc_product_id, '_skwirrel_grouped_product_id', true );

		if ( ! empty( $grouped_id ) ) {
			// Grouped product: sync the entire group including all variations.
			$result = $service->sync_single_grouped_product( (int) $grouped_id );

			if ( $result['success'] ) {
				$created = $result['created'] ?? 0;
				$updated = $result['updated'] ?? 0;
				/* translators: 1: number of created variations, 2: number of updated variations */
				$message = sprintf( __( 'Grouped product synced successfully (%1$d created, %2$d updated).', 'skwirrel-pim-sync' ), $created, $updated );
				wp_send_json_success( [ 'message' => $message ] );
			}

			wp_send_json_error( [ 'message' => $result['error'] ?? __( 'Sync failed.', 'skwirrel-pim-sync' ) ] );
		}

		$skwirrel_product_id = get_post_meta( $wc_product_id, '_skwirrel_product_id', true );
		if ( empty( $skwirrel_product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'No Skwirrel product ID found for this product.', 'skwirrel-pim-sync' ) ] );
		}

		$result = $service->sync_single_product( (int) $skwirrel_product_id );

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
		}

		wp_send_json_error( [ 'message' => $result['error'] ?? __( 'Sync failed.', 'skwirrel-pim-sync' ) ] );
	}
}
