# Deferred Work

Items surfaced during work but intentionally not done now. Each notes origin + why deferred.

## From WP 7.0 recovery review (spec-wp70-recovery, 2026-06-10)

- **Multisite: scheduled-sync re-arm is per-site.** `Skwirrel_WC_Sync_Action_Scheduler::maybe_upgrade_reschedule()` hooks `admin_init` and stores `skwirrel_wc_sync_version` as a per-site option. On a network-activated plugin updated network-wide, a subsite's lost schedule is only re-armed once an admin visits *that* subsite's wp-admin; subsites whose admin is never visited keep a stale/lost schedule. *Deferred:* the target installs are single-site; a network-admin / `wp_loaded` re-arm path is a separate, broader change. Revisit if a multisite client reports it.

## From WP 7.0 investigation (wp70-sync-break, 2026-06-10) — tracked for the Architecture step, NOT quick-dev

- **F4 / F6 / F7 — sync orchestrator hybrid rewrite.** Missing images (F4), phased non-resumable sync (F6), and duplicate products (F7) share one root: the phased, non-resumable orchestrator + checkpoint-before-completion + identity-resolution-misses. Agreed direction: per-product-atomic processing + thin deferred relations/variable-assembly pass, checkpoint per-product. **Deliberately deferred to the Architecture step** (after the PRD is finalized) — out of scope for the 3.10.2 recovery release. See `investigations/wp70-sync-break-investigation.md` Fix direction §2.
- **F5 — HS Code attribute empty-name.** Non-fatal (WC-core error, swallowed). Needs the raw Skwirrel "HS Code" feature payload + surrounding debug.log to confirm whether it's a `sanitize_title`→'' case or a WC 10.x strictness change. Small follow-up once payload is available.
