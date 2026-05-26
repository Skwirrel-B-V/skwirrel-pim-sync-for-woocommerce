# Changelog — Skwirrel PIM sync for WooCommerce

All notable changes to Skwirrel PIM sync for WooCommerce will be documented in this file.

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
