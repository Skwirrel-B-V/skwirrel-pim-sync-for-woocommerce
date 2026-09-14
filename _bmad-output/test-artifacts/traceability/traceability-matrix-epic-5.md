---
stepsCompleted:
  ['step-01-load-context', 'step-02-discover-tests', 'step-03-map-criteria', 'step-04-analyze-gaps', 'step-05-gate-decision']
lastStep: 'step-05-gate-decision'
lastSaved: '2026-09-14'
scope: 'Epic 5 — A settings screen you can navigate, trust, and verify'
tracedAtCommit: 'fa9ed8e'
workingTree: 'dirty — Epic 5 Context ID hidden-field fix, tab-strip restyle and sync_etim field are uncommitted; not yet released'
coverageBasis: 'acceptance_criteria'
oracleConfidence: 'high'
oracleResolutionMode: 'formal_requirements'
oracleInterpretation: 'epics.md as amended 2026-09-14 (owner decisions)'
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
tempCoverageMatrixPath: '/private/tmp/claude-501/-Users-joskoomen-Documents-Projects-Skwirrel-wordpress/f53d3b26-f99d-4404-8a95-4c7c2054f040/scratchpad/tea-trace-coverage-matrix-epic-5-2026-09-14-r2.json'
gateDecision: 'CONCERNS'
---

# Traceability Report — Epic 5

_Re-trace at `fa9ed8e` + working tree (2026-09-14, run 2). Supersedes this morning's trace at `20eaf3f`._

## Gate Decision: ⚠️ CONCERNS

**Rationale (deterministic rule 5):** P0 coverage is 100% and overall coverage is 91% (minimum 80%),
but P1 coverage is 86% against a 90% target. The two P1 shortfalls are still `5.1-AC4` (`#tab-{slug}`
deep link) and `5.1-AC6` (visible focus ring). Both can only be checked in a browser, and the closed
E2E decision in `deferred-work.md` holds them at CONCERNS.

**Unchanged from this morning.** No criterion changed status, and no test was lost.

**But the release picture changed.** Four commits landed after the last trace (`9fe8fd2`, `7ffb2d8`,
`46ed276`, `fa9ed8e`), and none of them carries the Epic 5 fix. The Context ID hidden-field fix, its
three tests and the visibility guard are **still uncommitted**. The green evidence for `5.3-AC1`,
`5.3-AC5` and `5.2-AC4` exists only in the working tree. Committed `HEAD` alone still has the
hidden-field trap from Finding 1 of the previous trace. See Finding 1.

## Coverage Summary

| Metric                    | Value                                        |
| ------------------------- | -------------------------------------------- |
| Total acceptance criteria | 22                                           |
| Fully covered             | 20 (91%)                                     |
| Partially covered         | 2                                            |
| Uncovered                 | 0                                            |
| P0 coverage               | **100%** (5/5): required 100% ✅             |
| P1 coverage               | **86%** (12/14): target 90%, minimum 80% ⚠️  |
| P2 coverage               | 100% (3/3) ✅                                |

## Coverage Oracle

Formal requirements, high confidence: `epics.md` Epic 5 **as amended 2026-09-14**, plus the four story
artifacts (all `status: done`). The oracle has not changed since this morning's trace. The AC IDs,
priorities and the six amendments recorded there (5.1-AC1, 5.1-AC2, 5.2-AC1, 5.2-AC2, 5.3-AC1 retired
and replaced, 5.3-AC5) all still apply.

## What changed since the last trace

| Change | Where | Epic 5 impact |
| --- | --- | --- |
| Overview page redesign | `fa9ed8e`, `class-skwirrel-wc-sync-admin-dashboard.php` | Outside Epic 5. The Danger zone card still deep-links to `?tab=settings#tab-danger-zone`. This is `5.1-AC4`'s one real consumer, and it is still never followed by a test. |
| Manual release of a stuck sync lock | `fa9ed8e`, `class-skwirrel-wc-sync-admin-settings.php` | Outside Epic 5. A new AJAX action with nonce and capability checks. |
| Debug page header/nav, design fixes | `9fe8fd2`, `7ffb2d8`, `46ed276` | Outside Epic 5. |
| **Tab strip restyle** (uncommitted) | `assets/settings-page.css` | Visual only. The focus ring still comes from `dashboard.css` `.skw-tab:focus-visible`. The restyle sets no `box-shadow`, so the ring survives. `.skw-settings-page .skw-tab:hover { background: transparent }` still beats the focus tint when a tab is both hovered and focused. `5.1-AC6` stays PARTIAL. |
| **Danger zone restyle** (uncommitted) | `render_danger_zone`: `.skw-dz-*` | Both forms still sit outside the settings form. *the danger zone stays outside and below the settings form* is green. |
| **`sync_etim` checkbox** (uncommitted) | Sync Options group → What to sync tab | New control inside an existing group. Covered by the input-name census and group map. Test hygiene fixed in this trace (Finding 2). |
| **Context ID hidden-field fix** (uncommitted) | dashboard + 3 tests + guard | Unchanged since this morning. Still not committed. |

## Test Inventory

| Level                          | Files | Cases | Notes                                           |
| ------------------------------ | ----: | ----: | ----------------------------------------------- |
| Unit                           |     5 |   122 | Stub bootstrap, no Docker                       |
| Integration (real WP + WC)     |     4 |    82 | wp-env. Counted as `other` in the JSON schema   |
| Component                      |     0 |     0 | No component layer in this stack                |
| E2E                            |     0 |     0 | No browser layer: **accepted ceiling**, closed  |

- `tests/Unit/SettingsTabsTest.php` (27) · `tests/Integration/SettingsTabsIntegrationTest.php` (19)
- `tests/Unit/AdminSettingsRequiredFieldsTest.php` (14) · `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` (26)
- `tests/Unit/ContextIdTest.php` (37) · `tests/Integration/ContextIdIntegrationTest.php` (17)
- `tests/Unit/TestConnectionMetricsTest.php` (28) · `tests/Integration/TestConnectionMetricsIntegrationTest.php` (20)
- `tests/Unit/AdminSettingsEndpointUrlTest.php` (16)

There are no skipped, pending or fixme cases. Every mapped test title was checked by string match against
its file. One mapping from the previous report named the wrong file and is corrected here: *every locale
ships a compiled catalogue next to its source* lives in `AdminSettingsRequiredFieldsTest.php`.

### Suite status at trace time

| Suite | Result |
| --- | --- |
| `vendor/bin/pest` (unit, whole repo) | ✅ **686 passed**, 1779 assertions |
| `npm run test:integration` (sole run on the test DB) | ✅ **240 passed**, 1 deprecated, 1786 assertions |
| Census test re-run after the Finding 2 edit | ✅ 1 passed, 48 assertions |
| `vendor/bin/phpstan analyse` | ✅ No errors |
| `vendor/bin/phpcs` | ✅ Clean |

The deprecation is in `SyncSafetyIntegrationTest`, outside Epic 5.

## Traceability Matrix

Legend: **FULL** = behaviour exercised · **PARTIAL** = asserted indirectly (source string) ·
**NONE** = no evidence. U = unit · I = integration. 🔧 = evidence exists only in the uncommitted working tree.

### Story 5.1 — Tabbed settings navigation

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.1-AC1**: every field in exactly one tab; groups re-home per the map; Danger zone outside the form | P1 | ✅ FULL | I: *each of the nine field groups sits in exactly the tab the re-home map names* · *the five tabs each own a panel; four settings panels live inside the form, Danger zone lives outside it* · *the danger zone stays outside and below the settings form* · U: *the registry ships the five settings tabs in a deterministic order*. Asserted **per field group**, not per control. See the LOW recommendation. |
| **5.1-AC2**: one request, no per-tab option writes, other tabs unchanged | **P0** | ✅ FULL | I: *every input name the pre-tabs settings form rendered is still rendered* (now includes `sync_etim`) · *saving from a non-default tab leaves unrelated stored settings unchanged* · *the tab strip sits outside the form and cannot submit it* · *the save button is inside the form but outside every panel, so it shows on every tab* |
| **5.1-AC3**: errored tab marked (not colour alone), first errored tab opens | P1 | ✅ FULL | I: *a tab holding a failing field is marked with a count, not colour alone* · *a sanitiser error is readable on the page, above the tab strip* · *a clean render carries no error notice and no badge* · U: *the first tab holding an error opens* |
| **5.1-AC4**: `#tab-{slug}` deep link opens that tab; no `?tab=` collision | P1 | 🟡 PARTIAL | I: *the tab behaviour rides on the existing admin script handle, adding no new asset* asserts the `#tab-` handling in the inline JS (`class-skwirrel-wc-sync-admin-settings.php:2415`). The link is never followed. The redesigned Overview's Danger zone card still links to `#tab-danger-zone`. Accepted ceiling. |
| **5.1-AC5**: no-JS degradation, nothing unreachable | P1 | ✅ FULL | I: *with no JavaScript every panel is a visible sequential section* |
| **5.1-AC6**: keyboard reachable/operable, visible focus ring, AT wiring | P1 | 🟡 PARTIAL | AT wiring is real: I: *the ARIA wiring resolves in both directions and exactly one tab is selected* · *the roving tabindex belongs to the selected tab, and only to it*. `ArrowRight` handling and the `:focus-visible` rule exist only as source strings. After the uncommitted restyle, the hover-plus-focus interaction noted this morning is unchanged. Accepted ceiling. |
| **5.1-AC7**: tab registration extensible without touching markup | P2 | ✅ FULL | U: *an external tab registers through the filter and lands at its order position* · I: *a tab registered with a named external renderer renders in its order position without touching the loop* · *an outside tab whose errors fire opens first, ahead of the built-in tabs* |

### Story 5.2 — Required markers and inline errors

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.2-AC1**: `*` + `aria-required` on unconditionally required fields | P1 | ✅ FULL | I: *an unconditionally required field is marked and announced as required* · *every marker on the screen is a named character, not colour alone* · U: *the unconditionally required fields are always required* |
| **5.2-AC2**: conditional marker matches the rule `sanitize_settings()` enforces | P1 | ✅ FULL | U: *the registry and sanitize_settings agree on every checkbox combination* · *super_category_id is required exactly when category sync is on* · I: *custom_collection_id is never required, whichever of the three consuming features is on* |
| **5.2-AC3**: messages sit next to their field and are programmatically associated | P1 | ✅ FULL | I: *a validation message renders at its field and is announced with it* · *every mapped error code renders at the field it names* · *a rejected value is kept in the field so it can be corrected* · U: *every error code maps to a field the settings screen knows about* |
| **5.2-AC4**: a failing field on a collapsed tab opens and marks that tab | P1 | ✅ FULL 🔧 | I: *the failing field ids are exposed to the tab strip as a seam* · *the failing field ids reach the browser through the localized seam* · guard 🔧: *no required or invalid field, and no inline error, sits inside a hidden element* |
| **5.2-AC5**: new strings translatable | P2 | ✅ FULL | U: *the strings this story added are in the POT and in all seven locales* · *every locale ships a compiled catalogue next to its source* |

### Story 5.3 — Context ID

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.3-AC1** _(amended)_: while hidden, a save carries the effective context, never re-raises a rejected value, never schedules a full re-sync | P1 | ✅ FULL 🔧 | I: *the Context ID field is hidden by default, and a configured context still round-trips on save* · *while hidden, a stored rejected Context ID is not sent back, so the next save clears its error* |
| **5.3-AC2**: value sent on every JSON-RPC call | **P0** | ✅ FULL | U: *no call site is left on a hardcoded context literal* · *getGroupedProducts carries the configured context* · I: *a configured Context ID reaches the product fetch of a real sync run* · *categories are fetched from the same context as the products* |
| **5.3-AC3**: empty ⇒ parameter omitted | **P0** | ✅ FULL | U: *an unset, empty or invalid Context ID resolves to null* · *getGroupedProducts sends no context parameter at all when none is configured* · I: *an unconfigured Context ID leaves the product fetch on the default context* |
| **5.3-AC4**: a changed Context ID arms `force_full_sync` and tells the admin | **P0** | ✅ FULL | U: *a changed effective context sets the force-full-sync flag and tells the admin* · *a rejected value arms no full sync* · I: *saving a changed effective context through update_option really sets the flag* · *a test-connection click preserves the Context ID and does not schedule a full re-sync* |
| **5.3-AC5** _(amended)_: rejected, not coerced; inline while shown, page notice only while hidden | P1 | ✅ FULL 🔧 | U: *an invalid Context ID raises a settings error and is stored exactly as typed* · I (shown): *a rejected Context ID comes back in the field with its message, and flags the Connection tab* · I (hidden) 🔧: *while hidden, a rejected Context ID neither renders an inline error nor flags the Connection tab* |

### Story 5.4 — Test Connection reports what came back

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.4-AC1**: round-trip time, status, API-reported product total | P1 | ✅ FULL | U: *a successful test reports timing, status and the API-reported total* · *the product total comes from the pagination block, not the returned array* · I: *a successful call carries measurement and the API-reported total* |
| **5.4-AC2**: zero products reads as a warning | P1 | ✅ FULL | U: *a zero total is a warning, not a success* · I: *zero products stays a success response and carries the warning in the tone* |
| **5.4-AC3**: a failure still reports timing and status | P1 | ✅ FULL | U: *a transport failure says there was no response instead of showing status 0* · *a JSON-RPC rejection is distinguishable from a transport failure* · I: *a transport failure counts every attempt and reports no HTTP response* |
| **5.4-AC4**: no extra writes; token appears nowhere in the output | **P0** | ✅ FULL | I: *the test writes nothing beyond the settings it already autosaved* · *the auth token is sent to the API but never appears in the response* · *an API error that reflects the auth token is redacted* · authz denied paths · U: *the formatter cannot leak the auth token because it never receives one* |
| **5.4-AC5**: tabular figures, strings translatable | P2 | ✅ FULL | U: *metric numbers render with tabular numerals* · *every new user-facing string is in the translation template* |

## Coverage Heuristics

| Dimension | Status | Finding |
| --- | --- | --- |
| Error paths | ✅ Strong | Transport, HTTP ≥400, JSON-RPC rejection, non-JSON, absent pagination, corrupt option. |
| Auth / authz | ✅ Strong | Denied-path tests on Test Connection. The new stuck-lock AJAX action is outside Epic 5. |
| UI state | ✅ Strong | Hidden-field state for `context_id` covered in both directions 🔧. |
| Visibility guard | 🟢 In place 🔧 | Green, but only in the working tree. |
| Back-compat | ✅ Strong | The input-name census stays green through the restyles and the new `sync_etim` control. |
| Per-control tab membership | 🟡 Advisory | "Every field in exactly one tab" is checked per group (9 groups). A control rendered outside any group but inside the form would show on every tab, and no test would notice. |
| E2E layer | ➖ Accepted ceiling | Caps `5.1-AC4` and `5.1-AC6`. |

## Findings

### 🟠 Finding 1: Epic 5's fix is still uncommitted while `HEAD` moves on (release risk)

This morning's trace fixed the hidden Context ID trap and added a visibility guard, "not yet released".
Since then, four commits landed on `release/4.0.0` and none of them includes that work. It is still in
the working tree, mixed with unrelated changes: `sync_etim`, the Danger zone and tab-strip restyles,
and the progress-banner notice.

- **Committed `HEAD` alone** would fail the 5.3-AC1 and 5.3-AC5 evidence. A rejected Context ID round-trips
  through the hidden input and re-raises its error on every save.
- **Risk:** a partial commit or stash before the 4.0.0 tag ships 4.0.0 with the trap.
- **Action:** commit the Epic 5 files (`class-skwirrel-wc-sync-admin-dashboard.php` context-id hunks,
  `ContextIdIntegrationTest.php`, `SettingsRequiredFieldsIntegrationTest.php`, `SkwirrelIntegrationTestCase.php`,
  `tests/Integration/bootstrap.php`) before the release.

This does not move the gate, which traces the tree as it is. It is the highest-priority item on the list.

### 🟢 Finding 2: `sync_etim` was added to the pre-tabs baseline census — **fixed**

The uncommitted `sync_etim` work added the key to `skwSettingsBaselineInputNames()`. That list is
documented as "a record of what the pre-tabs form rendered", and every later field (Context ID, Epic 6
mappings) is appended below it with a note. `sync_etim` now follows the same pattern
(`SettingsTabsIntegrationTest.php`). The assertion is unchanged, and the re-run is green (48 assertions).

### 🟡 Finding 3: Carried low-severity items

- **Contract residual:** `include_contexts` on the upserter's `sync_grouped_products_first()`. It matters
  less while the field is hidden.
- **Required-but-unenforced connection field:** `skwirrel_base_url` (Epic 5 retro action 6,
  `deferred-work.md`).
- **One integration run per wp-env test DB:** not yet noted in `tests/Integration/README.md`. This run
  was the only one on the test DB and came out clean.

## Next Actions

1. **Commit the Epic 5 working-tree changes and ship them through `/release`** (Finding 1).
2. LOW: add a guard that every `skwirrel_wc_sync_settings[...]` control has exactly one settings
   `tabpanel` ancestor, so 5.1-AC1 is enforced per control and not only per group.
3. Epic 5 retro action 6: decide and enforce what an unconfigured connection does on save.
4. Optional: `/bmad-testarch-test-review` on the nine Epic 5 test files.

None of these moves the gate. PASS stays out of reach because of the team's closed E2E decision.

---

## Gate Decision Summary

```
🚨 GATE DECISION: CONCERNS

📊 Coverage Analysis:
- P0 Coverage: 100% (Required: 100%) → MET
- P1 Coverage: 86% (PASS target: 90%, minimum: 80%) → PARTIAL
- Overall Coverage: 91% (Minimum: 80%) → MET

✅ Decision Rationale:
P0 coverage is 100% and overall coverage is 91% (minimum: 80%), but P1 coverage is 86%
(target: 90%). The shortfalls are 5.1-AC4 and 5.1-AC6, the accepted E2E ceiling.

⚠️ Critical Gaps: 0

📝 Recommended Actions:
1. Commit the uncommitted Epic 5 fix before tagging 4.0.0, then /release
2. Per-control tab-membership guard for 5.1-AC1
3. Epic 5 retro action 6: enforce the connection field on save

📂 Full Report: _bmad-output/test-artifacts/traceability/traceability-matrix-epic-5.md

⚠️ GATE: CONCERNS - Proceed with caution, address gaps soon
```
