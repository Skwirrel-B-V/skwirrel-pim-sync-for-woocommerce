<?php

declare(strict_types=1);

/**
 * Tests that the "Field mapping" settings tab (Epic 6, FR-18/FR-19) is fully translated.
 *
 * The existing catalogue guard in AdminSettingsRequiredFieldsTest only asserts that a msgid
 * reached the catalogues. That is not enough: a merged-but-untranslated entry has the msgid
 * and an empty msgstr, and gettext then falls back to the English source. The tab shipped in
 * exactly that state once. These tests assert the msgstr is actually filled in for every
 * locale that is not English.
 */

/**
 * Parse a `.po`/`.pot` into `msgid => msgstr`, joining the continuation lines gettext uses to
 * wrap long entries. Obsolete (`#~`) entries are skipped — they no longer ship.
 */
function skw_parse_catalogue(string $path): array
{
    $entries = [];
    $msgid   = null;
    $msgstr  = null;
    $target  = null;

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            if ($msgid !== null) {
                $entries[$msgid] = (string) $msgstr;
            }
            $msgid  = null;
            $msgstr = null;
            $target = null;
            continue;
        }

        if (str_starts_with($line, 'msgid ')) {
            if ($msgid !== null) {
                $entries[$msgid] = (string) $msgstr;
            }
            $msgid  = skw_po_unquote(substr($line, 6));
            $msgstr = null;
            $target = 'msgid';
            continue;
        }

        if (str_starts_with($line, 'msgstr ')) {
            $msgstr = skw_po_unquote(substr($line, 7));
            $target = 'msgstr';
            continue;
        }

        if (str_starts_with($line, '"') && $target !== null) {
            if ($target === 'msgid') {
                $msgid .= skw_po_unquote($line);
            } else {
                $msgstr .= skw_po_unquote($line);
            }
        }
    }

    if ($msgid !== null) {
        $entries[$msgid] = (string) $msgstr;
    }

    return $entries;
}

/** Strip the surrounding quotes from one `.po` string literal and unescape it. */
function skw_po_unquote(string $literal): string
{
    $literal = trim($literal);
    $literal = (string) preg_replace('/^"|"$/', '', $literal);

    return str_replace(['\\"', '\\n', '\\t', '\\\\'], ['"', "\n", "\t", '\\'], $literal);
}

/** Every user-facing string the Field mapping tab renders. */
function skw_field_mapping_strings(): array
{
    return [
        'Field mapping',
        'Drive WooCommerce fields from a Skwirrel custom class feature, so you no longer maintain the same value in two systems.',
        'Field mappings read product-level custom classes. Set the custom class collection ID under "What to sync" so those values can be fetched; without it the mappings stay inactive.',
        'Stock quantity',
        'Product title',
        'Short description',
        'Long description',
        'e.g. 1234 or STOCK_QTY',
        'e.g. 812 or PRODUCT_TITLE',
        'The ID or code of one product-level custom feature holding the stock quantity. The feature must be numeric, or text that contains only a number; range, logical and multi-value features are ignored. When a product has no usable value, its current stock is left untouched — never set to 0 and never switched to unmanaged. Trade-item level features are not used. Products priced on request keep their own availability whatever this feature says. Clearing this field turns the mapping off again, and the next synchronisation then returns priced variations to unmanaged and in stock, discarding the quantities it had been maintaining.',
        'The ID or code of the custom feature holding the product title. Leave empty to keep using the normal source (the ERP description, then the product translations). When a product has no value for this feature, the normal source is used for that product — the title is never blanked.',
        'The ID or code of the custom feature holding the short description. Leave empty to keep using the product translations. A product without a value keeps the normal source.',
        'The ID or code of the custom feature holding the long description. Leave empty to keep using the normal source. Formatting is kept; unsafe markup is removed. A product without a value keeps the normal source.',
    ];
}

test('the Field mapping strings are all present in the POT', function () {
    $pot = skw_parse_catalogue(
        dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot'
    );

    foreach (skw_field_mapping_strings() as $string) {
        expect(array_key_exists($string, $pot))
            ->toBeTrue('skwirrel-pim-sync.pot is missing: ' . $string);
    }
});

test('every locale carries a filled-in translation for each Field mapping string', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';

    $locales = glob($languages . '/skwirrel-pim-sync-*.po') ?: [];
    expect($locales)->toHaveCount(7);

    foreach ($locales as $path) {
        $catalogue = skw_parse_catalogue($path);
        $name      = basename($path);

        foreach (skw_field_mapping_strings() as $string) {
            expect(array_key_exists($string, $catalogue))
                ->toBeTrue($name . ' is missing: ' . $string);

            expect($catalogue[$string])
                ->not->toBe('', $name . ' has no translation for: ' . $string);
        }
    }
});
