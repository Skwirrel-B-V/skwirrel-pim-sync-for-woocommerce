# Changelog — Skwirrel PIM sync for WooCommerce

All notable changes to Skwirrel PIM sync for WooCommerce will be documented in this file.

## [3.11.0]

### Change — batch sync now commits each product fully in one pass (per-product-atomic)

* **Why**: a single-product sync from the product edit screen always worked, but a "normal" batch sync could leave products half-built or duplicated. The cause was structural: batch sync drained the queue in **six global phases** (create → taxonomy → attributes → media → relations → purge), processing *every* product at each phase before moving on. If a run died between phases (timeout, OOM, hard kill), products created in the first phase persisted with no categories/attributes/images, and — because the delta checkpoint had often already advanced — a later delta never went back to finish them. (Investigation findings F4/F6/F7.)
* **Fix** (`includes/class-skwirrel-wc-sync-service.php`): the four per-product phase loops (create, taxonomy, attributes, media) are collapsed into **one loop that fully commits each product before moving to the next** — the same shape as single-product sync. Only genuinely cross-entity work stays deferred to a thin tail: the variable-parent attribute-term flush, virtual/grouped-parent media, and cross-sell/upsell relations (which need every product to exist first), then purge. An interrupted run now leaves only *un-started* products incomplete; they are picked up cleanly on the next run, never bare or duplicated. This is a correctness change, not a speed regression — it does the identical work in **one** pass instead of ~five, with fewer object-cache flushes and queue round-trips; total time is unchanged (still dominated by image downloads, which are deduplicated by `_skwirrel_url_hash`).

### Change — re-syncs report (and skip) unchanged products instead of marking everything "updated"

* **Why**: a manual full sync re-saved every product and reported it as "updated" — even when nothing had changed in Skwirrel — so the result always read e.g. "0 created, 1126 updated". As the user put it: *if only the timestamp differs, it's not really updated.* It also churned `post_modified` on every product and spent minutes re-doing identical work.
* **Fix**: each product carries a `product_updated_on` timestamp (the same field delta sync already filters on). The upsert now compares it against a stored `_skwirrel_updated_on`; if it hasn't advanced, the product is reported **`unchanged`** and the per-product work (the attribute API refetch, re-save and media pass) is **skipped** — while still stamping `_skwirrel_synced_at` so the stale-purge never trashes it. The result now reads e.g. "0 created, 12 updated, 1114 unchanged, 0 failed", and a re-sync of a mostly-unchanged catalog drops from minutes to seconds.
* **Settings-aware**: a global signature of the output-affecting settings (+ plugin version) is stored each run; if it changes (e.g. you turn category sync on, or upgrade the plugin), the gate is disabled for one run so **everything reprocesses once**, then quiets down. The first run after upgrading to this version also reprocesses everything (no stored timestamps yet).
* **Scope**: gating applies to simple products and variations; variable-product *parents* and the variation-finalize pass are not gated yet (a small residual "updated" count). Single-product "Sync now" from the product screen is never gated. New columns: an **Unchanged** count on the dashboard result + history tables. (`includes/class-skwirrel-wc-sync-product-upserter.php`, `includes/class-skwirrel-wc-sync-service.php`.)

### Fix — live progress card now shows the real sync steps

* After the per-product rewrite the "Sync in progress…" card still listed the old six phases, so "Assign categories & brands" and "Connect attributes" hung as never-completing steps (they're now done inside the products step) and "Download images & documents" showed a misleading combined count. The card now lists only the steps that actually run — **Fetch products from API → Create & sync products → Finalize variable products → Link related products → Cleanup & finalize** (the variable and relations steps appear only when those settings are enabled) — and each step's state is derived from the current phase's position so none can get stuck pending (`includes/class-skwirrel-wc-sync-admin-dashboard.php`, `includes/class-skwirrel-wc-sync-service.php`).

### Safety — new products stay `draft` until fully committed

* A newly-created product is now written as `draft` and flipped to its real status (`publish`) only after its categories, attributes, and media are all in place. This closes the remaining window where a crash *mid-product* (e.g. during image download) could leave a freshly-created product **published but bare** on the storefront. Scoped to creation via a single shared `resolve_initial_status()` helper used by both `create_or_update_product()` (batch) and `upsert_product()` (single-product) — existing products keep their real status and are never unpublished mid-resync. Covered by `tests/Unit/DraftHoldStatusTest.php`.

### Fix — duplicate products with suffixed SKUs (F7)

* **Symptom**: re-syncs and simple↔grouped oscillation could create duplicate products with a suffixed SKU like `4250366870007-14768`.
* **Root cause** (`includes/class-skwirrel-wc-sync-product-upserter.php`): when the identity lookup missed but the SKU was already owned by an existing product, the upsert *minted a new `-{product_id}` SKU and created a second product* instead of reconciling with the existing one. The logic was duplicated across `create_or_update_product()` and `upsert_product()`, free to drift.
* **Fix**: a single shared `resolve_sku_identity()` helper now decides the collision outcome for both methods — **reuse** the existing product when an identically-keyed *simple* product is found, and **skip** (let the grouped-product path own it) when the SKU belongs to a *variable* product. A suffixed duplicate is never minted on the new-product path. Covered by `tests/Unit/IdentityReuseTest.php`.

### Fix — interrupted syncs advanced the delta checkpoint too early, stranding images (F4)

* **Root cause** (`includes/class-skwirrel-wc-sync-service.php`): on an initial delta run with no checkpoint, `skwirrel_wc_sync_last_sync` was written **up front**, before any product was committed. A run that died afterwards left the checkpoint advanced past products it never finished, so the next delta skipped them and their images never backfilled.
* **Fix**: the checkpoint is now written **only on provable completion** (end of run), stamped with the run *start* time so products changed upstream *during* the run are still caught next time. A crash before completion leaves `last_sync` untouched → the next run re-pulls and idempotently re-commits. This refines the 3.10.1 "seed the checkpoint so the next run narrows" behavior: the narrowing intent is preserved for successful runs, without the silent-skip risk on a failed one.

## [3.10.3]

### Fix — `wp_skwirrel_sync_queue` table grew without bound, eventually filling the disk

* **Symptom**: on a large-catalog install the `wp_skwirrel_sync_queue` table ballooned to ~8.8 GB / ~95k rows and kept growing across syncs, eventually exhausting disk and blocking admin logins. The table is meant to be transient scratch space that holds the full API payload per product (`product_data LONGTEXT`) only for the duration of a single run.
* **Root cause** (`includes/class-skwirrel-wc-sync-service.php`): the queue was only ever cleaned per-run, by `$queue->cleanup()` (DELETE `WHERE sync_run_id = <this run>`), at the success and *anticipated*-failure paths (API/pagination error, user abort). Any run that died another way left all of its rows orphaned forever, and nothing ever swept them: (1) the `catch` only handled `\RuntimeException` (user abort) — any other `\Throwable` propagated past the cleanup; (2) the `finally` block did not clean the queue; (3) the fatal-error/OOM shutdown handler recorded the crash but never touched the queue table; (4) a hard kill (server timeout, OOM-killer, `kill -9`, deploy mid-sync) bypassed every PHP-level handler. For a multi-hour full sync these unanticipated deaths are the *common* case, so orphaned rows accumulated unboundedly.
* **Fix** (defense in depth):
  * **Start-of-run orphan sweep** — `Skwirrel_WC_Sync_Queue::cleanup_orphans()` deletes every row whose `sync_run_id` differs from the current run, right after the queue is created. The run mutex (3.10.0) guarantees single concurrency, so any pre-existing row belongs to a dead run and is safe to drop. This is the key fix and the only one that self-heals after a hard kill, where no handler runs. Removed rows are warning-logged.
  * **`cleanup()` moved into `finally`** — a single, idempotent cleanup point that covers every thrown-exception path (the prior per-path cleanups become no-ops).
  * **`catch ( \Throwable $e )`** added alongside the existing `RuntimeException` catch — unexpected errors are now logged, recorded as a failed run, and cleaned up instead of silently leaking rows.
  * **Shutdown handler** now calls the new static `Skwirrel_WC_Sync_Queue::delete_run( $sync_run_id )` (run id passed into the closure by reference) so a fatal error / OOM also drops its run's rows.
  * The `$created/$updated/$failed` counters are initialised before the `try` so the new catch can report progress-so-far even on an early crash (this also retired three now-obsolete PHPStan baseline entries).
* **Operator note**: an already-bloated table can be reclaimed when no sync is running with `TRUNCATE TABLE wp_skwirrel_sync_queue;` (a plain `DELETE` does not return InnoDB disk to the OS). Going forward the start-of-run sweep keeps it bounded automatically.

## [3.10.2]

### Fix — scheduled syncs silently stopped after a plugin update on WP 7.0 (F2)

* **Symptom**: after a WordPress.org auto-update (and the move to WP 7.0 / WooCommerce 10.x), scheduled syncs stopped firing on some installs. The plugin kept working for manual "Sync now", but the recurring interval went quiet.
* **Root cause** (`includes/class-skwirrel-wc-sync-action-scheduler.php`): the recurring Action Scheduler job was armed only on activation and on settings save. An auto-update never re-runs the activation hook, so a schedule that was lost (or never re-armed against the new version) was never restored. WooCommerce 10.x's Action Scheduler housekeeping could also drop an orphaned action with nothing to re-create it.
* **Fix**: a stored-version-vs-`SKWIRREL_WC_SYNC_VERSION` check on `admin_init` (`maybe_upgrade_reschedule()`, mirroring the existing `Connectors::maybe_migrate_token` pattern) re-arms the schedule on every plugin version change and records the new version in the `skwirrel_wc_sync_version` option. A cheap idempotent self-heal (`ensure_scheduled()` + `is_scheduled()` via `as_next_scheduled_action`) re-arms the job on any admin page load when an interval is configured but no action exists. Both paths reuse the existing `schedule()`/`unschedule()` logic, honor an empty `sync_interval` (no schedule), and never create a duplicate recurring action.

### Fix — WP 7.0 Connectors registration emitted a `_doing_it_wrong` notice (F1)

* On WordPress 7.0, `register()` requires a non-empty `type`. The Skwirrel connector now registers with `'type' => 'service'` (`includes/class-skwirrel-wc-sync-connectors.php`), silencing the `_doing_it_wrong` notice. The plugin's own token field remains the actual UI until core lifts the `ai_provider` screen restriction.

### Fix — "Settings → Connectors" link pointed to the wrong admin URL

* The dashboard's API-token hint linked to `options-general.php?page=connectors`, which is not where WordPress 7.0 hosts the Connections Screen. Corrected to `options-connectors.php` (`includes/class-skwirrel-wc-sync-admin-dashboard.php`) so the link actually opens the Connectors page.

### New — branded Skwirrel logo on the WP 7.0 Connections Screen

* The Skwirrel connector previously showed the default plug icon. It now registers a `logo_url` (`includes/class-skwirrel-wc-sync-connectors.php`) pointing at the bundled Skwirrel mark (`assets/s.png`), so the Connections Screen shows our branding. Degrades gracefully to the default icon if the URL is unavailable.

### Fix — category renames and parent moves in Skwirrel did not propagate (F3)

* Previously, when a category already matched an existing WooCommerce term by `_skwirrel_category_id`, the term was returned unchanged — so renaming or re-parenting a category in Skwirrel never updated the WooCommerce category. `find_or_create_category_term()` now reconciles the meta-matched term via `wp_update_term()` (`includes/class-skwirrel-wc-sync-category-sync.php::maybe_update_term`), updating the name and/or parent **only** when the Skwirrel value actually differs — manual WooCommerce edits to unchanged fields are never clobbered. A `WP_Error` is warning-logged and the existing term is still returned; successful updates are info-logged. Both the tree-build and per-product assignment paths are covered, as both route through `find_or_create_category_term()`.

### Fix — product documents/downloads silently failed to attach (undefined `is_approved_directory()`)

* **Symptom**: on WooCommerce's current API (WP 7.0 / WC 10.x), every product with downloadable files logged `Failed to auto-approve uploads download directory` followed by a cascade of `Downloadable files save failed … not located within an approved directory`. No documents/downloads were attached to any product.
* **Root cause** (`includes/class-skwirrel-wc-sync-product-upserter.php`): `ensure_uploads_approved_download_directory()` pre-checked the uploads folder with `$register->is_approved_directory()`, a method that does not exist on `…ApprovedDirectories\Register`. The call threw, the surrounding `try/catch` swallowed it, and the `add_approved_directory()` + `enable_by_id()` calls below it never ran — so the uploads directory was never registered in WooCommerce's approved-download allowlist and every downloadable file was rejected.
* **Fix**: use the correct `is_valid_path()` method (WC 6.5+). The uploads base URL is now auto-registered and enabled as intended, once per sync, before the first document save — no manual "Approved download directories" step for the store owner. Pre-existing issue, not introduced in this release.

### Compatibility — minimum WordPress raised to 6.9

* Bumped `Requires at least:` from `6.0` to `6.9` in both the plugin header (`skwirrel-pim-sync.php`) and `readme.txt`. WordPress 7.0+ is now the primary development and test target; 6.9 is the backward-compatibility floor. `Tested up to:` remains `7.0`. No functional code changes — header/metadata only.

## [3.10.1]

### Fix — delta sync touched every product when no checkpoint existed

* **Symptom**: scheduled delta runs reported "updated: N" for every product on every interval, even when nothing changed upstream. The `_skwirrel_synced_at` meta was bumped on every product each cycle.
* **Root cause** (`includes/class-skwirrel-wc-sync-service.php:262-275`): `$delta_since = get_option( 'skwirrel_wc_sync_last_sync', '' )`. When that option was empty (first run, post-reset, post-purge, or after a streak of failed runs that never reached the success-path `update_option` on line 840), the `updated_on >= $delta_since` filter was never added to `$filter`. Line 275 still unconditionally forced `$use_filter = true` so `getProductsByFilter` was called — but with only `dynamic_selection_id`. The Skwirrel API returned every product in the selection. The plugin processed all of them. The next interval found the same empty checkpoint and did the same thing.
* **Fix**: when `$delta === true` and `$delta_since === ''`, treat the run as an initial-delta: log it at info level, and write `last_sync = $sync_started_at` *before* the API call so the next scheduled run has a real checkpoint to filter on. The current run still does a full pass (correct — we need an initial baseline), but no further runs do unless the checkpoint is wiped again. Safe: no products in the selection can be missed by this run because no filter narrows the API response.

### Diagnostics — observable signals for the other three "delta touches everything" causes

So a production install can self-diagnose without enabling `verbose_logging` (which the team can't turn on on live installs):

* **`includes/class-skwirrel-wc-sync-service.php`**: the `Sync started` log line is now `info` (was `verbose`). It records `delta`, `delta_since`, `initial_delta`, `batch_size`, `api_method`, `collection_ids`, `filter` on every run.
* **`includes/class-skwirrel-wc-sync-admin-settings.php::handle_reset_settings`**: the existing reset log now spells out that `last_sync` and `force_full_sync` are being cleared and that the next sync will run as an initial full pass.
* **`includes/class-skwirrel-wc-sync-purge-handler.php`**: same enrichment for the purge-handler reset-state log (line 296 region).
* **`includes/class-skwirrel-wc-sync-action-scheduler.php::run_scheduled_sync`**: new info log when the `force_full_sync` flag is *consumed* — tells you a Delete_Protection-triggered full run is about to happen.
* **`includes/class-skwirrel-wc-sync-delete-protection.php::on_product_trashed` and `::on_category_deleted`**: new info logs when the flag is *set*, with the post_id / term_id that triggered it. If a product gets re-trashed every interval (e.g. via a misbehaving filter on the WC side), this is now visible.

## [3.10.0]

### Fix — manual "Sync now" rejected by mutex collision (regression since 3.8.0)

* **Symptom**: every manual "Sync now" click was refused with "Another sync is already running; refusing to start a second concurrent run." Scheduled syncs worked, which is why the bug shipped undetected for five releases.
* **Root cause**: `Skwirrel_WC_Sync_Admin_Settings::handle_sync_now()` pre-sets the `SYNC_IN_PROGRESS` transient before dispatching the loopback AJAX, so the dashboard renders the "sync running" badge immediately on click. The 3.8.0 concurrency mutex inside `Skwirrel_WC_Sync_Service::run_sync()` reads the same transient as "a previous run is already in progress" and bails out. `Action_Scheduler::run_scheduled_sync()` does not pre-set the transient and was the only path that survived.
* **Fix — two-key separation (`includes/class-skwirrel-wc-sync-history.php`, `includes/class-skwirrel-wc-sync-service.php`, `includes/class-skwirrel-wc-sync-admin-settings.php`)**: new `SYNC_MUTEX` constant + transient owned exclusively by `run_sync()`. `SYNC_IN_PROGRESS` is now purely the UI badge; the mutex check no longer reads it. New helpers `Skwirrel_WC_Sync_History::acquire_sync_mutex()` and `::release_sync_mutex()` encapsulate the timestamp-based staleness check the original code's comment promised but never implemented — a stale mutex (older than `HEARTBEAT_TTL` = 60s) is now genuinely treated as a dead prior run, so a hard crash during sync no longer locks the install out for 60 seconds. `sync_heartbeat()` refreshes both transients; `update_last_result()` and the `finally` block in `run_sync()` release the mutex on every exit path including the four config-error early returns that bypassed `update_last_result()`. The `handle_sync_now()` pre-set is intentionally retained so the dashboard badge appears the instant the user clicks.
* **Tests**: new `tests/Unit/SyncMutexTest.php` (7 cases) covers fresh-mutex refusal, stale-mutex takeover, ignoring the UI badge, `sync_heartbeat` dual-refresh, `release_sync_mutex` clearing only the mutex, and `update_last_result` clearing both. Required transient stubs were added to `tests/bootstrap.php`.

### New — WordPress 7.0 Connectors API integration

* **Why**: WP 7.0 (released 2026-05-20) introduces a **Settings → Connectors** screen that lets users manage API credentials for participating plugins from one centralised place. Surfacing the Skwirrel API token there cleans up our settings page, gives admins one stop for credential rotation, and lines up with the platform direction.
* **What (`includes/class-skwirrel-wc-sync-connectors.php`)**: new singleton `Skwirrel_WC_Sync_Connectors`. Registers a `skwirrel_pim` connector on the `wp_connectors_init` hook with `api_key` authentication and `connectors_skwirrel_pim_api_key` as the credential storage key. Every entrypoint is guarded by `function_exists( 'wp_get_connector' )` so sub-7.0 sites are completely unaffected.
* **Migration**: on the first admin pageload after upgrading to 3.10.0, the legacy `skwirrel_wc_sync_auth_token` value is copied into the Connectors store via `update_option()`. Idempotent — gated by the new `skwirrel_wc_sync_db_version` option. Does not overwrite an existing Connectors credential. The legacy option is **kept** as a hidden fallback for one minor cycle and will be removed in 3.11.0.
* **Read path**: `Skwirrel_WC_Sync_Admin_Settings::get_auth_token()` now prefers the Connectors store and falls back to the legacy option. The two existing call sites (`class-skwirrel-wc-sync-admin-dashboard.php:498` masking, `class-skwirrel-wc-sync-service.php:1384` HTTP auth) are unaffected — same getter, same return type.
* **UI fork (`class-skwirrel-wc-sync-admin-dashboard.php`)**: on WP 7.0+ with the connector registered, the settings page hides the inline API Token password input and renders a status line + link to **Settings → Connectors**. On WP < 7.0 the existing password field, mask, and sanitiser behave exactly as before.
* **Reset Settings (`class-skwirrel-wc-sync-admin-settings.php::handle_reset_settings`)**: the 3.9.1 escape-hatch now also deletes `connectors_skwirrel_pim_api_key` (when the API is available) and invalidates that cache key alongside the existing options.
* **Tests**: new `tests/Unit/ConnectorsApiTest.php` (10 cases) covers registry-detection, registration arg shape, get-token fallback, and the migration's overwrite-protection / idempotency / no-op-without-legacy guarantees.

### Compatibility — WordPress 7.0

* `readme.txt` `Tested up to` bumped from `6.9` to `7.0`. `Requires at least` stays at `6.0`. PHP requirement unchanged (`8.3`).
* Admin CSS (`assets/admin.css`, `assets/dashboard.css`) audited against the new Modern admin theme: all custom rules are namespaced under `.skw-*` / `.skwirrel-*` and don't override core `.wrap` / `.button` / `.notice` chrome. Danger-zone red and warning yellow remain legible.

### CI — WordPress.org Plugin Check

* `.github/workflows/ci.yml` now runs `wordpress/plugin-check-action@v1` against `plugin/skwirrel-pim-sync/` on every push. Marked `continue-on-error: true` for the first release; flipped to blocking once the report is clean. WP.org will soon start scanning submissions and emailing maintainers on failure, so having the same checks visible in CI avoids release-time surprises.

## [3.9.1]

### Fix — settings refused to persist behind persistent object caches

* **Symptom**: after 3.9.0 a user on LiteSpeed Object Cache reported that updating the endpoint URL on the Settings page "did not store the changes" — the new value was written to the DB by `update_option`, but the next page load and the next sync run both read the old value back. WordPress core invalidates the `alloptions` group inside `update_option`, but not every object-cache drop-in propagates that delete across PHP workers in time.
* **Drop-in-agnostic fix (`includes/class-skwirrel-wc-sync-admin-settings.php`)**: new private helper `bust_settings_cache()` calls `wp_cache_delete` on `skwirrel_wc_sync_settings`, `skwirrel_wc_sync_auth_token`, `alloptions`, and `notoptions` in the `options` group. Invoked from `on_settings_updated()` and from the new Reset Settings handler. Uses only the standard cache API — no LiteSpeed-, Redis-, or Memcached-specific code paths — so every conforming drop-in honors it.

### New — Reset Skwirrel sync settings (Settings → Danger zone)

* **Why**: when a misbehaving object cache or other state-corruption bug leaves the plugin's settings stuck on a bad value that the Settings form cannot overwrite, the only previous escape-hatch was to drop into WP-CLI or phpMyAdmin and `delete_option('skwirrel_wc_sync_settings')` by hand.
* **What it does (`includes/class-skwirrel-wc-sync-admin-settings.php::handle_reset_settings`)**:
  * `delete_option` for `skwirrel_wc_sync_settings`, `skwirrel_wc_sync_auth_token`, `skwirrel_wc_sync_last_sync`, `skwirrel_wc_sync_force_full_sync`, `skwirrel_wc_sync_slug_resync_needed`, `skwirrel_wc_sync_permalinks`.
  * Deletes the sync-in-progress, background-sync, background-purge, and test-result transients.
  * Cancels every queued Action Scheduler job in the `skwirrel-pim-sync` group via `as_unschedule_all_actions`.
  * Calls `bust_settings_cache()` so the next read goes to the (now empty) DB.
  * Logs the action via `Skwirrel_WC_Sync_Logger::info()`.
* **What it preserves**: products, media attachments, categories, brands, manufacturers, attribute taxonomies, sync history. This is the "I can't save my endpoint URL" escape-hatch, not a factory reset for product data — the existing "Delete all Skwirrel products" button still handles that.
* **UI**: new form under the existing Danger zone on the Settings tab. Submits via `admin-post.php` with a `skwirrel_wc_sync_reset_settings` nonce and a JS confirmation dialog. After the redirect, an admin notice confirms the reset and directs the user to re-enter the subdomain and token.

## [3.9.0]

### Fix — settings: doubled `.skwirrel.eu` endpoint URL persisted across saves

* **Symptom**: with `lixero-tmp.z06.skwirrel.eu` pasted into the "Skwirrel subdomain" field on Settings, the inline JS appended `.skwirrel.eu/jsonrpc` unconditionally, producing `https://lixero-tmp.z06.skwirrel.eu.skwirrel.eu/jsonrpc`. Sync runs then failed with `cURL error 7: Failed to connect to lixero-tmp.z06.skwirrel.eu.skwirrel.eu`.
* **Why the bad URL "stuck"**: the stored value was round-tripped through the field on every page load — the greedy `^https?://(.+)\.skwirrel\.eu(?:/jsonrpc)?$` regex on the dashboard re-extracted `lixero-tmp.z06.skwirrel.eu` as the "subdomain", refilled the visible input with the doubled value, and unless the user manually wiped the field, re-saving wrote the same bad URL back. Not a caching layer — a self-reinforcing UI loop.
* **Fix in three places (`includes/class-skwirrel-wc-sync-admin-settings.php`, `includes/class-skwirrel-wc-sync-admin-dashboard.php`)**:
  1. New static helper `Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url()` parses the URL, lowercases the host, collapses any number of duplicated trailing `.skwirrel.eu` segments, restores `https://` if missing, and appends `/jsonrpc` when only a Skwirrel host is provided.
  2. `sanitize_settings()` runs the new helper before `esc_url_raw()`, so any doubled value is healed on save.
  3. The dashboard settings tab now calls the helper when reading `endpoint_url` for display — already-stored doubled URLs surface in the subdomain field as the correctly-stripped value.
  4. The inline JS attached to the subdomain field now defensively strips `https://`, trailing path segments, and trailing `.skwirrel.eu` on every `input`, `blur`, and `paste` event before constructing the hidden `endpoint_url`. Pasting a full hostname yields the correct subdomain.
* **No behavior change for sites with a clean URL.** New unit tests in `tests/Unit/AdminSettingsEndpointUrlTest.php` cover the doubled-suffix collapse, triple-suffix collapse, scheme-less input, host-only input, mixed case, and non-Skwirrel hosts.

## [3.8.2]

### Release hygiene — WordPress.org Plugin Check

* **Stop shipping dev-only files in the SVN trunk.** The 10up `action-wordpress-plugin-deploy` script (`deploy.sh:175`) silently skips `--exclude-from=.distignore` when `BUILD_DIR` is set. We use `BUILD_DIR: ./plugin/skwirrel-pim-sync`, so every prior tag inadvertently shipped empty `composer.json`, `phpstan.neon.dist`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, `.phpcs.xml.dist`, `.gitignore`, and `.distignore` placeholder files into the WP.org SVN trunk. Removed those tracked 0-byte placeholders from `plugin/skwirrel-pim-sync/`; wp-env still mounts the real files from the repo root via `.wp-env.json` mappings. The deploy ZIP for 3.8.2 contains only runtime files.
* **Suppress Plugin Check false positives in shipped code:**
  * `skwirrel-pim-sync.php:46` — added `phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` for the `active_plugins` filter call (WordPress core filter, not a plugin-defined hook).
  * `class-skwirrel-wc-sync-category-sync.php` — replaced misplaced `phpcs:ignore` annotations with `phpcs:disable`/`phpcs:enable` blocks around the two `$wpdb->get_var()` term-meta-by-value lookups (no WP API equivalent for "find term by meta value").
  * `class-skwirrel-wc-sync-category-sync.php` (line 364) — added `phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key` on the `'meta_key' => $meta_key` array entry inside a logger call (it's a log context key, not a query argument).
  * `class-skwirrel-wc-sync-purge-handler.php` — replaced misplaced `phpcs:ignore` annotations with `phpcs:disable`/`phpcs:enable` blocks around the three bulk `$wpdb->get_col()` lookups for Skwirrel media + product purge.
* **No runtime behavior changes.** Local `vendor/bin/phpcs`, `vendor/bin/phpstan`, and `vendor/bin/pest` all pass before and after.

## [3.8.1]

* **Grouped-products multi-selection** — `Skwirrel_WC_Sync_Product_Upserter::sync_grouped_products_first()` now calls the per-selection prefilter (`fetch_product_ids_for_selection`) once per configured `collection_ids` entry, merging the resulting allowed-product maps. Mirrors the 3.8.0 main-fetch fix; without this, grouped products whose members lived only in selections 2..N were silently skipped. Locked down by a new red→green test in `SyncSafetyIntegrationTest`.

## [3.8.0]

### Media: real Skwirrel↔WordPress mapping + content-change detection

* **Stable Skwirrel-media → WP-attachment mapping** — `_skwirrel_attachment_id` post meta now records the Skwirrel `product_attachment_id` for every imported attachment. The dedup lookup tries this stable id first and falls back to the URL-hash check only when no id is stored yet. CDN URL rewrites on the Skwirrel side no longer surface as duplicate WP attachments.
* **Content-change detection via Skwirrel's own SHA-256** — `_skwirrel_file_checksum` post meta now records `file_sha256_checksum` from the API. When a re-sync identifies the existing attachment via the stable id and finds a DIFFERENT checksum, the file is replaced in place: same WP attachment id, fresh bytes, image sub-sizes regenerated, mime type updated. Equal-second timestamp collisions with the previous filename are handled safely. Failed downloads / invalid bytes / copy errors are logged and the existing attachment is left untouched.
* **Offload-plugin-safe missing-file guard** — `find_valid_existing_attachment()` verifies `file_exists(get_attached_file($id))` after every successful lookup, but never invokes `wp_delete_attachment()` on a missing-file event. A broken record only has its Skwirrel-side meta keys cleared so future lookups miss it, and the WP attachment record itself is preserved — calling the WP delete pipeline would have triggered offload-plugin hooks (WP Offload Media, S3 Uploads, …) that may have purged the remote copy. New filter `skwirrel_wc_sync_attachment_is_valid` lets offload-aware site code declare an attachment valid when the local file is missing but a remote copy exists; the workspace's `mu-plugins/skwirrel-offload-compat.php` is a drop-in reference implementation.
* **Lazy migration for pre-3.8 attachments** — re-syncs of attachments imported before 3.8 backfill the new meta keys from the current API payload. The backfill only writes EMPTY meta — a non-empty stored checksum that differs from the API value is preserved as a content-change signal that drives the replace path. The first post-3.8 sync of any attachment establishes its baseline; subsequent syncs catch real Skwirrel-side content changes.
* **No re-download on upgrade** — the migration path is designed to make the upgrade transparent. Existing attachments stay where they are; new meta is added in the background as syncs naturally happen.

### Sync-safety hardening (P1 / P2 fixes from code review)

* **Mutex on concurrent runs** — `run_sync()` refuses to start when another run's heartbeat transient is fresh (HEARTBEAT_TTL = 60s). Returns `success=false` with an "already running" error before any HTTP call. A stale heartbeat is treated as "previous run died without cleanup" and the new run takes over. Without this, two concurrent runs raced the shared queue, the per-product `_skwirrel_synced_at` meta and ultimately the purge step — at worst trashing every Skwirrel-managed product because Run B truncated Run A's queue before any synced_at could be written.
* **Per-run queue isolation** — the global `Skwirrel_WC_Sync_Queue::truncate()` is now a deprecated no-op; `run_sync()` no longer wipes the table at startup. Each run uses its own `sync_run_id` and removes only its own rows via the existing `$queue->cleanup()` instance method. Defends queue contents even if the mutex is bypassed.
* **Pagination atomicity** — a fetch failure on a later page is now a hard abort. The previous behaviour (log + `break`) let the run continue through every phase, advance `last_sync` and run the stale-product purge — silently dropping products from future delta syncs and trashing the products on the un-fetched pages. The whole run is now recorded as failed, the queue is cleaned up, and the user-visible error includes the failing page number and selection id.
* **Multi-selection support in the main fetch** — `getProductsByFilter` is now called once per configured `dynamic_selection_id` (the API filter is a single-int field). Previously only `$collection_ids[0]` was used, silently dropping every product that lived only in selections 2..N even though the admin UI accepts the multi-id syntax.
* **Empty cross-sells / upsells now actually clear** — `assign_relations()` always writes the relation buckets the run is configured to sync, even when Skwirrel's payload returned zero relations for that bucket. Previously an empty payload short-circuited and existing WC cross-sells / upsells lingered forever once removed at the source. Buckets we are NOT syncing (e.g. upsells when `related_products_type=cross_sells`) are deliberately left alone — admin-curated relations on the unrelated bucket stay intact.

### Offload-plugin compatibility (new mu-plugin)

* **`mu-plugins/skwirrel-offload-compat.php`** — drop-in reference implementation of the new `skwirrel_wc_sync_attachment_is_valid` filter. Vetoes the missing-file cleanup whenever `wp_get_attachment_url()` returns a URL that does NOT start with the local uploads baseurl — the signal an offload plugin produces when serving the file from remote storage. Not bundled in the plugin ZIP; activate by dropping the file in `wp-content/mu-plugins/`.
* **readme.txt FAQ entry** documenting the filter and showing a one-line `mu-plugin` snippet for the common case.

### WordPress.org Plugin Check submission cleanup

* Expanded `plugin/skwirrel-pim-sync/.distignore` so `.phpcs.xml.dist`, `phpstan.neon.dist`, `phpstan-baseline.neon`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, `.gitignore`, `.distignore` itself, the `tests/` directory and any `vendor/` tree are excluded from the deploy ZIP. Plugin Check's `application_detected` and `hidden_files` errors no longer fire.
* `readme.txt`: trimmed `Tested up to: 6.9.4` → `6.9` (wp.org accepts only the major.minor portion).
* `class-skwirrel-wc-sync-purge-handler.php`: re-cast `array_chunk` outputs at the `implode` site so Plugin Check's static analyser can verify the IDs interpolated into the bulk SQL are int-sanitised. Runtime behaviour unchanged.
* `class-skwirrel-wc-sync-admin-settings.php`: passed the plugin version (instead of `null`) to the Google Fonts `wp_enqueue_style` call to satisfy `WordPress.WP.EnqueuedResourceParameters.MissingVersion`.

### Tests

* New `tests/Integration/MediaImporterIntegrationTest.php` (10 cases) — pins the new dedup-by-attachment-id, checksum-replace, file-existence guard, backfill, and offload-filter behaviours.
* New `tests/Integration/SyncSafetyIntegrationTest.php` (8 cases) — pins the mutex, queue isolation, pagination atomicity, multi-selection and relations-clear invariants. Authored red, turned green by the matching fixes.
* Total: 237 unit + 45 integration tests. PHPStan level 6 clean, PHPCS clean, Plugin Check clean.

## [3.7.0]

* **Bump minimum PHP to 8.3** — PHP 8.1 reached end-of-life on 2025-12-31 and 8.2 is in security-only support until 2026-12-31. `Requires PHP` in the plugin header + readme and the `composer.json` runtime constraint are all updated to `>=8.3`
* **Bump minimum Node.js to 22 LTS** — added `engines.node` to `package.json`. Node 18 EOL'd April 2025; Node 20 maintenance ends April 2026. Node 22 is the current Active LTS (maintenance until April 2027)
* **CI: PHP 8.1 → 8.3** — `.github/workflows/ci.yml` was still installing PHP 8.1, which broke `composer install` after Pest 3 / PHPUnit 11 (both PHP 8.2+) landed in `composer.lock`. Cache key bumped along with the version
* **wp-env: `phpVersion` 8.2 → 8.3** for parity with CI and the new runtime floor
* **Internal cleanup: aligned source files with WordPress coding standards** — no functional changes. With CI no longer dying in `composer install`, phpcs surfaced a backlog of 187 errors that had been masked. Cleared all of them:
  * **File renames**: 28 class files moved from `class-{slug}.php` to `class-skwirrel-wc-sync-{slug}.php` (full class name in kebab-case, per `WordPress.Files.FileName.InvalidClassFileName`). All `require_once` paths in the bootstrap, `tests/bootstrap.php`, and `tests/Unit/ProductUpserterPriceTest.php` updated.
  * **Bootstrap class extracted**: `Skwirrel_WC_Sync_Plugin` moved out of `skwirrel-pim-sync.php` into `includes/class-skwirrel-wc-sync-plugin.php`. The plugin entry file now only carries the header, constants, `before_woocommerce_init` hook, activation hook, and `require + ::instance()`.
  * **Yoda condition flips**: 125 `$expr === 'literal'` → `'literal' === $expr` across the plugin (token-aware fixer, no behavioural change).
  * **Variable naming**: 33 `$camelCase` → `$snake_case` local variables in `class-skwirrel-wc-sync-product-mapper.php` and `class-skwirrel-wc-sync-etim-extractor.php` (e.g. `$etimItems` → `$etim_items`).
  * **Reserved keyword param**: `Skwirrel_WC_Sync_Variation_Attributes_Fix::fix_rest_response_attributes()` parameter `$object` → `$wc_object` (PHP 8.2+ reserves `object` as a soft keyword).
  * **Removed unused parameter**: `Skwirrel_WC_Sync_Category_Sync::assign_categories()` no longer takes a `$mapper` argument (5 callers in `class-skwirrel-wc-sync-product-upserter.php` updated). The `update_option_*` callback `on_settings_updated()` dropped from 3 to 2 args.
  * **PHPCS config**: registered `manage_woocommerce` as a known WooCommerce capability so the WPCS Capabilities sniff stops flagging legitimate uses.
  * **Phpstan baseline regenerated**: same set of 179 entries (mostly missing array shape annotations), new file paths.

## [3.6.1]

* **Fix: ship files that were missing from the 3.6.0 build** — `includes/class-pim-link.php` (the "Open in Skwirrel" deep-link implementation), the updated `class-admin-settings.php` (Settings-saved notice + transient-based connection-test result), the updated `class-product-sync-meta-box.php` (PIM link button), the `assets/s.png` button icon, and the corresponding `.po`/`.mo` translation updates. A 3.6.0 install fatalled on activation with `Failed opening required ... includes/class-pim-link.php` because the class file was untracked in git when the release was tagged

## [3.6.0]

* **"Open in Skwirrel" deep-link** — the Skwirrel meta box on the product edit screen and each row on the WP Products list now offer an "Open in Skwirrel" link that jumps straight to the matching product in the Skwirrel PIM web UI. The host is derived from the configured JSON-RPC endpoint. Simple products use `/catalogue/products/edit/{product_id}`; variable/grouped product shells use `/catalogue/grouped-products/edit/{grouped_id}`
* **"Settings saved" notice on the settings page** — saving settings now shows a proper confirmation. The connection test result moved to a transient so it no longer re-displays after every save (previously a stale `?test=ok` URL parameter caused the test-success notice to fire on save)
* **New `skwirrel_wc_sync_after_attributes_fetched` action hook** — fires during the attributes phase right after a product's enriched payload (with `_etim` and `_custom_classes`) is fetched and before `assign_attributes()` runs. Lets site-specific code persist the enriched payload as post meta for custom frontend rendering, alongside the standard WooCommerce attribute table. Hook signature: `do_action( 'skwirrel_wc_sync_after_attributes_fetched', int $wc_id, array $attr_product, ?array $group_info )`. The hook fires in both the bulk Phase 3 loop and the single-grouped-product re-sync path
* **Fix: variation filter values no longer empty during sync** — pre-sync rebuild of the variable product shell preserved structure but wiped the parent's term-options to `[]`, which left the frontend variation filter (storage, color, connectivity, etc.) empty for the entire duration of the sync. The shell-rebuild in `create_variable_product_from_group()` now preserves the parent's existing term-options for each axis taxonomy
* **`flush_parent_attribute_terms()` is now authoritative** — at the end of Phase 3 the parent's variation taxonomy term-list is rebuilt as exactly the set of term IDs whose slugs appear in the current children's `attribute_pa_*` post meta. Stale terms from removed variants are dropped (previously merged in indefinitely); new terms from added/updated variants are picked up. Replaces the previous merge-on-merge pattern with a single derived-from-children compute

## [3.4.0]

* **Live sync log viewer** — the Debug page now tails the current sync log file with 2-second polling, auto-scroll, pause/clear controls, and a live-updating line counter. When no sync is running, the most recent log is shown
* **"View live log" anchor link** on the in-progress sync banner jumps straight to the live viewer, making it easy to watch a running sync in real time
* **Active log tracking** — `Skwirrel_WC_Sync_Logger` now records the active log filename in the `skwirrel_wc_sync_active_log` option so the viewer can find the current log across page refreshes and separate requests
* **Dashboard Debug block** relabelled to surface the live log viewer alongside the existing ETIM variation attribute troubleshooting
* **CI actions bumped to Node 24** — `actions/checkout` v4 → v5, `actions/cache` v4 → v5, `actions/upload-artifact` v4 → v5, `actions/github-script` v7 → v8 (resolves GitHub Node 20 deprecation warning)

## [3.3.0]

* **Log viewer performance** — log modal now renders progressively in batches of 200 lines via `requestAnimationFrame`, eliminating UI freezes on large logs
* **Download button** — new download button in the log modal header for direct raw log file download
* **Chunked server loading** — log viewer loads 100 KB at a time with a "Load more" button for the remainder, reducing initial payload
* **Progress indicator** — shows line count in the modal header during log display
* **API Response meta box for grouped products** — the Skwirrel API Response meta box now appears on variable/grouped product edit pages, showing the grouped product response in a collapsible section with JSON syntax highlighting
* **Lazy-loaded variation responses** — a "Load variation API responses" button fetches and displays each variation's stored API data via AJAX, with individual collapsible sections per variation
* **Single grouped product sync** — the "Sync this product" button now works for grouped products, syncing the variable product shell and all its member variations in one operation
* **Grouped product ID on variations** — each variation now stores `_skwirrel_grouped_product_id` meta for direct group membership lookup
* **Price fix** — `prices_managed_outside_skwirrel` now also protects simple product prices; previously only variation prices were guarded
* **Quick-scroll link** — Skwirrel sidebar meta box includes an anchor link to the API response section at the bottom of the page

## [3.2.2]

* **Prices managed outside Skwirrel** — new setting (Advanced section, default off) for installations where product prices are synced from a separate system (e.g. an ERP). When enabled, the PIM sync no longer overwrites existing variation prices with `0` if the PIM payload contains no trade-item price; the existing price (set by the external system) is preserved. The `price_on_request` flag is still honoured when present in the PIM payload. Simple-product paths are unaffected — they already only update prices when one is provided.

## [3.2.1]

* **Single-variant groups as simple products** — grouped products with only 1 variant are now synced as simple products instead of variable products with a single variation
* **Custom features as variation axes** — grouped products can now use custom features (from `getGroupedProducts` `include_custom_features`) to define variation attributes, alongside ETIM features
* **Custom feature matching by ID** — variation attributes are matched by `custom_feature_id` (as per API schema), with labels resolved from product-level translations in Phase 3
* **Attribute label auto-update** — `maybe_update_attribute_label()` now also replaces numeric IDs and `cc_` prefixed labels with proper translated names
* **No duplicate attributes** — custom feature variation-axis attributes are correctly excluded from regular product attributes to prevent duplication
* **Phase 3 custom class fetch** — `include_custom_classes` is now included in the Phase 3 per-product attribute fetch when grouped products are enabled, ensuring custom feature values are available for variation attribute assignment
* Fix PHPDoc issues in `apply_virtual_product_content()` (parameter names, type hints)

## [3.2.0]

* **Custom class collection ID** — new required setting that passes `include_custom_collection_id` to the API when fetching products
* **Custom classes in bulk fetch** — custom class data is now included in the main product fetch instead of a separate Phase 3 call
* **Text features as attributes** — custom class type T (text) features are now stored as visible product attributes instead of hidden meta
* **GTIN / Variant visibility** — new checkboxes to show or hide GTIN and Variant as product attributes (default: hidden)
* Sync aborts with a clear error when custom class collection ID is not configured

## [3.0.0]

* **Virtual product content** — variable products now inherit name, descriptions, categories, and brands from their virtual product (when available), replacing the raw grouped product code
* New setting: "Use virtual product content for variable products" checkbox under Sync Options
* New filter `skwirrel_wc_sync_before_virtual_content` for granular control over virtual product content application
* **Variation slugs** — each variation gets a deterministic URL slug generated from its attribute values during sync (e.g. `blue-large`)
* **Variation permalinks** — optional clean URLs: `/product/{product-slug}/{variation-slug}/` with automatic variation pre-selection
* New permalink setting: "Enable clean variation URLs" on Settings > Permalinks
* **Enhanced Skwirrel meta box** — variable products now show child variation links, virtual product ID, and variations show a link back to the parent product
* **Theme API** — new helper functions for theme developers: `skwirrel_get_variation_url()`, `skwirrel_get_default_variation()`, `skwirrel_get_variation_thumbnail()`, `skwirrel_get_all_variations_with_urls()`, `skwirrel_is_skwirrel_product()`
* New `snippets/` directory with example theme code for showing variation cards on archive pages

## [2.6.2]

* Fix "This product is not managed by Skwirrel" false positive on variable products and products without `external_product_id`
* Both `is_skwirrel_product()` and the Skwirrel meta box now also check `_skwirrel_product_id` and `_skwirrel_grouped_product_id`

## [2.6.1]

* Fix related products sync — use correct API flag `include_related_products` (was `include_product_relations`)
* Smart relation type mapping: UPSELL/SUCCESSOR → WC upsells, all others → WC cross-sells (auto mode)
* New "Auto (use relation type)" default option respects Skwirrel's CROSS_SELL, UPSELL, HAS_ACCESSORY, etc. types
* Override modes: force all relations to cross-sells, upsells, or both

## [2.6.0]

* **Related products sync** — new Phase 5 "Relations" syncs Skwirrel related products to WooCommerce cross-sells, upsells, or both
* New settings: "Sync related products" checkbox and "Related products mapping" dropdown (Cross-sells / Upsells / Both)
* Batch lookup for resolving Skwirrel IDs to WooCommerce product IDs for efficient relation assignment
* Unresolved relations are stored in `_skwirrel_pending_relations` meta and retried on the next sync
* Variations automatically assign relations to their parent variable product

## [2.5.0]

* **Variant label setting** — new "Variant label" dropdown in settings to choose which field is shown in the frontend variant dropdown when no ETIM variation axes are available: SKU (default), ERP description, or product name
* **Custom class attribute visibility filter** — new "Attribute visibility filter" setting to control which custom class attributes are visible on the product page (whitelist/blacklist by class ID or code)

## [2.4.4]

* Fix "Stop sync" button — abort check now runs every 25 products within each phase instead of only between phases
* Heartbeat refresh during abort checks to keep the UI sync-in-progress indicator alive
* Fix failed sync status card — show the failed sync timestamp and error message instead of the last successful sync time; display "Last successful sync" as a secondary line

## [2.4.3]

* Flush WordPress object cache after every product in all processing phases (1–4) to prevent WooCommerce product objects from accumulating in memory

## [2.4.2]

* Flush WordPress object cache (`wp_cache_flush()`) between all sync phases to free memory from accumulated term/meta lookups
* Log memory usage at sync start and after pre-sync to diagnose OOM issues

## [2.4.1]

* Fix OOM in grouped products pre-sync — flush wpdb memory after each API page in `sync_grouped_products_first()` and `fetch_product_ids_for_selection()`

## [2.4.0]

* **Deferred attribute fetch** — ETIM and custom class data is no longer included in the main product fetch; instead it is fetched per-product during the attribute phase to drastically reduce memory usage during sync

## [2.3.5]

* Aggressive memory management during sync — clear `$wpdb->queries[]` and flush after every product in all phases to prevent OOM from accumulated query log

## [2.3.4]

* Fix OOM during fetch phase — flush wpdb query log between batches to prevent memory accumulation from LONGTEXT INSERT queries
* Change default batch size from 100 to 10 (matches admin settings default)

## [2.3.3]

* Fix OOM during fetch phase — cap API batch size to 25 products per page and flush wpdb query log between batches to prevent memory accumulation

## [2.3.2]

* Fix unexpected output during plugin activation — use dbDelta-compatible SQL format for sync queue table

## [2.3.1]

* Use WordPress timezone for log filenames instead of UTC

## [2.3.0]

* **Database-backed sync queue** — product data is now stored in a temporary database table during sync instead of PHP memory, reducing memory usage from O(n) to O(1) regardless of product count
* Products are processed one at a time per phase via cursor pattern, preventing OOM crashes on servers with low memory limits (e.g. 128MB)
* Queue table is automatically created on plugin activation and cleaned up after each sync run

## [2.2.9]

* Convert existing simple products to variations when a grouped product sync encounters a duplicate SKU (trashes old simple, creates variation)
* Reduce memory usage during phased sync by freeing heavy product data after each phase completes

## [2.2.8]

* Simplify per-product category assignment — look up Skwirrel category IDs directly in the resolved map from tree sync instead of re-extracting and recursively resolving the category hierarchy per product

## [2.2.7]

* Add "Stop sync" button to progress banner — allows aborting a running sync from the dashboard
* Log timestamps now respect the WordPress timezone setting (uses `wp_date()` instead of `gmdate()`)

## [2.2.6]

* Add `include_contexts` to getCategories API call (required by V2 API for translations)
* Improved category sync diagnostics: log full request params, response structure, and categories without names

## [2.2.5]

* Fix approved download directory: also enable existing disabled directories (WooCommerce could add the `/uploads` directory but leave it disabled)

## [2.2.4]

* **Fix category sync** — use correct API parameter `super_category_id` instead of non-existent `category_id`
* Category tree sync now fetches all categories under a super category in one API call (no more recursion)
* Fix per-product category assignment when API returns ID-only `_categories` (no names) — match by Skwirrel ID against existing WC terms
* Support V1 API `_translation` format (keyed by language) in addition to V2 `_category_translations` (array)

## [2.2.3]

* Dark terminal-style log viewer with syntax highlighting for log levels (INFO=blue, WARNING=yellow, ERROR=red)
* JSON objects in log lines highlighted in cyan
* Sync separator lines styled with subtle dividers
* Modal title shows the log filename
* Truncation notice styled as warning banner

## [2.2.2]

* Raise PHP memory limit at sync start via `wp_raise_memory_limit('admin')` to prevent OOM crashes on large API responses
* Register shutdown handler to detect fatal errors (OOM) during sync and record them as failed sync results
* Fixes silent sync failures where a crash left the dashboard showing the previous successful result with no error record

## [2.2.1]

* Separate "Sync Logs" settings section with per-trigger log mode (one file per sync or per day)
* Manual and scheduled syncs each have their own log mode setting
* Add "Manual (no auto-delete)" option to log retention
* Fix super category ID field width to match selection IDs field

## [2.2.0]

* Per-sync log files — each sync run writes to its own log file for easy debugging
* Manual syncs get a unique log file; scheduled syncs share a daily log file (appended)
* Log viewer modal in sync history — click "View" to read log contents inline
* Configurable log retention setting (12 hours, 1 day, 2 days, 7 days, 30 days)
* Auto-cleanup of old log files at the start of each sync run
* Log files are cleaned up when history entries are deleted

## [2.1.5]

* Recursively fetch full category tree from API — all depth levels are now synced, not just direct children of the super category

## [2.1.4]

* Auto-register WP uploads directory as WooCommerce approved download directory during sync
* Fixes "downloadable file not in approved folder" errors for imported PDFs (WC 6.5+)

## [2.1.3]

* Store Skwirrel API response for all product types: `upsert_product_as_variation`, `create_variable_product_from_group`, and `create_or_update_variation` now also save `_skwirrel_api_response`

## [2.1.2]

* Fix grouped products ignoring dynamic selection ID — post-filter groups against selection product list
* Fetch allowed product IDs from selection before processing groups, skip groups with no matching members
* Remove unused `get_collection_ids()` from upserter (now passed as parameter from sync service)

## [2.1.1]

* Fix grouped products ignoring selection ID filter — pass `dynamic_selection_id` to `getGroupedProducts`
* Store raw Skwirrel API response as `_skwirrel_api_response` post meta during sync
* Add dedicated "Skwirrel API Response" meta box on the product edit screen showing the stored JSON

## [2.1.0]

* Selection ID is now required — sync aborts if no selection ID is configured
* Add "Show API response" button in the Skwirrel product meta box to view raw JSON from the API
* Reduce batch size maximum from 500 to 50, default from 100 to 10
* Fix translation: selection ID hint incorrectly said "category IDs" in all locales (nl, en, fr, de)

## [2.0.8]

* Add raw API response logging for `getCategories` and per-product `_categories` data (verbose mode)

## [2.0.7]

* Fix category tree sync failing when API returns single root category object instead of array
* Categories from `getCategories` are now correctly extracted from root `_children` when super category ID is used

## [2.0.6]

* Add diagnostic logging for category-to-product assignment to trace resolution failures
* Log each category resolve step (meta lookup, name fallback, creation) when verbose logging is enabled
* Warn when categories are extracted from API but no WooCommerce term IDs could be resolved
* Check and log `wp_set_object_terms` errors instead of silently ignoring failures

## [2.0.5]

* Update README and plugin description to reflect current feature set
* Replace "ERP/PIM" references with "PIM" throughout
* Update WooCommerce minimum to 8.0 (9.6+ recommended for native brand support)
* Update WooCommerce tested up to 10.6
* Update WordPress tested up to 6.9.4

## [2.0.4]

* Inline "Update on re-sync" toggle in Permalinks section — saves instantly via AJAX with ✓ indicator
* Slug change warning only shown when "Update on re-sync" is enabled and settings have actually changed
* Persistent hint below the toggle when enabled: warns about URL overwrite and SEO impact
* Add batch size field hint: "Products per API request (1–500)."

## [2.0.3]

* Add Permalinks section in Settings showing current slug configuration with link to Settings → Permalinks
* Show warning banner when slug settings change — advises a full resync and warns about potential link breakage
* Warning auto-clears after a successful sync completes
* Add Selection IDs hint link to Skwirrel selections page with dynamic subdomain URL

## [2.0.2]

* Add GTIN / Manufacturer product code search filter on the WooCommerce product list page
* Store `_product_gtin` and `_manufacturer_product_code` as dedicated product meta during sync
* Add subtitles to Debug and Danger Zone dashboard blocks

## [2.0.1]

* Rename "Collection IDs" to "Selection IDs"
* Add API token creation link with dynamic subdomain URL
* Add category finder link on Super category ID field
* Move WordPress admin notices below the Skwirrel header

## [2.0.0]

* New admin dashboard with block-grid layout replacing the tab-based UI
* Sync progress banner with 6-phase checklist and live counters
* Date-grouped sync history table (Today, Yesterday, day name, or date)
* Settings page redesigned with grouped fieldsets and Tailwind-inspired styling
* Simplified API connection: subdomain-only input with visual prefix/suffix
* Remove auth type selector (always uses static token)
* Sync Logs block links directly to WooCommerce logs
* Debug and Danger Zone inline in the dashboard grid
* Full translation update for all 7 locales (nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)

## [1.10.1]

* Add `Domain Path: /languages` header for automatic translation loading on WordPress 6.7+
* Add `load_plugin_textdomain()` fallback for older WordPress versions
* Add `nl.mo`/`nl.po` locale files for sites using `nl` instead of `nl_NL`
* Fix Danger Zone purge not removing all product attribute taxonomies — now cleans up all orphaned `pa_*` attributes, not just `etim_*` and `skwirrel_variant`

## [1.10.0]

* Phased sync architecture — sync now runs in 5 sequential phases (fetch, products, taxonomy, attributes, media) instead of processing everything per product in one pass
* Live progress checklist on the sync tab — shows current phase with status icon and counter (e.g. "247 / 500"), refreshes every 5 seconds
* Performance fix: restore `getProducts` API call for full sync — faster than `getProductsByFilter` with empty filter (regression from 1.9.9)
* Auto-refresh scoped to the sync tab only — no longer reloads settings or logs pages

## [1.9.9]

* Fix Danger Zone purge silently timing out on large datasets — add set_time_limit(0) to prevent PHP timeout
* Rewrite Danger Zone purge to use bulk SQL instead of per-item wp_delete_post/wp_delete_attachment calls — orders of magnitude faster on large stores

## [1.9.8]

* Add "Skwirrel" meta box on the WooCommerce product edit screen (above Publish) — shows Skwirrel product ID, last sync time, and a "Sync this product" button for quick single-product sync via the API

## [1.9.7]

* Add configurable "Product manufacturer base" slug on Settings → Permalinks page — allows customizing the URL base for the product_manufacturer taxonomy (like WooCommerce's brand base field)

## [1.9.6]

* Fix product sync failing when downloadable files are not in WooCommerce's approved directory — downloads/documents errors are now caught and logged as warnings, so category, brand and manufacturer assignment always proceeds

## [1.9.5]

* Brand sync is now always active — uses WooCommerce native product_brand taxonomy (available since WC 9.4)
* Add "Sync manufacturers" setting: registers product_manufacturer taxonomy, syncs manufacturer_name from Skwirrel products
* Manufacturer attribute no longer duplicated as product attribute when synced as taxonomy
* Default product list columns: hide Tags, show Manufacturers
* Add "Filter by manufacturer" dropdown on product list page
* Manufacturers column ordered after Brands, before Date

## [1.9.3]

* Fix variable product variation attributes: recover parent attribute options from child variation post meta when deferred terms are empty (e.g. when getProducts lacks _etim_features)
* Convert non-variation parent attributes to global WooCommerce taxonomy-based attributes (consistent with simple products)
* Fix brand not assigned to variable products: propagate brand_name from child variations to parent, since getGroupedProducts response lacks brand_name
* Fix categories not assigned to variable products: propagate _categories from child variations to parent (same root cause)

## [1.9.2]

* Remove legacy pa_variant migration code (no live installs to migrate)
* Fix simple product attributes: save as global WooCommerce taxonomy-based attributes instead of custom text attributes, so they appear in layered navigation and product filters

## [1.9.1]

* Remove legacy pre-1.8.0 Action Scheduler cleanup code (old slug reference)

## [1.9.0]

* Move remaining inline event handlers (onchange, onclick) to enqueued inline script for WordPress.org compliance
* Fix stale debug log path in admin help text (now correctly points to uploads/skwirrel-pim-sync/ subfolder)

## [1.8.4]

* Add non-variation ETIM and custom class attributes to parent variable products during sync
* Variation product attributes (from getProducts) are now automatically collected and merged onto the parent as visible, non-variation attributes

## [1.8.3]

* Fix empty variation attribute dropdowns on variable products by deferring parent attribute term updates to a single batch flush after all variations are processed

## [1.8.2]

* Replace all inline `<script>` and `<style>` tags with proper `wp_enqueue_script`/`wp_add_inline_script`/`wp_add_inline_style` calls
* Rename plugin display name to "Skwirrel PIM sync for WooCommerce"

## [1.8.1]

* Fix variation attribute labels showing raw ETIM codes (e.g. "EF002671") instead of human-readable names
* Add missing `include_etim_translations` and `include_languages` to `getGroupedProducts` API call

## [1.8.0]

* Rename plugin slug from `skwirrel-pim-wp-sync` to `skwirrel-pim-sync` (WordPress.org restricts "wp" in plugin slugs)
* Update text domain, Action Scheduler group, logger source, and admin page slug
* Rename main plugin file and all language files to match new slug
* Add activation cleanup for old Action Scheduler group from pre-1.8.0
* Existing settings, synced products, and translations are fully preserved

## [1.7.1]

* Remove deprecated `load_plugin_textdomain()` call (WordPress 4.6+ auto-loads translations)
* Fix unescaped SQL parameters in purge handler: use `$wpdb->prepare()` with placeholders
* Fix direct database query caching warning in taxonomy manager
* WordPress Plugin Check compliance improvements

## [1.7.0]

* Slug settings moved to Settings → Permalinks page (alongside WooCommerce product permalinks)
* New "Update slug on re-sync" option: when enabled, existing product slugs are updated during sync (not just new products)
* Slug resolver: exclude current product ID from duplicate check when updating existing products
* New class: Permalink_Settings — dedicated settings on the WordPress Permalinks page
* Backward compatible: migrates existing slug settings from plugin settings to new permalink option
* Sync history: new "Trigger" column showing Manual, Scheduled, or Purge
* Purge (delete all) now adds an entry to sync history with purge details
* Purge no longer clears the last sync status — previous sync results remain visible
* Purge rows highlighted in yellow in history table
* New dedicated option: `skwirrel_wc_sync_permalinks` for slug configuration
* Updated translation files (POT + nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)
* New unit tests: SlugResolverTest (16 tests covering all source fields, groups, backward compat)

## [1.6.0]

* Product slug configuration: choose slug source field (product name, SKU, manufacturer code, external ID, Skwirrel ID)
* Slug suffix on duplicate: configurable fallback field appended when slug already exists
* New class: Slug_Resolver — resolves product URL slugs based on admin settings
* Slugs only set for new products to preserve existing URLs
* Updated translation files with slug-related strings

## [1.5.0]

* Major refactoring: SyncService split from ~2200 lines into focused sub-classes
* New class: ProductUpserter — all product creation/update logic
* New class: ProductLookup — database lookup methods for Skwirrel meta
* New class: SyncHistory — sync result persistence and heartbeat management
* New class: PurgeHandler — stale product/category cleanup
* New class: CategorySync — category tree sync and per-product assignment
* New class: BrandSync — brand taxonomy sync
* New class: TaxonomyManager — ETIM and custom class taxonomy management
* New class: EtimExtractor — ETIM attribute parsing from ProductMapper
* New class: CustomClassExtractor — custom class feature handling from ProductMapper
* New class: AttachmentHandler — image/document import from ProductMapper
* SyncService reduced to ~480 lines (pure orchestrator)
* ProductMapper reduced to ~460 lines (delegates to focused sub-classes)
* All existing public APIs preserved — no breaking changes

## [1.4.0]

* Brand sync: Skwirrel brands synced into WooCommerce product_brand taxonomy
* Category tree sync: sync full category tree from a configurable super category ID
* Sync progress indicator: spinning icon on menu item, blue status bar with auto-refresh
* Sync button disabled while sync is in progress
* Heartbeat mechanism: sync status auto-expires after 60s without activity
* Purge: danger zone now also deletes product brands
* Settings save clears sync-in-progress state
* i18n: all UI strings switched to English source text
* Updated translation files (POT + nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)

## [1.3.2]

* i18n: all UI strings switched to English source text
* Updated translation files (POT + nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)
* Added new translation entries for tabbed UI, custom classes, danger zone and delete protection
* Recompiled all .mo binary translation files

## [1.3.1]

* Deep category tree sync: full ancestor chain from nested _parent_category (unlimited depth)
* Custom Class sync: product-level and trade-item-level custom classes as WooCommerce attributes
* Custom Class feature types: A (alphanumeric), M (multi), L (logical), N (numeric), R (range), D (date), I (internationalized)
* Custom Class text types T and B stored as product meta (_skwirrel_cc_* prefix)
* Whitelist/blacklist filtering on custom class ID or code
* New settings: sync_custom_classes, sync_trade_item_custom_classes, custom_class_filter_mode, custom_class_filter_ids

## [1.3.0]

* Admin UI: tabbed layout (Sync Products, Instellingen, Logs)
* Sync status and history now shown on the default Sync Products tab
* Sync button moved to page title, visible on all tabs
* Logs and variation debug instructions on dedicated Logs tab
* Fixed GitHub release workflow: version is read from plugin file, no more auto-incrementing

## [1.2.3]

* WordPress Plugin Check compliance: translators comments, ordered placeholders, escape output
* WordPress Plugin Check compliance: phpcs:ignore for direct DB queries, non-prefixed WooCommerce globals, nonce verification
* Use WordPress alternative functions (wp_parse_url, wp_delete_file, wp_is_writable)
* Translate readme.txt to English

## [1.2.2]

* Version bump in preparation for release

## [1.2.1]

* Update text domain and constants for Skwirrel PIM Sync rebranding

## [1.2.0]

* Rebranded to Skwirrel PIM Sync
* Added unit tests for MediaImporter, ProductMapper, and related components
* Added WordPress.org auto-deploy workflow
* Added automated versioning, tagging, and release workflow

## [1.1.2]

* Version bump
* Fix duplicate products during sync: 3-step lookup chain + SKU conflict prevention

## [1.1.1]

* Delete protection: warning banners on Skwirrel-managed products and categories
* Purge stale products and categories after full sync
* Category sync with parent-child hierarchy support
* Collection ID filter for selective synchronisation
* Translation files (POT + nl_NL, nl_BE, en_US, en_GB, de_DE, fr_FR, fr_BE)
* New settings: purge_stale_products, show_delete_warning, collection_ids, sync_categories, include_languages, image_language
* PHPStan, PHP_CodeSniffer, and Pest PHP test framework
* WooCommerce 10.5 compatibility

## [1.0.0]

* Initial release
* Full product synchronisation
* Variable products with ETIM variation axes
* Image and document import
* Delta synchronisation support
