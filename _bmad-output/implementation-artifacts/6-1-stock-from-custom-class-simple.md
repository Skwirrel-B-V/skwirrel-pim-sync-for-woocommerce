---
status: done
baseline_revision: 0f7c3c4
context:
  - _bmad-output/project-context.md
  - _bmad-output/planning-artifacts/epics.md
  - .claude/rules/sync-service.md
  - .claude/rules/admin-settings.md
  - CLAUDE.md
---

# Story 6.1: Stock quantity from a custom class — simple products

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner,
I want a Skwirrel custom-class feature to drive my product stock,
so that I stop maintaining stock levels in two systems.

## Acceptance Criteria

**1 — A Field mapping group exists with a Stock quantity field**

**Given** the settings screen
**When** it renders
**Then** a **Field mapping** field group exists containing a **Stock quantity** field that accepts a single product-level custom-feature ID or code, empty by default
**And** the group renders as an ordinary `.skw-fieldgroup` in the existing settings form (Story 5.1's tab registration does not exist yet — do not build a tab, and do not block on 5.1)
**And** the value round-trips through `sanitize_settings()` and survives a save with no other change.

**2 — A numeric mapped value writes managed stock**

**Given** a mapped feature ID/code and a simple product whose **product-level** custom classes carry a numeric value for it
**When** the product syncs
**Then** the product is saved with `set_manage_stock( true )` and `set_stock_quantity( <value> )`
**And** the value is resolved through `Skwirrel_WC_Sync_Custom_Class_Extractor` against `_custom_classes` only — **never** `_trade_item_custom_classes` (trade-item level is explicitly out of scope for FR-18).

**3 — A missing, empty or non-numeric value changes nothing (NFR-9)**

**Given** the mapped feature is absent, empty, `not_applicable`, or resolves to a non-numeric value on a product
**When** the product syncs
**Then** the product's existing WooCommerce `stock_quantity` and `manage_stock` flag are left **exactly** as they were — never zeroed, never flipped to unmanaged, no `set_stock_*` call is made at all
**And** the skip is logged at verbose level only (one line per product, matching the `prices_managed_outside_skwirrel` branch's register), never as a warning.

**4 — Unconfigured mapping is byte-for-byte today's behaviour**

**Given** no feature ID/code is configured (empty setting — the default)
**When** any product syncs
**Then** no stock-related read or write occurs at all, no extra API include flag is requested, and behaviour is identical to 3.13.1.

**5 — A changed stock value is not swallowed by the change gates**

**Given** the mapped stock value changes upstream
**When** a sync runs
**Then** the product is not skipped by the content-hash gate — `_custom_classes` is part of the hashed payload, so the hash already differs; verify this rather than adding a parallel mechanism
**And given** the mapping setting itself is changed or cleared
**Then** the next run reprocesses every product, because `compute_sync_signature()` is a denylist and a newly added output setting is covered automatically — confirm the new key is **not** added to that `$ignore` list.

**6 — Custom classes are actually fetched when the mapping is configured**

**Given** a configured stock mapping while `sync_custom_classes` and `sync_trade_item_custom_classes` are both **off**
**When** a sync runs
**Then** `include_custom_classes` (and the `include_custom_collection_id` it requires) are still requested, so `_custom_classes` is present in the payload and the mapping resolves
**And** when `custom_collection_id` is not configured in that situation, the run does not hard-fail — it logs a clear, actionable warning naming the missing collection ID and proceeds with stock mapping inert.

**7 — Both simple-product upsert paths write stock**

**Given** a product synced through the queued catalogue run (`create_or_update_product()`)
**And** a product re-synced from its edit screen (`upsert_product()` via `Skwirrel_WC_Sync_Service::upsert_product()`)
**When** either runs
**Then** both apply the stock mapping identically — a single shared helper, not two copies of the logic.

**8 — Variations and variable parents are untouched by this story**

**Given** a variable parent or a variation
**When** it syncs
**Then** the hardcoded `set_manage_stock( false )` / `set_stock_status( 'instock' )` in the variation and parent paths is **unchanged** — lifting it is Story 6.2's job, and doing it here would ship a half-migrated stock model.

**9 — Unit coverage pins the four cases**

**And** unit tests assert: numeric value resolves; missing value resolves to `null`; non-numeric value resolves to `null`; unconfigured mapping resolves to `null` without touching the payload
**And** the three quality gates pass: `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G` (level 6), `vendor/bin/phpcs`.

## Tasks / Subtasks

- [x] **Add the resolver to the custom-class extractor** (AC: 2, 3, 9)
  - [x] Add one public method to `class-skwirrel-wc-sync-custom-class-extractor.php`, e.g. `resolve_numeric_feature_value( array $product, string $mapping ): ?float` (or `?string` — pick one and be consistent with what `set_stock_quantity()` accepts). It must be **pure**: no `get_option()`, no WC calls, so `tests/Unit/` can drive it directly on the stub bootstrap.
  - [x] Reuse `collect_custom_classes( $product, false )` — the `false` is AC 2's product-level-only guarantee, and it is already the default.
  - [x] Match the mapping string against **both** `custom_feature_id` / `custom_class_feature_id` (numeric) and `custom_feature_code` (string, case-insensitive). `get_custom_feature_values_for_ids()` (`:302`) matches by ID and `filter_custom_classes()` (`:75`) matches by code — follow those two shapes, do not invent a third.
  - [x] Skip `not_applicable` features, exactly as every other extractor method does.
  - [x] Return the **raw numeric** value, not the display string. `format_custom_feature_value()` appends the unit for type `N` ("500 st"), which is right for an attribute and wrong for a stock quantity. Read `numeric_value` for type `N`; accept a numeric `text_value` for `T`/`A` only if it passes `is_numeric()`. Anything else → `null`.
  - [x] Delegate from `Skwirrel_WC_Sync_Product_Mapper` alongside the existing custom-class passthroughs (`class-skwirrel-wc-sync-product-mapper.php:981-1050`) so the upserter calls the mapper, consistent with every other field.
- [x] **Add the setting** (AC: 1, 4, 5)
  - [x] Sanitize in `class-skwirrel-wc-sync-admin-settings.php` next to the other custom-class keys (`:420-430`). A single `sanitize_text_field()` + `trim()` is enough; empty string is the default and the off switch.
  - [x] Render in `class-skwirrel-wc-sync-admin-dashboard.php` as a new `.skw-fieldgroup` titled **Field mapping**, placed after *Product status handling* (`:1141`) and before *Advanced* (`:1195`). Copy the `deprecated_remove_after_syncs` field markup (`:1188`) for structure: `.skw-field` > `.skw-label` > input > `.skw-field-hint`.
  - [x] Hint copy must say what "empty" means (mapping off) and that a missing value never clears existing stock — that is the NFR-9 promise the store owner is trusting.
  - [x] All strings translatable with the literal domain `'skwirrel-pim-sync'`. **No** `.pot`/`.po`/`.mo` regeneration in this story — that is release-time, via `/release`.
  - [x] Add the key to the upserter's `get_options()` defaults (`class-skwirrel-wc-sync-product-upserter.php:3178`), mirroring how `prices_managed_outside_skwirrel` is defaulted there.
  - [x] Do **not** add the key to `compute_sync_signature()`'s `$ignore` denylist (`class-skwirrel-wc-sync-service.php:2376-2392`) — AC 5 depends on it being hashed.
- [x] **Request the payload the mapping needs** (AC: 6)
  - [x] In `begin_run()` (`class-skwirrel-wc-sync-service.php:274-310`), extend the condition that sets `include_custom_classes` + `include_custom_collection_id` to also fire when the stock mapping is non-empty. The grouped-products branch at `:305-309` is the precedent — copy that shape.
  - [x] Mirror it in the two other request builders that set the same flags: `:1814-1824` and `:2090-2098`.
  - [x] The hard-fail on a missing `custom_collection_id` (`:244-249`) is gated on `sync_custom_classes` / `sync_trade_item_custom_classes` / `sync_grouped_products`. **Do not extend that hard-fail** to the stock mapping — a store owner configuring a stock field must not be able to lock themselves out of syncing. Warn and run with mapping inert (AC 6).
- [x] **Write the stock in both simple-product paths** (AC: 2, 3, 7, 8)
  - [x] Add one private helper on the upserter, e.g. `apply_stock_mapping( WC_Product $wc_product, array $product ): void`, that resolves and writes, and does nothing when the setting is empty or the value is `null`.
  - [x] Call it from `create_or_update_product()` (`:1662`) immediately after the price block and before `$wc_product->save()` (`:1822`) — the queued catalogue path, which is what every normal run uses.
  - [x] Call it from the legacy `upsert_product()` (`:239`) at the equivalent point after its price block (`~:388`). This path is still live: `Skwirrel_WC_Sync_Service::upsert_product()` (`:1775`) and the single-product edit-screen resync (`:1898`) both go through it. Missing it means "sync this product" from the product editor silently does not update stock.
  - [x] Never call `set_stock_status()` from this story. WooCommerce derives status from managed quantity; forcing it here would fight `wc_update_product_stock_status()` and the variable-parent aggregation in 6.2.
  - [x] Leave `class-skwirrel-wc-sync-product-upserter.php:620-660`, `:1440-1460` and `:1940-1970` (variation + parent stock) untouched (AC 8).
- [x] **Tests** (AC: 9)
  - [x] New `tests/Unit/StockMappingTest.php` driving the extractor resolver directly across the four cases in AC 9, plus: value present under a *trade-item* class only → `null` (proves AC 2's product-level scope), and `not_applicable` → `null`.
  - [x] Extend the settings-default coverage the way `tests/Unit/ProductUpserterPriceTest.php` does — it reflects into the private `get_options()` and asserts the default. Follow that file's `beforeEach()` construction of the upserter verbatim; it is the only working recipe for instantiating it under the stub bootstrap.
  - [x] Do not weaken an existing assertion, and do not regenerate `phpstan-baseline.neon` to hide a new finding.

### Review Findings

- [x] [Review][Patch] Field-mapping requests can be restricted by the custom-class whitelist [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:287] — when custom-class syncing is enabled with a whitelist that excludes the mapped feature's class, `include_custom_class_id` omits that class from the catalogue payload. The mapping is independently configured and should still receive its source class.
- [x] [Review][Patch] Invalid custom collection IDs are sent as collection ID `0` [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:316] — mapping-only configurations bypass the existing required-field validation; a non-empty invalid value is cast to `0`, without the required actionable warning, and leaves stock mapping inert.
- [x] [Review][Patch] Manual re-syncs omit the required missing-collection warning [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:1844] — both single-product and grouped-product request builders silently skip custom classes when the mapping is enabled and `custom_collection_id` is missing, contrary to AC 6's warning-and-inert behaviour.
- [x] [Review][Patch] Resolver does not reliably support `custom_class_feature_id` [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-custom-class-extractor.php:160] — null coalescing treats an empty `custom_feature_id` as present and never falls back to `custom_class_feature_id`, violating the stated ID-alias contract.
- [x] [Review][Patch] Settings-tab integration assertions were not updated for the new field group [tests/Integration/SettingsTabsIntegrationTest.php:49] — the exact input-name list and eight-group count reject the newly rendered `stock_quantity_feature` and Field mapping group, so the integration suite fails.
- [x] [Review][Defer] Timestamp-gated delta sync can still miss upstream custom-class changes without an updated timestamp [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:220] — deferred, pre-existing. The story records this as a known limitation of the default `observe` content-hash mode; it predates the mapping and requires a broader change-gate decision.

## Dev Notes

### What already exists — read before writing anything

**`class-skwirrel-wc-sync-custom-class-extractor.php`** is the whole custom-class surface and it is already very close to what this story needs. Do not add a second extractor.

- `collect_custom_classes( array $product, bool $include_trade_items = false )` (`:37`) — product level by default, trade-item level opt-in. AC 2 is satisfied simply by leaving the flag `false`.
- `filter_custom_classes()` (`:75`) shows the house pattern for matching a class by **numeric ID or lowercased string code** in the same call. The stock mapping accepts "an ID or code" and this is how the plugin already reads that kind of input.
- `parse_custom_class_filter()` (`:98`) splits a raw settings string into `{ids: int[], codes: string[]}`. If the stock field is ever allowed to hold either form, reuse this rather than re-parsing.
- `get_custom_feature_values_for_ids()` (`:302`) is the closest existing method: it walks `_custom_classes[]._custom_features[]`, matches on `custom_feature_id ?? custom_class_feature_id`, and skips `not_applicable`. Mirror its loop.
- **`format_custom_feature_value()` (`:465`) is the wrong tool for stock.** For type `N` it returns `"{numeric_value} {unit}"` — a display string. Read `numeric_value` directly.
- The constructor takes `$image_language` and the class also reads `image_language` from the options inside several methods. Your new method needs neither — stock is a number, not a translated string. Keep it pure.

**`class-skwirrel-wc-sync-product-upserter.php`** — two live simple-product paths, both yours (AC 7):

- `create_or_update_product()` (`:1662`) is the queued catalogue path (`class-skwirrel-wc-sync-service.php:920`). Price handling sits at `:1790-1821`, then `save()` at `:1822`. Your call goes between them.
- `upsert_product()` (`:239`) is the legacy monolith, still called for the single-product resync (`class-skwirrel-wc-sync-service.php:1775`, `:1898`). Its price block is at `:355-387`. **It is not dead code.** Two paths, one helper.
- `get_options()` (`:3178`) merges a hardcoded default array over the saved option. `prices_managed_outside_skwirrel` is defaulted there and read as `! empty( $this->get_options()[...] )` at four call sites — that is the pattern to copy for a settings-gated field write.
- `invalidate_change_gates()` (`:80`) exists for when a write must not be skipped next run. You should not need it: a changed upstream value changes the payload, which changes the hash.

**The NFR-9 precedent is `prices_managed_outside_skwirrel`.** Read `:1790-1821` in full. It is the exact shape this story repeats: when the PIM has nothing to say, take no branch that writes, log at verbose, and leave WooCommerce's value alone. The difference is that prices need an opt-in checkbox because the *default* is destructive (`set_price('0')`); stock has no such legacy default, so **non-destructive is unconditional here** — there is no `stock_managed_outside_skwirrel` switch to build. If a future story wants one, that is a new FR.

### The two change gates, and why AC 5 is mostly a verification

Both gates are in `create_or_update_product()` (`:1723-1762`):

1. **Timestamp gate** — `is_unchanged()` (`:218`) compares stored `_skwirrel_updated_on` against incoming `product_updated_on`. This is the **default** path (`content_hash_mode` defaults to `'observe'`, which computes but does not act).
2. **Content-hash gate** — `content_hash()` (`:164`) → `payload_signature()` (`:179`): strips `HASH_EXCLUDE_KEYS` (`product_updated_on` only), recursively ksorts, and md5s `sig|json`. **The entire payload is hashed, `_custom_classes` included.** It is authoritative only in `'enforce'` mode.

So: a changed custom-class value **does** change the content hash — nothing to build for that half of AC 5. But note the honest limitation, and say so in your completion notes rather than silently working around it: **in the default `observe` mode, the authoritative gate is still the timestamp.** If Skwirrel bumps a custom-class value without advancing `product_updated_on`, the product is skipped before the hash is consulted. That is a pre-existing property of the gate design, identical for every other mapped field, and it is not this story's job to change the default mode.

`compute_sync_signature()` (`class-skwirrel-wc-sync-service.php:2369`) is deliberately a **denylist** — its own comment explains that a newly added output setting is covered automatically "instead of slipping past the gate until someone remembers to allowlist it". Your new key is covered for free. Just do not add it to `$ignore`.

### The payload trap that will otherwise burn a day

`_custom_classes` is **only present in the API response when it was asked for**. `begin_run()` sets `include_custom_classes` at `:286` (gated on `sync_custom_classes`) and again at `:307` (gated on `sync_grouped_products`), and both need `include_custom_collection_id` (`:281`, `:308`) — without the collection ID the API returns no classes even with the include flag set.

A store owner who configures only a stock feature ID, with both custom-class toggles off, will get an empty `_custom_classes` on every product and a mapping that silently never fires. AC 6 exists for exactly this. There are **three** request builders that set these flags — `:274-310` (the run), `:1814-1824`, `:2090-2098` — and they must agree, or the single-product resync behaves differently from the catalogue run.

Do not extend the `custom_collection_id` **hard-fail** at `:244-249` to cover the stock mapping. That check aborts the entire run with "Custom classes or grouped products are enabled but no custom class collection ID is configured". Turning a stock-field typo into a total sync outage is a worse failure than an inert mapping.

### Settings surface

- Sanitization lives in `class-skwirrel-wc-sync-admin-settings.php` (`sanitize_settings()`, custom-class keys at `:420-430`). Rendering lives in `class-skwirrel-wc-sync-admin-dashboard.php` (`:767-1213`). They are different files — a field added to only one of them either does not persist or does not display.
- The settings form is one `<form>` with one option write. Everything stays in the DOM and submits together (this is also UX-DR13's constraint for when 5.1 lands). Do not add a second option or a per-group save.
- Field group markup: `<div class="skw-fieldgroup">` + `<h3 class="skw-fieldgroup-title">` + `.skw-field` rows. `deprecated_remove_after_syncs` (`:1186-1190`) is the closest single-input reference.
- The new group is called **Field mapping** because 6.2, 6.3 and Epic 5's tab all name it that. Use that exact label.

### Do not

- **Do not touch variation or variable-parent stock.** `set_manage_stock( false )` at `:630`, `:654`, `:1453`, `:1952`, `:1967` and the `sync_stock_status()` calls at `:835`, `:2110` are Story 6.2's scope. This story ships a working simple-product mapping; 6.2 lifts the hardcoding.
- **Do not read `_trade_item_custom_classes`.** Explicitly recorded as out of scope in Chapter 2's "Deliberately Out of Scope".
- **Do not call `set_stock_status()`.** Managed quantity drives status in WooCommerce; a manual status write here would be overridden or would fight 6.2's parent aggregation.
- **Do not write `0` when the value is missing.** That is the exact NFR-9 violation this epic exists to prevent, and it is how the price path used to behave before `prices_managed_outside_skwirrel`.
- **Do not add a new class.** This is a method on the extractor, a passthrough on the mapper, a helper on the upserter, one setting, one field group. No autoloader means a new class costs two registrations; it is not warranted here.
- **Do not bump the version, edit CHANGELOG.md / readme.txt, or regenerate translations.** Release-time work, done by `/release`.

### Project Structure Notes

- Only edit under `plugin/skwirrel-pim-sync/`; dev tooling (`vendor/`, `tests/`) lives at the repo root.
- Class naming is a hard WPCS rule: `Skwirrel_WC_Sync_{Name}` in `class-skwirrel-wc-sync-{slug}.php`. Not applicable if you add no class — which is the plan.
- All output escaped (`esc_attr`, `esc_html`), all input sanitized. WPCS fails the build otherwise.
- Logging only via `Skwirrel_WC_Sync_Logger`; the verbose skip line uses `$this->logger->verbose()`.
- PHP 8.3, `declare(strict_types=1)`. PHPStan runs at level 6 — type the new method's params and return explicitly, including the `?float`/`?string` nullable.

### Testing

- Gates, from the repo root, all three before commit: `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs` (auto-fix with `vendor/bin/phpcbf`).
- Pest syntax only: `test()`, `beforeEach()`, `expect()`, `dataset()`/`with()`. Unit tests in `tests/Unit/` run on the stub bootstrap (`tests/bootstrap.php`) with no Docker.
- `tests/Unit/CustomClassExtractorTest.php` and `tests/Unit/ProductMapperCustomClassTest.php` already build `_custom_classes` fixtures — lift a fixture from there instead of writing a new payload shape by hand.
- `tests/Unit/ProductUpserterPriceTest.php` is the reference for testing a settings-gated upserter branch: it constructs the upserter with all seven collaborators in `beforeEach()` and reflects into `get_options()`. Reuse that recipe.
- `tests/Unit/ContentHashTest.php` and `tests/Unit/UnchangedGateTest.php` cover the gates. If you assert anything about AC 5, extend those rather than starting a third gate test file.
- Integration tests (`tests/Integration/`, `npm run test:integration`) need Docker via wp-env. A real end-to-end stock assertion belongs to Story 6.4's canary suite; unit coverage is what AC 9 requires here.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 6.1`] — acceptance criteria this story implements verbatim.
- [Source: `_bmad-output/planning-artifacts/epics.md#Chapter 2 — Requirements Inventory`] — FR-18 (custom class → stock), NFR-9 (non-destructive field mapping), and the "Deliberately Out of Scope" entry ruling out trade-item-level custom classes and a faster stock cadence.
- [Source: `_bmad-output/planning-artifacts/epics.md#Chapter 2 — Backlog Reconciliation`] — "`prices_managed_outside_skwirrel` already exists as a setting — the precedent for NFR-9"; and the note that Epic 5's tabs are a second level inside the existing `?tab=` menu, which is why this story ships a plain field group.
- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 6`] — implementation order 6.1 → 6.2 → 6.3 → 6.5 → 6.4; 6.1 establishes the resolver and settings group the later stories consume.
- [Source: `_bmad-output/project-context.md#Sync Architecture Rules`] — "don't zero-out prices" (the rule NFR-9 generalises), meta keys are contracts, register a new class in two places.
- [Source: `_bmad-output/project-context.md#Testing & Quality Gates`] — three gates before every commit; don't regenerate the PHPStan baseline; don't weaken a test.
- [Source: `.claude/rules/admin-settings.md`] — settings storage, sanitization rules, `.skw-*` UI conventions, `manage_woocommerce` capability.
- [Source: `.claude/rules/sync-service.md`] — run_sync flow, delta vs full, where API include flags are assembled.
- [Source: `_bmad-output/implementation-artifacts/2-6-scheduled-membership-sweep.md`] — most recent completed story; its Dev Notes document the step/re-entrancy model of `run_step()` if you end up in `begin_run()`.

## Dev Agent Record

### Agent Model Used

claude-opus-5 (in-session, via story-automator orchestration `orchestration-6-20260826-143018`)

### Debug Log References

- `vendor/bin/pest` — 549 passed (1168 assertions), including 13 new in `tests/Unit/StockMappingTest.php`
- `vendor/bin/phpstan analyse --memory-limit=2G` — no errors (level 6, baseline unchanged)
- `vendor/bin/phpcs` — 34/34 clean

### Completion Notes List

- **Resolver is pure, as required.** `resolve_numeric_feature_value( array $product, string $mapping ): ?float` on `Skwirrel_WC_Sync_Custom_Class_Extractor` takes no options and calls no WC APIs, so `tests/Unit/` drives it directly on the stub bootstrap. It matches on numeric feature ID **or** case-insensitive feature code in one pass, mirroring `get_custom_feature_values_for_ids()` and `filter_custom_classes()` rather than inventing a third shape. A private `raw_numeric_feature_value()` reads `numeric_value` for type `N` and a numeric `text_value` for `T`/`A`; `format_custom_feature_value()` is deliberately not used because it appends the unit ("500 st").
- **NFR-9 is enforced by returning `null`, never `0`.** `apply_stock_mapping()` on the upserter returns early — writing nothing at all — when the mapping is empty or the value does not resolve. No `set_stock_*` call is made in that branch, so `manage_stock` and `stock_quantity` survive untouched. The skip logs at verbose level only, matching the `prices_managed_outside_skwirrel` register.
- **AC 5 was verification, not construction — with one honest caveat.** `_custom_classes` is part of the hashed payload (`payload_signature()` strips only `product_updated_on`), so a changed upstream stock value already changes the content hash; nothing was built for that. `stock_quantity_feature` is deliberately **not** added to `compute_sync_signature()`'s `$ignore` denylist, so changing or clearing the mapping busts the gate automatically. **Caveat, stated rather than worked around:** in the default `content_hash_mode = 'observe'`, the authoritative gate is still the timestamp, so a custom-class value changed without advancing `product_updated_on` is skipped before the hash is consulted. That is a pre-existing property of the gate design, identical for every other mapped field, and out of scope here.
- **AC 6 warns instead of hard-failing.** A new `Skwirrel_WC_Sync_Service::has_field_mapping()` gates `include_custom_classes` + `include_custom_collection_id` in all three request builders (`begin_run()`, single-product resync, grouped-product fetch), so the mapping resolves even with both custom-class toggles off. The `custom_collection_id` hard-fail at `begin_run()` was **not** extended: a missing collection ID logs an actionable warning naming the setting and the run proceeds with the mapping inert, rather than turning a field-mapping typo into a total sync outage.
- **Both simple-product paths share one helper (AC 7).** `create_or_update_product()` (queued catalogue run) and the legacy `upsert_product()` (product-editor resync) both call `apply_stock_mapping()` immediately after their price block — one implementation, no duplicated logic.
- **Variations and variable parents untouched (AC 8).** The diff adds exactly one `set_manage_stock()`, inside the new simple-product helper. The hardcoded `set_manage_stock( false )` / `set_stock_status( 'instock' )` calls in the variation and parent paths are unchanged; lifting them is Story 6.2.
- **Stale story assumption, resolved in favour of the shipped code.** The story says "Story 5.1's tab registration does not exist yet — do not build a tab". 5.1 has since shipped, so the settings screen is now tabbed. The **Field mapping** group is therefore rendered as an ordinary `.skw-fieldgroup` (as the AC requires) inside the existing **What to sync** panel, after *Product status handling*. `stock_quantity_feature` was added to that tab's `fields` list so Story 5.2's inline-error targeting resolves the field to the right tab. No new tab was created.
- **Version deliberately not bumped.** Per the story's "do not bump the version" instruction and the user's explicit direction, everything stays on **3.14.0**; no CHANGELOG.md / readme.txt version section was added and no `.pot`/`.po`/`.mo` was regenerated. The new UI strings use the literal `'skwirrel-pim-sync'` domain and will be picked up at release time by `/release`.

### File List

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-custom-class-extractor.php` (modified) — `resolve_numeric_feature_value()` + private `raw_numeric_feature_value()`
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php` (modified) — `get_stock_quantity()` delegation
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php` (modified) — `apply_stock_mapping()`, `stock_quantity_feature` default, both call sites
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php` (modified) — `has_field_mapping()` + include flags in three request builders + missing-collection warning
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php` (modified) — `stock_quantity_feature` sanitization
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php` (modified) — `render_fieldgroup_field_mapping()`, panel wiring, tab field registration
- `tests/Unit/StockMappingTest.php` (new) — 13 tests covering AC 2, 3, 4, 9

## Change Log

| Date | Change |
|------|--------|
| 2026-08-26 | Implemented Story 6.1: stock quantity from a product-level custom class for simple products. Adds the Field mapping settings group, a pure numeric resolver on the custom-class extractor, and a shared non-destructive `apply_stock_mapping()` used by both simple-product upsert paths. No version bump (stays on 3.14.0). |
