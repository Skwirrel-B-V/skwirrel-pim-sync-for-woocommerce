<?php

declare(strict_types=1);

/**
 * Guards against the untranslated-string backlog (measured 2026-08-27, closed 2026-09-08)
 * silently reopening. Every shipped locale must translate every non-empty msgid — the only
 * legitimate empty msgstr is the catalogue header, whose own msgid is empty too.
 *
 * Deliberately re-parses msgid/msgstr blocks by hand rather than reusing the simpler
 * "contains msgid \"...\"" substring checks the other translation tests use: those are fine for
 * asserting a specific string exists, but a real "is anything untranslated" pass must not treat a
 * long msgstr that merely *starts* with `msgstr ""` before wrapping onto continuation lines as
 * empty — that misdetection previously caused a translation-filling pass to concatenate new text
 * onto already-translated wrapped strings instead of recognising them as already complete.
 */

function skwUnescapePoString(string $s): string
{
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        if ('\\' === $s[$i] && $i + 1 < $len) {
            $next = $s[$i + 1];
            $out .= match ($next) {
                'n' => "\n",
                't' => "\t",
                '\\' => '\\',
                '"' => '"',
                default => $next,
            };
            $i++;
        } else {
            $out .= $s[$i];
        }
    }
    return $out;
}

/**
 * @return array<int, array{msgid: string, msgstr: string}>
 */
function skwParsePoEntries(string $path): array
{
    $content = (string) file_get_contents($path);
    $blocks = preg_split('/\n\n+/', $content) ?: [];
    $entries = [];

    foreach ($blocks as $block) {
        if (str_starts_with(trim($block), '#~')) {
            continue;
        }
        if (!preg_match(
            '/(?:^|\n)msgid((?:\s*\n?\s*"(?:[^"\\\\]|\\\\.)*")+)\s*\nmsgstr((?:\s*\n?\s*"(?:[^"\\\\]|\\\\.)*")+)\s*$/',
            $block,
            $m
        )) {
            continue;
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $msgidParts);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[2], $msgstrParts);

        $entries[] = [
            'msgid' => skwUnescapePoString(implode('', $msgidParts[1])),
            'msgstr' => implode('', $msgstrParts[1]), // raw is enough to test emptiness
        ];
    }

    return $entries;
}

test('every locale catalogue translates every non-empty msgid', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';
    $files = glob($languages . '/skwirrel-pim-sync-*.po') ?: [];

    expect($files)->toHaveCount(7);

    foreach ($files as $file) {
        $untranslated = [];
        foreach (skwParsePoEntries($file) as $entry) {
            if ('' !== $entry['msgid'] && '' === $entry['msgstr']) {
                $untranslated[] = $entry['msgid'];
            }
        }
        expect($untranslated)->toBe(
            [],
            basename($file) . ' has ' . count($untranslated) . ' untranslated string(s), e.g.: '
                . ($untranslated[0] ?? '')
        );
    }
});

test('every locale catalogue has no fatal format-string mismatches', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';
    $files = glob($languages . '/skwirrel-pim-sync-*.po') ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        // Requires msgfmt (already a hard dependency of this repo's translation workflow —
        // see docs/release.md). --check-format catches a msgstr referencing a %s/%d the
        // msgid never had, which crashes or silently drops output for that string at runtime.
        exec('msgfmt --check-format ' . escapeshellarg($file) . ' -o /dev/null 2>&1', $output, $exitCode);
        expect($exitCode)->toBe(0, basename($file) . ': ' . implode("\n", $output));
    }
});
