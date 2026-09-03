---
stepsCompleted:
  ['step-01-load-context', 'step-02-discover-tests', 'step-03-map-criteria', 'step-04-analyze-gaps', 'step-05-gate-decision']
lastStep: 'step-05-gate-decision'
lastSaved: '2026-09-02'
scope: 'Epic 5 — A settings screen you can navigate, trust, and verify'
tracedAtCommit: '4044e22'
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
collectionMode: 'contract_static'
collectionStatus: 'COLLECTED'
summaryConfidence: 'high'
gateDecision: 'CONCERNS'
---

# Traceability Report — Epic 5

_Re-trace at `4044e22` (2026-09-02). Supersedes the 2026-08-27 trace at `db22f76`._

## Gate Decision: ⚠️ CONCERNS

**Rationale (deterministic rule 5):** P0 coverage is 100% and overall coverage is 91% (minimum 80%),
but P1 coverage is 86% against a 90% target. The two P1 shortfalls are `5.1-AC4` and `5.1-AC6`,
verified by asserting strings in JavaScript and CSS source rather than by exercising behaviour.

**The numbers are unchanged from 2026-08-27. Everything behind them changed.** The previous trace
carried a caveat — the integration layer holding most of Epic 5's evidence could not produce the same
result twice, so the coverage figure was better than the evidence deserved. That caveat is gone.
The suite is deterministic, it runs in CI, and the sole remaining reason P1 sits at 86% is now a
**written, closed decision** rather than an open action.

This is a CONCERNS the team chose. `deferred-work.md` (Epic 6 retrospective, 2026-08-27) closes the
E2E question with source-string assertion as the accepted ceiling, and states the consequence
verbatim: _"any acceptance criterion that can only be verified in a browser will be marked partial in
a traceability trace and will hold a gate at CONCERNS rather than PASS. That is the accepted cost."_

**WAIVED was considered and rejected.** The owner's own decision prescribes CONCERNS as the intended
outcome, so recording a waiver would overwrite the decision it is meant to honour.

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

## Coverage Oracle

Formal requirements, high confidence. Epic 5's four stories carry explicit Given/When/Then acceptance
criteria in `epics.md`, mirrored by per-story implementation artifacts (all four `status: done`). No
synthetic inference; no external pointer resolved. AC IDs and priorities are carried forward
unchanged from the 2026-08-26 and 2026-08-27 traces.

## Test Inventory

| Level       | Files | Cases | Notes                                          |
| ----------- | ----: | ----: | ---------------------------------------------- |
| Unit        |     5 |   122 | Stub bootstrap, no Docker                      |
| Integration |     4 |    78 | Real WP + WC via wp-env                        |
| Component   |     0 |     0 | No component layer in this stack               |
| E2E         |     0 |     0 | No browser layer — **accepted ceiling**, closed |

Epic 5 test files (case counts as of this run):

- `tests/Unit/SettingsTabsTest.php` (27) · `tests/Integration/SettingsTabsIntegrationTest.php` (19)
- `tests/Unit/AdminSettingsRequiredFieldsTest.php` (14) · `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` (25)
- `tests/Unit/ContextIdTest.php` (37) · `tests/Integration/ContextIdIntegrationTest.php` (14)
- `tests/Unit/TestConnectionMetricsTest.php` (28) · `tests/Integration/TestConnectionMetricsIntegrationTest.php` (20)
- `tests/Unit/AdminSettingsEndpointUrlTest.php` (16) — new since the last trace; supporting evidence
  for `5.2-AC1` after the connection field changed shape (see Finding 2)

No skipped, pending or fixme cases in any Epic 5 file.

`ContextIdTest` grew 17 → 37 and `ContextIdIntegrationTest` shrank 23 → 14: the review-fix commits
(`9128bd7`, `dbb7571`, `76e3463`, `59d124a`, `3f94e48`) pushed the run-freezing and call-site census
assertions down to the unit layer, where they are deterministic and cheaper, and left the integration
file asserting what only real WordPress can answer.

### Suite status at trace time

| Suite | Result |
| --- | --- |
| `vendor/bin/pest` (unit, whole repo) | ✅ **664 passed**, 1590 assertions, 0.86s |
| `npm run test:integration` (whole repo, run A) | ✅ **233 passed**, 1767 assertions, 127.9s |
| `npm run test:integration` (whole repo, run B) | ✅ **233 passed**, 1767 assertions, 92.3s |
| `vendor/bin/phpstan analyse` | ✅ No errors |
| `vendor/bin/phpcs` | ✅ Clean, 34/34 files |

Two full integration runs, identical results. The matrix below is scored on the **full suite**, not
on isolated runs — the first trace in this epic's history where that is true.

## Traceability Matrix

Legend: **FULL** = behaviour exercised · **PARTIAL** = asserted indirectly (source string) ·
**NONE** = no evidence.

### Story 5.1 — Tabbed settings navigation

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.1-AC1** — every field belongs to exactly one tab; the eight groups re-home per the map; Danger Zone stays outside | P1 | ✅ FULL | I: *each of the nine field groups sits in exactly the tab the re-home map names* · *the five tabs each own a panel, and every panel lives inside the one settings form* · *the danger zone stays outside and below the settings form* · U: *the registry ships the five settings tabs in a deterministic order* · *every default tab has a renderer that exists on the dashboard class* |
| **5.1-AC2** — one request, no per-tab option writes, other tabs unchanged | **P0** | ✅ FULL | I: *every input name the pre-tabs settings form rendered is still rendered* · *saving from a non-default tab leaves unrelated stored settings unchanged* · *the tab strip sits outside the form and cannot submit it* · *the save button is inside the form but outside every panel* · *the permalinks group renders its own AJAX control and submits nothing with the form* · U: *a filter cannot remove or replace a built-in panel and drop its settings from the form* |
| **5.1-AC3** — errored tab marked (not colour alone), first errored tab opens | P1 | ✅ FULL | I: *a tab holding a failing field is marked with a count, not colour alone* · *a sanitiser error is readable on the page, above the tab strip* · *a clean render carries no error notice and no badge* · U: *the first tab holding an error opens, overriding the default tab* · *with errors on several tabs the first one in tab order opens* · *several errors on one tab are counted, not collapsed* · *an errored slug that is not in the registry cannot steal the opening tab* |
| **5.1-AC4** — `#tab-{slug}` deep link opens that tab; no collision with `?tab=` | P1 | 🟡 **PARTIAL** | I: *the tab behaviour rides on the existing admin script handle* asserts `'#tab-'` appears in the enqueued JS (line 652). The fragment-vs-query-var separation is proven; **the deep link is never followed.** Accepted ceiling. |
| **5.1-AC5** — no-JS degradation, nothing unreachable | P1 | ✅ FULL | I: *with no JavaScript every panel is a visible sequential section* — asserted against real rendered markup, five `role="tabpanel"` panels, none hidden server-side |
| **5.1-AC6** — keyboard reachable/operable, visible focus ring, AT wiring | P1 | 🟡 **PARTIAL** | AT wiring is real: I: *the ARIA wiring resolves in both directions and exactly one tab is selected* · *the roving tabindex belongs to the selected tab, and only to it*. Keyboard **operation** is `'ArrowRight'` in the JS source (line 654) and the focus ring is `.skw-tab:focus-visible` in the CSS (line 675). **No key is ever pressed.** Accepted ceiling. |
| **5.1-AC7** — tab registration is extensible without touching this markup | P2 | ✅ FULL | U: *an external tab registers through the filter and lands at its order position* · *a tab registered without an order sorts last* · *tabs sharing an order keep their registration order* · *malformed registrations are dropped* · *a non-array filter result falls back to the built-in registry* · *a closure renderer survives normalisation* · I: *a tab registered with a named external renderer renders in its order position without touching the loop* · *an outside tab whose errors fire opens first*. Proven in production by Epic 6's Field mapping tab. |

### Story 5.2 — Required markers and inline errors

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.2-AC1** — `*` + `aria-required` on every unconditionally required field; the bare-HTML5 fields brought into the same treatment | P1 | ✅ FULL | I: *an unconditionally required field is marked and announced as required* · *the required marker carries a literal character and a name, not colour alone* · *every marker on the screen is a named character, not colour alone* · *no control blocks submit without a marker and a registry entry behind it* · *no field that validation can reject calls itself optional in its label* · U: *the unconditionally required fields are always required*. See Finding 2 on the field rename. |
| **5.2-AC2** — conditional markers match the rule `sanitize_settings()` enforces | P1 | ✅ FULL | U: *the registry and sanitize_settings agree on every checkbox combination* (the anti-drift test) · *super_category_id is required exactly when category sync is on* · *custom_collection_id is required exactly when any consuming feature is on* · *the rule table covers exactly the conditionally required fields* · *each governing key makes its field required on its own* · I: *super_category_id is not required while category sync is off* / *becomes required as soon as category sync is on* · *custom_collection_id follows each of the three features that consume it* · *custom_collection_id carries no required state at all while nothing consumes it* · *the marker toggle reads its conditions off the markup and keeps no copy of them* |
| **5.2-AC3** — messages render adjacent to their field and are programmatically associated | P1 | ✅ FULL | I: *a validation message renders at its field and is announced with it* · *every message for one field renders inline and is described by the input* · *the same message appears once as a summary and once at the field* · *every mapped error code renders at the field it names* · *an inline message is added to the field description, not swapped in for the hint* · *a field that passed validation carries no invalid state* · *a rejected value is kept in the field so it can be corrected* · U: *every error code maps to a field the settings screen knows about* |
| **5.2-AC4** — a failing field on a collapsed tab opens and marks that tab | P1 | ✅ FULL | I: *the failing field ids are exposed to the tab strip as a seam* · *the failing field ids reach the browser through the localized seam* · *the localized seam is empty when nothing failed* · plus 5.1-AC3's tab-marking evidence · U: *the three sanitiser error codes all resolve to the What to sync tab* · *representative field ids resolve to the tab that actually renders them* |
| **5.2-AC5** — new strings translatable under `skwirrel-pim-sync` | P2 | ✅ FULL | U: *the strings this story added are in the POT and in all seven locales* · *every locale ships a compiled catalogue next to its source* |

### Story 5.3 — Context ID

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.3-AC1** — optional Context ID field, placeholder `1`, help text | P1 | ✅ FULL | I: *the Context ID renders as an optional whole-number field on the Connection tab* · *the Context ID has a label and a hint that explains what leaving it empty does* · U: *the Context ID is optional — it is not in the required-field registry* |
| **5.3-AC2** — the value is sent on `getProducts`, `getProductsByFilter` and `getGroupedProducts` alike | **P0** | ✅ FULL | U: *getGroupedProducts carries the configured context* · *getCategories carries the configured context* · *the selection membership sweep carries the configured context* · *the connection test probes the configured context* · **_no call site is left on a hardcoded context literal_** (the census test) · *the grouped-product fetch follows the same frozen context* · *each resumable step re-applies the run context to the upserter* · I: *a configured Context ID reaches the product fetch of a real sync run* · *categories are fetched from the same context as the products* · *the status discovery request uses a configured Context ID without changing the default request* |
| **5.3-AC3** — empty ⇒ parameter omitted; existing installs behave exactly as before | **P0** | ✅ FULL | U: *an unset, empty or invalid Context ID resolves to null so call sites keep their current behaviour* · *getGroupedProducts sends no context parameter at all when none is configured* (3-case dataset) · *the selection membership sweep keeps its existing empty options when no context is configured* · *a frozen run with no context configured sweeps without the parameter* · *a run with no context configured still asks for the default category context* · I: *an unconfigured Context ID leaves the product fetch on the default context*. **Verified against the pre-story baseline (`7737f8f`):** the six call sites that hardcoded `include_contexts => [1]` still send `[1]`; the sites that sent nothing still send nothing. "Exactly as before" is literally true per call site — the asymmetry is deliberate and tested. |
| **5.3-AC4** — a changed Context ID arms `force_full_sync` and tells the admin | **P0** | ✅ FULL | U: *a changed effective context sets the force-full-sync flag and tells the admin* · *an unchanged effective context leaves the flag alone and shows nothing* · *a rejected value arms no full sync, because nothing it controls changed* · *clearing the field is a valid choice, not a rejection* · *an install saved before the effective key existed keeps its stored context* · *a legacy install carrying an invalid stored value reads as the default context* · I: *saving a changed effective context through update_option really sets the flag* · *a save that leaves the effective context alone never schedules a full re-sync* · *changing an unrelated setting never schedules a full re-sync* · *a test-connection click preserves the Context ID and does not schedule a full re-sync* · *the effective Context ID is part of the change-gate signature, so a change reprocesses every product* · *a display-only Context ID edit does not reprocess the catalogue* |
| **5.3-AC5** — non-numeric/negative rejected with an inline error, not coerced | P1 | ✅ FULL | U: *an invalid Context ID raises a settings error and is stored exactly as typed* · *a rejected Context ID is rendered in a control that can actually show it* · *a rejected value never moves the context the plugin syncs with* · *the message names the context that stays in use* · *get_context_ids survives a corrupt settings option* · I: *a rejected Context ID comes back in the field with its message, and flags the Connection tab* · *a valid Context ID round-trips through the screen with no error* |

### Story 5.4 — Test Connection reports what came back

| AC | Pri | Status | Evidence |
| --- | --- | --- | --- |
| **5.4-AC1** — reports round-trip time, status, and the API-reported product total | P1 | ✅ FULL | U: *a successful test reports timing, status and the API-reported total* · *the product total comes from the pagination block, not the returned array* · *a missing or non-numeric pagination total resolves to unknown* · *an unknown total is reported as unavailable and never fabricated* · *the metrics are announced round-trip first, then status, then products* · *retries are reported so a slow result is not mistaken for one slow request* · I: *a successful call carries measurement and the API-reported total* · *a successful test answers with tone, headline and metric lines* · *an API build without a pagination block reports the total as unknown* |
| **5.4-AC2** — zero products reads as a warning, not an unqualified success | P1 | ✅ FULL | U: *a zero total is a warning, not a success* · *the warning tone is distinguished by more than colour* · *the tone values are stable machine constants, not translated copy* · I: *zero products stays a success response and carries the warning in the tone* · *a warning result renders as a WordPress warning notice with its metric lines* |
| **5.4-AC3** — a failure still reports timing and status, so a timeout ≠ a rejection | P1 | ✅ FULL | U: *a transport failure says there was no response instead of showing status 0* · *an HTTP error reports the status alongside the message* · *a JSON-RPC rejection is distinguishable from a transport failure* · *a failure with no message still gets a headline* · *the status wording flips to a bare HTTP code exactly at 400* · *nonsensical measurement is clamped rather than rendered* · I: *a JSON-RPC rejection still reports the HTTP status it was rejected with* · *an HTTP error response reports its status and is not retried* · *a body that is not JSON still comes back measured* · *a transport failure counts every attempt and reports no HTTP response* · *a failed test answers with an error tone and still reports timing and status* |
| **5.4-AC4** — no writes beyond the existing autosave; the token appears nowhere in output | **P0** | ✅ FULL | I: *the test writes nothing beyond the settings it already autosaved* · *the auth token is sent to the API but never appears in the response* · *an API error that reflects the auth token is redacted before the JSON response* · *an API-supplied error message is escaped in the notice* · authz denied paths: *a request carrying no nonce at all is refused, writes nothing and calls nothing* · *a request carrying a forged nonce is refused…* · *a signed-in subscriber holding a valid nonce is refused with 403* · *a refused request never becomes a server-side request to the URL it supplied* · U: *the formatter cannot leak the auth token because it never receives one* · *the shared payload pipeline redacts a credential reflected by the API* · *both test paths use one shared secret-safe formatter pipeline* |
| **5.4-AC5** — tabular figures, all new strings translatable | P2 | ✅ FULL | U: *metric numbers render with tabular numerals* · *every new user-facing string is in the translation template* · *the new strings are wrapped with the literal text domain* · *the result renderer builds DOM nodes and never assigns innerHTML* |

## Coverage Heuristics

| Dimension | Status | Finding |
| --- | --- | --- |
| Error paths | ✅ Strong | Transport failure, HTTP ≥400, JSON-RPC rejection, non-JSON body, absent pagination, empty message, retry counting, clamped timing, corrupt settings option. |
| UI state | ✅ Strong | Validation, error, no-JS, clean-render, zero-result and legacy-transient states asserted against real rendered markup. |
| Back-compat | ✅ Strong | Pre-tabs input-name census · legacy transient rendering · per-call-site pre-story context baseline · legacy installs with absent or invalid stored context. |
| Secret handling | ✅ Strong | Redaction covered on the formatter, the shared payload pipeline, and the rendered notice. |
| Auth / authz | ✅ Closed | Four denied-path tests, green in the full suite. |
| Endpoint contract | 🟢 **Closed** | `gh#46` is closed: the parameter is `include_contexts` and was **already hardcoded at six call sites** before this story, so the API demonstrably accepts it. Residual is now cosmetic — see Finding 3. |
| **Suite reliability** | 🟢 **Closed** | Was the previous trace's headline gap. Two consecutive full runs, 233/233 both times, identical. Fixed by `14c07bd` (teardown-based isolation via `Skwirrel_Integration_TestCase`) and now guarded by a CI integration job (`3e2a833`). |
| E2E layer | ➖ **Accepted ceiling** | No browser layer, by written decision. Caps `5.1-AC4` and `5.1-AC6` and nothing else. |

## Findings

### 🟢 Finding 1 — Everything the last trace raised is now closed

The 2026-08-27 trace left four items. All four resolved:

| # | Item | Status |
| --- | --- | --- |
| 1 | **Integration suite order-dependent** (retro action 4) — 40 failures in a full run, different set each time | 🟢 **Closed.** `14c07bd` replaced the never-bound `WP_UnitTestCase` rollback with per-file teardown in `Skwirrel_Integration_TestCase`. Two full runs here: 233/233, identical. The Epic 6 retro also corrected the original diagnosis — `WP_UnitTestCase` cannot bind under Pest 3 at all (PHPUnit 10 removed `parseTestMethodAnnotations()`), so the prescribed load-path fix would have produced a fatal, not transactions. `tests/Integration/README.md` now states the truth. |
| 2 | **No integration job in CI** — Finding 2 of the last trace sat red on the branch undetected | 🟢 **Closed.** `3e2a833` added an `integration` job to `.github/workflows/ci.yml` running `npm run test:integration` on every PR. |
| 3 | **No written decision on the E2E ceiling** (retro action 5) — sole reason P1 sat at 86% | 🟢 **Closed as a decision, not as coverage.** `deferred-work.md` records it under *CLOSED DECISION (Epic 6 retrospective)*: source-string assertion is the accepted ceiling, re-open only deliberately. It names the gate consequence in advance. |
| 4 | **Epic 6's fifth tab broke three Epic 5 assertions** | 🟢 **Closed.** Fixed in the last trace (`1cdf9f3`), still green here, and now regression-guarded by the CI integration job. |

The two P1 shortfalls survive — but they are the only thing left, and they are there on purpose.

### 🟠 Finding 2 — `epics.md` no longer describes the screen it specifies (documentation drift)

Two of Epic 5's ACs quote field and tab identities that the product has since moved past. Both are
correct product changes with tests that tracked them; the **epic text is stale**, not the code.

| AC | Epic text says | Product does | Landed in |
| --- | --- | --- | --- |
| 5.1-AC1 | "exactly one of **four** tabs" | **five** tabs — Epic 6's *Field mapping* joined via 5.1-AC7's registry, which is the extension point working as designed | Epic 6 |
| 5.2-AC1 | the three bare-HTML5 fields are "`subdomain`, `super_category_id`, `collection_ids`" | the field is **`skwirrel_base_url`** and takes a full address, not a subdomain | `4044e22` |

Neither weakens coverage: the tab tests assert five, and both Epic 5 integration files assert
`skwirrel_base_url` by its new id (`SettingsRequiredFieldsIntegrationTest:92`,
`SettingsTabsIntegrationTest:302`), joined by the new `AdminSettingsEndpointUrlTest` (16 cases on
normalisation, scheme healing, `/jsonrpc` idempotence and host handling).

The risk is to the *next* reader, not to this build: an AC that names a field which no longer exists
will eventually be re-implemented or mis-verified. **Recommend amending both ACs in `epics.md` in
place**, the way FR-3 was amended for the fifth tab.

### 🟡 Finding 3 — One stale marker left, and a genuinely external residual

`sprint-status.yaml:118` still reads _"getGroupedProducts context param still unverified against the
API"_. That is now narrower than it sounds, and partly wrong:

- `gh#46` is **closed** and records that `include_contexts` was already hardcoded to `[1]` at six
  call sites before this story — including the service's own grouped-products fetch
  (`class-skwirrel-wc-sync-service.php:2197`, pre-story baseline line 2087). The API accepts the
  parameter there; that much is settled by the code that shipped before Epic 5.
- The genuine residual is one call site: the **upserter's** `sync_grouped_products_first()` path,
  which has never sent `include_contexts` and now does when a context is configured
  (`class-skwirrel-wc-sync-product-upserter.php:1033`). Whether the API accepts it *on that specific
  call* cannot be established from this repository.

**Narrowed in this run**, since the correction is derivable from the repository (gh#46 plus the
pre-story baseline at `7737f8f`) rather than a guess about live API behaviour: the marker now names
the single upserter call site instead of the whole method. What remains genuinely unverifiable here
is whether the live API accepts the parameter on *that* call — clear it after one real sync against a
multi-context instance with grouped products enabled.

### 🟡 Finding 4 — Required-but-unenforced connection field (carried, renamed, still open)

`skwirrel_base_url` sits in `unconditional_required_fields()` — so it renders a `*`, carries
`aria-required`, and blocks submit client-side — while `sanitize_settings()` raises no error for it.
Submit with JS off, or with a crafted POST, and an empty endpoint is stored silently. The auth token
is the same shape, and worse: when the WP 7.0 Connectors API manages it, the field is replaced by a
status line with no input for "required" to attach to.

`AdminSettingsRequiredFieldsTest:113-116` excludes it from the registry-vs-sanitiser agreement test
with a comment saying why — the honest way to carry a known gap, and the reason 5.2-AC2's anti-drift
test still means something.

Unchanged in substance since the last trace; only the field id moved (`skwirrel_subdomain` →
`skwirrel_base_url`, `4044e22`). Correctly scoped out of story 5.2 (the ACs are about *marking*, and
marking is covered), recorded in `deferred-work.md:54`, tracked as Epic 5 retro action 6. It does not
reduce any AC's coverage. It remains the one place where the epic's own theme — *a settings screen
you can trust* — is not yet true end to end, and `deferred-work.md:54` still describes it under the
old field name.

## Next Actions

1. **Amend `5.1-AC1` and `5.2-AC1` in `epics.md`** to the five-tab set and the `skwirrel_base_url`
   field (Finding 2). Cheap, and it stops a future implementer verifying against a screen that no
   longer exists.
2. **Resolve the `sprint-status.yaml:118` marker** (Finding 3) — narrow it to the single upserter
   call site or clear it after one real multi-context grouped sync.
3. **Epic 5 retro action 6** (Finding 4) — decide what an unconfigured connection does on save, then
   mark and enforce it in one place. Also update `deferred-work.md:54` to the new field name.

None of these is a blocker, and none of them moves the gate. The gate is where the team decided it
would be.
