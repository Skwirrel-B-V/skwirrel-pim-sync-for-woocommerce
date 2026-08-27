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
gateDecision: 'PASS'
---

# Traceability Report — Epic 6

## Gate Decision: PASS

**Rationale:** P0 coverage is 100%, P1 coverage is 100% (target 90%), and overall coverage is 100%
(minimum 80%). No gaps remain open at any priority level.

Story 6.5's three shortfalls — recorded as FAIL earlier in this run — were closed by
`tests/Integration/DocumentNamesIntegrationTest.php` (12 cases), added and verified during this session.

---

## Coverage Oracle

**Resolved oracle:** formal acceptance criteria from `epics.md#epic-6` plus the five story files.
**Resolution mode:** `formal_requirements` · **Basis:** `acceptance_criteria` · **Confidence:** high
**External pointer status:** `not_used`

**Why this oracle:** Epic 6 carries explicit Given/When/Then acceptance criteria per story, all five stories
are `done` in `sprint-status.yaml`, and NFR-9 ("a value missing from the PIM never wipes what WooCommerce
already has") is stated as a hard, testable guarantee. The criteria name the exact classes and methods under
test, so no synthetic inference or external pointer was needed.

---

## Collection Status: COLLECTED

Both suites were executed against the working tree during this run:

| Suite | Command | Result |
|---|---|---|
| Unit | `vendor/bin/pest` | **608 passed** (1478 assertions), 1.09s |
| Integration | `npm run test:integration` (wp-env, real WP+WC) | **227 passed** (1753 assertions), 1 deprecated, 66.3s |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=2G` | **No errors** (level 6, 34 files) |
| Code style | `vendor/bin/phpcs` | **Clean** (34 files) |

No skipped, pending, or fixme tests in the traced set.

---

## Coverage Summary

- **Total acceptance criteria:** 29
- **Fully covered:** 29 (**100%**)
- **P0 coverage:** 9/9 (**100%**) — MET
- **P1 coverage:** 14/14 (**100%**) — MET (target 90%)
- **P2 coverage:** 6/6 (**100%**) — MET
- **P3 coverage:** n/a (0 criteria)

| Status | Count |
|---|---|
| FULL | 29 |
| PARTIAL | 0 |
| NONE | 0 |

---

## Traceability Matrix

Priorities follow `test-priorities-matrix.md`. **P0** = data integrity (NFR-9 destructive-write paths) and
security (`wp_kses_post`). **P1** = core mapping journeys and complex resolution logic. **P2** = settings
rendering, structural and gate-hygiene criteria.

### Story 6.1 — Stock quantity from a custom class, simple products (FR-18, NFR-9)

| AC | Criterion | Pri | Coverage | Tests |
|---|---|---|---|---|
| 6.1-AC1 | Field mapping group renders a Stock quantity field, empty by default; registers as a tab when 5.1 exists, else as an ordinary group | P2 | FULL | `Integration/SettingsTabsIntegrationTest.php:198` (input rendered), `:430` (group sits in `field-mapping` tab), `Unit/StockMappingTest.php:216` (default `''`), `:226` (stored value surfaced), `Unit/SettingsTabsTest.php:81`,`:90` (fallback when the registry is absent/empty) |
| 6.1-AC2 | Numeric value → `set_manage_stock(true)` + `set_stock_quantity()`, via `Custom_Class_Extractor`, product level only | P1 | FULL | `Unit/StockMappingTest.php:73`,`:84`,`:131`,`:190`,`:200`,`:241`; `Unit/CustomClassExtractorTest.php:725`,`:735`; `Integration/NonDestructiveMappingIntegrationTest.php:255` (control: a valid value does land) |
| 6.1-AC3 | Absent / empty / non-numeric leaves stock and `manage_stock` exactly as they were (**NFR-9**) | **P0** | FULL | `Unit/StockMappingTest.php:95`,`:106`,`:167`,`:178`; `Unit/NonDestructiveMappingTest.php:74`,`:84`; `Integration/NonDestructiveMappingIntegrationTest.php:226`,`:240` |
| 6.1-AC4 | No feature ID configured → no stock write at all, byte-for-byte prior behaviour | **P0** | FULL | `Unit/StockMappingTest.php:116` (resolves null without reading the payload); `Unit/NonDestructiveMappingTest.php:150`; `Unit/VariationStockMappingTest.php:86`; `Integration/VariationStockIntegrationTest.php:322` |
| 6.1-AC5 | A changed mapped value participates in `_skwirrel_content_hash`, so delta sync does not skip it | P1 | FULL | `Unit/ContentHashTest.php:138`,`:159`,`:180`; `Integration/VariationStockIntegrationTest.php:277` (observe-mode hash mismatch updates despite unchanged timestamp) |
| 6.1-AC6 | Unit pins: numeric written; missing untouched; non-numeric untouched; unconfigured writes nothing | P2 | FULL | All four pinned in `Unit/StockMappingTest.php` (`:73`, `:95`, `:106`, `:116`) |

**Trade-item guard (explicit in AC2):** `Unit/StockMappingTest.php:142` and `Unit/CustomClassExtractorTest.php:757`
both assert a value living only under `_trade_item_custom_classes` resolves to nothing.

### Story 6.2 — Stock quantity on variations (FR-18, NFR-9)

| AC | Criterion | Pri | Coverage | Tests |
|---|---|---|---|---|
| 6.2-AC1 | Each variation resolves the same feature ID against **its own** product-level custom classes | P1 | FULL | `Unit/VariationStockMappingTest.php:123`; `Integration/VariationStockIntegrationTest.php:131`, `:344` (legacy `upsert_product_as_variation` path too) |
| 6.2-AC2 | Hardcoded `set_manage_stock(false)` / forced `instock` no longer override a resolved quantity; unchanged when mapping is off | **P0** | FULL | `Unit/VariationStockMappingTest.php:86`,`:91`,`:96`,`:105` (pure decision + shared chokepoint); `Integration/VariationStockIntegrationTest.php:322`,`:344`; `Integration/NonDestructiveMappingIntegrationTest.php:326` |
| 6.2-AC3 | Parent aggregate refreshed through the existing `WC_Product_Variable::sync_stock_status()`, not a parallel mechanism | P1 | FULL (integration-only) | `Integration/VariationStockIntegrationTest.php:303` (parent stays unmanaged and aggregates from its children) |
| 6.2-AC4 | A variation with a missing/empty value keeps its stock; siblings unaffected (**NFR-9**) | **P0** | FULL | `Unit/VariationStockMappingTest.php:133`,`:176`; `Integration/VariationStockIntegrationTest.php:151`,`:177`,`:207`; `Integration/NonDestructiveMappingIntegrationTest.php:296`,`:355`,`:418` |
| 6.2-AC5 | A price-on-request variation keeps its existing out-of-stock treatment | P1 | FULL (integration-only) | `Integration/VariationStockIntegrationTest.php:234`,`:249`,`:261`; `Integration/NonDestructiveMappingIntegrationTest.php:441` |

**Trade-item guard:** `Unit/VariationStockMappingTest.php:147` covers the variation path.

### Story 6.3 — Title, short and long description from custom classes (FR-19, NFR-9)

| AC | Criterion | Pri | Coverage | Tests |
|---|---|---|---|---|
| 6.3-AC1 | Three further optional fields render, each independently empty by default | P2 | FULL | `Integration/SettingsTabsIntegrationTest.php:199-201` (all three inputs rendered inside the one form), `:430` (group in the `field-mapping` tab); `Unit/SettingsTabsTest.php:173` |
| 6.3-AC2 | A resolving value overrides the existing source | P1 | FULL | `Unit/ContentMappingTest.php:60`,`:72`,`:84`; `Integration/NonDestructiveMappingIntegrationTest.php:487` |
| 6.3-AC3 | Absent/empty → existing chain applies unchanged; a product never ends up with an empty title (**NFR-9**) | **P0** | FULL | `Unit/ContentMappingTest.php:102`,`:117` (three fall-back routes),`:179`,`:191`; `Integration/NonDestructiveMappingIntegrationTest.php:461` |
| 6.3-AC4 | Long description passes through `wp_kses_post()` — formatting survives, unsafe markup does not | **P0** | FULL (unit-only, pure function) | `Unit/ContentMappingTest.php:164`; `:205` asserts the title is **not** run through the post sanitiser; `:179` covers sanitising-to-empty falling back |
| 6.3-AC5 | Language selection follows the existing `image_language` / `include_languages` chain | P1 | FULL | `Unit/ContentMappingTest.php:240` (I-type via the existing chain); `Unit/CustomClassExtractorTest.php:348`,`:686` |
| 6.3-AC6 | A product whose title changes is **not** re-slugged; `update_slug_on_resync` untouched | P1 | FULL (unit-only) | `Unit/SlugResolverTest.php:356` (the resolver reads the raw payload, so a mapped title cannot reach it); composed with `:28` (`product_name` source resolves to null) and the upserter's `'' !== $slug` guard at `class-skwirrel-wc-sync-product-upserter.php:334` |
| 6.3-AC7 | A changed mapped content value is not skipped by the unchanged gate | P1 | FULL | `Unit/ContentHashTest.php:180` (each content mapping setting changes the sync signature), `:138`,`:159` |
| 6.3-AC8 | The three mappings work independently | P1 | FULL | `Unit/ContentMappingTest.php:222`; `Integration/NonDestructiveMappingIntegrationTest.php:512` |

**Translation completeness:** `Unit/FieldMappingTranslationsTest.php:100`,`:111` pin every Field-mapping string
into the POT and all seven locales.

### Story 6.5 — Document links show a readable name in the shop's language (FR-23, NFR-9)

| AC | Criterion | Pri | Coverage | Tests |
|---|---|---|---|---|
| 6.5-AC1 | Exact language match → `$doc['name']` is that entry's `product_attachment_title`, **and the frontend documents tab renders it as the link text** | P1 | FULL | `Unit/AttachmentHandlerDocumentNameTest.php:51` (resolver); `Integration/DocumentNamesIntegrationTest.php:184` (resolved title lands in the stored meta, not the filename), `:199` (frontend tab renders it as link text), `:217` (admin meta box), `:231` (the legacy all-in-one `upsert_product()` path writes it too), `:270` (exact match beats a prefix sibling) |
| 6.5-AC2 | Prefix match reuses the shared exact → prefix → first-entry chain, not a parallel implementation | P1 | FULL | `Unit/AttachmentHandlerDocumentNameTest.php:70`,`:85`,`:187` (image meta resolution unchanged by the shared-chain refactor); `Integration/DocumentNamesIntegrationTest.php:253` (nl-BE configured, only nl present — resolves end to end and renders) |
| 6.5-AC3 | Fallback: attachment title → `file_name` → URL basename → literal `Document`; never nameless (**NFR-9**) | **P0** | FULL | `Unit/AttachmentHandlerDocumentNameTest.php:104`,`:116`,`:128`,`:137`,`:144`,`:151` (the resolver never returns an empty string, whatever it is given); `Integration/DocumentNamesIntegrationTest.php:292` (untranslated document still renders, by filename), `:306` (an empty translation title falls through rather than making the document vanish from the tab) |
| 6.5-AC4 | Products synced before the story, holding raw filenames in `_skwirrel_document_attachments`, have their stored names refreshed on the next normal sync | P1 | FULL (integration-only) | `Integration/DocumentNamesIntegrationTest.php:327` (a product seeded in exactly the pre-3.14.0 state — meta present, holding the filename — is repaired by an ordinary `assign_media()` run, asserted both in the meta and in the rendered tab, with the stale name asserted as a precondition so the test measures a change); `:356` (the refresh is not a one-off — a later PIM rename lands too) |
| 6.5-AC5 | A title containing HTML is escaped at output (`esc_html`) in the documents tab and the admin meta box, exactly as today | P1 | FULL | `Unit/AttachmentHandlerDocumentNameTest.php:172` (the resolver returns HTML verbatim, never pre-escaped); `Integration/DocumentNamesIntegrationTest.php:379` (a `<script>` title is stored raw and rendered escaped in the frontend tab), `:403` (an `<img onerror>` title is escaped in the admin meta box) |
| 6.5-AC6 | The three quality gates pass | P2 | FULL | Executed this run: pest ✓, phpstan level 6 ✓, phpcs ✓ |

### Story 6.4 — The non-destructive guarantee is pinned by tests (NFR-9)

| AC | Criterion | Pri | Coverage | Tests |
|---|---|---|---|---|
| 6.4-AC1 | One case per mapping (stock, title, short, long) for absent/empty/malformed, on simple products and on variations for stock | **P0** | FULL | `Unit/NonDestructiveMappingTest.php:74`,`:79`,`:84`,`:111` (controls),`:121`,`:150`; `Integration/NonDestructiveMappingIntegrationTest.php:226`,`:240`,`:274`,`:296`,`:326`,`:461` |
| 6.4-AC2 | A response omitting `_custom_classes` entirely writes no mapped field and the run reports success | **P0** | FULL (integration-only) | `Integration/NonDestructiveMappingIntegrationTest.php:554` (full sync with no `_custom_classes` key succeeds, preserves every mapped field, raises no notice) |
| 6.4-AC3 | Stands alone as its own Pest file, never blocked on Story 1.14 | P2 | FULL | `tests/Unit/NonDestructiveMappingTest.php` and `tests/Integration/NonDestructiveMappingIntegrationTest.php` both exist and run independently |
| 6.4-AC4 | The three quality gates pass | P2 | FULL | Executed this run: pest ✓, phpstan level 6 ✓, phpcs ✓ |

**Bonus coverage beyond the AC set:** `Integration/NonDestructiveMappingIntegrationTest.php:629`,`:667`
extend the same guarantee to the pre-existing `prices_managed_outside_skwirrel` escape hatch — the precedent
NFR-9 was modelled on.

---

## Test Inventory

| Level | Test cases mapped | Criteria touched |
|---|---|---|
| Unit (`tests/Unit/`, stub bootstrap) | 66 | 25 |
| Integration (`tests/Integration/`, real WP+WC via wp-env) | 40 | 21 |
| E2E / API / Component | 0 | 0 |
| **Total unique mapped cases** | **106** across 14 files | — |

Skipped: 0 · Pending: 0 · Fixme: 0 · Blockers: none.

**No E2E layer exists in this project**, by design — the plugin has no frontend JS and no browser harness.
The integration suite rendering real admin and frontend HTML (`DOMXPath` for the settings screen, output
buffering for the documents tab and meta box) is the closest analogue and is counted as integration, not
E2E. Story 6.5's frontend-rendering criteria are now covered at that level.

---

## Coverage Heuristics

| Heuristic | Result |
|---|---|
| API endpoint coverage | Not applicable — Epic 6 adds no JSON-RPC method. It consumes `_custom_classes` and `_attachment_translations` already present in existing payloads. 0 endpoint gaps. |
| Auth / authz coverage | Not applicable to this epic (no new capability-gated surface). The settings screen it renders into is covered by Epic 5's `manage_woocommerce` tests. |
| Error-path coverage | **Strong.** Absent, empty, whitespace-only, non-numeric, `not_applicable`, malformed-reference, sanitises-to-empty, and whole-key-missing payloads are all exercised. This epic is unusually well covered on unhappy paths — that is its entire point. |
| Happy-path-only criteria | 0 |
| UI journey / UI state coverage | Not applicable (oracle is formal, not synthetic). Noted separately: the one *frontend* surface this epic touches — the documents tab — has no rendering test, which is what 6.5-AC1 and 6.5-AC5 record. |

---

## Gap Analysis

### Critical (P0) — none open

All nine P0 criteria are fully covered at both unit and integration level. The NFR-9 destructive-write
guarantee — the epic's defining constraint — is pinned from every angle: absent, empty, malformed,
trade-item-only, and whole-key-missing payloads, on simple products, on both variation paths, and across all
four mappings.

### High (P1) — none open

All three Story 6.5 shortfalls found earlier in this run were closed by
`tests/Integration/DocumentNamesIntegrationTest.php`, added and verified in this session:

| Was | Criterion | Now closed by |
|---|---|---|
| GAP-1 `NONE` | 6.5-AC4 — stored names never proven to refresh | `:327` seeds a product in the pre-3.14.0 state (meta present, holding the raw filename), asserts the stale name as a precondition, re-syncs, and asserts both the repaired meta and the repaired rendered tab. `:356` proves the repair is not a one-off. |
| GAP-2 `PARTIAL` | 6.5-AC1 — resolver-to-frontend wiring unverified | `:184` pins the stored meta, `:199` the frontend tab, `:217` the admin meta box, `:231` the legacy `upsert_product()` write site — which turned out to be a **second, independent** document-write path (`class-skwirrel-wc-sync-product-upserter.php:480`) alongside `assign_media()` at `:2544`. Both are now covered; only one would have been had the gap been closed narrowly. |
| GAP-3 `PARTIAL` | 6.5-AC5 — escaping asserted from one side only | `:379` and `:403` assert a `<script>` and an `<img onerror>` title are stored raw and rendered escaped, in the tab and the meta box respectively. |

**Mutation-verified.** The 12 new cases were run against the pre-story source (line 329 reverted to
`$att['file_name'] ?? $att['product_attachment_title'] ?? ''`): **10 of 12 failed**. The two that still
passed are the untranslated and empty-title cases, which correctly behave identically either way. The
suite therefore detects the exact regression it exists to prevent, rather than passing vacuously.

### Medium (P2) — none open

### Low — observations, not gaps

- **6.3-AC6 (slug preservation)** is proven by composition rather than end to end: `SlugResolverTest.php:356`
  pins that a mapped title cannot reach the resolver, `:28` pins that the default `product_name` source
  resolves to null, and the upserter guards on `'' !== $slug`. Each link is tested; the chain is not. Low
  risk, but an integration assertion on `post_name` surviving a re-sync with `update_slug_on_resync` enabled
  would close it properly.
- **6.2-AC3, 6.2-AC5, 6.4-AC2** are integration-only. That is the correct level for each — they are
  assertions about real WC data-store behaviour — so this is recorded for completeness, not as a concern.

---

## Gate Criteria

| Criterion | Required | Actual | Status |
|---|---|---|---|
| P0 coverage | 100% | 100% (9/9) | **MET** |
| P1 coverage | ≥80% (target 90%) | 100% (14/14) | **MET** |
| Overall coverage | ≥80% | 100% (29/29) | **MET** |
| Critical gaps open | 0 | 0 | MET |
| Collection status | COLLECTED | COLLECTED | MET |

Gate rule applied: **Rule 4** — P0 at 100%, P1 at or above the 90% target, overall at or above the 80%
minimum.

---

## Next Actions

None required for Epic 6. Coverage is complete at every priority level and all four quality gates are green.

Optional, carried forward as a low-priority observation rather than a gap:

1. **6.3-AC6 (slug preservation)** is proven by composition rather than end to end — see Low observations
   above. An integration assertion on `post_name` surviving a re-sync with `update_slug_on_resync` enabled
   would close it properly. Low risk; each link in the chain is individually tested.
2. **Story 1.14's regression-canary suite** remains `backlog`. Per 6.4-AC3 the non-destructive cases stand
   alone and were never blocked on it, but folding them in when 1.14 lands is the tidier end state.

## Notes on this run

- Both suites and both static gates were executed live against the working tree on 2026-08-27, so this is a
  measured result, not an inferred one.
- This run superseded the earlier same-day CONCERNS decision, first landing on FAIL. The difference was
  enumeration, not regression: Story 6.5's acceptance criteria were split more finely against the story
  text, which raised the P1 denominator and pushed a 79% result under the 80% floor. The underlying finding
  was unchanged and had already been recorded — Story 6.5 was the only under-covered story in the epic.
- That finding was then acted on in-session: `tests/Integration/DocumentNamesIntegrationTest.php` (12 cases)
  closed all three P1 shortfalls, and the full suite was re-run green. Integration went from 215 to 227
  passing with no cross-file pollution. The gate moved FAIL → **PASS**.
- Closing GAP-2 surfaced something the narrow fix would have missed: documents are written at **two**
  independent sites — `upsert_product()` (`class-skwirrel-wc-sync-product-upserter.php:480`, the legacy
  all-in-one path) and `assign_media()` (`:2544`, the phase method the current batch loop uses). Both are
  now covered.
