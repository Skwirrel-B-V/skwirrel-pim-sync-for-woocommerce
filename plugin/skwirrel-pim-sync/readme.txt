=== Skwirrel PIM sync for WooCommerce ===
Contributors: jkoomen
Tags: woocommerce, sync, pim, skwirrel, product-sync
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 3.11.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronises products from the Skwirrel PIM system to WooCommerce via a JSON-RPC 2.0 API.


== Description ==

Skwirrel PIM sync for WooCommerce connects your WooCommerce webshop to the Skwirrel PIM system. Products, variations, categories, brands, manufacturers, images, and documents are synchronised automatically or on demand.

**Features:**

* Full and delta (incremental) product synchronisation
* Simple and variable product support with ETIM classification for variation axes
* Automatic category tree sync with parent-child hierarchy
* Brand sync via WooCommerce native product_brand taxonomy
* Manufacturer sync with dedicated product_manufacturer taxonomy
* Product image and document import into the WordPress media library
* Custom class attributes (alphanumeric, logical, numeric, range, date, multi)
* Configurable product URL slugs (source field, suffix, update on re-sync)
* GTIN and manufacturer product code search filter on the product list page
* Scheduled synchronisation via WP-Cron or Action Scheduler
* Manual synchronisation from the admin dashboard with live progress tracking
* Date-grouped sync history (last 20 runs)
* Stale product and category purge after full sync
* Delete protection with warnings and automatic full re-sync
* Multilingual support with 7 locales (nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)
* Optional integration with the WordPress 7.0 Connections Screen for centralised API key management

**Requirements:**

* WordPress 6.0 or higher
* WooCommerce 8.0 or higher (9.6+ recommended for native brand support; tested up to 10.6)
* PHP 8.3 or higher
* An active Skwirrel account with API access

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/skwirrel-pim-sync/`, or install the plugin directly through the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to WooCommerce > Skwirrel Sync to configure the plugin.
4. Enter your Skwirrel API URL and authentication token.
5. Click 'Sync now' to start the first synchronisation.

== Frequently Asked Questions ==

= Which Skwirrel API version is supported? =

The plugin works with the Skwirrel JSON-RPC 2.0 API.

= How often are products synchronised? =

You can set an automatic schedule (hourly, twice daily, or daily) or synchronise manually from the settings page.

= Are existing products overwritten? =

The plugin uses the Skwirrel external ID as a unique key. Existing products are updated, not duplicated.

= I use a media offload plugin (WP Offload Media, S3 Uploads, …) — will the sync delete my offloaded files? =

No, the sync never invokes `wp_delete_attachment()` on a missing-file event in 3.8.0+. When the local file is gone, the plugin only clears its own Skwirrel-side meta keys from the WP attachment record so the next sync can download fresh; the WP record itself (and any remote copy your offload plugin manages) is left untouched.

If you want to go a step further and have the sync **reuse** the existing WP attachment (no fresh download, no churn) when the local file is gone but the remote copy is fine, hook into the `skwirrel_wc_sync_attachment_is_valid` filter. The simplest implementation as a mu-plugin:

`<?php`
`add_filter( 'skwirrel_wc_sync_attachment_is_valid', function ( $local_present, $att_id ) {`
`    return $local_present || (bool) wp_get_attachment_url( $att_id );`
`}, 10, 2 );`

Returning `true` tells the sync the attachment is still valid even though the local file is missing. The plugin ships a more thorough reference implementation (URL-equals-uploads-baseurl check) you can adapt — see the project's `mu-plugins/skwirrel-offload-compat.php`.

== Changelog ==

= 3.11.2 =

* Improvement: the sync status now updates live, without reloading the page. On the Skwirrel admin pages you see the full progress banner; on any other admin page a small status toast appears in the corner with the current step, a counter and a "View live log" link — you can move it between the bottom-right and top-right corner and hide it for the session.
* Improvement: "Test connection" now saves your connection settings (subdomain and token) first and shows the result instantly, so it works right after you enter a new environment — no separate save needed.
* Improvement: the "Batch size" setting moved into the Sync options section and now allows up to 100 products per request.
* Improvement: the "Edit permalink settings" link opens in a new browser tab.
* Improvement: the scheduled sync interval now has finer options (every 2/3/4/6/8 hours) and enforces a safe minimum based on how long your last full sync took — there is always at least one full hour of rest between automatic syncs, so runs can never overlap (a 45-minute sync requires at least 2 hours, 75 minutes requires 3 hours).
* Improvement: the interface now matches your WordPress admin theme — the accent colour follows your admin colour scheme and the header matches the admin menu — with Skwirrel lime icon accents.

= 3.11.1 =

* Fix: re-syncs no longer report variable (grouped) products as "updated" when nothing changed. In 3.11.0 the "unchanged" gate covered simple products and variations but not the variable-product parents or their shared content/images, so an unchanged catalog still showed a residual "updated" count and re-applied that content every run. Those are now included in the gate, so a repeat sync of an unchanged catalog reports 0 updated and skips the redundant work.
* Fix: change detection now compares the actual product content rather than the "last modified" timestamp. Products that Skwirrel re-stamps without a real content change are now correctly recognised as unchanged and skipped.
* Maintenance: internal code-quality cleanups (static analysis and coding-standards fixes). No functional change.

= 3.11.0 =

* Change: a "normal" batch sync now imports each product fully in one pass — create, categories, attributes, and images together — exactly like syncing a single product from the product screen. Previously batch sync worked in separate global phases (all products created first, then all categorised, then all imaged, …), so a run interrupted by a timeout or server limit could leave products half-built (created but without images or attributes) and a later sync would not go back to finish them. Each product is now committed completely before the next, so an interrupted run only leaves not-yet-started products, which are picked up cleanly next time. Same work, same speed — just no half-finished products.
* Safety: a newly-created product now stays a draft until its categories, attributes, and images are all in place, then goes live. This prevents a sync that is interrupted while importing a brand-new product from briefly showing an empty product on your shop. (Existing products are never affected — they are never unpublished during a sync.)
* Fix: re-syncs no longer create duplicate products with a suffixed SKU (e.g. `4250366870007-14768`). When a product's SKU already exists, the sync now reuses the existing product (or, for grouped/variable products, leaves it to the grouped-product path) instead of minting a second copy.
* Fix: an interrupted sync no longer "loses" products. The delta checkpoint that tracks what has been synced is now advanced only when a run fully completes (and is stamped with the run's start time), so a run that dies partway through is simply re-done next time instead of silently skipping the products it never finished.
* Fix: the live "Sync in progress" panel now shows the steps that actually run (Fetch, Create & sync products, Finalize variable products, Link related products, Cleanup) instead of the old phase list, so steps no longer appear stuck and the counts make sense.
* Change: re-syncs now report products as "unchanged" instead of marking every product "updated". A product counts as updated only when it actually changed in Skwirrel (its update timestamp advanced) — not just because a sync ran. Unchanged products are skipped (no re-save), so a repeat sync of a mostly-unchanged catalog finishes in seconds, and the result shows a new "Unchanged" count. Changing a sync setting (or upgrading the plugin) automatically reprocesses everything once.

= 3.10.3 =

* Fix: the internal `wp_skwirrel_sync_queue` working table no longer grows without bound. This table is temporary scratch space used during a sync, and is cleaned up when a run finishes. Runs that ended abnormally — a fatal error, an out-of-memory kill, a server timeout, or a hard-killed process — left their rows behind, and over time these accumulated and could fill the disk. Each sync now sweeps away leftovers from earlier interrupted runs at the start, and cleanup is hardened to run on every failure path, so the table stays small automatically. (No product data is affected — this is purely temporary working data.)

= Older versions =

Earlier changelog entries (3.10.2 and before) are in the full changelog on GitHub:
https://github.com/Skwirrel-B-V/skwirrel-pim-sync-for-woocommerce/blob/main/CHANGELOG.md
