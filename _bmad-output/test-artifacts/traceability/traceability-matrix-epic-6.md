---
stepsCompleted: ['step-01-load-context', 'step-02-discover-tests', 'step-03-map-criteria', 'step-04-analyze-gaps', 'step-05-gate-decision']
lastStep: 'step-05-gate-decision'
lastSaved: '2026-08-27'
scope: 'Epic 6 — Stock and product content driven from Skwirrel data'
coverageBasis: 'acceptance_criteria'
oracleConfidence: 'high'
oracleResolutionMode: 'formal_requirements'
oracleSources:
  - '_bmad-output/planning-artifacts/epics.md#epic-6'
  - '_bmad-output/implementation-artifacts/6-1-stock-from-custom-class-simple.md'
  - '_bmad-output/implementation-artifacts/6-2-stock-on-variations.md'
  - '_bmad-output/implementation-artifacts/6-3-title-and-descriptions-from-custom-class.md'
  - '_bmad-output/implementation-artifacts/6-4-non-destructive-mapping-test-suite.md'
  - '_bmad-output/implementation-artifacts/6-5-document-link-names-language-aware.md'
externalPointerStatus: 'not_used'
collectionStatus: 'COLLECTED'
gateDecision: 'CONCERNS'
---

# Traceability Report — Epic 6

## Coverage Oracle

**Resolved oracle:** formal acceptance criteria from `epics.md#epic-6` plus the five story files.
**Why this oracle:** Epic 6 carries explicit Given/When/Then acceptance criteria per story, all five stories are
`done` in `sprint-status.yaml`, and NFR-9 ("a missing PIM value never clears WooCommerce data") is stated as a
hard guarantee. No synthetic inference or external pointer was needed.

**Confidence:** high — criteria are behavioural, testable, and name the exact classes/methods under test.

| Field | Value |
| --- | --- |
| coverageBasis | acceptance_criteria |
| oracleResolutionMode | formal_requirements |
| oracleConfidence | high |
| externalPointerStatus | not_used |

**Stories in scope:** 6.1 (stock, simple) · 6.2 (stock, variations) · 6.3 (title + descriptions) · 6.4 (NFR-9 canary suite) · 6.5 (document link names) — all `done`.

---

## Test Inventory (step 2)

Discovered by searching `tests/Unit/` and `tests/Integration/` for the Epic 6 setting keys
(`stock_quantity_feature`, `title_feature_id`, `short_description_feature_id`, `long_description_feature_id`),
the resolver method names, and the story-declared file list.

| File | Level | Cases | Serves |
| --- | --- | --- | --- |
| `tests/Unit/StockMappingTest.php` | Unit | 14 | 6.1 |
| `tests/Unit/VariationStockMappingTest.php` | Unit | 8 | 6.2 |
| `tests/Unit/ContentMappingTest.php` | Unit | 12 | 6.3 |
| `tests/Unit/CustomClassExtractorTest.php` (extended) | Unit | 7 new | 6.3 |
| `tests/Unit/ProductMapperNameTest.php` (extended) | Unit | 3 new | 6.3 |
| `tests/Unit/ContentHashTest.php` (extended) | Unit | 2 new | 6.1 / 6.3 change gate |
| `tests/Unit/SlugResolverTest.php` (extended) | Unit | 1 new | 6.3 slug invariant |
| `tests/Unit/NonDestructiveMappingTest.php` | Unit | 7 | 6.4 |
| `tests/Unit/AttachmentHandlerDocumentNameTest.php` | Unit | 11 | 6.5 |
| `tests/Unit/FieldMappingTranslationsTest.php` | Unit | 2 | 6.1 / 6.3 i18n |
| `tests/Integration/VariationStockIntegrationTest.php` | Integration | 11 | 6.2 |
| `tests/Integration/NonDestructiveMappingIntegrationTest.php` | Integration | 21 | 6.4 (and 6.1 / 6.3 behaviour) |
| `tests/Integration/SettingsTabsIntegrationTest.php` (extended) | Integration | pins extended | 6.1 / 6.3 settings render |

**No skipped, pending or `fixme` cases** were found in any Epic 6 test file.

### Collection status: COLLECTED — but only from a clean test database

| Suite | Command | Result |
| --- | --- | --- |
| Unit | `vendor/bin/pest` | **608 passed** (1478 assertions) |
| Integration | `npm run test:integration` after `npx wp-env clean tests` | **215 passed** (1729 assertions), 1 deprecation |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=2G` | **No errors** (level 6) |
| Code style | `vendor/bin/phpcs` | **Clean** (34 files) |

⚠️ **Collection finding — the integration suite is not idempotent.** Run back-to-back against the same
wp-env database without cleaning, the suite reported **43 failures across 9 files** — including files with no
Epic 6 involvement (`SweepMembershipIntegrationTest`, `ContextIdIntegrationTest`, `TestConnectionMetricsIntegrationTest`,
`SettingsTabsIntegrationTest`). Symptoms were `wc_get_product_id_by_sku()` returning `0` for a SKU the test had
just written, `WC_Data_Exception` on duplicate SKUs, `run_sync` reporting `created: 0`, and `wp_insert_user()`
failing on a colliding subscriber login. After `npx wp-env clean tests` the same suite ran **fully green**.
This is residue from prior runs, not an Epic 6 regression — but it means a red integration run is not, on its
own, trustworthy evidence of a defect. Recorded as a gap below.

### Coverage Heuristics

| Heuristic | Finding |
| --- | --- |
| API endpoint coverage | Not applicable — Epic 6 consumes existing `getProducts` / `getGroupedProducts` responses; it adds no endpoint. |
| Auth / authz coverage | Not applicable — no new capability gate. The Field mapping settings inherit the `manage_woocommerce` gate already pinned by `SettingsTabsIntegrationTest`. |
| Error-path coverage | **Strong.** Absent, `not_applicable`, empty, whitespace-only, non-numeric, malformed-ref and trade-item-scoped payloads are each tested separately rather than collapsed. |
| Happy-path-only criteria | None in stock/content mapping. **One in document naming:** 6.5's resolver has an exhaustive fallback chain, but nothing exercises the resolver → `$doc['name']` → template wiring. |
| UI journey / state coverage | Not applicable — oracle is formal, not synthetic. |

---

## Traceability Matrix (step 3)

Priority is assigned from `test-priorities-matrix.md`: NFR-9 data-integrity criteria and the write paths
they guard are P0; user-facing behaviour and settings surface are P1; structural/process criteria are P2.

### Story 6.1 — Stock quantity from a custom class, simple products

| AC | Criterion | Pri | Tests | Coverage |
| --- | --- | --- | --- | --- |
| 6.1-AC1 | Field mapping group + Stock quantity field, empty by default | P1 | `SettingsTabsIntegrationTest` (input-name pin, tab map); `StockMappingTest` "defaults to an empty string", "a stored value is surfaced" | FULL |
| 6.1-AC2 | Numeric value → `set_manage_stock(true)` + `set_stock_quantity()`, product level only | **P0** | `StockMappingTest` ×8 (ID, code, type N/T, raw number not display string); `NonDestructiveMappingIntegrationTest` "the control case proves the write path works" | FULL |
| 6.1-AC3 | Absent / empty / non-numeric → stock untouched (NFR-9) | **P0** | `StockMappingTest` ×4; `NonDestructiveMappingIntegrationTest` "keeps its stock…" (3-shape dataset) + "custom classes omit the mapped feature" | FULL |
| 6.1-AC4 | No feature configured → no stock write at all | **P0** | `StockMappingTest` "resolves to null without reading the payload"; `NonDestructiveMappingIntegrationTest` AC-4 (payload carries all four features) | FULL |
| 6.1-AC5 | Mapped stock change participates in `_skwirrel_content_hash` | P1 | `ContentHashTest` "a changed custom-class value changes the content hash", "still seen when only product_updated_on also moved" | FULL |
| 6.1-AC6 | Unit coverage pins the four cases | P1 | `StockMappingTest` (14 cases) | FULL |

### Story 6.2 — Stock quantity on variations

| AC | Criterion | Pri | Tests | Coverage |
| --- | --- | --- | --- | --- |
| 6.2-AC1 | Variation resolves the feature against its own payload → managed stock | **P0** | `VariationStockIntegrationTest` "a resolved quantity lands on the variation", "the legacy `upsert_product_as_variation` path also writes mapped stock"; `VariationStockMappingTest` ×2 | FULL |
| 6.2-AC2 | Hardcoded `set_manage_stock(false)` / `instock` no longer override; unchanged when unconfigured | **P0** | `VariationStockMappingTest` "unconfigured… legacy writes still fire", "configured… suppressing the legacy writes", "the chokepoint both paths share"; `VariationStockIntegrationTest` "with the mapping off a priced variation is unmanaged and in stock, as before" | FULL |
| 6.2-AC3 | Parent aggregate refreshed via `WC_Product_Variable::sync_stock_status()` | P1 | `VariationStockIntegrationTest` "the variable parent stays unmanaged and aggregates from its children" | FULL |
| 6.2-AC4 | Missing/empty variation value → untouched; siblings unaffected | **P0** | `VariationStockIntegrationTest` ×3; `NonDestructiveMappingIntegrationTest` ×4 incl. grouped sync + legacy path | FULL |
| 6.2-AC5 | Price-on-request variation keeps its out-of-stock treatment | P1 | `VariationStockIntegrationTest` ×3 (incl. queued and legacy paths); `NonDestructiveMappingIntegrationTest` "price on request still ends out of stock even with a valid stock value" | FULL |

### Story 6.3 — Title, short and long description from custom classes

| AC | Criterion | Pri | Tests | Coverage |
| --- | --- | --- | --- | --- |
| 6.3-AC1 | Three further optional fields, each independently empty by default | P1 | `SettingsTabsIntegrationTest` (three input names pinned, exact-set assertion) | FULL |
| 6.3-AC2 | Resolved non-empty value overrides the existing source | P1 | `ContentMappingTest` ×3; `ProductMapperNameTest` ×3; `NonDestructiveMappingIntegrationTest` "resolved title, short- and long-description values each win" | FULL |
| 6.3-AC3 | Absent/empty → existing chain unchanged; never an empty title (NFR-9) | **P0** | `ContentMappingTest` ×5 (absent / `not_applicable` / empty by three routes, whitespace-only, sanitises-to-empty); `NonDestructiveMappingIntegrationTest` 3-shape dataset | FULL |
| 6.3-AC4 | Long description passes through `wp_kses_post()` | **P0** | `ContentMappingTest` "markup survives while unsafe markup does not", "the title is not run through the post sanitiser" | FULL — *unit-only, against the `tests/bootstrap.php` `wp_kses_post()` stub. The stub strips `script`/`style` and inline handlers, so the assertion is real for the call site; WordPress's own kses correctness is out of scope.* |
| 6.3-AC5 | Translations follow the existing `image_language` chain | P1 | `ContentMappingTest` "an I-type value picks the language through the existing chain"; `CustomClassExtractorTest` (formatter delegation) | FULL |
| 6.3-AC6 | A mapped title never rewrites the slug | P1 | `SlugResolverTest` (mapping configured, mapped title wins, slug still from raw SKU) | FULL |
| 6.3-AC7 | Mapped content change is not skipped by the unchanged gate | P1 | `ContentHashTest` "each content mapping setting changes the sync signature" + payload-hash cases | FULL |
| 6.3-AC8 | The three mappings work independently | P1 | `ContentMappingTest` "configuring only the long description leaves title and short description alone"; `NonDestructiveMappingIntegrationTest` AC-4 | FULL |

### Story 6.4 — The non-destructive guarantee is pinned by tests

| AC | Criterion | Pri | Tests | Coverage |
| --- | --- | --- | --- | --- |
| 6.4-AC1 | Each of the four mappings, absent/empty/malformed → asserted unchanged, simple + variations | **P0** | `NonDestructiveMappingIntegrationTest` (21 cases) + `NonDestructiveMappingTest` (7 cases). **Mutation-verified**: injecting the NFR-9 violation into `apply_stock_mapping()` took the suite to 9 failed / 9 passed | FULL |
| 6.4-AC2 | API omits `_custom_classes` entirely → nothing written, run reports success | **P0** | `NonDestructiveMappingIntegrationTest` "a full sync with no `_custom_classes` key succeeds, preserves every mapped field and raises no notice" | FULL |
| 6.4-AC3 | Stands alone as its own Pest file, never blocked on Story 1.14 | P2 | `tests/Integration/NonDestructiveMappingIntegrationTest.php` exists standalone; 1.14 still `backlog` | FULL |
| 6.4-AC4 | `pest`, `phpstan` level 6, `phpcs` all pass | P1 | Re-verified this run: 608 unit / 215 integration passed, PHPStan "No errors", PHPCS clean | FULL |

### Story 6.5 — Document links show a readable name in the shop's language

| AC | Criterion | Pri | Tests | Coverage |
| --- | --- | --- | --- | --- |
| 6.5-AC1 | Exact language match → `$doc['name']`, **and the documents tab renders it as link text** | P1 | `AttachmentHandlerDocumentNameTest` "an exact language match wins" (resolver only) | **PARTIAL** — the resolver is pinned; nothing exercises `get_document_attachments()` → `$doc['name']` → `get_documents_for_product()` → template. The second half of the AC is unverified. |
| 6.5-AC2 | Prefix match through the shared `pick_attachment_translation()` chain, not a parallel one | P1 | `AttachmentHandlerDocumentNameTest` "a two-letter prefix match wins", "the first entry is used when neither matches", "image meta resolution is unchanged by the shared-chain refactor" | FULL |
| 6.5-AC3 | Fallback chain title → `file_name` → URL basename; never nameless | P1 | `AttachmentHandlerDocumentNameTest` ×6 incl. "never returns an empty string, whatever it is given" | FULL |
| 6.5-AC4 | Pre-existing stored raw filenames are refreshed by a normal re-sync — no migration | P1 | *(none)* | **NONE** — no test at any level drives a re-sync over a product whose stored `_skwirrel_document_attachments` holds legacy raw filenames. This is the AC that guarantees existing shops actually get the fix. |
| 6.5-AC5 | Escaped at output (`esc_html`) in the tab and the meta box, exactly as today | P1 *(security, low probability — content originates in the store's own PIM feed; escaping is pre-existing and unchanged)* | `AttachmentHandlerDocumentNameTest` "HTML in a title is returned verbatim, not escaped here" — the complementary half | **PARTIAL** — proves escaping is the consumer's job, but no test asserts any of the three render sites actually escapes. |
| 6.5-AC6 | `pest`, `phpstan` level 6, `phpcs` all pass | P1 | Re-verified this run — all green | FULL |

---

## Coverage Summary

| Metric | Value |
| --- | --- |
| Total acceptance criteria | 29 |
| Fully covered | 26 (90%) |
| Partially covered | 2 |
| Uncovered | 1 |
| **P0 coverage** | **100%** (10/10) — required 100% ✅ |
| **P1 coverage** | **83%** (15/18) — target 90%, minimum 80% ⚠️ |
| P2 coverage | 100% (1/1) ✅ |

Coverage by level: Unit 67 cases across 10 files · Integration 32 cases across 3 files · no E2E, API or component
level (no browser suite in this project — admin UI is server-rendered PHP, asserted through real WP in the
integration suite).

---

## Gap Analysis (step 4)

### High — Story 6.5's fix is unverified end to end

**GAP-1 · 6.5-AC4 · uncovered · P1 · risk score 6 (probability 2 × impact 3), category TECH/BUS**
Nothing tests that a re-sync refreshes stored `_skwirrel_document_attachments` names. Every existing shop's
documents are stored with raw filenames today; this AC is the entire delivery mechanism for the fix. If the
stored array is written from a cached or short-circuited path — the unchanged gate skipping a product whose
only change is the resolved document name is a plausible mechanism — the resolver would be correct and no
customer would ever see the difference.
**Recommendation:** one integration test — seed a product whose `_skwirrel_document_attachments` holds a raw
filename, run the upsert with a payload carrying `_attachment_translations`, assert the stored name is now the
translated title. Same shape as the `NonDestructiveMappingIntegrationTest` seed-then-upsert-then-read pattern.

**GAP-2 · 6.5-AC1 (second clause) · partial · P1 · risk score 4 (2 × 2), category TECH**
The resolver is thoroughly pinned; the wiring from resolver → `$doc['name']` → `get_documents_for_product()` →
template is not touched by any test. Note the sharp edge the story itself documented: `get_documents_for_product()`
**drops** any document with an empty name, so a wiring defect does not render badly — the document disappears
from the tab entirely.
**Recommendation:** fold into GAP-1's test — after the re-sync, call `get_documents_for_product()` and assert
the returned row carries the translated name. One test closes both gaps.

### Medium — the escaping invariant is asserted from one side only

**GAP-3 · 6.5-AC5 · partial · P1 · risk score 3 (1 × 3), category SEC**
`AttachmentHandlerDocumentNameTest` proves the resolver returns HTML verbatim, i.e. that escaping is the
consumer's responsibility. No test asserts that any of the three consumers still discharges it. The story
honoured the AC by not editing those files, and `esc_html()` is present at all three sites, but the invariant
is unpinned: a future refactor of the documents tab could drop it silently.
**Recommendation:** assert `esc_html` output at the render site once, in whichever test eventually covers
GAP-2. Low urgency — probability is low because the content originates in the store's own PIM feed.

### Medium — the integration suite cannot be trusted on a second run

**GAP-4 · cross-cutting · process · risk score 6 (3 × 2), category OPS**
`npm run test:integration` fails 43 tests across 9 files when run against a database a previous run already
touched, and passes 215/215 from clean. The failures are indistinguishable in shape from real regressions
(missing SKUs, duplicate-SKU exceptions, `created: 0`), so the suite currently trains its readers to
disbelieve red runs — which is exactly how a real regression gets waved through.
**Recommendation:** either make teardown restore the pre-test state (the `SyncSafetyIntegrationTest:23-70`
teardown pattern generalised), or make `npm run test:integration` clean the tests DB first. The deferred
finding on 6.4 — "run the real integration suite in required CI" (`.github/workflows/ci.yml:48`) — cannot be
actioned until this is fixed; a non-idempotent suite in CI is a permanent red.

### Low — noted, no action proposed

- **6.1-AC1's fallback branch** ("when Story 5.1's tab registration is not present, renders as an ordinary
  field group") has no test. 5.1 is `done`, so the branch is unreachable in the shipped product. Left alone
  deliberately — a test for a dead branch is upkeep without value.
- **6.3-AC4** is unit-only against the bootstrap's `wp_kses_post()` stub. Adequate: the AC is about routing
  the value through the sanitiser, not about kses's own correctness.

### What is genuinely well covered — worth saying plainly

The NFR-9 guarantee is the strongest-pinned invariant in this codebase. Story 6.4's suite was **mutation-tested**
— the violation was deliberately injected and the suite went from 18/18 green to 9 failed — which is evidence
almost no test suite in this repository can offer. Absent, `not_applicable`, empty, whitespace-only,
non-numeric, malformed-ref and trade-item-scoped payloads are each tested separately rather than collapsed
into one dataset, and every "unchanged" assertion has a positive control proving the write path can write.
Both parallel variation write paths are covered. That is the standard the rest of the epic should be read against.

---

## Gate Decision: ⚠️ CONCERNS

**Rationale:** P0 coverage is 100% (10/10) and overall coverage is 90% (minimum 80%), but P1 coverage is 83%
(target 90%, minimum 80%) — Rule 5. Every shortfall sits in Story 6.5: its resolver is exhaustively pinned
while the delivery path that makes the fix reach a real shop is untested (6.5-AC4 uncovered, 6.5-AC1 partial),
and the output-escaping invariant is asserted from one side only (6.5-AC5 partial). Stories 6.1–6.4 are
unconditionally clean, with the NFR-9 guarantee mutation-verified.

**Not a blocker for release.** The gap is in verification, not in known-broken behaviour, and the one-line
defect 6.5 set out to fix is demonstrably fixed at the resolver.

| Criterion | Required | Actual | Status |
| --- | --- | --- | --- |
| P0 coverage | 100% | 100% (10/10) | MET |
| P1 coverage | 90% target / 80% min | 83% (15/18) | PARTIAL |
| Overall coverage | ≥ 80% | 90% (26/29) | MET |
| Collection status | COLLECTED | COLLECTED (clean DB) | MET |

## Next Actions

1. **Close GAP-1 + GAP-2 with one integration test** (~30 min): seed a product carrying a legacy raw-filename
   `_skwirrel_document_attachments`, re-sync with `_attachment_translations` present, assert the stored name
   is refreshed **and** that `get_documents_for_product()` returns it. This alone takes P1 to 94% and the gate
   to PASS.
2. **Add the `esc_html` assertion at the render site** (GAP-3) in that same test — one extra expectation.
3. **Fix integration-suite idempotency** (GAP-4) before wiring the integration suite into required CI. Track
   against the existing deferred item at `.github/workflows/ci.yml:48`.
4. Optional: log GAP-4 in `deferred-work.md` so it is not rediscovered by the next trace.
