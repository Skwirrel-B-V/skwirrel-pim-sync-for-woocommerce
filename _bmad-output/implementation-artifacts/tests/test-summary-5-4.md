# Test Automation Summary — Story 5.4 (Test Connection reports what actually came back)

**Workflow:** `bmad-qa-generate-e2e-tests` · **Date:** 2026-08-26 · **Story:** `5-4-test-connection-metrics.md`

## Framework detected

No new framework introduced — the project already has two Pest suites and no browser harness.

| Suite | Command | Bootstrap |
|---|---|---|
| Unit | `vendor/bin/pest` | `tests/bootstrap.php` (WP/WC stubs, no Docker) |
| Integration | `npm run test:integration` | real WP 7.0 + WooCommerce 10.8 via wp-env |

"E2E" here means: drive the real code path against a real WordPress + WooCommerce and assert the
observable outcome — the JSON an administrator's browser receives, the notice markup on the settings
screen, or the HTTP request that left the plugin.

## What already existed

`tests/Unit/TestConnectionMetricsTest.php` (16 tests) covered `format_test_result()` thoroughly —
tone selection, unknown/zero counts, the transport-error wording, the attempts line — plus the
pagination read in `test_connection()` through a canned-response client subclass. Nothing there was
weakened; everything below is additive.

Its blind spot is structural: the stub bootstrap has no HTTP layer, no options table and no admin
render, so everything on either side of the formatter was unasserted.

## Gaps found and closed

### New file — `tests/Integration/TestConnectionMetricsIntegrationTest.php` (15 tests)

| # | Gap | Why it mattered | AC |
|---|---|---|---|
| 1 | `call()` attaching `meta` on **every** return path | The story calls out that a branch missing `meta` renders blank metrics. Only the success and JSON-RPC-error paths were exercised; the invalid-JSON, HTTP-error and retry-exhaustion exits were not reached by any test. | 1, 4 |
| 2 | `attempts` really counting requests | The unit test feeds `attempts => 3` in by hand — it proves the wording, not the counting. A transport failure with `retries = 2` now asserts three real `wp_remote_post()` calls **and** three recorded requests. | 4 |
| 3 | HTTP errors not being retried | A 500 is an answer, not a lost request. Nothing pinned that it exits after one attempt, so a future retry-loop change could triple the wait silently. | 4 |
| 4 | `http_code` on a transport failure | The `''` → `0` cast is the whole reason the screen says "no response" instead of "Status: 0". Asserted through the real `wp_remote_retrieve_response_code()`, not a fixture. | 4 |
| 5 | The pagination read against a real HTTP response | The canned client bypasses `call()` entirely. A response with two products and no `page` block now proves the count stays unknown end-to-end. | 2 |
| 6 | The AJAX payload itself | `handle_test_connection_ajax()` had no test at all. Now: success carries tone/headline/details; **zero products stays `success: true`** with `tone: warning`; a failure carries `tone: error` with timing and status alongside the message. | 1, 3, 4 |
| 7 | The token not leaking (NFR-4) | The unit test proves the *formatter* has no token input. This proves the *response* has no token in it — while asserting the token really was on the request, so the guard cannot pass vacuously. | 5 |
| 8 | "No new writes" | AC 5's central claim was unasserted. The options table is diffed across the whole AJAX call: no new option, and no new transient (they land in that table without a persistent object cache). | 5 |
| 9 | The legacy notice | AC 8's render half: warning tone → `notice-warning` with its metric lines, an API-supplied `<img onerror>` message escaped, a pre-change transient (no `tone`, no `details`) still rendering, and the transient being read exactly once. | 8 |

### Extended — `tests/Unit/TestConnectionMetricsTest.php` (16 → 38 tests)

| # | Gap | Why it mattered | AC |
|---|---|---|---|
| 10 | The 400 boundary | 399/400/503 pin where the wording flips from "JSON-RPC error" to a bare HTTP code. | 4 |
| 11 | Nonsensical measurement | `duration_ms => -5`, `attempts => 0` must clamp, never reach the screen as "-5 ms" / "Attempts: 0". | 1 |
| 12 | Detail **order** | The aria-live region reads the array in order, so round-trip → status → products → attempts is part of the contract. | 6 |
| 13 | Error passthrough | The formatter must **not** sanitise — escaping is the renderer's job. Silently stripping markup here would hide a renderer that stopped escaping. | 6 |
| 14 | `innerHTML` regression guard | AC 6's core claim. The `setRes()` body is sliced out of the shipped source and asserted to use `createElement`/`textContent`/`removeChild` and never `innerHTML` (which *is* used elsewhere in the same file, so a file-wide grep would not do). | 6 |
| 15 | Tone→class map + stale-script fallback | Both halves of the degradation story are now pinned. | 5, 6 |
| 16 | Warning tone not colour alone (NFR-7) | `.skw-test-warning` asserted to carry weight **and** a glyph; `.skw-test-metric` asserted to carry `tabular-nums`. | 3, 6 |
| 17 | One shared formatter | AC 8 says the two paths "cannot drift". The legacy path ends in `wp_safe_redirect()` + `exit` and cannot be invoked from a test process, so this is asserted structurally: both handlers call `self::format_test_result()`, and the replaced `Connection successful — settings saved.` string is gone. | 8 |
| 18 | Translatability | All nine new msgids present in the `.pot`, and the source wraps them with the literal `'skwirrel-pim-sync'` domain. | 7 |

## Coverage

| Acceptance criterion | Covered by |
|---|---|
| AC 1 — time, status, product total | unit (formatter) + integration (real transport, AJAX payload) |
| AC 2 — missing total reads as unknown | unit + integration (real response with no `page` block) |
| AC 3 — zero is a warning | unit (tone) + integration (AJAX stays `success: true`) + CSS guard (glyph + weight) |
| AC 4 — failures still report timing/status/attempts | unit (wording) + integration (all four failure exits, real attempt counting) |
| AC 5 — no new writes, no token in output | integration (options-table diff, raw-response scan) |
| AC 6 — safe, accessible rendering | source guards (no `innerHTML`, tabular-nums) + detail-order test. **Browser behaviour itself is not covered** — no harness. |
| AC 7 — translatable strings | `.pot` + text-domain guards |
| AC 8 — legacy path parity | integration (notice render, escaping, legacy payload) + structural shared-formatter guard |

## Not covered (deliberately, not faked)

- The click actually rendering spans, and the live region announcing them — no browser harness.
- How the amber warning tone looks on screen.
- `handle_test_connection()` end to end: it finishes with `wp_safe_redirect()` + `exit`, which would
  kill the test process. Its render half is covered; its formatting is covered structurally.

Both exclusions were verified by hand in wp-env per the story's completion notes.

## Gate results

| Gate | Result |
|---|---|
| `vendor/bin/pest` | **533 passed** (1069 assertions) |
| `vendor/bin/phpstan analyse --memory-limit=2G` | **No errors** (level 6, no baseline entries added) |
| `vendor/bin/phpcs` | **Clean** |
| `npm run test:integration` | **177 passed**, 1 failed — see below |

### Pre-existing failure, unrelated to this story

`tests/Integration/ContextIdIntegrationTest.php:559` — *"a configured Context ID reaches the product
fetch of a real sync run"* fails on `the run made no membership sweep`. It fails **in isolation** on
this branch too (`--filter=ContextId`: 1 failed, 22 passed), i.e. it predates this pass and is not
caused by the new file. It belongs to Story 5.3 / the membership-sweep behaviour and was left for a
deliberate decision rather than silently re-asserted.

## Next steps

- Triage the Story 5.3 sweep failure above before the release tag.
- If a browser harness is ever added, the three "not covered" items are the first candidates.
