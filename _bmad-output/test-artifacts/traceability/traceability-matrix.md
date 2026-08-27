---
stepsCompleted:
  ['step-01-load-context', 'step-02-discover-tests', 'step-03-map-criteria', 'step-04-analyze-gaps', 'step-05-gate-decision']
lastStep: 'step-05-gate-decision'
lastSaved: '2026-08-27'
scope: 'Epic 5 — A settings screen you can navigate, trust, and verify'
tracedAtCommit: 'db22f76'
coverageBasis: 'acceptance_criteria'
oracleConfidence: 'high'
oracleResolutionMode: 'formal_requirements'
oracleSources:
  - '_bmad-output/planning-artifacts/epics.md#epic-5'
  - '_bmad-output/implementation-artifacts/5-1-tabbed-settings-navigation.md'
  - '_bmad-output/implementation-artifacts/5-2-required-field-markers-and-inline-errors.md'
  - '_bmad-output/implementation-artifacts/5-3-context-id.md'
  - '_bmad-output/implementation-artifacts/5-4-test-connection-metrics.md'
externalPointerStatus: 'not_used'
collectionStatus: 'COLLECTED'
gateDecision: 'CONCERNS'
tempCoverageMatrixPath: '/private/tmp/claude-501/-Users-joskoomen-Documents-Projects-Skwirrel-wordpress/6dc3fb87-c5bc-46f9-834f-f71b67055645/scratchpad/tea-trace-coverage-matrix-20260827.json'
---

# Traceability Report — Epic 5

_Re-trace at `db22f76` (2026-08-27). Supersedes the 2026-08-26 trace at `c0d8c65`._

## Gate Decision: ⚠️ CONCERNS

**Rationale (deterministic rule 5):** P0 coverage is 100% and overall coverage is 91% (minimum 80%),
but P1 coverage is 86% against a 90% target. The two P1 shortfalls are unchanged from the previous
trace: `5.1-AC4` and `5.1-AC6` are verified by asserting strings in JavaScript and CSS source rather
than by exercising behaviour.

**Read this caveat with the decision.** The gate rules score *coverage*, not *reliability*. This run
found the integration suite to be **order-dependent and non-deterministic** — the layer that carries
most of Epic 5's evidence. On a coverage-only reading the gate is CONCERNS; on the evidence a
reasonable person would want, it is weaker than that number suggests. See Finding 1.

## Coverage Summary

| Metric                    | Value                                        |
| ------------------------- | -------------------------------------------- |
| Total acceptance criteria | 22                                           |
| Fully covered             | 20 (91%)                                     |
| Partially covered         | 2                                            |
| Uncovered                 | 0                                            |
| P0 coverage               | **100%** (5/5) — required 100% ✅            |
| P1 coverage               | **86%** (12/14) — target 90%, minimum 80% ⚠️ |
| P2 coverage               | 100% (3/3) ✅                                |

Coverage percentages are **unchanged** from the 2026-08-26 trace. What changed is the state of the
evidence behind them, and the status of the three open actions that trace raised.

## Coverage Oracle

Formal requirements, high confidence. Epic 5's four stories carry explicit Given/When/Then acceptance
criteria in `epics.md`, mirrored by per-story implementation artifacts. No synthetic inference; no
external pointer resolved.

## Test Inventory

| Level       | Files | Cases | Notes                                                    |
| ----------- | ----: | ----: | -------------------------------------------------------- |
| Unit        |     4 |    86 | Stub bootstrap, no Docker                                 |
| Integration |     4 |    77 | Real WP + WC via wp-env                                   |
| Component   |     0 |     0 | No component layer in this stack                          |
| E2E         |     0 |     0 | No browser layer — documented stack constraint            |

Epic 5 test files (case counts as of this run):

- `tests/Unit/SettingsTabsTest.php` (27) · `tests/Integration/SettingsTabsIntegrationTest.php` (19)
- `tests/Unit/AdminSettingsRequiredFieldsTest.php` (14) · `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` (25)
- `tests/Unit/ContextIdTest.php` (17) · `tests/Integration/ContextIdIntegrationTest.php` (23)
- `tests/Unit/TestConnectionMetricsTest.php` (28) · `tests/Integration/TestConnectionMetricsIntegrationTest.php` (20)

### Suite status at trace time

| Suite | Result |
| --- | --- |
| `vendor/bin/pest` (unit, whole repo) | ✅ **608 passed**, 1478 assertions, 6.25s — no skips, no fixmes |
| `npm run test:integration` (whole repo) | ❌ **40 failed**, 175 passed, 1610 assertions — see Finding 1 |
| Epic 5 integration files run **in isolation** | ✅ 19 + 25 + 23 + 20 = 87 passed |

The isolated numbers are what the matrix below is scored on. The full-run number is why the caveat
above exists.

## Traceability Matrix

Per-AC mappings are **unchanged** from the 2026-08-26 trace and are not restated in full here; that
document's matrix remains accurate as to which test asserts which criterion. Only the deltas follow.

### Deltas since `c0d8c65`

| AC | Was | Now | What changed |
| --- | --- | --- | --- |
| 5.1-AC1 | FULL | FULL | The tab set grew to five (Epic 6's *Field mapping* tab, which FR-3-amended always specified). The re-home-map test accommodated it; three sibling assertions did not — see Finding 2. |
| 5.1-AC5 | FULL | FULL | Evidence *(with no JavaScript every panel is a visible sequential section)* was **red** on arrival, restored in this run. |
| 5.1-AC6 | PARTIAL | PARTIAL | Its real-markup half *(ARIA wiring, roving tabindex)* was **red** on arrival, restored in this run. The source-string half is unchanged and still caps this AC. |
| 5.4-AC4 | FULL | FULL | The four authorization denied-path tests added on 2026-08-26 are present and green in isolation (20/20). |
| All others | — | — | No change. |

## Coverage Heuristics

| Dimension | Status | Finding |
| --- | --- | --- |
| Error paths | ✅ Strong | Unchanged — transport failure, HTTP ≥400, JSON-RPC rejection, non-JSON body, absent pagination, empty message, retry counting, clamped timing. |
| UI state | ✅ Strong | Validation, error, no-JS, clean-render, zero-result and legacy-transient states asserted against real rendered markup. |
| Back-compat | ✅ Strong | Legacy transient rendering plus the pre-tabs input-name census. |
| Secret handling | ✅ Strong | Redaction covered on both the formatter and the shared payload pipeline. |
| Auth / authz | ✅ Closed | Four denied-path tests (absent nonce, forged nonce, subscriber with valid nonce, SSRF pin) present and green. Closed 2026-08-26, verified here. |
| Endpoint contract | 🟡 Narrowed | Was a gap. `gh#46` records **“Unblocked 2026-08-25: the parameter is `include_contexts`”** — the parameter name is confirmed. The residual is narrower than previously reported: whether **`getGroupedProducts` specifically** accepts it. `sprint-status.yaml:113` still carries the stale unverified marker. |
| **Suite reliability** | 🔴 **New gap** | The integration layer does not produce the same result twice. See Finding 1. |
| E2E layer | ➖ Absent | No browser-driven layer. A documented stack constraint, and still what caps 5.1-AC4 and 5.1-AC6. Retro action 5 (“decide the E2E question explicitly”) is still open — no written decision exists. |

## Findings

### 🔴 Finding 1 — The integration suite is order-dependent and non-deterministic

This is the headline result of the re-trace, and it is new since 2026-08-26 (that run recorded a
clean 179 passed).

Measured:

| Run | Result |
| --- | --- |
| Full suite, run A | 47 failed / 168 passed |
| Full suite, run B (after the Finding 2 fix) | 40 failed / 175 passed |
| `ContextIdIntegrationTest` alone | 23 passed — then, on a repeat, 22 passed / 1 failed |
| `SettingsRequiredFieldsIntegrationTest` alone | 3 failed — then, on a repeat, 1 failed, on a different test |
| `SyncSafetyIntegrationTest > empty cross_sells…` alone | passed (fails in the full run) |
| `TestConnectionMetricsIntegrationTest` alone | 20 passed (3 of them fail in the full run) |

The failing **set changes between runs**. Tests that fail inside the suite pass on their own, and one
file produced two different failures on two consecutive isolated runs. The failures span suites that
have nothing to do with each other — Epic 6 stock mapping, membership sweep, purge, cross-sells,
Context ID, Test Connection.

This is not a product defect and I found no evidence of one behind any of these failures. It is
leaked state between tests. The retrospective already names the cause and the fix: **retro action 4,
“Fix the integration-suite DB isolation binding”, deferred since 3.13.0.** This trace is the second
data point it predicted, now with numbers.

**Why it matters for this gate:** three of Epic 5's five P0 criteria (`5.3-AC2`, `5.3-AC3`,
`5.4-AC4`) have integration evidence that fails inside the suite. Each retains green *unit* coverage,
which is why P0 still scores 100% — but the layer that proves the behaviour against real WordPress
cannot currently be trusted to report the same answer twice, and a real regression arriving in that
noise would not be noticed.

**Action:** raise retro action 4 to blocking, ahead of further Epic 6 test growth.

### 🟠 Finding 2 — Epic 6's fifth tab broke three Epic 5 assertions (fixed in this run)

`SettingsTabsIntegrationTest` hardcoded a four-tab settings screen. Epic 6 added the *Field mapping*
tab — which `epics.md` FR-3-amended specifies explicitly (“four intent groups **plus a fifth Field
mapping tab**”), so the product is right and the tests were stale. The update had been started and
left half-done: `aria-controls` was already counted as 5 while its sibling counts still read 3 and 4.

Deterministic, reproducible, and red on arrival:

| Test | Assertion | Was | Now |
| --- | --- | --- | --- |
| *the ARIA wiring resolves in both directions…* | `aria-selected="false"` count | 3 | 4 |
| *the ARIA wiring resolves in both directions…* | `tabindex="-1"` count | 3 | 4 |
| *with no JavaScript every panel is a visible sequential section* | `role="tabpanel"` count | 4 | 5 |
| *the roving tabindex belongs to the selected tab…* | `role="tab"` count | 4 | 5 |

Fixed here per the project's standing rule that gate findings are fixed on sight, and per
“don't weaken a test to make it pass — if behavior changed intentionally, update the assertion
deliberately and say so.” The intent (exactly one selected tab, no panel hidden server-side, roving
tabindex on the selected tab only) is preserved; only the arity moved. `SettingsTabsIntegrationTest`
is now 19/19 green and `phpcs` clean on the file.

This also means Epic 5's 5.1-AC5 and 5.1-AC6 evidence was **red on the branch** between Epic 6
landing and this trace, and nothing flagged it — a direct consequence of the CI gap already recorded
in `deferred-work.md` (CI runs `--testsuite=Unit` only, so no integration regression can fail a
build).

### 🟡 Finding 3 — Open actions from the last trace, re-checked

| # | Action | Status |
| --- | --- | --- |
| 1 | Confirm the Context ID API parameter name | 🟢 **Largely closed** — `gh#46` records the parameter as `include_contexts`, unblocked 2026-08-25. Residual: `getGroupedProducts` acceptance specifically, and the stale marker at `sprint-status.yaml:113`. |
| 2 | Authorization denied-path tests | 🟢 **Done** — four tests, green in isolation. |
| 3 | Decide the ceiling for 5.1-AC4 / 5.1-AC6 | 🔴 **Still open** — retro action 5 asked for a written decision either way and none exists. This is the sole reason P1 sits at 86%, and it will not resolve itself. |

### 🟡 Finding 4 — Required-but-unenforced fields (carried, not regressed)

`skwirrel_subdomain` is marked required client-side with no server-side rule; `sanitize_settings()`
checks neither it nor the auth token. `AdminSettingsRequiredFieldsTest` excludes it from the
registry-vs-sanitiser agreement test with a comment saying why. Correctly scoped out of story 5.2,
recorded in `deferred-work.md`, and tracked as retro action 6. It does not reduce 5.2's AC coverage —
the ACs are about *marking*, and marking is covered — but it is the one place where the epic's theme
(“a settings screen you can trust”) is not yet true end-to-end.

## Next Actions

1. **Fix the integration-suite DB isolation binding** (retro action 4). Now the largest risk to every
   integration-level claim in this matrix. Blocking, before Epic 6 grows the suite further.
2. **Add an integration job to required CI.** Finding 2 sat red on the branch undetected because CI
   runs the unit suite only. Already recorded twice in `deferred-work.md`.
3. **Write the 5.1-AC4 / 5.1-AC6 decision down** — stand up Playwright, or accept source-string
   assertion as the ceiling in the ledger. Either closes the gate item; deferring does not.
4. **Clear or narrow the `sprint-status.yaml:113` marker** now that `include_contexts` is confirmed.
5. Re-run this trace once 1 and 3 land.

## Gate Criteria

| Criterion        | Required      | Actual | Status     |
| ---------------- | ------------- | ------ | ---------- |
| P0 coverage      | 100%          | 100%   | ✅ MET     |
| P1 coverage      | 90% (min 80%) | 86%    | ⚠️ PARTIAL |
| Overall coverage | ≥ 80%         | 91%    | ✅ MET     |
| Critical gaps    | 0             | 0      | ✅ MET     |

**Decision:** CONCERNS — proceed, address the gaps. Not a release blocker on coverage grounds.

**Scope.** This gate covers **Epic 5 only**, traced at `db22f76`. Epic 6 (stories 6.1–6.5) has landed
on this branch, has had no traceability run, and holds no gate. Several of the full-run integration
failures observed here are in Epic 6 files; they are unexamined by this trace and must not be read as
cleared by it. Re-run the workflow against Epic 6 before treating this branch as traced.
