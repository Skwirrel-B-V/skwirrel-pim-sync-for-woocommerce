# Story 6.2: Stock quantity on variations

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner selling variable products,
I want each variation to carry its own stock quantity from Skwirrel,
so that variable products report availability as accurately as simple ones.

## Prerequisite — Story 6.1 owns everything this story consumes

**6.1 is `backlog`; verified against the working tree at 3.13.1 there is no stock mapping in the code** — no `stock*` key in `Product_Upserter::get_options()`, no numeric resolver on the extractor, no Field mapping group in the dashboard.

**Build 6.1 first.** This story is a thin extension of it: 6.1 ships the setting, the resolver, the request flags and the `apply_stock_mapping()` helper on the upserter, and deliberately leaves the variation paths alone (its own AC 8). 6.2 is "call that helper from the two variation paths, and stop the hardcoded writes from clobbering it."

**What 6.1 hands you (confirm each by reading the code, not by trusting this table):**

| Piece | Where | Shape |
|---|---|---|
| Setting key | `skwirrel_wc_sync_settings`, defaulted in `Product_Upserter::get_options()` (`:3178`) | Empty string = mapping off. **6.1 picks the exact key name — read it out of `get_options()`, do not guess and do not add a second one.** |
| Resolver | `Skwirrel_WC_Sync_Custom_Class_Extractor::resolve_numeric_feature_value( array $product, string $mapping ): ?float` | Pure, product-level `_custom_classes` only, raw `numeric_value`, `null` for absent/empty/`not_applicable`/non-numeric. Delegated through `Skwirrel_WC_Sync_Product_Mapper`. |
| Write helper | `Skwirrel_WC_Sync_Product_Upserter::apply_stock_mapping( WC_Product $wc_product, array $product ): void` | Resolves + writes `set_manage_stock( true )` / `set_stock_quantity()`. No-ops when the setting is empty or the value is `null`. Never writes `stock_status`. |
| Payload flags | `Sync_Service::begin_run()` (`:274-310`) and the two other request builders (`:1814`, `:2090`) | Request `include_custom_classes` when the mapping is non-empty. |

`apply_stock_mapping()` is typed `WC_Product`, and `WC_Product_Variation extends WC_Product` — **it already works on a variation unchanged. Do not write a variation-specific copy of it.** If 6.1 landed with a narrower signature, widen 6.1's helper rather than forking it.

If you are forced to build 6.2 before 6.1, build 6.1 in full first. Do not ship half of 6.1 inline here.

## Acceptance Criteria

**1 — A resolved quantity is written per variation**

**Given** the stock mapping is configured and a variation's own Skwirrel product payload carries a numeric value for the mapped feature
**When** the grouped sync runs
**Then** that variation is saved with `set_manage_stock( true )` and `set_stock_quantity( <value> )`
**And** each variation resolves the mapping against **its own** payload's product-level `_custom_classes` — variations are themselves Skwirrel products, so this is a per-variation resolution, never a value inherited from the group or the parent.

**2 — Both variation write paths are covered**

**Given** the two live variation paths, `create_or_update_variation()` (`:1863`) and `upsert_product_as_variation()` (`:580`)
**When** the change lands
**Then** both call the shared helper. Fixing only the first leaves single-product resync from the product editor silently stockless; fixing only the second leaves every normal run stockless.

**3 — The hardcoded assumptions no longer clobber a resolved quantity**

**Given** the price branches that hardcode `set_manage_stock( false )` and `set_stock_status( 'instock' )` (`:629-630`, `:653-654`, `:1951-1952`, `:1966-1967`)
**When** the stock mapping is configured
**Then** those writes no longer override the resolved stock state.

**4 — Unconfigured mapping is byte-for-byte today's behaviour**

**Given** the mapping setting is empty (the default)
**When** any variation syncs
**Then** both paths execute exactly as they do at 3.13.1 — same writes, same order, no stock-related read added. A shop that never opts in sees no diff at all.

**5 — A missing value never wipes a variation's stock (NFR-9)**

**Given** the mapping is configured but a variation's feature is absent, empty, `not_applicable` or non-numeric
**When** the sync runs
**Then** that variation's existing `manage_stock`, `stock_quantity` and `stock_status` are left **exactly** as they were — not zeroed, not flipped to unmanaged, not forced to `instock`
**And** the skip is logged at verbose level only, matching the `prices_managed_outside_skwirrel` branch's register.

**6 — Siblings are independent**

**Given** a group where one variation resolves a quantity and another does not
**When** the group syncs
**Then** the resolving one gets its quantity and the non-resolving one is untouched. Neither outcome depends on the other, and neither aborts the group.

**7 — Price-on-request keeps its out-of-stock treatment**

**Given** a price-on-request variation
**When** stock mapping is configured
**Then** it still ends up `outofstock`. Stock mapping does not override that rule, whatever quantity resolves.

**8 — Parent aggregation uses the existing mechanism**

**Given** variations with resolved stock
**When** the group finishes assembling
**Then** the variable parent's aggregate stock status comes from the `WC_Product_Variable::sync_stock_status( $wc_variable_id )` call that **already runs** after variation save in both paths (`:835`, `:2110`) — no parallel mechanism, no new hook, no new call site
**And** the parent's own `set_manage_stock( false )` / `set_stock_status( 'instock' )` at `:1452-1453` stays as it is: parent-level stock stays unmanaged, and `sync_stock_status()` recomputes the parent's status from its children afterwards.

**9 — A stock-only change still lands on a delta run**

**Given** a variation whose mapped stock value changed upstream while nothing else about it did
**When** a delta sync runs
**Then** it is not skipped by the change gate at `:1902-1934`. Verify this rather than building anything: `_custom_classes` is part of the payload hashed by `payload_signature()` (`:179`), which excludes only `product_updated_on`.

**10 — Gates pass**

`vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G` (level 6) and `vendor/bin/phpcs`, all green, run from the repo root.

## Tasks / Subtasks

- [x] **Task 0 — Confirm 6.1's contract in the code** (AC: 1)
  - [x] `grep -rn "apply_stock_mapping\|resolve_numeric_feature_value" plugin/` — both must exist. If not, stop: 6.1 has not landed.
  - [x] Read the actual setting key out of `Product_Upserter::get_options()` `$defaults` and use that name verbatim throughout.
  - [x] Confirm `apply_stock_mapping()`'s parameter is typed `WC_Product` (not `WC_Product_Simple`). Widen 6.1's signature if needed; do not fork the helper.

- [x] **Task 1 — A single suppression decision, shared by both paths** (AC: 3, 4, 5, 7)
  - [x] Add one **pure** private helper to `Product_Upserter` — no `get_option()`, no WC calls — e.g.
        `private static function stock_mapping_governs( bool $mapping_active ): bool`, or fold it into a small decision method returning the flag both call sites read. Keep it trivial and testable.
  - [x] Rule it encodes: when the mapping setting is non-empty, the legacy `set_manage_stock( false )` / `set_stock_status( 'instock' )` writes in the price branches are **suppressed** — whether or not this particular variation resolved a value. That is what makes AC 5 hold: a configured-but-unresolved variation must be left alone, not reset to today's unmanaged/instock default.
  - [x] When the setting is empty, the flag is false and nothing changes (AC 4).
  - [x] Do not duplicate the guard expression at the two call sites. 2.6's review lesson: one chokepoint, guards inside it — see Dev Notes.

- [x] **Task 2 — Wire `create_or_update_variation()` (`:1863`)** (AC: 1, 2, 3, 5, 7)
  - [x] This is the path every normal run uses (`Sync_Service:916`, `:2194`). Do it first.
  - [x] Read the suppression flag once before the price branch at `:1944-1968`.
  - [x] Guard the four legacy writes at `:1951-1952` and `:1966-1967` behind `! $suppressed`. Leave the price writes themselves untouched — including the `prices_managed_outside_skwirrel` branch (`:1957`), which must keep writing nothing.
  - [x] Call `$this->apply_stock_mapping( $variation, $product )` after the price branch and before `$variation->save()` (`:2096`).
  - [x] Re-assert `set_stock_status( 'outofstock' )` for the price-on-request case as the last stock word before save (AC 7).
  - [x] Do not touch the change-gate early returns at `:1919`/`:1930` — they must keep stamping `_skwirrel_synced_at`.

- [x] **Task 3 — Wire `upsert_product_as_variation()` (`:580`)** (AC: 1, 2, 3, 5, 7)
  - [x] Same change against the price branch at `:620-654`, helper call before `$variation->save()`.
  - [x] **Read this method in full first.** It is a near-duplicate of Task 2's but not identical: no change gate, no `guard_revive_from_trash()`, different return type (`string`, not `array`). A blind copy-paste between the two breaks something.

- [x] **Task 4 — Verify, don't build** (AC: 8, 9)
  - [x] Confirm both paths already call `WC_Product_Variable::sync_stock_status()` after save (`:835`, `:2110`). Add nothing.
  - [x] Confirm the parent writes at `:1452-1453` are left as they are.
  - [x] Confirm `_custom_classes` reaches the *variation* payloads, not just the simple-product ones: variations are written from the ordinary product payloads queued by the catalogue fetch, so 6.1's `include_custom_classes` flag work at `Sync_Service:274-310` / `:1814` / `:2090` already covers them. Spot-check one queued variation payload with verbose logging rather than assuming.
  - [x] Confirm the mapping key is absent from `compute_sync_signature()`'s `$ignore` denylist (`Sync_Service:2376-2392`).

- [x] **Task 5 — Tests** (AC: 1, 5, 6, 10)
  - [x] `tests/Unit/VariationStockMappingTest.php` (Pest): the Task 1 decision helper across configured/unconfigured; and the resolver driven against variation-shaped payloads — value present, absent, empty, non-numeric, `not_applicable`, and present only under `_trade_item_custom_classes` (must be `null`, proving product-level scope).
  - [x] `tests/Integration/VariationStockIntegrationTest.php` against real WC: quantity lands on the variation; a variation with a pre-set quantity and no mapped value keeps it exactly (AC 5); a sibling with a value still resolves (AC 6); the parent's aggregate status reflects its children after `sync_stock_status()` (AC 8); a price-on-request variation ends `outofstock` (AC 7).
  - [x] **The unit bootstrap's `WC_Product` stub has no stock setters** (`tests/bootstrap.php:475-535`). Do not try to drive the full upsert method from a unit test — that is what the pure helper and the integration suite are for. Extend the stub only additively, and only if a test genuinely needs it.
  - [x] `tests/Unit/ProductUpserterPriceTest.php` is the reference recipe for constructing the upserter under the stub bootstrap (all seven collaborators in `beforeEach()`, `ReflectionMethod` for private helpers). Follow it verbatim.
  - [x] Do not weaken an existing assertion, and do not regenerate `phpstan-baseline.neon` to hide a new finding.

- [x] **Task 6 — Ship** (AC: 10)
  - [x] Run all three gates from the repo root; fix findings on sight.
  - [x] Version bump, `CHANGELOG.md` + `readme.txt` entries — via `/release`. This story adds no new user-facing strings (6.1 owns the settings copy), so no `.pot`/`.po`/`.mo` work unless a log string you add is translatable (log strings are not).

## Dev Notes

### Files to touch — all in `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php`

| Symbol | Line | Current state | This story |
|---|---|---|---|
| `upsert_product_as_variation()` | `580` | Legacy string-returning path. Still live: reached via `Sync_Service:1776`/`:1898` for single-product resync. Price branch `620-654` hardcodes `instock` + `manage_stock(false)` in two of four branches. | Suppress those; call `apply_stock_mapping()`. |
| `create_or_update_variation()` | `1863` | Current queue path (`Sync_Service:916`, `:2194`). Change gate `1902-1934`, trash-revive guard, price branch `1944-1968`, save `2096`, parent sync `2106-2118`. | Same change. |
| `create_variable_product_from_group()` | `1452-1453` | Parent: `set_stock_status('instock')` + `set_manage_stock(false)`. | **Leave both.** Correct WooCommerce modelling for a variable parent; `sync_stock_status()` recomputes status from children afterwards. |
| `get_options()` | `3178` | Merges hardcoded defaults over the saved option. `prices_managed_outside_skwirrel` is read as `! empty( $this->get_options()[...] )` at four sites. | Read 6.1's key the same way. |

No new class, no new file under `includes/`, no `require_once` in `skwirrel-pim-sync.php`. Two new test files.

### The trap that will bite you

`get_custom_feature_values_for_ids()` (`custom-class-extractor:302`) is the method you will reach for by instinct. **It is not the resolver.** It routes through `format_custom_feature_value()` (`:465`), which for a type-`N` feature returns `"{numeric_value} {unit}"` — `"12 st"`. Feeding that to `set_stock_quantity()` gives you `12` on a good day and `0` on a bad one. Use 6.1's `resolve_numeric_feature_value()`, which reads the raw `numeric_value`.

Second trap: `collect_custom_classes()` defaults `$include_trade_items` to `false`, but `get_grouped_class_features()` defaults it to `true`. FR-18 is product-level only, and trade-item-level mapping is explicitly out of scope in the epic. If you touch either, pass the flag explicitly.

### Existing invariants you must not break

- **`_skwirrel_synced_at` is stamped on every variation path**, including the unchanged-gate early returns at `:1919`/`:1930`. A variation that stops being stamped gets falsely trashed by the stale purge.
- **The `prices_managed_outside_skwirrel` branch (`:631`, `:1957`) deliberately writes nothing** — price *and* stock preserved for shops running an external ERP price feed. When the mapped value is `null`, your change must add no write there. When a quantity resolves, writing stock there is correct and intended: it is the PIM speaking about stock, not about price.
- **Content hash covers the whole raw payload** (`payload_signature()`, `:179`), excluding only `product_updated_on`. `_custom_classes` participates for free — AC 9 is verification, not code.
- **`compute_sync_signature()` is a denylist** (`Sync_Service:2369`), so a new output setting busts the gate automatically. Just do not add the key to `$ignore`.
- **Never write `stock_status` alongside a managed quantity.** WooCommerce derives status from `manage_stock` + quantity + backorders on save; forcing it fights `wc_update_product_stock_status()` and the parent aggregation. The single surviving explicit status write is the price-on-request `outofstock`.

### Learnings carried from Story 2.6 (last story shipped, 3.13.0)

Two review lessons from the membership sweep shape Task 1:

- **Explicit facts, passed by whoever knows them — never re-derived downstream.** 2.6's `apply_missing_state()` takes `$human_initiated` and `$membership_complete` as *required* parameters instead of looking them up. Same here: the decision helper receives its inputs and calls no `get_option()`. That is what makes it unit-testable on the stub bootstrap.
- **One chokepoint, guards inside it.** 2.6 collapsed every removal write into a single guarded method so a new caller inherits the guard by construction. Here: one suppression decision, read by both variation paths. Two call sites with two hand-written guard expressions is exactly the drift this shape prevents — and with two near-duplicate methods in this file, drift is the default outcome, not the unlucky one.

### Recent commit context

`3.13.1` (`0f7c3c4`) is HEAD; the working tree carries only BMAD artefact edits. The last three functional releases (3.12.x → 3.13.1) all touched the upserter and the sync service. **Every line number in this document was read at 3.13.1 — re-verify before editing** with `grep -n "set_manage_stock" plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php`.

### Project conventions (hard rules)

- PHP 8.3, `declare(strict_types=1)`; type every parameter and return — PHPStan level 6, and the baseline must not grow.
- Logging only via `Skwirrel_WC_Sync_Logger` (`$this->logger->verbose()` matches the surrounding style in both methods). Never `error_log()`.
- Pest style: `test()` / `beforeEach()` / `expect()`. No class-based PHPUnit. File naming `{Thing}Test.php`.
- Unit suite runs on the stub bootstrap (no Docker). Integration needs wp-env: `npm run env:start`, then `npm run test:integration`.

### Project Structure Notes

No structural change and no settings work — 6.1 owns the Field mapping group (rendered in `class-skwirrel-wc-sync-admin-dashboard.php`, sanitized in `class-skwirrel-wc-sync-admin-settings.php`; they are different files). Do not add a field, a tab, or a second option here. Story 5.1's tab registration does not exist in the code yet and must not be anticipated with speculative markup.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 6.2: Stock quantity on variations (FR-18, NFR-9)`] — the five ACs this story expands
- [Source: `_bmad-output/implementation-artifacts/6-1-stock-from-custom-class-simple.md`] — the setting, resolver, request flags and `apply_stock_mapping()` helper consumed here; its AC 8 is why the variation paths were left for this story
- [Source: `_bmad-output/planning-artifacts/epics.md#Chapter 2 — Requirements Inventory`] — FR-18, NFR-9; trade-item-level mapping explicitly out of scope
- [Source: `_bmad-output/planning-artifacts/epics.md#As-built facts that change how Chapter 2 must be built`] — `prices_managed_outside_skwirrel` as the NFR-9 precedent
- [Source: `_bmad-output/implementation-artifacts/2-6-scheduled-membership-sweep.md#Completion Notes List`] — explicit-facts and single-chokepoint patterns
- [Source: `_bmad-output/project-context.md#Sync Architecture Rules`] — don't zero-out, `_skwirrel_synced_at`, variable-product assembly order
- [Source: `CLAUDE.md#Quality Checks`] · [Source: `.claude/rules/sync-service.md#Delta vs Full Sync`]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (in-session, orchestration `orchestration-6-20260826-143018`)

### Debug Log References

- `vendor/bin/pest` — 557 passed (1185 assertions), 8 new in `tests/Unit/VariationStockMappingTest.php`
- `npx wp-env run tests-cli … pest -c phpunit-integration.xml.dist` — 191 passed (1648 assertions), 8 new in `tests/Integration/VariationStockIntegrationTest.php`
- `vendor/bin/phpstan analyse --memory-limit=2G` — no errors (level 6, baseline unchanged)
- `vendor/bin/phpcs` — 34/34 clean

### Completion Notes List

- **Task 0 confirmed against the code, not the table.** `apply_stock_mapping()` and `resolve_numeric_feature_value()` both exist from 6.1; the setting key read out of `get_options()` is `stock_quantity_feature`. 6.1 landed the helper with an *untyped* first parameter, which already accepted a variation — it has been **widened explicitly to `WC_Product`** (which `WC_Product_Variation` extends) rather than forked, satisfying Task 0's intent and clearing a PHPStan `missingType.parameter`.
- **One suppression decision, two consumers (Task 1).** `stock_mapping_governs( string $mapping ): bool` is pure — it reads no options and calls no WC — so it is unit-testable on the stub bootstrap; `stock_mapping_is_active()` is the single chokepoint both variation paths read, so the two near-duplicate methods cannot drift. A test asserts purity directly by storing a setting and showing it does not change the answer.
- **Suppression is unconditional when the mapping is on — that is what makes AC 5 hold.** The legacy `set_manage_stock( false )` / `set_stock_status( 'instock' )` writes are suppressed whether or not *this* variation resolved a value. Had they been suppressed only on a successful resolve, a configured-but-unresolved variation would still be reset to unmanaged/instock, which is precisely the NFR-9 violation the epic exists to prevent. The integration suite pins this: a variation seeded with a hand-set quantity of 7 and then re-synced with the feature absent still reads `manage_stock = true, quantity = 7`.
- **Deviation from Task 2/3's literal wording on price-on-request, for the reason the Dev Notes give.** The task text says to call the helper and then re-assert `set_stock_status( 'outofstock' )`. Doing that would combine an explicit status with a managed quantity, which the same story's Dev Notes forbid ("Never write `stock_status` alongside a managed quantity") because WooCommerce recomputes status from the quantity on save and would undo the rule. Price-on-request variations are therefore **excluded from `apply_stock_mapping()` entirely**, keeping their existing explicit `outofstock` as the only stock word. AC 7 holds deterministically, and the integration test proves it with a variation that carries both a price-on-request flag and a resolvable quantity of 99.
- **The integration suite earned its keep.** The first run failed AC 7 — but the defect was in the fixture, not the code: a payload with *no* price is the "no price" branch, while price-on-request needs an explicit `price_on_request` flag on a price row (`Product_Mapper::is_price_on_request()`). The fixture was corrected and now documents that distinction for the next reader.
- **AC 8 and AC 9 were verified, not built.** Both `WC_Product_Variable::sync_stock_status()` call sites are untouched, as are the parent's own `set_stock_status( 'instock' )` / `set_manage_stock( false )` writes; the diff adds no new call site. `stock_quantity_feature` remains absent from `compute_sync_signature()`'s `$ignore` denylist, so changing the mapping busts the change gate automatically. The 6.1 caveat still applies unchanged: in the default `observe` mode the authoritative gate is the timestamp, so a value changed without advancing `product_updated_on` is skipped before the hash is consulted.
- **A pre-existing test needed its pin updated, not weakened.** Story 5.1's `SettingsTabsIntegrationTest` asserts the exact set of settings field groups and their tab placement. 6.1 added a ninth group ("Field mapping"), so the map, the expected input-name list and the count were updated to the new intended reality (8 → 9). No assertion was loosened — the test still pins an exact count and exact placement.
- **Version deliberately not bumped.** Stays on **3.14.0** per the user's direction; no CHANGELOG/readme version section, no translation regeneration. This story adds no user-facing strings (6.1 owns the settings copy).

### File List

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php` (modified) — `stock_mapping_setting()`, pure `stock_mapping_governs()`, `stock_mapping_is_active()`; `apply_stock_mapping()` widened to `WC_Product`; both variation price branches guarded and wired
- `tests/Unit/VariationStockMappingTest.php` (new) — 8 tests: the suppression decision and per-variation resolution
- `tests/Integration/VariationStockIntegrationTest.php` (new) — 8 tests against real WooCommerce covering AC 1, 2, 4, 5, 6, 7, 8
- `tests/Integration/SettingsTabsIntegrationTest.php` (modified) — field-group pin updated for the new "Field mapping" group

## Change Log

| Date | Change |
|------|--------|
| 2026-08-26 | Implemented Story 6.2: stock quantity on variations. Both variation write paths call 6.1's shared helper; the legacy unmanaged/instock writes are suppressed whenever a mapping is configured, so an unresolved variation keeps its stock. Price-on-request keeps its out-of-stock rule. No version bump (stays on 3.14.0). |
