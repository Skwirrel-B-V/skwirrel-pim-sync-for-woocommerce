---
stepsCompleted: [1, 2, 3, 4]
inputDocuments:
  - '_bmad-output/planning-artifacts/prds/prd-wordpress-2026-06-10/prd.md'
  - '_bmad-output/planning-artifacts/architecture.md'
  - '_bmad-output/planning-artifacts/ux-designs/ux-wordpress-2026-06-11/DESIGN.md'
  - '_bmad-output/planning-artifacts/ux-designs/ux-wordpress-2026-06-11/EXPERIENCE.md'
  - '_bmad-output/project-context.md'
---

# Skwirrel PIM sync for WooCommerce — Epic Breakdown

## Overview

This document decomposes the "Simple, Self-Diagnosing, Update-Proof" chapter (PRD FR-1–17 + NFRs,
Architecture D1–D7 + review refinements, UX DESIGN/EXPERIENCE spines) into implementable epics and
stories for the Developer agent. Current shipped version: **3.10.2**.

> **⚠️ Read `## Breakdown Revisions v2` at the end of this document — it is authoritative.** It records
> the story splits, new stories, sequencing moves, the Global Definition of Done, and the AC tightenings
> folded in from the 2026-06-12 party-mode review (PM/Engineer/Test-Architect). Where it amends a story
> below, the revision wins.

## Requirements Inventory

### Functional Requirements

- **FR-1:** Guided first-run setup (ordered Connect → Verify → Essentials → First sync flow for new installs).
- **FR-2:** Sensible defaults so a freshly connected install syncs correctly without touching advanced options (purge OFF by default).
- **FR-3:** Intent-grouped settings (Connection / What to sync / How it looks / Advanced) with visible "setting relations" (inactive fields dim + show a one-line reason).
- **FR-4:** Self-service Health Check — plain-language verdict over connection, last-sync, config sanity, environment; bounded time; never crashes the admin.
- **FR-5:** Fault Attribution — states whether a problem is "ours" (Skwirrel) or "environment", always citing evidence.
- **FR-6:** Conflict detection — curated, data-driven signatures (image/media optimizers, caching, permalinks) name the conflicting component + affected capability.
- **FR-7:** Exportable Diagnostics Report with Environment Snapshot, one action, token redacted.
- **FR-8:** Surfaced sync state & history in plain language (trigger, outcome, counts) without opening log files.
- **FR-9:** Compatibility self-check against running WP/WC (below-minimum warning; untested-version notice; 7.0-primary/6.9-floor posture).
- **FR-10:** Safe degradation, not breakage — incompatibility/abort halts safely (no partial-write corruption); admin shows "paused (reason)" + how to resume.
- **FR-11:** Connectors API as the forward credential path with legacy `skwirrel_wc_sync_auth_token` fallback; token never exported. *(Adapter already exists; formalize resolve-with-fallback contract.)*
- **FR-12:** Delta correctness regression guard — delta touches only changed products (zero when nothing changed). **Not a speed target** (performance deferred).
- **FR-13:** Syncs work on WordPress 7.0 — ✅ **MET in 3.10.2** (F1/F2/F3 + documents fix). Carries a non-regression obligation for the rewrite.
- **FR-14:** Regression coverage for the WP 7.0 breaks — ◐ **PARTIAL**; remaining = an automated documents-path (`is_valid_path`) WP-7.0 integration test.
- **FR-15:** Clickable result counts → deep-link to affected products AND category-structure changes (native WC filtered lists via `?skwirrel_run=…`).
- **FR-16:** Preflight / preview before sync (dry-run) forecasting products (add/change/remove) AND category structure (created/renamed/removed-or-orphaned + re-homed counts); count+list depth (no per-field diff).
- **FR-17:** Opt-in "Start over / Clean all" reset — Skwirrel-scoped, never automatic, preview + "I understand" checkbox, Trash (recoverable).

### NonFunctional Requirements

- **NFR-1 (Read-only invariant):** Health Check + Preflight must never mutate products/settings or trigger a sync. FR-17 reset is the single gated destructive exception. Enforced via a runtime write-guard + a phpstan/phpcs sniff.
- **NFR-2 (No destructive defaults):** purge/reset stay opt-in; mid-run failure must not leave partial/corrupt catalog state.
- **NFR-3 (Quality gates):** pest (unit) + phpstan L6 + phpcs (WPCS) green before release; wp-env integration coverage for real-WP behavior; diagnostics/compat are priority test targets.
- **NFR-4 (Security):** auth token never present in any diagnostics export (extends the settings-export rule).
- **NFR-5 (Performance — DEFERRED):** no wall-clock budget this chapter; design for correctness + resumability. (Out of scope.)
- **NFR-6 (Compatibility posture):** WP 7.0+ primary / 6.9+ floor; WC 8.0+ (9.6+ brands); HPOS-compatible; Connectors-forward with legacy fallback.
- **NFR-7 (Accessibility & i18n):** new strings translatable (text domain `skwirrel-pim-sync`, English source); `manage_woocommerce` gating; WP-admin conventions; status never colour-only; honors reduced-motion.
- **NFR-8 (Observability):** `Skwirrel_WC_Sync_Logger` remains the substrate; diagnostics surface it; Health is read-only/side-effect-free.

### Additional Requirements (from Architecture D1–D7 + review refinements)

- **AR-A (Test harness FIRST):** a recording-`$wpdb` fake, injectable **crash** seam (`before_ledger_mark`) and **clock** seam, and a real-ledger integration harness — the no-writes/idempotency/crash-resume tests depend on this. Build before the core.
- **AR-B (D3 Identity Resolver):** single `Skwirrel_WC_Sync_Identity_Resolver` owning upsert-key precedence (ext→internal→manufacturer→product_id); writes identity meta first; on SKU collision **reuses** the matched product (no suffixed duplicates); simple↔variation = two one-way gated transitions (never delete-recreate). Fixes F7.
- **AR-C (D1 Resolver + Change_Set):** `Resolver` builds an immutable `Change_Set` (products + category structure); read-only. `Committer` is the sole writer.
- **AR-D (D2 Committer + Run_Ledger):** per-**entity**-atomic (simple product OR product group); ledger row = `(entity, phase)` with phases `RESOLVE→UPSERT_CORE→MEDIA→RELATIONS/VARIATION_ASSEMBLY`; atomic claim (`UPDATE … WHERE idempotency_key=… AND status IN(pending,failed) AND claimed_at IS NULL`); stale-claim reaper; `last_sync` advances only when ledger drains; `wc_delete_product_transients()` after assembly.
- **AR-E (Variable/grouped assembly):** group is the atomic unit; members park `pending_assembly`, never committed as simple provisionally; variation membership committed only in the deferred pass with the parent present.
- **AR-F (D4 per-run marker):** `_skwirrel_last_run_id` on every touched product AND term; `run_id` allocated once per run, persisted in the ledger header, read back by continuations (same value as FR-15 deep-link key).
- **AR-G (D7 storage):** preview `Change_Set` in a per-user transient (display-only; **commit always re-resolves**); history extends `skwirrel_wc_sync_history`.
- **AR-H (D5 Health engine):** `Health_Check` runner over a registry of check objects; conflict signatures as data; report serialized minus secrets; checks read bounded queries (no full hydration).
- **AR-I (D6 Compatibility guard):** gates sync entry; fail-safe (unknown env → paused, never sync-anyway); dependency-free (must not hydrate products); owns the "paused (reason)" state.
- **AR-J (Migration phased→ledger):** on the upgrade hook, fence via `skwirrel_wc_sync_migrating` (a D6 pause reason); **void in-flight phased state + set `skwirrel_wc_sync_force_full_sync`** (don't migrate a non-resumable corpse); schema migration forward-only/additive with a `schema_gen` tag; downgrade ⇒ documented full-resync.
- **AR-K (5 non-negotiable tests + canary — release gate):** (1) resolver idempotency property; (2) crash-resume golden-state over every commit boundary; (3) variable-assembly crash-between-parent-and-variations; (4) migrate-mid-run duplicate-key; (5) read-only write-guard wrapping preflight+health. Plus the duplicate-key canary (`_skwirrel_external_id` GROUP BY HAVING COUNT>1 = empty) at the end of every integration test.
- **AR-L (Brownfield constraints):** no autoloader (register new class in `skwirrel-pim-sync.php` + `Skwirrel_WC_Sync_Plugin`); WPCS class/file naming; singletons; HPOS-safe WC CRUD; reuse `_skwirrel_*` meta contracts; don't zero-out prices.

### UX Design Requirements

- **UX-DR1:** Document the existing `.skw-dashboard` design system as the DESIGN.md token contract (colors/typography/radius/spacing/components from `assets/dashboard.css`); new surfaces reuse it. Reserve red fills for destructive actions only.
- **UX-DR2:** Hub IA — keep the action-block grid; add a **Health & Diagnostics** block carrying a **standing verdict badge** (healthy/warning/problem) that re-runs on open.
- **UX-DR3:** **Health & Diagnostics screen** — overall verdict + per-check rows (status pill + plain verdict + attribution chip ours/environment/undetermined + Details disclosure) + conflict items + "Copy report for support".
- **UX-DR4:** **change_set_table** component — shared by Preflight/Result/Reset; Products block (added/changed/removed counts as deep-links) + Category-structure block (created/renamed old→new/removed-or-orphaned with "re-homes N products"; subtree nested). Counts use tabular-nums.
- **UX-DR5:** **progress_ledger** component — resumable per-item "X of Y products · resumable" with Resume affordance + paused (warning) variant + abort; **retires the 7-phase banner**; AJAX poll, JS-off fallback.
- **UX-DR6:** **Settings** reworked into four intent groups (Connection / What to sync / How it looks / Advanced) with relation-disabled fields dimmed + reason line (FR-3).
- **UX-DR7:** **Preflight-as-a-step** in the Sync-Now flow (forecast → Commit/Cancel); commit re-resolves if catalog changed.
- **UX-DR8:** **Reset flow** (FR-17) — Danger Zone, change-set preview + required "I understand" checkbox + danger button; Trash/recoverable copy; scope = Skwirrel products only.
- **UX-DR9:** **Guided Setup** (FR-1) first-run flow replacing the hub: Connect (live-verify gate) → Essentials (defaults pre-filled) → First sync (offers preflight); dismissable, never returns.
- **UX-DR10:** **Deep-link out** — `?skwirrel_run=…` query var on native WC product list + Products→Categories (FR-15); removed → Trash view.
- **UX-DR11:** **Accessibility floor** — keyboard + visible focus ring, focus-trapped modals (Esc), status never colour-only (icon+label), plain-language primary with technical behind Details, honor reduced-motion, descriptive link text for counts.
- **UX-DR12:** **Voice/microcopy** — outcome-first plain language; one next step; honest evidence-cited attribution; explicit destructive-scope copy; exact tabular numbers.

### FR Coverage Map

- FR-1 → Epic 4 (guided first-run setup)
- FR-2 → Epic 4 (sensible defaults)
- FR-3 → Epic 4 (intent-grouped settings + relations)
- FR-4 → Epic 3 (Health Check)
- FR-5 → Epic 3 (fault attribution)
- FR-6 → Epic 3 (conflict detection)
- FR-7 → Epic 3 (diagnostics report)
- FR-8 → Epic 2 (plain-language sync result + history surfacing)
- FR-9 → Epic 3 (compatibility self-check)
- FR-10 → Epic 3 (safe degradation) — minimal "paused" flag seeded in Epic 1
- FR-11 → Epic 3 (Connectors-forward credentials; adapter exists)
- FR-12 → Epic 1 (delta correctness regression guard)
- FR-13 → Epic 1 (non-regression obligation; shipped in 3.10.2)
- FR-14 → Epic 1 (documents-path 7.0 integration test)
- FR-15 → Epic 2 (result deep-links to products + categories)
- FR-16 → Epic 2 (preflight forecast)
- FR-17 → Epic 2 (start-over reset)

**NFR / cross-cutting:** NFR-1 read-only (Epic 1 write-guard; consumed by 2/3) · NFR-2 no destructive defaults (Epic 2 reset, Epic 4 defaults) · NFR-3 gates (all) · NFR-4 token security (Epic 3 report) · NFR-5 performance **deferred/out of scope** · NFR-6 compatibility (Epic 1/3) · NFR-7 a11y/i18n (Epics 2/3/4) · NFR-8 observability (Epic 3).

## Epic List

### Epic 1: Reliable, resumable, duplicate-free syncs (the new sync core)
Syncs stop stranding images, duplicating products, and breaking on interruption; an interrupted sync resumes cleanly and never corrupts the catalog. Delivers the per-entity-atomic rewrite end-to-end (test harness → identity resolver → resolver/Change_Set → committer/work-ledger → variable assembly → per-run marker → phased→ledger migration → the 5 non-negotiable tests), including the live resumable-progress UI that retires the 7-phase banner.
**FRs covered:** FR-12, FR-13 (non-regression), FR-14 · **ARs:** A–L · **UX:** UX-DR5
*Foundation — enables Epic 2.*

### Epic 2: See and control what a sync changes (control & visibility)
Preview exactly what a sync will add/change/remove — products AND category structure — before committing; click result counts to land on the affected products/categories in WooCommerce; safely "start over" when wanted.
**FRs covered:** FR-16, FR-15, FR-8, FR-17 · **UX:** UX-DR4, UX-DR7, UX-DR8, UX-DR10, UX-DR12
*Builds on Epic 1's resolver / Change_Set / run-marker.*

### Epic 3: Self-diagnosis & safe updates ("is it us or the environment?")
A non-technical owner runs a Health Check, gets a plain verdict with honest fault attribution + named conflicts + a copy-for-support report, and trusts that WP/WC updates won't silently break the sync (it pauses safely instead).
**FRs covered:** FR-4, FR-5, FR-6, FR-7, FR-9, FR-10, FR-11 · **UX:** UX-DR2, UX-DR3, UX-DR11
*Loosely coupled — parallelizable after Epic 1 seeds a minimal "paused" flag.*

### Epic 4: Legible setup & settings (first-run + configuration)
A new user configures correctly the first time via a guided flow with sensible defaults, and the ongoing settings surface is grouped and self-explaining (inactive settings say why).
**FRs covered:** FR-1, FR-2, FR-3 · **UX:** UX-DR6, UX-DR9
*Largely standalone / parallel; "first sync" optionally offers Epic 2's preflight.*

---

## Epic 1: Reliable, resumable, duplicate-free syncs (the new sync core)

Deliver the per-entity-atomic sync rewrite so interrupted runs resume cleanly and never strand
images, duplicate products, or corrupt the catalog. Stories are dependency-ordered; the test harness
comes first because the core's invariants are proven by tests, not by faith.

### Story 1.1: Test harness, seams & duplicate-key canary

As a developer,
I want a recording-`$wpdb` fake plus injectable crash and clock seams and a real-ledger integration base,
So that the sync core's no-writes, idempotency, and crash-resume invariants can be proven mechanically.

**Acceptance Criteria:**

**Given** the unit bootstrap (`tests/bootstrap.php`)
**When** a test binds the recording `$wpdb`
**Then** every `query/insert/update/delete` and `update_post_meta`/`wp_*_term`/`wp_*_post` is captured (or throwable on demand)
**And** a reusable `expectNoWrites()` helper passes only when zero writes occurred.

**Given** the orchestrator
**When** a test enables the **crash seam** (`before_ledger_mark`) and the **clock seam** (injectable `now()`)
**Then** a crash can be triggered synchronously at a named commit boundary, and timestamps are deterministic (no real `time()` in tested logic).

**Given** any integration test
**When** it finishes
**Then** the reusable **duplicate-key canary** asserts `SELECT meta_value,COUNT(*) … _skwirrel_external_id … HAVING COUNT(*)>1` returns empty.

**And** pest + phpstan L6 + phpcs stay green; new classes registered in `skwirrel-pim-sync.php` + `Skwirrel_WC_Sync_Plugin`.

### Story 1.2: Identity Resolver (kills duplicate products / F7)

As a store owner,
I want product identity resolved one consistent way with SKU collisions reused instead of duplicated,
So that re-syncs and edge cases stop creating duplicate products like `4250366870007-14768`.

**Acceptance Criteria:**

**Given** `Skwirrel_WC_Sync_Identity_Resolver`
**When** it resolves a product
**Then** it applies the fixed precedence `external_id → internal_product_code → manufacturer_product_code → product_id` and writes identity meta (`_skwirrel_external_id`/`_skwirrel_product_id`) first.

**Given** a product whose identity meta misses but whose SKU already exists
**When** identity is resolved
**Then** the existing SKU-matched product is **reused** (never a suffixed/new SKU minted).

**Given** a product that must change simple↔variation
**When** the resolver is asked to transition it
**Then** only a **one-way, parent-present** transition is permitted; delete-recreate is forbidden.

**And** `IdentityResolverTest` proves "SKU collision reuses matched id, never suffixes" (fails before, passes after).

### Story 1.3: Resolver + immutable Change_Set (read-only)

As a developer,
I want a `Resolver` that builds an immutable `Change_Set` (products + category structure) without writing,
So that preflight and commit share one resolution path and the forecast can never drift from reality.

**Acceptance Criteria:**

**Given** `Skwirrel_WC_Sync_Resolver` and `Skwirrel_WC_Sync_Change_Set`
**When** `resolve($mode)` runs
**Then** it returns an immutable value object holding scalars/arrays (never a `WC_Product`) with product ops `create|update|remove` and category ops `create|rename|remove|orphan` (+ re-homed counts), `run_id`, `mode`.

**Given** a resolve pass
**When** it executes against the recording `$wpdb`
**Then** `expectNoWrites()` passes (read-only).

**Given** state immediately after a commit
**When** `resolve()` runs again on unchanged upstream data
**Then** it returns a no-op/REUSE change-set (**resolver idempotency property test** — AR-K #1).

### Story 1.4: Read-only enforcement (runtime guard + static sniff)

As a developer,
I want writes structurally confined to the Committer/Reset paths,
So that the read-only invariant is enforced, not just promised.

**Acceptance Criteria:**

**Given** a runtime write-guard
**When** any code outside `Committer`/`Reset_Service` attempts `->save()`/`wp_insert_post`/`wp_update_post`/`update_*_meta`/`wp_set_object_terms`
**Then** it throws under read-only mode (test bootstrap wraps resolve/preflight/health in that mode).

**Given** the quality gate
**When** phpstan/phpcs runs
**Then** a sniff forbids write-family calls inside resolver/health-namespaced files (static backstop).

### Story 1.5: Committer + Run_Ledger (entity,phase) with atomic claim

As a store owner,
I want each product committed atomically and the run recorded in a resumable ledger,
So that an interrupted sync never leaves bare/partial products and resumes exactly where it stopped.

**Acceptance Criteria:**

**Given** `Skwirrel_WC_Sync_Run_Ledger` extending the Queue table
**When** a run starts
**Then** rows are keyed `(entity, phase)` with phases `RESOLVE→UPSERT_CORE→MEDIA→RELATIONS/VARIATION_ASSEMBLY`, columns `status(pending|running|done|failed)`, `attempts`, `claimed_at`, `idempotency_key=sha1(run_id:product_id:phase)`.

**Given** concurrent workers (AS double-fire / loopback retry)
**When** a row is claimed
**Then** the atomic `UPDATE … WHERE idempotency_key=… AND status IN(pending,failed) AND claimed_at IS NULL` yields affected-rows 1=own / 0=skip; a stale-claim reaper resets `running` rows older than N minutes.

**Given** the `Committer`
**When** it commits a product
**Then** `UPSERT_CORE` writes post+meta atomically and stamps `_skwirrel_synced_at`; `MEDIA` keys on `_skwirrel_url_hash` and skips on re-fire; `last_sync` advances **only** when the ledger fully drains.

**Given** a delta with no upstream changes
**When** it runs
**Then** it touches **zero** products (FR-12 delta-correctness guard).

**Given** a crash at any commit boundary (crash seam)
**When** the run resumes
**Then** final state is byte-identical to an uninterrupted run (**crash-resume golden-state test** — AR-K #2).

### Story 1.6: Variable / grouped-product assembly

As a store owner,
I want variable products assembled as a unit after their members exist,
So that grouped products never flicker between simple and orphaned-variation across runs.

**Acceptance Criteria:**

**Given** a product known to be a group member
**When** the per-product pass runs
**Then** it is parked `pending_assembly` and **never** committed as a simple product provisionally.

**Given** all of a group's members have `UPSERT_CORE = done`
**When** the deferred VARIATION_ASSEMBLY pass runs
**Then** the parent is ensured, variations created/updated in place (never delete-recreate), axes set, removed variations pruned, and `wc_delete_product_transients($parent_id)` is called.

**Given** a crash between "parent committed" and "variations assigned"
**When** the run resumes
**Then** the orphaned shell reconciles (no duplicate, no stranded variation) (**variable-assembly crash test** — AR-K #3).

### Story 1.7: Per-run marker & run header

As a store owner,
I want every product and category a run touched tagged with that run's id,
So that I can later see exactly what a given sync changed.

**Acceptance Criteria:**

**Given** a run
**When** it starts
**Then** a single `run_id` is allocated once, persisted in the ledger header, and read back by every continuation (never re-minted).

**Given** the Committer commits a product or category term
**When** it writes
**Then** it stamps `_skwirrel_last_run_id` on that product AND term (same value used by FR-15 deep-links).

### Story 1.8: Resumable progress UI (retire the 7-phase banner)

As a store owner,
I want a clear "X of Y products · resumable" progress view during a sync,
So that I understand progress and can resume an interrupted run.

**Acceptance Criteria:**

**Given** a running sync
**When** I view the dashboard
**Then** the `progress_ledger` shows committed/total (tabular-nums) + current activity, replacing the old 7-phase list, and polls via AJAX.

**Given** an interrupted run
**When** I return to the dashboard
**Then** it shows "Paused at N/Total" with a **Resume** affordance; with JS off it degrades to a static "in progress — refresh" view.

**And** the UI uses the existing `.skw-*` components/tokens (UX-DR5); status is never colour-only.

### Story 1.9: Phased→ledger migration (safe upgrade)

As a store owner upgrading the plugin,
I want a mid-run upgrade to never corrupt my catalog,
So that updating is a non-event.

**Acceptance Criteria:**

**Given** the upgrade hook (db-version-gated)
**When** the new core activates
**Then** it sets `skwirrel_wc_sync_migrating` (a pause reason), **voids in-flight phased state**, sets `skwirrel_wc_sync_force_full_sync`, and applies a forward-only/additive schema migration tagging legacy rows `schema_gen` (ignored, not interpreted).

**Given** an install with a half-drained old-core ledger
**When** it is migrated and the next sync runs
**Then** the duplicate-key invariant holds and the catalog converges to a clean full sync (**migrate-mid-run test** — AR-K #4).

**And** downgrade is documented as "requires a full resync".

### Story 1.10: WP 7.0 documents-path regression test (FR-14)

As a store owner on WP 7.0 / WooCommerce 10.x,
I want product documents/downloads to keep attaching,
So that the 3.10.2 fix can never silently regress.

**Acceptance Criteria:**

**Given** a WP 7.0 + WC 10.x integration environment
**When** a sync runs for a product with downloadable documents
**Then** the uploads dir auto-approves via `is_valid_path()` and the files attach (asserted), with zero "Downloadable files save failed" warnings.

---

## Epic 2: See and control what a sync changes (control & visibility)

Give the store owner full insight and control over structural and destructive operations — preview
before commit, click results into WooCommerce, and start over safely. Builds on Epic 1.

### Story 2.1: Change-set presentation component

As a store owner,
I want one consistent way to see product and category changes,
So that "before" (preflight), "after" (result), and reset previews all speak the same language.

**Acceptance Criteria:**

**Given** a `Change_Set`
**When** it renders as a `change_set_table`
**Then** a **Products** block shows added/changed/removed counts (tabular-nums) and a **Category structure** block shows created / renamed (old→new) / removed-or-orphaned with a "re-homes N products" sub-count; whole-subtree deletes render nested.

**And** removed products and category removals are visually emphasised (warning) as highest-risk; it reuses `.skw-*` tokens.

### Story 2.2: Preflight before sync (FR-16)

As a store owner,
I want to preview exactly what a sync will change before committing,
So that I never discover a mass change or purge after the fact.

**Acceptance Criteria:**

**Given** the Sync-Now flow
**When** I choose **Preview**
**Then** the Resolver runs read-only and renders the `change_set_table` for products AND category structure (reflecting current settings: delta/full, collection filter, purge on/off).

**Given** a preview was shown
**When** I click **Commit**
**Then** the sync **re-resolves** (preview is display-only, never the commit input) and proceeds; **Cancel** writes nothing.

**And** the preview pass passes the read-only write-guard (no products/categories/`_skwirrel_synced_at` mutated).

### Story 2.3: Result deep-links to affected products & categories (FR-15)

As a store owner,
I want the result counts to be clickable,
So that I can see exactly which products/categories a sync added, changed, or removed.

**Acceptance Criteria:**

**Given** a finished run's result
**When** I click "added (N)" / "changed (M)" / "removed (K)"
**Then** the native WC product list opens filtered by `?skwirrel_run={run_id}` to exactly those items; "removed" opens the Trash view; category changes link to Products→Categories.

**Given** a count of zero
**When** the result renders
**Then** it is plain text, not a dead link.

### Story 2.4: Plain-language result & history (FR-8)

As a store owner,
I want recent sync outcomes in plain language,
So that I never have to open WooCommerce log files to know what happened.

**Acceptance Criteria:**

**Given** a completed sync
**When** I view the dashboard
**Then** the status card states outcome + timestamp + counts in plain language, and the history table shows trigger (manual/scheduled/purge), outcome badge, and counts (tabular-nums).

**Given** a failed sync
**When** I view it
**Then** a plain-language reason and the one next step are shown (no stack trace as primary content).

### Story 2.5: Start over / clean all reset (FR-17)

As a store owner,
I want an explicit, previewed "start over" that only removes Skwirrel products,
So that I can reset to a clean slate without fear of touching my other products.

**Acceptance Criteria:**

**Given** `Skwirrel_WC_Sync_Reset_Service` invoked from the Danger Zone
**When** I open it
**Then** it shows a change-set preview ("removes all N Skwirrel products to Trash — recoverable; your other products are untouched") and requires a checked "I understand" box before the danger button enables.

**Given** I confirm
**When** the reset runs
**Then** it removes **only** products carrying `_skwirrel_external_id`/`_skwirrel_product_id` (to Trash), never others, and is never triggered automatically.

**And** the integration test asserts only Skwirrel-owned products are removed.

---

## Epic 3: Self-diagnosis & safe updates ("is it us or the environment?")

Let a non-technical owner answer "is it broken, and is it us?" themselves, and make WP/WC updates safe.
Loosely coupled to the core; parallelizable after Epic 1 seeds the pause flag.

### Story 3.1: Health Check engine & runner (FR-4)

As a store owner,
I want a one-click health check that never crashes my admin,
So that I can see whether the plugin is healthy at any time.

**Acceptance Criteria:**

**Given** `Skwirrel_WC_Sync_Health_Check` over a registry of check objects
**When** I run it (capability `manage_woocommerce`)
**Then** it returns an overall status (healthy/warning/problem) + per-check `{status, plain verdict, evidence, attribution}` within a bounded time, read-only.

**Given** a single check throws
**When** the run completes
**Then** that check degrades to `undetermined` (with reason) and the page never white-screens.

### Story 3.2: Core health checks (connection, schedule, environment, last-sync)

As a store owner,
I want the health check to cover the things that actually break,
So that real problems surface in plain language.

**Acceptance Criteria:**

**Given** the registry
**When** the check runs
**Then** it includes: connection/API reachability; **schedule armed?** (guards the F2 class); environment/version range (WP/WC/PHP vs 7.0-primary/6.9-floor, FR-9); and last-sync outcome.
**And** each check reads bounded queries (COUNT/EXISTS/sampled) — never full catalog hydration.

### Story 3.3: Fault attribution (FR-5)

As a store owner,
I want the verdict to say whether a problem is Skwirrel's or my environment's,
So that I don't waste a support round-trip blaming the wrong thing.

**Acceptance Criteria:**

**Given** a detected symptom with a healthy Skwirrel connection/config
**When** the verdict renders
**Then** it attributes likely cause to the environment and names the component when detectable; when the plugin is at fault it says so and links the relevant setting.
**And** every non-`undetermined` attribution cites the signal it is based on.

### Story 3.4: Conflict detection (FR-6)

As a store owner,
I want known plugin conflicts detected and named,
So that I learn which other plugin is implicated.

**Acceptance Criteria:**

**Given** a data-driven conflict-signature registry (seeded: image/media optimizers, caching/performance, permalink/SEO plugins)
**When** the health check runs
**Then** a detected conflict names the component + the affected capability (images/permalinks/variations); absence yields "no known conflicts detected" stated as *not a guarantee*.
**And** the registry is extensible via a `skwirrel_wc_sync_conflict_signatures` filter.

### Story 3.5: Diagnostics report export (FR-7)

As a store owner,
I want to copy/export a diagnostics report in one action,
So that I can hand support a precise, safe report.

**Acceptance Criteria:**

**Given** a health verdict
**When** I click "Copy report for support" / download
**Then** the report includes WP/WC/PHP versions, active plugins, theme, server limits, plugin version, and the verdict — and **never** the auth token (redacted).

### Story 3.6: Health screen & hub verdict badge

As a store owner,
I want health visible at a glance and readable on its own screen,
So that problems surface without my digging.

**Acceptance Criteria:**

**Given** the hub
**When** it renders
**Then** the **Health & Diagnostics** action block carries a standing verdict badge (healthy/warning/problem) from the last check and re-runs on open.

**Given** the Health screen
**When** it renders
**Then** it shows the overall verdict + per-check rows (status pill + plain verdict + attribution chip + Details disclosure) + conflict items + copy-report; keyboard-navigable, status never colour-only, technical detail behind Details (UX-DR3/UX-DR11).

### Story 3.7: Compatibility guard & safe degradation (FR-9/FR-10)

As a store owner,
I want the sync to pause safely instead of breaking when the environment is incompatible,
So that updating WordPress/WooCommerce never corrupts my catalog.

**Acceptance Criteria:**

**Given** `Skwirrel_WC_Sync_Compatibility_Guard` gating sync entry
**When** the environment is unsupported, mid-update, or migrating
**Then** the sync enters an explicit "paused (reason)" state (status card warning + how to resume), never a fatal error; Sync Now is disabled with that reason.

**Given** an unknown/unparseable environment
**When** the guard evaluates
**Then** it **fails safe** (paused), never sync-anyway, and does not hydrate products (dependency-free).

**And** a WP/WC update produces no fatal error on any plugin admin screen; no partial-write corruption (per Epic 1 atomicity).

### Story 3.8: Connectors-forward credential contract (FR-11)

As a store owner,
I want credentials resolved via the WP 7.0 Connectors path with a 6.9 fallback,
So that token management is future-proof and never leaks.

**Acceptance Criteria:**

**Given** WP 7.0+
**When** the token resolves
**Then** it uses the Connectors path; on WP 6.9 it falls back to `skwirrel_wc_sync_auth_token`; neither path exposes the token in any export.
**And** a unit test exercises both resolution paths.

---

## Epic 4: Legible setup & settings (first-run + configuration)

Make first configuration foolproof and the ongoing settings surface self-explaining. Largely
standalone/parallel; "first sync" optionally offers Epic 2's preflight.

### Story 4.1: Intent-grouped settings (FR-3)

As a store owner,
I want settings grouped by purpose,
So that I can find and understand them without fear.

**Acceptance Criteria:**

**Given** the settings screen
**When** it renders
**Then** every setting belongs to exactly one of four groups — **Connection** (token, endpoint) · **What to sync** (categories, brands, grouped products, selections, collections) · **How it looks** (images, slugs/permalinks, language) · **Advanced** (timeout, retries, batch size, purge, verbose) — using the existing `.skw-*` form components.

### Story 4.2: Visible setting relations (FR-3)

As a store owner,
I want a setting that has no effect to say so,
So that I'm never confused by silently-inert options.

**Acceptance Criteria:**

**Given** setting B has no effect because setting A is off
**When** the settings screen renders
**Then** B is shown dimmed/inactive with a one-line "Inactive because *{A}* is off" reason rather than disappearing.
**And** no setting's meaning depends on undocumented interaction.

### Story 4.3: Sensible defaults (FR-2)

As a new store owner,
I want defaults that just work,
So that a freshly connected install syncs without touching advanced options.

**Acceptance Criteria:**

**Given** only a valid token is provided
**When** a sync runs
**Then** it completes successfully using defaults; no default enables a destructive action (purge OFF), matching the documented settings tables.

### Story 4.4: Guided first-run setup (FR-1)

As a new, non-technical store owner,
I want a step-by-step setup instead of a wall of fields,
So that I configure correctly the first time without a developer.

**Acceptance Criteria:**

**Given** a fresh install with no valid configuration
**When** I open the plugin
**Then** Guided Setup renders (not the full settings table): **Connect** (paste token; cannot advance until verified live against Skwirrel) → **Essentials** (a few choices, sensible defaults pre-filled) → **First sync** (offers preflight if available).

**Given** a wrong token
**When** I try to advance
**Then** I see one plain-language error ("The token was rejected by Skwirrel") + the one next step, not a stack trace.

**Given** setup completes
**When** I return later
**Then** the hub renders and Guided Setup does not reappear (dismissable, gated on a stored flag).

---

## Breakdown Revisions v2 — folded in 2026-06-12 (authoritative)

Source: party-mode review (PM John · Senior Eng Amelia · Test Architect Murat). These amendments take
precedence over the story text above where they conflict. Net story count ≈ 27 → ≈ 39.

### Global Definition of Done (applies to EVERY story)
- **Class registration:** each new class is `require_once`'d in `skwirrel-pim-sync.php` AND instantiated/hook-wired in `Skwirrel_WC_Sync_Plugin`; a smoke test asserts the class loads (no activation fatal). *(phpstan passing ≠ plugin activates.)*
- **i18n:** new user-facing strings wrapped in `__()`/`esc_html__()` with text domain `skwirrel-pim-sync`; `.pot` regenerated; all 7 `.po`/`.mo` updated. A CI string-coverage check asserts no in-source string is missing from `.pot`.
- **Security:** every POST/AJAX endpoint is nonce-verified + `manage_woocommerce`; background endpoints also check the `skwirrel_wc_sync_bg_token` transient.
- **Gates:** pest + phpstan L6 + phpcs green; the duplicate-key canary runs at the END of every integration test.

### Story splits
- **1.5 → 1.5a / 1.5b / 1.5c.** **1.5a** Run_Ledger table + DAO (`(run_id, entity_id, phase, status, attempts, claimed_at, idempotency_key)`, indexes on `(claimed_at)` and `(run_id,phase)`, dbDelta migration; AC: EXPLAIN shows the claim query is indexed). **1.5b** Committer-through-ledger (AC: idempotent re-commit = zero net writes). **1.5c** atomic claim + stale-claim reaper (TTL = a number, advanced via the clock seam) + **crash-resume golden-state as an enumerated boundary `dataset()`** — boundaries at minimum: after grouped-shell create · after each product UPSERT_CORE page · after category assignment · **after `skwirrel_wc_sync_last_sync` write but before history append** · after MEDIA. DoD names the boundary count.
- **1.9 → 1.9a / 1.9b.** **1.9a** idle-state phased→ledger migration (up/down, idempotent re-run). **1.9b** migrate-*during*-resume (old-format checkpoint resumed under the new schema; duplicate-key invariant holds).
- **3.7 → 3.7a / 3.7b.** **3.7a** compatibility guard + degradation matrix as an enumerated `with()` dataset (`{WC<min, WP<6.9, HPOS off, migrating}` → expected paused/degraded; unknown env → fail-safe paused); **AC rewritten** from the un-falsifiable "no future fatal" to "activation/upgrade routine is idempotent + fatal-free against fixtured stale option/schema state"; the WP/WC version matrix moves to **CI config**, not an AC. **3.7b** degradation UI surface (paused state + resume; reuses 1.8 components — declared, not a silent re-edit).
- **4.4 → 4.4a / 4.4b.** **4.4a** wizard shell + step routing + state persistence. **4.4b** live credential verify — reuses 3.8's Connectors/JsonRpc path; unit AC asserts request-shaping + response interpretation against a **mocked transport** (200+valid→green, 401→"bad token", timeout→"unreachable"); a genuinely-live check is a separate, non-gating `@live` smoke.
- **1.6** watch: if variable/grouped assembly fills a session on its own, split the assembly from its crash test; ensure 1.5c's reaper is generic so 1.6 doesn't reimplement claim logic.

### New stories
- **1.11 — Live-progress + abort AJAX endpoints.** `wp_ajax_skwirrel_wc_sync_progress` (reads the ledger) + `wp_ajax_skwirrel_wc_sync_abort`; nonce + cap + bg-token; **abort flips a ledger flag honored at the next phase boundary** (never a mid-write kill). Back-dependency: 1.5a. Powers 1.8's polling, the hub ambient running-state, AND the user-facing "stop a running sync" panic button.
- **1.12 — Clean uninstall & deactivate hygiene.** `uninstall.php` DROPs the ledger table and deletes new options (run-id markers, migration flags) + run-id post/term meta. On **deactivate** (≠ uninstall): in-flight ledger left resumable, AS jobs for the active run cancelled cleanly, bg-token transient cleared. AC: install → run → uninstall → assert table + options + meta gone.
- **1.13 — No-orphaned-variation invariant suite** *(cross-cutting; spans 1.5/1.6/2.5)*. Post-condition after ANY sync/reset path: no `WC_Product_Variation` without an existing parent; no variable parent with zero variations after a completed run.
- **1.14 — Regression-canary suite.** One Pest file pinning already-fixed bugs so they can't silently return: delta-checkpoint (3.10.1), scheduler re-arm (F2), connector type (F1), category rename (F3), object-cache bust on settings save (3.9.1), and **don't-zero-out missing prices** (ERP-price client rule).
- **1.15 — Upgrade-from-3.10.2 smoke.** Seed real 3.10.2 on-disk fixtures (its option keys, queue-table schema, `_skwirrel_*` meta) → activate the new version → assert no fatal + data intact. Dependency: 1.9a. *(1.9 tests the mechanism; this tests the real prior state.)*

### Sequencing moves
- **Pull the schedule-armed health check (3.2 sub-check) and the paused/safe-degradation forward.** The minimal "paused" flag is already seeded in Epic 1 (1.9 fence); the schedule-arm check is prioritized at the FRONT of Epic 3 because it guards the founding F2 bug that started this chapter.
- **Connectors credential contract (3.8)** stays in Epic 3 (health uses it) but **4.4b explicitly depends on it**; if Epic 4 starts in parallel, 3.8 is implemented first.
- **1.4 write-guard seam precedes 1.3.** The runtime write-guard must exist as a test seam before 1.3 lands (so 1.3's idempotency test can assert against it); the phpstan/phpcs sniff half of 1.4 may follow.

### AC tightenings (apply to the named stories)
- **1.1:** crash seam = injectable `die_after($phase)` (not "kill the process"); clock seam = injectable `now()`. **1.1 is the upstream seam blocker for 3.2 (scheduler), 3.4 (plugin registry), 3.7 (option/schema state), 4.4b (HTTP transport)** — declare these as test-seams it must provide.
- **1.3:** AC = "Resolver produces a Change_Set with **exact write count 0**, verified by the 1.4 write-guard seam."
- **1.4:** the test asserts the guard **rejected/short-circuited the write call** (spy on the write boundary), not "no rows changed" (avoids false-greens).
- **1.5c / Epic 1 (highest-risk):** `skwirrel_wc_sync_last_sync` is written **only on provable completion**; a crash before completion leaves it untouched. *(Closes the silent delta-skip data-loss — the 3.10.1 bug class.)*
- **Purge gate:** purge is skipped unless the sync is **provably complete AND unfiltered** (guards mass-trashing on a short API page or an empty-parsed collection filter).
- **2.2 preflight:** nonce + cap; **zero writes** (write-guard armed); the forecast is **scoped within the active `collection_ids`** (a forecast that ignores the filter lies about what commit will touch).
- **2.5 reset:** nonce + cap + confirm; targets `_skwirrel_external_id`/`_skwirrel_product_id` scope **regardless of any active collection filter**, and **never** non-Skwirrel products. *(The subtlest scoping bug in the set.)*
- **1.2 + 2.4 (make the fix visible):** surface a plain-language "N duplicates reconciled / what changed" line in the result, so the identity-resolver win is provable to the user (the founding pain was invisible duplicates).
- **3.2:** include a **negative fixture that de-arms Action Scheduler** and asserts the schedule check reports RED (not just the happy path).
- **3.3 / 3.4:** ship **fixture tables** — input fault → expected attribution (3.3); an **injectable plugin-registry** + known-bad fixtures → expected conflict verdict (3.4). No real third-party plugins in CI.
- **Migration comms (1.9):** an explicit AC — the returning user is told, in plain language, what changed and that a re-sync is running (don't let the message ride invisibly on the mechanism).

### Implementation-order & cross-epic notes (no forward dependencies)
- **Implementation order within Epic 1 is by dependency, not by number.** The new stories were appended, so renumber-free ordering is: `1.1 → 1.2 → 1.4(write-guard seam) → 1.3 → 1.5a → 1.11(AJAX endpoints) → 1.5b → 1.5c → 1.6 → 1.7 → 1.8(progress UI, consumes 1.11) → 1.9a → 1.15(upgrade smoke) → 1.9b → 1.10 → 1.13/1.14(canary suites)`. Build 1.11 **before** 1.8; build the 1.4 write-guard seam **before** 1.3.
- **1.13 (no-orphaned-variation) is not a backward dependency on Epic 2.** It lands its **sync/assembly-path** coverage in Epic 1 (1.5/1.6 paths) where those stories already exist; the **reset-path** coverage is added as an AC extension on **2.5** when reset is built. Epic 1 never waits on Epic 2.
- **1.14 (regression-canary)** depends only on the already-shipped 3.10.x fixes (no in-epic forward dep); it can land any time after 1.1.
