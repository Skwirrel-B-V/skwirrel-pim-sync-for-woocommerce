# Test Automation Summary — Story 5.1 (Tabbed settings navigation)

**Workflow:** `bmad-qa-generate-e2e-tests` · **Date:** 2026-08-26 · **Story:** `5-1-tabbed-settings-navigation.md`

## Framework detected

No new framework introduced. The project already has two Pest suites and no browser harness:

| Suite | Command | Bootstrap |
|---|---|---|
| Unit | `vendor/bin/pest` | `tests/bootstrap.php` (WP/WC stubs, no Docker) |
| Integration | `npm run test:integration` | real WP 7.0 + WooCommerce 10.8 via wp-env |

There is no Playwright/Cypress in this repo and none was added — `project-context.md` records that
decision explicitly ("do not write a test that pretends to cover" the browser layer). "E2E" here
means: render the real admin screen for a real administrator against a real WordPress and assert
the outcome, which is the highest fidelity this project supports.

## Gaps found and closed

The story shipped with 18 unit + 9 integration tests. Auditing them against the eight acceptance
criteria surfaced 17 machine-checkable claims that nothing asserted. All were written and are
passing.

### Unit — `tests/Unit/SettingsTabsTest.php` (+7, now 25)

| Test | AC | Gap it closes |
|---|---|---|
| an errored slug that is not in the registry cannot steal the opening tab | 3 | A stale slug in the error counts must not blank the opening tab |
| a filter-registered tab claims its own error codes | 3, 7 | Error routing was only proven for built-in tabs |
| a code claimed by two tabs is counted once, on the first tab in order | 3 | The `break` in `count_errors_by_tab()` was unasserted |
| `_required` is only stripped from the end of a code | 3 | Suffix stripping must not match mid-string |
| the same code raised twice is counted twice | 3 | Duplicate codes are counted, not deduplicated |
| a closure renderer survives normalisation | 7 | The extension path — `normalize_settings_tabs()` must not flatten a callable |
| every built-in tab declares the field ids its own sanitiser rules can flag | 3 | Guards against a rename silently killing the badge |

### Integration — `tests/Integration/SettingsTabsIntegrationTest.php` (+10, now 19)

Parsed with `DOMDocument`/`DOMXPath` rather than substring offsets, so "inside panel X" means
containment in the tree.

| Test | AC | Gap it closes |
|---|---|---|
| each of the eight field groups sits in exactly the tab the re-home map names | 1 | **The re-home map itself was never asserted** — the central claim of AC 1. Also pins the count at eight, so a ninth group cannot appear unnoticed |
| the save button is inside the form but outside every panel | 1 | "visible from every tab" was unchecked |
| with no JavaScript every panel is a visible sequential section | 5 | **The no-JS baseline was untested** — a server-side `hidden` would lose three quarters of the screen for a no-JS admin |
| the roving tabindex belongs to the selected tab, and only to it | 6 | The old assertion (`tabindex="0"` count `>= 1`) also matched the panels; this pairs `tabindex` to `aria-selected` per tab and checks the tablist is labelled |
| a sanitiser error is readable on the page, above the tab strip | 3 | The badge was asserted, the *message* was not — and the message block is new behaviour this story introduced |
| a clean render carries no error notice and no badge | 3 | The negative case: no false badge, and Connection opens |
| a tab registered from outside renders itself, in its order position | 7 | **AC 7 had no end-to-end proof** — only the registry was unit-tested. This registers through the filter, renders through its own callable, and lands inside the one form |
| an outside tab whose errors fire opens first | 3, 7 | Error routing and initial-tab selection work for extension tabs too |
| the tab behaviour rides on the existing admin script handle | 4, T3 | No new script handle; and the fragment mechanism never writes a `tab=` query var (AC 4's collision) |
| the tab styling reuses the `skw-*` sheet, never the legacy one | 1, T5 | `.skw-tab*` in `dashboard.css`, focus-visible ring present, `admin.css` untouched |

## Results

```
vendor/bin/pest                                → 421 passed (663 assertions)
npm run test:integration                       → 116 passed, 1 pre-existing deprecation
vendor/bin/phpstan analyse --memory-limit=2G   → No errors
vendor/bin/phpcs                               → 34/34 clean
```

## Coverage against the acceptance criteria

| AC | Status |
|---|---|
| 1 — every field in exactly one of four tabs, per the re-home map | ✅ fully covered |
| 2 — saving is the same request as before | ✅ fully covered (baseline input-name fixture + round-trip) |
| 3 — a tab with an error is marked and opens first | ✅ fully covered (badge, message, count, ordering, negative case) |
| 4 — `#tab-{slug}` deep-linking without a `?tab=` collision | ⚠️ partial — the script is asserted present and query-var-free; the fragment *behaviour* is browser-only |
| 5 — no field unreachable without JavaScript | ✅ fully covered server-side |
| 6 — keyboard and assistive technology | ⚠️ partial — full ARIA wiring and the initial roving state are asserted; the key handling and focus ring are browser-only |
| 7 — extensible registration | ✅ fully covered (unit + integration through the filter) |
| 8 — no regressions | ✅ covered (bound element IDs, permalinks AJAX select, danger zone, no `disabled`) |

## Deliberately not automated

There is no browser harness in this repo, so these stay manual and are recorded as such in the
test file's header rather than faked:

- clicking a tab actually switching panels, and Left/Right/Home/End moving between them;
- how the focus ring looks;
- `history.replaceState` writing `#tab-{slug}` on activation;
- real Chrome/Firefox native validation of a `required` control inside a hidden panel;
- screen-reader announcement of the error badge.

## Next steps

- Run both suites in CI (integration needs the wp-env stack).
- If browser coverage is ever wanted, the four items above are the shortlist that would justify a
  Playwright harness — they are the only claims in this story no PHP test can reach.
