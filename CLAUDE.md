# CLAUDE.md — Skwirrel PIM sync for WooCommerce

## Project Overview

WordPress plugin that synchronises products from the Skwirrel PIM system into WooCommerce via a JSON-RPC 2.0 API. Written in PHP 8.1+, targeting WordPress 6.x and WooCommerce 8+ (9.6+ recommended for native brand support; tested up to 10.6).

All UI strings use English source text with translatable strings (text domain `skwirrel-pim-sync`). Translations are available for nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, and en_GB.

## Repository Layout

The repository is a developer workspace, not the plugin itself. Tooling lives at the repo root; the shippable plugin lives under `plugin/skwirrel-pim-sync/`.

```
/                                   # repo root (dev workspace)
├── plugin/skwirrel-pim-sync/       # the actual WordPress plugin (what ships)
│   ├── skwirrel-pim-sync.php       # plugin bootstrap + header
│   ├── includes/                   # all classes (require_once, no autoloader)
│   ├── assets/                     # admin.css, product-documents.css
│   ├── templates/                  # WC-overridable templates
│   ├── languages/                  # .pot + 7 locales (.po/.mo)
│   └── readme.txt                  # WordPress.org readme
├── tests/                          # Pest tests (Unit + Integration)
├── composer.json / vendor/         # dev dependencies (phpstan, phpcs, pest)
├── package.json                    # wp-env scripts
├── .wp-env.json                    # WordPress 6.9 + WooCommerce Docker stack
├── phpunit.xml.dist                # unit suite config
├── phpstan.neon.dist               # static analysis config (+ baseline)
└── .phpcs.xml.dist                 # code style config
```

The plugin directory has no `composer.json` of its own. Dev dependencies are installed at the repo root and reused from there.

## Architecture

Singleton-based class architecture without Composer autoloading — all classes are loaded via `require_once` in the main plugin file (`plugin/skwirrel-pim-sync/skwirrel-pim-sync.php`).

### Key Classes & Responsibilities

All class files live under `plugin/skwirrel-pim-sync/includes/`.

| Class | File | Role |
|-------|------|------|
| `Skwirrel_WC_Sync_Plugin` | `skwirrel-pim-sync.php` | Bootstrap, dependency loading, hook registration |
| `Skwirrel_WC_Sync_Admin_Settings` | `class-admin-settings.php` | Admin UI, settings persistence, manual sync trigger |
| `Skwirrel_WC_Sync_Admin_Dashboard` | `class-admin-dashboard.php` | Dashboard screen (status, history, log viewer) |
| `Skwirrel_WC_Sync_Permalink_Settings` | `class-permalink-settings.php` | Slug configuration on Settings → Permalinks |
| `Skwirrel_WC_Sync_Action_Scheduler` | `class-action-scheduler.php` | Cron/Action Scheduler job management |
| `Skwirrel_WC_Sync_Service` | `class-sync-service.php` | Core sync orchestrator — fetches, maps, upserts products |
| `Skwirrel_WC_Sync_Sync_Queue` | `class-sync-queue.php` | Custom DB table for queued sync work |
| `Skwirrel_WC_Sync_Sync_History` | `class-sync-history.php` | Persisted history of sync runs |
| `Skwirrel_WC_Sync_Product_Mapper` | `class-product-mapper.php` | Maps Skwirrel API data to WooCommerce field values |
| `Skwirrel_WC_Sync_Product_Upserter` | `class-product-upserter.php` | Creates/updates simple + variation products |
| `Skwirrel_WC_Sync_Product_Lookup` | `class-product-lookup.php` | Resolves existing WC products by Skwirrel keys |
| `Skwirrel_WC_Sync_Purge_Handler` | `class-purge-handler.php` | Trashes stale products/categories after full sync |
| `Skwirrel_WC_Sync_Category_Sync` | `class-category-sync.php` | Matches/creates WC categories from Skwirrel |
| `Skwirrel_WC_Sync_Brand_Sync` | `class-brand-sync.php` | Registers/syncs brand + manufacturer taxonomies |
| `Skwirrel_WC_Sync_Taxonomy_Manager` | `class-taxonomy-manager.php` | Shared taxonomy helpers |
| `Skwirrel_WC_Sync_Etim_Extractor` | `class-etim-extractor.php` | ETIM feature/value extraction |
| `Skwirrel_WC_Sync_Custom_Class_Extractor` | `class-custom-class-extractor.php` | Custom (non-ETIM) classification extraction |
| `Skwirrel_WC_Sync_Attachment_Handler` | `class-attachment-handler.php` | Ties imported media to products/variations |
| `Skwirrel_WC_Sync_Media_Importer` | `class-media-importer.php` | Downloads images/files into WP media library |
| `Skwirrel_WC_Sync_JsonRpc_Client` | `class-jsonrpc-client.php` | HTTP client for Skwirrel JSON-RPC API |
| `Skwirrel_WC_Sync_Logger` | `class-logger.php` | Logging wrapper around `WC_Logger` |
| `Skwirrel_WC_Sync_Slug_Resolver` | `class-slug-resolver.php` | Resolves product URL slugs based on permalink settings |
| `Skwirrel_WC_Sync_Variation_Permalinks` | `class-variation-permalinks.php` | Variation-specific slug/permalink handling |
| `Skwirrel_WC_Sync_Variation_Attributes_Fix` | `class-variation-attributes-fix.php` | Patches WooCommerce variation attribute bugs (static) |
| `Skwirrel_WC_Sync_Product_Documents` | `class-product-documents.php` | Frontend documents tab + admin meta box |
| `Skwirrel_WC_Sync_Product_Sync_Meta_Box` | `class-product-sync-meta-box.php` | Admin meta box on product edit screen |
| `Skwirrel_WC_Sync_Delete_Protection` | `class-delete-protection.php` | Delete warnings + force full sync after WC deletion |
| `Skwirrel_WC_Sync_Theme_Api` | `class-theme-api.php` (+ `theme-api-functions.php`) | Public theme helper API |

### External API

All calls go to a configured JSON-RPC endpoint (e.g. `https://xxx.skwirrel.eu/jsonrpc`):

- `getProducts` — full paginated product list
- `getProductsByFilter` — delta sync (filter by `updated_on >= last_sync`)
- `getGroupedProducts` — variable product groups with ETIM variation axes

Authentication: Bearer token or `X-Skwirrel-Api-Token` header.

## Conventions

- **PHP version**: 8.1+ with `declare(strict_types=1)` in the main file
- **Naming**: `Skwirrel_WC_Sync_` prefix for all classes; files named `class-{slug}.php`
- **Singletons**: Most classes use `::instance()` pattern with private constructors
- **No autoloader**: All includes are manual `require_once` in the bootstrap
- **Settings storage**: Main settings in `skwirrel_wc_sync_settings` option; auth token stored separately in `skwirrel_wc_sync_auth_token`
- **Logging**: Always use `Skwirrel_WC_Sync_Logger` (wraps `wc_get_logger()`, source `skwirrel-pim-sync`)
- **WooCommerce hooks**: Use standard WC filter/action naming conventions
- **Templates**: Follow WooCommerce template override pattern (`plugin/skwirrel-pim-sync/templates/`, overridable in theme)
- **Text domain**: `skwirrel-pim-sync`
- **Language**: English source text with translations (nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)

## Important Post Meta Keys

| Key | Purpose |
|-----|---------|
| `_skwirrel_external_id` | Skwirrel external product ID (primary upsert key) |
| `_skwirrel_product_id` | Skwirrel internal product ID |
| `_skwirrel_synced_at` | Last sync timestamp for this product |
| `_skwirrel_source_url` | Original CDN URL for media attachments |
| `_skwirrel_url_hash` | SHA-256 hash of source URL (media deduplication) |
| `_skwirrel_document_attachments` | Serialized array of document metadata |
| `_skwirrel_category_id` | Skwirrel category ID (term meta on WC product_cat terms) |

## WP Options

| Option | Purpose |
|--------|---------|
| `skwirrel_wc_sync_settings` | Main plugin settings array |
| `skwirrel_wc_sync_auth_token` | API auth token (stored separately, never exposed in settings export) |
| `skwirrel_wc_sync_last_sync` | ISO timestamp of last sync run |
| `skwirrel_wc_sync_last_result` | Result array of last sync (success, counts) |
| `skwirrel_wc_sync_history` | Array of last 20 sync results |
| `skwirrel_wc_sync_permalinks` | Slug settings (slug_source_field, slug_suffix_field, update_slug_on_resync) — configured via Settings → Permalinks |
| `skwirrel_wc_sync_force_full_sync` | Flag: next scheduled sync runs as full sync (set after WC deletion) |

## Sync Flow

1. Admin clicks "Sync now" → background HTTP loopback fires the sync
2. Scheduled sync fires via Action Scheduler / WP-Cron
3. `Sync_Service::run_sync($delta)`:
   - If grouped products enabled: fetch groups first, create `WC_Product_Variable` shells
   - Paginate through products via API
   - For each product: resolve unique key → find existing or create new → map fields → save
   - Products belonging to a group become `WC_Product_Variation`
   - Delta sync filters by `updated_on >= last_sync`
   - If `sync_categories` enabled: categories are matched/created via Skwirrel ID or name
   - After full sync (non-delta, no collection filter): purge stale products/categories (if `purge_stale_products` enabled)

### Settings Keys (in `skwirrel_wc_sync_settings` array)

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `endpoint_url` | string | `''` | JSON-RPC endpoint URL |
| `auth_type` | string | `bearer` | `bearer` or `token` |
| `timeout` | int | `30` | HTTP request timeout (seconds) |
| `retries` | int | `2` | Number of retry attempts |
| `sync_interval` | string | `''` | Cron interval |
| `batch_size` | int | `100` | Products per API page |
| `sync_categories` | bool | `false` | Create/assign WC categories from Skwirrel |
| `sync_grouped_products` | bool | `false` | Enable `getGroupedProducts` (variable products) |
| `sync_manufacturers` | bool | `false` | Register + sync `product_manufacturer` taxonomy |
| `sync_images` | bool | `true` | Download images to media library |
| `use_sku_field` | string | `internal_product_code` | `internal_product_code` or `manufacturer_product_code` |
| `collection_ids` | string | `''` | Comma-separated collection IDs filter |
| `purge_stale_products` | bool | `false` | Trash products not in Skwirrel after full sync |
| `show_delete_warning` | bool | `true` | Show warning banner on Skwirrel-managed items |
| `include_languages` | array | `['nl-NL','nl']` | Language codes to include in API calls |
| `image_language` | string | `'nl'` | Preferred language for image selection |
| `verbose_logging` | bool | `false` | Enable verbose sync logging |

### Permalink Settings (in `skwirrel_wc_sync_permalinks` option, configured via Settings → Permalinks)

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `slug_source_field` | string | `product_name` | Primary field for product URL slug (product_name, internal_product_code, manufacturer_product_code, external_product_id, product_id) |
| `slug_suffix_field` | string | `''` | Suffix field appended to slug when duplicate exists (same options minus product_name, or empty for WP auto-numbering) |
| `update_slug_on_resync` | bool | `false` | When true, also update slugs for existing products during sync (not just new products) |

## Versioning & Release

- **Every change bumps the version** — update `Version:` in `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` header and `SKWIRREL_WC_SYNC_VERSION` constant
- **Each version is committed and tagged** — `git tag X.Y.Z` on the version bump commit
- **Tag format**: `X.Y.Z` (no `v` prefix) — consistent with existing tags
- **Update changelog**: add entries to both `CHANGELOG.md` (repo root) and `plugin/skwirrel-pim-sync/readme.txt` (WordPress format)
- **Update translations**: regenerate `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot` and update all `.po`/`.mo` files when strings change

### Quality Checks (run before every commit, from the repo root)

```bash
# All three must pass before committing:
vendor/bin/pest            # Unit tests (stub bootstrap, no Docker)
vendor/bin/phpstan analyse # Static analysis (level 6, baseline in phpstan-baseline.neon)
vendor/bin/phpcs           # Code style (WordPress standards)

# Auto-fix code style issues:
vendor/bin/phpcbf
```

### Integration tests (wp-env)

Integration tests run against a real WordPress + WooCommerce stack inside Docker via `@wordpress/env`. They live in `tests/Integration/` and use the real `$wpdb`, real WC data stores, and real term/post APIs.

The `.wp-env.json` mounts `./plugin/skwirrel-pim-sync` into the container at `wp-content/plugins/skwirrel-pim-sync`. Test commands run `composer`/`pest` inside that plugin directory.

```bash
npm install                # one-time
npm run env:start          # boot wp-env (Docker)
npm run composer:install   # install composer deps inside the tests container
npm run test:unit          # unit suite inside the container
npm run test:integration   # integration suite against real WP
npm run test:all           # both suites

# Stop / reset
'/Users/joskoomen/Pictures/screenshots/Scherm­afbeelding 2026-04-17 om 17.26.00.png'npm run env:stop
npm run env:clean          # drop both DBs
```

See `tests/Integration/README.md` for the full guide.

## Development Notes

- No build step or frontend JS — admin uses plain PHP-rendered forms
- CSS assets: `plugin/skwirrel-pim-sync/assets/admin.css` (admin settings page) and `plugin/skwirrel-pim-sync/assets/product-documents.css` (frontend documents tab)
- The `SKWIRREL_WC_SYNC_DEBUG_ETIM` constant enables detailed ETIM debug logging to the uploads directory
- The `SKWIRREL_VERBOSE_SYNC` constant or `verbose_logging` setting enables verbose log output
- Static analysis: `vendor/bin/phpstan analyse` (config in `phpstan.neon.dist`, baseline in `phpstan-baseline.neon`)
- Code style: `vendor/bin/phpcs` (config in `.phpcs.xml.dist`)
- Unit tests: `vendor/bin/pest` (Pest PHP, config in `phpunit.xml.dist`, stub bootstrap in `tests/bootstrap.php`)
- Integration tests: `npm run test:integration` (Pest + real WP via wp-env, bootstrap in `tests/Integration/bootstrap.php`)
- Local environment: `.wp-env.json` provisions WordPress 6.9 + WooCommerce + this plugin in Docker. See `tests/Integration/README.md`.
