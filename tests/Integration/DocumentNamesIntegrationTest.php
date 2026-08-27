<?php
/**
 * Integration tests for document link names (Story 6.5, FR-23, NFR-9).
 *
 * `tests/Unit/AttachmentHandlerDocumentNameTest.php` pins `resolve_document_name()` in isolation —
 * eleven cases over the exact → prefix → attachment-title → file_name → basename chain. What it
 * cannot reach is everything either side of that pure function:
 *
 *  - that `get_document_attachments()` actually calls it (the call is one line, and a refactor
 *    could drop it with the whole unit file still green);
 *  - that the resolved name is what lands in `_skwirrel_document_attachments`, including for
 *    products synced before this story whose stored names are raw filenames (AC-4's "no separate
 *    migration, the normal sync repairs them" promise);
 *  - that the documents tab and the admin meta box render it, escaped.
 *
 * All three need a real media import, a real `update_post_meta`, and real rendering, so they are
 * integration tests by necessity. HTTP is stubbed via `pre_http_request` the way
 * `MediaImporterIntegrationTest` does, so nothing here touches the network.
 *
 * Pinned here:
 *  AC-1  the resolved title reaches the stored meta and the rendered link text
 *  AC-2  the two-letter prefix match survives the whole round trip
 *  AC-3  an untranslated document still renders, by its filename
 *  AC-4  a pre-existing raw filename is refreshed by an ordinary re-sync
 *  AC-5  markup in a title is escaped at output, in both sinks
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

beforeEach( function () {
	delete_option( 'skwirrel_wc_sync_settings' );

	update_option( 'skwirrel_wc_sync_settings', [
		'endpoint_url'   => 'https://test.skwirrel.example/jsonrpc',
		'image_language' => 'nl',
		'sync_images'    => false,
	] );

	$this->upserter = docnames_upserter();
} );

afterEach( function () {
	remove_all_filters( 'pre_http_request' );
} );

/**
 * The upserter with its seven real collaborators.
 *
 * Documents ride `assign_media()`, which needs no content mapping, but the constructor shape is
 * the same one `Sync_Service` builds.
 */
function docnames_upserter(): Skwirrel_WC_Sync_Product_Upserter {
	$logger           = new Skwirrel_WC_Sync_Logger();
	$mapper           = new Skwirrel_WC_Sync_Product_Mapper();
	$lookup           = new Skwirrel_WC_Sync_Product_Lookup( $mapper );
	$brand_sync       = new Skwirrel_WC_Sync_Brand_Sync( $logger );
	$taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager( $logger );
	$category_sync    = new Skwirrel_WC_Sync_Category_Sync( $logger, $mapper );
	$slug_resolver    = new Skwirrel_WC_Sync_Slug_Resolver();

	return new Skwirrel_WC_Sync_Product_Upserter(
		$logger,
		$mapper,
		$lookup,
		$category_sync,
		$brand_sync,
		$taxonomy_manager,
		$slug_resolver
	);
}

/**
 * Answer any request for $url_substring with canned bytes, so no test needs the network.
 */
function docnames_stub_download( string $url_substring, string $body = '%PDF-1.4 fake bytes' ): void {
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $url_substring, $body ) {
		if ( false === strpos( (string) $url, $url_substring ) ) {
			return $pre;
		}
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		];
	}, 10, 3 );
}

/**
 * A product payload carrying exactly one document attachment.
 *
 * `MAN` is a document type code, and the URL ends `.pdf`, so the image filter in
 * `get_document_attachments()` lets it through as a document rather than skipping it.
 *
 * @param string                                 $sku          SKU / external id.
 * @param string                                 $file_name    Raw `file_name` on the attachment.
 * @param array<int, array<string, mixed>>       $translations `_attachment_translations` entries.
 * @param array<string, mixed>                   $overrides    Attachment-level overrides.
 * @return array<string, mixed>
 */
function docnames_payload( string $sku, string $file_name, array $translations = [], array $overrides = [] ): array {
	$attachment = array_merge(
		[
			'product_attachment_type_code' => 'MAN',
			'source_url'                   => 'https://cdn.example/' . $file_name,
			'file_name'                    => $file_name,
			'_attachment_translations'     => $translations,
		],
		$overrides
	);

	return [
		'product_id'              => abs( crc32( $sku ) ) % 100000,
		'external_product_id'     => $sku,
		'internal_product_code'   => $sku,
		'product_erp_description' => 'ERP title for ' . $sku,
		'_attachments'            => [ $attachment ],
		'_trade_items'            => [ [ '_trade_item_prices' => [ [ 'net_price' => 10.0 ] ] ] ],
	];
}

/** One `_attachment_translations` entry. */
function docnames_translation( string $language, string $title ): array {
	return [
		'language'                 => $language,
		'product_attachment_title' => $title,
	];
}

/**
 * A real simple product, stamped with the keys the upsert path looks products up by.
 *
 * @param array<int, array<string, mixed>>|null $stored_docs Seed `_skwirrel_document_attachments`.
 */
function docnames_seed_product( string $sku, ?array $stored_docs = null ): int {
	$product = new WC_Product_Simple();
	$product->set_name( 'Seeded ' . $sku );
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$id = $product->save();

	update_post_meta( $id, '_skwirrel_external_id', 'ext:' . $sku );
	update_post_meta( $id, '_skwirrel_product_id', abs( crc32( $sku ) ) % 100000 );

	if ( null !== $stored_docs ) {
		update_post_meta( $id, '_skwirrel_document_attachments', $stored_docs );
	}

	return $id;
}

/** The stored document names for a product, in order. */
function docnames_stored( int $wc_id ): array {
	$docs = get_post_meta( $wc_id, '_skwirrel_document_attachments', true );
	if ( ! is_array( $docs ) ) {
		return [];
	}
	return array_map( static fn( $doc ) => (string) ( $doc['name'] ?? '' ), $docs );
}

/** The frontend documents tab, rendered for $wc_id. */
function docnames_render_tab( int $wc_id ): string {
	$GLOBALS['product'] = wc_get_product( $wc_id );
	ob_start();
	Skwirrel_WC_Sync_Product_Documents::instance()->render_product_tab();
	$html = (string) ob_get_clean();
	unset( $GLOBALS['product'] );
	return $html;
}

/** The admin meta box, rendered for $wc_id. */
function docnames_render_meta_box( int $wc_id ): string {
	ob_start();
	Skwirrel_WC_Sync_Product_Documents::instance()->render_meta_box( get_post( $wc_id ) );
	return (string) ob_get_clean();
}

// ------------------------------------------------------------------
// AC-1 — the resolved title reaches the meta and the rendered link
// ------------------------------------------------------------------

test( 'the translated title is what lands in the stored meta, not the filename', function () {
	docnames_stub_download( 'cdn.example/DOP-3821-EN.pdf' );

	$sku = 'DOC-AC1-store';
	$id  = docnames_seed_product( $sku );

	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'DOP-3821-EN.pdf', [ docnames_translation( 'nl', 'Prestatieverklaring' ) ] )
	);

	// The whole point of the story: the human title, never the raw filename.
	expect( docnames_stored( $id ) )->toBe( [ 'Prestatieverklaring' ] );
} );

test( 'the frontend documents tab renders the resolved title as the link text', function () {
	docnames_stub_download( 'cdn.example/DOP-3821-EN.pdf' );

	$sku = 'DOC-AC1-render';
	$id  = docnames_seed_product( $sku );

	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'DOP-3821-EN.pdf', [ docnames_translation( 'nl', 'Prestatieverklaring' ) ] )
	);

	$html = docnames_render_tab( $id );

	expect( $html )->toContain( 'Prestatieverklaring' );
	// And the filename it replaced is gone from the link text.
	expect( $html )->not->toContain( 'DOP-3821-EN.pdf' );
} );

test( 'the admin meta box renders the same resolved title', function () {
	docnames_stub_download( 'cdn.example/DOP-3821-EN.pdf' );

	$sku = 'DOC-AC1-metabox';
	$id  = docnames_seed_product( $sku );

	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'DOP-3821-EN.pdf', [ docnames_translation( 'nl', 'Prestatieverklaring' ) ] )
	);

	expect( docnames_render_meta_box( $id ) )->toContain( 'Prestatieverklaring' );
} );

test( 'the legacy all-in-one upsert path writes the resolved title too', function () {
	docnames_stub_download( 'cdn.example/MON-118.pdf' );

	// create_or_update_product() deliberately skips media; assign_media() is its companion phase.
	// upsert_product() is the older all-in-one path and carries its own document write, so the
	// fix has to be present in both or half the shops never see it.
	$sku = 'DOC-AC1-legacy';
	docnames_seed_product( $sku );

	$this->upserter->upsert_product(
		docnames_payload( $sku, 'MON-118.pdf', [ docnames_translation( 'nl', 'Montagehandleiding' ) ] )
	);

	$wc_id = wc_get_product_id_by_sku( $sku );
	expect( $wc_id )->toBeGreaterThan( 0 );
	expect( docnames_stored( $wc_id ) )->toBe( [ 'Montagehandleiding' ] );
} );

// ------------------------------------------------------------------
// AC-2 — the prefix match survives the round trip
// ------------------------------------------------------------------

test( 'a two-letter prefix match resolves end to end', function () {
	update_option( 'skwirrel_wc_sync_settings', [ 'image_language' => 'nl-BE', 'sync_images' => false ] );
	docnames_stub_download( 'cdn.example/CER-77.pdf' );

	$sku = 'DOC-AC2-prefix';
	$id  = docnames_seed_product( $sku );

	// Configured nl-BE, only nl present: the prefix leg of the shared chain.
	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'CER-77.pdf', [ docnames_translation( 'nl', 'Certificaat' ) ] )
	);

	expect( docnames_stored( $id ) )->toBe( [ 'Certificaat' ] );
	expect( docnames_render_tab( $id ) )->toContain( 'Certificaat' );
} );

test( 'an exact match wins over a prefix sibling', function () {
	update_option( 'skwirrel_wc_sync_settings', [ 'image_language' => 'nl-BE', 'sync_images' => false ] );
	docnames_stub_download( 'cdn.example/CER-78.pdf' );

	$sku = 'DOC-AC2-exact';
	$id  = docnames_seed_product( $sku );

	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'CER-78.pdf', [
			docnames_translation( 'nl', 'Nederlands certificaat' ),
			docnames_translation( 'nl-BE', 'Belgisch certificaat' ),
		] )
	);

	expect( docnames_stored( $id ) )->toBe( [ 'Belgisch certificaat' ] );
} );

// ------------------------------------------------------------------
// AC-3 — an untranslated document still renders, never nameless
// ------------------------------------------------------------------

test( 'a document with no translations still renders, by its filename', function () {
	docnames_stub_download( 'cdn.example/losse-bijlage.pdf' );

	// The control for AC-1: proves the assertions above detect a *change* of name rather than
	// merely the presence of one, and pins the NFR-9 tail — a document is never nameless.
	$sku = 'DOC-AC3-untranslated';
	$id  = docnames_seed_product( $sku );

	$this->upserter->assign_media( $id, docnames_payload( $sku, 'losse-bijlage.pdf' ) );

	expect( docnames_stored( $id ) )->toBe( [ 'losse-bijlage.pdf' ] );
	expect( docnames_render_tab( $id ) )->toContain( 'losse-bijlage.pdf' );
} );

test( 'an empty translation title falls through to the filename rather than vanishing', function () {
	docnames_stub_download( 'cdn.example/leeg.pdf' );

	// get_documents_for_product() drops any document with an empty name, so a resolver that
	// returned '' here would make the document disappear from the tab entirely.
	$sku = 'DOC-AC3-emptytitle';
	$id  = docnames_seed_product( $sku );

	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'leeg.pdf', [ docnames_translation( 'nl', '   ' ) ] )
	);

	expect( docnames_stored( $id ) )->toBe( [ 'leeg.pdf' ] );
	expect( docnames_render_tab( $id ) )->toContain( 'leeg.pdf' );
} );

// ------------------------------------------------------------------
// AC-4 — an ordinary re-sync repairs a stored raw filename
// ------------------------------------------------------------------

test( 'a stored raw filename is refreshed to the resolved title on re-sync', function () {
	docnames_stub_download( 'cdn.example/DOP-9001-EN.pdf' );

	// Exactly the state every shop synced before 3.14.0 is in: the meta already exists and holds
	// the filename. The story promises no separate migration — the normal sync repairs it.
	$sku = 'DOC-AC4-refresh';
	$id  = docnames_seed_product( $sku, [
		[
			'id'         => 0,
			'url'        => 'https://cdn.example/DOP-9001-EN.pdf',
			'name'       => 'DOP-9001-EN.pdf',
			'type'       => 'MAN',
			'type_label' => 'Manual',
		],
	] );

	// Precondition: the stale name really is stored, so the assertion below measures a change.
	expect( docnames_stored( $id ) )->toBe( [ 'DOP-9001-EN.pdf' ] );

	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'DOP-9001-EN.pdf', [ docnames_translation( 'nl', 'Prestatieverklaring' ) ] )
	);

	expect( docnames_stored( $id ) )->toBe( [ 'Prestatieverklaring' ] );
	expect( docnames_render_tab( $id ) )->toContain( 'Prestatieverklaring' );
	expect( docnames_render_tab( $id ) )->not->toContain( 'DOP-9001-EN.pdf' );
} );

test( 'the refresh is not a one-off — a later title change lands as well', function () {
	docnames_stub_download( 'cdn.example/DOP-9002-EN.pdf' );

	$sku     = 'DOC-AC4-retitle';
	$id      = docnames_seed_product( $sku );
	$payload = static fn( string $title ): array => docnames_payload(
		$sku,
		'DOP-9002-EN.pdf',
		[ docnames_translation( 'nl', $title ) ]
	);

	$this->upserter->assign_media( $id, $payload( 'Eerste titel' ) );
	expect( docnames_stored( $id ) )->toBe( [ 'Eerste titel' ] );

	// The PIM renames it; the next ordinary sync must carry that through, not cache the old name.
	$this->upserter->assign_media( $id, $payload( 'Hernoemde titel' ) );
	expect( docnames_stored( $id ) )->toBe( [ 'Hernoemde titel' ] );
} );

// ------------------------------------------------------------------
// AC-5 — markup in a title is escaped at output, in both sinks
// ------------------------------------------------------------------

test( 'markup in a document title is escaped in the frontend tab', function () {
	docnames_stub_download( 'cdn.example/XSS-1.pdf' );

	// The story changes which string is chosen, never how it is escaped — but the string now
	// comes from PIM-authored translation text rather than a filename, so the escaping matters
	// more than it did. Nothing else in the suite would catch esc_html() being dropped.
	$sku = 'DOC-AC5-tab';
	$id  = docnames_seed_product( $sku );

	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'XSS-1.pdf', [
			docnames_translation( 'nl', 'Handleiding <script>alert(1)</script>' ),
		] )
	);

	// Stored raw — escaping is the consumer's job, deliberately.
	expect( docnames_stored( $id ) )->toBe( [ 'Handleiding <script>alert(1)</script>' ] );

	$html = docnames_render_tab( $id );
	expect( $html )->not->toContain( '<script>alert(1)</script>' );
	expect( $html )->toContain( '&lt;script&gt;alert(1)&lt;/script&gt;' );
} );

test( 'markup in a document title is escaped in the admin meta box', function () {
	docnames_stub_download( 'cdn.example/XSS-2.pdf' );

	$sku = 'DOC-AC5-metabox';
	$id  = docnames_seed_product( $sku );

	$this->upserter->assign_media(
		$id,
		docnames_payload( $sku, 'XSS-2.pdf', [
			docnames_translation( 'nl', 'Datasheet <img src=x onerror=alert(1)>' ),
		] )
	);

	$html = docnames_render_meta_box( $id );
	expect( $html )->not->toContain( '<img src=x onerror=alert(1)>' );
	expect( $html )->toContain( '&lt;img src=x onerror=alert(1)&gt;' );
} );
