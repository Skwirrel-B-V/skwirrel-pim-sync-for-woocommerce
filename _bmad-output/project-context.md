---
project_name: 'Skwirrel PIM sync for WooCommerce'
user_name: 'Jos'
date: '2026-06-10'
sections_completed: ['technology_stack', 'language_framework', 'sync_architecture', 'testing_quality', 'release_workflow', 'critical_dont_miss']
status: 'complete'
rule_count: 39
optimized_for_llm: true
---

# Project Context for AI Agents

_This file contains critical rules and patterns that AI agents must follow when implementing code in this project. Focus on unobvious details that agents might otherwise miss._

---

## Technology Stack & Versions

- **PHP 8.3+** — `declare(strict_types=1)` in the bootstrap; type everything. Target syntax must run on 8.3 (no 8.4-only features).
- **WordPress 6.9+** (tested & usable floor; header `Requires at least:` still reads `6.0` — bump pending) · **WooCommerce 8.0+** (9.6+ for native brands, current release 10.8).
  - WP 7.0 ships the **Connectors API** — the plugin integrates with it (since 3.10.0) while staying compatible down to 6.9. Prefer the Connectors API over legacy mechanisms when wiring sync hooks.
- **HPOS-compatible** — plugin declares `custom_order_tables` compatibility; never touch legacy order-post-meta APIs directly.
- **No Composer autoloader in the plugin** — every class is loaded via `require_once` in `skwirrel-pim-sync.php`. New classes MUST be added there manually.
- **No build step, no frontend JS framework** — admin UI is plain PHP-rendered forms; styling via two static CSS files.
- **Dev tooling lives at repo root, NOT in the plugin** — `plugin/skwirrel-pim-sync/` has no `composer.json`. Run `vendor/bin/*` from the repo root.
  - PHPStan ^2.0 (level 6, `phpstan-baseline.neon`) · PHP_CodeSniffer ^3.7 + WPCS ^3.0 · Pest ^3.0 · wp-env (Docker) for integration tests.
- **API**: Skwirrel JSON-RPC 2.0 (`getProducts`, `getProductsByFilter`, `getGroupedProducts`). Auth via Bearer token or `X-Skwirrel-Api-Token`.

## Critical Implementation Rules

### Language & Framework Rules (PHP / WordPress / WooCommerce)

- **Class naming is a hard WPCS rule** — every class is `Skwirrel_WC_Sync_{Name}`, file `class-skwirrel-wc-sync-{slug}.php` (full class name in kebab-case). `WordPress.Files.FileName.InvalidClassFileName` will fail otherwise.
- **Singletons** — most classes use a private constructor + `::instance()`. Don't `new` them; follow the existing pattern.
- **Register a new class in TWO places**: add the `require_once` in `skwirrel-pim-sync.php` AND wire its hooks in `Skwirrel_WC_Sync_Plugin`. There is no autoloader to catch omissions.
- **All output must be escaped** (`esc_html`, `esc_attr`, `esc_url`), all input sanitized (`sanitize_text_field`, `esc_url_raw`, etc.). WPCS enforces this — unescaped output fails phpcs.
- **All user-facing strings are translatable** — wrap in `__()`/`esc_html__()` etc. with text domain `'skwirrel-pim-sync'` (string literal, never a variable). Source text is English.
- **Logging only via `Skwirrel_WC_Sync_Logger`** (wraps `wc_get_logger()`, source `skwirrel-pim-sync`). Never `error_log()` or raw `wc_get_logger()`.
- **Settings access** — main settings in option `skwirrel_wc_sync_settings` (array); auth token in the separate `skwirrel_wc_sync_auth_token` option, which must NEVER appear in settings export.
- **Templates** follow the WooCommerce overridable pattern (`templates/`, theme-overridable via `wc_get_template`). Don't hardcode markup that should be a template.
- **Meta keys are contracts** — reuse the documented `_skwirrel_*` keys (see Post Meta table in CLAUDE.md); never invent a parallel key for the same data.

### Sync Architecture Rules

- **Upsert key precedence is fixed**: `external_product_id` → `internal_product_code` → `manufacturer_product_code` → `product_id` (prefixed `ext:` / `sku:` / `id:` by `get_unique_key()`). Never reorder or bypass it — it's how existing products are matched.
- **Delta vs full**: `run_sync(true)` = delta (`getProductsByFilter` on `updated_on >= last_sync`); `run_sync(false)` = full (`getProducts`). Last sync stored ISO `Y-m-d\TH:i:s\Z` in `skwirrel_wc_sync_last_sync`.
- **Purge is dangerous — respect the guards**: stale-product trashing runs ONLY after a full sync with NO collection filter and `purge_stale_products` enabled. A collection filter active → purge is SKIPPED (else you'd trash other collections' products).
- **Never permanently delete** — purge moves products to trash. Categories with attached non-trashed products are NEVER deleted (warning logged instead).
- **SQL safety for meta**: validate with `REGEXP '^[0-9]+$'` before `CAST(... AS UNSIGNED)`; corrupt meta values must be skipped, not crash the run.
- **Don't zero-out prices**: some clients sync prices via a separate ERP feed. A missing/`price_on_request` price maps to `null` — never overwrite an existing WC price with 0/empty when the PIM omits it.
- **Variable products**: grouped sync runs FIRST, builds `$product_to_group_map`; group members become `WC_Product_Variation`. Trashing a variable parent auto-trashes its variations.
- **Skip VIRTUAL products** unless they belong to a group.
- **`_skwirrel_synced_at`** is the stale-detection timestamp — every upsert path (simple + variation) must write it, or the product gets falsely purged.

### Testing & Quality Gates

- **Three checks MUST pass before every commit**, run from the repo root:
  - `vendor/bin/pest` (unit) · `vendor/bin/phpstan analyse` (level 6) · `vendor/bin/phpcs` (WPCS)
  - Auto-fix style with `vendor/bin/phpcbf`. Never commit with a failing gate.
- **Pest, not class-based PHPUnit** — use `test()`, `beforeEach()`, `expect()`, and `dataset()`/`with()`. File naming `{ClassName}Test.php`.
- **Two suites**: unit tests in `tests/Unit/` run on stub bootstrap (no Docker, fast); integration tests in `tests/Integration/` run against real WP+WC via wp-env (`npm run test:integration`).
- **PHPStan baseline** — `phpstan-baseline.neon` holds known issues. Don't regenerate it to hide NEW errors; fix new findings instead.
- **Priority test targets**: Product Mapper (field extraction, fallback chains, ETIM, categories), Sync Service (purge detection, collection-filter parsing), JsonRpc Client (request/response/errors), Delete Protection (force-full-sync flag).
- **Don't weaken a test to make it pass** — if behavior changed intentionally, update the assertion deliberately and say so.

### Release Workflow

- **Every change bumps the version** — update BOTH `Version:` in the plugin header AND the `SKWIRREL_WC_SYNC_VERSION` constant. They must match.
- **Changelog in TWO files** — `CHANGELOG.md` (repo root) AND `plugin/skwirrel-pim-sync/readme.txt` (WordPress format, `= X.Y.Z =` heading + `Stable tag:`). The deploy workflow FAILS if the `readme.txt` entry is missing.
- **Tag format `X.Y.Z`** (no `v` prefix) on the version-bump commit. Pushing the tag triggers WordPress.org SVN deploy via `.github/workflows/deploy.yml`, which verifies header + constant + Stable tag + changelog all match the tag.
- **Translations** — when translatable strings change, regenerate `languages/skwirrel-pim-sync.pot` and update all 7 locales' `.po`/`.mo` (nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB).
- **Deploy environment gate** uses **fnmatch, not regex** — `[0-9]*.[0-9]*.[0-9]*` is valid; `[0-9]+.[0-9]+.[0-9]+` silently blocks every deploy.

### Critical Don't-Miss Rules

- **Repo root ≠ plugin** — only edit shippable code under `plugin/skwirrel-pim-sync/`. Don't ship dev placeholders, tooling, or test files into the plugin directory.
- **The plugin slug is `skwirrel-pim-sync`** — WordPress.org bans "wp" in slugs. Don't reintroduce the old `skwirrel-pim-wp-sync` slug anywhere user-facing.
- **No `Date.now()`-style nondeterminism in logic that's tested** — keep mappers pure where possible so unit tests stay deterministic.
- **Background sync** fires via HTTP loopback / Action Scheduler, gated by transient `skwirrel_wc_sync_bg_token` — preserve the gating when touching sync triggers.
- **Capability gate** for admin UI is `manage_woocommerce`, page slug `skwirrel-pim-sync` under the WooCommerce menu.
- **Don't expose the auth token** — it's stored separately and excluded from settings export by design.

---

## Usage Guidelines

**For AI Agents:**

- Read this file before implementing any code in this project.
- Follow ALL rules exactly as documented; when in doubt, prefer the more restrictive option.
- This file is a lean supplement to `CLAUDE.md` and `.claude/rules/*` — those hold the full reference tables (meta keys, settings keys, class map). Don't duplicate; cross-reference.
- Update this file when a new non-obvious pattern emerges that would otherwise trip up an agent.

**For Humans:**

- Keep this file lean and focused on what agents miss — not a full manual.
- Update when the stack changes (PHP/WP/WC versions, tooling) or when a release process step changes.
- Review periodically; remove rules that have become obvious.

Last Updated: 2026-06-10
