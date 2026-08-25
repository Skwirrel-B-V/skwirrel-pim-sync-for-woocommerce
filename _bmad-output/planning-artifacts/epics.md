---
stepsCompleted: [1, 2, 3, 4, 'ch2-step-01', 'ch2-reconciliation', 'ch2-step-02', 'ch2-step-03']
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

> **Revisions v3 — as-built re-baseline (folded in 2026-07-22, authoritative; supersedes the story
> text below where it conflicts).** Source: correct-course / `sprint-change-proposal-2026-07-22.md`.
>
> Epic 1 was **not** implemented as the planned `Resolver` / immutable `Change_Set` / `Run_Ledger` /
> `Committer` rewrite. Instead the existing **queue-based phase-cursor core** was hardened
> incrementally (per-product-atomic commits + draft-until-complete + content-hash change gate +
> `SyncMutex` + SKU-reuse), landing across releases 3.11.0→3.11.3. The user-facing outcomes
> (duplicate-free, no bare/partial products, delta-correct, resumable-enough) are met; the ledger
> architecture and its invariant-proving harness are not built and are **dropped**.
>
> **DONE (outcome achieved by the queue core):** 1.1 (harness — seams deferred), 1.2, 1.5b, 1.6,
> 1.8, 1.10, 1.11.
> **DROPPED (ledger-architecture-only, no consumer under as-built):** 1.4, 1.5a, 1.5c, 1.9a, 1.9b.
> **OPEN — slim Epic 1 remainder:**
> - **1.3** — reduced to a read-only **Change_Set forecast on the queue core** (feeds Epic 2 preflight / FR-16).
> - **1.7** — run header is done; remaining work is **stamping `_skwirrel_last_run_id` on each committed product + term** (feeds Epic 2 FR-15 deep-links).
> - **1.12** — clean uninstall/deactivate hygiene (no `uninstall.php` / deactivate hook exists yet).
> - **1.13** — WC-level no-orphaned-variation invariant suite (only queue-row orphans are tested today).
> - **1.14** — regression-canary suite: 4/6 pinned; still missing the object-cache-bust test and the real "don't zero-out missing prices" *behavior* test.
> - **1.15** — upgrade-from-3.10.2 activation smoke (activation/upgrade path is untested against real prior fixtures).

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

> **v3 re-baseline:** rescoped. The full `Resolver` split is dropped; deliver only a **read-only
> Change_Set forecast built on the queue-based core** (products + category ops, zero writes) so
> Epic 2 preflight (FR-16) has a forecast to render. No `Run_Ledger` dependency.

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

> **v3 re-baseline:** the run header (single `sync_run_id` allocated once, persisted in the queue
> table, read back by every continuation) is **done**. Remaining scope = **stamp
> `_skwirrel_last_run_id` on every committed product AND term** (the value FR-15 deep-links read).

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

---

# Chapter 2 — Client request (Jeroen) — folded in 2026-08-19

Source: client request from Jeroen (five items), fully elicited with Jos on 2026-08-19. This chapter is
additive to Chapter 1 (FR-1–17 / Epics 1–4). It introduces **Epic 5** (admin configuration & feedback)
and **Epic 6** (custom class field mapping), and **amends Chapter 1 where noted**.

## Chapter 2 — Requirements Inventory

### New Functional Requirements

- **FR-18:** **Custom class → stock quantity.** A configurable product-level custom-feature ID/code supplies a numeric stock quantity, written via `set_manage_stock(true)` + `set_stock_quantity()`. Applies to simple products **and** variations. A missing/empty value **never** overwrites existing WooCommerce stock. Rides the normal delta/full sync — no separate schedule.
- **FR-19:** **Custom class → product content.** Configurable product-level custom-feature IDs/codes supply **product title**, **short description** and **long description**. Each mapping is independent and optional (empty = off). The custom-class value **overrides** the existing source, falling back to the current chain when empty (title: `product_erp_description` → translations). Long description passes through `wp_kses_post`. Product slugs are **not** rewritten.
- **FR-20:** **Context ID.** An optional settings field whose value is sent as an API parameter on every JSON-RPC call. Empty = parameter omitted, so the API applies its own default (`1`). Changing it sets the existing `skwirrel_wc_sync_force_full_sync` flag, so the catalog can never end up a mix of two contexts.
- **FR-21:** **Test Connection results.** The connection test reports round-trip time, HTTP/JSON-RPC status, and total products found — proving not just reachability but that the configuration actually returns data.
- **FR-22:** **Required-field marking.** Required settings fields carry a visible `*` and `aria-required`; existing save-time validation errors render **inline next to the failing field** instead of only as a top-of-page notice. Conditionally-required fields show their marker only when the condition holds.
- **FR-23:** **Document links carry a readable, language-aware name.** A product document's link text is the human `product_attachment_title` for the configured language — resolved through the same `_attachment_translations` exact → prefix → first-entry chain images already use — instead of the raw `file_name`. The raw filename remains the last-resort fallback, so a document is never nameless.

### Amended Chapter 1 Requirements

- **FR-3 (amended):** intent-grouped settings are **delivered as tabs** — four intent groups (Connection / What to sync / How it looks / Advanced) **plus a fifth "Field mapping" tab** for FR-18/FR-19. Tabs are presentation-only over the existing single form: every field stays in the DOM and submits in one save. **Chapter 1 Story 4.1 is superseded by Epic 5's tabs story.** Epic 4 retains Stories 4.2, 4.3, 4.4.
- **UX-DR6 (amended):** the four intent groups become the tab set defined above.

### New Non-Functional Requirement

- **NFR-9 (Non-destructive field mapping):** a value absent from the PIM must never clear or zero data already present in WooCommerce. Extends the existing "don't zero-out prices" rule to stock (FR-18), to title/descriptions (FR-19), and to document link names (FR-23 — a missing translation falls back, never renders empty). Testable: sync a product whose mapped feature is missing/empty and assert the WooCommerce value is unchanged.

### New UX Design Requirements

- **UX-DR13: Tabbed settings navigation.** Presentation-only tabs over the existing single settings form (48+ inputs, 8 field groups re-homed into 4 intent groups + Field mapping). Requirements: all fields remain in the DOM and submit together (never split into per-tab option writes — `sanitize_settings()` validates **across** groups); deep-linking via `#tab-slug`; a tab containing a validation error is visibly marked; the first tab with an error auto-opens on load; keyboard-operable with visible focus (NFR-7); usable with JS off (no tab hides a field permanently).
- **UX-DR14: Required-field marking & inline errors.** `*` + `aria-required` on required fields; `add_settings_error()` messages rendered adjacent to their field; conditional markers (`custom_collection_id`, `super_category_id`) appear only when their condition holds. Must be tab-aware — an inline error on a collapsed tab is a regression against today's top-of-page notice.

### Chapter 2 FR Coverage Map

- FR-3 (amended) → **Epic 5** (tabbed settings; supersedes Story 4.1)
- FR-18 → **Epic 6** (custom class → stock)
- FR-19 → **Epic 6** (custom class → title / short / long description)
- FR-20 → **Epic 5** (Context ID)
- FR-21 → **Epic 5** (Test Connection metrics)
- FR-22 → **Epic 5** (required-field markers + inline errors)
- FR-23 → **Epic 6** (document link names)
- NFR-9 → **Epic 6** (enforced), **Epic 5** (no destructive default on the new fields)
- UX-DR13, UX-DR14 → **Epic 5**

### Open Items (must resolve before dev)

- **FR-20 — exact API parameter name for the Context ID is unconfirmed.** Jos to verify against the Skwirrel API docs. Known: it is an API parameter, optional, API default `1`. Blocks Epic 5's Context ID story only.

### Deliberately Out of Scope (recorded, not built)

- **Stock on a faster cadence** than the normal sync (stock-only sync job, or live stock at checkout). Decided: stock rides the existing sync. Revisit only against a concrete oversell problem.
- **Test Connection count honouring the configured collection IDs and Context ID.** Would catch the "connection fine, zero products sync" failure. Noted as a follow-up to FR-21.
- **Trade-item-level custom classes** as a mapping source. FR-18/FR-19 are product-level only.

## Chapter 2 — Backlog Reconciliation (audited 2026-08-19 against 3.13.1 as-built)

Chapter 1's story text still describes the **Resolver / Change_Set / Run_Ledger rewrite that was
abandoned** in the 2026-07-22 correct-course (see `sprint-change-proposal-2026-07-22.md`). Epic 1 shipped
as incremental hardening of the queue core instead. Sprint-status was re-baselined then; **the story text
below in Chapter 1 was not**, and three more releases (3.12.0 → 3.13.1) have shipped since. Verdicts below
are from reading the code and test suite, not the changelog.

### Verified DONE — story text in Chapter 1 is obsolete

| Story | Verdict | Evidence in as-built |
|---|---|---|
| **1.7** per-run marker | **DONE** — sprint-status says `backlog` | `class-skwirrel-wc-sync-run-links.php`, `_skwirrel_last_run_id` + `_skwirrel_run_outcome` written in upserter / service / purge-handler; `tests/Unit/RunLinksTest.php` |
| **2.3** result deep-links (FR-15) | **DONE** — sprint-status says `backlog` | Shipped 3.12.1; hardened through 3.12.2 (outcome-as-a-set, parent stamping, trash inclusion, `show_in_admin_all_list` selection) |
| **3.8** Connectors-forward credentials (FR-11) | **DONE** — sprint-status says `backlog` | `class-skwirrel-wc-sync-connectors.php` (`get_token()`, `register_connector()`, `maybe_migrate_token()`), `tests/Unit/ConnectorsApiTest.php`; shipped 3.10.0 |

### Verified still OPEN — story stands as written

- **1.12** clean uninstall & deactivate — no `uninstall.php`, no `register_deactivation_hook`. Real gap.
- **1.13** no-orphaned-variation invariant suite — no WC-level invariant test.
- **1.15** upgrade-from-3.10.2 smoke — no activation/upgrade fixture test.
- **2.1** change_set_table component, **2.2** preflight — no preflight/dry-run code anywhere.
- **2.5** start-over / clean-all reset — only `handle_reset_settings()` exists (settings only, **not** products). Story stands.
- **Epic 3 entirely** except 3.8 — no health, diagnostics or compatibility class exists.
- **4.4a** guided setup shell — nothing.

### Needs re-scoping — partially delivered, story text now overstates the remaining work

- **1.3** Resolver + Change_Set — the resolver half was **dropped** with the rewrite; only the Change_Set *forecast* survives, and only as an Epic 2 preflight dependency. Retitle to "Change_Set forecast over the queue core" or fold into 2.2.
- **1.14** regression-canary suite — most canaries now exist as real tests (`ActionSchedulerRearmTest`, `CategoryRenameTest`, `ConnectorsApiTest`, `ProductUpserterPriceTest`, `UnchangedGateTest`). Residual: object-cache-bust on settings save, and a true price-zero-out behaviour test (the existing one asserts the *setting default*, not the behaviour).
- **2.4** plain-language result & history — largely shipped: history table with trigger labels, Deprecated column, run-scoped links, sweep advisories rendered under their run (3.13.0). Residual is copy/IA polish, not a build.
- **4.3** sensible defaults — the documented defaults already satisfy this (purge OFF, etc.). Reduce to an audit-and-document story.
- **4.4b** live credential verify — `handle_test_connection_ajax()` already autosaves and verifies live against Skwirrel. Residual is the wizard gate, which belongs to 4.4a.

### Superseded by Chapter 2

- **4.1** intent-grouped settings — delivered by Epic 5's tabbed-settings story (FR-3 amended). **Strike from Epic 4.**

### As-built facts that change how Chapter 2 must be built

- **A page-level `?tab=` mechanism already exists** (3.13.0 top-level menu: Status / Settings / Sync logs / Sync now, with a `submenu_file` filter highlighting the active entry). Epic 5's settings tabs are a **second level inside** the Settings tab — they must not collide with the `?tab=` query var, and should reuse the existing `.skw-*` components rather than introduce a second tab system.
- **HTML5 `required` is already on three fields** (`subdomain`, `super_category_id`, `collection_ids`) with no asterisk, no `aria-required`, and no inline error. FR-22 is therefore partly "finish and make consistent", not "add from scratch" — and `custom_collection_id`, the conditional case, has none of it.
- **`prices_managed_outside_skwirrel` already exists as a setting** — the precedent for NFR-9. If stock needs the same escape hatch, mirror that naming (`stock_managed_outside_skwirrel`) rather than inventing a new pattern.
- **Four classes shipped since the Chapter 1 docs were written** and are absent from the CLAUDE.md class map: `connectors`, `deprecated-status`, `pim-link`, `run-links`. Documentation debt, tracked here so it is not rediscovered.

## Chapter 2 — Epic List

### Epic 5: A settings screen you can navigate, trust, and verify
A store owner finds the setting they need without scrolling past forty others, can see at a glance which
fields are mandatory and exactly which one rejected their save, can point the plugin at a specific Skwirrel
context, and can prove from the settings screen that the connection is not merely reachable but actually
returning products.
**FRs covered:** FR-3 (amended — supersedes Story 4.1), FR-20, FR-21, FR-22 · **UX:** UX-DR6 (amended), UX-DR13, UX-DR14 · **NFR:** NFR-7 (a11y/i18n)
*Standalone. Touches only the admin surface and the JSON-RPC client's parameter/response handling — no sync-path risk. Independently shippable ahead of Epic 6.*

### Epic 6: Stock and product content driven from Skwirrel data
A store owner maps a Skwirrel custom-class feature onto WooCommerce stock quantity, product title, short
description and long description — so the fields their PIM actually owns stop being maintained twice — with
a hard guarantee that a value missing from the PIM never wipes what WooCommerce already has.
**FRs covered:** FR-18, FR-19, FR-23 · **NFR:** NFR-9 (non-destructive field mapping — the epic's defining constraint)
*Standalone: its settings render as a field group in whatever container exists. Epic 5 promotes that group to its own tab; neither epic blocks the other.*

### Chapter 2 Epic Sequencing

- **Epic 5 first** is preferred but not required — it builds the Field mapping tab that Epic 6's fields belong in, so shipping it first avoids re-homing them later.
- **Neither epic depends on Epics 1–4.** Epic 5 supersedes Story 4.1 and can proceed while Epic 1's remaining gaps (1.12, 1.13, 1.15) stay open.
- **Where this sits against the in-flight backlog is a sprint-planning call.** These are client-requested; Epics 2 and 3 are not. Do not let that resolve itself by default.

---

## Epic 5: A settings screen you can navigate, trust, and verify

Make the settings surface navigable, honest about what it requires, and able to prove the connection works.
Standalone — admin surface plus the JSON-RPC client's parameter and response handling. No sync-path risk.

**Implementation order: 5.1 → 5.2 → 5.3 → 5.4.** 5.2 depends on 5.1 (inline errors must be tab-aware);
5.3 and 5.4 are independent of both and may be pulled forward if 5.1 stalls.

### Story 5.1: Tabbed settings navigation (FR-3, UX-DR13)

As a store owner,
I want the settings grouped into tabs I can move between,
So that I can find the setting I need without scrolling past forty I don't.

**Acceptance Criteria:**

**Given** the settings screen
**When** it renders
**Then** every existing field belongs to exactly one of four tabs — **Connection** · **What to sync** · **How it looks** · **Advanced** — built from the existing `.skw-*` components.
**And** the current eight groups re-home as: *API Connection* → Connection; *Sync Options* + *Product status handling* → What to sync; *Media & Language* + *Permalinks* → How it looks; *Scheduling* + *Sync Logs* + *Advanced* → Advanced. The Danger Zone stays outside the tab set, where it is today.

**Given** the tabbed screen
**When** I save
**Then** all fields submit in a single request exactly as before — fields on inactive tabs remain in the DOM and are **not** split into per-tab option writes, because `sanitize_settings()` validates across groups (`custom_collection_id` is required based on `sync_custom_classes`).
**And** saving from any tab leaves every other tab's stored values unchanged.

**Given** a save that produced validation errors
**When** the screen re-renders
**Then** each tab containing an error is visibly marked (not colour alone — icon or count, per NFR-7), and the first tab with an error is the one opened.

**Given** a URL carrying `#tab-{slug}`
**When** the page loads
**Then** that tab opens. **And** the anchor mechanism does not collide with the existing page-level `?tab=` query var used by the top-level menu (Status / Settings / Sync logs).

**Given** JavaScript is unavailable
**When** the screen renders
**Then** no field is unreachable — the panels degrade to sequential sections rather than hiding content permanently.

**Given** keyboard-only navigation
**When** I move through the tabs
**Then** they are reachable and operable with a visible focus ring, and the tab/panel relationship is exposed to assistive technology.

**And** the tab registration is extensible, so Epic 6 can add its Field mapping tab without modifying this story's markup.

### Story 5.2: Required fields are marked, and errors point at the field (FR-22, UX-DR14)

As a store owner,
I want to see which fields are mandatory and exactly which one rejected my save,
So that I'm not hunting through a long form guessing what went wrong.

**Acceptance Criteria:**

**Given** the settings screen
**When** it renders
**Then** every unconditionally required field shows a `*` next to its label and carries `aria-required`, and the three fields that already use the bare HTML5 `required` attribute (`subdomain`, `super_category_id`, `collection_ids`) are brought into that same treatment rather than left inconsistent.

**Given** a conditionally required field (`custom_collection_id`, `super_category_id`)
**When** the condition that makes it required is not met
**Then** it shows no marker; **when** the condition is met, the marker appears — matching the rule `sanitize_settings()` will actually enforce on save.

**Given** a save that fails validation
**When** the screen re-renders
**Then** each `add_settings_error()` message is rendered adjacent to the field it concerns, in addition to the existing summary, and the field is programmatically associated with its message for screen readers.

**Given** a failing field on a collapsed tab
**When** the screen re-renders
**Then** that tab is opened and marked (Story 5.1) — an inline error on a hidden tab must never be less visible than today's top-of-page notice.

**And** all new strings are translatable under `skwirrel-pim-sync`, English source.

### Story 5.3: Context ID (FR-20)

As a store owner whose Skwirrel instance serves more than one context,
I want to tell the plugin which context to read,
So that I import the content intended for this shop.

**Acceptance Criteria:**

**Given** the Connection tab
**When** it renders
**Then** an optional **Context ID** field is present with placeholder `1` and help text stating that leaving it empty uses the Skwirrel default.

**Given** a Context ID is set
**When** any JSON-RPC call is made
**Then** the value is sent as the documented API parameter on `getProducts`, `getProductsByFilter` and `getGroupedProducts` alike.

**Given** the Context ID field is empty
**When** any JSON-RPC call is made
**Then** the parameter is omitted entirely, so existing installs behave exactly as before this story.

**Given** a shop that has already synced
**When** the Context ID is changed and saved
**Then** `skwirrel_wc_sync_force_full_sync` is set, so the next run is a full sync and the catalog cannot end up a mix of two contexts.
**And** the admin is told, in plain language, that a full re-sync will follow.

**Given** a non-numeric or negative value
**When** the settings are saved
**Then** it is rejected with an inline error (Story 5.2), not silently coerced.

> ⚠️ **BLOCKED UNTIL CONFIRMED:** the exact API parameter name is unverified. Jos to confirm against the Skwirrel API docs before this story starts. Known: optional API parameter, API-side default `1`.

### Story 5.4: Test Connection reports what actually came back (FR-21)

As a store owner,
I want the connection test to tell me more than "it worked",
So that I can see the integration is genuinely returning products rather than merely reachable.

**Acceptance Criteria:**

**Given** a successful test
**When** the result renders
**Then** it reports round-trip time, the HTTP / JSON-RPC status, and the total number of products the API reports — replacing the current bare success message.

**Given** a connection that succeeds but returns zero products
**When** the result renders
**Then** the zero count is stated plainly as a warning-toned result, not as an unqualified success.

**Given** a failing test
**When** the result renders
**Then** the round-trip time and status are still reported alongside the existing error message, so a timeout is distinguishable from a rejection.

**Given** the test runs
**When** it completes
**Then** it performs no writes beyond the existing connection-settings autosave in `handle_test_connection_ajax()`, and the auth token appears nowhere in the rendered output (NFR-4).

**And** the counts render with tabular figures and all new strings are translatable.

> **Follow-up, explicitly not in this story:** making the product count honour the configured `collection_ids` and Context ID. That is what would catch "connection fine, zero products sync" — recorded in the Chapter 2 out-of-scope list.

---

## Epic 6: Stock and product content driven from Skwirrel data

Let a store owner map product-level custom-class features onto native WooCommerce fields, so data the PIM
already owns stops being maintained twice — under a hard guarantee that a value missing from the PIM never
wipes what WooCommerce already has (**NFR-9**).

**Implementation order: 6.1 → 6.2 → 6.3 → 6.5 → 6.4.** 6.5 is independent of 6.1's resolver and may land at any point. 6.1 establishes the resolver and the settings group that
6.2 and 6.3 consume. Standalone against Epic 5: the settings render as an ordinary field group, promoted to
a Field mapping tab via Story 5.1's registration when that exists.

### Story 6.1: Stock quantity from a custom class — simple products (FR-18, NFR-9)

As a store owner,
I want a Skwirrel custom-class feature to drive my product stock,
So that I stop maintaining stock levels in two systems.

**Acceptance Criteria:**

**Given** the settings screen
**When** it renders
**Then** a **Field mapping** group exists containing a **Stock quantity** field that accepts a product-level custom-feature ID or code, empty by default. **And** when Story 5.1's tab registration is present, the group registers as its own tab; when it is not, it renders as an ordinary field group.

**Given** a mapped feature ID and a simple product whose product-level custom classes carry a numeric value for it
**When** the product syncs
**Then** the product is saved with `set_manage_stock( true )` and `set_stock_quantity( value )`, resolved through the existing `Custom_Class_Extractor` (product level only — `_custom_classes`, never `_trade_item_custom_classes`).

**Given** the mapped feature is absent, empty, or non-numeric on a product
**When** the product syncs
**Then** the product's existing WooCommerce stock quantity and `manage_stock` flag are left **exactly** as they were — never zeroed, never flipped to unmanaged. *(NFR-9. Mirrors the `prices_managed_outside_skwirrel` guarantee.)*

**Given** no feature ID is configured
**When** any product syncs
**Then** no stock-related write occurs at all, and behaviour is byte-for-byte what it was before this story.

**Given** the mapped stock value changes upstream while nothing else about the product does
**When** a delta sync runs
**Then** the product is **not** skipped by the unchanged gate — the resolved mapping values participate in `_skwirrel_content_hash`, or the change would never land.

**And** unit coverage pins: numeric value written; missing value leaves stock untouched; non-numeric value leaves stock untouched; unconfigured mapping writes nothing.

### Story 6.2: Stock quantity on variations (FR-18, NFR-9)

As a store owner selling variable products,
I want each variation to carry its own stock,
So that variable products report availability as accurately as simple ones.

**Acceptance Criteria:**

**Given** stock mapping is configured and a variation's own Skwirrel product carries a numeric value for the mapped feature
**When** the grouped sync runs
**Then** that variation is saved with managed stock and its quantity — each variation resolving the same feature ID against **its own** product-level custom classes, since variations are themselves Skwirrel products.

**Given** the existing variation paths that hardcode `set_manage_stock( false )` and force `instock` in `class-skwirrel-wc-sync-product-upserter.php`
**When** stock mapping is configured
**Then** those hardcoded assumptions no longer override a resolved quantity.
**And when** stock mapping is **not** configured, those paths behave exactly as they do today — this story changes nothing for shops that never opt in.

**Given** variations with resolved stock
**When** the group finishes assembling
**Then** the variable parent's aggregate stock status is refreshed through the existing `WC_Product_Variable::sync_stock_status()` call rather than a parallel mechanism.

**Given** a variation whose mapped feature is missing or empty
**When** the sync runs
**Then** that variation's existing stock state is left untouched (NFR-9), and its siblings' resolution is unaffected.

**And** a price-on-request variation keeps its existing out-of-stock treatment — stock mapping does not override that rule.

### Story 6.3: Title, short and long description from custom classes (FR-19, NFR-9)

As a store owner,
I want product copy to come from the custom-class fields my PIM actually authors,
So that the shop shows the text my team maintains rather than an ERP description.

**Acceptance Criteria:**

**Given** the Field mapping group
**When** it renders
**Then** it contains three further optional fields — **Product title**, **Short description**, **Long description** — each accepting a product-level custom-feature ID or code, each independently empty by default.

**Given** a mapped feature that resolves to a non-empty value
**When** the product syncs
**Then** that value is written to the corresponding WooCommerce field, overriding the existing source.

**Given** a mapped feature that is absent or resolves to an empty value
**When** the product syncs
**Then** the existing resolution chain applies unchanged — title falls back to `product_erp_description` → translations; short and long description fall back to their current chains. A product never ends up with an empty title because the PIM omitted a value (NFR-9).

**Given** a long-description value containing markup
**When** it is written
**Then** it passes through `wp_kses_post()` — formatting survives, unsafe markup does not.

**Given** a value carrying custom-class translations
**When** it is resolved
**Then** language selection follows the existing `image_language` / `include_languages` settings, consistent with every other translated field.

**Given** a product whose title changes because of this mapping
**When** it syncs
**Then** its slug is **not** rewritten — `update_slug_on_resync` behaviour is untouched, so existing product URLs and their SEO value survive.

**Given** a mapped content value changes upstream while nothing else does
**When** a delta sync runs
**Then** the product is not skipped by the unchanged gate (same content-hash requirement as 6.1).

**And** the three mappings work independently: configuring only the long description leaves title and short description entirely alone.

---

### Story 6.5: Document links show a readable name in the shop's language (FR-23, NFR-9)

As a shop visitor,
I want a product's documents listed by their real names in my language,
So that I can tell a mounting instruction from a declaration of performance without opening both.

**Acceptance Criteria:**

**Given** a document attachment whose `_attachment_translations` contains an entry for the configured `image_language`
**When** the product syncs
**Then** `$doc['name']` is that entry's `product_attachment_title`, and the frontend documents tab renders it as the link text.

**Given** no exact language match, but a translation sharing the two-letter prefix (`nl-BE` configured, `nl` present)
**When** the name resolves
**Then** the prefix match is used — the same exact → prefix → first-entry chain `get_attachment_meta_for_language()` already applies to images, not a second parallel implementation.

**Given** an attachment with no translations, or translations carrying an empty title
**When** the name resolves
**Then** it falls back to `product_attachment_title` on the attachment itself, then to `file_name`, then to the URL basename — a document is never rendered nameless (NFR-9).

**Given** products synced before this story landed, whose stored `_skwirrel_document_attachments` hold raw filenames
**When** they are re-synced
**Then** the stored names are refreshed to the resolved titles — no separate migration, the normal delta/full sync repairs them.

**Given** a document title containing HTML or a stray tag
**When** it renders in the documents tab and the admin meta box
**Then** it is escaped at output (`esc_html`) exactly as today — this story changes which string is chosen, never how it is escaped.

**And** the three quality gates (`pest`, `phpstan` level 6, `phpcs`) pass.

**Implementation notes (as-built, verified 2026-08-19 against 3.13.1):**
- The defect is one line: `class-skwirrel-wc-sync-attachment-handler.php:262` reads
  `$att['file_name'] ?? $att['product_attachment_title'] ?? ''` — raw filename first, translations never consulted.
- `get_attachment_meta_for_language()` (same class, line 40) already implements the required chain for images.
  It is `private` and reads `image_language` from the settings option directly; reuse it rather than duplicating.
- Consumers of `$doc['name']` are `class-skwirrel-wc-sync-product-documents.php:82` (frontend tab),
  `:125` (admin meta box) and `templates/single-product/tabs/skwirrel-documents.php:30` (overridable template).
  All three already `esc_html()`; none need changing.
- `image_language` is the existing language setting. Do **not** introduce a `document_language` twin — if a
  separate axis is ever wanted, that is a new FR, not a silent addition here.

### Story 6.4: The non-destructive guarantee is pinned by tests (NFR-9)

As a maintainer,
I want the "a missing PIM value never clears WooCommerce data" rule pinned by tests,
So that it cannot regress the way an unpinned invariant eventually does.

**Acceptance Criteria:**

**Given** each of the four mappings (stock, title, short description, long description)
**When** a product syncs whose mapped feature is absent, empty, or malformed
**Then** an automated test asserts the existing WooCommerce value is unchanged — one case per mapping, on simple products and on variations for stock.

**Given** a sync where the API response omits `_custom_classes` entirely
**When** the run completes
**Then** no mapped field is written on any product, and the run reports success rather than failing.

**Given** the regression-canary suite (Story 1.14) exists
**When** this story completes
**Then** these cases join it, alongside the "don't zero out prices" canary they extend.
**And given** Story 1.14 is still open (it is `backlog` as of 2026-08-19)
**Then** this story stands alone as its own Pest file — it must never be blocked on 1.14 landing first.

**And** the three quality gates (`pest`, `phpstan` level 6, `phpcs`) pass.
