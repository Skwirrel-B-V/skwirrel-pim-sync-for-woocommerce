# Memory

_Curated long-term knowledge. Structured so a cold start is immediately useful._

## Plugins

### skwirrel-pim-sync — "Skwirrel PIM sync for WooCommerce"
- **Repo layout:** dev workspace at root; shippable plugin at `plugin/skwirrel-pim-sync/`. Tooling (composer, phpstan, phpcs, pest, wp-env) lives at the repo root, not in the plugin.
- **What it does:** syncs products from the Skwirrel PIM into WooCommerce over JSON-RPC 2.0.
- **Architecture:** singletons + manual `require_once`, no autoloader. 33 files in `includes/`, all `class-skwirrel-wc-sync-{slug}.php` (WPCS filename rule). 1.7M total.
- **Floors (3.12.2):** WP >= 6.9, PHP >= 8.3, WC >= 8.0. Tested to WP 7.0 (readme), WC 10.6 (bootstrap header).
- **Note:** the bootstrap declares no `Tested up to` of its own — only readme.txt carries it. Worth aligning.
- **Ships to:** WordPress.org via SVN, on tag push (`.github/workflows/deploy.yml`).
- **Gates:** `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G` (level 6 + baseline), `vendor/bin/phpcs`. All three before every commit. Integration suite needs Docker/wp-env.
- **Release:** version in plugin header + `SKWIRREL_WC_SYNC_VERSION` + readme `Stable tag` + package.json; changelog in both CHANGELOG.md and readme.txt (deploy fails without `= X.Y.Z =`). Tag `X.Y.Z`, no `v`.
- **Release trap (documented):** the `wordpress-org` environment's allowed-tag pattern is **fnmatch, not regex**. `[0-9]+.[0-9]+.[0-9]+` silently matches nothing and blocks every deploy.
- **Integration suite reality (verified 2026-08-19):** `tests/Pest.php`'s `uses(WP_UnitTestCase::class)->in('Integration')` binding does NOT take effect — integration tests run as plain `PHPUnit\Framework\TestCase`, so there are **no DB transactions**; `tests/Integration/README.md` claims there are and is wrong. Hence the manual purge helpers in `tests/Integration/bootstrap.php`. wp-env pins WP 7.0 + WC 10.8.
- **Admin-menu testing recipe:** `wp-admin/menu.php` (+ `wp-admin/includes/menu.php`) can only be loaded once per PHP process (function declarations), and must be required with the menu globals imported via `global`. Snapshot core's baseline from an `admin_menu` callback at `-PHP_INT_MAX`, then restore + re-fire per scenario. Rendered top-level order ≠ raw `$menu` keys: WooCommerce opts into `custom_menu_order` and rewrites the list. See `tests/Integration/AdminMenuIntegrationTest.php`.
- **State at 2026-08-18:** version 3.12.2, fully consistent across all five locations.
- **State at 2026-09-08:** on `release/3.14.0` (untagged, still open). Built the stuck-sync warning
  + health check + Debug checklist on branch `feature/stuck-sync-warning` off it — all gates green,
  not committed (build only). Version correctly stayed 3.14.0 per `release-consistency.py`.

- **Catalogue regeneration recipe (verified 2026-08-27):** no local wp-cli; use the wp-env container — `npx wp-env run cli --env-cwd=wp-content/plugins/skwirrel-pim-sync wp i18n make-pot . languages/skwirrel-pim-sync.pot --slug=skwirrel-pim-sync --domain=skwirrel-pim-sync --exclude=vendor,node_modules,tests`, then `msgmerge --update --backup=none --no-fuzzy-matching` per locale, then translate, then `msgcat --width=79` to restore gettext wrapping (polib wraps *before* the space and reflows the whole file), then `msgfmt` **last** — `AdminSettingsRequiredFieldsTest` asserts .mo mtime >= .po mtime.
- **en_GB and en_US are byte-identical mirrors of the English source** by convention here — msgstr == msgid. Fill them; don't leave them empty.
- **The i18n gate does NOT catch new strings.** There is no global POT-coverage test. Instead the house convention is **one hand-curated POT-coverage test per story that adds strings** — `AdminSettingsRequiredFieldsTest.php:244`, `TestConnectionMetricsTest.php:450`, `FieldMappingTranslationsTest.php:102`. Plus `AdminSettingsRequiredFieldsTest.php:274` asserting `.mo` mtime >= `.po` mtime. A string added without its own test passes silently. So any story adding admin strings needs BOTH an explicit POT-regeneration AC and its own coverage test.
- **Untranslated backlog (measured 2026-08-27):** empty `msgstr` counts per locale — de_DE 80, fr_BE 81, fr_FR 81, nl_BE 72, nl_NL 72, en_GB 62, en_US 62 (each includes the header line). The strings *exist* in every catalogue, so the per-story coverage tests all pass while ~60-80 strings per locale ship untranslated. **This contradicts the en_GB/en_US mirror convention below** — those two should be msgstr == msgid and 62 of them are blank. Worth a cleanup pass.
- **House pattern for admin-triggered HTTP fan-out (verified 2026-08-27):** never hand the saved `timeout`/`retries` to a client running inside an admin request. `Admin_Settings::fetch_statuses()` (`:1100-1119`) clamps to `STATUS_SCAN_TIMEOUT = 10` / `STATUS_SCAN_RETRIES = 1` and chunks against a `STATUS_SCAN_BUDGET`, with a comment spelling out why (120s x 6 attempts). `JsonRpc_Client::call()` has retries+1 attempts, blocking `usleep(500000 * $attempt)` backoff, and **no total elapsed budget** — so a call-count cap bounds nothing. Bound admin work by wall clock, not by call count.
- **Catalogue drift is the recurring i18n defect here, not wrong text domains.** Every string checked so far uses `skwirrel-pim-sync` correctly; what goes stale is the POT. Regenerate it as part of any story that adds admin strings, not at release time.

## Decisions
- Prices: one client runs a separate ERP price sync. The PIM sync must never zero out a missing price.
- WP 7.0+ is the primary development target; 6.9 is the backward-compat floor. Prefer the Connectors API.

## Architecture correction (verified 2026-09-08)
- **`.claude/rules/sync-service.md` is stale.** It describes a synchronous `run_sync()` flow. The
  actual current build is a **resumable Action Scheduler state machine**: `begin_run()` logs
  "Sync started", saves run state, and hands off to a scheduled `skwirrel_wc_sync_step` action
  (group `skwirrel-pim-sync`) — `run_async_step()` executes one bounded step (`step_init`,
  `step_fetch`, …) per action firing, then re-enqueues itself until `done`/`failed`. A synchronous
  driver (`run_sync()` looping `run_step()` in-process) still exists for CLI/no-Action-Scheduler
  environments, but is not the path a normal admin-triggered or scheduled sync takes. Symptom of
  not knowing this: a sync log that stops right after "Sync started" looks broken, but that line
  is genuinely the last thing the *triggering* request logs — the real work happens in a
  separate request. Worth correcting the rule file properly sometime; flagging here so I don't
  re-diagnose the same confusion next time.
- **Heartbeat/stall mechanics**: `HEARTBEAT_TTL = 60s` (`Skwirrel_WC_Sync_History`), `MAX_STALL = 6`
  and `RUN_STATE_ACTIVE_TTL = 900s` (`Skwirrel_WC_Sync_Service`). `MAX_STALL` only protects a run
  whose steps *are* executing but making no progress — a run whose first step never gets picked up
  by Action Scheduler at all never reaches that guard, since nothing inside `run_async_step()` ever
  runs to detect it. See `get_stuck_run_warning()` (added 2026-09-08), which checks independently
  from the UI layer instead.

## Verified WordPress core APIs worth reusing here
- `WP_Site_Health::get_instance()->get_test_scheduled_events()` and `->get_test_loopback_requests()`
  (`wp-admin/includes/class-wp-site-health.php`, not autoloaded — `require_once` it first) — the
  same tests Tools → Site Health runs. `get_test_loopback_requests()` → `can_perform_loopback()`
  does one live `wp_remote_post()` to the site's own `wp-cron.php`, 10s timeout. Both read-only /
  side-effect-free apart from that one HTTP call. Verified by reading the class source directly
  inside the running wp-env container, not assumed.
- **Admin URLs** (also verified in-container, not guessed): Action Scheduler's own screen is
  `tools.php?page=action-scheduler` (`ActionScheduler_AdminView::register_menu()`, under Tools).
  Site Health is `site-health.php` — a **top-level admin file, not** `tools.php?page=health-check`
  (my first guess, wrong; core registers it via `$submenu['tools.php'][20]` in `wp-admin/menu.php`).

## Verified API Facts
- **Upstream checked 2026-09-08:** WordPress 7.1 (via field guide, make.wordpress.org, 2026-08-05), WooCommerce 11.1.0 (not yet checked — see Watch List). Next run starts here.
- **WordPress 7.1 does not affect this plugin.** Checked every dev note in the field guide:
  - Iframed-editor completion (all post types now edit inside an iframe) — only affects plugins injecting JS/CSS across the editor iframe boundary. `Skwirrel_WC_Sync_Product_Sync_Meta_Box` is a plain server-rendered classic meta box with no enqueued JS crossing that boundary (metaboxes render outside the editor iframe, unaffected). Not affected.
  - Media REST API changes (image dimension validation, size-aware encode quality on `/wp/v2/media` sideload) — `class-skwirrel-wc-sync-media-importer.php` calls `wp_insert_attachment()` directly (`:123,229`), never the REST media endpoint. Not affected.
  - jQuery UI bumped to 1.14.2 — no jQuery usage anywhere in the plugin's admin JS (verified by grep; all inline JS is vanilla `fetch`/DOM). Not affected.
  - Abilities API additions, `notify_post_author` filter change, theme.json coercion fix, XML-RPC/REST multisite fixes, privacy-request cron change, comments fix — none touch code this plugin runs (no Abilities API use, no post-author-notification filtering, no theme.json, no XML-RPC, no privacy-erasure hooks, no custom comment types).
  - `readme.txt` `Tested up to` raised 7.0 → 7.1 on this basis (2026-09-08, on `feature/stuck-sync-warning`).
- **Upstream checked 2026-08-18:** WordPress 7.0.4, WooCommerce 11.0.1.
- **WooCommerce 11.0 (2026-08-04) does not affect this plugin.** All developer-facing changes ruled out with evidence:
  - Action Scheduler 4.0.0 `$unique` now includes args — plugin never passes `$unique` (5-arg calls only, `class-skwirrel-wc-sync-action-scheduler.php:58,221`). Not affected.
  - AS 4.0.0 purges failed actions after 3 months — plugin never queries action status or failed actions. Not affected.
  - `get_queried_object()` on the Shop page now returns `WP_Post` — the one call site (`class-skwirrel-wc-sync-variation-permalinks.php:91`) is guarded by `is_singular('product')` and never runs on the shop page. Not affected.
  - `product_shipping_class` now non-public — plugin never references it. Not affected.
  - Product Editor beta fully removed — plugin registers no editor blocks, slots, routes or feature flags. Not affected.
  - Product Image block `Resolution` removed / stock restored on failed orders / `ReserveStock` default 60min — all order- or block-side; plugin sets `manage_stock(false)` and touches no order hooks. Not affected.
- WooCommerce 11.0.1 requires WP >= 6.9, PHP >= 7.4. This plugin's floors (WP 6.9 / PHP 8.3) are stricter, so no conflict.
- `wp_get_connector()` (WP 7.0 Connectors API) and both brand taxonomies are `function_exists`/`taxonomy_exists` guarded. Safe across the floor.

## Unread Pulse Findings
{None — no unattended run has happened yet.}

## Deferred Deprecations
{None. Nothing upstream is currently scheduled to break this plugin.}

## Watch List
- **WooCommerce 10.6 → 11.1.0 — not yet checked.** `wc_tested_up_to` still says 10.6; upstream is now 11.1.0 (three point releases past the last check, which only covered 11.0.1). `upstream-versions.py` flags this "major" severity. Needs its own upstream-watch pass — not done as part of the 2026-09-08 WP 7.1 check.

## Open Questions
- CHANGELOG.md / readme.txt `= 3.14.0 =` document Epic 5 only; Epic 6 (stories 6.1-6.5, committed on release/3.14.0) has no entry. Raised 2026-08-27, not actioned.
- Plugin-header metadata (Description, Author, Plugin URI, Author URI) is untranslated in all 7 locales. Deliberate or an oversight?
- Does the plugin ship to clients as well as WordPress.org, or .org only?
- Are the untracked `.dist` configs inside `plugin/skwirrel-pim-sync/` deliberate, or strays that shouldn't ship?
- Does anything actually exercise WC 11 in a test environment, or is "tested up to" a paper claim here?
