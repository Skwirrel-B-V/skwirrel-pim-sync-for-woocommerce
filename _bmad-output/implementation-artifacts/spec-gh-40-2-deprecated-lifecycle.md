---
title: 'GH-40 (Story 2) Deprecated product lifecycle'
type: 'feature'
created: '2026-07-22'
status: 'done'
baseline_commit: 'e2caed48e1ef9ff75a8ed7c8e6363e3d01c6f2fe'
context: ['{project-root}/_bmad-output/project-context.md', '{project-root}/.claude/rules/sync-service.md', '{project-root}/_bmad-output/implementation-artifacts/spec-gh-40-product-status-handling.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Story 1 maps statuses to Publish/Draft/Trash — a one-shot decision. There is no gradual retirement: a discontinued/removed product either stays visible, hides, or is trashed immediately, with no labelled bucket to review before removal.

**Approach:** Add a fourth mapping target, a custom **`deprecated`** WooCommerce post status (hidden like draft, its own admin bucket). Products mapped to `deprecated` sit in that status; a full-sync finalize pass advances a per-product counter and, once it exceeds a configurable threshold, moves them to **trash** (never permanently deleted). The counter resets if the product returns to a visible status.

## Boundaries & Constraints

**Always:**
- WC-state whitelist becomes exactly `{publish, draft, trash, deprecated}` — sanitize every stored mapping value + `status_mapping_default` against it.
- The `deprecated` post status registers on `init` as non-public, hidden from shop/search (non-purchasable like draft), with an admin status-list entry for `product`.
- The Deprecated counter (`_skwirrel_deprecated_sync_count`) is advanced ONLY by the finalize escalation pass, which runs on **full sync only** (never delta). This is deliberate: the change-gate skips unchanged products before `get_status()`, so a per-upsert tick would never advance for a stable product.
- Entry to `deprecated` (upsert for present products, purge for `__missing__`) only SETS the status; it never counts. Returning to a visible status (publish/draft) deletes the counter meta (reset).
- Removal = move to **trash** only; never `wp_delete_post()`. Counter meta is cleared when a product is trashed.
- `get_status()` stays pure/testable; the escalation counter math is a pure static helper.
- Register the new class in TWO places (require_once in bootstrap + hook wiring in `Skwirrel_WC_Sync_Plugin`).

**Ask First:**
- Any removal stronger than trash (permanent delete), or ticking the counter on delta syncs.
- Changing the default `deprecated_remove_after_syncs` (proposed: 3).

**Never:**
- Permanent deletion / `wp_delete_post()` of deprecated products.
- Touching the upsert lookups or stale-purge SQL — the trash-aware-lookup fix is a SEPARATE change (see `deferred-work.md`), not part of this story.
- Version bump / changelog / readme / translation regen (all release-time, via `/release`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| Map to deprecated | Present product, status mapped `→ deprecated` | Upsert sets post status `deprecated`; product hidden from shop | N/A |
| Escalation tick | `deprecated` product, counter 2, threshold 2, full sync | Finalize pass: counter→3 (>2) → set `trash`, counter meta cleared | N/A |
| Immediate | `deprecated` product, threshold 0, full sync | Finalize pass: counter→1 (>0) → `trash` | N/A |
| Still ageing | `deprecated` product, counter 1, threshold 3, full sync | counter→2 (≤3) → stays `deprecated` | N/A |
| Recovery | `deprecated` product resolves back to `publish` | Upsert sets `publish`; counter meta deleted | N/A |
| Delta sync | Any `deprecated` product | Counter NOT advanced (finalize pass is full-sync only) | N/A |
| Missing → deprecated | Full sync, product absent, `__missing__ → deprecated` | Purge sets `deprecated` (entry); finalize pass then ticks it | N/A |

</frozen-after-approval>

## Code Map

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-deprecated-status.php` (NEW) — register `deprecated` post status (`init`), admin status-list label for `product`; pure static `escalate(int $count, int $threshold): array{count:int, remove:bool}`.
- `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` — `require_once` the new class.
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-plugin.php` — instantiate + wire the new class.
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php` — add `deprecated` to `VALID_STATES`; add `DEPRECATED_COUNT_META` const (`_skwirrel_deprecated_sync_count`).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php` — at the status-application sites (simple L307/L1493, grouped parent L1171): a `deprecated` resolved status is set as-is (entry, no count); any non-deprecated resolved status deletes the counter meta (reset).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php` — `get_options()` default `deprecated_remove_after_syncs` (3); call the escalation pass from `step_finalize()` (full sync only).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-purge-handler.php` — allow `__missing__ → deprecated` entry (don't let the "already in target state" skip block it); add `escalate_deprecated(int $threshold): int` — SQL-scan `post_status='deprecated'` Skwirrel products, `escalate()` each, trash those over threshold (variation cascade + numeric-meta safety).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php` — add `deprecated` to the state whitelist; sanitize `deprecated_remove_after_syncs` (`max(0,(int))`).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php` — add the "Deprecated" option to the state selects; add the threshold number input (hint: 0 = immediate).

## Tasks & Acceptance

**Execution:**
- [x] `class-skwirrel-wc-sync-deprecated-status.php` -- NEW: register the hidden `deprecated` post status + admin status label for `product`; pure static `escalate()`; wire into bootstrap require + `Skwirrel_WC_Sync_Plugin` -- the status + counter math.
- [x] `class-skwirrel-wc-sync-product-mapper.php` -- add `deprecated` to `VALID_STATES` and the `DEPRECATED_COUNT_META` constant so the mapping accepts/returns it -- makes deprecated a first-class target.
- [x] `class-skwirrel-wc-sync-product-upserter.php` -- apply a `deprecated` resolved status directly (entry); delete the counter meta whenever the resolved status is non-deprecated (reset on recovery) -- present-product entry/reset.
- [x] `class-skwirrel-wc-sync-purge-handler.php` -- support `__missing__ → deprecated` entry, and add `escalate_deprecated()` (scan → `escalate()` → trash over threshold, variation cascade) -- missing entry + the sole counter ticker.
- [x] `class-skwirrel-wc-sync-service.php` -- add the `deprecated_remove_after_syncs` default; call `escalate_deprecated()` from `step_finalize()` on full sync only -- wires the pass into the run.
- [x] `class-skwirrel-wc-sync-admin-settings.php` + `class-skwirrel-wc-sync-admin-dashboard.php` -- add `deprecated` to the whitelist + selects and the threshold input; all strings translatable -- the control surface.
- [x] `tests/Unit/DeprecatedLifecycleTest.php` (NEW) -- Pest tests: `escalate()` (immediate/threshold-N/under-threshold), `get_status()` returns `deprecated` when mapped, mapper accepts `deprecated` in `set_status_handling`, counter-reset semantics -- locks the logic.

**Acceptance Criteria:**
- Given a status mapped to `deprecated`, when a product with that status is synced, then its post status is `deprecated` and it is hidden from the shop and listed under the admin Deprecated filter.
- Given a `deprecated` product and threshold N, when N+1 full syncs have run, then it is moved to trash and its counter meta is cleared; with threshold 0 it is trashed on the first full-sync pass; a delta sync never advances the counter.
- Given a `deprecated` product that resolves back to a visible status, when it is next synced, then it returns to that status and the counter meta is deleted.

## Design Notes

Counter lifecycle is centralized in the finalize pass to stay reliable against the change-gate: entry points (upsert / purge) only set the `deprecated` status; `escalate_deprecated()` (full-sync finalize) is the ONLY place the counter increments and the ONLY place a deprecated product is trashed. `escalate(count, threshold)` returns `{count: count+1, remove: (count+1) > threshold}`. Counter starts at 0 (absent meta), so N=0 ⇒ removed on the first pass, N ⇒ visible as deprecated for ~N passes. Recovery reset lives in the upsert (delete the meta on any non-deprecated resolved status). Register the status early enough (`init`) that WC and the admin list recognise it. NB: a product escalated to trash that later re-appears active still duplicates until the separate trash-aware-lookup fix lands — that is out of scope here by design.

## Verification

**Commands:**
- `vendor/bin/pest` -- expected: all pass incl. new `DeprecatedLifecycleTest.php`.
- `vendor/bin/phpstan analyse` -- expected: no new errors.
- `vendor/bin/phpcs` -- expected: clean (class-file naming for the new class, escaping, text domain).

**Manual checks:**
- Map a status to Deprecated (threshold 2), run full syncs: product shows `deprecated`, appears under its own admin filter, is not purchasable, and moves to trash on the pass after the threshold.
- Toggle threshold to 0 and confirm a deprecated product is trashed on the next full sync.
