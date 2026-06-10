# Changes since 3.6.1 — and why sync stopped working

## Likely root cause (read this first)

**Manual "Sync now" is broken by a self-collision between the admin handler and the new mutex.**

Path of a manual sync click:

1. `Skwirrel_WC_Sync_Admin_Settings::handle_sync_now()` sets the transient `skwirrel_wc_sync_in_progress` to `time()`, TTL 60s, **before** dispatching the background AJAX request. (Unchanged since 3.6.1.)
2. It fires a non-blocking `wp_remote_post` to `admin-ajax.php?action=skwirrel_wc_sync_background`.
3. `handle_background_sync()` calls `Skwirrel_WC_Sync_Service::run_sync()`.
4. **NEW in 3.8.0**, the very first thing `run_sync()` does is:
   ```php
   $existing_heartbeat = get_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
   if ( ! empty( $existing_heartbeat ) ) {
       return [ 'success' => false, 'error' => 'Another sync is already running…' ];
   }
   ```
5. The transient set in step 1 is still there → `run_sync()` returns immediately with "Another sync is already running".
6. `handle_background_sync()` then deletes the transient. Nothing was synced. The user sees no progress.

In 3.6.1 there was no mutex in `run_sync()`, so the transient acted purely as a UI badge ("sync running" indicator). 3.8.0 repurposed the same transient as a hard guard against concurrent runs but never updated `handle_sync_now()` to stop setting it ahead of time. They now collide on every manual click.

Scheduled syncs go through `Action_Scheduler::run_scheduled_sync()` which calls `run_sync()` directly without setting the transient first, so the cron path is mostly fine. It can still be blocked transiently if a previous manual click is within the 60s TTL window, or if a previous run died without `delete_transient` (the code comment claims stale heartbeats are taken over by the new run, but they aren't — `! empty()` is the only check).

### Three viable fixes (pick one)

1. **Drop the pre-set in `handle_sync_now()`.** Let `run_sync()` set the heartbeat itself via `Skwirrel_WC_Sync_History::sync_heartbeat()` (it already does, right after the mutex check). Smallest patch.
2. **Use a different key for the mutex.** Keep `SYNC_IN_PROGRESS` as the UI badge and introduce e.g. `SYNC_MUTEX` for the guard. Cleanest separation, but touches more files.
3. **Add a "force" token.** `handle_sync_now()` writes a one-shot transient that `run_sync()` consumes to bypass the mutex once. More moving parts than necessary.

Recommendation: **Option 1.** The dashboard polls `is_running` via `get_transient(SYNC_IN_PROGRESS)`, which is refreshed by `sync_heartbeat()` at every phase update — the UI badge keeps working without the pre-set.

---

## Full version history 3.6.1 → 3.9.1

Five releases shipped between the working 3.6.1 and the current 3.9.1. Below is a focused summary of what changed, with emphasis on anything that touches the sync path.

### 3.7.0 — PHP 8.3 floor + WPCS cleanup

- **Runtime floor bumped to PHP 8.3.** Header `Requires PHP: 8.1` → `8.3`. `composer.json` runtime constraint, CI matrix, and `.wp-env.json` `phpVersion` all match. If the live server runs PHP 8.1 or 8.2, the plugin will not activate at all. Worth verifying on the affected install.
- **Node engine floor bumped to 22 LTS** (dev-time only).
- **28 class files renamed** from `class-{slug}.php` to `class-skwirrel-wc-sync-{slug}.php`. All `require_once` calls in the bootstrap, `tests/bootstrap.php`, and tests updated to match. Pure rename, no behaviour change.
- **Bootstrap class extracted.** `Skwirrel_WC_Sync_Plugin` moved out of `skwirrel-pim-sync.php` into `includes/class-skwirrel-wc-sync-plugin.php` (303 lines). The plugin entry file shrank from 355 → 67 lines.
- **125 Yoda condition flips** (`$x === 'foo'` → `'foo' === $x`). No behaviour change.
- **33 local variables renamed** from camelCase to snake_case in `class-skwirrel-wc-sync-product-mapper.php` and `class-skwirrel-wc-sync-etim-extractor.php`. No behaviour change.
- **`Category_Sync::assign_categories()` lost its `$mapper` argument.** Five callers in `Product_Upserter` updated. The `on_settings_updated()` callback dropped from 3 args to 2.

### 3.8.0 — Sync-safety hardening (this is where things got risky)

This release rewrote core orchestration in `class-skwirrel-wc-sync-service.php`. Net diff ≈ 300 lines.

- **Mutex on concurrent runs (the regression).** New first-thing check in `run_sync()` reads `SYNC_IN_PROGRESS` transient and refuses if set. The comment claims stale heartbeats are taken over by the new run, but the code only checks `! empty()` — there is no stale-takeover path. Collides with `handle_sync_now()` as described above.
- **Per-run queue isolation.** `Skwirrel_WC_Sync_Queue::truncate()` is now a deprecated no-op (logs a `_deprecated_function` warning when anything calls it). Each run uses its own `sync_run_id` and `$queue->cleanup()` (instance method) instead. If something external still calls the static `::truncate()`, that work silently does nothing now.
- **Pagination atomicity.** A fetch failure on page N is now a hard abort: queue is cleaned up, history records `success=false`, and `run_sync()` returns. In 3.6.1 a mid-pagination failure was a `log + break` — the run continued, `last_sync` got advanced, and purge could trash the un-fetched products. Stricter and safer, but it also means transient API hiccups now visibly fail the run instead of silently completing.
- **Multi-selection loop.** `getProductsByFilter` and `getGroupedProducts` are now called once **per** `collection_ids` entry instead of only `[0]`. Backward-compatible for single-selection configs.
- **Stable media mapping.** `_skwirrel_attachment_id` post-meta now records the Skwirrel `product_attachment_id`. Dedup tries this stable id first and falls back to URL-hash. CDN URL rewrites no longer surface as duplicates.
- **Checksum-based replacement.** `_skwirrel_file_checksum` records `file_sha256_checksum` from the API. Differing checksum on re-sync replaces the file in place, same WP attachment id, sub-sizes regenerated.
- **Offload-plugin-safe missing-file guard.** A broken record only has its Skwirrel meta cleared; the WP attachment is preserved. New filter `skwirrel_wc_sync_attachment_is_valid` lets offload-aware code declare an attachment valid when the local file is missing. Reference impl in `mu-plugins/skwirrel-offload-compat.php` (NOT bundled in the plugin ZIP).
- **Cross-sells / upsells now actually clear** when the Skwirrel payload returns zero relations for a synced bucket.

### 3.8.1 — Grouped-products multi-selection patch

- `Product_Upserter::sync_grouped_products_first()` now calls the per-selection prefilter once per `collection_ids` entry and merges the allowed-product maps. Mirrors the 3.8.0 main-fetch fix; without it grouped products living only in selections 2..N were silently dropped.

### 3.8.2 — Release hygiene (no runtime changes)

- Stopped shipping dev-only files in the WP.org SVN trunk.
- Added `phpcs:ignore` / `phpcs:disable`/`enable` blocks in `category-sync.php` and `purge-handler.php` to silence Plugin Check false positives. No runtime impact.

### 3.9.0 — Endpoint URL doubling fix

- **`normalize_endpoint_url()` static helper** added to `Admin_Settings`. Collapses any number of trailing `.skwirrel.eu` segments, restores `https://`, appends `/jsonrpc` when only a host is given.
- `sanitize_settings()` runs the helper before `esc_url_raw()`; the dashboard reads the helper on display; inline JS strips scheme/path/duplicate suffixes on every input/blur/paste.
- If a user previously had a working bad URL (e.g. with extra `.skwirrel.eu`), the normalizer will silently rewrite it on the next save. Worth checking what endpoint URL is now persisted: `wp option get skwirrel_wc_sync_settings`.

### 3.9.1 — Object-cache settings persistence + Reset button

- **`bust_settings_cache()` helper** wipes `skwirrel_wc_sync_settings`, `skwirrel_wc_sync_auth_token`, `alloptions`, `notoptions` from the `options` group after every save. Fixes "settings refused to persist" on LiteSpeed Object Cache.
- **Reset Skwirrel sync settings** button (Settings → Danger zone). Deletes settings, auth token, last-sync option, force-full-sync flag, slug-resync flag, permalink option, plus the sync/background/purge transients, and unschedules all `skwirrel-pim-sync` Action Scheduler jobs. Preserves products, media, taxonomies, history. Useful escape-hatch if the in-progress transient gets stuck.

---

## File inventory diff

- **3.6.1**: 28 files in `includes/`, bootstrap inside `skwirrel-pim-sync.php`.
- **3.9.1**: 29 files in `includes/` (all renamed `class-skwirrel-wc-sync-*.php` + new `class-skwirrel-wc-sync-plugin.php` bootstrap class), `skwirrel-pim-sync.php` reduced to header + activation hook + `require + ::instance()`.

No class was removed. The only structural change is the bootstrap split.

---

## Quick diagnostic checklist for the broken install

Use these to confirm the diagnosis on the affected site, in order:

1. **Server PHP version**: `php -v`. Must be ≥ 8.3, otherwise activation silently fails. If PHP is lower, that explains everything and the rest of this checklist is moot.
2. **Stale heartbeat transient**: in WP-CLI run `wp transient get skwirrel_wc_sync_in_progress`. If it returns a value, that is what is blocking `run_sync()`. Clear with `wp transient delete skwirrel_wc_sync_in_progress` and try again — sync will succeed once, then break again on the next click (because `handle_sync_now()` re-sets it).
3. **Try a scheduled sync instead of manual.** If the cron path works but "Sync now" does not, that conclusively confirms the mutex collision.
4. **Endpoint URL**: `wp option get skwirrel_wc_sync_settings` and verify `endpoint_url` looks sane after the 3.9.0 normalizer. Compare against what the site was using on 3.6.1.
5. **Object cache**: if on LiteSpeed/Redis, confirm 3.9.1's `bust_settings_cache()` is actually being called on save — settings page POSTs trigger `update_option_skwirrel_wc_sync_settings` which fires `on_settings_updated()`.
6. **Look at the `skwirrel-pim-sync` WC log**: `wp-content/uploads/wc-logs/skwirrel-pim-sync-*.log`. The mutex rejection message ("Another sync is already running") would appear there if my diagnosis is correct.

---

## Next steps

- Confirm the mutex collision on the affected site using the checklist above (clear the transient, try once → expected to succeed once, then break again).
- Apply the one-line fix: remove `set_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS, ... )` from `handle_sync_now()` and `handle_purge_now()`. `sync_heartbeat()` inside `run_sync()` already manages it.
- Bump to 3.9.2, add a Pest test that asserts `run_sync()` is callable immediately after `handle_sync_now()` sets up the dispatch.
- Verify server is on PHP ≥ 8.3 before any further debugging.
