---
status: review
baseline_revision: 92e38f531a4a02a7b2f367083ec2ff58dfeb0ed6
context:
  - _bmad-output/project-context.md
  - _bmad-output/implementation-artifacts/spec-gh-40-2-deprecated-lifecycle.md
  - .claude/rules/sync-service.md
  - CLAUDE.md
---

# Story 2.6: Scheduled membership sweep

Status: review

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

- [x] **Promote the sweep to a reusable service** (AC: 1, 5)
  - [x] Move/expose `fetch_product_ids_for_selection()` so both the grouped-product post-filter and the sync service can call it. It is currently `private` on the upserter (`class-skwirrel-wc-sync-product-upserter.php:1053`) with one caller at `:937`.
  - [x] Page it at a limit independent of the content `batch_size` (reference install runs `batch_size: 25`, so 850 ids costs 34 calls). A dedicated constant is enough — no new setting.
  - [x] Return a completeness flag alongside the id set; the existing implementation `break`s on a failed page and returns a partial set silently, which AC 5 must not tolerate.
- [x] **Run the sweep as part of the run** (AC: 1)
  - [x] Call it per selection id during `step_init` or a new step in `class-skwirrel-wc-sync-service.php`, before `step_fetch`.
  - [x] Persist the id set + completeness flag in `$ctx`. Follow the existing pattern for large per-run payloads: the product→group map is stored in its own autoload-off option via `save_group_map()` / `load_group_map()` (`OPTION_GROUP_MAP`), not inline in `$ctx`. Do the same — an 850-id array inline would bloat every run-state write.
  - [x] Clear it on run completion the same way `clear_group_map()` does.
- [x] **Filter the delta payload** (AC: 2)
  - [x] In `step_fetch` (`class-skwirrel-wc-sync-service.php:512-560`), drop fetched products whose `product_id` is not in the sweep set before they are queued.
  - [x] Log once per run with the dropped count, not once per product.
- [x] **Drive removals from the sweep** (AC: 3, 4, 5)
  - [x] In `step_finalize` (`class-skwirrel-wc-sync-service.php:1024-1033`), replace the `if ( $ctx['delta'] ) { skip }` guard with the sweep-diff path.
  - [x] Add a diff-based entry point on `Skwirrel_WC_Sync_Purge_Handler` that takes the sweep id set instead of relying on `_skwirrel_synced_at < started_at`.
  - [x] Add the mass-removal bound before acting, and the incomplete-sweep refusal. Suggested default ratio **0.25**, as a class constant exposed through a `skwirrel_wc_sync_mass_removal_ratio` filter — not a setting. For scale: 70 of 920 (≈8%) is a normal week on the reference install.
  - [x] Diff **parent products only**. Variations carry `_skwirrel_virtual_product_id`, not a selection membership of their own, and the purge handler already cascades a trashed parent to its children (`class-skwirrel-wc-sync-purge-handler.php:628-640`). Diffing variations against the sweep set would mark every one of them as missing.
  - [x] Keep `$missing_state = 'trash'` as-is (see Open decision).
- [x] **Tests** (AC: 1–7)
  - [x] Unit: sweep-diff selection logic and the mass-removal ratio maths as pure functions.
  - [x] Integration: a scheduled (delta) run removes a product that left the selection; a run whose sweep failed removes nothing; a run over the ratio removes nothing and reports; a re-added product is untrashed.
- [x] **Correct the stale comments** while in these files (no behaviour change)
  - [x] `class-skwirrel-wc-sync-service.php:1028` claims removals are "handled per the configurable `__missing__` mapping (keep / draft / trash)". No such mapping exists.
  - [x] The purge log line reads "applying configured handling" for a hardcoded state.

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

claude-opus-5[1m]

### Debug Log References

All four gates run from the worktree root, final state:

- `vendor/bin/pest` — **392 passed** (614 assertions).
- `vendor/bin/phpcs` — clean (34 files).
- `vendor/bin/phpstan analyse --memory-limit=2G --debug` — no errors. NB: the default parallel run
  OOMs its worker on this machine regardless of these changes; `--debug` (single process) works.
- `npm run test:integration` — **61 passed, 1 deprecated** (355 assertions). The single deprecation
  is pre-existing (`Skwirrel_WC_Sync_Queue::truncate()` in `SyncSafetyIntegrationTest`).

### Completion Notes List

#### The sweep

- `Skwirrel_WC_Sync_Product_Upserter::fetch_product_ids_for_selection()` promoted to public,
  returning `{ids, complete}` and paged at its own `SWEEP_PAGE_LIMIT` (500) rather than the content
  `batch_size`. A failed page reports `complete => false` instead of a silent partial set.
- The sweep runs in `step_init` BEFORE the grouped-product pass, and its id set is passed to
  `sync_grouped_products_first()` via a new optional `$allowed_product_ids` argument — the grouped
  post-filter needs the identical membership, so this avoids issuing the same calls twice.
- Persisted out-of-band in `skwirrel_wc_sync_run_sweep` (autoload off), mirroring the group-map
  pattern; cleared in `finish_run()`.
- `step_fetch` drops payload products absent from the sweep before they are queued, counted in
  `$ctx['sweep_dropped']` and logged once per run.

#### The removal chokepoint (revised after coordinator review)

The first implementation put the mass-removal bound on the delta branch only, on the assumption that
a full sync implies a human asked for it. The customer's 2026-08-18 15:31 log disproved that: a
scheduled run went `delta:false` because `skwirrel_wc_sync_force_full_sync` was armed — by the
previous run's own purge, via `Delete_Protection::on_product_trashed()`. Nobody was at the keyboard,
and that run took the unbraked path.

The fix is structural rather than a patch to that scenario:

- `Skwirrel_WC_Sync_Purge_Handler::apply_missing_state()` is now **the single chokepoint**. It is the
  only code in the run that writes the missing-state, and the guards live inside it. A new detection
  path inherits the bound by construction; it cannot reach a removal around it.
- Its guard inputs are **explicit facts passed by whoever knows them**, never re-derived downstream:
  `$human_initiated` (resolved once in `begin_run()` from the run's trigger) and
  `$membership_complete` (from the sweep). Both are required parameters, not defaulted.
- **Three preconditions, all failing closed:**
  1. `! $membership_complete` → refuse. Never bypassable, by anyone — absence cannot be proven from
     an incomplete picture. A zero-length sweep set downgrades to incomplete rather than reading as
     "the selection is empty".
  2. `owned_count < missing_count` → refuse. An incoherent denominator means the caller measured two
     different universes and the ratio would be meaningless.
  3. Magnitude over `MASS_REMOVAL_RATIO` → refuse, **unless** `$human_initiated`. That is AC 4's
     escape hatch, and it now keys on who started the run rather than on delta vs full.
- `purge_stale_products()` and `purge_missing_from_sweep()` are now pure DETECTION. Both return
  `{trashed, refused, message}` and both hand the same two facts to the chokepoint. The bound is not
  duplicated into the full-sync path — there is one copy, inside the chokepoint.
- `count_stale_purge_universe()` gives the synced_at detection a denominator drawn from exactly the
  same joins, statuses, post types and meta keys as its stale queries minus the staleness clause, so
  the stale set is always a subset and precondition 2 holds.
- `step_finalize()` calls one `finalize_removals()` for every run. The detection still branches
  (a delta payload is not a census; a full run's `synced_at` additionally catches products that are
  in the selection but failed to import) — the authorisation does not.

#### Inferences that REMAIN in the removal path, and why each is safe

Stated plainly, as requested:

1. **`$ctx['delta']` selects the detection.** Not a safety inference — it decides which evidence is
   available, not whether removal is permitted. Both detections converge on the same guard.
2. **`human_initiated := TRIGGER_MANUAL === $trigger`.** The only production caller passing
   `TRIGGER_MANUAL` is the admin "Sync now" handler (`class-skwirrel-wc-sync-admin-settings.php:750`);
   everything else, including unrecognised triggers, resolves to `false` and is braked. Safe today
   and fails closed — but it is still an inference: wiring a future automated caller that passes
   `TRIGGER_MANUAL` would silently remove the brake. A dedicated explicit flag on `run_sync()` would
   close this; not done here to avoid widening the story's public API.
3. **A successful sweep response is truthful.** We cannot prove the API is not lying in some new way
   — a successful-but-wrong response is exactly the defect this story exists for. The sweep avoids
   the known trigger (`updated_on`), and the magnitude bound is the backstop for the unknown ones.
4. **Grouped variable parents are outside the sweep diff.** They carry
   `_skwirrel_grouped_product_id`, not `_skwirrel_product_id`, so a variable parent whose whole group
   left the selection is NOT retired on a delta run — only on a full sync via the synced_at path.
   Deliberate (the story requires diffing parents only, and variations have no membership of their
   own), but it is a real coverage gap worth a follow-up.
5. **`escalate_deprecated()` does not pass through the chokepoint.** Justified: it is not an
   inference about membership. It is an explicit per-product countdown the admin configured via
   `deprecated_remove_after_syncs`, and AC 7 plus the frozen GH-40 spec fix its cadence. Flagging it
   rather than leaving it unmentioned — if the team wants every removal braked without exception,
   this is the one that would have to change, and that needs the spec renegotiated.
6. **`purge_all()` (danger zone) bypasses everything.** An explicit admin action behind a
   confirmation UI; out of scope and intentionally unbraked.

#### The force-full feedback loop

Confirmed and self-limiting, in both paths: `Delete_Protection::on_product_trashed()` arms
`force_full_sync` when the purge trashes a product, so the next scheduled run goes full — but both
detection queries exclude `post_status IN ('trash','auto-draft')`, so the retired products are
invisible to the next run, nothing new is trashed, and the flag is not re-armed. It cannot cycle.
With the brake now on the decision, the promoted full run is braked identically to a delta.

One consequence worth a follow-up decision, NOT changed here: a removal on a scheduled delta now
promotes the *next* scheduled run to a full sync, which is the O(catalogue) cost the story explicitly
wanted to avoid. Suppressing `force_full_sync` for the purge's own trashing (the `$internal_op`
mechanism already exists for exactly this reason) would fix it, but that changes Delete_Protection
behaviour outside this story's scope.

#### Other

- `Skwirrel_WC_Sync_History::update_last_result()` gained a trailing `$warning` parameter, stored as
  `warning` and rendered under the dashboard's last-sync line, so a refusal is visible without
  marking a successful run as failed.
- Stale comments corrected: the `__missing__` "keep / draft / trash" mapping claim in
  `step_finalize()` and the purge log line's "applying configured handling".
- The open decision was carried, not resolved: the response stays `trash`, set at one
  `$missing_state` line per detection.

#### Test isolation (the three integration failures)

Diagnosed as **(a) test isolation**, confirmed empirically rather than argued: the file passed 6/6 in
isolation, and bisecting by pairs identified `ProductLookupIntegrationTest` as the leaker. Its
"handles a large batch (>100 ids)" test seeds 120 products tagged with `_skwirrel_product_id` ONLY
and has no cleanup at all. 120 leaked + ~7 from that file's other tests + the 4 seeded = the 131 in
the failure message, exactly. `WP_UnitTestCase`'s transaction does not roll WC product saves back.

The pre-existing per-file cleanups all filter on `_skwirrel_external_id` /
`_skwirrel_grouped_product_id` / `_skwirrel_synced_at`, so a product carrying only
`_skwirrel_product_id` was invisible to them — and very much visible to the sweep diff.

Fixed at both ends: a shared `skwPurgeSkwirrelPosts()` in `tests/Integration/bootstrap.php` covering
all four meta keys; an `afterEach` in `ProductLookupIntegrationTest` so it stops leaking; and
`beforeEach` + `afterEach` in `SweepMembershipIntegrationTest` so it is robust regardless of what ran
before. `PurgeHandlerIntegrationTest` now uses the shared helper too. No assertion was weakened and
the ratio was not lowered — `3 of 4` is still asserted verbatim.

**I concluded it is NOT (b), a scope defect**, and did not narrow the diff. Reasoning:

- The "purge is skipped when a collection filter is active" rule cited from
  `_bmad-output/project-context.md:47` and `.claude/rules/sync-service.md:30` **no longer exists in
  the code** — `grep` finds no such guard anywhere in the plugin, and `begin_run()` now *requires* at
  least one selection id, so if the guard still existed the purge could never run at all. The docs
  describe a world where `collection_ids` was optional. **Both doc lines are now stale and should be
  corrected — flagging rather than editing, since they are project-owned.**
- The sweep set IS the union of every configured selection (`run_membership_sweep()` iterates
  `$ctx['collection_ids']` from `get_collection_ids()` and unions with `+=`) — verified.
- Products synced under a selection later removed from settings are marked missing — but the
  pre-existing full-sync purge does exactly the same to them (never fetched → stale `synced_at` →
  trashed). No regression. What is new is that it can now happen unattended, which is precisely the
  case the magnitude bound covers: dropping a selection produces a large missing set → refused +
  warning. Two new integration tests pin this (`a scheduled FULL sync is braked exactly like a
  scheduled delta` / `a manual FULL sync applies the same removal the scheduled one refused`).

### File List

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php`
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php`
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-purge-handler.php`
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-history.php`
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php`
- `tests/Unit/SweepRemovalTest.php` (new)
- `tests/Integration/SweepMembershipIntegrationTest.php` (new)
- `tests/Integration/bootstrap.php`
- `tests/Integration/PurgeHandlerIntegrationTest.php`
- `tests/Integration/ProductLookupIntegrationTest.php`
- `tests/Integration/SyncSafetyIntegrationTest.php`
- `tests/Integration/SyncServiceIntegrationTest.php`
