<?php
/**
 * Integration tests for redirects after the plugin was deleted and installed again (#90).
 *
 * Uninstall removes every `skwirrel_wc_sync_*` option, the rename job included, but keeps the
 * `_skwirrel_old_upload_path` rows. Those rows must still turn the redirect on.
 */

declare(strict_types=1);

beforeEach(function (): void {
	delete_option( Skwirrel_WC_Sync_File_Rename_Job::OPTION );
	delete_option( Skwirrel_WC_Sync_File_Redirects::OPTION_HAS_OLD_PATHS );
	delete_post_meta_by_key( Skwirrel_WC_Sync_File_Rename_Job::META_OLD_PATH );
});

afterEach(function (): void {
	delete_option( Skwirrel_WC_Sync_File_Redirects::OPTION_HAS_OLD_PATHS );
	delete_post_meta_by_key( Skwirrel_WC_Sync_File_Rename_Job::META_OLD_PATH );
});

test( 'old-path rows kept through a reinstall still redirect, without a rename', function (): void {
	$attachment = wp_insert_post(
		[
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'post_title'  => 'foto',
		]
	);
	add_post_meta( $attachment, Skwirrel_WC_Sync_File_Rename_Job::META_OLD_PATH, "2024/05/skwirrel-a-1.jpg\t2024/05/foto.jpg" );

	// What uninstall leaves behind: no rename job, no stored answer.
	expect( Skwirrel_WC_Sync_File_Rename_Job::has_run() )->toBeFalse();
	expect( get_option( Skwirrel_WC_Sync_File_Redirects::OPTION_HAS_OLD_PATHS, false ) )->toBeFalse();

	expect( Skwirrel_WC_Sync_File_Redirects::has_old_paths() )->toBeTrue();
	expect( get_option( Skwirrel_WC_Sync_File_Redirects::OPTION_HAS_OLD_PATHS ) )->toBe( '1' );
	expect( Skwirrel_WC_Sync_File_Redirects::find_target( '2024/05/skwirrel-a-1.jpg' ) )->toBe( '2024/05/foto.jpg' );
} );

test( 'a site without old-path rows stores that once and is not looked up again', function (): void {
	global $wpdb;
	expect( Skwirrel_WC_Sync_File_Redirects::has_old_paths() )->toBeFalse();
	expect( get_option( Skwirrel_WC_Sync_File_Redirects::OPTION_HAS_OLD_PATHS ) )->toBe( '0' );

	$before = $wpdb->num_queries;
	expect( Skwirrel_WC_Sync_File_Redirects::has_old_paths() )->toBeFalse();
	expect( $wpdb->num_queries )->toBe( $before );
} );
