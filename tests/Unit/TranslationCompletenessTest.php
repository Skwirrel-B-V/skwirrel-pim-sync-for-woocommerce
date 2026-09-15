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

test('every POT msgid is present in every locale catalogue', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';
    $pot = array_filter(array_column(skwParsePoEntries($languages . '/skwirrel-pim-sync.pot'), 'msgid'));

    foreach (glob($languages . '/skwirrel-pim-sync-*.po') ?: [] as $file) {
        $missing = array_values(array_diff($pot, array_column(skwParsePoEntries($file), 'msgid')));
        expect($missing)->toBe([], basename($file) . ' is missing POT msgid(s), e.g.: ' . ($missing[0] ?? ''));
    }
});

/**
 * @return array<string, string> Relative path => source, for every PHP file in the plugin.
 */
function skwPluginPhpSources(): array
{
    $root  = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $out   = [];
    foreach ($files as $file) {
        if ('php' === $file->getExtension()) {
            $out[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }
    return $out;
}

test('no translatable string uses a single-quoted \n, which renders as a literal backslash-n', function () {
    $offenders = [];
    foreach (skwPluginPhpSources() as $path => $source) {
        if (preg_match_all("/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_n)\(\s*'(?:[^'\\\\]|\\\\.)*\\\\n(?:[^'\\\\]|\\\\.)*'/", $source, $m)) {
            foreach ($m[0] as $hit) {
                $offenders[] = $path . ': ' . substr($hit, 0, 80);
            }
        }
    }
    expect($offenders)->toBe([]);
});

test('AJAX error and wp_die responses carry no hardcoded, untranslated text', function () {
    $offenders = [];
    foreach (skwPluginPhpSources() as $path => $source) {
        if (preg_match_all("/\b(?:wp_send_json_error|wp_die)\(\s*'[^']*[A-Za-z][^']*'/", $source, $m)) {
            foreach ($m[0] as $hit) {
                $offenders[] = $path . ': ' . $hit;
            }
        }
    }
    expect($offenders)->toBe([]);
});
