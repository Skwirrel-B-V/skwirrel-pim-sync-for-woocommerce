<?php
/**
 * Integration tests for the uninstall cleanup (uninstall.php → Skwirrel_WC_Sync_Uninstaller).
 *
 * Real options, real post meta and the real queue table; the cleanup runs for the current site.
 */

declare(strict_types=1);

require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-skwirrel-wc-sync-uninstaller.php';

afterEach( function () {
	Skwirrel_WC_Sync_Queue::create_table();
} );

test( 'uninstall removes the plugin options and file name metas but keeps old upload paths', function () {
	global $wpdb;

	foreach ( [
		'skwirrel_wc_sync_rename_job',
		'skwirrel_wc_sync_attribute_groups',
		'skwirrel_wc_sync_attribute_sources',
		'skwirrel_wc_sync_settings',
		'skwirrel_wc_sync_auth_token',
		'skwirrel_wc_sync_history',
		'skwirrel_wc_sync_permalinks',
	] as $option ) {
		update_option( $option, [ 'x' => 1 ] );
	}
	set_transient( 'skwirrel_wc_sync_bg_token', 'abc', 600 );
	update_option( 'unrelated_plugin_option', 'stay' );

	$attachment = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'uninstall-test' ] );
	update_post_meta( $attachment, '_skwirrel_file_name', 'foto.jpg' );
	add_post_meta( $attachment, '_skwirrel_previous_file_name', 'old.jpg' );
	add_post_meta( $attachment, '_skwirrel_old_upload_path', "2024/05/old.jpg\t2024/05/foto.jpg" );
	update_post_meta( $attachment, '_skwirrel_external_id', 'ext-1' );

	Skwirrel_WC_Sync_Queue::create_table();

	Skwirrel_WC_Sync_Uninstaller::cleanup_site();
	wp_cache_flush();

	foreach ( [
		'skwirrel_wc_sync_rename_job',
		'skwirrel_wc_sync_attribute_groups',
		'skwirrel_wc_sync_attribute_sources',
		'skwirrel_wc_sync_settings',
		'skwirrel_wc_sync_auth_token',
		'skwirrel_wc_sync_history',
		'skwirrel_wc_sync_permalinks',
	] as $option ) {
		expect( get_option( $option, 'gone' ) )->toBe( 'gone' );
	}
	expect( get_transient( 'skwirrel_wc_sync_bg_token' ) )->toBeFalse();
	expect( get_option( 'unrelated_plugin_option' ) )->toBe( 'stay' );

	expect( get_post_meta( $attachment, '_skwirrel_file_name' ) )->toBe( [] );
	expect( get_post_meta( $attachment, '_skwirrel_previous_file_name' ) )->toBe( [] );
	expect( get_post_meta( $attachment, '_skwirrel_old_upload_path', true ) )->toBe( "2024/05/old.jpg\t2024/05/foto.jpg" );
	// Products and their Skwirrel keys are out of scope: they stay.
	expect( get_post_meta( $attachment, '_skwirrel_external_id', true ) )->toBe( 'ext-1' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	expect( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'skwirrel_sync_queue' ) ) )->toBeNull();

	delete_option( 'unrelated_plugin_option' );
	wp_delete_post( $attachment, true );
	// Later tests need the queue table back.
	Skwirrel_WC_Sync_Queue::create_table();
} );
