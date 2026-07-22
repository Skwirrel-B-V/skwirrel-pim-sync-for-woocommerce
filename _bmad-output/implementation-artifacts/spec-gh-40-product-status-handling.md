---
title: 'GH-40 (Story 1) Configurable product status mapping'
type: 'feature'
created: '2026-07-22'
status: 'done'
baseline_commit: '3fe1ac2f95568675300e6a2a312095fb1886f02f'
context: ['{project-root}/_bmad-output/project-context.md', '{project-root}/.claude/rules/sync-service.md', '{project-root}/.claude/rules/product-mapping.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The sync recognizes only `product_trashed_on`→`trash` and the substring `"draft"`→`draft`; everything else becomes `publish`. Skwirrel exposes no fixed status enum (free-text, discovered at runtime), so admins cannot control which WooCommerce state each source status becomes, nor what happens to products that are discontinued (`product_trashed_on`) or vanish from the feed.

**Approach:** Make the source-status → WooCommerce-state mapping configurable. Discover distinct status labels as syncs see them, persist them, and let the admin map each label — plus pseudo-statuses `__trashed__` (removed upstream) and `__missing__` (no longer in feed) — to `publish` / `draft` / `trash`. `get_status()` and the stale-purge path consult this mapping. Behavior is unchanged until an admin configures a mapping. (Story 2 will add a `deprecated` lifecycle as a fourth target — this story deliberately stops at the mapping.)

## Boundaries & Constraints

**Always:**
- Zero behavior change when unconfigured: `product_trashed_on`→`trash`; description contains "draft"→`draft`; else `status_mapping_default` (default `publish`).
- WC-state whitelist is exactly `{publish, draft, trash}`. Sanitize every stored mapping value against it.
- `get_status()` stays pure/testable — the mapping is injected via a setter, never read from `get_option()` inside it.
- Status discovery accumulates in run context and is persisted ONCE at finalize (no per-product option writes).
- The `__missing__` path runs only on full sync, no collection filter, with `purge_stale_products` enabled (existing guards). `__missing__→publish` means "leave untouched" (keep visible).
- `protect_from_deletion` governs ONLY the manual-deletion lock + banner while a sync runs; it no longer alters automatic status decisions (the mapping is authoritative).
- Never permanently delete; the strongest automatic action is `trash`. Variations keep `publish`.

**Ask First:**
- Any WC state beyond `{publish, draft, trash}` (the `deprecated` target belongs to Story 2).
- Changing the unconfigured defaults for `__trashed__` (trash) or `status_mapping_default` (publish).

**Never:**
- Introducing the `deprecated` custom status, a sync counter, or removal thresholds (Story 2 — see `deferred-work.md`).
- Trashing/draft-ing on delta syncs or when a collection filter is active.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| Mapped label | Present product, `"Discontinued"`, mapping `discontinued→draft` | `get_status()`→`draft`; label recorded in seen-statuses | N/A |
| Legacy draft | `"Draft - not published"`, no mapping | `get_status()`→`draft` (legacy rule) | N/A |
| Unmapped new label | `"Foobar"`, no mapping, default `publish` | `get_status()`→`publish`; recorded as discovered | N/A |
| Empty status | No `_product_status` | `status_mapping_default` (publish); recorded `__none__` | N/A |
| Trashed upstream | `product_trashed_on` set, `__trashed__→trash` | `get_status()`→`trash` | N/A |
| No longer in feed | Full sync, product not re-stamped, `__missing__` mapped, purge enabled | Applied per mapping: `publish`→leave untouched, `draft`→set draft, `trash`→set trash (variations cascade) | N/A |
| Delta sync | Product absent from delta payload | Never trashed/drafted (purge skipped on delta) | N/A |

</frozen-after-approval>

## Code Map

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php` — `get_status()` (L121-130) rewrite; add `get_status_label()`, `set_status_handling()`, option/const keys.
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php` — `get_options()` defaults (L1854-1872); inject mapping into mapper; collect seen labels in run ctx; persist `skwirrel_wc_sync_seen_statuses` at finalize; purge gate (L959-975) runs the `__missing__` mapping (drop the protect-based skip).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-purge-handler.php` — `purge_stale_products()` (L500-615) applies the `__missing__` mapped state (skip on publish/keep; else draft/trash) instead of hardcoded `set_status('trash')`; keep variation cascade + numeric-meta SQL safety.
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php` — remove the `guard_trash_status()` clamp at simple L307 / grouped L1172 / variable L1496 so mapping-derived status is applied directly (delete the now-unused method).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-delete-protection.php` — narrow `protect_from_deletion` to the manual delete-lock/banner (keep `pre_trash_post`/`pre_delete_post` + notice); remove its automatic-status role.
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php` — `sanitize_settings()`: `status_mapping` (assoc, whitelist values), `status_mapping_default` (whitelist).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php` — "Product status handling" `.skw-fieldgroup`: discovered-status table (Publish/Draft/Trash selects), `__trashed__`/`__missing__` rows, default select.
- Options: new `skwirrel_wc_sync_seen_statuses` (`normalized→display`); settings keys `status_mapping`, `status_mapping_default`. Languages: new strings (regen .pot/.po/.mo at release).

## Tasks & Acceptance

**Execution:**
- [x] `class-skwirrel-wc-sync-product-mapper.php` -- add `get_status_label(array): string` (normalize trimmed description; `__trashed__` if `product_trashed_on`; `__none__` if empty), `set_status_handling(array $mapping, string $default)`, and rewrite `get_status()` to resolve via injected mapping → legacy "draft" fallback → default -- central, pure, testable status logic.
- [x] `class-skwirrel-wc-sync-service.php` -- resolve `status_mapping`/`status_mapping_default` from options and inject into the mapper; accumulate `get_status_label()` values into run ctx during the product loop; merge (capped) into `skwirrel_wc_sync_seen_statuses` at finalize; add the two defaults to `get_options()`; make the purge gate run the `__missing__` mapping instead of hard-skipping when protection is on -- wires config + discovery into the run.
- [x] `class-skwirrel-wc-sync-purge-handler.php` -- change `purge_stale_products()` to apply the `__missing__` mapped state (skip when `publish`/keep, else set draft/trash) preserving variation cascade + SQL safety -- configurable "no longer available at source" handling.
- [x] `class-skwirrel-wc-sync-product-upserter.php` -- apply the mapping-derived status directly at the three call sites; remove the `guard_trash_status()` clamp/method -- mapping becomes authoritative for automatic status.
- [x] `class-skwirrel-wc-sync-delete-protection.php` -- scope `protect_from_deletion` to the manual delete-lock + banner only; drop `is_deletion_protection_enabled()` from automatic-status paths -- ends the accidental WIP entanglement.
- [x] `class-skwirrel-wc-sync-admin-settings.php` -- sanitize `status_mapping` (iterate submitted assoc array, whitelist each value ∈ {publish,draft,trash}) and `status_mapping_default` (whitelist, fallback `publish`) mirroring the `include_languages`/select patterns -- safe persistence.
- [x] `class-skwirrel-wc-sync-admin-dashboard.php` -- render the "Product status handling" `.skw-fieldgroup`: one `.skw-field-row` per discovered status (from `skwirrel_wc_sync_seen_statuses`) with a Publish/Draft/Trash `.skw-select`, explicit `__trashed__` and `__missing__` rows, and a "default for new statuses" select; all strings translatable (`skwirrel-pim-sync`) -- the admin control surface.
- [x] `tests/Unit/ProductStatusMappingTest.php` (NEW) -- Pest tests for every I/O row: mapped label, legacy draft, unmapped→default, empty→`__none__`, `__trashed__`, and label normalization; `tests/Unit/DeletionProtectionTest.php` -- update for the narrowed protect scope -- locks logic + prevents regression.

**Acceptance Criteria:**
- Given a discovered status mapped to a WC state in Settings, when the next sync upserts a product with that status, then the product is created/updated in the mapped WC state.
- Given `__missing__` mapped to a state, when a Skwirrel-managed product is absent from a full sync (purge enabled), then it is handled per that mapping (kept / draft / trash); on a delta sync it is never touched.
- Given a status label never seen before, when it appears in a sync, then it is recorded in `skwirrel_wc_sync_seen_statuses` and appears as a configurable row in Settings, defaulting to `status_mapping_default`.
- Given `protect_from_deletion` is enabled, when a sync runs, then only the manual delete-lock/banner is affected — automatic status follows the mapping unchanged.

## Design Notes

`get_status()` for a present, non-upstream-trashed product: (1) normalized label ∈ `status_mapping` → mapped state; (2) else description contains "draft" → `draft` (legacy default); (3) else `status_mapping_default`. `product_trashed_on` resolves via the `__trashed__` row (default `trash`). Injection keeps the mapper pure: the service calls `set_status_handling()` once per run; unit tests inject a fixed map. Pseudo-status keys `__trashed__`/`__missing__`/`__none__` use the `__…__` prefix so they never collide with a real free-text label. `status_mapping` is stored as a flat assoc array `label → state` inside `skwirrel_wc_sync_settings`, mirroring how `include_languages` nests an array under one key.

## Verification

**Commands:**
- `vendor/bin/pest` -- expected: all pass, incl. new `ProductStatusMappingTest.php` and updated `DeletionProtectionTest.php`.
- `vendor/bin/phpstan analyse` -- expected: no new errors (baseline unchanged).
- `vendor/bin/phpcs` -- expected: clean (escaping, text domain, class-file naming).

**Manual checks:**
- Run a full sync, open Settings → "Product status handling": discovered statuses appear as rows; changing a mapping and re-syncing moves matching products to the chosen state.
- Map `__missing__→trash`, remove a product from the feed, run a full sync with purge enabled → product trashed; set `__missing__→publish` → product left untouched.

## Suggested Review Order

**Status resolution (the core)**

- Entry point: mapping-driven status resolution, legacy fallback preserved, stays pure.
  [`class-skwirrel-wc-sync-product-mapper.php:210`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php#L210)

- Injected per run; whitelists WC states so only publish/draft/trash are stored.
  [`class-skwirrel-wc-sync-product-mapper.php:162`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php#L162)

- Shared normalization — makes discovery, storage and runtime keys identical (review-fix).
  [`class-skwirrel-wc-sync-product-mapper.php:181`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php#L181)

**Wiring into the resumable run**

- Constructor injection: the shared mapper is configured every action, not just run start.
  [`class-skwirrel-wc-sync-service.php:46`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php#L46)

- Discovery choke point (simple products only; variations are forced publish).
  [`class-skwirrel-wc-sync-service.php:680`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php#L680)

- Read-merge-write persistence, bounded, safe across overlapping processes (review-fix).
  [`class-skwirrel-wc-sync-product-mapper.php:258`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php#L258)

**"No longer available" (`__missing__`) handling**

- Purge applies the mapped state; publish = keep untouched; guarded against re-writes (review-fix).
  [`class-skwirrel-wc-sync-purge-handler.php:507`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-purge-handler.php#L507)

**Upsert + protection cleanup**

- Variable/grouped parent trashed-upstream now follows the configurable `__trashed__` mapping.
  [`class-skwirrel-wc-sync-product-upserter.php:1171`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php#L1171)

- `protect_from_deletion` narrowed to the manual delete-lock; mapping is authoritative.
  [`class-skwirrel-wc-sync-delete-protection.php:57`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-delete-protection.php#L57)

**Settings surface**

- Sanitize: whitelist states, normalize keys, cap size (review-fix).
  [`class-skwirrel-wc-sync-admin-settings.php:271`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php#L271)

- The "Product status handling" table (discovered rows + pseudo-status rows + default).
  [`class-skwirrel-wc-sync-admin-dashboard.php:955`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php#L955)

**Tests**

- Mapping resolution, normalization, discovery.
  [`ProductStatusMappingTest.php:1`](../../tests/Unit/ProductStatusMappingTest.php#L1)
