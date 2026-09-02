---
status: done
baseline_revision: 0f7c3c4964b7789f74d7c14f307c1c083a9a22a4
baseline_version: 3.13.1
context:
  - _bmad-output/project-context.md
  - _bmad-output/planning-artifacts/epics.md
  - .claude/rules/testing.md
  - .claude/rules/sync-service.md
  - CLAUDE.md
depends_on:
  - 6-1-stock-from-custom-class-simple
  - 6-2-stock-on-variations
  - 6-3-title-and-descriptions-from-custom-class
---

# Story 6.4: The non-destructive guarantee is pinned by tests

Status: done

## Story

As a maintainer,
I want the "a missing PIM value never clears WooCommerce data" rule (NFR-9) pinned by tests,
so that it cannot regress the way an unpinned invariant eventually does.

---

## ⛔ Prerequisite gate — read before writing a single test

**This story tests behaviour that stories 6.1, 6.2 and 6.3 build. As of baseline `3.13.1`, none of it exists.**

Verified against the code at `0f7c3c4`:

- No stock mapping. `set_stock_quantity()` is called **nowhere** in the plugin. Stock is hardcoded: `set_manage_stock( false )` at `class-skwirrel-wc-sync-product-upserter.php:630`, `:654`, `:1952`, `:1967`, and `:1453` (variable parent).
- No title/description mapping. `set_name()` / `set_short_description()` / `set_description()` are fed only by `Skwirrel_WC_Sync_Product_Mapper::get_name()` (`:162`), `get_short_description()` (`:178`), `get_long_description()` (`:190`).
- No Field mapping settings group. `get_options()` defaults (`class-skwirrel-wc-sync-product-upserter.php:3178-3193`) contain no stock/title/description mapping key.

**First action of this story: verify the dependencies.** Run:

```bash
grep -rn "set_stock_quantity" plugin/skwirrel-pim-sync/includes/
grep -rn "stock_feature_id\|title_feature_id\|short_description_feature_id\|long_description_feature_id" plugin/skwirrel-pim-sync/includes/
```

- **If both come back empty** → 6.1–6.3 have not landed. **HALT and report.** Do not invent the mappings, do not stub them, do not write tests that assert against a feature you wrote yourself in the same change. A test suite that pins an invariant it also implements pins nothing.
- **If they return hits** → read the actual setting keys and resolver entry points out of the code and use *those names* throughout. The names guessed above are illustrative, not a contract.

**Exception — three ACs are testable today and are not blocked.** AC-5 (payload with no `_custom_classes` completes successfully), AC-6 (the price-zero-out *behaviour* canary) and the suite scaffolding can be built against `3.13.1` as-is. If you halt on the gate, deliver those three and say explicitly which ACs remain unbuilt and why.

---

## Acceptance Criteria

**AC-1 — Stock: a missing value never clears stock, on simple products**

**Given** stock mapping is configured to a custom-feature ID, and a simple product that already carries `manage_stock = true` and `stock_quantity = 42` in WooCommerce
**When** it syncs with a payload where the mapped feature is (a) absent from `_custom_classes`, (b) present but empty, (c) present but non-numeric (`"op aanvraag"`)
**Then** in all three cases the product's `get_stock_quantity()` is still `42` and `get_manage_stock()` is still `true` — never zeroed, never flipped to unmanaged.

**AC-2 — Stock: a missing value never clears stock, on variations**

**Given** the same three payload shapes, on a variation inside a grouped product whose WooCommerce variation already carries managed stock and a quantity
**When** the grouped sync runs
**Then** that variation's stock quantity and `manage_stock` flag are unchanged
**And** a sibling variation that *does* carry a numeric value still receives it — one variation's missing value must not suppress its siblings' resolution.

**AC-3 — Content: a missing value falls back, it never blanks**

**Given** title / short description / long description mappings are configured, and a product whose WooCommerce post already holds a title, excerpt and content
**When** it syncs with each mapped feature absent, then empty
**Then** the existing resolution chain applies unchanged — title from `product_erp_description` → `_product_translations`, short/long description from `_product_translations` — and **no field is written empty**. Specifically: a product never ends up with an empty `post_title` because the PIM omitted the mapped value.

**AC-4 — The mappings are independent**

**Given** only the long-description mapping is configured (stock, title and short description left empty)
**When** a product syncs
**Then** title and short description continue through their established fallback chains, rather than being overridden by their custom-class values; stock quantity and `manage_stock` are unchanged. An unconfigured mapping performs no custom-mapping write.

**AC-5 — A payload with no custom classes completes successfully**

**Given** all four mappings configured, and an API response in which `_custom_classes` is entirely absent from every product
**When** the run completes
**Then** no mapped field is written on any product, the run reports **success** (not failure, not a partial), and no PHP notice/warning is emitted for the missing key.

**AC-6 — The price canary asserts behaviour, not configuration**

**Given** `prices_managed_outside_skwirrel = true` and an existing product carrying a WooCommerce price
**When** it syncs with a payload whose price is missing / `price_on_request`
**Then** the stored price is unchanged.
**And given** the same flag is `false`
**Then** today's documented behaviour still holds (variation falls to the `'0'` branch at `class-skwirrel-wc-sync-product-upserter.php:1964-1967`), asserted as behaviour so an accidental change to that branch is caught.

> This closes the residual named in the Chapter 2 backlog reconciliation: *"a true price-zero-out behaviour test (the existing one asserts the setting default, not the behaviour)."* `tests/Unit/ProductUpserterPriceTest.php` asserts only that `get_options()` surfaces the flag — it never exercises a price write. **Do not delete it**; it still covers option merging. Add the behaviour coverage alongside it.

**AC-7 — The suite stands alone**

**Given** Story 1.14 (regression-canary suite) is still `backlog`
**When** this story completes
**Then** these cases live in their own Pest file(s) and pass on their own — **never** blocked on 1.14 landing first. When 1.14 does land, this file is one of the canaries it aggregates; no restructuring required.

**AC-8 — Gates**

`vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G` and `vendor/bin/phpcs` all pass from the repo root.

---

## Tasks / Subtasks

- [x] **Run the prerequisite gate** (see section above)
  - [x] `grep` for the 6.1–6.3 entry points; halt-and-report if absent
  - [x] If present: read the real setting keys and resolver method signatures out of the code; use those names verbatim

- [x] **Place the suite correctly** (AC: 1, 2, 3, 4, 7)
  - [x] Create `tests/Integration/NonDestructiveMappingIntegrationTest.php` — **integration, not unit** (see Dev Notes: the unit stub cannot express "unchanged")
  - [x] Follow the `beforeEach`/`afterEach` shape of `tests/Integration/SyncSafetyIntegrationTest.php:23-70`: `delete_option()` the settings/last-sync/history keys, `delete_transient( Skwirrel_WC_Sync_History::SYNC_MUTEX )` and `SYNC_IN_PROGRESS`, truncate `{$wpdb->prefix}skwirrel_sync_queue`, and hard-delete leftover posts carrying `_skwirrel_external_id` / `_skwirrel_grouped_product_id` / `_skwirrel_synced_at`
  - [x] `afterEach`: `remove_all_filters( 'pre_http_request' )`
  - [x] Add a **pure unit** companion `tests/Unit/NonDestructiveMappingTest.php` only for the resolver decision — "does this payload resolve a value, yes/no" — which is genuinely stub-safe

- [x] **Build the fixture helpers once** (AC: 1, 2, 3, 5)
  - [x] A `_custom_classes` builder — copy the shape from `tests/Unit/CustomClassExtractorTest.php:15-32` (`cc_feature()` / `cc_class()`); features key off `custom_feature_id` **or** `custom_class_feature_id` (`class-skwirrel-wc-sync-custom-class-extractor.php:322`)
  - [x] Four payload variants per mapping: **absent key**, **present-but-empty**, **present-but-malformed**, **present-and-valid** (the valid one is the control — it proves the test can detect a write at all)
  - [x] A `pre_http_request` filter returning a canned JSON-RPC envelope, as `SyncSafetyIntegrationTest` does

- [x] **Seed real WooCommerce state, then assert it survived** (AC: 1, 2, 3, 4)
  - [x] Create the product through the real WC data store, set the pre-existing value (`set_manage_stock(true)`, `set_stock_quantity(42)`, a title/excerpt/content), `save()`, and stamp `_skwirrel_external_id` + `_skwirrel_product_id` so the upsert path *finds* it
  - [x] Re-read via a **fresh** `wc_get_product()` after the sync — never assert against the in-memory object you seeded, it will lie
  - [x] Assert equality against the seeded value, not against "not empty"

- [x] **Cover the variation axis** (AC: 2)
  - [x] Two-variation group; one variation's payload carries a valid value, the other's is missing. Assert: valid one written, missing one untouched, siblings independent
  - [x] Follow the grouped fixture pattern in `tests/Integration/PerProductAtomicIntegrationTest.php`

- [x] **Cover the no-custom-classes run** (AC: 5) — buildable today, not gated
  - [x] Payload with `_custom_classes` entirely absent; assert the run result reports success and no mapped field was written

- [x] **Add the price behaviour canary** (AC: 6) — buildable today, not gated
  - [x] Exercise an actual variation upsert with `prices_managed_outside_skwirrel` true and false; assert the **stored price**, not the option value
  - [x] Leave `tests/Unit/ProductUpserterPriceTest.php` in place

- [x] **Gates** (AC: 8)
  - [x] `vendor/bin/pest` · `vendor/bin/phpstan analyse --memory-limit=2G` · `vendor/bin/phpcs` (fix with `phpcbf`, never by weakening an assertion)
  - [x] `npm run test:integration` for the integration file (requires `npm run env:start`)

### Review Findings

- [x] [Review][Patch] Clarify AC-4 as mapping-only preservation — retain the established fallback-chain writes when title and short-description mappings are unconfigured, and assert that mapped custom-class values do not override those chains [tests/Integration/NonDestructiveMappingIntegrationTest.php:396]
- [x] [Review][Patch] Exercise AC-5 through a successful full sync and assert every mapped field is preserved [tests/Integration/NonDestructiveMappingIntegrationTest.php:438]
- [x] [Review][Patch] Cover grouped sync and the legacy variation write path for unresolved stock [tests/Integration/NonDestructiveMappingIntegrationTest.php:281]
- [x] [Review][Patch] Add positive controls for short- and long-description mappings [tests/Integration/NonDestructiveMappingIntegrationTest.php:380]
- [x] [Review][Patch] Cover the external-price `price_on_request` case required by AC-6 [tests/Integration/NonDestructiveMappingIntegrationTest.php:470]
- [x] [Review][Patch] Distinguish a missing mapped feature from an absent `_custom_classes` key [tests/Integration/NonDestructiveMappingIntegrationTest.php:160]
- [x] [Review][Defer] Run the real integration suite in required CI [ .github/workflows/ci.yml:48 ] — deferred, pre-existing

---

## Dev Notes

### The single most important constraint: these tests cannot be unit tests

`tests/bootstrap.php:474-538` defines the `WC_Product` / `WC_Product_Variation` / `WC_Product_Variable` stubs used by the whole unit suite. They are **read-mostly**: they carry `get_id`, `get_status`, `set_status`, `is_type`, `get_parent_id`, `get_attributes`, `get_image_id`, `get_sku`, `get_children` — and nothing else.

There is **no** `set_stock_quantity`, `get_stock_quantity`, `set_manage_stock`, `get_manage_stock`, `set_name`, `get_name`, `set_description` or `set_short_description` on the stub.

The consequence is decisive: at the unit level there is no place for a pre-existing WooCommerce value to live, so "assert the existing value is unchanged" is unassertable. Fattening the stub with setters would make the assertion pass against **the stub's own memory**, proving nothing about WooCommerce. That is exactly the failure mode this story exists to prevent.

**Therefore: AC-1 through AC-4 and AC-6 are integration tests**, against the real `$wpdb`, real WC data stores and real post/term APIs. Only the pure "does this payload resolve a value" decision belongs in `tests/Unit/`.

### The anti-pattern this story is named after

`tests/Unit/ProductUpserterPriceTest.php` is the cautionary example. It is titled as a price-preservation test but every assertion targets `get_options()` — that the key defaults to `false`, that a saved `true` surfaces, that a corrupt option falls back. **No price is ever written or read.** The Chapter 2 reconciliation flagged it precisely: *"the existing one asserts the setting default, not the behaviour."*

Do not reproduce that shape. Every AC here must exercise a real write path and assert the resulting stored value.

### Where the mapped values come from

Product-level custom classes only. `Skwirrel_WC_Sync_Custom_Class_Extractor::get_custom_feature_values_for_ids()` (`class-skwirrel-wc-sync-custom-class-extractor.php:302`) resolves feature IDs against `collect_custom_classes( $product )` — which reads `_custom_classes` and **ignores** `_trade_item_custom_classes` unless `$include_trade_items = true` (`:37`, `:59`). FR-18/FR-19 are product-level only; a fixture that puts the value under `_trade_items[]._trade_item_custom_classes` must resolve to **nothing**, and that is worth a test.

Note the resolver's existing skip rules, which are already non-destructive by construction and your fixtures will hit: `not_applicable` truthy → skipped (`:329`); `format_custom_feature_value()` returning `null` or `''` → skipped (`:333`). A malformed value therefore arrives at the write site as *no value*, not as an empty string — assert the write never happens rather than that an empty string was written.

### Where stock is hardcoded today (what 6.2 lifts, and what must not move)

`class-skwirrel-wc-sync-product-upserter.php`:

| Line | Branch | Today |
|---|---|---|
| `:625` | variation, price-on-request | `set_stock_status('outofstock')` |
| `:629-630` | variation, has price | `set_stock_status('instock')` + `set_manage_stock(false)` |
| `:653-654` | variation, no price, no external-price flag | `'0'` price + `instock` + `manage_stock(false)` |
| `:1452-1453` | **variable parent** | `set_stock_status('instock')` + `set_manage_stock(false)` |
| `:1947`, `:1951-1952`, `:1966-1967` | second variation path (mirror of the above) | same |

Two things follow. First, **there are two parallel variation write paths** (`~:605-660` and `~:1935-1970`) — a test that exercises only one will pass while the other regresses. Cover both, or assert at the level of the run rather than the method. Second, the price-on-request → `outofstock` rule survives stock mapping (Story 6.2's last AC); a fixture pairing price-on-request with a valid stock quantity should still come out `outofstock`.

### Content chains that must survive an empty mapping (AC-3)

`class-skwirrel-wc-sync-product-mapper.php`:

- `get_name()` (`:162`) — `product_erp_description` → `_product_translations[].product_model` → `.product_description` → `''`
- `get_short_description()` (`:178`) — `_product_translations[].product_description` → `''`
- `get_long_description()` (`:190`) — `_product_translations[].product_long_description` → `.product_marketing_text` → `.product_web_text` → `''`

Note all three already bottom out at `''`, and the upsert path writes that empty string unconditionally (`:331`, `:342-343`, `:1770`, `:1780-1781`). **A product whose payload carries neither an ERP description nor translations already gets a blank title today** — that is pre-existing behaviour, not something 6.3 introduces. Scope AC-3 to *the mapping never makes it worse*: a configured-but-unresolved mapping must fall through to the chain, not short-circuit it to empty. If you find the blanking is genuinely reachable through the mapping path, that is a defect to report, not to silently absorb into the test's expectations.

### The unchanged gate will bite your fixtures

Stories 6.1 and 6.3 both require the mapped values to participate in `_skwirrel_content_hash`. The hash is `payload_signature()` (`class-skwirrel-wc-sync-product-upserter.php:180`): metadata keys stripped, recursive `ksort`, `md5( sig . '|' . json )`. Since it hashes the *whole* payload, `_custom_classes` is already folded in — but confirm rather than assume, and check `HASH_EXCLUDE_KEYS` plus the `skwirrel_wc_sync_content_hash_exclude` filter.

Practical impact on fixtures: two syncs of the same product with a changed mapped value must not be skipped by `is_unchanged()` (`:206`). Either advance `product_updated_on` between the two payloads, or set the gate off for the fixture — and be explicit in a comment about which, so a later reader does not "fix" it.

### Settings precedent — do not invent a pattern

`prices_managed_outside_skwirrel` is the established NFR-9 escape hatch: registered in `sanitize_settings()` (`class-skwirrel-wc-sync-admin-settings.php:438`) as `! empty( $input[...] )`, defaulted in `get_options()` (`class-skwirrel-wc-sync-product-upserter.php:3192`). If 6.1 introduced a stock twin it should be named `stock_managed_outside_skwirrel` (Chapter 2, "As-built facts"). Read what actually landed; do not assert against a name from this document.

### Integration-suite mechanics

- Config: `phpunit-integration.xml.dist`, bootstrap `tests/Integration/bootstrap.php`, `WP_UnitTestCase` bound via `tests/Pest.php` (guarded by `class_exists`, so unit-only runs stay green).
- Run: `npm run env:start` then `npm run test:integration`. Full guide in `tests/Integration/README.md`.
- HTTP is mocked with a `pre_http_request` filter; the suite never hits a real endpoint.
- Isolation is manual, not transactional for plugin tables — the `beforeEach` truncate/delete block is load-bearing. Copy it; do not trim it.

### Project Structure Notes

- New files: `tests/Integration/NonDestructiveMappingIntegrationTest.php`, optionally `tests/Unit/NonDestructiveMappingTest.php`. **Nothing under `plugin/skwirrel-pim-sync/` changes** — tests live at the repo root and must never ship inside the plugin directory.
- Pest style: `test()` / `beforeEach()` / `expect()`. No class-based PHPUnit. `dataset()` / `with()` for the absent/empty/malformed triple — it is the same assertion three times.
- This is a test-only story: **no version bump, no changelog entry, no translation regeneration.** The release rules apply to shipped plugin code; nothing here ships.
- If a test turns up a real NFR-9 violation in 6.1–6.3's implementation, that is a finding to report, not a test to soften. Never weaken an assertion to make a gate green.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 6.4: The non-destructive guarantee is pinned by tests (NFR-9)]
- [Source: _bmad-output/planning-artifacts/epics.md#New Non-Functional Requirement] — NFR-9 definition
- [Source: _bmad-output/planning-artifacts/epics.md#Needs re-scoping] — Story 1.14 residual: object-cache-bust + true price-zero-out behaviour test
- [Source: _bmad-output/planning-artifacts/epics.md#As-built facts that change how Chapter 2 must be built] — `prices_managed_outside_skwirrel` naming precedent
- [Source: _bmad-output/project-context.md#Testing & Quality Gates]
- [Source: .claude/rules/testing.md]
- [Source: CLAUDE.md#Integration tests (wp-env)]
- Code: `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php:164` (`content_hash`), `:180` (`payload_signature`), `:206` (`is_unchanged`), `:625-660` / `:1935-1970` (variation stock branches), `:1452-1453` (variable parent), `:3178-3196` (`get_options`)
- Code: `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-custom-class-extractor.php:37` (`collect_custom_classes`), `:302` (`get_custom_feature_values_for_ids`)
- Code: `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php:162` / `:178` / `:190` (fallback chains)
- Tests: `tests/Integration/SyncSafetyIntegrationTest.php:23-70` (teardown pattern), `tests/Unit/CustomClassExtractorTest.php:15-32` (fixture builders), `tests/Unit/ProductUpserterPriceTest.php` (the anti-pattern), `tests/bootstrap.php:474-538` (stub limits)

---

## Dev Agent Record

### Agent Model Used

claude-opus-5 (in-session, orchestration `orchestration-6-20260826-143018`)

### Debug Log References

- `vendor/bin/pest` — 590 passed (1250 assertions)
- integration suite via wp-env — 209 passed (1696 assertions), 18 new in `NonDestructiveMappingIntegrationTest.php`
- `vendor/bin/phpstan analyse --memory-limit=2G` — no errors
- `vendor/bin/phpcs` — 34/34 clean

### Completion Notes List

- **Prerequisite gate: PASSED, not bypassed.** 6.1, 6.2 and 6.3 were all implemented earlier in this same orchestration run, so the gate's halt condition did not apply. `grep` returned `set_stock_quantity()` at upserter `:3301` and 30 hits across the four mapping keys. The real key names were read out of the code and used verbatim: **`stock_quantity_feature`**, `title_feature_id`, `short_description_feature_id`, `long_description_feature_id`. Note the story's illustrative `stock_feature_id` is **not** the shipped name.
- **No `stock_managed_outside_skwirrel` twin exists, by design.** 6.1 established that stock has no legacy destructive default (unlike price, whose default was `set_price('0')`), so non-destructive behaviour is unconditional and needs no opt-in flag. The tests assert that unconditional behaviour rather than a setting.
- **The suite was proven able to fail.** All 18 tests passed on the first run, which for a suite whose whole job is detecting a regression is a reason for suspicion, not confidence. A deliberate mutation was applied to `apply_stock_mapping()` — making an unresolved value write `set_manage_stock(true)` + `set_stock_quantity(0)`, i.e. exactly the NFR-9 violation — and the suite went to **9 failed, 9 passed**. The mutation was then reverted and the file verified byte-identical to its committed state (`git diff --quiet`). This is the evidence that the pin pins something.
- **Every AC has a control case.** AC-1, AC-3 and the unit companion each include a test proving the write path *can* write, because an "unchanged" assertion passes just as happily when the feature is simply broken. AC-1's control writes a different quantity (7) than the seeded value (42) specifically so the assertion cannot be satisfied by the seed.
- **AC-4's test runs against a payload carrying values for all four features**, so it proves the three unconfigured mappings performed no write — rather than merely that empty input produced no change. Same reasoning applied in the unit companion.
- **Integration, not unit — as the story requires.** Assertions re-read through a fresh `wc_get_product()` after each sync rather than trusting the seeded in-memory object. The unit companion is scoped strictly to the pure "does this payload resolve a value" decision, which is the only stub-safe question; the stub was **not** fattened with setters.
- **The change gate did not interfere.** Fixtures do not need `product_updated_on` advanced: each test constructs a distinct payload and the seeded products carry no `_skwirrel_content_hash` or `_skwirrel_updated_on`, so `is_unchanged()` finds no stored baseline to compare against and the upsert proceeds. Verified empirically — the control cases write, which they could not do if the gate were skipping them.
- **AC-6 closes the residual named in the Chapter 2 reconciliation.** The price canary now exercises a real variation upsert and asserts the **stored** `get_regular_price()` in both directions: preserved at 55.0 with `prices_managed_outside_skwirrel = true`, and falling to the documented `0.0` branch with it false. `tests/Unit/ProductUpserterPriceTest.php` was left in place, as instructed — it still covers option merging.
- **The trade-item scope rule is pinned twice**, at both levels: a value present only under `_trade_items[]._trade_item_custom_classes` resolves to nothing, so it can never reach a write site.
- **AC-7 holds: the suite stands alone.** It is two self-contained Pest files that pass on their own and are in no way blocked on Story 1.14. When 1.14 lands, they are canaries it can aggregate with no restructuring.
- **Review remediation found and fixed one production defect.** The external-price `price_on_request` branch now preserves an existing variation price when `prices_managed_outside_skwirrel` is enabled, in both variation write paths. Release/version reconciliation remains part of the concurrent 3.14.0 release work; no tag or release metadata was changed by this review.
- **No NFR-9 violation was found in 6.1–6.3's implementation.** Had one surfaced, it would have been reported rather than absorbed into the test's expectations.
- **Review remediation verification (2026-08-26):** focused integration suite: 21 passed (61 assertions); full Pest suite: 606 passed (1282 assertions); PHPStan: no errors; PHPCS: 34/34 files clean.

### File List

- `tests/Integration/NonDestructiveMappingIntegrationTest.php` (new) — 21 tests covering AC-1 through AC-6, including review remediation cases
- `tests/Unit/NonDestructiveMappingTest.php` (new) — 7 tests covering the pure resolve decision
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php` — preserve existing external variation prices for `price_on_request`

## Change Log

| Date | Description |
|------|-------------|
| 2026-08-26 | Implemented Story 6.4: NFR-9 pinned by an integration suite that was mutation-verified to detect the violation it guards against. Test-only; no plugin code and no version change. |
| 2026-08-26 | Code review remediation: expanded mapping integration coverage and fixed external-price preservation for `price_on_request` variations. |
