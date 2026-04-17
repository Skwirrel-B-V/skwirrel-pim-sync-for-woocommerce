# Skwirrel PIM sync for WooCommerce

[![CI](https://github.com/Skwirrel-B-V/skwirrel-pim-wp-sync/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/Skwirrel-B-V/skwirrel-pim-wp-sync/actions/workflows/ci.yml)
[![WordPress plugin](https://img.shields.io/wordpress/plugin/v/skwirrel-pim-sync?label=wp.org)](https://wordpress.org/plugins/skwirrel-pim-sync/)
[![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/skwirrel-pim-sync)](https://wordpress.org/plugins/skwirrel-pim-sync/)
[![Tested WP](https://img.shields.io/wordpress/plugin/tested/skwirrel-pim-sync)](https://wordpress.org/plugins/skwirrel-pim-sync/)
[![License](https://img.shields.io/badge/license-GPLv2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)

WordPress plugin that synchronises products from the Skwirrel PIM system to WooCommerce via a JSON-RPC 2.0 API.

Syncs simple and variable products (with ETIM variation axes), categories, brands, manufacturers, images, and documents — either on demand from the admin dashboard or on a schedule via WP-Cron / Action Scheduler. See [`plugin/skwirrel-pim-sync/readme.txt`](plugin/skwirrel-pim-sync/readme.txt) for the full user-facing feature list and settings reference (also published on WordPress.org).

## Changelog
- **[CHANGELOG.md](CHANGELOG.md)** — version history (dev-facing)

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+ (9.6+ recommended for native brand support; tested up to 10.6)
- PHP 8.1+
- An active Skwirrel account with API access

## Installation

Install from the **[WordPress.org plugin directory](https://wordpress.org/plugins/skwirrel-pim-sync/)**. Then activate, go to **WooCommerce → Skwirrel Sync**, enter your subdomain and API token, and click **Test connection** → **Sync now**.

## Documentation

- **[docs/local-development.md](docs/local-development.md)** — setting up the repo, wp-env, editing translations, debug constants
- **[docs/local-testing.md](docs/local-testing.md)** — unit + integration tests, static analysis, code style, pre-commit gate
- **[docs/release.md](docs/release.md)** — triggers, workflow internals, and the end-to-end tag-based release flow

## Testing

[![Unit tests](https://img.shields.io/badge/unit%20tests-159-brightgreen)](docs/local-testing.md)
[![Integration tests](https://img.shields.io/badge/integration%20tests-7-brightgreen)](docs/local-testing.md)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%206-blue)](phpstan.neon.dist)
[![Code style](https://img.shields.io/badge/code%20style-WordPress-blue)](.phpcs.xml.dist)

| Check | Tooling | Runs in CI? |
|---|---|---|
| Unit tests | Pest — 159 tests, 262 assertions (stub bootstrap, no Docker) | Yes, on every push + PR |
| Integration tests | Pest + `WP_UnitTestCase` — 7 tests against real WP + WC via `@wordpress/env` | No — local only |
| Static analysis | PHPStan, level 6 (`phpstan.neon.dist` + `phpstan-baseline.neon`) | Yes |
| Code style | PHPCS, WordPress Coding Standards (`.phpcs.xml.dist`) | Yes |

Run everything from the repo root:

```bash
vendor/bin/pest              # unit tests
vendor/bin/phpstan analyse   # static analysis
vendor/bin/phpcs             # code style
npm run test:integration     # integration tests (requires npm run env:start)
```

Test counts above are a snapshot of `main`; run `vendor/bin/pest` for the authoritative total. Full guide: **[docs/local-testing.md](docs/local-testing.md)**.

## Contributing

- Every change bumps the version in all three spots (plugin header, `SKWIRREL_WC_SYNC_VERSION` constant, `readme.txt` Stable tag) — see [`docs/release.md`](docs/release.md).
- Pre-commit quality gate: `vendor/bin/pest`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs` — all three must pass. CI re-runs them on PRs and pushes to `main`.

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
