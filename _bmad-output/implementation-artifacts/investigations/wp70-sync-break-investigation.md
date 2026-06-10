---
case: WP 7.0 sync break — Skwirrel PIM sync
slug: wp70-sync-break
status: Active — diagnosis complete, fixes not yet implemented
opened: 2026-06-10
investigator: Claude (BMad Investigate) with Jos
---

# Case File — WP 7.0 "sync partially broken" (Skwirrel PIM sync)

## Hand-off Brief (15-second read)
After clients updated to **3.10.1** and their sites moved to **WordPress 7.0 / WooCommerce 10.x**, the sync "partially stopped working." It is **not one bug** — it is a cluster of seven, of which the headline ("no scheduled syncs appear") is the **scheduler never being re-armed after a WP.org auto-update**. Several others (missing images, duplicate products) stem from a shared structural weakness: a **phased, non-resumable sync** plus **identity-resolution that misses**, so interrupted runs strand or duplicate products.

## Case Info
- **Affected release:** 3.10.1 (latest released tag). Working tree = 3.10.2 (unreleased) already contains an uncommitted partial fix for Issue 1.
- **Environment:** WP 7.0.0, WooCommerce 10.x. Example client: essec.jakobus-corneel.be.
- **Inputs (referenced, not all read raw):** client report (Dutch, 2026-06-10) + ChatGPT analysis screenshots; production sync log 2026-06-08 (a *successful* run, 1156 updated); debug.log excerpts 2026-06-10; WooCommerce product-list screenshot showing duplicate Gigaset products; `prd-wordpress-2026-06-10/prd.md` §4.0.

## Problem Statement
"Sync gedeeltelijk niet meer na de laatste update." Concrete reported symptoms: (a) no scheduled syncs run/appear; (b) images intermittently missing on new products; (c) duplicate products created in a run; (d) category renames/parent-moves not propagated; (e) connector `type` notice in debug.log; (f) HS Code attribute creation fails; (g) new products shown incomplete until a later phase.

---

## Confirmed Findings (evidence-graded)

### F1 — Connector `type` notice — CONFIRMED, non-fatal (log noise)
- 3.10.1's `register_connector()` calls `$registry->register()` **without** a `type` arg; WP 7.0.0 flags it via `_doing_it_wrong` ("vereist een niet-lege type string"). CONFIRMED via `git show 3.10.1:...connectors.php` (no `type` key) and client debug.log 2026-06-10 09:26.
- Working tree adds `'type' => 'service'` at `includes/class-skwirrel-wc-sync-connectors.php:106` — **uncommitted** (`git blame` → "Not Committed Yet 2026-06-10"). CONFIRMED.
- **Notice only**, does not abort PHP; token still resolves via legacy option fallback (`get_token()` `:76-81`). DEDUCED. → Not the cause of the sync stopping; fix it to silence the log and make the connector register cleanly.

### F2 — Scheduled syncs stopped — CONFIRMED root cause of the headline symptom
- The recurring job is armed **only** by `schedule()`, called from just two places: `register_activation_hook` (`skwirrel-pim-sync.php:62`) and settings-save (`class-skwirrel-wc-sync-admin-settings.php:82`). CONFIRMED.
- On normal load the scheduler is only **instantiated** (`class-skwirrel-wc-sync-plugin.php:104`), which re-adds the action handler + `cron_schedules` filter (`class-skwirrel-wc-sync-action-scheduler.php:27-30`) but does **not** call `schedule()`. CONFIRMED.
- There is **no upgrade hook** (`upgrader_process_complete` / db-version-gated reschedule) that re-arms the schedule. DEDUCED.
- **Conclusion:** a WordPress.org **auto-update does not fire the activation hook**, so after the 3.10.x update (or any AS action loss under WC 10.x) the recurring action is gone and nothing recreates it until the admin re-saves settings or reactivates. This is the exact signature of "geen 'gepland' meer." CONFIRMED (mechanism) / HYPOTHESIZED (which trigger lost it on this install — confirm via `wp_actionscheduler_actions` for hook `skwirrel_wc_sync_run`).
- Mutex ruled out: `SYNC_MUTEX` self-heals after `HEARTBEAT_TTL=60s` (`class-skwirrel-wc-sync-history.php:46`, `:113-120`) and `run_sync()` releases in `finally` (`class-skwirrel-wc-sync-service.php:892-898`). DEDUCED — a crashed run does not permanently block.

### F3 — Category rename/parent not synced — CONFIRMED
- In `find_or_create_category_term()`, a term matched by `_skwirrel_category_id` returns the existing `term_id` immediately with **no `wp_update_term()`** (`class-skwirrel-wc-sync-category-sync.php:350-360`; tree path `:136-145`). `grep wp_update_term` → 0 occurrences. CONFIRMED.
- Deletion works because it runs through the independent `Purge_Handler` seen-vs-stored diff, not this path. DEDUCED. Pre-existing logic gap, unrelated to 7.0.

### F4 — Images intermittently missing — CONFIRMED (primary) + contributing
- Image import is **Phase 4 (Media)**, after product creation (`class-skwirrel-wc-sync-service.php:703-779`). On an initial-delta run, `last_sync` is written **upfront** before any phase (`:280`). If the run aborts before Phase 4 (`check_abort` throws `:1573-1579`, caught `:879`, queue dropped `:882`), products from Phase 1 persist **without images** but the checkpoint already advanced → next delta won't refetch them → images **never backfilled**. CONFIRMED + DEDUCED. Matches "Gigaset created yesterday, still no images today."
- Contributing: per-image failures only `warning`-log and drop silently, no retry (`class-skwirrel-wc-sync-media-importer.php:69-103`). CONFIRMED.

### F5 — HS Code attribute creation fails — CONFIRMED non-fatal; root cause needs payload
- "Geef een naam op voor deze eigenschap" is **WooCommerce core's** `wc_create_attribute` empty-name error — string does not exist in the plugin. CONFIRMED.
- Plugin call sites all pass a fallback name (`class-skwirrel-wc-sync-taxonomy-manager.php:237-245`, upserter `:509/:1066/:1082/:1401/:1435`), so the empty name most likely comes from a feature whose code+label both `sanitize_title()` to empty (`taxonomy-manager.php:179-182`), or a WC 10.x strictness change. HYPOTHESIZED — needs the raw "HS Code" feature payload + surrounding debug.log. Error is swallowed (`:246-248`) → non-fatal.

### F6 — Phased, non-resumable architecture — CONFIRMED (structural enabler)
- `run_sync()` drains the queue per phase strictly in order: Fetch `:335-453` → P1 Products `:519-577` → P2 Taxonomy `:581-627` → P3 Attributes `:631-701` → P4 Media `:703-779` → P5 Relations `:783-825` → P6 Cleanup `:832-877`. CONFIRMED.
- Any interruption after P1 and before P4/P5 leaves a product **created but bare** (no category/attributes/images), and the queue is dropped with **no resume** (`:882`). CONFIRMED. This is the shared root under F4 and F7. The user's own suggestion — "process each new product fully on detection" — directly targets this.

### F7 — Duplicate products — CONFIRMED mechanism
- Upsert identity chain: Step2 `find_by_external_id` (`product-upserter.php:105`), Step3 `find_by_skwirrel_product_id` (`:110`). When both **miss**, `$is_new=true` (`:124`).
- It then finds the product by SKU (`wc_get_product_id_by_sku` `:126`) but, instead of reusing it, appends `-{skwirrel_product_id}` to mint a NEW unique SKU (`:129`) → **duplicate product** (e.g. `4250366870007-14768`). Same guard on the update path `:142-145`. CONFIRMED.
- **Why the chain misses:** products oscillate between "simple" and "1-member-group → variation" representations (log: repeated "Group has 1 member, will sync as simple product"); the simple→variation path even blanks the old SKU (`set_sku('')` `:422/:1329`). Combined with partial runs (F6) leaving incomplete `_skwirrel_*` meta, the meta lookup misses and the SKU guard spawns suffixed/plain duplicates. CONFIRMED (mechanism) / HYPOTHESIZED (exact oscillation trigger per product — confirm via the "Dubbele SKU voorkomen" / "SKU conflict bij update" warnings in the failing-run log).

---

## Hypotheses
- **H1 (Open):** the lost schedule on this install was caused specifically by the WP.org auto-update not firing activation (vs AS table change). Confirm: inspect `wp_actionscheduler_actions` + AS failed-action log for `skwirrel_wc_sync_run`.
- **H2 (Open):** HS Code empty name comes from `sanitize_title()` reducing code+label to '' for a specific feature. Confirm: raw payload for the HS Code custom feature.
- **H3 (Refuted):** "stuck mutex blocks all future scheduled runs." Refuted — 60s heartbeat TTL + `finally` release.
- **H4 (Refuted):** "Connectors token resolution fails on 7.0, breaking auth." Refuted — 2026-06-08 run authenticated and updated 1156 products; `type` issue is a non-fatal notice; legacy fallback intact.

## Final Conclusion — confidence: HIGH (cluster), with 2 open data-gaps
"Sync broken after 7.0" = **F2 (scheduler not re-armed)** for the headline, layered on a **structural weakness (F6)** that produces **F4 (missing images)** and **F7 (duplicates)**. F1/F3/F5 are real but secondary (noise / narrow / non-fatal). The original Connectors-auth hypothesis is **refuted** as the breaker.

## Fix direction (by mechanism — for the dev cycle, out of scope here)
1. **F2 — re-arm scheduling on upgrade.** Add an upgrade routine (db-version-gated, or `upgrader_process_complete`) that calls `schedule()`; immediate client remediation: re-save settings / reactivate. *(highest impact, smallest change)*
2. **F6/F4/F7 — identity + resumability.** (a) Write `_skwirrel_external_id`/`_skwirrel_product_id` in Phase 1 atomically so later misses can't happen; (b) on `$is_new` SKU collision, **reuse** the SKU-matched product instead of minting a suffixed duplicate; (c) don't advance `last_sync` until the run completes all phases (or make runs resumable / process each product fully on detection).
3. **F1 — commit the `type => 'service'` fix** (already staged) + register cleanly.
4. **F3 — add `wp_update_term()`** for name/parent on the found-by-meta path.
5. **F5 — guard empty attribute names** before `wc_create_attribute`; capture payload to confirm.

## Reproduction / verification
- F2: on a staging 7.0 site, schedule a sync, trigger a plugin auto-update, observe the recurring AS action disappear and not return.
- F4/F7: force a timeout/OOM mid-run after Phase 1 on new products; observe image-less + duplicate-on-next-run.

## Next data needed
- A **failing-run log** (not the successful 2026-06-08 one) to confirm F2's trigger and surface F7's "Dubbele SKU" warnings.
- `wp_actionscheduler_actions` rows for `skwirrel_wc_sync_run`.
- Raw Skwirrel payload for the "HS Code" feature.
