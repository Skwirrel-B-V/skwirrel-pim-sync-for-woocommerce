<?php

declare(strict_types=1);

/**
 * The "Content language" custom-value input on the Media & Language field group had a hardcoded
 * placeholder ("e.g. es-ES") that never went through a translation function — every other string
 * on that screen is translatable, this one silently wasn't. Fixed by wrapping it in esc_attr_e()
 * with the plugin's text domain; this pins it in the catalogues so it cannot regress silently.
 */

function skwPlaceholderCatalogueMsgids(string $path): string
{
    $raw = (string) file_get_contents($path);

    return (string) preg_replace('/"[ \t]*\R[ \t]*"/', '', $raw);
}

test('the custom-language placeholder is translatable and in every catalogue', function () {
    $includes = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php';
    $source = (string) file_get_contents($includes);

    expect($source)->toContain("esc_attr_e( 'e.g. es-ES', 'skwirrel-pim-sync' )");
    expect($source)->not->toContain('placeholder="e.g. es-ES"');

    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';
    $catalogues = array_merge(
        [$languages . '/skwirrel-pim-sync.pot'],
        glob($languages . '/skwirrel-pim-sync-*.po') ?: []
    );

    expect($catalogues)->toHaveCount(8);

    foreach ($catalogues as $catalogue) {
        $content = skwPlaceholderCatalogueMsgids($catalogue);
        expect(str_contains($content, 'msgid "e.g. es-ES"'))
            ->toBeTrue(basename($catalogue) . ' is missing: e.g. es-ES');
    }
});
