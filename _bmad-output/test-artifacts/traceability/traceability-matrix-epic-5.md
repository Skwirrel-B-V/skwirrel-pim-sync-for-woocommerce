---
stepsCompleted:
  ['step-01-load-context', 'step-02-discover-tests', 'step-03-map-criteria', 'step-04-analyze-gaps', 'step-05-gate-decision']
lastStep: 'step-05-gate-decision'
lastSaved: '2026-09-14'
scope: 'Epic 5 — A settings screen you can navigate, trust, and verify'
tracedAtCommit: '20eaf3f'
workingTree: 'dirty — no Epic 5 surface touched'
coverageBasis: 'acceptance_criteria'
oracleConfidence: 'high'
oracleResolutionMode: 'formal_requirements'
oracleInterpretation: 'epics.md as written (owner decision, 2026-09-14)'
oracleSources:
  - '_bmad-output/planning-artifacts/epics.md#epic-5'
  - '_bmad-output/implementation-artifacts/5-1-tabbed-settings-navigation.md'
  - '_bmad-output/implementation-artifacts/5-2-required-field-markers-and-inline-errors.md'
  - '_bmad-output/implementation-artifacts/5-3-context-id.md'
  - '_bmad-output/implementation-artifacts/5-4-test-connection-metrics.md'
externalPointerStatus: 'not_used'
collectionMode: 'contract_static'
collectionStatus: 'COLLECTED'
summaryConfidence: 'high'
tempCoverageMatrixPath: '/private/tmp/claude-501/-Users-joskoomen-Documents-Projects-Skwirrel-wordpress/87d88eef-29bc-4c89-9252-e1811e520b05/scratchpad/tea-trace-coverage-matrix-epic-5-2026-09-14.json'
gateDecision: 'FAIL'
---

# Traceability Report — Epic 5

_Re-trace at `20eaf3f` (2026-09-14). Supersedes the 2026-09-02 trace at `4044e22`._

## Gate Decision: 🚫 FAIL

**Rationale (deterministic rule 3):** P1 coverage is **71%** against an 80% minimum. P0 is 100% and
overall is 82%, so rules 1 and 2 pass. Rule 3 does not.

**What moved the gate was a product change, not a test change.** `e8332bf` (_"hide the Context ID
field on the settings screen"_) wrapped the field in `hidden`. That broke two P1 criteria:

- `5.3-AC1`: _"an optional **Context ID** field is present"_.
- `5.3-AC5`: _"rejected with an inline error"_, which implies the owner can see the error and fix it.

Every test stayed green, because the tests check that the field exists in the DOM and never check
that it is visible. The commit passed all three quality gates while making both ACs false.

**Oracle interpretation — owner decision.** Jos chose to score against `epics.md` *as written*
rather than treat the hide as a deliberate descope. That is the right call for a stopgap. It is
also cheap to reverse, and either route below lifts the gate to **CONCERNS**:

| Route | Effect | P1 | Gate |
| --- | --- | --- | --- |
| Unhide the field | 5.3-AC1 and 5.3-AC5 back to FULL | 12/14 = 86% | CONCERNS |
| Retire 5.3-AC1, amend 5.3-AC5 in `epics.md` | 5.3-AC1 leaves the count; 5.3-AC5 FULL | 11/13 = 85% | CONCERNS |

Neither route reaches PASS. `5.1-AC4` and `5.1-AC6` still sit at the accepted E2E ceiling, and the
closed decision in `deferred-work.md` names CONCERNS as the cost of that.

## Coverage Summary

| Metric                    | Value                                          | Δ vs 2026-09-02 |
| ------------------------- | ---------------------------------------------- | --------------- |
| Total acceptance criteria | 22                                             | —               |
| Fully covered             | 18 (82%)                                       | −2              |
| Partially covered         | 4                                              | +2              |
| Uncovered                 | 0                                              | —               |
| P0 coverage               | **100%** (5/5) — required 100% ✅              | —               |
| P1 coverage               | **71%** (10/14) — target 90%, minimum 80% 🚫   | −15 pts         |
| P2 coverage               | 100% (3/3) ✅                                  | —               |

## Coverage Oracle

Formal requirements, high confidence: the four Epic 5 stories in `epics.md` (unamended since the last
trace) plus their implementation artifacts, all `status: done`. AC IDs and priorities are unchanged
from previous traces.

Four commits since `4044e22` contradict AC text. They are scored in two different ways, and the
difference is deliberate:

| Commit | Contradicts | Scored | Why |
| --- | --- | --- | --- |
| `e7c51da` tabs re-homed, "How it looks" dropped | 5.1-AC1 | FULL + drift | The AC's invariant (every field in exactly one tab, per a re-home map) is still exercised against the current map. Only the named examples are stale. |
| `c346a07` Danger zone becomes a tab | 5.1-AC1 | FULL + drift | The purpose behind "stays outside" was keeping it out of the settings form payload. It is still outside the form, and that is tested. |
| `86bc9c6` `custom_collection_id` no longer required | 5.1-AC2 rationale, 5.2-AC2 | FULL + drift | The AC's invariant (marker == the rule the sanitiser enforces) holds, and the anti-drift test still checks it across every checkbox combination. |
| `e8332bf` Context ID field hidden | 5.3-AC1, 5.3-AC5 | **PARTIAL** | The outcome the AC promises to the owner is gone. That is not a stale example. |

A strict literal reading that also demoted `5.1-AC1` would give P1 9/14 = 64%. The gate is the same,
so the distinction changes nothing here.

## Test Inventory

| Level       | Files | Cases | Notes                                           |
| ----------- | ----: | ----: | ----------------------------------------------- |
| Unit        |     5 |   122 | Stub bootstrap, no Docker                       |
| Integration |     4 |    78 | Real WP + WC via wp-env                         |
| Component   |     0 |     0 | No component layer in this stack                |
| E2E         |     0 |     0 | No browser layer — **accepted ceiling**, closed |

- `tests/Unit/SettingsTabsTest.php` (27) · `tests/Integration/SettingsTabsIntegrationTest.php` (19)
- `tests/Unit/AdminSettingsRequiredFieldsTest.php` (14) · `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` (25)
- `tests/Unit/ContextIdTest.php` (37) · `tests/Integration/ContextIdIntegrationTest.php` (14)
- `tests/Unit/TestConnectionMetricsTest.php` (28) · `tests/Integration/TestConnectionMetricsIntegrationTest.php` (20)
- `tests/Unit/AdminSettingsEndpointUrlTest.php` (16)

The case counts are identical to 2026-09-02, but the contents are not. `86bc9c6` rewrote four
required-field tests to assert that `custom_collection_id` is *never* required. `c346a07` and
`e7c51da` rewrote the tab-count and tab-order assertions. `ContextIdIntegrationTest` was **not
touched** by `e8332bf`, which is how that commit got through. No skipped, pending or fixme cases.

### Suite status at trace time

| Suite | Result |
| --- | --- |
| `vendor/bin/pest` (unit, whole repo) | ✅ **675 passed**, 1749 assertions, 0.78s |
| `npm run test:integration` (run A) | ✅ **233 passed**, 1 deprecated, 1755 assertions, 58.6s |
| `npm run test:integration` (run B) | ✅ **233 passed**, 1 deprecated, 1755 assertions, 50.4s |
| `vendor/bin/phpstan analyse` | ✅ No errors |
| `vendor/bin/phpcs` | ✅ Clean |

Both runs were identical, so the suite is still deterministic. The one deprecation is in
`SyncSafetyIntegrationTest` (queue test), outside Epic 5. The runs used the working tree as it stood,
with uncommitted changes to the sync banner class, delete protection, sync service, CSS and
translations. None of them touch an Epic 5 code path.

## Traceability Matrix

Legend: **FULL** = behaviour exercised · **PARTIAL** = asserted indirectly, or the asserted outcome is
no longer the product's · **NONE** = no evidence. Rows marked 🔄 changed since 2026-09-02.

### Story 5.1 — Tabbed settings navigation

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.1-AC1** — every field in exactly one tab; groups re-home per the map; Danger Zone outside | P1 | ✅ FULL 🔄 | I: *each of the nine field groups sits in exactly the tab the re-home map names* · *the five tabs each own a panel; four settings panels live inside the form, Danger zone lives outside it* · *the danger zone stays outside and below the settings form* · U: *the registry ships the five settings tabs in a deterministic order*. **Drift:** the AC says four tabs incl. "How it looks", with Danger Zone outside the tab set. The product has five, and Danger zone is one of them (`e7c51da`, `c346a07`). |
| **5.1-AC2** — one request, no per-tab option writes, other tabs unchanged | **P0** | ✅ FULL | I: *every input name the pre-tabs settings form rendered is still rendered* · *saving from a non-default tab leaves unrelated stored settings unchanged* · *the tab strip sits outside the form and cannot submit it* · *the save button is inside the form but outside every panel* · U: *a filter cannot remove or replace a built-in panel and drop its settings from the form*. The AC's example (`custom_collection_id` required by `sync_custom_classes`) is stale since `86bc9c6`. The single-request property it justifies is still tested. |
| **5.1-AC3** — errored tab marked (not colour alone), first errored tab opens | P1 | ✅ FULL | I: *a tab holding a failing field is marked with a count, not colour alone* · *a sanitiser error is readable on the page, above the tab strip* · *a clean render carries no error notice and no badge* · U: *the first tab holding an error opens* · *with errors on several tabs the first one in tab order opens* · *several errors on one tab are counted, not collapsed* |
| **5.1-AC4** — `#tab-{slug}` deep link opens that tab; no `?tab=` collision | P1 | 🟡 PARTIAL | I: *the tab behaviour rides on the existing admin script handle* asserts `'#tab-'` in the enqueued JS (line 666). The link is never followed. It now has a real consumer: the dashboard's Danger Zone quick-link deep-links to `#tab-danger-zone` (`c346a07`), which is also never followed. Accepted ceiling. |
| **5.1-AC5** — no-JS degradation, nothing unreachable | P1 | ✅ FULL | I: *with no JavaScript every panel is a visible sequential section* |
| **5.1-AC6** — keyboard reachable/operable, visible focus ring, AT wiring | P1 | 🟡 PARTIAL | AT wiring is real: I: *the ARIA wiring resolves in both directions and exactly one tab is selected* · *the roving tabindex belongs to the selected tab, and only to it*. `'ArrowRight'` (line 668) and `.skw-tab:focus-visible` (line 689) are source strings. The redesign (`dbd565a`) adds `.skw-settings-page .skw-tab` rules with higher specificity but no focus rule. The `:hover` rule's `background: transparent` wins over the focus tint when a tab is hovered and focused together. That can't be checked without a browser. Accepted ceiling. |
| **5.1-AC7** — tab registration extensible without touching markup | P2 | ✅ FULL | U: *an external tab registers through the filter and lands at its order position* · *malformed registrations are dropped* · I: *a tab registered with a named external renderer renders in its order position without touching the loop* · *an outside tab whose errors fire opens first* |

### Story 5.2 — Required markers and inline errors

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.2-AC1** — `*` + `aria-required` on unconditionally required fields | P1 | ✅ FULL | I: *an unconditionally required field is marked and announced as required* · *every marker on the screen is a named character, not colour alone* (now 3 markers) · *no control blocks submit without a marker and a registry entry behind it* · U: *the unconditionally required fields are always required*. **Drift:** the AC names `subdomain`; the field is `skwirrel_base_url`. |
| **5.2-AC2** — conditional markers match the rule `sanitize_settings()` enforces | P1 | ✅ FULL 🔄 | U: *the registry and sanitize_settings agree on every checkbox combination* · *super_category_id is required exactly when category sync is on* · *custom_collection_id is never required, regardless of the consuming features* · I: *custom_collection_id is never required, whichever of the three consuming features is on* · *custom_collection_id carries no required state at all, whatever else is configured* · *the marker toggle reads its conditions off the markup*. **Drift:** the AC names `custom_collection_id` as conditional. Since `86bc9c6` it is never required. Marker and rule still agree, which is what the AC protects. |
| **5.2-AC3** — messages adjacent to their field, programmatically associated | P1 | ✅ FULL | I: *a validation message renders at its field and is announced with it* · *every mapped error code renders at the field it names* · *a rejected value is kept in the field so it can be corrected* · U: *every error code maps to a field the settings screen knows about* |
| **5.2-AC4** — a failing field on a collapsed tab opens and marks that tab | P1 | ✅ FULL | I: *the failing field ids are exposed to the tab strip as a seam* · *the failing field ids reach the browser through the localized seam* · U: *the two sanitiser error codes all resolve to the What to sync tab*. The one field that now violates this AC's intent (`context_id`: tab opens, field invisible) is scored under 5.3-AC5, so it isn't counted twice. |
| **5.2-AC5** — new strings translatable | P2 | ✅ FULL | U: *the strings this story added are in the POT and in all seven locales* · *every locale ships a compiled catalogue next to its source* |

### Story 5.3 — Context ID

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.3-AC1** — optional Context ID field present, placeholder `1`, help text | P1 | 🟡 **PARTIAL** 🔄 | I: *the Context ID renders as an optional whole-number field on the Connection tab* · *the Context ID has a label and a hint that explains what leaving it empty does* · U: *the Context ID is optional*. All still true in the DOM. **But** `admin-dashboard.php:1505` now reads `<div class="skw-field" hidden …>`, so the field is not present to the owner. No test asserts visibility. |
| **5.3-AC2** — value sent on every JSON-RPC call | **P0** | ✅ FULL | U: *no call site is left on a hardcoded context literal* (census) · *getGroupedProducts carries the configured context* · *each resumable step re-applies the run context to the upserter* · I: *a configured Context ID reaches the product fetch of a real sync run* · *categories are fetched from the same context as the products*. `e8332bf` left the read and API paths untouched, and the tests confirm it. |
| **5.3-AC3** — empty ⇒ parameter omitted; existing installs unchanged | **P0** | ✅ FULL | U: *an unset, empty or invalid Context ID resolves to null* · *getGroupedProducts sends no context parameter at all when none is configured* · I: *an unconfigured Context ID leaves the product fetch on the default context* |
| **5.3-AC4** — a changed Context ID arms `force_full_sync` and tells the admin | **P0** | ✅ FULL | U: *a changed effective context sets the force-full-sync flag and tells the admin* · *a rejected value arms no full sync* · I: *saving a changed effective context through update_option really sets the flag* · *a test-connection click preserves the Context ID and does not schedule a full re-sync*. Still reachable for an install that already has a stored context. Hiding the field just means the owner can no longer *make* the change from the UI. |
| **5.3-AC5** — non-numeric/negative rejected with an inline error, not coerced | P1 | 🟡 **PARTIAL** 🔄 | U: *an invalid Context ID raises a settings error and is stored exactly as typed* · *a rejected value never moves the context the plugin syncs with* · I: *a rejected Context ID comes back in the field with its message, and flags the Connection tab* (line 340). Rejection and not-coercing are exercised. The inline error now renders inside the hidden wrapper. The test's own comment, _"The user sees what they typed, so they can correct it"_, is no longer true. See Finding 1. |

### Story 5.4 — Test Connection reports what came back

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.4-AC1** — round-trip time, status, API-reported product total | P1 | ✅ FULL | U: *a successful test reports timing, status and the API-reported total* · *the product total comes from the pagination block, not the returned array* · I: *a successful call carries measurement and the API-reported total* |
| **5.4-AC2** — zero products reads as a warning | P1 | ✅ FULL | U: *a zero total is a warning, not a success* · I: *zero products stays a success response and carries the warning in the tone* |
| **5.4-AC3** — a failure still reports timing and status | P1 | ✅ FULL | U: *a transport failure says there was no response instead of showing status 0* · *a JSON-RPC rejection is distinguishable from a transport failure* · I: *a transport failure counts every attempt and reports no HTTP response* |
| **5.4-AC4** — no extra writes; token appears nowhere in output | **P0** | ✅ FULL | I: *the test writes nothing beyond the settings it already autosaved* · *the auth token is sent to the API but never appears in the response* · *an API error that reflects the auth token is redacted* · authz denied paths (no nonce, forged nonce, subscriber 403, no SSRF on refusal) · U: *the formatter cannot leak the auth token because it never receives one* |
| **5.4-AC5** — tabular figures, strings translatable | P2 | ✅ FULL | U: *metric numbers render with tabular numerals* · *every new user-facing string is in the translation template* |

## Coverage Heuristics

| Dimension | Status | Finding |
| --- | --- | --- |
| Error paths | ✅ Strong | Unchanged: transport, HTTP ≥400, JSON-RPC rejection, non-JSON, absent pagination, corrupt option. |
| UI state | 🟠 **Gap** | Error state for a hidden field (`context_id`) — see Finding 1. Every other state is asserted against real markup. |
| Back-compat | ✅ Strong | Pre-tabs input-name census still green after two re-homings and a new tab. |
| Secret handling | ✅ Strong | Unchanged. |
| Auth / authz | ✅ Closed | Four denied-path tests, green. |
| **Visibility** | 🟠 **New blind spot** | No Epic 5 test asserts that a field it checks is visible. Presence in the DOM is treated as presence to the owner. `e8332bf` is the proof that this matters. |
| Suite reliability | 🟢 Closed | Two identical full runs. |
| E2E layer | ➖ Accepted ceiling | Caps `5.1-AC4` and `5.1-AC6`. Hiding the field is catchable **without** a browser, so it doesn't fall under this ceiling. |

## Findings

### 🔴 Finding 1 — The hidden Context ID field traps a rejected value (bug)

Chain, derived from code and the existing integration test (not reproduced in a browser):

1. `sanitize_settings()` stores a rejected `context_id` **exactly as typed** (`'abc'`) and raises
   `add_settings_error(…, 'context_id', …)` (`class-skwirrel-wc-sync-admin-settings.php:437-453`).
2. The screen renders that value back into the input (asserted by
   `ContextIdIntegrationTest.php:340`). The input sits inside `<div class="skw-field" hidden>`
   (`class-skwirrel-wc-sync-admin-dashboard.php:1505-1527`).
3. Hidden inputs still submit, so **every later save** posts `'abc'` again. Step 1 re-raises the
   error.
4. Every render then opens the Connection tab, badges it, shows the top-of-page notice, and puts
   the inline message inside the hidden wrapper.

The owner sees a permanent error about a field they cannot see, and nothing on the screen lets them
clear it. Sync is not affected: `get_context_ids()` refuses the rejected value and keeps the
effective context, as 5.3-AC5's unit tests prove.

**Who is exposed:** any install where an invalid Context ID was typed between the 5.3 release and
`e8332bf`, or that received a crafted POST. It's rare, but a normal save can't get them out.

This is a bug whichever route is taken on the gate. Retiring the AC doesn't fix it. Two fixes would
work: stop rendering a stored rejected value into the hidden input (render the effective value
instead), or don't raise a context error while the field is hidden. Either way, add a test that no
field carrying `aria-invalid` sits inside a `[hidden]` ancestor.

### 🟠 Finding 2 — False-green evidence let `e8332bf` through every gate

`e8332bf` changed one attribute, and 675 unit + 233 integration tests, PHPStan and PHPCS all passed.
Epic 5's integration layer uses DOMXPath over rendered markup. That is the right tool, and it could
already have caught this with one assertion: *no ancestor of the field carries `hidden`*. There is no
such assertion anywhere in the Epic 5 files.

This is **not** the E2E ceiling. The ceiling covers behaviour that needs a real browser (following a
link, pressing a key). A `hidden` attribute is plain markup, so it can be asserted server-side at no
extra cost. I recommend adding a shared helper, used by the tests behind 5.1-AC5, 5.2-AC1, 5.2-AC3
and 5.3-AC1: *assert the element and every ancestor lacks `hidden`* (outside inactive tab panels,
which JS hides and which 5.1-AC5 already covers).

### 🟠 Finding 3 — `epics.md` drift grew from 2 ACs to 4 (carried, unactioned)

The 2026-09-02 trace recommended amending `5.1-AC1` and `5.2-AC1`. That wasn't done, and three more
commits have added to the drift since:

| AC | Epic text says | Product does | Landed in |
| --- | --- | --- | --- |
| 5.1-AC1 | four tabs incl. **How it looks**; Danger Zone **outside** the tab set | five tabs: Connection · What to sync · Field mapping · Advanced · **Danger zone** | Epic 6, `e7c51da`, `c346a07` |
| 5.1-AC2 | "`custom_collection_id` is required based on `sync_custom_classes`" | never required | `86bc9c6` |
| 5.2-AC1 | field `subdomain` | `skwirrel_base_url` | `4044e22` |
| 5.2-AC2 | `custom_collection_id` is conditionally required | never required; run-time guard in `Sync_Service::run_sync()` | `86bc9c6` |

None of these moves the gate. They do make each trace harder than it needs to be, and the Context ID
case shows where that leads: an oracle nobody maintains leaves you with a judgement call on every
change. Amend them together with whatever is decided for 5.3-AC1.

### 🟡 Finding 4 — Carried low-severity items, unchanged

- **Contract residual** (`sprint-status.yaml:123-126`): `include_contexts` on the upserter's
  `sync_grouped_products_first()`. Clear it after one real multi-context grouped sync. With the field
  hidden, fewer installs will ever set a context, so this residual matters less.
- **Required-but-unenforced connection field**: `skwirrel_base_url` is marked required but
  `sanitize_settings()` doesn't enforce it. `deferred-work.md:54` still calls it `skwirrel_subdomain`.
  Epic 5 retro action 6.

## Next Actions

1. **Decide the Context ID field** (gate unblock). Either unhide it, or retire `5.3-AC1` and amend
   `5.3-AC5` in `epics.md`. Both routes land at CONCERNS.
2. **Fix the hidden-field error trap** (Finding 1). Do this whichever way #1 goes, because the bug
   is independent of the AC wording.
3. **Add a visibility assertion** to the Epic 5 integration helpers (Finding 2), so the next
   `hidden` fails a test rather than a trace.
4. **Amend `epics.md`** for 5.1-AC1, 5.1-AC2, 5.2-AC1, 5.2-AC2 (Finding 3), in the same edit as #1.
5. Update `deferred-work.md:54` to the new field name (Finding 4).

---

## Gate Decision Summary

```
🚨 GATE DECISION: FAIL

📊 Coverage Analysis:
- P0 Coverage: 100% (Required: 100%) → MET
- P1 Coverage: 71% (PASS target: 90%, minimum: 80%) → NOT_MET
- Overall Coverage: 82% (Minimum: 80%) → MET

✅ Decision Rationale:
P1 coverage is 71% (minimum: 80%). 5.3-AC1 and 5.3-AC5 fell to PARTIAL when e8332bf hid the
Context ID field; 5.1-AC4 and 5.1-AC6 remain at the accepted E2E ceiling.

⚠️ Critical Gaps: 0

📝 Recommended Actions:
1. Decide the Context ID field — unhide, or retire/amend 5.3-AC1/AC5 (→ CONCERNS)
2. Fix the hidden-field error trap
3. Add a visibility assertion to the Epic 5 integration helpers

📂 Full Report: _bmad-output/test-artifacts/traceability/traceability-matrix-epic-5.md

🚫 GATE: FAIL - Release BLOCKED until coverage improves
```
