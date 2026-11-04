<?php
/**
 * Uninstall Skwirrel PIM sync for WooCommerce.
 *
 * Runs when the plugin is deleted from Plugins (not on deactivation). What is removed and what is
 * kept is described in Skwirrel_WC_Sync_Uninstaller.
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-skwirrel-wc-sync-uninstaller.php';

Skwirrel_WC_Sync_Uninstaller::run();
