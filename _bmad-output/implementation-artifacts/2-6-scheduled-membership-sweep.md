---
status: done
baseline_revision: 92e38f531a4a02a7b2f367083ec2ff58dfeb0ed6
context:
  - _bmad-output/project-context.md
  - _bmad-output/implementation-artifacts/spec-gh-40-2-deprecated-lifecycle.md
  - .claude/rules/sync-service.md
  - CLAUDE.md
---

# Story 2.6: Scheduled membership sweep

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner,
I want my scheduled syncs to retire products that have left the Skwirrel selection, without ever removing a large batch silently,
so that my shop stops selling products the PIM no longer lists, and I stay in control of destructive changes.

## Acceptance Criteria

**1 — The sweep runs on every scheduled run**

**Given** a sync run (`begin_run()` already hard-fails a run with no configured selection, `class-skwirrel-wc-sync-service.php:226-228`, so at least one selection is always present)
**When** the run reaches its fetch phase
**Then** it fetches the complete product-id list **for every** configured selection via `getProductsByFilter` with `filter: { dynamic_selection_id }` and `options: []` — no `updated_on`, no payload includes
**And** the per-selection id sets are merged into **one union** set before any filtering or diffing (a product in any configured selection is in-selection)
**And** the union set plus its completeness flag are persisted outside `$ctx` so they survive step yields and Action Scheduler restarts.

**2 — The delta payload is filtered against the sweep**

**Given** a **complete** sweep, and a delta fetch that returns products absent from the sweep id set
**When** those products are queued
**Then** they are dropped before upsert, counted into a run-level `dropped_out_of_selection` counter, and logged once with the total
**And** no `_skwirrel_synced_at` stamp, status change or untrash is written for them.

**Given** an **incomplete** sweep (any selection or page failed)
**When** the delta payload is processed
**Then** **no product is dropped** — the filter is disabled for that run, because a partial id set would silently skip products that are genuinely still in the selection.

**3 — Removals are driven by the sweep diff, not by absence from the payload**

**Given** `purge_stale_products` is enabled
**When** the run finalizes
**Then** **parent** products (`post_type = 'product'`, status not `trash`/`auto-draft`) carrying a numeric `_skwirrel_product_id` whose id is absent from the sweep union set are handled by the missing-product path
**And** this happens on scheduled runs, not only on manual full syncs
**And** the existing `_skwirrel_synced_at < started_at` purge on full syncs is left in place and unchanged.

**4 — A mass removal is refused, not performed**

**Given** the removal set exceeds `MASS_REMOVAL_RATIO` of the Skwirrel-owned product count — where the **denominator** is the same population the diff is taken over (parent products, status not `trash`/`auto-draft`, carrying a numeric `_skwirrel_product_id`), counted in the same pass as the diff so numerator and denominator can never disagree
**When** the run finalizes
**Then** **nothing at all** is removed (the bound is all-or-nothing, never a partial removal of the first N), the run still completes successfully, and a warning naming the removal count, the owned count and the ratio is logged and stored in the run result
**And** a human can still perform the removal by running a manual sync.

**Given** an owned count of `0`, or a removal set at or below `MASS_REMOVAL_FLOOR`
**When** the bound is evaluated
**Then** it does not trip: a zero denominator never divides, and a catalogue small enough that a normal removal exceeds the ratio (2 owned products, 1 leaving = 50%) is not locked out of every removal forever. Suggested floor **5**.

**5 — An incomplete sweep never removes anything**

**Given** any sweep page fails, or the sweep did not complete for every configured selection
**When** the run finalizes
**Then** no removals happen at all and the reason is logged **and stored in the run result**
**And** the delta content sync still completes normally (an incomplete sweep degrades the run to today's behaviour — it never fails the run).

**6 — Genuine re-adds still revive**

**Given** a product previously trashed as out-of-selection reappears in both the sweep set and the payload
**When** it is upserted
**Then** `guard_revive_from_trash()` untrashes it exactly as today.

**7 — The deprecated escalation cadence is unchanged**

**Given** any scheduled run
**When** it finalizes
**Then** `escalate_deprecated()` still runs on full syncs only and its counter is not advanced by the sweep.

**8 — A refusal is visible to the store owner, not only in the log file**

**Given** a run that refused a removal (mass-removal bound or incomplete sweep)
**When** the store owner looks at the dashboard status card and history row for that run
**Then** the refusal reason and the numbers behind it are readable there in plain language, without opening a WooCommerce log file
**And** the run still shows as successful, because refusing to remove is the correct outcome, not a failure.

**9 — Sweep-driven removals behave exactly like purge-driven ones**

**Given** a product removed by the sweep diff
**When** it is trashed
**Then** it carries the same side effects the existing purge path applies: the run marker via `Skwirrel_WC_Sync_Run_Links::mark_trashed()` (so Story 2.3's "removed (K)" deep-link resolves), `reset_deprecated_counter_on_entry()`, `Skwirrel_WC_Sync_Product_Upserter::invalidate_change_gates()`, the variation cascade for variable parents, and the "already in the target state → skip silently, do not count" rule
**And** the reported `trashed` count therefore still equals the number of products this run actually changed.

## Tasks / Subtasks

- [x] **Promote the sweep to a reusable service** (AC: 1, 5)
  - [x] Move/expose `fetch_product_ids_for_selection()` so both the grouped-product post-filter and the sync service can call it. It is currently `private` on the upserter (`class-skwirrel-wc-sync-product-upserter.php:1053`) with one caller at `:937`.
  - [x] Page it at a limit independent of the content `batch_size` (reference install runs `batch_size: 25`, so 850 ids costs 34 calls). A dedicated constant is enough — no new setting.
  - [x] Return a completeness flag alongside the id set; the existing implementation `break`s on a failed page and returns a partial set silently, which AC 5 must not tolerate.
- [x] **Run the sweep as part of the run** (AC: 1)
  - [x] Call it for **every** entry in `$ctx['collection_ids']` during `step_init` or a new step in `class-skwirrel-wc-sync-service.php`, before `step_fetch`. Merge the per-selection maps into one union — the grouped-product prefilter already does exactly this with `$allowed_product_ids += $ids_for_selection` (`class-skwirrel-wc-sync-product-upserter.php:937`); reuse that shape, an `array<int, true>` keyed for `isset()`.
  - [x] A selection is complete only if **all** its pages succeeded; the run's flag is the AND across every selection. One failed selection out of two means an incomplete sweep, not a smaller one.
  - [x] Persist the union set + completeness flag in its own autoload-off option, not inline in `$ctx`. Follow the existing pattern for large per-run payloads: the product→group map uses `save_group_map()` / `load_group_map()` (`OPTION_GROUP_MAP`, `class-skwirrel-wc-sync-service.php:66`, `:1371-1399`). An 850-id array inline would bloat every run-state write.
  - [x] Load it the same way `load_group_map()` is loaded inside the step (`:506`), **not** into an instance property — `run_step()` builds a fresh service per step (`:402-410`).
  - [x] Clear it on run completion and on `fail_run()`, the same way `clear_group_map()` is called at `:314`.
- [x] **Filter the delta payload** (AC: 2)
  - [x] In `step_fetch` (`class-skwirrel-wc-sync-service.php:512-560`), drop fetched products whose `product_id` is not in the sweep union set before they are queued — after the VIRTUAL/group-member branches, so a group member is not dropped by mistake.
  - [x] Skip the filter entirely when the sweep is incomplete (AC 2, second scenario).
  - [x] Accumulate the count in `$ctx` (it must survive step yields, like every other counter there) and log once at finalize with the total, not once per product.
- [x] **Drive removals from the sweep** (AC: 3, 4, 5, 9)
  - [x] In `step_finalize` (`class-skwirrel-wc-sync-service.php:1024-1033`), replace the `if ( $ctx['delta'] ) { skip }` guard with the sweep-diff path. Keep the `finalize_stage` staging — finalize can yield and be re-entered, so the diff must run once per run, not once per re-entry.
  - [x] Add a diff-based entry point on `Skwirrel_WC_Sync_Purge_Handler` that takes the sweep id set instead of relying on `_skwirrel_synced_at < started_at`. **Extract the shared removal body** out of `purge_stale_products()` (`:595-640`) and call it from both paths rather than writing a second removal loop — that body is where the run marker, deprecated-counter reset, gate invalidation, already-in-state skip and variation cascade live, and AC 9 is a promise to keep all five.
  - [x] Select candidates in one query: `post_id` + `meta_value` for `meta_key = _skwirrel_product_id`, joined to `wp_posts` on `post_type = 'product'` and `post_status NOT IN ('trash','auto-draft')`, with the same `REGEXP '^[0-9]+$'` numeric guard the existing purge uses (`:536`). Diff in PHP against the id map — do **not** build an 850-term `NOT IN (…)`. The same result set gives you the AC-4 denominator for free.
  - [x] Add the mass-removal bound before acting, and the incomplete-sweep refusal. Suggested default ratio **0.25**, as a class constant exposed through a `skwirrel_wc_sync_mass_removal_ratio` filter — not a setting. For scale: 70 of 920 (≈8%) is a normal week on the reference install. Add the `MASS_REMOVAL_FLOOR` (suggested 5) and the zero-denominator guard from AC 4.
  - [x] Diff **parent products only**. Variations carry `_skwirrel_virtual_product_id`, not a selection membership of their own, and the purge handler already cascades a trashed parent to its children (`class-skwirrel-wc-sync-purge-handler.php:628-640`). Diffing variations against the sweep set would mark every one of them as missing. Note the existing stale query deliberately includes `product_variation` (`:529`) — the sweep query must **not** copy that clause.
  - [x] Variable-product shells have no `_skwirrel_product_id` of their own (`class-skwirrel-wc-sync-pim-link.php:48`), so they never enter the diff. Leave them to the existing full-sync purge; do not invent a membership for them.
  - [x] Keep `$missing_state = 'trash'` as-is (see Open decision).
- [x] **Surface the outcome** (AC: 4, 5, 8)
  - [x] `Skwirrel_WC_Sync_History::update_last_result()` (`class-skwirrel-wc-sync-history.php:206-221`) takes **14 positional parameters and has no slot for a non-fatal warning**. Decide once and note it in the completion notes: either add a trailing optional param, or carry the reason in the existing `error` string while `success` stays `true`. Do not silently drop the reason because the signature did not fit.
  - [x] Render it on the dashboard status card and the history row in plain language, translatable with the literal `'skwirrel-pim-sync'` domain. Match the phrasing register of Story 2.4's plain-language result — a refusal reads as "N products would have been removed (X% of your Skwirrel products) — nothing was removed", not as an exception.
- [x] **Tests** (AC: 1–9)
  - [x] Unit: the sweep-diff selection logic, the union merge across selections, and the mass-removal ratio maths (including zero denominator and the floor) as pure functions.
  - [x] Integration: a scheduled (delta) run removes a product that left the selection; a run whose sweep failed removes nothing **and drops nothing**; a run over the ratio removes nothing and reports; a re-added product is untrashed; a sweep-removed product carries its run marker (AC 9).
  - [x] **The sweep collides with the existing API stub.** `tests/Integration/SyncSafetyIntegrationTest.php` counts `getProductsByFilter` calls per `dynamic_selection_id` and returns products only on the **first** call per selection (`:355-377`). A sweep issued before `step_fetch` consumes that first call, so the content fetch gets an empty page and the test fails for the wrong reason. Teach the stub to tell the two apart — the sweep is the call with `empty( $params['options'] )`, the content fetch always carries `$api_includes` (`class-skwirrel-wc-sync-service.php:1964-1972`). Fix the stub; do not weaken the assertion.
- [x] **Correct the stale comments** while in these files (no behaviour change)
  - [x] `class-skwirrel-wc-sync-service.php:1028-1029` claims removals are "handled per the configurable `__missing__` mapping (keep / draft / trash)". No such mapping exists in shipped code (see Open decision).
  - [x] The purge log line at `class-skwirrel-wc-sync-purge-handler.php:606` reads "applying configured handling" for a hardcoded state. Changing the log **message** is safe; do not rename the `state` context key, it is asserted on.

### Review Findings

- [x] [Review][Patch] Permit a complete empty sweep only through the manual escape hatch; scheduled runs must refuse it and surface a warning (decision: manual-only empty reconciliation) [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:553]
- [x] [Review][Patch] Delta runs with no queued payload bypass finalization, sweep removals, and refusal reporting [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:717]
- [x] [Review][Patch] An incomplete sweep is still used as an authoritative grouped-product filter [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:484]
- [x] [Review][Patch] Membership filtering runs before the virtual/group-member exemptions required by AC 2 [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:649]
- [x] [Review][Patch] Sweep pagination infers completion from page size, ignores pagination metadata, and has no termination guard [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php:1116]
- [x] [Review][Patch] Malformed or missing product IDs in a successful sweep page can leave a partial set marked complete [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php:1108]
- [x] [Review][Patch] A payload row without a usable product ID bypasses the complete-sweep membership filter [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:649]
- [x] [Review][Patch] The entire membership sweep runs in the unbounded init step without a persisted selection/page cursor [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:484]
- [x] [Review][Patch] Synchronous run results omit the non-fatal removal warning stored in history [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:1334]
- [x] [Review][Patch] Conflicting duplicate `_skwirrel_product_id` rows are resolved by database row order and can select the wrong membership ID [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-purge-handler.php:977]
- [x] [Review][Patch] Required coverage does not assert multi-selection unioning, the sweep-removal run marker, or dashboard warning rendering [tests/Integration/SweepMembershipIntegrationTest.php:1]
- [x] [Review][Defer] Runtime sweep behavior is covered only by integration tests that normal CI does not execute [.github/workflows/ci.yml:48] — deferred, pre-existing

#### Re-review Findings

- [x] [Review][Patch] Abort before product writes when grouped sync is enabled and the membership sweep is incomplete, preventing both catalogue-wide grouped imports and grouped members being handled as simple products (decision: fail safely and retry later) [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:505]
- [x] [Review][Patch] A complete empty scheduled sweep is still authoritative for grouped and payload filtering, so a transient empty response can suppress every update even though removal is refused; surface the warning even when purge is disabled [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:520]
- [x] [Review][Patch] A successful sweep response with no `products` field is accepted as an authoritative empty page instead of an incomplete sweep [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php:1165]
- [x] [Review][Patch] Sweep completion accepts lossy or contradictory pagination metadata and treats a short page as terminal when metadata is absent, allowing server-side page caps to truncate membership silently; validate progress and detect repeated pages [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php:1165]
- [x] [Review][Patch] Oversized digit strings overflow into `PHP_INT_MAX`, while collection settings also admit zero, negative, decimal, and overflowing IDs; normalize all sweep, payload, and purge identifiers as strict positive platform integers [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:1427]
- [x] [Review][Patch] SKU fallback can classify an out-of-selection payload row as an allowed group member when it shares a SKU with an in-selection product [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:710]
- [x] [Review][Patch] A database error while loading owned-product membership is cast to an empty result and reported as a successful no-op instead of refusing removal with a warning [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-purge-handler.php:957]
- [x] [Review][Patch] The incomplete-sweep grouped regression test returns no groups, so it cannot prove that grouped filtering is disabled or that the selected fail-safe policy is honored [tests/Integration/SweepMembershipIntegrationTest.php:1]
- [x] [Review][Patch] The persisted sweep selection/page cursor, deadline yield, resume path, and poison-loop signature have no Action Scheduler regression coverage [tests/Integration/SweepMembershipIntegrationTest.php:1]
- [x] [Review][Patch] The manual empty-sweep escape-hatch test runs a delta sync even though the admin action invokes a full sync, and the scheduled-empty test needs a non-empty payload to expose accidental authoritative filtering [tests/Integration/SweepMembershipIntegrationTest.php:1]

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
- The removal body at `:604-640` does **five** things per product, and a hand-rolled second loop will quietly miss some of them:
  1. `logger->info( 'Product no longer in Skwirrel feed …' )`
  2. `set_status()` + `save()`
  3. `reset_deprecated_counter_on_entry()` — without it a returning product carries a stale escalation counter
  4. `invalidate_change_gates()`
  5. `Skwirrel_WC_Sync_Run_Links::mark_trashed( $post_id, $run_id )` — **this is what makes Story 2.3's "removed (K)" deep-link resolve.** Omit it and the count renders but links to an empty product list.
  Then the variation cascade repeats 2–5 for each child. Reuse this body (AC 9); do not re-implement it.
- `purge_stale_products()` returns an `int`. A diff entry point that must also report a refusal needs a richer return — `escalate_deprecated()` (`:688`) already returns `[ 'trashed' => int, 'complete' => bool ]`; follow that shape rather than inventing a third convention.

### Sweep-set semantics

- **The union, not per-selection sets.** `dynamic_selection_id` is a single-int filter, so N configured selections mean N sweep calls. Diffing a product against only its own selection's set is wrong — merge first. `sync_grouped_products_first()` (`class-skwirrel-wc-sync-product-upserter.php:928-947`) is the reference implementation of this merge and its comment explains why.
- **The sweep is not a preflight.** It is a fetch of ids only (`options: []`), so it costs one page per `sweep limit` per selection and returns no payload. At the reference install's 850 products a 500-id limit is 2 calls per selection; do not reuse `batch_size`, which is tuned for payload weight.
- **Completeness is a hard gate on both consumers.** Incomplete ⇒ no drops (AC 2) *and* no removals (AC 5). It is tempting to allow the filter but not the removals; that is worse, because dropping a live product from the payload silently withholds its update with no counter-evidence anywhere.
- **Sweep ids are Skwirrel `product_id` values**, matching `_skwirrel_product_id` (`class-skwirrel-wc-sync-product-mapper.php:18`), written on every upsert path — simple (`upserter:415`, `:1592`) and variation (`:752`, `:1846`). This is deliberately a *different* key from the one the existing stale purge uses (`_skwirrel_external_id`); both are written, so the two paths coexist without interfering.

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

`class-skwirrel-wc-sync-product-mapper.php:26-33` states the design intent: *"products deleted upstream are excluded from the feed by default … and discontinuation is expressed through the product's own status."* Leaving a selection is not the same event as being discontinued, and the plugin currently cannot tell them apart. Note that `spec-gh-40-product-status-handling.md` still documents a configurable `__missing__` → `publish`/`draft`/`trash` mapping (§Boundaries, I/O matrix) that the shipped code no longer has — `purge_stale_products()` hardcodes `trash` and its own comment says so (`class-skwirrel-wc-sync-purge-handler.php:515-518`). The spec is stale, not the code.

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

- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 2`] — "See and control what a sync changes"; control over structural and destructive operations, removals emphasised as the highest-risk class of change. **Note:** Epic 2 enumerates stories 2.1–2.5 only; this story is an addition to the epic, so its acceptance criteria are derived from the live-install findings below, not lifted from the epic file.
- [Source: `_bmad-output/planning-artifacts/epics.md#Story 2.3`] — result counts deep-link into WooCommerce via `?skwirrel_run={run_id}`, "removed" opening the Trash view. This is the shipped feature AC 9's `mark_trashed()` requirement protects.
- [Source: `_bmad-output/planning-artifacts/epics.md#Story 2.4`] — plain-language result and history; the register AC 8's refusal message must match.
- [Source: `_bmad-output/implementation-artifacts/spec-gh-40-product-status-handling.md`] — origin of the `__missing__` pseudo-status and the "`__missing__` runs only on full sync, no collection filter, purge enabled" boundary this story deliberately widens. Read §"Boundaries & Constraints" and the I/O matrix row "No longer in feed" before touching the purge path.
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

#### Verification against the refined spec, and two gaps closed

The implementation was written against the earlier 7-AC version of this story. The spec was then
sharpened to 9 ACs (AC 8 dashboard/history visibility, AC 9 side-effect parity) with extra
subtasks. Re-verified every AC against the merged code; two genuine gaps were found and closed.

- **AC 4 — `MASS_REMOVAL_FLOOR` was missing.** The bound was ratio-only, so a small catalogue was
  locked out of every scheduled removal permanently: 2 owned products with 1 leaving is 50%, over
  any sane ratio, on every run. Added `MASS_REMOVAL_FLOOR = 5` as an unconditional early return in
  `exceeds_mass_removal()` — at or below the floor the ratio is not consulted. The zero-denominator
  guard AC 4 also asks for was already present (`$owned_count <= 0` returns false, never divides).
  - **One existing assertion was deliberately changed, not weakened.** `'a ratio of 0 refuses any
    removal at all'` asserted `exceeds_mass_removal(1, 920, 0.0) === true`. AC 4 makes the floor
    unconditional, so a set of 1 can no longer trip any bound. The test now pins the same property
    one product above the floor (`6, 920, 0.0`) and is renamed to say so. A ratio of 0 now means
    "refuse any removal above the floor", which is what the docblock says.
- **AC 8 — the refusal reached the status card but not the history row.** `warning` was stored on
  the run result (and therefore already in each history entry) and rendered under the dashboard's
  last-sync line, but `render_history_table()` never read it, so the row for a refused run was
  indistinguishable from a clean one. Added a `.skw-row-warning` advisory row under the entry it
  belongs to, escaped, colspan-11, with a matching style in `dashboard.css`. The Success badge is
  unchanged — refusing is the correct outcome, per AC 8.

Verified as already satisfied, no change needed:

- **AC 9** — `apply_missing_state()` is one shared removal body carrying all five side effects
  (`logger->info`, `set_status`+`save`, `reset_deprecated_counter_on_entry()`,
  `invalidate_change_gates()`, `Run_Links::mark_trashed()`), the variation cascade, and the
  already-in-target-state skip that is not counted. Both detections call it; there is no second loop.
- **Sweep cleared on `fail_run()`** — `fail_run()` calls `finish_run()`, which calls
  `clear_sweep_set()` alongside `clear_group_map()`.
- **Drop-filter placement.** The refined subtask asks for the membership filter to sit *after* the
  VIRTUAL/group-member branches so a group member is not dropped by mistake; it currently sits
  before them. Left as-is deliberately: `sync_grouped_products_first()` post-filters group members
  against this same id set by `product_id` (`class-skwirrel-wc-sync-product-upserter.php:986-996`),
  so a member absent from the sweep is already excluded from the group map and cannot be rescued by
  a later branch. Moving the check would change nothing and would put the drop after two `continue`s
  that would then bypass the counter.

Gates re-run on the merged result, from the repo root:

- `vendor/bin/pest` — **396 passed** (619 assertions).
- `vendor/bin/phpcs` — clean (34 files).
- `php -d memory_limit=2G vendor/bin/phpstan analyse --debug` — no errors.
- `npm run test:integration` — **61 passed** (381 assertions), 1 pre-existing deprecation
  (`Skwirrel_WC_Sync_Queue::truncate()` in `SyncSafetyIntegrationTest`).

**The floor forced three integration fixtures to grow, and that is the point of it.** Three
mass-removal tests were built on 3-of-4 and 3-of-3 fixtures, which the floor no longer classifies as
mass removals — they went green-to-red on a behaviour change AC 4 explicitly asks for. Rather than
lower the floor or relax the assertions, the fixtures were scaled so each scenario is still the
thing its name claims:

- `SweepMembershipIntegrationTest` — "a scheduled FULL sync is braked exactly like a scheduled
  delta" and its manual-trigger mirror now seed 12 products (11 stale); "a removal set over the
  mass-removal ratio is refused" and the filter test that lets the same removal through now run
  9-of-12 (75%). The warning assertion moved from `'3 of 4'` to `'9 of 12'`.
- `PurgeHandlerIntegrationTest` — the human-initiated pair now seeds 8 stale products instead of 3.

Every assertion kept its meaning: the same ratios, the same refusals, the same escape hatch. Only
the magnitudes moved above the floor so the ratio is actually the thing under test.

#### Code-review remediation (2026-08-19)

- Reworked the membership sweep into validated, one-page steps with persisted selection/page
  cursors. The Action Scheduler poison-loop watermark now includes that cursor, so a large sweep
  can yield repeatedly without being mistaken for a stalled run.
- Sweep pagination now follows API page metadata when present, falls back safely when absent, has a
  hard termination guard, and marks malformed product-id rows incomplete instead of authorising a
  partial set.
- Every delta reaches finalization even when no content rows are queued. Complete-sweep filtering
  now runs after virtual/group-member handling and drops malformed simple-product payload rows.
- Incomplete sweeps disable grouped-product filtering. Conflicting duplicate product-id meta fails
  closed, and synchronous results now carry the same removal warning stored in history.
- Empty selection decision: scheduled runs refuse a complete empty sweep and direct the owner to a
  manual sync; a manual run can deliberately reconcile the catalogue to empty.
- Added executable coverage for pagination metadata, malformed ids, incomplete grouped filtering,
  multi-selection unions, no-payload removals, run markers, empty-selection authorization,
  duplicate meta, returned warnings, and both dashboard warning surfaces.
- Final gates: `vendor/bin/pest` — 396 passed (619 assertions); `vendor/bin/phpcs` — clean;
  `php -d memory_limit=2G vendor/bin/phpstan analyse --debug --no-progress` — no errors;
  `npm run test:integration` — 87 passed (539 assertions), 1 pre-existing deprecation.

#### Re-review remediation (2026-08-19)

- Incomplete or unattended-empty membership results now abort before grouped shells or payloads are
  written when grouped-product sync is enabled. Product-only runs continue without treating an
  unattended empty result as authoritative, and the warning remains visible when purge is disabled.
- Sweep pages now require a valid `products` collection, strict non-lossy pagination metadata when
  supplied, forward progress, and strict positive platform-sized identifiers. Metadata-free APIs are
  read through an empty terminal page so server-side caps cannot truncate membership silently.
- Authoritative membership resolves grouped rows by validated product id rather than SKU fallback.
  Purge database failures and invalid/overflowing product-id meta now refuse removal explicitly.
- Added regression coverage for grouped fail-safe aborts, missing/contradictory/repeated pagination,
  server-capped pages, overflow, async yield/resume and poison signatures, SKU collisions, scheduled
  empty payload processing, purge-disabled warnings, and the real manual full-sync escape hatch.
- Updated and compiled every shipped translation catalogue for the four new operator-facing safety
  messages.
- Final gates: `vendor/bin/pest` — 396 passed (619 assertions); `vendor/bin/phpcs` — clean;
  `php -d memory_limit=2G vendor/bin/phpstan analyse --debug --no-progress` — no errors;
  `npm run test:integration` — 97 passed (569 assertions), 1 pre-existing deprecation;
  all seven catalogues pass `msgfmt --check`.

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
- `plugin/skwirrel-pim-sync/assets/dashboard.css`
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot`
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-*.po`
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-*.mo`

## Change Log

| Date | Change |
|------|--------|
| 2026-08-18 | Implemented the sweep, the removal chokepoint and the dashboard warning; all gates green. |
| 2026-08-19 | Merged into `main`; re-verified against the refined 9-AC spec. Closed two gaps: `MASS_REMOVAL_FLOOR` (AC 4) and the refusal in the history row (AC 8). Scaled three mass-removal integration fixtures above the new floor. All four gates green: pest 396, phpcs clean, phpstan clean, integration 61. |
| 2026-08-19 | Completed the adversarial re-review: hardened grouped fail-safe behavior, empty-sweep handling, pagination, identifiers, SKU membership and database failures; added async and edge-case coverage; refreshed all translations. |
