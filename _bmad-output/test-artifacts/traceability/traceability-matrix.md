---
stepsCompleted:
  ['step-01-load-context', 'step-02-discover-tests', 'step-03-map-criteria', 'step-04-analyze-gaps', 'step-05-gate-decision']
lastStep: 'step-05-gate-decision'
lastSaved: '2026-08-26'
scope: 'Epic 5 — A settings screen you can navigate, trust, and verify'
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
tempCoverageMatrixPath: '/private/tmp/claude-501/-Users-joskoomen-Documents-Projects-Skwirrel-wordpress/f6d83cac-0601-490f-b98b-0cb73b2f01b9/scratchpad/tea-trace-coverage-matrix-20260826.json'
---

# Traceability Report — Epic 5

## Gate Decision: ⚠️ CONCERNS

**Rationale:** P0 coverage is 100% and overall coverage is 91% (minimum: 80%), but P1 coverage is 86% (target: 90%). Two P1 acceptance criteria are verified only by asserting strings in JavaScript and CSS source rather than by exercising behaviour, and two named risks sit outside the AC set: an unverified API parameter name and an untested authorization-denied path.

## Coverage Summary

| Metric                | Value                                            |
| --------------------- | ------------------------------------------------ |
| Total acceptance criteria | 22                                           |
| Fully covered         | 20 (91%)                                         |
| Partially covered     | 2                                                |
| Uncovered             | 0                                                |
| P0 coverage           | **100%** (5/5) — required 100% ✅                |
| P1 coverage           | **86%** (12/14) — target 90%, minimum 80% ⚠️     |
| P2 coverage           | 100% (3/3) ✅                                    |

## Coverage Oracle

Formal requirements, high confidence. Epic 5's four stories carry explicit Given/When/Then acceptance criteria in `epics.md`, mirrored by per-story implementation artifacts. No synthetic inference was needed and no external pointer was resolved.

## Test Inventory

| Level       | Files | Cases | Notes                                                        |
| ----------- | ----: | ----: | ------------------------------------------------------------ |
| Unit        |     4 |    87 | Stub bootstrap, no Docker                                     |
| Integration |     4 |    54 | Real WP + WC via wp-env; real `$wpdb`, real term/post APIs     |
| Component   |     0 |     0 | No component layer in this stack                              |
| E2E         |     0 |     0 | No browser layer — deliberate stack constraint                |

Epic 5 test files:

- `tests/Unit/SettingsTabsTest.php` (28) · `tests/Integration/SettingsTabsIntegrationTest.php` (19)
- `tests/Unit/AdminSettingsRequiredFieldsTest.php` (14) · `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` (25)
- `tests/Unit/ContextIdTest.php` (17) · `tests/Integration/ContextIdIntegrationTest.php` (13)
- `tests/Unit/TestConnectionMetricsTest.php` (28) · `tests/Integration/TestConnectionMetricsIntegrationTest.php` (16)

**Suite status at trace time — both green, no skips, no fixmes:**

- `vendor/bin/pest` → 536 passed, 1150 assertions, 2.44s
- `npm run test:integration` → 179 passed, 1610 assertions, 81.2s (1 deprecation notice)

## Traceability Matrix

### Story 5.1 — Tabbed settings navigation

| AC       | Requirement                                                                     | Pri | Status  | Level        | Evidence                                                                                                                                                   |
| -------- | ------------------------------------------------------------------------------- | --- | ------- | ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 5.1-AC1  | Four tabs; every field in exactly one; eight groups re-home per the map          | P1  | FULL    | Integration  | `SettingsTabsIntegrationTest`: *every input name the pre-tabs settings form rendered is still rendered* · *each of the eight field groups sits in exactly the tab the re-home map names* · *the four tabs each own a panel* |
| 5.1-AC2  | Single-request save; inactive tabs stay in DOM; other tabs' values unchanged     | **P0** | FULL | Integration  | *saving from a non-default tab leaves unrelated stored settings unchanged* · *every panel lives inside the one settings form* · *the tab strip sits outside the form and cannot submit it* · *the save button is inside the form but outside every panel* |
| 5.1-AC3  | Errored tabs marked (not colour alone); first errored tab opens                  | P1  | FULL    | Unit + Integ | Unit: *the first tab holding an error opens* · *with errors on several tabs the first one in tab order opens* · *a code claimed by two tabs is counted once*. Integ: *a tab holding a failing field is marked with a count, not colour alone* |
| 5.1-AC4  | `#tab-{slug}` deep link opens the tab; no collision with page-level `?tab=`      | P1  | **PARTIAL** | Integration | Fragment half asserted as a source substring only (`expect($joined)->toContain('#tab-')`). `?tab=` non-collision genuinely covered by `AdminMenuIntegrationTest`: *the tab links do not steal ownership of the page* |
| 5.1-AC5  | No-JS: no field unreachable, panels degrade to sequential sections               | P1  | FULL    | Integration  | *with no JavaScript every panel is a visible sequential section*                                                                                            |
| 5.1-AC6  | Keyboard reachable/operable, visible focus ring, ARIA tab/panel relationship     | P1  | **PARTIAL** | Integration | ARIA + roving tabindex asserted on real markup (*the ARIA wiring resolves in both directions*, *the roving tabindex belongs to the selected tab*). Keyboard operation = `'ArrowRight'` string in JS source; focus ring = `.skw-tab:focus-visible` selector presence |
| 5.1-AC7  | Tab registration extensible without touching this story's markup                | P2  | FULL    | Unit + Integ | Unit: *a fifth tab registers through the filter and lands at its order position*, *a filter cannot remove or replace a built-in panel*, *malformed registrations are dropped*. Integ: *a tab registered with a named external renderer renders in its order position* |

### Story 5.2 — Required field markers and inline errors

| AC       | Requirement                                                                     | Pri | Status | Level        | Evidence                                                                                                                        |
| -------- | ------------------------------------------------------------------------------- | --- | ------ | ------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| 5.2-AC1  | Unconditional required fields get `*` + `aria-required`; the three HTML5-`required` fields harmonised | P1 | FULL | Unit + Integ | Unit: *the unconditionally required fields are always required*. Integ: *an unconditionally required field is marked and announced as required* · *no control blocks submit without a marker and a registry entry behind it* |
| 5.2-AC2  | Conditional markers match exactly what `sanitize_settings()` enforces            | P1  | FULL   | Unit + Integ | Unit: *the registry and sanitize_settings agree on every checkbox combination* · *the rule table covers exactly the conditionally required fields*. Integ: *custom_collection_id follows each of the three features that consume it* · *…carries no required state at all while nothing consumes it* |
| 5.2-AC3  | Errors rendered adjacent to the field + summary; programmatically associated     | P1  | FULL   | Integration  | *a validation message renders at its field and is announced with it* · *every message for one field renders inline and is described by the input* · *the same message appears once as a summary and once at the field* · *an error code with no field mapping still reaches the user through the summary* |
| 5.2-AC4  | A failing field on a collapsed tab opens and marks that tab                      | P1  | FULL   | Unit + Integ | Unit: *the three sanitiser error codes all resolve to the What to sync tab* · *representative field ids resolve to the tab that actually renders them*. Integ: *the failing field ids are exposed to the tab strip as a seam* · *…reach the browser through the localized seam* |
| 5.2-AC5  | New strings translatable under `skwirrel-pim-sync`                              | P2  | FULL   | Unit         | *the strings this story added are in the POT and in all seven locales* · *every locale ships a compiled catalogue next to its source*             |

### Story 5.3 — Context ID

| AC       | Requirement                                                                     | Pri | Status | Level        | Evidence                                                                                                                        |
| -------- | ------------------------------------------------------------------------------- | --- | ------ | ------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| 5.3-AC1  | Optional Context ID field on Connection tab, placeholder `1`, help text          | P1  | FULL   | Unit + Integ | Unit: *the Context ID is optional — it is not in the required-field registry*. Integ: *renders as an optional whole-number field on the Connection tab* · *has a label and a hint that explains what leaving it empty does* |
| 5.3-AC2  | Value sent as the API parameter on getProducts / getProductsByFilter / getGroupedProducts | **P0** | FULL | Unit + Integ | Unit: *getGroupedProducts carries the configured context* · *getCategories carries the configured context* · *the selection membership sweep carries the configured context* · *no call site is left on a hardcoded context literal* (5 wired sites pinned at source). Integ: *a configured Context ID reaches the product fetch of a real sync run* · *categories are fetched from the same context as the products* · *the status discovery request uses a configured Context ID* |
| 5.3-AC3  | Empty → parameter omitted; existing installs behave exactly as before            | **P0** | FULL | Unit + Integ | Unit: *an unset, empty or invalid Context ID resolves to null so call sites keep their current behaviour* · *getGroupedProducts sends no context parameter at all when none is configured*. Integ: *an unconfigured Context ID leaves the product fetch on the default context* |
| 5.3-AC4  | A changed context sets `skwirrel_wc_sync_force_full_sync` and tells the admin    | **P0** | FULL | Unit + Integ | Unit: *a changed effective context sets the force-full-sync flag and tells the admin* · *an unchanged effective context leaves the flag alone*. Integ: *saving a changed effective context through update_option really sets the flag* · *a save that leaves the effective context alone never schedules a full re-sync* · *changing an unrelated setting never schedules a full re-sync* · *a test-connection click preserves the Context ID and does not schedule a full re-sync* · *the Context ID is part of the change-gate signature* |
| 5.3-AC5  | Non-numeric/negative rejected with an inline error, not silently coerced          | P1  | FULL   | Unit + Integ | Unit: *an invalid Context ID raises a settings error and is stored exactly as typed* · *an empty or valid Context ID raises no settings error* · *an omitted Context ID field stores an empty string* · *get_context_ids survives a corrupt settings option*. Integ: *a rejected Context ID comes back in the field with its message, and flags the Connection tab* |

### Story 5.4 — Test Connection reports what actually came back

| AC       | Requirement                                                                     | Pri | Status | Level        | Evidence                                                                                                                        |
| -------- | ------------------------------------------------------------------------------- | --- | ------ | ------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| 5.4-AC1  | Success reports round-trip time, HTTP/JSON-RPC status, and total products         | P1  | FULL   | Unit + Integ | Unit: *a successful test reports timing, status and the API-reported total* · *the product total comes from the pagination block, not the returned array* · *the status wording flips to a bare HTTP code exactly at 400* · *the metrics are announced round-trip first, then status, then products*. Integ: *a successful call carries measurement and the API-reported total* · *a successful test answers with tone, headline and metric lines* |
| 5.4-AC2  | Zero products stated plainly as a warning, not an unqualified success            | P1  | FULL   | Unit + Integ | Unit: *a zero total is a warning, not a success* · *the warning tone is distinguished by more than colour*. Integ: *zero products stays a success response and carries the warning in the tone* · *a warning result renders as a WordPress warning notice with its metric lines* |
| 5.4-AC3  | Failure still reports round-trip and status (timeout distinguishable from rejection) | P1 | FULL | Unit + Integ | Unit: *a transport failure says there was no response instead of showing status 0* · *an HTTP error reports the status alongside the message* · *a JSON-RPC rejection is distinguishable from a transport failure* · *retries are reported* · *a failure with no message still gets a headline*. Integ: *a JSON-RPC rejection still reports the HTTP status it was rejected with* · *an HTTP error response reports its status and is not retried* · *a body that is not JSON still comes back measured* · *a transport failure counts every attempt and reports no HTTP response* |
| 5.4-AC4  | No writes beyond the existing autosave; auth token nowhere in output (NFR-4)      | **P0** | FULL | Unit + Integ | Unit: *the formatter cannot leak the auth token because it never receives one* · *the shared payload pipeline redacts a credential reflected by the API* · *both test paths use one shared secret-safe formatter pipeline* · *the result renderer builds DOM nodes and never assigns innerHTML*. Integ: *the test writes nothing beyond the settings it already autosaved* · *the auth token is sent to the API but never appears in the response* · *an API error that reflects the auth token is redacted before the JSON response* · *an API-supplied error message is escaped in the notice* |
| 5.4-AC5  | Counts render with tabular figures; new strings translatable                     | P2  | FULL   | Unit         | *metric numbers render with tabular numerals* · *every new user-facing string is in the translation template* · *the new strings are wrapped with the literal text domain* |

## Coverage Heuristics

| Dimension          | Status     | Finding                                                                                                                                        |
| ------------------ | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Error paths        | ✅ Strong  | 5.4 covers transport failure, HTTP ≥400, JSON-RPC rejection, non-JSON body, absent pagination, empty message, retry counting, clamped timing.     |
| UI state           | ✅ Strong  | Validation, error, no-JS, clean-render, zero-result, and legacy-transient states all asserted against real rendered markup.                       |
| Back-compat        | ✅ Strong  | *a transient written before this change still renders* · *a successful legacy result renders as a success notice* · the pre-tabs input-name census. |
| Secret handling    | ✅ Strong  | Redaction covered on both the formatter and the shared payload pipeline, including a credential reflected back by the API.                        |
| **Auth / authz**   | ✅ **Closed 2026-08-26** | Was a gap: every test supplied a valid nonce and an admin, so the `check_ajax_referer()` + `current_user_can('manage_woocommerce')` → 403 guards were never exercised. Four denied-path tests added to `TestConnectionMetricsIntegrationTest` (absent nonce, forged nonce, subscriber with a valid nonce, SSRF pin). Each asserts refusal **plus** unchanged connection state **plus** no outbound HTTP. |
| **Endpoint contract** | ⚠️ **Gap** | The Context ID parameter (`include_contexts`) is pinned only against mocks. `epics.md` marks story 5.3 *BLOCKED UNTIL CONFIRMED* and `sprint-status.yaml` still records *"getGroupedProducts context param still unverified against the API"*. Green tests here prove wiring, not the contract. |
| E2E layer          | ➖ Absent  | No browser-driven layer. Admin-JS behaviour is verified by source-string assertion. A documented stack constraint (no build step, no JS framework), not an oversight — but it is what caps 5.1-AC4 and 5.1-AC6. |

## Gaps & Recommendations

### 1. Confirm the Context ID API parameter name — *highest value, lowest effort*

`_bmad-output/implementation-artifacts/sprint-status.yaml:113` still carries the unverified marker, and the epic's own blocker was never formally cleared even though 5.3 is marked `done`. The unit suite proves the value reaches every call site; nothing proves `include_contexts` is what the Skwirrel API actually reads. If the name is wrong, all 30 Context ID tests stay green while the feature silently does nothing.

**Action:** verify against the Skwirrel JSON-RPC docs, then clear the marker in `sprint-status.yaml` — or, if the docs are unavailable, capture one real response and add a recorded-response contract test.

### 2. ✅ DONE — authorization denied-path tests for `handle_test_connection_ajax()`

Closed 2026-08-26. Four tests added (20/20 green in that file, phpcs clean). The scope turned out wider than first stated: `endpoint_url` is read from `$_POST`, autosaved, **and then requested**, so these guards also stand between an unprivileged POST and a server-side request to a caller-chosen URL. Each test asserts refusal, unchanged connection state, and that no HTTP request left the server; a fourth pins the SSRF case directly.

Not vacuous by construction — with the capability check removed the subscriber would receive a result payload rather than `Access denied.`, and with the nonce check removed the handler would reach the autosave and the outbound call. A live mutation check was deliberately skipped while another agent held `admin-settings.php`; worth running once the tree is quiet.

### 3. Decide the ceiling for 5.1-AC4 / 5.1-AC6

`SettingsTabsIntegrationTest.php:26-31` is admirably honest about what it does not cover: the `#tab-{slug}` deep link, the `history.replaceState` rewrite, keyboard roving, and the focus ring are asserted as strings in the enqueued script and stylesheet. That is a reasonable ceiling for a plugin with no JS build step — but it should be a **recorded decision**, not an implicit one.

**Action:** either accept it and note the limitation in the epic retrospective, or extract the tab controller so the fragment-parse and arrow-key branches become unit-testable.

## Next Actions

1. Clear (or test) the Context ID API parameter contract — blocks a clean Epic 5 close-out.
2. Add the two authz denied-path integration cases for the test-connection endpoint.
3. Record the JS-assertion ceiling as an accepted limitation in the Epic 5 retrospective.
4. Re-run this trace after 1–2; P1 coverage is unaffected by them, so the gate stays CONCERNS until 5.1-AC4/AC6 are resolved or formally waived.

## Gate Criteria

| Criterion         | Required | Actual | Status     |
| ----------------- | -------- | ------ | ---------- |
| P0 coverage       | 100%     | 100%   | ✅ MET     |
| P1 coverage       | 90% (min 80%) | 86% | ⚠️ PARTIAL |
| Overall coverage  | ≥ 80%    | 91%    | ✅ MET     |
| Critical gaps     | 0        | 0      | ✅ MET     |

**Decision:** CONCERNS — proceed, address the gaps soon. Not a release blocker.

**Scope and freshness.** This gate covers **Epic 5 only**, traced at commit `c0d8c65`. Two updates since:
* The auth/authz heuristic gap is **closed** (above). It was recorded as a heuristic finding, not an acceptance criterion, so the percentages and the gate decision are unchanged.
* **Epic 6 (stories 6.1–6.4) has since landed on this branch and is NOT covered by this trace.** It has had no traceability run and holds no gate. Re-run the workflow against Epic 6 before treating this branch as traced.

The gate stays CONCERNS because the two criteria holding P1 at 86% — 5.1-AC4 and 5.1-AC6 — are unchanged and cannot be closed server-side.
