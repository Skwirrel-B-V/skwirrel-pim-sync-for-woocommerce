<?php
/**
 * Integration tests for renaming already imported files (Skwirrel_WC_Sync_File_Rename_Job).
 *
 * Real files in the uploads folder, real attachment metadata with generated image sizes, real posts
 * whose content and meta link to the old file. Downloads are stubbed through `pre_http_request`.
 */

declare(strict_types=1);

afterEach(function () {
	remove_all_filters( 'pre_http_request' );
	Skwirrel_WC_Sync_File_Naming::override_config( null );
});

/** A real JPEG of the given size, as bytes. */
function skwRenameJpeg( int $w, int $h ): string {
	$img = imagecreatetruecolor( $w, $h );
	imagefilledrectangle( $img, 0, 0, $w, $h, imagecolorallocate( $img, 40, 120, 200 ) );
	ob_start();
	imagejpeg( $img, null, 70 );
	$bytes = (string) ob_get_clean();
	imagedestroy( $img );
	return $bytes;
}

/** Import a downloaded image with the plugin's own importer, under the default naming. */
function skwRenameImport( string $bytes, string $file_name, int $skwirrel_id ): int {
	update_option( 'skwirrel_wc_sync_settings', [ 'file_naming_mode' => 'current' ] );
	add_filter(
		'pre_http_request',
		static fn ( $pre, $args, $url ) => str_contains( $url, 'cdn.example.test' )
			? [
				'headers'  => [],
				'body'     => $bytes,
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			]
			: $pre,
		10,
		3
	);
	$importer = new Skwirrel_WC_Sync_Media_Importer();
	$id       = $importer->import_image(
		'https://cdn.example.test/img-' . $skwirrel_id . '.jpg',
		$file_name,
		0,
		'alt',
		'',
		[
			'attachment_id' => $skwirrel_id,
			'file_name'     => $file_name,
		]
	);
	remove_all_filters( 'pre_http_request' );
	return $id;
}

test( 'an imported image is renamed with all its sizes, links are rewritten and the old URL redirects', function (): void {
	$id = skwRenameImport( skwRenameJpeg( 1200, 800 ), 'Vloerverwarming.jpg', 9101 );
	expect( $id )->toBeGreaterThan( 0 );

	$old_path = (string) get_attached_file( $id );
	$old_rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
	$meta     = wp_get_attachment_metadata( $id );
	expect( basename( $old_path ) )->toStartWith( 'skwirrel-' );
	expect( $meta['sizes'] )->not->toBeEmpty();
	$old_size_rel = dirname( $old_rel ) . '/' . $meta['sizes']['medium']['file'];

	$upload   = wp_upload_dir();
	$post_id  = wp_insert_post(
		[
			'post_title'   => 'Uses the image',
			'post_status'  => 'publish',
			'post_content' => '<img src="' . $upload['baseurl'] . '/' . $old_rel . '"><img src="' . $upload['baseurl'] . '/' . $old_size_rel . '"><a href="https://vendor.example/' . $old_rel . '">other site</a>',
		]
	);
	update_post_meta( $post_id, '_hero', [ 'url' => $upload['baseurl'] . '/' . $old_rel ] );

	update_option( 'skwirrel_wc_sync_settings', [ 'file_naming_mode' => 'original' ] );
	expect( Skwirrel_WC_Sync_File_Rename_Job::rename_attachment( $id ) )->toBe( 'renamed' );

	$new_path = (string) get_attached_file( $id );
	expect( basename( $new_path ) )->toBe( 'Vloerverwarming.jpg' );
	expect( file_exists( $new_path ) )->toBeTrue();
	expect( file_exists( $old_path ) )->toBeFalse();

	$new_meta = wp_get_attachment_metadata( $id );
	foreach ( $new_meta['sizes'] as $size ) {
		expect( $size['file'] )->toStartWith( 'Vloerverwarming-' );
		expect( file_exists( dirname( $new_path ) . '/' . $size['file'] ) )->toBeTrue();
	}
	foreach ( $meta['sizes'] as $size ) {
		expect( file_exists( dirname( $old_path ) . '/' . $size['file'] ) )->toBeFalse();
	}

	// Links to the old file in content and in (serialized) meta now point at the new one.
	$content = (string) get_post_field( 'post_content', $post_id );
	expect( $content )->toContain( dirname( $old_rel ) . '/Vloerverwarming.jpg' );
	// Another site's URL that happens to end in the same path is not ours to change.
	expect( $content )->toContain( 'https://vendor.example/' . $old_rel );
	expect( str_replace( 'https://vendor.example/' . $old_rel, '', $content ) )->not->toContain( basename( $old_path ) );
	expect( get_post_meta( $post_id, '_hero', true )['url'] )->toContain( 'Vloerverwarming.jpg' );

	// The old name is kept and the old URLs redirect to the new ones, sizes included.
	expect( get_post_meta( $id, '_skwirrel_previous_file_name', true ) )->toBe( basename( $old_path ) );
	expect( Skwirrel_WC_Sync_File_Redirects::find_target( $old_rel ) )->toBe( dirname( $old_rel ) . '/Vloerverwarming.jpg' );
	expect( Skwirrel_WC_Sync_File_Redirects::find_target( $old_size_rel ) )->toStartWith( dirname( $old_rel ) . '/Vloerverwarming-' );

	// Running it again finds nothing to rename.
	expect( Skwirrel_WC_Sync_File_Rename_Job::rename_attachment( $id ) )->toBe( 'skipped' );
	expect( Skwirrel_WC_Sync_File_Renamer::preview()['counts']['rename'] )->toBe( 0 );
} );

test( 'a big image keeps its original next to the scaled copy and is not renamed again', function (): void {
	$id = skwRenameImport( skwRenameJpeg( 3000, 2000 ), 'Groot-Beeld.jpg', 9102 );
	expect( $id )->toBeGreaterThan( 0 );
	$meta = wp_get_attachment_metadata( $id );
	expect( $meta['original_image'] ?? '' )->not->toBe( '' );
	expect( (string) get_attached_file( $id ) )->toContain( '-scaled.' );

	update_option( 'skwirrel_wc_sync_settings', [ 'file_naming_mode' => 'original' ] );
	expect( Skwirrel_WC_Sync_File_Rename_Job::rename_attachment( $id ) )->toBe( 'renamed' );

	$path = (string) get_attached_file( $id );
	expect( basename( $path ) )->toBe( 'Groot-Beeld-scaled.jpg' );
	expect( file_exists( dirname( $path ) . '/Groot-Beeld.jpg' ) )->toBeTrue();
	expect( wp_get_attachment_metadata( $id )['original_image'] )->toBe( 'Groot-Beeld.jpg' );

	expect( Skwirrel_WC_Sync_File_Rename_Job::rename_attachment( $id ) )->toBe( 'skipped' );
} );

test( 'a second file wanting a name that another renamed file just left gets a suffixed name', function (): void {
	$first  = skwRenameImport( skwRenameJpeg( 400, 300 ), 'beta.jpg', 9103 );
	$second = skwRenameImport( skwRenameJpeg( 400, 300 ), 'alpha.jpg', 9104 );
	// The first file currently carries the name the second one is about to get.
	$dir      = dirname( (string) get_attached_file( $first ) );
	$rel_dir  = dirname( (string) get_post_meta( $first, '_wp_attached_file', true ) );
	$old_meta = wp_get_attachment_metadata( $first );
	foreach ( array_merge( [ basename( (string) get_attached_file( $first ) ) ], array_column( $old_meta['sizes'] ?? [], 'file' ) ) as $file ) {
		unlink( $dir . '/' . $file );
	}
	copy( (string) get_attached_file( $second ), $dir . '/alpha.jpg' );
	update_attached_file( $first, $dir . '/alpha.jpg' );
	update_post_meta( $first, '_skwirrel_file_name', 'beta.jpg' );
	wp_update_attachment_metadata( $first, [ 'file' => $rel_dir . '/alpha.jpg' ] );

	update_option( 'skwirrel_wc_sync_settings', [ 'file_naming_mode' => 'original' ] );
	expect( Skwirrel_WC_Sync_File_Rename_Job::rename_attachment( $first ) )->toBe( 'renamed' );
	expect( basename( (string) get_attached_file( $first ) ) )->toBe( 'beta.jpg' );

	// "alpha.jpg" is now the old URL of the first file, which redirects: the second file must not take it over.
	expect( Skwirrel_WC_Sync_File_Rename_Job::rename_attachment( $second ) )->toBe( 'renamed' );
	expect( basename( (string) get_attached_file( $second ) ) )->toBe( 'alpha-1.jpg' );
} );
