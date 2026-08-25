---
status: ready-for-dev
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

Status: ready-for-dev

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
**Then** title, short description, stock quantity and `manage_stock` are byte-for-byte what they were before — an unconfigured mapping performs **no write at all**, not a write of the same value.

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

- [ ] **Run the prerequisite gate** (see section above)
  - [ ] `grep` for the 6.1–6.3 entry points; halt-and-report if absent
  - [ ] If present: read the real setting keys and resolver method signatures out of the code; use those names verbatim

- [ ] **Place the suite correctly** (AC: 1, 2, 3, 4, 7)
  - [ ] Create `tests/Integration/NonDestructiveMappingIntegrationTest.php` — **integration, not unit** (see Dev Notes: the unit stub cannot express "unchanged")
  - [ ] Follow the `beforeEach`/`afterEach` shape of `tests/Integration/SyncSafetyIntegrationTest.php:23-70`: `delete_option()` the settings/last-sync/history keys, `delete_transient( Skwirrel_WC_Sync_History::SYNC_MUTEX )` and `SYNC_IN_PROGRESS`, truncate `{$wpdb->prefix}skwirrel_sync_queue`, and hard-delete leftover posts carrying `_skwirrel_external_id` / `_skwirrel_grouped_product_id` / `_skwirrel_synced_at`
  - [ ] `afterEach`: `remove_all_filters( 'pre_http_request' )`
  - [ ] Add a **pure unit** companion `tests/Unit/NonDestructiveMappingTest.php` only for the resolver decision — "does this payload resolve a value, yes/no" — which is genuinely stub-safe

- [ ] **Build the fixture helpers once** (AC: 1, 2, 3, 5)
  - [ ] A `_custom_classes` builder — copy the shape from `tests/Unit/CustomClassExtractorTest.php:15-32` (`cc_feature()` / `cc_class()`); features key off `custom_feature_id` **or** `custom_class_feature_id` (`class-skwirrel-wc-sync-custom-class-extractor.php:322`)
  - [ ] Four payload variants per mapping: **absent key**, **present-but-empty**, **present-but-malformed**, **present-and-valid** (the valid one is the control — it proves the test can detect a write at all)
  - [ ] A `pre_http_request` filter returning a canned JSON-RPC envelope, as `SyncSafetyIntegrationTest` does

- [ ] **Seed real WooCommerce state, then assert it survived** (AC: 1, 2, 3, 4)
  - [ ] Create the product through the real WC data store, set the pre-existing value (`set_manage_stock(true)`, `set_stock_quantity(42)`, a title/excerpt/content), `save()`, and stamp `_skwirrel_external_id` + `_skwirrel_product_id` so the upsert path *finds* it
  - [ ] Re-read via a **fresh** `wc_get_product()` after the sync — never assert against the in-memory object you seeded, it will lie
  - [ ] Assert equality against the seeded value, not against "not empty"

- [ ] **Cover the variation axis** (AC: 2)
  - [ ] Two-variation group; one variation's payload carries a valid value, the other's is missing. Assert: valid one written, missing one untouched, siblings independent
  - [ ] Follow the grouped fixture pattern in `tests/Integration/PerProductAtomicIntegrationTest.php`

- [ ] **Cover the no-custom-classes run** (AC: 5) — buildable today, not gated
  - [ ] Payload with `_custom_classes` entirely absent; assert the run result reports success and no mapped field was written

- [ ] **Add the price behaviour canary** (AC: 6) — buildable today, not gated
  - [ ] Exercise an actual variation upsert with `prices_managed_outside_skwirrel` true and false; assert the **stored price**, not the option value
  - [ ] Leave `tests/Unit/ProductUpserterPriceTest.php` in place

- [ ] **Gates** (AC: 8)
  - [ ] `vendor/bin/pest` · `vendor/bin/phpstan analyse --memory-limit=2G` · `vendor/bin/phpcs` (fix with `phpcbf`, never by weakening an assertion)
  - [ ] `npm run test:integration` for the integration file (requires `npm run env:start`)

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

### Debug Log References

### Completion Notes List

### File List
