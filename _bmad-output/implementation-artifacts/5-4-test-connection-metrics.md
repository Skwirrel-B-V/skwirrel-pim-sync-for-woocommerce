---
status: done
baseline_commit: 73e8b1fc2a4c704c0564914f99319daf6612c94c
baseline_revision: 0f7c3c4964b7789f74d7c14f307c1c083a9a22a4
context:
  - _bmad-output/project-context.md
  - _bmad-output/planning-artifacts/epics.md
  - CLAUDE.md
  - .claude/rules/admin-settings.md
---

# Story 5.4: Test Connection reports what actually came back

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner,
I want the connection test to tell me more than "it worked",
so that I can see the integration is genuinely returning products rather than merely reachable.

## Acceptance Criteria

**1 — A successful test reports round-trip time, status, and product total**

**Given** the Connection field group on the settings screen (`class-skwirrel-wc-sync-admin-dashboard.php:838-841`)
**When** "Test connection" succeeds
**Then** the inline result reports **round-trip time** (ms), the **HTTP / JSON-RPC status**, and the **total number of products the API reports** — replacing today's bare `Connection successful — settings saved.`
**And** the product total is the API's own pagination total (`result.page.number_of_items`), **not** `count(result.products)` — the test calls with `limit => 1`, so counting the returned array would always report `1`.

**2 — A missing pagination total is reported as unknown, never as a wrong number**

**Given** a response whose `result.page.number_of_items` key is absent or non-numeric
**When** the result renders
**Then** the product line states the total is unavailable, and **never** substitutes `count(result.products)` (which would read `1 product` on every install).

**3 — Zero products is a warning, not a success**

**Given** a call that succeeds at the transport and JSON-RPC level but reports `number_of_items === 0`
**When** the result renders
**Then** the zero count is stated plainly in a **warning tone** (its own visual treatment, distinct from both success and error, and not conveyed by colour alone — NFR-7), and the copy says the connection works but no products came back.

**4 — A failing test still reports timing and status**

**Given** a test that fails — transport error/timeout (`is_wp_error`), HTTP >= 400, or a JSON-RPC `error` object
**When** the result renders
**Then** round-trip time and status are reported **alongside** the existing error message, so a timeout is distinguishable from a rejection
**And** a transport failure with no HTTP response reports that plainly (no bare `0` status)
**And** when the client retried, the number of attempts is reported — a 30s test that retried twice must not read as a single slow request.

**5 — No new writes, no token in the output**

**Given** the AJAX test runs
**When** it completes
**Then** it performs **no writes beyond the existing connection-settings autosave** already in `handle_test_connection_ajax()` (`class-skwirrel-wc-sync-admin-settings.php:590-630`) — no new options, no new transients, no logging change beyond what `call()` already does
**And** the auth token appears nowhere in the rendered output or the JSON response (NFR-4). The formatter receives only timing/status/count/error-message — never the token, never the request headers.

**6 — Rendering is safe, accessible and legible**

**Given** the result renders in `#skwirrel-test-result` (`role="status" aria-live="polite"`)
**When** the JS writes it
**Then** it builds DOM nodes with `createElement` + `textContent` — **no `innerHTML`**, because the payload carries an API-supplied error string
**And** the numeric values render with `font-variant-numeric: tabular-nums`
**And** the `aria-live` region announces the whole result, headline and metrics together.

**7 — All new strings are translatable**

**Given** every new user-facing string
**When** it is added
**Then** it is wrapped in `__()`/`esc_html__()` with the literal text domain `'skwirrel-pim-sync'`, English source, and numbers are formatted through `number_format_i18n()`.

**8 — The legacy non-AJAX path stays consistent**

**Given** `handle_test_connection()` (`admin_post_skwirrel_wc_sync_test`, `class-skwirrel-wc-sync-admin-settings.php:541-582`) — still registered, no longer reachable from the UI, but reachable by URL
**When** it runs
**Then** it stores and renders the **same** metric lines through the **same** formatter, so the two paths cannot drift. Do not delete this path in this story.

> **Follow-up, explicitly not in this story:** making the product count honour the configured `collection_ids` / `dynamic_selection_id` and the Context ID (Story 5.3). That is what would catch "connection fine, zero products sync" — recorded in the Chapter 2 out-of-scope list. Keep the test call exactly as unfiltered as it is today.

## Tasks / Subtasks

- [x] **Task 1 — Measure and expose transport metrics in the client** (AC: 1, 4, 5)
  - [x] In `Skwirrel_WC_Sync_JsonRpc_Client::call()` (`includes/class-skwirrel-wc-sync-jsonrpc-client.php:46`), capture `microtime(true)` at entry and compute elapsed ms at **every** return point (success, JSON-RPC error, HTTP >= 400, invalid JSON, retries-exhausted).
  - [x] Add an additive `meta` key to the returned array on **both** branches: `['duration_ms' => int, 'http_code' => int, 'attempts' => int]`. `http_code` is `0` when `is_wp_error()` (no HTTP response). `attempts` is the number of `wp_remote_post()` calls actually made.
  - [x] Update the `@return` docblock array shape on `call()` — PHPStan level 6 reads it; an undeclared key fails at the consumer.
  - [x] Do **not** change retry behaviour, headers, `sslverify`, or the existing logging calls.

- [x] **Task 2 — Return the product total from `test_connection()`** (AC: 1, 2)
  - [x] In `test_connection()` (`includes/class-skwirrel-wc-sync-jsonrpc-client.php:161`), keep the params exactly as they are (`limit => 1`, all includes off).
  - [x] Read `result['page']['number_of_items']`. Emit `product_count` as `int` when the value is numeric, and `null` when the key is absent/non-numeric.
  - [x] Return `product_count` alongside `success` / `result` / `error` / `meta`; declare it in the docblock shape.
  - [x] Never fall back to `count($result['result']['products'])`.

- [x] **Task 3 — Pure formatter for the result** (AC: 1–5, 7)
  - [x] Add `public static function format_test_result( array $meta, ?int $product_count, bool $success, string $error_message ): array` to `Skwirrel_WC_Sync_Admin_Settings`, returning `[ 'tone' => 'success'|'warning'|'error', 'message' => string, 'details' => string[] ]`.
  - [x] `tone` rules: `error` when `! $success`; `warning` when success and `0 === $product_count`; `success` otherwise (including `null` count).
  - [x] `details` lines: round-trip time (`number_format_i18n` + ms), status, product total (or "unavailable"), and attempts **only when `attempts > 1`**.
  - [x] Status wording: `0 === http_code` → "no response (transport error)"; `>= 400` → the HTTP code; otherwise "HTTP 200 · JSON-RPC OK" (or the JSON-RPC error code when one was returned).
  - [x] Keep it pure: no `get_option`, no `microtime`, no globals — it must be unit-testable on the stub bootstrap.
  - [x] `'tone'` values are **stable machine constants, not translated** (they map to CSS classes); only `message`/`details` are translated.

- [x] **Task 4 — Wire the AJAX handler** (AC: 1, 3, 4, 5)
  - [x] In `handle_test_connection_ajax()` (`includes/class-skwirrel-wc-sync-admin-settings.php:590`), pass the client result through `format_test_result()`.
  - [x] Send `message`, `details`, `tone` in the JSON payload. Zero products stays `wp_send_json_success()` (the call *did* succeed) — the warning is carried by `tone`, not by the success flag.
  - [x] Failure keeps `wp_send_json_error()` and the existing error message as `message`, now with `details` and `tone => 'error'`.
  - [x] Leave the autosave block (`update_option` × 2) exactly as it is. Add no other write.

- [x] **Task 5 — Render it** (AC: 3, 6, 7)
  - [x] In the inline script (`includes/class-skwirrel-wc-sync-admin-settings.php:1552-1575`), replace the single `res.textContent = txt` write with a renderer that clears the node, appends a headline `<span>` and one `<span class="skw-test-metric">` per detail, all via `createElement` + `textContent`.
  - [x] Drive the class from `r.data.tone` when present, falling back to today's `ok ? success : error` mapping so a stale cached script degrades safely.
  - [x] Keep the `skwirrelPimSync.testingLabel` / `testSubdomainLabel` / `testNetworkLabel` fallbacks intact; add any new label strings to the `wp_localize_script()` array (`:1456-1478`).
  - [x] In `assets/dashboard.css`, add `.skw-test-result.skw-test-warning` (amber, e.g. `#b45309`) next to the existing `.skw-test-success` / `.skw-test-error` block (`:405-417`), and a `.skw-test-metric` rule with `font-variant-numeric: tabular-nums`. Distinguish the warning tone by more than colour (weight/prefix glyph).

- [x] **Task 6 — Legacy path parity** (AC: 8)
  - [x] In `handle_test_connection()` (`:541`), store `tone` + `message` + `details` in the `TEST_RESULT_TRANSIENT` payload.
  - [x] In `maybe_show_notices()` (`:1891-1900`), render those lines in the notice, escaping each with `esc_html()`. Map `warning` tone to `notice-warning`.

- [x] **Task 7 — Tests** (AC: 1–5)
  - [x] New `tests/Unit/TestConnectionMetricsTest.php` (Pest) covering `format_test_result()`: success with a count, success with `0` (warning tone), success with `null` count (no fabricated number), transport error (`http_code 0`), HTTP 500, JSON-RPC error, and `attempts > 1` adding an attempts line.
  - [x] Assert the formatter output contains no token-like value when an error message is passed through verbatim (guards NFR-4 by construction — the formatter has no token input).
  - [x] Add any missing WP stubs (`number_format_i18n`, `_n`) to `tests/bootstrap.php` behind `if (!function_exists(...))`, matching the existing style.

- [x] **Task 8 — Ship** (project rules)
  - [x] Run all three gates from the **repo root**: `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs`. Fix findings; never regenerate `phpstan-baseline.neon` to hide new errors.
  - [x] New translatable strings → regenerate `languages/skwirrel-pim-sync.pot` and update all 7 locales (`.po` + `.mo`).
  - [x] Version bump + `CHANGELOG.md` + `readme.txt` are handled by `/release` — do not hand-edit version numbers.

## Dev Notes

### Why this story exists

Today the test proves *reachability* and nothing more: `handle_test_connection_ajax()` returns the fixed
string `Connection successful — settings saved.` A shop that is pointed at the right host with a valid
token but an empty selection gets a green tick and then syncs nothing. FR-21 makes the test report what
actually came back.

### The one hard fact that de-risks this story

The Skwirrel API source is vendored in this repo, so the response shape is **confirmed, not guessed**:

- `Models\Exchange\SkwirrelJson\Export\V2\ProductExporter\GetProductsExporter::getData()` returns
  `[ 'products' => [...], 'page' => $list->getPaginationData()->getAsArray() ]`
  — `skwirrel/app/app/Models/Exchange/SkwirrelJson/Export/V2/ProductExporter/GetProductsExporter.php`
- `PaginationData::getAsArray()` returns
  `[ 'number_of_items', 'items_per_page', 'number_of_pages', 'current_page' ]`
  — `skwirrel/app/lib/DataTypes/PaginationData.php:31-39`

So **`result.page.number_of_items` is the total product count**. The plugin has never read the `page`
block — `step_fetch()` only ever reads `result['result']['products']`
(`class-skwirrel-wc-sync-service.php:714`). This story is the first consumer. Read defensively anyway:
tenants run different API builds, hence AC 2.

### Current state of the files you will touch

**`includes/class-skwirrel-wc-sync-jsonrpc-client.php`**
- `call()` (`:46`) — builds the JSON-RPC envelope, retries `$this->retries` times with a
  `usleep(500000 * $attempt)` backoff, and returns `['success' => bool, 'result'?: mixed, 'error'?: array]`.
  Note the ordering quirk: `wp_remote_retrieve_response_code()` / `..._body()` are called *before* the
  `is_wp_error()` check — harmless (they return `''`), but it means `$code` is `''` on transport failure.
  Cast to `int` for `http_code`, giving `0`.
- Four distinct exits already exist (transport retry-exhaustion, invalid JSON, HTTP >= 400, JSON-RPC
  error, success). **Every one** must carry `meta`, or the failure branches render blank metrics.
- `test_connection()` (`:161`) — one `getProducts` call, `limit => 1`, every `include_*` off. Cheap by
  design. Keep it that way.

**`includes/class-skwirrel-wc-sync-admin-settings.php`**
- `handle_test_connection_ajax()` (`:590`) — nonce → capability → normalise endpoint → **autosave**
  (`update_option` on the token option and the settings option) → construct client → test → respond.
  The autosave is deliberate (the classic test tested *saved* settings and failed on freshly typed
  credentials). It is the **only** write allowed to remain.
- `handle_test_connection()` (`:541`) — legacy `admin_post` path. No UI element posts to it any more
  (nothing in the dashboard markup targets `skwirrel_wc_sync_test`), but the action is still registered
  at `:79`, so it is reachable by URL. Keep it, keep it consistent.
- Inline JS (`:1552-1575`) — `setRes(txt, cls)` does `res.textContent = txt` and swaps the class.
  Single-string by construction; the metrics need real child nodes.
- `wp_localize_script()` (`:1456-1478`) — where every JS-side string lives. New labels go here.

**`includes/class-skwirrel-wc-sync-admin-dashboard.php:838-841`** — the button and the
`<span id="skwirrel-test-result" class="skw-test-result" role="status" aria-live="polite">`.
The live region already exists; do not add a second one.

**`assets/dashboard.css:405-417`** — the `.skw-test-result` / `.skw-test-success` / `.skw-test-error`
block. `font-variant-numeric: tabular-nums` is already used three times in this file
(`:526`, `:609`, `:796`) — match that, don't invent a new numeric treatment.

### Do not

- **Do not** add a `total`/`count` API parameter, a second API call, or `include_*` flags to
  `test_connection()` to get the count. The pagination block is already in the response.
- **Do not** apply `collection_ids` / `dynamic_selection_id` / Context ID to the test call. That is the
  explicitly deferred follow-up.
- **Do not** use `innerHTML` for the result — the error string is API-supplied.
- **Do not** convert `success` to `false` for the zero-product case. The connection *did* succeed;
  the tone carries the warning.
- **Do not** translate the `tone` values — they map to CSS class names.
- **Do not** introduce a new logger, a new option, or a new transient key.
- **Do not** touch the sync path. This story is admin-surface + client response handling only, which is
  exactly why Epic 5 is described as carrying no sync-path risk.

### Testability seam

`wp_remote_post()` has no stub in `tests/bootstrap.php`, so the HTTP path is not unit-testable on the
stub bootstrap. That is why Task 3 puts every decision (tone selection, wording, unknown-count handling,
attempts line) in a **pure static formatter** that takes plain arrays — the client keeps only measurement.
Time is captured in the client and *passed into* the formatter, keeping the tested logic deterministic
(project rule: no nondeterminism in tested logic).

### Project Structure Notes

- No new class is introduced, so the "register in two places" rule (require in `skwirrel-pim-sync.php`
  **and** wire hooks in `Skwirrel_WC_Sync_Plugin`) does not apply here. If you find yourself wanting a
  new class, you have over-built this story.
- Only shippable code under `plugin/skwirrel-pim-sync/` is edited; the new test lives at repo-root
  `tests/Unit/`.
- Dev tooling runs from the **repo root** — the plugin directory has no `composer.json`.

### Testing

- Pest, not class-based PHPUnit: `test()`, `beforeEach()`, `expect()`, `dataset()`/`with()`.
  File name `TestConnectionMetricsTest.php`.
- `vendor/bin/pest` · `vendor/bin/phpstan analyse --memory-limit=2G` · `vendor/bin/phpcs` — all three
  green before commit. `vendor/bin/phpcbf` auto-fixes style.
- Manual check worth doing once in wp-env: a wrong token (expect an error tone with a status and a
  timing), and a valid token (expect a count matching the Skwirrel product list).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 5.4: Test Connection reports what actually came back]
- [Source: _bmad-output/planning-artifacts/epics.md#Epic 5: A settings screen you can navigate, trust, and verify] — FR-21, NFR-4, NFR-7
- [Source: _bmad-output/planning-artifacts/epics.md#Chapter 2 — Requirements Inventory] — FR-21 statement, deferred count-filtering follow-up
- [Source: _bmad-output/project-context.md#Critical Implementation Rules] — escaping, translatable strings, logging, gates
- [Source: .claude/rules/admin-settings.md] — settings storage, capability `manage_woocommerce`, page slug, token never exposed
- [Source: skwirrel/app/app/Models/Exchange/SkwirrelJson/Export/V2/ProductExporter/GetProductsExporter.php#getData] — response shape
- [Source: skwirrel/app/lib/DataTypes/PaginationData.php#getAsArray] — `number_of_items`

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code, bmad-dev-story workflow)

### Debug Log References

- `vendor/bin/pest` — 511 passed (1026 assertions), including the 16 new tests in `tests/Unit/TestConnectionMetricsTest.php`.
- `vendor/bin/phpstan analyse --memory-limit=2G` — no errors, level 6.
- `vendor/bin/phpcs` — clean (one alignment warning auto-fixed with `phpcbf`).
- The inline admin script was extracted and parsed with `node --check` to confirm the new DOM-building renderer is syntactically valid before shipping.

### Completion Notes List

**Client (Task 1, 2).** `call()` now captures `microtime(true)` at entry and attaches
`meta => [duration_ms, http_code, attempts]` at every one of its three return points (JSON-RPC error,
success, and the shared post-loop exit that serves retry-exhaustion, invalid JSON and HTTP >= 400) via a
private `build_meta()` helper. `$last_code` records the HTTP status as an int, so a transport failure —
where `wp_remote_retrieve_response_code()` returns `''` — lands on `0` and is reported as "no response"
rather than as the status `0`. Retry behaviour, headers, `sslverify` and every existing logging call are
untouched. `test_connection()` keeps its params exactly as they were and adds `product_count`, read from
`result.page.number_of_items` through a defensive `extract_product_count()` that yields `null` for an
absent or non-numeric value; it never falls back to `count(result.products)`.

**Formatter (Task 3).** `Skwirrel_WC_Sync_Admin_Settings::format_test_result()` is pure — no
`get_option`, no `microtime`, no globals — and takes only measurement, a count, a success flag and an
already-extracted error message; the shared payload preparation step removes an exact reflected credential
before calling it, and request headers never become presentation inputs. `tone` values
(`success`/`warning`/`error`) are untranslated machine constants that map to CSS classes. Status wording
lives in a small `describe_test_status()` helper and includes the JSON-RPC error code when one exists.

**Decision worth flagging:** the product line renders only on a *successful* call. On a failure there is
no pagination block to read, so a "Products: unavailable" line there would be noise rather than
information; AC 4 asks only for timing and status alongside the error message, and AC 2's "unavailable"
case is about a successful response missing the key. A test pins this ("a JSON-RPC rejection is
distinguishable from a transport failure" asserts no `Products` line on failure).

**Second decision:** the success headline is now `Connection successful.` rather than the old
`Connection successful — settings saved.`. AC 1 replaces that string anyway, and the same formatter feeds
the legacy `admin_post` path, which does not autosave — a shared "settings saved" claim would have been
false there. The autosave itself is unchanged.

**AJAX + legacy parity (Task 4, 6).** Both handlers now build their payload from the same
`format_test_result()` call, so the two paths cannot drift. Zero products stays `wp_send_json_success()` —
the call did succeed; the warning travels in `tone`. The autosave block in the AJAX handler is untouched
and no new option, transient key or logger was introduced. `maybe_show_notices()` maps the tone to
`notice-success`/`notice-warning`/`notice-error`, falls back to the old success flag when a transient
predates this change, and escapes every metric line with `esc_html()`.

**Rendering (Task 5).** `setRes()` builds a detached headline plus one `.skw-test-metric` span per detail
using `createElement` + `textContent` — no `innerHTML`, which matters because the headline can be an
API-supplied error string. It marks the explicit `aria-atomic="true"` status region busy while replacing its
contents with the completed fragment, so headline and metrics are announced together. The tone class comes
from the server when present and falls back to the old `ok ? success : error` mapping, so a browser holding
a stale cached script degrades safely. No new localized labels were needed. CSS adds
`.skw-test-warning` (amber, heavier weight, and a `⚠` glyph so the tone is not carried by colour alone —
NFR-7) and `.skw-test-metric` with `font-variant-numeric: tabular-nums`, matching the three existing uses
in this file.

**Tests (Task 7).** `tests/Unit/TestConnectionMetricsTest.php` now contains 41 tests covering the formatter,
pagination extraction, rendering/source contracts, translations, JSON-RPC error-code presentation and the
atomic live region. `tests/Integration/TestConnectionMetricsIntegrationTest.php` contains 16 real-WP tests
covering every client exit, AJAX payloads, writes, secret redaction and legacy notices. A canned-response
client subclass additionally pins that `test_connection()` still asks for `limit => 1` while reporting the
catalogue total. `number_format_i18n()` and `_n()` stubs were added to `tests/bootstrap.php` behind
`function_exists` guards.

**Baseline.** One `phpstan-baseline.neon` entry (`test_connection()` return type has no value type) was
*removed* because the new docblock shape resolves it. No entry was added.

**Not done here, by design:** the version bump, `CHANGELOG.md` and `readme.txt` are `/release`'s job. The
test call remains unfiltered — applying `collection_ids` / `dynamic_selection_id` / Context ID to the
count is the story's explicitly deferred follow-up.

### File List

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-jsonrpc-client.php` (modified)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php` (modified)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php` (modified by review)
- `plugin/skwirrel-pim-sync/assets/dashboard.css` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-nl_NL.po` / `.mo` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-nl_BE.po` / `.mo` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-de_DE.po` / `.mo` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-fr_FR.po` / `.mo` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-fr_BE.po` / `.mo` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-en_US.po` / `.mo` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-en_GB.po` / `.mo` (modified)
- `tests/Unit/TestConnectionMetricsTest.php` (new)
- `tests/Integration/TestConnectionMetricsIntegrationTest.php` (new)
- `tests/bootstrap.php` (modified)
- `phpstan-baseline.neon` (modified)
- `_bmad-output/implementation-artifacts/5-4-test-connection-metrics.md` (modified)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified)
- `_bmad-output/implementation-artifacts/tests/test-summary-5-4.md` (new QA artifact)

## Senior Developer Review (AI)

**Reviewer:** Codex · **Date:** 2026-08-26 · **Outcome:** Approve after automatic fixes

### Findings resolved

1. **HIGH — A reflected credential could leak through an API-controlled error message.** Both handlers
   passed `error.message` directly into their output payload. A shared secret-safe payload step now detects
   the exact credential used for the request and replaces a reflected message with the generic translated
   failure headline before either JSON or HTML rendering. An integration regression test covers this path.
2. **HIGH — JSON-RPC failures did not report the returned RPC error code.** The status line said only
   `JSON-RPC error`, despite the completed task requiring the code when available. The shared formatter now
   reports the localized HTTP status and JSON-RPC error code together.
3. **MEDIUM — Whole-result live-region announcement relied on inconsistent implicit behaviour.** The status
   region now declares `aria-atomic="true"`; the renderer builds a detached `DocumentFragment` and brackets
   replacement with `aria-busy`, so headline and metrics are committed as one complete status update.
4. **MEDIUM — Integration-test teardown removed other code's filters.** The new test file used
   `remove_all_filters()` for shared WordPress hooks, which could make later integration tests order-dependent.
   It now tracks and removes only the callbacks it installed.
5. **MEDIUM — Story evidence did not match Git.** The QA-added integration suite and test summary were absent
   from the File List, the dashboard became part of the review fix, and the recorded test totals were stale.
   The File List and test notes now match the working tree.

### Acceptance-criteria and task audit

- AC 1–8: **implemented after fixes**. All completed tasks were verified against the implementation and tests.
- No separate Epic 5 tech-spec artifact exists; the authoritative Epic 5/Story 5.4 section in `epics.md`,
  `architecture.md`, project context and admin-settings rules were used instead.
- Reference checks used the official WordPress code references for `wp_send_json_error()`,
  `number_format_i18n()` and HTTP response codes, plus W3C ARIA22 guidance recommending explicit
  `aria-atomic="true"` when the whole status must be announced.

### Validation

- `vendor/bin/pest` — **536 passed (1150 assertions)**.
- `vendor/bin/phpstan analyse --debug --memory-limit=2G` — **no errors**, all 34 source files analysed.
  The exact parallel command was also attempted, but this managed sandbox forbids PHPStan's local TCP
  worker socket (`EPERM`); `--debug` runs the same analysis serially without that socket.
- `vendor/bin/phpcs` — **clean**, 34/34 files.
- PO format checks and MO recompilation — **all 7 locales clean and compiled**.
- `npm run test:integration -- --filter=TestConnectionMetricsIntegrationTest` — not rerun in this sandbox
  because access to the local OrbStack/Docker socket is denied. The new test file passes PHP syntax and PHPCS;
  the preceding QA pass recorded 177 integration tests passing with one unrelated Story 5.3 failure.

**Issues fixed:** 5 · **Action items:** 0 · **Critical issues remaining:** 0

## Change Log

- 2026-08-26 — Senior Developer Review (AI): auto-fixed five findings covering reflected-token redaction,
  JSON-RPC error-code reporting, atomic status announcements, test-hook isolation and story/Git traceability.
  Added review regressions, updated all locale catalogs, and approved the story as done.

- 2026-08-26 — Story 5.4 implemented. The connection test now reports round-trip time, HTTP/JSON-RPC
  status and the API-reported product total instead of a bare success string. Transport measurement was
  added to `JsonRpc_Client::call()` on every return path; `test_connection()` reads the pagination total
  defensively and never substitutes the size of the returned array. All presentation decisions moved into
  one pure `format_test_result()` formatter shared by the AJAX and legacy `admin_post` paths, so the two
  cannot drift. Zero products renders in a distinct warning tone (glyph + weight + colour, NFR-7) while
  staying a success response. The inline renderer builds DOM nodes rather than assigning `innerHTML`,
  because the headline can carry an API-supplied error string. Unit and integration coverage added; all three
  repository gates green (PHPStan serial fallback in the managed sandbox); translations updated for all 7 locales.
