# Local development

Setting up the Skwirrel PIM sync plugin for local work. Related docs: [`local-testing.md`](./local-testing.md) for unit + integration tests, [`release.md`](./release.md) for the publish flow.

## Prerequisites

- **PHP 8.1+** with standard WP extensions
- **Composer 2.x**
- **Node.js 18+** and **npm** (for wp-env)
- **Docker Desktop** (for wp-env)
- Git

All of these need to be on your `PATH` and Docker Desktop must be running before you start wp-env.

## Repository layout

```
/                                   # repo root — all dev commands run from here
├── plugin/skwirrel-pim-sync/       # the shippable plugin
│   ├── skwirrel-pim-sync.php       # bootstrap + header (Version: lives here)
│   ├── includes/                   # classes (require_once, no autoloader)
│   ├── assets/                     # admin.css, product-documents.css
│   ├── templates/                  # WC-overridable templates
│   ├── languages/                  # .pot + 7 locales
│   └── readme.txt                  # WordPress.org readme (Stable tag: here)
├── plugin/assets/                  # wp.org store assets (banners, icons)
├── tests/                          # Pest tests (Unit + Integration)
├── docs/                           # this folder
├── composer.json / vendor/         # dev deps (phpstan, phpcs, pest, WP stubs)
├── package.json / node_modules/    # wp-env scripts
├── .wp-env.json                    # WP 6.9 + WooCommerce Docker stack
├── phpunit.xml.dist                # unit suite config
├── phpunit-integration.xml.dist    # integration suite config
├── phpstan.neon.dist (+ baseline)  # static analysis
└── .phpcs.xml.dist                 # code style
```

The plugin directory has **no** `composer.json` of its own. All dev dependencies live at the repo root and are reused from there.

## First-time setup

From the repo root:

```bash
# Install PHP dev dependencies (Pest, PHPStan, PHPCS, WP/WC stubs)
composer install

# Install Node deps (wp-env)
npm install
```

After `composer install` you can run unit tests, PHPStan, and PHPCS. `npm install` is only needed for the Docker-based dev site and integration tests.

## Running WordPress locally (wp-env)

`wp-env` mounts `./plugin/skwirrel-pim-sync` into a WordPress 6.9 + WooCommerce Docker stack, so any edit to the plugin source is live on `localhost:8888` with no reload step.

```bash
npm run env:start     # boot WP + WC in Docker (first run pulls images, ~2 min)
npm run env:stop      # stop the containers (keeps DB)
npm run env:clean     # drop both DBs (dev + tests)
npm run env:destroy   # nuke volumes entirely
npm run env:logs      # tail container logs
npm run env:cli ...   # WP-CLI inside the container (e.g. npm run env:cli plugin list)
```

Two WordPress instances run side by side:

| | URL | User | Password |
|---|---|---|---|
| Dev site | http://localhost:8888/wp-admin | `admin` | `password` |
| Tests site | http://localhost:8889/wp-admin | `admin` | `password` |

The dev site (`:8888`) is what you open in the browser while coding. The tests site (`:8889`) is reset between integration test runs — don't rely on it for manual clicking.

The `.wp-env.json` provisions:

- WordPress 6.9.4
- PHP 8.1
- WooCommerce (latest from wp.org)
- Plugin Check (latest from wp.org)
- This plugin from `./plugin/skwirrel-pim-sync`
- `WP_DEBUG`, `WP_DEBUG_LOG`, `SCRIPT_DEBUG` enabled on the dev site

## Day-to-day development loop

1. Boot wp-env once: `npm run env:start`
2. Edit files under `plugin/skwirrel-pim-sync/`
3. Refresh `http://localhost:8888` — changes are live
4. Before committing, run the pre-commit checks (below)

### Configuring the plugin in the dev site

The plugin expects a Skwirrel API endpoint. On the dev site:

1. Plugins → activate **Skwirrel PIM sync for WooCommerce** (and WooCommerce if needed)
2. WooCommerce → **Skwirrel Sync** → fill in the subdomain and API token
3. Click **Test connection** → **Sync now**

Settings + token persist across `env:stop`/`env:start`. They are wiped by `env:clean` or `env:destroy`.

### Viewing logs

Plugin logs go through `wc_get_logger()` with source `skwirrel-pim-sync`. View them at:

- WooCommerce → Status → Logs → `skwirrel-pim-sync-YYYY-MM-DD-*.log`
- or directly in the admin dashboard under **Sync Logs**

For PHP errors, `WP_DEBUG_LOG` writes to `wp-content/debug.log` inside the container — reach it with `npm run env:logs` or browse to the container's volume.

## Code quality

All three must pass before every commit. CI (`.github/workflows/ci.yml`) re-runs them on push to `main` and on pull requests.

```bash
vendor/bin/pest              # Unit tests (stub bootstrap, no Docker)
vendor/bin/phpstan analyse   # Static analysis, level 6 (baseline: phpstan-baseline.neon)
vendor/bin/phpcs             # WordPress coding standards (config: .phpcs.xml.dist)

vendor/bin/phpcbf            # Auto-fix code style issues
```

See [`local-testing.md`](./local-testing.md) for the full testing guide including integration tests.

There is also a slash command `/quality` in `.claude/commands/quality.md` that runs all three and fixes issues.

## Editing translatable strings

The text domain is `skwirrel-pim-sync`. When you add or change `__()`/`_e()`/`esc_html__()` strings:

1. Regenerate the POT file:
   ```bash
   # Using WP-CLI inside wp-env:
   npm run env:cli i18n make-pot wp-content/plugins/skwirrel-pim-sync wp-content/plugins/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot
   ```
2. Merge the new strings into each `.po` file under `plugin/skwirrel-pim-sync/languages/` (Poedit, or `msgmerge`).
3. Translate the new entries.
4. Compile `.mo` files (Poedit does this on save; `msgfmt` otherwise).
5. Commit all updated `.pot`/`.po`/`.mo` files together with the code change.

Locales shipped: `nl_NL`, `nl_BE`, `de_DE`, `fr_FR`, `fr_BE`, `en_US`, `en_GB`.

## Versioning

Every code change bumps the version. All three spots must match exactly, or the deploy workflow rejects the tag:

- `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` → `Version:` header
- `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` → `SKWIRREL_WC_SYNC_VERSION` constant
- `plugin/skwirrel-pim-sync/readme.txt` → `Stable tag:`

Also add a changelog entry to **both** `CHANGELOG.md` (repo root, dev-facing) and `plugin/skwirrel-pim-sync/readme.txt` under `== Changelog ==` (user-facing, `= X.Y.Z =` format).

See [`release.md`](./release.md) for the tag + SVN deploy flow.

## Debug constants

Set in `wp-config.php` (or via `.wp-env.json` `config`) on the dev site when you need them:

| Constant | Effect |
|---|---|
| `SKWIRREL_WC_SYNC_DEBUG_ETIM` | Writes detailed ETIM debug logs to `wp-content/uploads/` |
| `SKWIRREL_VERBOSE_SYNC` | Verbose per-product sync logging (equivalent to the `verbose_logging` setting) |
| `WP_DEBUG`, `WP_DEBUG_LOG` | Already on in wp-env |

## Common issues

- **`composer install` complains about platform PHP** — the repo requires PHP 8.1+. Check `php -v`. On macOS, consider `brew install php@8.1` or use the PHP provided inside wp-env.
- **`npm run env:start` fails / hangs** — make sure Docker Desktop is actually running. First boot pulls images and can take several minutes.
- **Port 8888 or 8889 in use** — either stop the conflicting service or change the port in `.wp-env.json`.
- **Changes don't appear on the dev site** — hard refresh the browser; check which instance you're on (`:8888` dev vs `:8889` tests). If opcache is stubborn, `npm run env:stop && npm run env:start`.
- **"WooCommerce is required" notice** — activate WooCommerce first; the plugin deactivates itself if WC is missing at activation time.
