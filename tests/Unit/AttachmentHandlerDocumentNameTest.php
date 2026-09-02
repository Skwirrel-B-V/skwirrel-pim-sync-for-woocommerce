<?php

declare(strict_types=1);

/**
 * Tests for document link names (Story 6.5, FR-23).
 *
 * The defect was one line: the documents tab took the raw `file_name` and never consulted
 * `_attachment_translations`, so a shop visitor saw `DOP-3821-EN.pdf` instead of
 * "Prestatieverklaring". `resolve_document_name()` fixes the choice of string; nothing about
 * how it is escaped downstream changes.
 *
 * `get_document_attachments()` itself calls import_file() (network + filesystem) so it is not
 * unit-testable — which is why the resolver is a public pure method.
 */

beforeEach(function () {
    $GLOBALS['_test_options'] = [];
    $this->handler = new Skwirrel_WC_Sync_Attachment_Handler('nl');
});

afterEach(function () {
    $GLOBALS['_test_options'] = [];
});

/** Point the plugin at a language for one test. */
function doc_language(Skwirrel_WC_Sync_Attachment_Handler $handler, string $lang): void
{
    // The language is injected, not read back out of the settings option: the handler resolves
    // every title in the language its owner hands it, which is what lets a sync run freeze one.
    $handler->set_image_language($lang);
}

/**
 * An attachment carrying translations.
 *
 * @param array<int, array<string,mixed>> $translations Translation entries.
 * @param array<string,mixed>             $overrides    Attachment-level fields.
 * @return array<string,mixed>
 */
function doc_attachment(array $translations = [], array $overrides = []): array
{
    return array_merge(
        [ '_attachment_translations' => $translations ],
        $overrides
    );
}

// ------------------------------------------------------------------
// AC1 — exact language match
// ------------------------------------------------------------------

test('an exact language match wins', function () {
    doc_language($this->handler, 'nl');

    $att = doc_attachment(
        [
            [ 'language' => 'de', 'product_attachment_title' => 'Montageanleitung' ],
            [ 'language' => 'nl', 'product_attachment_title' => 'Montagehandleiding' ],
        ],
        [ 'file_name' => 'MAN-123.pdf' ]
    );

    expect($this->handler->resolve_document_name($att, 'https://cdn.example/MAN-123.pdf'))
        ->toBe('Montagehandleiding');
});

// ------------------------------------------------------------------
// AC2 — prefix match, through the shared chain
// ------------------------------------------------------------------

test('a two-letter prefix match wins when there is no exact match', function () {
    doc_language($this->handler, 'nl-BE');

    $att = doc_attachment(
        [
            [ 'language' => 'fr', 'product_attachment_title' => 'Notice de montage' ],
            [ 'language' => 'nl', 'product_attachment_title' => 'Montagehandleiding' ],
        ],
        [ 'file_name' => 'MAN-123.pdf' ]
    );

    expect($this->handler->resolve_document_name($att, 'https://cdn.example/MAN-123.pdf'))
        ->toBe('Montagehandleiding');
});

test('the first entry is used when neither exact nor prefix matches', function () {
    doc_language($this->handler, 'es');

    $att = doc_attachment(
        [
            [ 'language' => 'de', 'product_attachment_title' => 'Montageanleitung' ],
            [ 'language' => 'nl', 'product_attachment_title' => 'Montagehandleiding' ],
        ],
        [ 'file_name' => 'MAN-123.pdf' ]
    );

    expect($this->handler->resolve_document_name($att, 'https://cdn.example/MAN-123.pdf'))
        ->toBe('Montageanleitung');
});

// ------------------------------------------------------------------
// AC3 — every rung of the fallback chain
// ------------------------------------------------------------------

test('an empty translation title falls through to the attachment-level title', function () {
    doc_language($this->handler, 'nl');

    $att = doc_attachment(
        [ [ 'language' => 'nl', 'product_attachment_title' => '' ] ],
        [ 'product_attachment_title' => 'Prestatieverklaring', 'file_name' => 'DOP-1.pdf' ]
    );

    expect($this->handler->resolve_document_name($att, 'https://cdn.example/DOP-1.pdf'))
        ->toBe('Prestatieverklaring');
});

test('a whitespace-only translation title falls through too', function () {
    doc_language($this->handler, 'nl');

    $att = doc_attachment(
        [ [ 'language' => 'nl', 'product_attachment_title' => '   ' ] ],
        [ 'file_name' => 'DOP-1.pdf' ]
    );

    expect($this->handler->resolve_document_name($att, 'https://cdn.example/DOP-1.pdf'))
        ->toBe('DOP-1.pdf');
});

test('no translations at all falls back to file_name', function () {
    doc_language($this->handler, 'nl');

    $att = doc_attachment([], [ 'file_name' => 'DOP-1.pdf' ]);

    expect($this->handler->resolve_document_name($att, 'https://cdn.example/DOP-1.pdf'))
        ->toBe('DOP-1.pdf');
});

test('nothing at all falls back to the URL basename', function () {
    doc_language($this->handler, 'nl');

    expect($this->handler->resolve_document_name([], 'https://cdn.example/files/DOP-9.pdf'))
        ->toBe('DOP-9.pdf');
});

test('a URL with no usable path falls back to the literal Document', function () {
    doc_language($this->handler, 'nl');

    expect($this->handler->resolve_document_name([], ''))->toBe('Document');
    expect($this->handler->resolve_document_name([], 'https://cdn.example'))->toBe('Document');
});

test('the resolver never returns an empty string, whatever it is given', function () {
    doc_language($this->handler, 'nl');

    // A nameless document is dropped by get_documents_for_product(), so "" is the one
    // outcome that must be unreachable.
    $shapes = [
        [],
        doc_attachment([], [ 'file_name' => '' ]),
        doc_attachment([ [ 'language' => 'nl', 'product_attachment_title' => '' ] ], [ 'file_name' => '  ' ]),
        doc_attachment([ [ 'language' => 'nl' ] ]),
    ];

    foreach ($shapes as $i => $att) {
        expect($this->handler->resolve_document_name($att, ''))->not->toBe('', "shape {$i}");
    }
});

// ------------------------------------------------------------------
// AC5 — the resolver returns raw text; escaping is the consumer's job
// ------------------------------------------------------------------

test('HTML in a title is returned verbatim, not escaped here', function () {
    doc_language($this->handler, 'nl');

    $att = doc_attachment(
        [ [ 'language' => 'nl', 'product_attachment_title' => 'Handleiding <b>NL</b>' ] ]
    );

    expect($this->handler->resolve_document_name($att, 'https://cdn.example/x.pdf'))
        ->toBe('Handleiding <b>NL</b>');
});

// ------------------------------------------------------------------
// Regression pin — the Task 1 refactor must not change image behaviour
// ------------------------------------------------------------------

test('image meta resolution is unchanged by the shared-chain refactor', function () {
    $invoke = function (array $att): array {
        $ref = new ReflectionMethod($this->handler, 'get_attachment_meta_for_language');
        return $ref->invoke($this->handler, $att);
    };

    doc_language($this->handler, 'nl');

    // Exact match.
    expect($invoke(doc_attachment(
        [
            [ 'language' => 'de', 'product_attachment_title' => 'DE', 'product_attachment_description' => 'DE desc' ],
            [ 'language' => 'nl', 'product_attachment_title' => 'NL', 'product_attachment_description' => 'NL desc' ],
        ],
        [ 'file_name' => 'img.jpg' ]
    )))->toBe([ 'title' => 'NL', 'description' => 'NL desc' ]);

    // Prefix match.
    doc_language($this->handler, 'nl-BE');
    expect($invoke(doc_attachment(
        [ [ 'language' => 'nl', 'product_attachment_title' => 'NL', 'product_attachment_description' => 'NL desc' ] ],
        [ 'file_name' => 'img.jpg' ]
    )))->toBe([ 'title' => 'NL', 'description' => 'NL desc' ]);

    // First-entry fallback.
    doc_language($this->handler, 'es');
    expect($invoke(doc_attachment(
        [ [ 'language' => 'de', 'product_attachment_title' => 'DE', 'product_attachment_description' => 'DE desc' ] ],
        [ 'file_name' => 'img.jpg' ]
    )))->toBe([ 'title' => 'DE', 'description' => 'DE desc' ]);

    // No translations: file_name, empty description.
    expect($invoke(doc_attachment([], [ 'file_name' => 'img.jpg' ])))
        ->toBe([ 'title' => 'img.jpg', 'description' => '' ]);

    // Translation present but title key absent: file_name, per the baked-in image fallback.
    doc_language($this->handler, 'nl');
    expect($invoke(doc_attachment(
        [ [ 'language' => 'nl', 'product_attachment_description' => 'NL desc' ] ],
        [ 'file_name' => 'img.jpg' ]
    )))->toBe([ 'title' => 'img.jpg', 'description' => 'NL desc' ]);

    // The image path deliberately does NOT consult attachment-level product_attachment_title —
    // that rung belongs to documents only. Pinning it stops the refactor bleeding across.
    expect($invoke(doc_attachment(
        [],
        [ 'file_name' => 'img.jpg', 'product_attachment_title' => 'Should not win' ]
    )))->toBe([ 'title' => 'img.jpg', 'description' => '' ]);
});

// ------------------------------------------------------------------
// The last rung is customer-visible, so it ships translated
// ------------------------------------------------------------------

test('the nameless-document fallback is translatable and present in every catalogue', function () {
    $plugin = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync';

    $source = (string) file_get_contents($plugin . '/includes/class-skwirrel-wc-sync-attachment-handler.php');
    expect($source)->toContain("__( 'Document', 'skwirrel-pim-sync' )");

    $catalogues = array_merge(
        [$plugin . '/languages/skwirrel-pim-sync.pot'],
        glob($plugin . '/languages/skwirrel-pim-sync-*.po') ?: []
    );
    expect($catalogues)->toHaveCount(8);

    foreach ($catalogues as $catalogue) {
        expect(str_contains((string) file_get_contents($catalogue), 'msgid "Document"'))
            ->toBeTrue(basename($catalogue) . ' is missing the Document fallback');
    }
});

test('the resolver reads the language it was given, never the live option', function () {
    // The constructor argument used to be written and then ignored; a run freezes a language and
    // must get it, whatever an administrator saves halfway through.
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'image_language' => 'fr' ];

    $handler = new Skwirrel_WC_Sync_Attachment_Handler('de');

    $att = doc_attachment(
        [
            [ 'language' => 'fr', 'product_attachment_title' => 'Notice de montage' ],
            [ 'language' => 'de', 'product_attachment_title' => 'Montageanleitung' ],
        ],
        [ 'file_name' => 'MAN-123.pdf' ]
    );

    expect($handler->resolve_document_name($att, 'https://cdn.example/MAN-123.pdf'))
        ->toBe('Montageanleitung');
});
