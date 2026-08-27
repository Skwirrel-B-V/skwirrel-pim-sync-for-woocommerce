---
status: backlog
baseline_commit: db22f7689656a755035ba04ac25bd4b3117eabd6
baseline_revision: 0d41b0d64cbe8b882027043c2ec2bbd27bae2a8f
context:
  - _bmad-output/implementation-artifacts/5-4-test-connection-metrics.md
  - _bmad-output/project-context.md
  - _bmad-output/planning-artifacts/epics.md
  - CLAUDE.md
  - .claude/rules/admin-settings.md
---

# Story 7.1: Test Connection tests *your* settings, not just the server

Status: backlog

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner about to press "Sync now" for the first time,
I want the connection test to tell me how many products *my* configured selection and context actually
return,
so that I have permission to press the real button instead of guessing whether an empty shop is coming.

## Origin

Story 5.4 closed with this note, and this story is exactly what it names:

> **Follow-up, explicitly not in this story:** making the product count honour the configured
> `collection_ids` / `dynamic_selection_id` and the Context ID (Story 5.3). That is what would catch
> "connection fine, zero products sync" — recorded in the Chapter 2 out-of-scope list. Keep the test
> call exactly as unfiltered as it is today.
> — `_bmad-output/implementation-artifacts/5-4-test-connection-metrics.md:80`

**Jos's ruling, binding on this story:** Test Connection grows *toward* a dry run. This is a deliberate
stepping stone to a future full dry-run feature. Removing the filtered probe, or reverting Test
Connection to a pure reachability check, is off the table. No acceptance criterion below hedges on that.

## Acceptance Criteria

**1 — The unfiltered probe stays the gate; the scoped probe never overrides it**

**Given** `Skwirrel_WC_Sync_JsonRpc_Client::test_connection()`
(`plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-jsonrpc-client.php:198`)
**When** it runs
**Then** its top-level keys keep their present meaning and shape exactly — `success`, `error`, `result`,
`meta`, `product_count` — so `Skwirrel_WC_Sync_Admin_Settings` and the existing Pest suites
(`tests/Unit/TestConnectionMetricsTest.php`, `tests/Integration/TestConnectionMetricsIntegrationTest.php`)
need no rewrite
**And** exactly one key is added: `scoped`, which is either `null` (no filters configured) or
`['success' => bool, 'error' => array|null, 'product_count' => int|null, 'filters' => array]`
**And** a failing `scoped` **never** flips top-level `success`. Two verdicts, one authoritative: the
unfiltered probe is the gate.

**2 — The scoped probe runs only after the gate passes**

**Given** an unfiltered probe that failed (transport error, HTTP >= 400, or a JSON-RPC `error` object)
**When** `test_connection()` returns
**Then** no scoped request was issued at all, and `scoped` is present with
`success => false, product_count => null` and an error marker meaning *not tested*, so the renderer can
say "Not tested — connection required first" rather than inventing a second failure (AC 7).

**3 — The scoped probe asks the API the same question the sync will ask**

**Given** a configured selection (`collection_ids`) and/or a configured Context ID (`context_id`)
**When** the scoped probe is built
**Then** the filters are resolved through the **existing** helpers — `resolve_context_ids()` /
`get_context_ids()` (`class-skwirrel-wc-sync-admin-settings.php:612` and `:646`) and the same
positive-integer parse the sync uses for `collection_ids`
(`Skwirrel_WC_Sync_Service::get_collection_ids()`, `class-skwirrel-wc-sync-service.php:2504`) — never a
second parser
**And** the request carries `limit => 1`, `page => 1` and every `include_*` flag off, exactly like the
unfiltered probe: this reads a total, it does not fetch payload
**And** it carries **no** `updated_on` filter. Delta semantics are not part of a connection test.

**4 — Payload assembly lives behind one seam**

**Given** the client now issues two shapes of probe
**When** the params are built
**Then** both go through one private method on the client,
`build_probe_params( array $filters = [] ): array`, which is the only place probe params are assembled
**And** the `@return` docblock array shape on `test_connection()` is updated to declare `scoped`
(PHPStan level 6 reads it; an undeclared key fails at the consumer)
**And** no existing caller of `call()` is rerouted through the new seam in this story.

**5 — No number the panel shows may have more than one cause**

**Given** any figure rendered in the result panel
**When** it renders
**Then** it carries exactly one possible reading. Specifically: a scoped total of `0` renders as
"no products match your filter" and is only ever reached from a **successful** scoped call; an
**unreachable** server renders as "not tested"; an **unauthorised** or rejected scoped call renders as
its own rejection line; and an API build that reports **no** pagination total renders as
"product count not reported by this server" — **never** as `0`, and never as an empty string
**And** `product_count` in `scoped` is `null` in every one of those non-count cases, reusing the same
defensive read as `extract_product_count()`
(`class-skwirrel-wc-sync-jsonrpc-client.php`, `result.page.number_of_items`, numeric or `null`).
A result that can mean two things is not a result.

**6 — One panel, two rows, the same skeleton every time**

**Given** the result region `#skwirrel-test-result`
(`class-skwirrel-wc-sync-admin-dashboard.php:838-841`, `role="status" aria-live="polite"
aria-atomic="true"`)
**When** any outcome renders
**Then** the panel always has the same two-row skeleton — **Row 1 Connection** and **Row 2 Your
settings** — with Row 2 indented and using the same visual grammar as Row 1, so the panel can later grow
a third row for a real dry run without a redesign
**And** Row 1 reads like `Connected to Skwirrel · 412 ms` and Row 2 like
`Collection 12, context 3 → 1,204 products match.`
**And** the Row 1 detail lines keep their existing order (round-trip, then status, then products, then
attempts when > 1) — pinned by `tests/Unit/TestConnectionMetricsTest.php:311`.

**7 — Tone rules: the filter is never louder than the connection**

**Given** the two rows
**When** they render
**Then**:
- Filter matched nothing → Row 1 stays **green**, Row 2 is **amber**, never red:
  "Connected, but no products match your filter."
- Server unreachable / gate failed → Row 1 **red**, Row 2 rendered **greyed** as
  "Not tested — connection required first." **Never two red rows for one problem.**
- Count unavailable → Row 2 is **green**: "Filter accepted · product count not reported by this server."
  The absence of a number is not a warning.
- No filters configured at all → Row 2 states plainly that nothing is filtering the sync yet, in the
  neutral/greyed treatment. It is not a warning either.
**And** every tone is distinguished by more than colour (glyph and/or weight — NFR-7), matching the
existing `.skw-test-warning` treatment in `assets/dashboard.css`.

**8 — An amber Row 2 names the next step and where to take it**

**Given** the amber "no products match your filter" state
**When** it renders
**Then** it is followed by a one-line next step that names the tab by its actual label, e.g.
"Check Selection IDs on the What to sync tab" — the tab slug `what-to-sync` / label `What to sync`
registered in `Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs()` (`:806-810`), which is where
`collection_ids` lives. A label alone is not enough; this must be a pointer.

**9 — The raw technical string is never above the fold**

**Given** a failing test of either row
**When** the panel renders
**Then** the plain-language verdict is **on top** and the raw transport string (e.g.
`cURL error 28: Operation timed out after 30001 milliseconds`) is **below**, inside a native
`<details><summary>Technical details (for support)</summary>` element — zero JS, collapsed by default
**And** that block holds the verbatim error, the endpoint, the HTTP status and a timestamp in a `<pre>`
with `user-select: all`
**And** the "(for support)" phrasing is kept verbatim in the English source: it tells the admin this is
not for them. Collapsed or absent — rendering it in smaller type does not satisfy this AC.
**And** this closes the **gh#47 display fix** ruled on separately: it is a presentation change only.
`format_test_result()` / `prepare_test_result_payload()` keep passing the API error text through
verbatim (minus the existing reflected-credential redaction) — escaping is the renderer's job and
support needs the raw string.

**10 — Rendering stays DOM-built, never `innerHTML`**

**Given** the payload carries API-supplied error strings
**When** the inline renderer writes the panel (`setRes()`,
`class-skwirrel-wc-sync-admin-settings.php:1885-1908`)
**Then** every node — both rows, the `<details>`, the `<summary>` and the `<pre>` — is built with
`createElement` + `textContent`, with **no `innerHTML` anywhere**, pinned by the existing test
`tests/Unit/TestConnectionMetricsTest.php:376`
**And** the whole panel is still committed in one pass inside the `aria-busy` bracket, so the live
region announces both rows as one status update
**And** a browser holding a stale cached script still degrades to the current single-row rendering.

**11 — Scoped values are read from saved settings, and the panel says so**

**Given** the AJAX handler `handle_test_connection_ajax()`
(`class-skwirrel-wc-sync-admin-settings.php:902`) autosaves **only** `endpoint_url`, `auth_type` and the
token, and reads everything else from `skwirrel_wc_sync_settings`
**When** the scoped probe resolves its filters
**Then** it reads the **saved** `collection_ids` / `context_id`, and the panel's Row 2 states the filter
values it actually used (e.g. "Collection 12, context 3"), so an admin who typed a new Selection ID
without saving can see the test did not use it
**And** the handler adds **no** new write: the autosave block stays exactly as it is (AC 5 of Story 5.4,
pinned by `tests/Integration/TestConnectionMetricsIntegrationTest.php:442`).

**12 — Secrets, strings and gates**

**Given** everything added here
**When** it ships
**Then** the auth token appears nowhere in the payload, the panel or the `<details>` block — the
existing reflected-credential redaction in `prepare_test_result_payload()` (`:833`) covers the scoped
error message too
**And** every new user-facing string is wrapped in `__()`/`esc_html__()` with the literal text domain
`'skwirrel-pim-sync'`, English source, numbers through `number_format_i18n()`
**And** `tone` values stay untranslated machine constants that map to CSS classes
**And** both test paths — AJAX and the legacy `admin_post` path `handle_test_connection()` (`:849`) —
render the scoped row through the **same** formatter, so they cannot drift. Do not delete the legacy path.

## Out of scope — state it, do not creep into it

Two HTTP calls (three at most; see the multi-selection note below), synchronous return. Explicitly **not**
in this story:

- **No pagination.** The probe reads a total, it never walks pages.
- **No per-product create/update classification.** That is the dry run, not this.
- **No caching** of either probe result.
- **No new settings** and no new option, transient or meta key.
- **No Action Scheduler job**, no background work, no loopback.
- **No history entries** — this writes nothing to `skwirrel_wc_sync_history`.
- **No change to the sync path.** Admin surface plus client response handling only.
- **No "would be trashed" number.** See the open questions.

## Design note — the future dry run (record, do not build)

The direction this is a stepping stone toward, so a later story does not have to re-derive it:

- A `Skwirrel_WC_Sync_Dry_Run` class in
  `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-dry-run.php` (WPCS file-naming rule;
  requires registration in **two** places — the `require_once` in `skwirrel-pim-sync.php` **and** hook
  wiring in `Skwirrel_WC_Sync_Plugin`).
- It walks pages through the same client and the same `build_probe_params()` seam introduced here.
- It classifies create-vs-update through `Skwirrel_WC_Sync_Product_Lookup`, and **never** touches
  `Skwirrel_WC_Sync_Product_Upserter`. The seam that matters is **lookup without upsert**.
- Test Connection is the **zero-page case** of that walk. This story exists to make that literally true.

**The parity rule that governs the follow-up:** the danger is not drift in the *fetch* layer — it is
drift in the *decision* layer. Re-implementing "would this be created or updated?" in a second place is
where the lie enters. Any dry run must call the same lookup the sync calls, or the forecast it shows is
fiction. Note that Epic 2's `2-2-preflight-before-sync` targets the same decision layer from the sync
screen; whichever lands second must consume the first's classifier, not grow its own.

## Placement — why this is Epic 7 and not 5 or 6

Stated plainly rather than forced into an existing epic:

- **Not Epic 5.** Epic 5 is `done` and its retrospective is `done` (`epic-5-retro-2026-08-26.md`).
  Reopening a closed, retro'd epic to append its own deferred follow-up rewrites a settled record.
- **Not Epic 6.** Epic 6 is `in-progress` but its theme is stock and product content from Skwirrel data.
  This story touches neither. Filing it there would make the epic's name a lie.
- **Not Epic 2.** `2-2-preflight-before-sync` is the closest relative, but it is a change-set preview on
  the sync screen and it sits behind `1-3-resolver-and-change-set`, which is still `backlog`. This story
  must not inherit that block.
- **New Epic 7 — "Know before you press: from Test Connection to a dry run."** This is story 7.1, the
  first and smallest step of that arc, and it gives the eventual full dry run somewhere to live. If a
  later correct-course decides the dry run belongs under Epic 2 after all, Epic 7 folds into it cheaply —
  it has one story.

## Open questions — unanswered, do not invent answers

1. **Which moment is the real one?** Onboarding, after-a-settings-change, or the morning after a bad
   sync? The three want different panels — an onboarding panel wants reassurance, a morning-after panel
   wants a diff. Unanswered.
2. **Is "would be trashed" the number Jos actually wants** from the eventual full dry run? It is the
   scariest number and the most expensive to compute honestly. Unanswered.
3. **Has anyone in the wild actually hit** "my filter returned nothing and I couldn't tell"? The whole
   story rests on that failure being real rather than imagined. Unanswered.

## Dev Notes

### Contradictions between the roundtable design and the code as it stands — read before you build

These were found while writing this story. **Do not paper over them; AC 3 is written to the code, and
the deviation is deliberate.**

1. **`collection_ids` is not a `getProducts` parameter, and never has been.** The roundtable framed the
   scoped probe as "a second `getProducts` … plus `collection_ids` and `include_contexts`". The plugin
   never sends `collection_ids` to the API. The sync sends **one call per selection ID** as
   `getProductsByFilter` with `filter => [ 'dynamic_selection_id' => (int) $id ]`
   (`class-skwirrel-wc-sync-service.php:710-713` → `fetch_products_page()` at `:2352-2382`), and
   `dynamic_selection_id` is a **single-int** filter — `class-skwirrel-wc-sync-product-upserter.php:980`
   says so explicitly. A `getProducts` probe therefore **cannot** answer "how many products does my
   selection return".
   **Resolution taken here:** the scoped probe uses `getProductsByFilter` with `dynamic_selection_id`
   only — no `updated_on`, no `options` payload — which is precisely the shape the sweep already uses and
   which `class-skwirrel-wc-sync-service.php:579` documents. The architect's objection to
   `getProductsByFilter` was that it "drags `updated_on` semantics in"; omitting `updated_on` removes
   that objection, and using `getProducts` instead would answer the wrong question. **Flag this to
   Winston before starting** — it is a deviation from the literal instruction in service of its intent.
2. **Consequently, N configured selections mean N scoped calls.** The "two HTTP calls" figure holds only
   for a single selection ID. Cap the scoped probe at a small, hard-coded number of selection IDs
   (suggest 5) and, above the cap, report the filter as accepted without a summed total rather than
   firing an unbounded number of requests from an admin click. Do **not** add a setting for the cap.
3. **`extract_product_count()` is `private static`** on the client
   (`class-skwirrel-wc-sync-jsonrpc-client.php`, end of file). It is already the right helper for the
   scoped total — reuse it in place; do not duplicate it and do not widen its visibility without need.
4. **`context_id` is validated but `resolve_context_ids()` is `private`;** `get_context_ids()` is the
   `public static` accessor and reads `skwirrel_wc_sync_settings` itself. The client has no access to
   settings by design, so the **caller** (`handle_test_connection_ajax()` /
   `handle_test_connection()`) must resolve the filters and hand them to `test_connection()`. Do not
   make the client read options.
5. **The current renderer emits a flat list of `.skw-test-metric` spans** (`:1888-1908`). The two-row
   skeleton of AC 6 is a real structural change to `setRes()`. Keep the existing detail ordering intact
   (AC 6) so `tests/Unit/TestConnectionMetricsTest.php:311` still passes.

### Tests you must not contradict

- `tests/Unit/TestConnectionMetricsTest.php:253` — *"a failed call reports no product total at all."*
  The scoped row is a **separate** row; do not satisfy AC 7's "not tested" state by adding a Products
  line to the failed Row 1.
- `:143` — tone values are stable machine constants, not translated.
- `:376` — the renderer builds DOM nodes and never assigns `innerHTML`.
- `:389` — the live region announces each complete result atomically.
- `:424` — both test paths use one shared secret-safe formatter pipeline.
- `tests/Integration/TestConnectionMetricsIntegrationTest.php:442` — the test writes nothing beyond the
  settings it already autosaved.

### Testing

- Pest, not class-based PHPUnit: `test()`, `beforeEach()`, `expect()`, `dataset()`/`with()`.
- Suggested new unit file `tests/Unit/TestConnectionScopedProbeTest.php`, covering: `scoped => null` when
  nothing is configured; scoped success with a total; scoped zero (amber, Row 1 still green); scoped
  count unavailable (green, no `0`); gate failed → scoped "not tested" and **no** second request issued;
  filter values echoed in Row 2; and the selection cap.
- Integration additions belong beside the existing suite in
  `tests/Integration/TestConnectionMetricsIntegrationTest.php` — reuse its canned-response client
  subclass and its callback-tracking teardown (do **not** use `remove_all_filters()`; that was a review
  finding on 5.4).
- All three gates from the **repo root** before commit: `vendor/bin/pest`,
  `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs`. Never regenerate
  `phpstan-baseline.neon` to hide a new error.
- New translatable strings → regenerate `languages/skwirrel-pim-sync.pot` and update all 7 locales
  (`.po` + `.mo`). Version bump / `CHANGELOG.md` / `readme.txt` are `/release`'s job — do not hand-edit.

### References

- [Source: _bmad-output/implementation-artifacts/5-4-test-connection-metrics.md:80] — the deferred
  follow-up this story implements
- [Source: _bmad-output/implementation-artifacts/5-4-test-connection-metrics.md#Do not] — the "do not
  apply collection_ids / dynamic_selection_id / Context ID to the test call" line that this story lifts
- [Source: _bmad-output/planning-artifacts/epics.md#Epic 5] — FR-21, NFR-4, NFR-7
- [Source: _bmad-output/project-context.md#Critical Implementation Rules] — escaping, translatable
  strings, class registration in two places, gates
- [Source: .claude/rules/admin-settings.md] — settings storage, `manage_woocommerce`, token never exposed
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-jsonrpc-client.php:198] —
  `test_connection()` and `extract_product_count()`
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php:612,646,724,833,902]
  — `resolve_context_ids()`, `get_context_ids()`, `format_test_result()`,
  `prepare_test_result_payload()`, `handle_test_connection_ajax()`
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:710,2352,2504] — how the
  sync actually applies a selection

## Change Log

- 2026-08-27 — Story drafted (John / PM) from the Test Connection → dry run roundtable. Encodes Winston's
  additive `scoped` key and `build_probe_params()` seam, Sally's two-row panel with its amber/greyed tone
  rules and collapsed "Technical details (for support)" block, the gh#47 verdict-on-top display fix, and
  the one-cause-per-number constraint. Placed in a new Epic 7 rather than reopening the closed Epic 5.
