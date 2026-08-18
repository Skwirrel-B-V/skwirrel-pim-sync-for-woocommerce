# Story 2.6: Scheduled membership sweep

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner,
I want my scheduled syncs to retire products that have left the Skwirrel selection, without ever removing a large batch silently,
so that my shop stops selling products the PIM no longer lists, and I stay in control of destructive changes.

## Acceptance Criteria

**1 — The sweep runs on every scheduled run**

**Given** a sync run with one or more configured selection IDs
**When** the run reaches its fetch phase
**Then** it fetches the complete product-id list per selection via `getProductsByFilter` with `filter: { dynamic_selection_id }` and `options: []` — no `updated_on`, no payload includes
**And** the resulting id set is persisted in the run context so it survives step yields and Action Scheduler restarts.

**2 — The delta payload is filtered against the sweep**

**Given** a delta fetch returns products absent from the sweep id set
**When** those products are queued
**Then** they are dropped before upsert, counted, and logged once with the total
**And** no `_skwirrel_synced_at` stamp, status change or untrash is written for them.

**3 — Removals are driven by the sweep diff, not by absence from the payload**

**Given** `purge_stale_products` is enabled
**When** the run finalizes
**Then** products carrying `_skwirrel_product_id` whose id is absent from the sweep set are handled by the missing-product path
**And** this happens on scheduled runs, not only on manual full syncs.

**4 — A mass removal is refused, not performed**

**Given** the removal set exceeds `MASS_REMOVAL_RATIO` of the Skwirrel-owned product count
**When** the run finalizes
**Then** nothing is removed, the run still completes successfully, and a warning naming the count and the ratio is logged and stored in the run result
**And** a human can still perform the removal by running a manual sync.

**5 — An incomplete sweep never removes anything**

**Given** any sweep page fails, or the sweep did not complete for every configured selection
**When** the run finalizes
**Then** no removals happen at all and the reason is logged
**And** the delta content sync still completes normally.

**6 — Genuine re-adds still revive**

**Given** a product previously trashed as out-of-selection reappears in both the sweep set and the payload
**When** it is upserted
**Then** `guard_revive_from_trash()` untrashes it exactly as today.

**7 — The deprecated escalation cadence is unchanged**

**Given** any scheduled run
**When** it finalizes
**Then** `escalate_deprecated()` still runs on full syncs only and its counter is not advanced by the sweep.

## Tasks / Subtasks

- [ ] **Promote the sweep to a reusable service** (AC: 1, 5)
  - [ ] Move/expose `fetch_product_ids_for_selection()` so both the grouped-product post-filter and the sync service can call it. It is currently `private` on the upserter (`class-skwirrel-wc-sync-product-upserter.php:1053`) with one caller at `:937`.
  - [ ] Page it at a limit independent of the content `batch_size` (reference install runs `batch_size: 25`, so 850 ids costs 34 calls). A dedicated constant is enough — no new setting.
  - [ ] Return a completeness flag alongside the id set; the existing implementation `break`s on a failed page and returns a partial set silently, which AC 5 must not tolerate.
- [ ] **Run the sweep as part of the run** (AC: 1)
  - [ ] Call it per selection id during `step_init` or a new step in `class-skwirrel-wc-sync-service.php`, before `step_fetch`.
  - [ ] Persist the id set + completeness flag in `$ctx`. Follow the existing pattern for large per-run payloads: the product→group map is stored in its own autoload-off option via `save_group_map()` / `load_group_map()` (`OPTION_GROUP_MAP`), not inline in `$ctx`. Do the same — an 850-id array inline would bloat every run-state write.
  - [ ] Clear it on run completion the same way `clear_group_map()` does.
- [ ] **Filter the delta payload** (AC: 2)
  - [ ] In `step_fetch` (`class-skwirrel-wc-sync-service.php:512-560`), drop fetched products whose `product_id` is not in the sweep set before they are queued.
  - [ ] Log once per run with the dropped count, not once per product.
- [ ] **Drive removals from the sweep** (AC: 3, 4, 5)
  - [ ] In `step_finalize` (`class-skwirrel-wc-sync-service.php:1024-1033`), replace the `if ( $ctx['delta'] ) { skip }` guard with the sweep-diff path.
  - [ ] Add a diff-based entry point on `Skwirrel_WC_Sync_Purge_Handler` that takes the sweep id set instead of relying on `_skwirrel_synced_at < started_at`.
  - [ ] Add the mass-removal bound before acting, and the incomplete-sweep refusal. Suggested default ratio **0.25**, as a class constant exposed through a `skwirrel_wc_sync_mass_removal_ratio` filter — not a setting. For scale: 70 of 920 (≈8%) is a normal week on the reference install.
  - [ ] Diff **parent products only**. Variations carry `_skwirrel_virtual_product_id`, not a selection membership of their own, and the purge handler already cascades a trashed parent to its children (`class-skwirrel-wc-sync-purge-handler.php:628-640`). Diffing variations against the sweep set would mark every one of them as missing.
  - [ ] Keep `$missing_state = 'trash'` as-is (see Open decision).
- [ ] **Tests** (AC: 1–7)
  - [ ] Unit: sweep-diff selection logic and the mass-removal ratio maths as pure functions.
  - [ ] Integration: a scheduled (delta) run removes a product that left the selection; a run whose sweep failed removes nothing; a run over the ratio removes nothing and reports; a re-added product is untrashed.
- [ ] **Correct the stale comments** while in these files (no behaviour change)
  - [ ] `class-skwirrel-wc-sync-service.php:1028` claims removals are "handled per the configurable `__missing__` mapping (keep / draft / trash)". No such mapping exists.
  - [ ] The purge log line reads "applying configured handling" for a hardcoded state.

## Dev Notes

### Why this story exists

Confirmed on a live install (plugin 3.12.2, selection 3, ~850 products), 2026-08-18:

- **Every scheduled sync is a delta.** The recurring action is armed with empty args (`class-skwirrel-wc-sync-action-scheduler.php:58`), so `$delta = $force_full ? false : ( $args['delta'] ?? true )` (`:159`) always resolves `true`. There is no periodic full sync, and only the `skwirrel_wc_sync_force_full_sync` flag ever overrides it.
- **So cleanup has never run on a schedule.** Both `purge_stale_products` (`class-skwirrel-wc-sync-service.php:1026`) and `escalate_deprecated` (`:1047`) are gated on `! $ctx['delta']`. Seven scheduled runs in one day all logged `Purge skipped: delta sync (only during full sync)` and `trashed: 0`.
- **The delta payload lies about membership.** Same selection, nine minutes apart: the delta fetched **919** products, the full fetched **850**. The 69 extra are products that have left selection 3 — the same 70 a full sync trashes as "no longer in Skwirrel feed" are re-synced as live products by every delta run. Sample: SKU `5413729248366` / `wc_id 137791` appears once in the full sync (on its trash line) and once in each of four delta runs (as a normal product).

Root cause is **upstream and parked**: the Skwirrel API drops or widens the `dynamic_selection_id` scope when an `updated_on` filter is present. Not fixable here. The sweep neutralises it because the sweep call carries no `updated_on`.

### Current state of the files you will touch

**`class-skwirrel-wc-sync-service.php`**
- `begin_run()` builds `$ctx` (`:316-352`) — add sweep keys here, and note `fetch_filter` already carries the `updated_on` clause for delta.
- `fetch_products_page()` (`:1956`) is called with `$use_filter = true` hardcoded (`:518`), so **both full and delta already go through `getProductsByFilter`**. The `getProducts` branch at `:1977` is dead code on this path. Do not "fix" that; it is not the bug.
- `step_fetch()` loops selections via `$ctx['sel_index']` / `$ctx['page']` and yields on a deadline. Anything you add must be re-entrant across Action Scheduler steps.
- `run_step()` (`:402`) re-applies the run's frozen options on every step because each step builds a fresh service. Sweep state must be loaded the same way, not held in an instance property.

**`class-skwirrel-wc-sync-purge-handler.php`**
- `purge_stale_products()` (`:509`) detects staleness by SQL: `_skwirrel_external_id` present AND `_skwirrel_synced_at` missing or `< $sync_started_at`. The query **excludes `post_status IN ('trash','auto-draft')`** (`:528`, `:551`), which is why a second full sync reports "No stale products found" — already-trashed products are invisible to it. Keep that exclusion.
- `$missing_state = 'trash'` is hardcoded at `:518`.
- A product already in the target state is skipped without logging or counting (`:602`), so the `trashed` count only ever reports products actually changed. Preserve that — it is what makes the count trustworthy.
- On trash it calls `Skwirrel_WC_Sync_Product_Upserter::invalidate_change_gates()` (`:622`) **deliberately**, so a returning product is not skipped by a gate before the revive logic runs. Do not remove this; AC 6 depends on it.

**`class-skwirrel-wc-sync-product-upserter.php`**
- `fetch_product_ids_for_selection()` (`:1053`) is the sweep. It already does the right call shape. Its current failure mode is `break` + `warning` returning a partial set.
- `guard_revive_from_trash()` (`:2535`) untrashes when the mapped status is `publish`/`draft`, and `status_drifted()` (`:2554`) defeats both change gates when WC status has drifted from the mapping. Both are correct; leave them alone.

### Do not

- **Do not make scheduled syncs full syncs.** It was the obvious fix and it was rejected: it makes every scheduled run O(catalogue), and `get_min_interval_seconds()` (`class-skwirrel-wc-sync-action-scheduler.php:327`) derives the minimum allowed sync interval from the last full-sync duration plus an hour, so large catalogues would be forced to sync less often.
- **Do not add a blanket no-revive guard** for products trashed as missing. Reviving a product genuinely re-added to the selection is correct behaviour (AC 6).
- **Do not tick the deprecated counter on the sweep.** `spec-gh-40-2-deprecated-lifecycle.md` is a frozen, human-owned spec and lists "ticking the counter on delta syncs" under **Ask First**. AC 7 holds the current cadence.
- **Do not permanently delete.** Removal means trash, always.

### Open decision — carry, do not resolve

Not yet settled by the team; it sets the final acceptance criteria for the *response* to a removal, not for the detection this story builds:

> Should leaving a selection trash a product at all, or only draft it?

`class-skwirrel-wc-sync-product-mapper.php:26-33` states the design intent: *"products deleted upstream are excluded from the feed by default … and discontinuation is expressed through the product's own status."* Leaving a selection is not the same event as being discontinued, and the plugin currently cannot tell them apart. Note that `spec-gh-40-2-deprecated-lifecycle.md` still documents a configurable `__missing__ → deprecated` mapping that the shipped code no longer has.

Related, and worth raising in the same conversation: `deprecated_remove_after_syncs` counts sync passes. Its shipped default of 3 meant weeks when full syncs were manual. If the cadence ever changes, it becomes hours.

**Build the detection with `trash` as the response. If the decision lands before implementation, only the response changes.**

### Project Structure Notes

- Only edit under `plugin/skwirrel-pim-sync/`. Dev tooling is at the repo root.
- Any new class is `Skwirrel_WC_Sync_{Name}` in `class-skwirrel-wc-sync-{slug}.php`, and must be registered in **two** places: `require_once` in `skwirrel-pim-sync.php` and hook wiring in `Skwirrel_WC_Sync_Plugin`. There is no autoloader.
- This story most likely needs **no new class** — it extends the service, purge handler and upserter. Prefer that over a new one.
- Logging only via `Skwirrel_WC_Sync_Logger`. User-facing strings translatable with the literal text domain `'skwirrel-pim-sync'`.
- Meta keys are contracts; reuse `_skwirrel_product_id` / `_skwirrel_external_id` / `_skwirrel_synced_at`. Do not invent a parallel key.

### Testing

- Gates, from the repo root, all three must pass before commit: `vendor/bin/pest`, `vendor/bin/phpstan analyse` (level 6), `vendor/bin/phpcs`.
- Pest syntax: `test()`, `beforeEach()`, `expect()`, `dataset()`/`with()`. Unit tests in `tests/Unit/` (stub bootstrap), integration in `tests/Integration/` (`npm run test:integration`, real WP+WC via wp-env).
- `tests/Integration/SyncSafetyIntegrationTest.php` already asserts on `$params['filter']['dynamic_selection_id']` (`:369`, `:417`) — extend that harness rather than building a new API stub.
- Do not weaken an existing assertion to make a run pass. Do not regenerate `phpstan-baseline.neon` to hide new findings.
- No version bump, changelog, readme or translation regeneration in this story — those are release-time, via `/release`.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 2`] — control over structural and destructive operations; removals are the highest-risk class of change.
- [Source: `_bmad-output/implementation-artifacts/spec-gh-40-2-deprecated-lifecycle.md`] — frozen spec; escalation is full-sync-only by design, counter-on-delta is Ask First.
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`] — trash-aware upsert lookup + `guard_revive_from_trash()` landed with GH-40 Story 2 (PR #42).
- [Source: `_bmad-output/project-context.md#Sync Architecture Rules`] — purge guards, never permanently delete, `_skwirrel_synced_at` contract, don't zero-out prices.
- [Source: `.claude/rules/sync-service.md`] — run_sync flow, delta vs full, purge logic.
- Findings doc (evidence, run tables, mechanism): https://claude.ai/code/artifact/011657d1-c8b8-482b-bcb0-820f795151f5

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
