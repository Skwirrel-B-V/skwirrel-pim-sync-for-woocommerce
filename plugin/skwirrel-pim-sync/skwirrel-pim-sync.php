<?php

/**
 * Plugin Name: Skwirrel PIM sync for WooCommerce
 * Plugin URI: https://github.com/Skwirrel-B-V/skwirrel-pim-sync-for-woocommerce
 * Description: Sync plugin for Skwirrel PIM via Skwirrel JSON-RPC API to WooCommerce.
 * Version: 3.11.2
 * Author: Skwirrel B.V.
 * Author URI: https://skwirrel.eu
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * WC requires at least: 8.0
 * WC tested up to: 10.6
 * License: GPL v2 or later
 * Text Domain: skwirrel-pim-sync
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SKWIRREL_WC_SYNC_VERSION', '3.11.2' );
define( 'SKWIRREL_WC_SYNC_PLUGIN_FILE', __FILE__ );
define( 'SKWIRREL_WC_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SKWIRREL_WC_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	function (): void {
		// Check WooCommerce dependency on activation.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'active_plugins' is a WordPress core filter, not a plugin-defined hook.
		if ( ! class_exists( 'WooCommerce' ) && ! in_array( 'woocommerce/woocommerce.php', (array) apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) ), true ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'Skwirrel PIM sync for WooCommerce requires WooCommerce to function.', 'skwirrel-pim-sync' )
				. ' <a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">'
				. esc_html__( 'Install WooCommerce', 'skwirrel-pim-sync' ) . '</a>.',
				'Plugin Activation Error',
				[ 'back_link' => true ]
			);
		}

		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-skwirrel-wc-sync-queue.php';
		Skwirrel_WC_Sync_Queue::create_table();

		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-skwirrel-wc-sync-action-scheduler.php';
		Skwirrel_WC_Sync_Action_Scheduler::instance()->schedule();
	}
);

require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-skwirrel-wc-sync-plugin.php';
Skwirrel_WC_Sync_Plugin::instance();
