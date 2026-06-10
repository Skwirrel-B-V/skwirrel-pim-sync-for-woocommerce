---
title: 'WP 7.0 recovery release (3.10.2): re-arm scheduler, connector type, category rename'
type: 'bugfix'
created: '2026-06-10'
status: 'done'
baseline_commit: '2823b8920a0bd5a57f4d311a7ae82861fd056361'
context:
  - '{project-root}/CLAUDE.md'
  - '{project-root}/_bmad-output/project-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/investigations/wp70-sync-break-investigation.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** After clients auto-updated the plugin and moved to WordPress 7.0 / WooCommerce 10.x, three contained failures appeared (from the WP 7.0 investigation): **(F2)** scheduled syncs stop running — the recurring Action Scheduler job is armed only on activation/settings-save, and a WP.org auto-update never re-arms it; **(F1)** the WP 7.0 Connectors registration logs a `_doing_it_wrong` notice because `register()` is called without a non-empty `type`; **(F3)** renaming or re-parenting a category in Skwirrel does not propagate to WooCommerce because the meta-matched term is returned without `wp_update_term()`.

**Approach:** Re-arm the recurring schedule on plugin **version change** plus a cheap self-heal when the configured interval is set but no action exists (F2); pass `'type' => 'service'` when registering the connector (F1, already staged in the working tree); reconcile name/parent on the meta-matched `product_cat` term via `wp_update_term()` only when the Skwirrel-provided value differs (F3). Ship as **3.10.2** (version header/constant/Stable tag already bumped; expand the existing changelog entry).

## Boundaries & Constraints

**Always:** Idempotent (no duplicate scheduled actions, no needless term writes); respect an empty `sync_interval` (= no schedule); reuse the existing `schedule()` / `unschedule()` logic in the scheduler; log only via `Skwirrel_WC_Sync_Logger`; `declare(strict_types=1)`, WPCS naming/escaping; all three gates pass (`pest`, `phpstan`, `phpcs`); F3 updates a term **only** when the Skwirrel name/parent actually differs from the current term (do not clobber manual WC edits).

**Ask First:** Any change that would require touching the 6-phase sync orchestrator or the upsert/identity logic (it should not be needed); deleting the legacy `skwirrel_wc_sync_auth_token` option.

**Never:** Touch the sync orchestrator phasing / `last_sync` checkpoint logic or the upsert SKU/identity code — F4/F6/F7 are deferred to the Architecture step. Do not add new settings, change the API client, or bump the version beyond 3.10.2.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Upgrade, interval set | stored version ≠ `SKWIRREL_WC_SYNC_VERSION`, `sync_interval='daily'`, no/stale action | `schedule()` runs → exactly one recurring `skwirrel_wc_sync_run` armed; version option updated; info-logged | n/a |
| Upgrade, interval empty | version changed, `sync_interval=''` | no action scheduled (unschedule path); version option updated | n/a |
| Self-heal | version unchanged, interval set, **no** scheduled action | `ensure_scheduled()` arms exactly one action | n/a |
| Steady state | version unchanged, interval set, action already exists | no-op — no duplicate created | n/a |
| Category meta-match, name changed | term found by `_skwirrel_category_id`, Skwirrel name differs | `wp_update_term()` updates the name; info-logged | `WP_Error` → warning log, still return existing term_id |
| Category meta-match, parent changed | found by meta, mapped parent term differs and > 0 | `wp_update_term()` updates the parent | as above |
| Category meta-match, unchanged | name and parent already identical | no `wp_update_term()` call | n/a |

</frozen-after-approval>

## Code Map

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php` -- **F2**: add `ensure_scheduled()`, `is_scheduled()`, version-gated `maybe_upgrade_reschedule()`; hook on `admin_init` in constructor. Reuse existing `schedule()`/`unschedule()`.
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-connectors.php` -- **F1**: `'type' => 'service'` at line 106 (already staged; verify only).
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php` -- **F3**: add private `maybe_update_term()`; call it at the meta-match return (line ~350-360) before returning. Covers both tree-build and `assign_categories` (both route through `find_or_create_category_term()`).
- `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` / `readme.txt` / `CHANGELOG.md` -- version 3.10.2 already set; expand changelog entry to cover F1/F2/F3.
- `tests/Unit/` -- new Pest tests for F2 (re-arm/self-heal/no-duplicate decision logic) and F3 (update-only-when-differs logic).

## Tasks & Acceptance

**Execution:**
- [x] `includes/class-skwirrel-wc-sync-action-scheduler.php` -- add `VERSION_OPTION` const + public `ensure_scheduled()` (no-op when interval empty or action already scheduled, else `schedule()`), private `is_scheduled()` (`as_next_scheduled_action` when available, else `wp_next_scheduled`), public `maybe_upgrade_reschedule()` (on version-option mismatch call `schedule()` and update the option; otherwise call `ensure_scheduled()` as self-heal; info-log the re-arm), and `add_action('admin_init', [$this,'maybe_upgrade_reschedule'])` in the constructor -- re-arms the recurring job after an auto-update that skipped activation, and heals a lost action.
- [x] `includes/class-skwirrel-wc-sync-category-sync.php` -- add private `maybe_update_term(int $term_id, string $name, string $taxonomy, int $parent_term_id): void` (build `$update` with `name` only when non-empty and differs, `parent` only when `>0` and differs; if empty, return; else `wp_update_term()`, warning-log on `WP_Error`, info-log on success) and call it at the meta-match branch before `return (int) $existing_term_id;` -- propagates Skwirrel renames/parent-moves without clobbering unchanged terms.
- [x] `includes/class-skwirrel-wc-sync-connectors.php` -- verify the staged `'type' => 'service'` is present and correct -- silences the WP 7.0 connector notice.
- [x] `CHANGELOG.md` + `plugin/skwirrel-pim-sync/readme.txt` -- expand the `3.10.2` / `= 3.10.2 =` entry to list F2 (scheduler re-arm on upgrade), F1 (connector type), F3 (category rename/parent sync) alongside the existing WP 6.9 note -- accurate release notes (Stable tag/header/constant already 3.10.2).
- [x] `tests/Unit/ActionSchedulerRearmTest.php` + `tests/Unit/CategoryRenameTest.php` -- unit-test the I/O matrix decision logic (re-arm vs self-heal vs no-op; update-only-when-differs) -- lock the behavior against regression (FR-14 spirit).

**Acceptance Criteria:**
- Given a site whose plugin version option is stale and `sync_interval` is non-empty, when `admin_init` fires, then exactly one `skwirrel_wc_sync_run` recurring action exists and the version option equals the constant.
- Given a category previously synced (has `_skwirrel_category_id`) whose Skwirrel name changed, when the category sync runs, then the WooCommerce term name is updated to match; given an unchanged category, then no `wp_update_term()` call is made.
- Given the plugin loads on WP 7.0, when the connector registers, then no "non-empty type" `_doing_it_wrong` notice is emitted.
- Given the repo root, when the three quality gates run, then `pest`, `phpstan analyse`, and `phpcs` all pass.

## Design Notes

F2 trigger choice: a **stored-version-vs-constant** check on `admin_init` (mirroring the existing `Connectors::maybe_migrate_token` pattern) is more robust than `upgrader_process_complete`, which misses manual/SFTP/auto-update paths. `schedule()` already unschedules-then-reschedules and honors an empty interval, so calling it on version change is safe and idempotent. `ensure_scheduled()` self-heals the secondary cause (AS action lost under WC 10.x) cheaply via a single `as_next_scheduled_action` check.

F3 scope: only the **meta-match** branch needs reconciliation — the name-match fallback already matched on name (and parent, via `term_exists($name,$tax,$parent)`), so it can't be stale. One helper, one call site, both sync paths covered because the tree-builder also routes through `find_or_create_category_term()`.

## Verification

**Commands:**
- `vendor/bin/pest` -- expected: all green, including the two new test files.
- `vendor/bin/phpstan analyse` -- expected: no new errors (level 6, baseline unchanged).
- `vendor/bin/phpcs` -- expected: clean (run `vendor/bin/phpcbf` first to auto-fix).

## Suggested Review Order

**F2 — scheduler re-arm (the headline fix)**

- Entry point: version-change trigger on `admin_init` re-arms after a WP.org auto-update.
  [`action-scheduler.php:98`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php#L98)
- Idempotent self-heal: arms the schedule when an interval is set but no action exists.
  [`action-scheduler.php:76`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php#L76)
- Review-patch P4: detect a schedule on either backend (Action Scheduler or WP-Cron).
  [`action-scheduler.php:60`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php#L60)
- Review-patch P2: register `weekly` so the WP-Cron fallback can't fail-loop.
  [`action-scheduler.php:172`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php#L172)
- Wiring: the `admin_init` hook that drives it all.
  [`action-scheduler.php:33`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php#L33)

**F3 — category rename / parent propagation**

- Call site: reconcile the meta-matched term before returning it (was the bug).
  [`category-sync.php:352`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php#L352)
- Helper: `wp_update_term()` only when name/parent actually differ (no clobbering).
  [`category-sync.php:456`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php#L456)
- Review-patch P3: skip a cyclic re-parent that would silently root the term.
  [`category-sync.php:472`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php#L472)

**F1 — connector type (verify)**

- Non-empty `type` silences the WP 7.0 `_doing_it_wrong` notice.
  [`connectors.php:106`](../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-connectors.php#L106)

**Peripherals — release notes & tests**

- Changelog (detailed) and readme (terse), both at 3.10.2.
  [`CHANGELOG.md`](../../CHANGELOG.md) · [`readme.txt`](../../plugin/skwirrel-pim-sync/readme.txt)
- Tests lock the re-arm decision logic and the rename/cyclic-guard logic.
  [`ActionSchedulerRearmTest.php`](../../tests/Unit/ActionSchedulerRearmTest.php) · [`CategoryRenameTest.php`](../../tests/Unit/CategoryRenameTest.php)
