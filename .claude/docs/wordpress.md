# WordPress / wp-env — Local Development

Quick reference for the local WordPress environment used to develop and
integration-test this plugin.

## Project layout

The real plugin project root is `skwirrel-pim-sync/`, not the repo root. All
wp-env / Composer / npm commands must be run from there.

```bash
cd skwirrel-pim-sync
```

## Starting the environment

```bash
npm run env:start       # boot WordPress + WooCommerce in Docker
npm run env:stop        # stop containers (keeps DB)
npm run env:clean       # drop both dev and tests DBs
npm run env:destroy     # nuke containers, volumes, everything
```

`.wp-env.json` provisions:

- WordPress (pinned in `core`)
- PHP 8.1
- WooCommerce (latest from wp.org)
- Plugin Check (latest from wp.org)
- This plugin (mounted from `.`)

The plugin list is ordered so WooCommerce is installed and activated **before**
this plugin — otherwise the Skwirrel plugin's `WC requires WooCommerce` guard
aborts activation.

## Admin login

Default `@wordpress/env` credentials (same for every wp-env project):

| | URL | User | Password |
|---|---|---|---|
| Dev site | http://localhost:8888/wp-admin | `admin` | `password` |
| Tests site | http://localhost:8889/wp-admin | `admin` | `password` |

- `:8888` is the dev instance — use this for manual QA and clicking through
  the admin.
- `:8889` is the tests instance — a separate DB used by integration tests.
  Safe to wipe with `npm run env:clean` without touching the dev site.

If a port is already in use, override `port` / `testsPort` in `.wp-env.json`.

## Running commands inside the container

```bash
# WP-CLI against the dev instance
npx wp-env run cli wp user list
npx wp-env run cli wp plugin list
npx wp-env run cli wp option get skwirrel_wc_sync_settings

# WP-CLI against the tests instance
npx wp-env run tests-cli wp user list

# Composer inside the tests container (needed before test:integration)
npm run composer:install
```

## Gotchas

- **PHP version**: `.wp-env.json` pins PHP 8.1, which is also the plugin's
  declared minimum. Do **not** use PHP 8.2+ syntax (readonly classes, DNF
  types, typed class constants, etc.) — it will parse-error on boot.
- **File changes are live**: the plugin directory is bind-mounted, so edits
  apply immediately. You only need `env:start` again if `.wp-env.json` or
  plugin headers changed.
- **WooCommerce textdomain notice**: on a fresh boot you may see
  `_load_textdomain_just_in_time was called incorrectly` for the `woocommerce`
  domain. This is a known WooCommerce quirk under WP 6.7+ and is harmless.
- **Loopback requests**: the dashboard "Sync now" button fires a loopback
  HTTP request. wp-env allows this by default; if it ever stops working,
  check that `WP_HOME` / `WP_SITEURL` match the actual port.
