<?php
/**
 * Integration tests for Skwirrel_WC_Sync_Media_Importer.
 *
 * Exercises the actual import flow against a real WordPress environment.
 * HTTP requests are stubbed via the `pre_http_request` filter so the suite
 * does not depend on any external host.
 *
 * Each test starts from a clean slate — beforeEach removes any leftover
 * Skwirrel-tagged attachments to keep count assertions independent.
 */

declare(strict_types=1);

beforeEach(function () {
	$this->importer = new Skwirrel_WC_Sync_Media_Importer();

	// Nuke leftover attachments tagged as Skwirrel.
	global $wpdb;
	$leftover = $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		WHERE meta_key IN ('_skwirrel_source_url', '_skwirrel_url_hash', '_skwirrel_attachment_id', '_skwirrel_file_checksum')"
	);
	foreach ( $leftover as $pid ) {
		wp_delete_attachment( (int) $pid, true );
	}
});

afterEach(function () {
	remove_all_filters( 'pre_http_request' );
});

/** Minimal 67-byte 1x1 transparent PNG used as fake CDN response body. */
function fakePngBytes(): string {
	return base64_decode(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
	);
}

/**
 * Install a `pre_http_request` stub that returns canned bytes for any URL
 * matching $url_substring. Other URLs fall through to the real network
 * (none expected here).
 */
function stubMediaDownload( string $url_substring, string $body ): void {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $url_substring, $body ) {
		if ( false === strpos( $url, $url_substring ) ) {
			return $pre;
		}
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		];
	}, 10, 3 );
}

// ------------------------------------------------------------------
// import_image() — persists the new Skwirrel-side meta keys
// ------------------------------------------------------------------

test( 'import_image persists Skwirrel attachment_id and file_checksum from api_meta', function () {
	stubMediaDownload( 'cdn.example/image.png', fakePngBytes() );

	$id = $this->importer->import_image(
		'https://cdn.example/image.png',
		'Test image',
		0,
		'',
		'',
		[
			'attachment_id' => 4242,
			'file_checksum' => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
		]
	);

	expect( $id )->toBeGreaterThan( 0 );
	expect( get_post_meta( $id, '_skwirrel_attachment_id', true ) )->toBe( '4242' );
	// Stored in lowercase to make later string comparisons deterministic.
	expect( get_post_meta( $id, '_skwirrel_file_checksum', true ) )
		->toBe( 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890' );
	// Existing URL-based meta is still written (backwards compatibility).
	expect( get_post_meta( $id, '_skwirrel_source_url', true ) )->toBe( 'https://cdn.example/image.png' );
	expect( get_post_meta( $id, '_skwirrel_url_hash', true ) )->not->toBe( '' );
} );

test( 'import_image without api_meta falls back to URL-only meta (no attachment_id, no checksum)', function () {
	stubMediaDownload( 'cdn.example/legacy.png', fakePngBytes() );

	$id = $this->importer->import_image( 'https://cdn.example/legacy.png' );

	expect( $id )->toBeGreaterThan( 0 );
	expect( get_post_meta( $id, '_skwirrel_attachment_id', true ) )->toBe( '' );
	expect( get_post_meta( $id, '_skwirrel_file_checksum', true ) )->toBe( '' );
	expect( get_post_meta( $id, '_skwirrel_source_url', true ) )->toBe( 'https://cdn.example/legacy.png' );
} );

test( 'import_image skips empty api_meta values', function () {
	stubMediaDownload( 'cdn.example/empty-meta.png', fakePngBytes() );

	$id = $this->importer->import_image(
		'https://cdn.example/empty-meta.png',
		'',
		0,
		'',
		'',
		[
			'attachment_id' => 0,        // zero = no real id
			'file_checksum' => '   ',    // whitespace only
		]
	);

	expect( $id )->toBeGreaterThan( 0 );
	expect( get_post_meta( $id, '_skwirrel_attachment_id', true ) )->toBe( '' );
	expect( get_post_meta( $id, '_skwirrel_file_checksum', true ) )->toBe( '' );
} );

// ------------------------------------------------------------------
// import_file() — same persistence path
// ------------------------------------------------------------------

test( 'import_file persists Skwirrel attachment_id and file_checksum from api_meta', function () {
	stubMediaDownload( 'cdn.example/manual.pdf', '%PDF-1.4 fake bytes' );

	$id = $this->importer->import_file(
		'https://cdn.example/manual.pdf',
		'manual.pdf',
		0,
		[
			'attachment_id' => 9001,
			'file_checksum' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
		]
	);

	expect( $id )->toBeGreaterThan( 0 );
	expect( get_post_meta( $id, '_skwirrel_attachment_id', true ) )->toBe( '9001' );
	expect( get_post_meta( $id, '_skwirrel_file_checksum', true ) )
		->toBe( 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef' );
} );
