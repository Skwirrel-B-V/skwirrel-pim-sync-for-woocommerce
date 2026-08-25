---
status: ready-for-dev
baseline_revision: 0f7c3c4964b7789f74d7c14f307c1c083a9a22a4
context:
  - _bmad-output/project-context.md
  - _bmad-output/planning-artifacts/epics.md
  - CLAUDE.md
  - .claude/rules/admin-settings.md
---

# Story 5.4: Test Connection reports what actually came back

Status: ready-for-dev

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

- [ ] **Task 1 — Measure and expose transport metrics in the client** (AC: 1, 4, 5)
  - [ ] In `Skwirrel_WC_Sync_JsonRpc_Client::call()` (`includes/class-skwirrel-wc-sync-jsonrpc-client.php:46`), capture `microtime(true)` at entry and compute elapsed ms at **every** return point (success, JSON-RPC error, HTTP >= 400, invalid JSON, retries-exhausted).
  - [ ] Add an additive `meta` key to the returned array on **both** branches: `['duration_ms' => int, 'http_code' => int, 'attempts' => int]`. `http_code` is `0` when `is_wp_error()` (no HTTP response). `attempts` is the number of `wp_remote_post()` calls actually made.
  - [ ] Update the `@return` docblock array shape on `call()` — PHPStan level 6 reads it; an undeclared key fails at the consumer.
  - [ ] Do **not** change retry behaviour, headers, `sslverify`, or the existing logging calls.

- [ ] **Task 2 — Return the product total from `test_connection()`** (AC: 1, 2)
  - [ ] In `test_connection()` (`includes/class-skwirrel-wc-sync-jsonrpc-client.php:161`), keep the params exactly as they are (`limit => 1`, all includes off).
  - [ ] Read `result['page']['number_of_items']`. Emit `product_count` as `int` when the value is numeric, and `null` when the key is absent/non-numeric.
  - [ ] Return `product_count` alongside `success` / `result` / `error` / `meta`; declare it in the docblock shape.
  - [ ] Never fall back to `count($result['result']['products'])`.

- [ ] **Task 3 — Pure formatter for the result** (AC: 1–5, 7)
  - [ ] Add `public static function format_test_result( array $meta, ?int $product_count, bool $success, string $error_message ): array` to `Skwirrel_WC_Sync_Admin_Settings`, returning `[ 'tone' => 'success'|'warning'|'error', 'message' => string, 'details' => string[] ]`.
  - [ ] `tone` rules: `error` when `! $success`; `warning` when success and `0 === $product_count`; `success` otherwise (including `null` count).
  - [ ] `details` lines: round-trip time (`number_format_i18n` + ms), status, product total (or "unavailable"), and attempts **only when `attempts > 1`**.
  - [ ] Status wording: `0 === http_code` → "no response (transport error)"; `>= 400` → the HTTP code; otherwise "HTTP 200 · JSON-RPC OK" (or the JSON-RPC error code when one was returned).
  - [ ] Keep it pure: no `get_option`, no `microtime`, no globals — it must be unit-testable on the stub bootstrap.
  - [ ] `'tone'` values are **stable machine constants, not translated** (they map to CSS classes); only `message`/`details` are translated.

- [ ] **Task 4 — Wire the AJAX handler** (AC: 1, 3, 4, 5)
  - [ ] In `handle_test_connection_ajax()` (`includes/class-skwirrel-wc-sync-admin-settings.php:590`), pass the client result through `format_test_result()`.
  - [ ] Send `message`, `details`, `tone` in the JSON payload. Zero products stays `wp_send_json_success()` (the call *did* succeed) — the warning is carried by `tone`, not by the success flag.
  - [ ] Failure keeps `wp_send_json_error()` and the existing error message as `message`, now with `details` and `tone => 'error'`.
  - [ ] Leave the autosave block (`update_option` × 2) exactly as it is. Add no other write.

- [ ] **Task 5 — Render it** (AC: 3, 6, 7)
  - [ ] In the inline script (`includes/class-skwirrel-wc-sync-admin-settings.php:1552-1575`), replace the single `res.textContent = txt` write with a renderer that clears the node, appends a headline `<span>` and one `<span class="skw-test-metric">` per detail, all via `createElement` + `textContent`.
  - [ ] Drive the class from `r.data.tone` when present, falling back to today's `ok ? success : error` mapping so a stale cached script degrades safely.
  - [ ] Keep the `skwirrelPimSync.testingLabel` / `testSubdomainLabel` / `testNetworkLabel` fallbacks intact; add any new label strings to the `wp_localize_script()` array (`:1456-1478`).
  - [ ] In `assets/dashboard.css`, add `.skw-test-result.skw-test-warning` (amber, e.g. `#b45309`) next to the existing `.skw-test-success` / `.skw-test-error` block (`:405-417`), and a `.skw-test-metric` rule with `font-variant-numeric: tabular-nums`. Distinguish the warning tone by more than colour (weight/prefix glyph).

- [ ] **Task 6 — Legacy path parity** (AC: 8)
  - [ ] In `handle_test_connection()` (`:541`), store `tone` + `message` + `details` in the `TEST_RESULT_TRANSIENT` payload.
  - [ ] In `maybe_show_notices()` (`:1891-1900`), render those lines in the notice, escaping each with `esc_html()`. Map `warning` tone to `notice-warning`.

- [ ] **Task 7 — Tests** (AC: 1–5)
  - [ ] New `tests/Unit/TestConnectionMetricsTest.php` (Pest) covering `format_test_result()`: success with a count, success with `0` (warning tone), success with `null` count (no fabricated number), transport error (`http_code 0`), HTTP 500, JSON-RPC error, and `attempts > 1` adding an attempts line.
  - [ ] Assert the formatter output contains no token-like value when an error message is passed through verbatim (guards NFR-4 by construction — the formatter has no token input).
  - [ ] Add any missing WP stubs (`number_format_i18n`, `_n`) to `tests/bootstrap.php` behind `if (!function_exists(...))`, matching the existing style.

- [ ] **Task 8 — Ship** (project rules)
  - [ ] Run all three gates from the **repo root**: `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs`. Fix findings; never regenerate `phpstan-baseline.neon` to hide new errors.
  - [ ] New translatable strings → regenerate `languages/skwirrel-pim-sync.pot` and update all 7 locales (`.po` + `.mo`).
  - [ ] Version bump + `CHANGELOG.md` + `readme.txt` are handled by `/release` — do not hand-edit version numbers.

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

### Debug Log References

### Completion Notes List

### File List

## Change Log
