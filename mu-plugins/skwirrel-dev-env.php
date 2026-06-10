<?php
/**
 * Plugin Name: Skwirrel Dev Env — loopback + cron fixes (local only)
 * Description: Makes wp-env behave for manual testing. Rewrites internal HTTP loopback requests that target the public port (8888) so they reach the container's own Apache on port 80. This fixes the plugin's "Sync now" background request, WP-Cron spawning, and Action Scheduler async dispatch — all of which loopback to the site URL and otherwise fail inside the container. Also neutralizes the WooCommerce Pattern Toolkit remote fetch that crashed the site during plugin upgrades.
 * Version: 1.0.0
 * Author: Skwirrel B.V.
 * Author URI: https://skwirrel.eu
 * Requires PHP: 8.3
 *
 * THIS FILE IS A LOCAL DEVELOPMENT SHIM. It self-disables unless
 * WP_ENVIRONMENT_TYPE === 'local', so it is inert on staging/production
 * even if it ever ships by accident. Do NOT bake this into the main plugin.
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hard gate: only ever active in a local wp-env style environment.
if ( ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() ) {
	return;
}

/**
 * Rewrite outbound loopback HTTP requests so they reach the container's own
 * web server.
 *
 * wp-env publishes the site on the host at http://localhost:8888 but inside
 * the WordPress container Apache listens on port 80; nothing listens on 8888.
 * So PHP loopback calls — admin-ajax.php (Skwirrel "Sync now"), wp-cron.php
 * (WP-Cron spawn), and Action Scheduler's async dispatch — all target :8888
 * and fail with a connection error. We detect requests aimed at this site's
 * own host:port and re-issue them against 127.0.0.1:80, preserving the
 * original Host header so WordPress still routes/authorises them correctly.
 *
 * Implementation: short-circuit via `pre_http_request`, perform the rewritten
 * request ourselves, and return its result. A static re-entrancy guard stops
 * the inner request from recursing back into this filter.
 *
 * @param false|array|WP_Error $pre  Short-circuit value. Untouched if not a loopback.
 * @param array                $args wp_remote_* request args.
 * @param string               $url  Target URL.
 * @return false|array|WP_Error
 */
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		static $in_flight = false;

		if ( $in_flight ) {
			return $pre; // Let the rewritten inner request pass straight through.
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $pre;
		}

		// Only rewrite requests to THIS site's host on a non-80/443 port
		// (i.e. the published wp-env port such as 8888/8889).
		$home       = wp_parse_url( home_url() );
		$home_host  = is_array( $home ) && ! empty( $home['host'] ) ? $home['host'] : '';
		$req_host   = $parts['host'];
		$req_port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$local_host = in_array( $req_host, [ $home_host, 'localhost', '127.0.0.1' ], true );

		if ( ! $local_host || $req_port <= 0 || 80 === $req_port || 443 === $req_port ) {
			return $pre;
		}

		// Build the internal URL: same path/query, but 127.0.0.1 on port 80.
		$path     = $parts['path'] ?? '/';
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$internal = 'http://127.0.0.1' . $path . $query;

		// Preserve the original Host header so WP sees the canonical host:port.
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = [];
		}
		$args['headers']['Host'] = $req_host . ( $req_port ? ':' . $req_port : '' );

		$in_flight = true;
		$result    = wp_remote_request( $internal, $args );
		$in_flight = false;

		return $result;
	},
	5, // Run early so we win before the real transport.
	3
);

/**
 * Stop the WooCommerce Pattern Toolkit from making external pattern-fetch
 * calls in local dev.
 *
 * The original site crash was PTKPatternsStore scheduling a recurring
 * `fetch_patterns` action during a plugin upgrade, before Action Scheduler's
 * autoloader had initialised. We don't need remote WordPress.com patterns in a
 * local test store, so we unschedule that action and short-circuit the HTTP
 * call to the patterns API. Defensive only — pinning WooCommerce already
 * removes the upgrade race that triggered the fatal.
 */
add_action(
	'init',
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'fetch_patterns' );
		}
	},
	20
);

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( is_string( $url ) && str_contains( $url, 'public-api.wordpress.com' ) && str_contains( $url, 'pattern' ) ) {
			// Return an empty, well-formed response so callers don't error.
			return [
				'headers'  => [],
				'body'     => '[]',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		}
		return $pre;
	},
	5,
	3
);
