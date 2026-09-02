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

**Landed early (3.14.0 code review, PR #53):** the *Context ID* half of that note is already done. The
unfiltered gate now sends `include_contexts` when a Context ID is configured, because a probe that reads
the default context's total while every sync targets another one reports a number about the wrong
dataset — see `Skwirrel_WC_Sync_JsonRpc_Client::test_connection( ?array $context_ids )`. This is exactly
what AC 4's `build_probe_block()` specifies for the shared block, so this story absorbs it rather than
re-deriving it: fold the existing parameter into the seam. **Selection filtering (`collection_ids` /
`dynamic_selection_id`) is untouched and remains wholly this story's work.**

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
**And** exactly one key is added: `scoped`, which is either `null` (the gate is all there is to report)
or a **per-selection** structure carrying no summed total anywhere:

```
scoped => [
  'mode'       => 'filter' | 'blocked',
  'contexts'   => array<int, int>|null,   // from get_context_ids()
  'selections' => [                        // ordered as configured; empty when mode is 'blocked'
    [
      'selection_id'  => int,
      'state'         => 'matched' | 'empty' | 'unavailable' | 'rejected' | 'not_tested',
      'product_count' => int|null,         // int only when state is 'matched'
      'error'         => array|null,       // set only when state is 'rejected'
    ],
    ...
  ],
]
```

**And** `scoped` has **no top-level `product_count`**. There is deliberately no scalar to read, because
summing per-selection totals double-counts any product that belongs to two selections — see AC 5 and the
Dev Notes. A caller that wants one number must be unable to get one
**And** a failing `scoped` **never** flips top-level `success`. Two verdicts, one authoritative: the
unfiltered probe is the gate.

**2 — The scoped probe runs only after the gate passes**

**Given** an unfiltered probe that failed (transport error, HTTP >= 400, or a JSON-RPC `error` object)
**When** `test_connection()` returns
**Then** no scoped request was issued at all, and `scoped` is present with every configured selection
carrying `state => 'not_tested'` and `product_count => null`, so the renderer can say
"Not tested — connection required first" rather than inventing a second failure (AC 7).
**And** `'not_tested'` is the **single shared vocabulary** for "we did not ask": it covers the failed
gate here and budget exhaustion in AC 13. One word, one meaning, two causes that the row states
explicitly.

**3 — The scoped probe asks the API the same question the sync will ask**

**Given** a configured selection (`collection_ids`) and/or a configured Context ID (`context_id`)
**When** the scoped probe is built
**Then** the request is `getProductsByFilter` with `filter => [ 'dynamic_selection_id' => (int) $id ]`,
one call per configured selection ID — the shape the sync itself uses
(`class-skwirrel-wc-sync-service.php:710` → `fetch_products_page()` `:2352-2382`). See Dev Notes: the
API has no `collection_ids` parameter, so a `getProducts` probe cannot answer this question.
**And** the context filter is resolved through the **existing** `get_context_ids()`
(`class-skwirrel-wc-sync-admin-settings.php:646`, `public static`, already reads the option) — never a
second context parser
**And** the selection string is parsed by **one** helper: this story extracts the existing inline parse
in `sanitize_settings()` (`class-skwirrel-wc-sync-admin-settings.php:491-497`) into
`public static function parse_selection_ids( mixed $raw ): array<int, int>` on
`Skwirrel_WC_Sync_Admin_Settings`, routes the sanitiser through it (a behaviour-identical extraction,
not a rewrite), and calls it from the scoped probe. See the **Ruling on shared parsing** below for what
this deliberately does *not* touch.
**And** the request carries `limit => 1`, `page => 1`, `options => []` and every `include_*` flag off,
exactly like the unfiltered probe: this reads a total, it does not fetch payload
**And** it carries **no** `updated_on` filter. Delta semantics are not part of a connection test.
**And** the probe **mirrors whichever branch the configuration actually takes** — it never defaults to
the filter branch. Verified against the code, that resolves to two reachable cases, not three:
- **Selections configured** → `mode => 'filter'`, one `getProductsByFilter` probe per selection ID.
- **No selections configured** → `mode => 'blocked'`, and **no scoped probe is issued at all**, because
  the sync would not run either: `collection_ids` is in `unconditional_required_fields()`
  (`class-skwirrel-wc-sync-admin-settings.php:325-326`) and `run_sync()` hard-fails at
  `class-skwirrel-wc-sync-service.php:242-245` with "No selection IDs configured. A selection ID is
  required." Row 2 must say exactly that in plain language — *a sync would not start* — which is far
  more useful than a product count.
- A context-configured-but-no-selections install falls into `'blocked'` like any other. There is no
  scoped `getProducts` branch to mirror: `fetch_products_page()` is only ever called with
  `$use_filter = true` (`class-skwirrel-wc-sync-service.php:713`, the sole call site), so its
  `getProducts` branch (`:2372-2382`) is dead code in the sync path today. **Do not build a probe path
  for it.**

**4 — Payload assembly lives behind one seam**

**Given** the client issues two shapes of probe — the unfiltered `getProducts` gate (AC 1) and the
scoped `getProductsByFilter` calls (AC 3) — which share a read block but not an envelope
**When** the params are built
**Then** the genuinely shared part is built by **one** private method that returns only that block:
`build_probe_block(): array` — `page => 1`, `limit => 1`, every `include_*` off, plus
`include_contexts` when a context is configured. It takes no mode argument.
**And** the envelope choice is expressed with the **same `$use_filter` shape the sync already uses**,
`fetch_products_page( $client, bool $use_filter, array $filter, ... )`
(`class-skwirrel-wc-sync-service.php:2352-2382`) — an explicit boolean, not an inferred one.
**Explicitly rejected:** a single `build_probe_params( array $filters = [] )` that switches envelope on
whether `$filters` is empty. That is a boolean flag wearing a costume — the caller cannot see that it is
choosing a JSON-RPC method, and an accidentally-empty filter array silently becomes a different API call.
Convergence with the sync comes from matching its shape, not from hiding the switch
**And** the `@return` docblock array shape on `test_connection()` is updated to declare `scoped`
(PHPStan level 6 reads it; an undeclared key fails at the consumer)
**And** no existing caller of `call()` is rerouted through the new seam in this story.

**5 — No number the panel shows may have more than one cause**

**Given** any figure rendered in the result panel
**When** it renders
**Then** it carries exactly one possible reading, and it is **attributed to exactly one selection**.
Each configured selection renders its own figure with its own state:
- `matched` → the API-reported total for **that** selection.
- `empty` → "no products match", only ever reached from a **successful** scoped call reporting `0`.
- `unavailable` → "product count not reported by this server" — **never** `0`, never an empty string.
- `rejected` → that selection's own rejection line (e.g. unauthorised, unknown selection ID).
- `not_tested` → the gate failed (AC 2) or the budget ran out (AC 13); the row says which.

**And** `product_count` is a non-null `int` **only** in the `matched` state, read through the same
defensive `extract_product_count()` (`class-skwirrel-wc-sync-jsonrpc-client.php`,
`result.page.number_of_items`, numeric or `null`).
**And** — the amendment that makes this AC bite — **no total is ever summed across selections, on any
path, including the fully-successful one.** A product belonging to two configured selections is counted
by both, so a sum is a number with more than one cause even when everything worked. The happy path was
going to lie. There is no scalar in the payload (AC 1) and no summed figure in the panel (AC 6), so none
can be rendered by accident. A result that can mean two things is not a result.

**6 — One panel, two rows, the same skeleton every time**

**Given** the result region `#skwirrel-test-result`
(`class-skwirrel-wc-sync-admin-dashboard.php:838-841`, `role="status" aria-live="polite"
aria-atomic="true"`)
**When** any outcome renders
**Then** the panel always has the same two-row skeleton — **Row 1 Connection** and **Row 2 Your
settings** — with Row 2 indented and using the same visual grammar as Row 1, so the panel can later grow
a third row for a real dry run without a redesign
**And** Row 1 reads like `Connected to Skwirrel · 412 ms`
**And** Row 2 reports **per selection, never one total** — one segment per configured selection ID, in
configured order, each carrying its own figure and state:
`Context 3 · selection 12 → 1,204 products · selection 15 → 300 products`
and, with mixed states,
`Context 3 · selection 12 → 1,204 products · selection 15 → no products match · selection 19 → not tested`
**And** when `mode => 'blocked'` (no selections configured, AC 3), Row 2 carries no figures at all and
reads `No selection IDs configured — a sync would not start.`
**And** the panel renders **no summed figure anywhere**, on any path (AC 5). Segments may be laid out as
a list rather than a run-on sentence if that reads better at three or more selections; what is fixed is
that every number is attributed to exactly one selection.
**And** the Row 1 detail lines keep their existing order (round-trip, then status, then products, then
attempts when > 1) — pinned by `tests/Unit/TestConnectionMetricsTest.php:311`
**And** — **in scope, decided, not silent** — `#skwirrel-test-result` changes from a `<span>` to a
`<div>` (`class-skwirrel-wc-sync-admin-dashboard.php:1411`), because two stacked rows and the AC 9
`<details>`/`<pre>` are flow content and are invalid inside phrasing content. `.skw-test-result` in
`assets/dashboard.css:405-410` loses its inline `margin-left: 12px` treatment in favour of a block
layout under the button row. This is a real layout change to the Connection tab and this story owns it.

**7 — Tone rules: the filter is never louder than the connection**

**Given** the two rows, where Row 2 is now a sequence of per-selection segments (AC 6)
**When** they render
**Then** tone is applied **per segment**, and Row 2's own tone is the worst of its segments — never
worse than that, and never propagated up to Row 1:
- Selection matched products (`matched`) → that segment **green**.
- Selection matched nothing (`empty`) → that segment **amber**, never red, and **Row 1 stays green**:
  "no products match". One empty selection among several does not make the others look broken.
- Count unavailable (`unavailable`) → that segment **green**: "product count not reported by this
  server". The absence of a number is not a warning.
- Selection rejected (`rejected`) → that segment **amber**, not red. The connection is fine; one
  selection was refused. Row 1 stays green.
- Not tested (`not_tested`) → that segment **muted/greyed**, whether the cause was the failed gate or
  budget exhaustion (AC 2, AC 13).
- Server unreachable / gate failed → Row 1 **red**, every Row 2 segment **muted** as
  "Not tested — connection required first." **Never two red rows for one problem**, and never a red
  segment for something the gate already explained.
- `mode => 'blocked'` (no selections configured) → Row 1 green, Row 2 **amber** with the AC 8 pointer.
  A configuration that cannot sync is actionable, so amber; it is not an error, so not red.
**And** Row 2 is never red on any path. Red belongs to Row 1 and to the gate alone.
**And** every tone is distinguished by more than colour (glyph and/or weight — NFR-7)
**And** — **in scope, decided** — the tone classes become **row-scoped**. Today they are panel-scoped
(`.skw-test-result.skw-test-{success,error,warning}`, `assets/dashboard.css:411-427`), which cannot
express a green Row 1 beside an amber Row 2. Introduce a row-level modifier (e.g.
`.skw-test-row.skw-tone-{success,warning,error,muted}`) and keep the panel class for the outer element.
**And** `success` and `error` currently carry **colour only** — only `warning` has a glyph and heavier
weight (`:424-428`, with a comment saying warning is "the only tone that carries a glyph"). Two rows in
two different tones means colour alone is now doing real work, so this story adds a non-colour
affordance to `success` and to `error` as well, and a `muted` treatment for the "not tested" state.
That is four tones × row, and it is this story's work, not a later polish pass.

**8 — An amber Row 2 names the next step and where to take it**

**Given** any amber Row 2 state — one or more selections `empty` or `rejected`, or `mode => 'blocked'`
**When** it renders
**Then** it is followed by **one** line of next step (not one per segment), naming the tab by its actual
label: "Check Selection IDs on the What to sync tab" — the tab slug `what-to-sync` / label
`What to sync` registered in `Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs()` (`:806-810`), which
is where `collection_ids` lives. A label alone is not enough; this must be a pointer.
**And** in the `blocked` case the same pointer follows "No selection IDs configured — a sync would not
start", because that is precisely where the admin has to go to fix it.

**9 — The raw technical string is never above the fold**

**Given** a failing test of either row
**When** the panel renders
**Then** the plain-language verdict is **on top** and the raw transport string (e.g.
`cURL error 28: Operation timed out after 30001 milliseconds`) is **below**, inside a native
`<details><summary>Technical details (for support)</summary>` element — zero JS, collapsed by default
**And** that block holds the verbatim error, the endpoint, the HTTP status and a timestamp in a `<pre>`
with `user-select: all`
**And** the `<details>` element sits **outside** the `aria-live` region — a sibling container below it,
not a child. Inside, expanding or collapsing it mutates an `aria-atomic="true"` live region, which
re-announces the entire panel including the raw cURL string every time the admin toggles it. It is still
built with `createElement` + `textContent`; no `innerHTML` is needed to place it outside
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
**And** both rows are still built into one detached `DocumentFragment` and committed to the live region
in a single synchronous replacement, so the region announces them as one status update. Note the
existing `aria-busy` true/false bracket in `setRes()` (`:1890`, `:1907`) opens and closes inside the
same synchronous call and is therefore inert — do not treat it as the mechanism, and do not add more
of it. The atomicity comes from the single-pass fragment swap and `aria-atomic="true"`
**And** a browser holding a stale cached script still degrades to the current single-row rendering.

**11 — Scoped values are read from saved settings, and the panel says so**

**Given** the AJAX handler `handle_test_connection_ajax()`
(`class-skwirrel-wc-sync-admin-settings.php:902`) autosaves **only** `endpoint_url`, `auth_type` and the
token, and reads everything else from `skwirrel_wc_sync_settings`
**When** the scoped probe resolves its filters
**Then** it reads the **saved** `collection_ids` / `context_id`, and Row 2 names every selection ID and
the context it actually probed (AC 6's per-selection segments do this by construction — each segment
carries its own `selection_id`), so an admin who typed a new Selection ID without saving can see it was
not among the ones tested
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
**And** the legacy `admin_post` path `handle_test_connection()` (`:849`) shares the **payload layer and
nothing below it**: both paths call `prepare_test_result_payload()` → `format_test_result()`, so the
verdicts, tones, wording and the scoped row's *text* cannot drift. It does **not** share the renderer —
it emits a WordPress admin notice built in `maybe_show_notices()` (`:2424-2429`) with
`.skw-test-metrics` / `.skw-test-metric` spans and never renders `.skw-test-result` at all. AC 6's
two-row skeleton and AC 9's `<details>` are renderer-level and **do not reach it**. The legacy notice
must therefore render the scoped headline and its detail lines as additional escaped lines in the same
notice, and nothing more. Do not delete the legacy path and do not grow a second renderer for it.

**13 — The probe is bounded by wall clock, not by call count**

**Given** `JsonRpc_Client::call()` loops `while ( $attempt <= $this->retries )` — retries **+ 1**
attempts — with a blocking `usleep( 500000 * $attempt )` backoff and **no total elapsed budget**
(`class-skwirrel-wc-sync-jsonrpc-client.php:77,78,111`), and the constructor clamps only the
*per-request* values (timeout 5–120, retries 0–5, `:33-34`), and `handle_test_connection_ajax()` passes
the **saved** timeout/retries straight through (`class-skwirrel-wc-sync-admin-settings.php:932-933`),
and neither file calls `set_time_limit()`
**When** the probes run inside one `admin-ajax.php` request
**Then** they are bounded by an **elapsed-time budget** and by **clamped probe-local timeout/retries** —
not by a cap on the number of selection IDs
**And** the probe uses its own conservative timeout and retry count, in the shape this codebase already
uses for exactly this problem: `fetch_statuses()` clamps to `STATUS_SCAN_TIMEOUT = 10` /
`STATUS_SCAN_RETRIES = 1` against `STATUS_SCAN_BUDGET = 12`
(`class-skwirrel-wc-sync-admin-settings.php:43-58`, `:1100-1119`), with a comment spelling out the
"120s × up to six attempts" hazard. Follow it; do not invent a different mechanism, and do not add a
setting for it
**And** the budget is checked **between** scoped calls, so the probe stops early and reports what it
resolved so far rather than being killed mid-request
**And** an unprobed selection is simply left in `state => 'not_tested'` with `product_count => null` —
the **same vocabulary and the same muted treatment** as the failed-gate case (AC 2, AC 7). Nothing
special is needed for budget exhaustion, and no "partial" concept enters the payload or the copy
**And** because AC 5 forbids a summed total on every path, there is no partial total that could exist to
mislead. This is the point of the per-selection shape: the budget case falls out of it for free rather
than needing its own rule
**And** a supporting fact for the reviewer: at the clamp maxima one `call()` is
6 × 120 s + 7.5 s backoff ≈ **727 s**; at the shipped defaults (timeout 30, retries 2) it is
3 × 30 s + 1.5 s ≈ **91.5 s**. PHP's default `max_execution_time` is 30 s and a typical FPM/nginx
`fastcgi_read_timeout` is 60 s. Unbudgeted, the admin gets a truncated response or a 504 — **no verdict
at all**, which is strictly worse than today's unfiltered probe.

**14 — Translations ship with this story, not at release time**

**Given** this story adds a substantial number of admin-facing strings
**When** it is completed
**Then** `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot` is regenerated **as part of this
story**, and all seven locales (`nl_NL`, `nl_BE`, `de_DE`, `fr_FR`, `fr_BE`, `en_US`, `en_GB`) are
updated and recompiled — `.po` translated, `.mo` rebuilt **last** so its mtime is newer than its `.po`
(`tests/Unit/AdminSettingsRequiredFieldsTest.php` asserts that ordering)
**And** `en_GB` / `en_US` are filled as mirrors of the English source (`msgstr` == `msgid`) per the house
convention — verified: 410 of 413 pairs are byte-identical, the exceptions being genuine en_GB
localisations. Do not leave them empty
**And** the story ships its own hand-curated POT-coverage test for its new strings, following the house
pattern (`AdminSettingsRequiredFieldsTest.php:244`, `TestConnectionMetricsTest.php:450`,
`FieldMappingTranslationsTest.php:102`) — there is no global POT-coverage gate, so a story that skips
this ships untranslatable strings silently.

## Ruling on shared parsing (Henk finding 2)

**Ruling: authorise exactly one new shared helper, and scope it narrowly.**

The original AC 3 told the dev to resolve `collection_ids` through
`Skwirrel_WC_Sync_Service::get_collection_ids()`. Verified: that method is **`private function`**
(non-static) at `class-skwirrel-wc-sync-service.php:2504` — unreachable from `Admin_Settings`. The
instruction was unimplementable. Also verified: the positive-int parse exists in **four** places —
three private `normalize_positive_id()` copies (`service:1484`, `purge-handler:1067`, `upserter:1344`)
and a fourth inline variant in `sanitize_settings()` (`admin-settings:491-497`), which differs subtly
(`is_numeric` + `intval`, so `"12.9"` becomes `12`).

So: **add `Skwirrel_WC_Sync_Admin_Settings::parse_selection_ids()` (`public static`), extracted verbatim
from the `sanitize_settings()` block, and give it exactly two callers — the sanitiser and the scoped
probe.** Behaviour-identical extraction, so the existing sanitiser tests stay green and pin it.

**Explicitly not authorised here:** unifying the three `normalize_positive_id()` copies, or making
`Service::get_collection_ids()` public/static. That is a repo-wide refactor of the sync path, which this
admin-surface story has no business carrying, and it would put Epic 7 on the sync path for the first
time. The "never a second parser" clause therefore means *within the admin surface's handling of the
selection-ID string*, not repo-wide. Recorded in `deferred-work.md`.

## Out of scope — state it, do not creep into it

One unfiltered probe plus one scoped probe per configured selection ID, synchronous return, the whole
thing under an elapsed-time budget (AC 13). The earlier "two HTTP calls" framing was wrong: the API has
no `collection_ids` parameter, so N selections mean N scoped calls, and a *count* cap bounds nothing
when a single call can legitimately run for 727 s. Explicitly **not** in this story:

- **No pagination.** The probe reads a total, it never walks pages.
- **No per-product create/update classification.** That is the dry run, not this.
- **No caching** of either probe result.
- **No new settings** and no new option, transient or meta key.
- **No Action Scheduler job**, no background work, no loopback.
- **No history entries** — this writes nothing to `skwirrel_wc_sync_history`.
- **No change to the sync path.** Admin surface plus client response handling only.
- **No "would be trashed" number.** See the open questions.
- **No repo-wide parser refactor.** One new `parse_selection_ids()` with two callers; the three private
  `normalize_positive_id()` copies stay exactly where they are (see the Ruling, and `deferred-work.md`).
- **No unrelated translation fixes.** The catalogues carry at least one known defect (`deferred-work.md`,
  2026-08-27); AC 14 regenerates and fills what *this story* adds, and nothing else.

## Design note — the future dry run (record, do not build)

The direction this is a stepping stone toward, so a later story does not have to re-derive it:

- A `Skwirrel_WC_Sync_Dry_Run` class in
  `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-dry-run.php` (WPCS file-naming rule;
  requires registration in **two** places — the `require_once` in `skwirrel-pim-sync.php` **and** hook
  wiring in `Skwirrel_WC_Sync_Plugin`).
- It walks pages through the same client, reusing `build_probe_block()` (AC 4) for the read block.
- It classifies create-vs-update through `Skwirrel_WC_Sync_Product_Lookup`, and **never** touches
  `Skwirrel_WC_Sync_Product_Upserter`. The seam that matters is **lookup without upsert**.
- Test Connection is the **zero-page case** of that walk. This story exists to make that literally true.

**The seam that matters is the decision layer, not the params builder.** `build_probe_block()` is a
convenience — worth having, not worth mistaking for the shared surface. The dry run's real shared surface
is the decision layer: `Skwirrel_WC_Sync_Product_Lookup`, plus **dedupe across overlapping selections**,
which only a page walk can do. That is exactly what this story cannot do and does not pretend to: with
`limit => 1` there are no product IDs to compare, so overlap is unknowable here — which is why Row 2
reports per selection and never sums (AC 5). A dry run that walks pages *can* dedupe, and must, or its
headline number inherits the same double-count. That is a decision-layer job, not a fetch-layer one.

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
2. **Consequently, N configured selections mean N scoped calls — and a count cap does not bound that.**
   The first draft of this story capped the probe at five selection IDs. Henk's review killed that, and
   he is right: with the clamp maxima a *single* `call()` can run ~727 s, so "at most six calls" is a
   ~73-minute ceiling in one admin-ajax request. **AC 13 replaces the count cap with a wall-clock budget
   plus probe-local timeout/retry clamps**, copying `fetch_statuses()`. Any surviving mention of a
   five-selection cap elsewhere is stale — AC 13 governs.
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
6. **The result element is a `<span>`, not a container** (`class-skwirrel-wc-sync-admin-dashboard.php:1411`,
   inline beside the button, `margin-left: 12px`). `<details>` and `<pre>` are flow content and are
   invalid inside phrasing content. AC 6 now owns the `<span>` → `<div>` change and the layout it implies.
7. **`aria-busy` in `setRes()` is inert.** It is set `true` at `:1890` and `false` at `:1907` inside the
   same synchronous function, so no assistive technology ever observes the busy state. AC 10 no longer
   leans on it.
8. **Tone CSS is panel-scoped and two of three tones are colour-only.**
   `.skw-test-result.skw-test-{success,error,warning}` (`assets/dashboard.css:411-427`); only `warning`
   carries a glyph and heavier weight. AC 7 now owns row-scoped tones plus non-colour affordances for
   `success`, `error` and `muted`.

### Verification of Winston's ratification pass (John, 2026-08-27)

Both of his structural rulings hold and are folded in. Two things I checked that change the shape of what
he asked for:

- **The `getProducts` branch he asked the probe to mirror is unreachable.** His refinement — "when a
  context is configured but no selections are, the sync takes the `getProducts` branch" — assumes
  selections are optional. They are not: `collection_ids` sits in `unconditional_required_fields()`
  (`class-skwirrel-wc-sync-admin-settings.php:325-326`) and `run_sync()` hard-fails at
  `class-skwirrel-wc-sync-service.php:242-245` before any fetch. `fetch_products_page()` has exactly one
  call site and it always passes `$use_filter = true` (`:713`), so its `getProducts` branch
  (`:2372-2382`) is dead code in the sync path. His *principle* — mirror the branch the config takes —
  is right and is what AC 3 now encodes; it just resolves to `filter` or `blocked`, and `blocked` is a
  better Row 2 than a product count would have been, because it tells the admin the sync would refuse to
  start.
- **The overlap problem he predicted is real in the live sync, and it is worse than a wrong count.**
  Confirmed below; filed to `deferred-work.md`, not absorbed here.

**Overlapping selections in the live sync (checked, real, not this story's to fix).** `step_fetch()`
loops the configured selections and calls `$queue->insert_item()` per product per selection
(`class-skwirrel-wc-sync-service.php:707-760`). The queue table has no unique constraint — `PRIMARY KEY
(id)` plus two non-unique indexes (`class-skwirrel-wc-sync-queue.php:28-41`) — and `insert_item()` is a
plain `$wpdb->insert()` (`:92-104`). So a product in two configured selections is fetched twice and
queued twice, then processed twice through the full upsert phases. It does **not** produce duplicate
products — the `_skwirrel_external_id` upsert lookup finds the existing one on the second pass — but the
run does duplicated work and the reported counters (`fetched`, `total`, `created`/`updated`/`unchanged`)
are inflated. Note the membership *sweep* does dedupe, via `array_fill_keys` in `load_sweep_set()`
(`:1753-1765`); the fetch loop does not. **Do not fix this in 7.1** — it is a sync-path change and this
story is admin-surface only.

### Verification of Henk's review (John, 2026-08-27)

Every finding was re-checked against the code rather than taken on trust. **Five of six landed exactly as
described** (findings 1, 2, 3, 5, 6) and are folded into the ACs above. Finding 4 is right on substance —
collapsed `<details>` content is out of the accessibility tree, and toggling it inside an
`aria-atomic="true"` region re-announces the whole panel — and its `aria-busy` observation is confirmed.

Two numbers of his were wrong and are corrected here so a dev does not inherit them:

- **The retry backoff is 7.5 s, not 4.5 s.** The `usleep( 500000 * $attempt )` fires for attempts 1–5, so
  0.5 + 1 + 1.5 + 2 + 2.5 = 7.5 s. His headline total (~727 s) is nonetheless right: 720 + 7.5 = 727.5 s.
  Worth knowing precisely *when* it applies — the backoff and the retry loop only continue on the
  `is_wp_error()` transport branch (`:96-112`); invalid JSON and HTTP >= 400 `break` immediately
  (`:124`, `:139`) and a JSON-RPC `error` object returns straight away (`:145`). A slow-but-responsive
  server therefore costs one timeout, not six.
- **His empty-`msgstr` count is an artefact, not a finding.** He reports 60–80 untranslated strings per
  locale; that is what `grep -c '^msgstr ""$'` returns, and it counts the opening line of every
  *multi-line* `msgstr` plus the header. `msgattrib --untranslated` gives the real answer: **4 per locale**
  for de/fr/nl, and all four are plugin-header metadata (plugin URI, description, author, author URI),
  which are intentionally untranslated. There is no 60–80-string translation debt. See `deferred-work.md`
  for the small, real item that *did* surface.

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
- Suggested new unit file `tests/Unit/TestConnectionScopedProbeTest.php`, covering:
  - `mode => 'blocked'` with no selections configured — **no scoped request issued**, Row 2 says a sync
    would not start, and the AC 8 pointer is present.
  - Per-selection states in one payload: `matched` + `empty` + `unavailable` + `rejected` together,
    asserting each figure is attributed to its own `selection_id` and each carries its own tone.
  - **The no-sum guarantee, asserted directly**: the payload exposes no scalar total, and the rendered
    Row 2 contains no figure equal to the sum of its segments. This is the test that stops the
    double-count regressing in — write it even though it feels tautological today.
  - Gate failed → every selection `not_tested`, **no** scoped request issued.
  - Budget exhausted → the unprobed selections are `not_tested`, indistinguishable in shape from the
    failed-gate case, and still no total. Keep the budget clock injectable so the test is deterministic —
    no wall-clock nondeterminism in tested logic (project rule).
  - `parse_selection_ids()` agreeing with what the sanitiser stores.
  - `build_probe_block()` returning the same block for both envelopes, and the `$use_filter` choice
    selecting `getProductsByFilter` vs `getProducts` explicitly.
- **POT-coverage test (AC 14, house convention).** The same new unit file carries a hand-curated
  `dataset()` of every new msgid this story adds, asserting each is present in
  `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot` and wrapped with the **literal** text domain
  `'skwirrel-pim-sync'` — modelled on `tests/Unit/TestConnectionMetricsTest.php:450,473`. There is no
  global POT-coverage gate; if this story does not carry its own list, nothing catches an untranslatable
  string.
- **POT regeneration recipe** (no local wp-cli in this repo; use the wp-env container). Order matters —
  `msgfmt` runs **last**:
  1. `npx wp-env run cli --env-cwd=wp-content/plugins/skwirrel-pim-sync wp i18n make-pot . languages/skwirrel-pim-sync.pot --slug=skwirrel-pim-sync --domain=skwirrel-pim-sync --exclude=vendor,node_modules,tests`
  2. `msgmerge --update --backup=none --no-fuzzy-matching <locale>.po skwirrel-pim-sync.pot` per locale
  3. translate the new entries; fill `en_GB` / `en_US` as mirrors (`msgstr` == `msgid`), never empty
  4. `msgcat --width=79` to restore gettext wrapping
  5. `msgfmt` last, so every `.mo` is newer than its `.po`
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
  additive `scoped` key and its params seam, Sally's two-row panel with its amber/greyed tone
  rules and collapsed "Technical details (for support)" block, the gh#47 verdict-on-top display fix, and
  the one-cause-per-number constraint. Placed in a new Epic 7 rather than reopening the closed Epic 5.

- 2026-08-27 — Review pass folded in (Henk, WordPress/WooCommerce plugin engineer; verified by John
  against the code). AC 3 rewritten: the unimplementable `Service::get_collection_ids()` mandate is
  replaced by a ruling authorising one shared `parse_selection_ids()` helper. New AC 13 replaces the
  selection-count cap with a wall-clock budget plus probe-local timeout/retry clamps, following
  `fetch_statuses()`. New AC 14 pulls POT regeneration and all seven locales into the story instead of
  release time. AC 6 takes the `<span>` → `<div>` layout change into scope; AC 7 takes row-scoped tones
  and non-colour affordances for `success`/`error`/`muted` into scope. AC 9 moves the `<details>` outside
  the live region; AC 10 drops its reliance on the inert `aria-busy` bracket; AC 12 states that the legacy
  `admin_post` path shares the payload layer only, never the renderer. Two of Henk's numbers corrected:
  the retry backoff is 7.5 s (not 4.5 s), and the reported 60–80 empty `msgstr` per locale is a
  `grep` artefact — `msgattrib` shows 4, all plugin-header metadata.

- 2026-08-27 — Architect ratification pass (Winston; verified by John against the code). AC 3's
  `getProductsByFilter` call accepted without reservation. Four amendments folded in. **AC 1**: `scoped`
  becomes a per-selection structure (`mode`, `contexts`, `selections[]` with `state` +
  `product_count`) and loses its scalar `product_count` entirely. **AC 3**: the probe mirrors whichever
  branch the config takes — verified to be two reachable cases, `filter` and `blocked`, not three.
  **AC 4**: the seam splits into `build_probe_block()` plus an explicit `$use_filter` envelope choice
  matching `fetch_products_page()`; the single-method-two-envelopes formulation is rejected as a hidden
  mode switch. **AC 5**: no total is summed across selections on any path, including the successful one,
  because overlapping selections double-count. **ACs 2, 6, 7, 8, 13** follow that through: per-selection
  segments, per-segment tones, Row 2 never red, one shared `not_tested` vocabulary for both the failed
  gate and budget exhaustion. Design note amended to name the decision layer (`Product_Lookup` + dedupe
  across overlapping selections) as the seam that matters. One premise of Winston's corrected: the
  context-configured-but-no-selections case cannot take a `getProducts` branch, because `collection_ids`
  is unconditionally required and the sync hard-fails without it. Overlapping-selection double-work in
  the live sync filed to `deferred-work.md`.
