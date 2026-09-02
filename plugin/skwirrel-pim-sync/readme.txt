=== Skwirrel PIM sync for WooCommerce ===
Contributors: jkoomen
Tags: woocommerce, sync, pim, skwirrel, product-sync
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 3.14.0
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

= 3.14.0 =

* New: the settings screen is grouped into tabs — Connection, What to sync, How it looks, Advanced and Field mapping — so you no longer scroll past forty settings to reach one. No setting changed, moved out of its group, or was renamed.
* New: a tab containing a field that failed validation is marked with a warning icon and a count, and opens first. Validation messages from saving are now shown on the screen itself.
* New: link straight to a tab with `#tab-connection`, `#tab-what-to-sync`, `#tab-how-it-looks`, `#tab-advanced` or `#tab-field-mapping`; the address bar keeps up as you switch tabs.
* New: the tab strip is fully keyboard operable (arrow keys, Home and End) and announced correctly by screen readers.
* New: mandatory settings are marked with an asterisk, and fields that are only mandatory in certain configurations pick the marker up the moment you tick the setting that makes them so — no save needed to find out.
* New: every validation message now appears next to the field it is about, as well as in the standard WordPress summary at the top, and screen readers announce it together with that field.
* New: an optional Context ID on the API Connection settings, for Skwirrel instances that serve more than one context. Leave it empty to keep using the Skwirrel default context — that is what your shop does today.
* New: the Context ID is applied to products, product groups and categories alike, so your catalogue can never end up a mix of two contexts.
* New: changing the Context ID re-imports your whole catalogue on the next synchronisation, and says so when you save. Saving without changing it does not.
* New: a Context ID that is not a whole number greater than 0 is rejected with a message at the field, stays visible so you can correct it, and is never sent to Skwirrel.
* New: "Test connection" now reports how long the round trip took, the status that came back, and how many products Skwirrel says it has — instead of only telling you it worked.
* New: a test that connects but finds no products is shown as a warning rather than a green tick, so an empty selection is visible straight away instead of after a synchronisation that imports nothing.
* New: a failed test now shows the timing and the status next to the error, so a timeout can be told apart from a refusal, and it says how many attempts were made when it retried.
* New: a "Field mapping" tab where you can point four WooCommerce fields at your own Skwirrel custom fields — stock quantity, product title, short description and long description. Leave one empty and that field keeps working exactly as it does today.
* New: stock levels can now come straight from Skwirrel, for simple products and for each variation of a variable product separately, so you stop keeping stock in two systems.
* New: a product with no value for the mapped stock field is left completely alone — its stock is never set to 0 and never switched to unmanaged. The same holds for the title and description mappings: an empty field falls back to the text you get today instead of blanking your product.
* New: variations priced on request stay out of stock whatever the stock field says.
* New: document links on a product now show the document's real name in your shop's language instead of the file name, so you can tell a mounting instruction from a declaration of performance without opening both.
* Fix: a synchronisation now finishes under the settings it started with. Changing the Context ID or a field mapping while a synchronisation is running no longer applies to only half of your catalogue, and can no longer cause valid products to be removed.
* Fix: with a Context ID set, "Test connection" now reports the number of products in *that* context instead of the default one.
* Fix: saving while a required field sits on another tab now opens that tab and points at the field, instead of the browser silently refusing to submit.
* Fix: the Super category ID no longer has to be filled in before the form submits when category sync is switched off.
* Fix: saving with a rejected value no longer shows a green "Settings saved." confirmation alongside the error.
* Fix: the Custom class collection ID is no longer labelled "(optional)" while saving can reject it as missing.
* Fix: restored the translated delete-protection hint to the wording the plugin actually displays.
* Maintenance: the temporary WooCommerce-menu pointer used during the top-level-menu migration has now expired and been removed.
* Note: with JavaScript disabled the settings screen still shows every setting, exactly as before.

= 3.13.1 =

* Fix: the new selection-safety messages introduced in 3.13.0 are now included in every shipped translation catalogue instead of falling back to English.

= 3.13.0 =

* New: Skwirrel Sync now has its own menu in the WordPress admin instead of sitting under WooCommerce, with Status, Settings, Sync logs and Sync now as sub-items. Your existing bookmarks keep working, and sites upgrading from an older version keep a link under WooCommerce for a few releases so nothing goes missing.
* New: scheduled syncs now decide what to remove by checking which products are still in your Skwirrel selection, instead of assuming a product that did not change has been withdrawn.
* New: a sync that would remove an unusually large part of your catalogue refuses to do it and tells you so in the sync history, with the Success badge intact — nothing is removed until you run a manual sync to confirm.
* New: if the selection check could not be completed, or came back empty, nothing is removed at all. Product-only syncs continue safely; grouped-product syncs stop before product changes when membership cannot be proven, avoiding catalogue-wide group imports or misclassified variations.
* Fix: selection checks now reject malformed pagination, repeated pages, missing product data, invalid or overflowing IDs, and database read failures instead of treating partial membership as complete.
* Fix: German — "Sync Now" was labelled "Synchronisierungsverlauf" (sync history), and the "Show delete warning on Skwirrel products" setting was labelled "Delete all Skwirrel products". Both now say what they do.
* Fix: German — "API Connection" and "Scheduling" showed the wrong labels.
* Fix: Dutch, German and French — the hints for finding your category IDs and your selection IDs both showed the same text; they now describe the right one.
* Fix: the warnings a sync shows when it refuses to remove products are now translated instead of appearing in English.

= 3.12.2 =

* Fix: a product you set to Private (or scheduled for later) stays that way — previously every sync published it again, over and over.
* Fix: if a variable product was removed from Skwirrel but its variations were left behind published, the next full sync now hides those variations too instead of leaving them for sale.
* Fix: a sync that converts a product into a variation no longer forces every following scheduled sync to run as a slow full sync.
* Fix: a product status whose *description* says "Draft" but whose internal code does not (e.g. code `PENDING_REVIEW`, description "Draft - not published") is held as a draft again, as it was before 3.12 — upgrading no longer publishes products that used to stay hidden.
* Fix: renaming a status in Skwirrel now updates its label in the Product status handling table; previously the table kept showing the old name forever.
* Fix: a status used only by products marked as removed in Skwirrel is now discovered too, so it can be configured instead of silently following the global default.
* Fix: "Refresh statuses from Skwirrel" now walks the whole catalogue instead of only the first page, so statuses used further down the feed are found. It also reports when it stopped before the end.
* Fix: a product you trash yourself in WooCommerce is now brought back by the next full sync instead of staying in the trash until its Skwirrel timestamp happens to change.
* Fix: a revived product keeps its original permalink instead of being restored on a permanent `…__trashed` URL.
* Fix: a variable product that was cleaned up and later returns unchanged now comes back out of the trash — previously its group signature still matched, so the sync skipped it and it stayed hidden.
* Fix: changing the status mapping while a sync is running no longer applies half-way through that run; the run finishes under the mapping it started with.
* Fix: the block on manually deleting Skwirrel products now lasts the whole sync, instead of lapsing during long API calls.
* Fix: the sync history's Created / Updated links now also list products the run put straight into the Deprecated state.
* Fix: "Refresh statuses from Skwirrel" now scans large catalogues in steps instead of one long request that a server or browser timeout could kill.
* Fix: for variable products, the sync history's Created / Updated links now open the parent products of that run — previously they could open an almost empty list.
* Fix: when one run both creates and updates variations of the same product, that product now appears under *both* the Created and Updated links instead of only one of them.
* Fix: if you manually republish a product that the sync had set to Deprecated (or change the status of a mapped draft), the next full sync restores the state your mapping asks for — previously it was skipped as "unchanged" forever.
* Fix: status mappings you configured before 3.12 keep working after upgrading, also for statuses whose internal code differs from their description — previously such a product quietly fell back to the default state.
* Fix: a sync that spends several rounds retiring deprecated products is no longer aborted as "stalled".
* Fix: if a sync fails part-way, the Deleted count in the history now reports the same thing a successful run does.
* Fix: if you renamed one of the built-in Skwirrel statuses (Draft, Available, Discontinued), the Product status handling table now shows your name and ID instead of the built-in English one.
* Fix: after a permanent purge from the Danger Zone, the sync history no longer offers links to products that no longer exist.
* Fix: variable products created or updated as part of a grouped sync now show up in that run's Created / Updated links.
* Fix: the sync history's Deleted link now also finds removals of individual variations, whose parent product stays published.
* Fix: syncs no longer risk running out of time or memory while retiring deprecated products on large catalogues — they are now processed in batches that resume.
* Fix: "Refresh statuses from Skwirrel" no longer hangs for minutes when the Skwirrel endpoint is slow or unreachable.
* Fix: a variation that was trashed and later returns keeps its original URL instead of coming back on a `…__trashed` permalink.
* Fix: re-syncing a single product from its edit screen now also records its Skwirrel status, so the status shows up in the Product status handling table.
* Fix: clicking "Refresh statuses from Skwirrel" with unsaved changes on the settings page no longer reloads the page and discards them.
* Fix: a product created and then trashed within the same run still appears under that run's Created link.
* Fix: translations — plural forms are now declared in every language file (`msgfmt --check` passed), the status-discovery message has a real plural, and several Dutch, German and French strings that were empty or paired with the wrong text have been corrected.

= 3.12.1 =

* New: the **Product status handling** table now always shows the three built-in Skwirrel statuses (Draft, Available, Discontinued) with recommended defaults — no need to wait for a sync first.
* New: **"Refresh statuses from Skwirrel"** button — fetch your Skwirrel status list on demand to configure the mapping before your first sync.
* New: status rows now display the numeric ID, internal code, and label together as a compact badge.
* New: the sync history table has a **Deprecated** column showing how many products ended each run in the deprecated state.
* New: the Created / Updated / Deprecated / Deleted counts in the sync history now link directly to the filtered product list for that run.
* Change: **Custom class collection ID** is now optional — only required when syncing custom classes or grouped products.
* Change: `product_trashed_on` no longer automatically moves products to the trash — their outcome is now governed by the status-handling mapping (e.g. map DISCONTINUED → Trash or Deprecated).
* Translations updated for all 7 locales.

= 3.12.0 =

* New: **Product status handling** — decide, per Skwirrel product status, what happens in WooCommerce: keep it published, set it to draft, move it to the trash, or mark it as **Deprecated**. Statuses are detected automatically as they appear during a sync, and you set a default for newly-seen statuses.
* New: **Deprecated lifecycle** — retire products gradually. A product marked Deprecated is hidden in its own bucket and, after a number of full syncs you choose, is moved to the trash automatically (never permanently deleted). Set the threshold to 0 to remove immediately.
* New: control what happens to products that are **removed/discontinued in Skwirrel** or **no longer in the feed** — keep them, set them to draft, trash them, or deprecate them.
* Improvement: a product that was trashed and later returns is now revived in place instead of being duplicated.
* Improvement: while a sync is running, manually deleting Skwirrel products is temporarily blocked to prevent conflicts.
* Translations updated for Dutch, German and French.

= 3.11.3 =

* Improvement: small dashboard tweaks — the "Save settings" button matches the other buttons' rounded corners, the sync progress block has more breathing room below the header, and the dashboard now uses up to 1280px width so the cards and history table have more room.

= 3.11.2 =

* Improvement: the sync status now updates live, without reloading the page. On the Skwirrel admin pages you see the full progress banner; on any other admin page a small status toast appears in the corner with the current step, a counter and a "View live log" link — you can move it between the bottom-right and top-right corner and hide it for the session.
* Improvement: "Test connection" now saves your connection settings (subdomain and token) first and shows the result instantly, so it works right after you enter a new environment — no separate save needed.
* Improvement: the "Batch size" setting moved into the Sync options section and now allows up to 100 products per request.
* Improvement: the "Edit permalink settings" link opens in a new browser tab.
* Improvement: the scheduled sync interval now has finer options (every 2/3/4/6/8 hours) and enforces a safe minimum based on how long your last full sync took — there is always at least one full hour of rest between automatic syncs, so runs can never overlap (a 45-minute sync requires at least 2 hours, 75 minutes requires 3 hours).
* Improvement: the interface now matches your WordPress admin theme — the accent colour follows your admin colour scheme and the header matches the admin menu — with Skwirrel lime icon accents.
* Fix: the "unchanged" gate for variable (grouped) products now works on the very first sync after installing, updating, or changing a sync setting. Previously that first run rebuilt the product correctly but did not remember its fingerprint, so the next sync still reported every variable product as "updated" once more before settling down. They are now recognised as unchanged immediately.
* Fix: a variable product whose category, brand, or manufacturer assignment fails during a sync is no longer wrongly skipped as "unchanged" on the next run — it is retried until that part succeeds.
* Maintenance: build and packaging hygiene — removed stray development files from the shipped plugin and tidied internal code quality. No functional change.

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
