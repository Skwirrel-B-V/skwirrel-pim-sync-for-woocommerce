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

// ------------------------------------------------------------------
// Re-import lookup paths — by Skwirrel attachment_id (primary) and
// URL hash (legacy fallback)
// ------------------------------------------------------------------

test( 'import_image returns the existing attachment id when attachment_id matches a prior import', function () {
	stubMediaDownload( 'cdn.example/dedup-by-id.png', fakePngBytes() );

	$first_id = $this->importer->import_image(
		'https://cdn.example/dedup-by-id.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 5005, 'file_checksum' => 'a' ]
	);
	expect( $first_id )->toBeGreaterThan( 0 );

	// Same Skwirrel id, but a totally different URL — the lookup must use
	// attachment_id rather than the URL hash, otherwise URL-rewrites on the
	// CDN would silently produce duplicate attachments.
	$second_id = $this->importer->import_image(
		'https://cdn.example/RENAMED-after-cdn-refactor.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 5005, 'file_checksum' => 'a' ]
	);

	expect( $second_id )->toBe( $first_id );
} );

test( 'import_image still finds legacy attachments via URL hash when attachment_id is unknown', function () {
	stubMediaDownload( 'cdn.example/legacy-fallback.png', fakePngBytes() );

	// First sync simulates a pre-3.8 install: no api_meta passed, so only the URL
	// hash is recorded.
	$first_id = $this->importer->import_image( 'https://cdn.example/legacy-fallback.png' );
	expect( $first_id )->toBeGreaterThan( 0 );
	expect( get_post_meta( $first_id, '_skwirrel_attachment_id', true ) )->toBe( '' );

	// Second sync hits the same URL. Even with api_meta now provided, the
	// attachment_id lookup misses (no record stored that ID), so the URL
	// hash fallback must catch it instead of triggering a duplicate download.
	$second_id = $this->importer->import_image(
		'https://cdn.example/legacy-fallback.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 7777, 'file_checksum' => 'b' ]
	);

	expect( $second_id )->toBe( $first_id );
} );

test( 'import_image dedup by attachment_id ignores a different file_checksum stored against another id', function () {
	stubMediaDownload( 'cdn.example/dedup-isolation-a.png', fakePngBytes() );
	stubMediaDownload( 'cdn.example/dedup-isolation-b.png', fakePngBytes() );

	$first_id = $this->importer->import_image(
		'https://cdn.example/dedup-isolation-a.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 1001, 'file_checksum' => 'aaa' ]
	);

	// Different attachment_id should not collide with the first record even
	// though the file checksums happen to look similar — we look up by id,
	// not by checksum (yet — that comes in commit 4).
	$second_id = $this->importer->import_image(
		'https://cdn.example/dedup-isolation-b.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 1002, 'file_checksum' => 'aaa' ]
	);

	expect( $second_id )->not->toBe( $first_id );
	expect( $second_id )->toBeGreaterThan( $first_id );
} );

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

// ------------------------------------------------------------------
// Content-update path — checksum mismatch triggers in-place replace
// ------------------------------------------------------------------

test( 'import_image leaves the file untouched when checksum is unchanged', function () {
	stubMediaDownload( 'cdn.example/stable.png', fakePngBytes() );

	$first_id = $this->importer->import_image(
		'https://cdn.example/stable.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 6001, 'file_checksum' => 'aaa' ]
	);
	$initial_path = get_attached_file( $first_id );
	expect( file_exists( (string) $initial_path ) )->toBeTrue();

	$second_id = $this->importer->import_image(
		'https://cdn.example/stable.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 6001, 'file_checksum' => 'aaa' ]
	);

	expect( $second_id )->toBe( $first_id );
	expect( get_attached_file( $second_id ) )->toBe( $initial_path );
} );

test( 'import_image replaces the underlying file when api checksum differs from stored', function () {
	stubMediaDownload( 'cdn.example/changed.png', fakePngBytes() );

	$first_id = $this->importer->import_image(
		'https://cdn.example/changed.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 6002, 'file_checksum' => 'oldoldold' ]
	);
	expect( get_post_meta( $first_id, '_skwirrel_file_checksum', true ) )->toBe( 'oldoldold' );

	// Trigger content-update with a different checksum on the same Skwirrel
	// attachment id. The WP attachment record is preserved (same id) but the
	// stored checksum is refreshed and the file under that id has been
	// re-written from the freshly-downloaded bytes.
	$second_id = $this->importer->import_image(
		'https://cdn.example/changed.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 6002, 'file_checksum' => 'newnewnew' ]
	);

	expect( $second_id )->toBe( $first_id );
	expect( get_post_meta( $second_id, '_skwirrel_file_checksum', true ) )->toBe( 'newnewnew' );
	expect( file_exists( (string) get_attached_file( $second_id ) ) )->toBeTrue();
} );

test( 'import_image does not replace when the stored checksum is empty (legacy attachment)', function () {
	stubMediaDownload( 'cdn.example/legacy-checksum.png', fakePngBytes() );

	// First import: pre-3.8 sim — no api_meta, so no checksum stored.
	$first_id = $this->importer->import_image( 'https://cdn.example/legacy-checksum.png' );
	$initial_path = (string) get_attached_file( $first_id );

	// Second import passes a checksum, but since the stored side is empty we
	// must NOT trigger a re-download — that would create churn for every
	// pre-3.8 attachment on the first sync after the 3.8 upgrade.
	$second_id = $this->importer->import_image(
		'https://cdn.example/legacy-checksum.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 0, 'file_checksum' => 'somechecksum' ]
	);

	expect( $second_id )->toBe( $first_id );
	expect( get_attached_file( $second_id ) )->toBe( $initial_path );
} );

// ------------------------------------------------------------------
// Backwards-compat backfill — pre-3.8 attachments have no Skwirrel-side
// meta; they should be silently upgraded on first re-sync without any
// re-download
// ------------------------------------------------------------------

test( 'pre-3.8 attachment (URL-hash only) gets attachment_id and checksum backfilled on first re-sync', function () {
	stubMediaDownload( 'cdn.example/legacy-backfill.png', fakePngBytes() );

	// Step 1: simulate a pre-3.8 import — no api_meta supplied.
	$first_id = $this->importer->import_image( 'https://cdn.example/legacy-backfill.png' );
	expect( get_post_meta( $first_id, '_skwirrel_attachment_id', true ) )->toBe( '' );
	expect( get_post_meta( $first_id, '_skwirrel_file_checksum', true ) )->toBe( '' );
	$initial_path = (string) get_attached_file( $first_id );

	// Step 2: a re-sync now passes the new api_meta. The match still happens
	// via URL hash (attachment_id lookup misses), and both meta keys must be
	// quietly populated. No re-download — the file path stays put.
	$second_id = $this->importer->import_image(
		'https://cdn.example/legacy-backfill.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 4242, 'file_checksum' => 'aabbcc' ]
	);

	expect( $second_id )->toBe( $first_id );
	expect( get_post_meta( $second_id, '_skwirrel_attachment_id', true ) )->toBe( '4242' );
	expect( get_post_meta( $second_id, '_skwirrel_file_checksum', true ) )->toBe( 'aabbcc' );
	expect( get_attached_file( $second_id ) )->toBe( $initial_path );
} );

test( 'backfill leaves an existing stored checksum untouched (must not squash content-update signal)', function () {
	stubMediaDownload( 'cdn.example/already-tracked.png', fakePngBytes() );

	// Step 1: attachment imported under 3.8 with a known checksum.
	$first_id = $this->importer->import_image(
		'https://cdn.example/already-tracked.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 5555, 'file_checksum' => 'original-checksum' ]
	);
	expect( get_post_meta( $first_id, '_skwirrel_file_checksum', true ) )->toBe( 'original-checksum' );

	// Step 2: a re-sync with a DIFFERENT checksum must NOT be silently
	// overwritten by the backfill; instead the replace path takes over and
	// the new checksum lands AFTER the file was actually replaced.
	$second_id = $this->importer->import_image(
		'https://cdn.example/already-tracked.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 5555, 'file_checksum' => 'different-checksum' ]
	);

	expect( $second_id )->toBe( $first_id );
	expect( get_post_meta( $second_id, '_skwirrel_file_checksum', true ) )->toBe( 'different-checksum' );
} );

// ------------------------------------------------------------------
// File-existence check — broken WP attachment records get cleaned up
// before the import path treats them as a valid match
// ------------------------------------------------------------------

test( 'import_image disconnects a broken record (file gone) without deleting it, then downloads fresh', function () {
	stubMediaDownload( 'cdn.example/broken.png', fakePngBytes() );

	$first_id = $this->importer->import_image(
		'https://cdn.example/broken.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 8001, 'file_checksum' => 'aaa' ]
	);
	$path = (string) get_attached_file( $first_id );
	expect( file_exists( $path ) )->toBeTrue();

	// Simulate the file being gone (admin cleanup, half-failed sync).
	unlink( $path );
	expect( get_post( $first_id ) )->not->toBeNull();

	$second_id = $this->importer->import_image(
		'https://cdn.example/broken.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 8001, 'file_checksum' => 'aaa' ]
	);

	// Critical invariants for offload-plugin safety:
	//  * the WP attachment record is NOT deleted (wp_delete_attachment would
	//    trigger offload-plugin hooks that may purge remote storage),
	//  * the Skwirrel-side meta IS cleared so future lookups miss it,
	//  * a fresh download happens under a new attachment id.
	expect( $second_id )->toBeGreaterThan( 0 );
	expect( $second_id )->not->toBe( $first_id );
	expect( get_post( $first_id ) )->not->toBeNull();
	expect( get_post_meta( $first_id, '_skwirrel_attachment_id', true ) )->toBe( '' );
	expect( get_post_meta( $first_id, '_skwirrel_url_hash', true ) )->toBe( '' );
	expect( get_post_meta( $first_id, '_skwirrel_file_checksum', true ) )->toBe( '' );
	expect( get_post_meta( $first_id, '_skwirrel_source_url', true ) )->toBe( '' );
	expect( file_exists( (string) get_attached_file( $second_id ) ) )->toBeTrue();
} );

test( 'import_file disconnects a broken record (file gone) without deleting it, then downloads fresh', function () {
	stubMediaDownload( 'cdn.example/broken-doc.pdf', '%PDF-1.4 content' );

	$first_id = $this->importer->import_file(
		'https://cdn.example/broken-doc.pdf',
		'broken.pdf',
		0,
		[ 'attachment_id' => 8002, 'file_checksum' => 'aaa' ]
	);
	$path = (string) get_attached_file( $first_id );
	unlink( $path );

	$second_id = $this->importer->import_file(
		'https://cdn.example/broken-doc.pdf',
		'broken.pdf',
		0,
		[ 'attachment_id' => 8002, 'file_checksum' => 'aaa' ]
	);

	expect( $second_id )->toBeGreaterThan( 0 );
	expect( $second_id )->not->toBe( $first_id );
	expect( get_post( $first_id ) )->not->toBeNull();
	expect( get_post_meta( $first_id, '_skwirrel_attachment_id', true ) )->toBe( '' );
	expect( file_exists( (string) get_attached_file( $second_id ) ) )->toBeTrue();
} );

test( 'skwirrel_wc_sync_attachment_is_valid filter keeps offloaded records (local file missing, remote ok)', function () {
	stubMediaDownload( 'cdn.example/offloaded.png', fakePngBytes() );

	$first_id = $this->importer->import_image(
		'https://cdn.example/offloaded.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 8003, 'file_checksum' => 'aaa' ]
	);
	$path = (string) get_attached_file( $first_id );
	unlink( $path ); // simulate offload plugin removing the local file after pushing to S3

	// Site code (or an offload-plugin integration) overrides the default
	// file_exists check so the import path keeps reusing the existing record.
	$filter = static fn( bool $default, int $att_id, ?string $local_path ): bool => true;
	add_filter( 'skwirrel_wc_sync_attachment_is_valid', $filter, 10, 3 );

	$second_id = $this->importer->import_image(
		'https://cdn.example/offloaded.png',
		'',
		0,
		'',
		'',
		[ 'attachment_id' => 8003, 'file_checksum' => 'aaa' ]
	);

	remove_filter( 'skwirrel_wc_sync_attachment_is_valid', $filter, 10 );

	// Same WP attachment id reused — no fresh download, no meta cleanup,
	// no churn for offloaded media libraries.
	expect( $second_id )->toBe( $first_id );
	expect( get_post_meta( $first_id, '_skwirrel_attachment_id', true ) )->toBe( '8003' );
	expect( get_post_meta( $first_id, '_skwirrel_file_checksum', true ) )->toBe( 'aaa' );
} );

test( 'import_file replaces underlying bytes when checksum differs', function () {
	stubMediaDownload( 'cdn.example/changing-doc.pdf', '%PDF-1.4 v1' );

	$first_id = $this->importer->import_file(
		'https://cdn.example/changing-doc.pdf',
		'doc.pdf',
		0,
		[ 'attachment_id' => 7001, 'file_checksum' => 'v1-checksum' ]
	);
	$initial_path = (string) get_attached_file( $first_id );
	expect( file_get_contents( $initial_path ) )->toBe( '%PDF-1.4 v1' );

	// Restub with new bytes for the same URL, pass new checksum.
	remove_all_filters( 'pre_http_request' );
	stubMediaDownload( 'cdn.example/changing-doc.pdf', '%PDF-1.4 v2 with extra content' );

	$second_id = $this->importer->import_file(
		'https://cdn.example/changing-doc.pdf',
		'doc.pdf',
		0,
		[ 'attachment_id' => 7001, 'file_checksum' => 'v2-checksum' ]
	);

	expect( $second_id )->toBe( $first_id );
	expect( get_post_meta( $second_id, '_skwirrel_file_checksum', true ) )->toBe( 'v2-checksum' );
	$new_path = (string) get_attached_file( $second_id );
	expect( file_get_contents( $new_path ) )->toBe( '%PDF-1.4 v2 with extra content' );
} );
