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

- **Catalogue regeneration recipe (verified 2026-08-27):** no local wp-cli; use the wp-env container — `npx wp-env run cli --env-cwd=wp-content/plugins/skwirrel-pim-sync wp i18n make-pot . languages/skwirrel-pim-sync.pot --slug=skwirrel-pim-sync --domain=skwirrel-pim-sync --exclude=vendor,node_modules,tests`, then `msgmerge --update --backup=none --no-fuzzy-matching` per locale, then translate, then `msgcat --width=79` to restore gettext wrapping (polib wraps *before* the space and reflows the whole file), then `msgfmt` **last** — `AdminSettingsRequiredFieldsTest` asserts .mo mtime >= .po mtime.
- **en_GB and en_US are byte-identical mirrors of the English source** by convention here — msgstr == msgid. Fill them; don't leave them empty.
- **Catalogue drift is the recurring i18n defect here, not wrong text domains.** Every string checked so far uses `skwirrel-pim-sync` correctly; what goes stale is the POT. Regenerate it as part of any story that adds admin strings, not at release time.

## Decisions
- Prices: one client runs a separate ERP price sync. The PIM sync must never zero out a missing price.
- WP 7.0+ is the primary development target; 6.9 is the backward-compat floor. Prefer the Connectors API.

## Verified API Facts
- **Upstream checked 2026-08-18:** WordPress 7.0.4, WooCommerce 11.0.1. Next run starts here.
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
- **WordPress 7.1** — the WC 11.0.1 changelog carries order-list fixes explicitly for WP 7.1 compatibility, so 7.1 is close. Next real upstream event.

## Open Questions
- CHANGELOG.md / readme.txt `= 3.14.0 =` document Epic 5 only; Epic 6 (stories 6.1-6.5, committed on release/3.14.0) has no entry. Raised 2026-08-27, not actioned.
- Plugin-header metadata (Description, Author, Plugin URI, Author URI) is untranslated in all 7 locales. Deliberate or an oversight?
- Does the plugin ship to clients as well as WordPress.org, or .org only?
- Are the untracked `.dist` configs inside `plugin/skwirrel-pim-sync/` deliberate, or strays that shouldn't ship?
- Does anything actually exercise WC 11 in a test environment, or is "tested up to" a paper claim here?
