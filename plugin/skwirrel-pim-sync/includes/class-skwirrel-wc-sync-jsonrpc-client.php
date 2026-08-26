<?php
/**
 * Skwirrel JSON-RPC 2.0 API Client.
 *
 * Supports Bearer token and X-Skwirrel-Api-Token authentication.
 * All requests require X-Skwirrel-Api-Version: 2 header.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_JsonRpc_Client {

	private string $endpoint;
	private string $auth_type;
	private string $auth_token;
	private int $timeout;
	private int $retries;
	private Skwirrel_WC_Sync_Logger $logger;
	private int $request_id = 0;

	public function __construct(
		string $endpoint,
		string $auth_type,
		string $auth_token,
		int $timeout = 30,
		int $retries = 2
	) {
		$this->endpoint   = rtrim( $endpoint, '/' );
		$this->auth_type  = $auth_type;
		$this->auth_token = $auth_token;
		$this->timeout    = max( 5, min( 120, $timeout ) );
		$this->retries    = max( 0, min( 5, $retries ) );
		$this->logger     = new Skwirrel_WC_Sync_Logger();
	}

	/**
	 * Call a JSON-RPC method.
	 *
	 * `meta` is measurement only — it carries what the transport did (how long, which HTTP code,
	 * how many attempts) so a caller can tell a timeout apart from a rejection. It is present on
	 * every return path; a branch that omitted it would render blank metrics.
	 *
	 * @param string $method Method name (e.g. getProducts, getProductsByFilter)
	 * @param array<string, mixed> $params Method parameters
	 * @return array{success: bool, result?: mixed, error?: array{code: int, message: string, data?: mixed}, meta: array{duration_ms: int, http_code: int, attempts: int}}
	 */
	public function call( string $method, array $params = [] ): array {
		$started_at = microtime( true );
		++$this->request_id;
		$body = [
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
			'id'      => $this->request_id,
		];

		$headers = [
			'Content-Type'           => 'application/json',
			'Accept'                 => 'application/json',
			'X-Skwirrel-Api-Version' => '2',
		];

		if ( 'bearer' === $this->auth_type ) {
			$headers['Authorization'] = 'Bearer ' . $this->auth_token;
		} elseif ( 'token' === $this->auth_type ) {
			$headers['X-Skwirrel-Api-Token'] = $this->auth_token;
		}

		$attempt    = 0;
		$attempts   = 0;
		$last_error = null;
		$last_code  = 0;

		while ( $attempt <= $this->retries ) {
			++$attempts;
			$response = wp_remote_post(
				$this->endpoint,
				[
					'timeout'   => $this->timeout,
					'headers'   => $headers,
					'body'      => wp_json_encode( $body ),
					'sslverify' => true,
				]
			);

			$code     = wp_remote_retrieve_response_code( $response );
			$body_raw = wp_remote_retrieve_body( $response );

			// A transport failure has no HTTP response, so the code above is '' — 0 after the cast.
			// That zero is what tells the formatter to say "no response" rather than print a status.
			$last_code = (int) $code;

			if ( is_wp_error( $response ) ) {
				$last_error = [
					'code'    => -1,
					'message' => $response->get_error_message(),
				];
				$this->logger->warning(
					'JSON-RPC request failed',
					[
						'error'   => $last_error,
						'attempt' => $attempt + 1,
					]
				);
				++$attempt;
				if ( $attempt <= $this->retries ) {
					usleep( 500000 * $attempt ); // 0.5s, 1s, 1.5s...
				}
				continue;
			}

			$decoded = json_decode( $body_raw, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$last_error = [
					'code'    => -32700,
					'message' => 'Invalid JSON response',
				];
				$this->logger->error( 'Invalid JSON response', [ 'body' => substr( $body_raw, 0, 500 ) ] );
				break;
			}

			if ( $code >= 400 ) {
				$last_error = [
					'code'    => $code,
					'message' => $decoded['error']['message'] ?? wp_remote_retrieve_response_message( $response ),
					'data'    => $decoded['error']['data'] ?? null,
				];
				$this->logger->error(
					'API error response',
					[
						'code'  => $code,
						'error' => $last_error,
					]
				);
				break;
			}

			if ( isset( $decoded['error'] ) ) {
				$last_error = [
					'code'    => $decoded['error']['code'] ?? -32603,
					'message' => $decoded['error']['message'] ?? 'Unknown error',
					'data'    => $decoded['error']['data'] ?? null,
				];
				$this->logger->error( 'JSON-RPC error', $last_error );
				return [
					'success' => false,
					'error'   => $last_error,
					'meta'    => $this->build_meta( $started_at, $last_code, $attempts ),
				];
			}

			return [
				'success' => true,
				'result'  => $decoded['result'] ?? null,
				'meta'    => $this->build_meta( $started_at, $last_code, $attempts ),
			];
		}

		return [
			'success' => false,
			'error'   => $last_error ?? [
				'code'    => -1,
				'message' => 'Unknown error',
			],
			'meta'    => $this->build_meta( $started_at, $last_code, $attempts ),
		];
	}

	/**
	 * Transport measurement for one {@see self::call()}.
	 *
	 * @param float $started_at microtime(true) captured at the start of the call.
	 * @param int   $http_code  Last HTTP status seen; 0 when no response was received at all.
	 * @param int   $attempts   Number of wp_remote_post() calls actually made.
	 * @return array{duration_ms: int, http_code: int, attempts: int}
	 */
	private function build_meta( float $started_at, int $http_code, int $attempts ): array {
		return [
			'duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'http_code'   => $http_code,
			'attempts'    => $attempts,
		];
	}

	/**
	 * Test connection with a minimal getProducts call.
	 *
	 * `product_count` is the API's own pagination total (`result.page.number_of_items`), not the
	 * size of the returned array — the call asks for a single product, so counting the array would
	 * report `1` on every install. Absent or non-numeric means unknown, never a substituted number.
	 *
	 * @return array{success: bool, result?: mixed, error?: array{code: int, message: string, data?: mixed}, meta: array{duration_ms: int, http_code: int, attempts: int}, product_count: int|null}
	 */
	public function test_connection(): array {
		$result = $this->call(
			'getProducts',
			[
				'page'                         => 1,
				'limit'                        => 1,
				'include_product_status'       => false,
				'include_product_translations' => false,
				'include_attachments'          => false,
				'include_trade_items'          => false,
				'include_categories'           => false,
			]
		);

		if ( $result['success'] ) {
			$this->logger->info( 'Connection test successful' );
		}

		$result['product_count'] = self::extract_product_count( $result['result'] ?? null );

		return $result;
	}

	/**
	 * The total the API reports for a product listing, or null when it does not report one.
	 *
	 * Tenants run different API builds, so the pagination block is read defensively: anything that
	 * is not a numeric `number_of_items` yields null, which the caller renders as "unavailable".
	 *
	 * @param mixed $rpc_result The JSON-RPC `result` payload.
	 */
	private static function extract_product_count( $rpc_result ): ?int {
		if ( ! is_array( $rpc_result ) || ! isset( $rpc_result['page']['number_of_items'] ) ) {
			return null;
		}

		$total = $rpc_result['page']['number_of_items'];

		return is_numeric( $total ) ? (int) $total : null;
	}
}
