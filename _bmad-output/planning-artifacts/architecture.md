---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8]
lastStep: 8
status: 'complete'
completedAt: '2026-06-11'
inputDocuments:
  - '_bmad-output/planning-artifacts/prds/prd-wordpress-2026-06-10/prd.md'
  - '_bmad-output/planning-artifacts/prds/prd-wordpress-2026-06-10/.decision-log.md'
  - '_bmad-output/implementation-artifacts/investigations/wp70-sync-break-investigation.md'
  - '_bmad-output/implementation-artifacts/deferred-work.md'
  - '_bmad-output/project-context.md'
  - 'CLAUDE.md'
  - '.claude/rules/sync-service.md'
  - '.claude/rules/product-mapping.md'
  - '.claude/rules/admin-settings.md'
  - '.claude/rules/testing.md'
workflowType: 'architecture'
project_name: 'Skwirrel PIM sync for WooCommerce'
user_name: 'Jos'
date: '2026-06-11'
---

# Architecture Decision Document

_This document builds collaboratively through step-by-step discovery. Sections are appended as we work through each architectural decision together._

## Project Context Analysis

### Requirements Overview

**Functional Requirements (17 FRs, 4 user journeys):**
- **§4.0 WP 7.0 restoration (FR-13/14)** — delivered in 3.10.2 (F1/F2/F3 + documents fix).
  Architectural obligation here: the sync-core rewrite must *not regress* 7.0; FR-14's
  remaining gap is an automated documents-path integration test.
- **Headline rewrite (drives F4/F6/F7)** — replace the phased, non-resumable orchestrator
  with **per-product-atomic** processing: identity meta written first, each product fully
  resolved+committed on detection, a **thin deferred relations/variable-assembly pass**,
  and **per-product checkpointing** (do not advance `last_sync` until complete).
- **Shared resolve→commit path (keystone)** — serves FR-16 (preflight = resolve, no writes),
  FR-15 (per-run marker stamped at commit → deep-links), FR-17 (Skwirrel-scoped reset).
  Preflight forecasts **products** (add/change/remove) AND **category structure**
  (created/renamed/removed-or-orphaned, with re-homed product counts).
- **Pillar B — diagnostics/health (FR-4–8)** — read-only Health Check, Fault Attribution,
  conflict-signature registry (FR-6: image/media optimizers, caching, permalinks as seeds),
  exportable token-redacted report + environment snapshot, readable sync state/history.
- **Pillar C — resilience (FR-9–11)** — compatibility self-check, safe degradation (halt
  without partial-write corruption), Connectors-forward credentials w/ 6.9 fallback (adapter exists).
- **Pillar A — legibility (FR-1–3)** — guided setup, sensible defaults, intent-grouped settings
  with visible "setting relations". Largely a UX-step deliverable; architecture provides the
  settings/first-run state model and a relation-evaluation hook.
- **FR-12** — delta correctness guard only (delta touches only changed products).

**Non-Functional Requirements (drivers):**
- **Read-only invariant** — Health Check + Preflight must never mutate or trigger a sync.
  FR-17 reset is the single, explicitly-gated destructive exception (opt-in + confirm + preview).
- **No destructive defaults** — purge/reset stay opt-in; mid-run failure must not corrupt catalog.
- **Quality gates** — pest (unit) + phpstan L6 + phpcs (WPCS) green before release; wp-env
  integration coverage for real-WP behavior. Diagnostics/compat logic are priority test targets.
- **Security** — auth token never in any diagnostics export (extends settings-export rule).
- **Performance — DEFERRED** this chapter. Design the core for correctness + resumability,
  not speed (per-product-atomic helps the later perf chapter, but no time budget is asserted).
- **Compatibility posture** — WP 7.0+ primary / 6.9+ floor; WC 8.0+ (9.6+ brands); HPOS-compatible;
  Connectors API preferred with legacy fallback.
- **i18n / a11y** — new strings translatable (text domain `skwirrel-pim-sync`), `manage_woocommerce`
  gating, WP-admin conventions; English-first (locales may trail).

**Scale & Complexity:**
- Primary domain: WordPress/WooCommerce integration plugin (API-backed PIM→commerce sync service).
- Complexity level: **medium** (brownfield, mature ~25-class plugin; no real-time/multi-tenant/
  regulatory complexity; the hard part is structural correctness + a clean diagnostics surface).
- Estimated new/changed architectural components (this chapter):
  1. Sync core: **resolve** (read-only) + **commit** engine, per-product orchestrator, checkpoint store
  2. **Identity resolver** — single source of truth for upsert-key precedence + simple↔variation reconciliation (kills F7)
  3. **Change-set model** — unified product + category-structure diff used by preflight, result, reset
  4. **Run ledger / per-run marker** — stamps touched products & categories for FR-15 deep-links
  5. **Reset service** — Skwirrel-scoped, opt-in, preview-gated (FR-17)
  6. **Health/diagnostics engine** — checks + fault attribution + conflict registry + report/export
  7. **Sync-state surface** — history → plain-language readable (FR-8)
  8. **Compatibility & safe-degradation guard** (FR-9/10)
  9. **Connectors credential adapter** — exists; formalize the resolve-with-fallback contract (FR-11)
  10. **Settings / guided-setup state model** — UX-led; architecture supplies the data model + relation hook

### Technical Constraints & Dependencies

- **No Composer autoloader in the plugin** — every class loaded via `require_once` in
  `skwirrel-pim-sync.php`; new classes registered in TWO places (require + hook wiring in
  `Skwirrel_WC_Sync_Plugin`). Singletons via `::instance()`.
- **Hard WPCS naming** — class `Skwirrel_WC_Sync_{Name}`, file `class-skwirrel-wc-sync-{slug}.php`.
- **Meta keys are contracts** — reuse documented `_skwirrel_*` keys; `_skwirrel_synced_at` is the
  stale-detection timestamp every upsert path must write. No parallel keys for the same data.
- **WC data stores + HPOS** — use WC CRUD; never touch legacy order-post-meta directly.
- **Background sync** — HTTP loopback / Action Scheduler, gated by transient
  `skwirrel_wc_sync_bg_token`; mutex self-heals after 60s TTL + `finally` release.
- **Upsert key precedence is fixed** — ext → internal_code → manufacturer_code → product_id;
  must be single-sourced in the identity resolver, never reordered.
- **Purge guards** — trashing runs only after a full sync with no collection filter +
  `purge_stale_products`; never permanent delete; categories with attached products never deleted.
- **No build step / no JS framework** — admin UI is server-rendered PHP forms + 2 static CSS files;
  preflight & health UI must fit this (minimal/no JS; deep-links are plain filtered admin URLs).
- **Don't zero-out prices** — missing/`price_on_request` → `null`; never overwrite an existing WC price.

### Cross-Cutting Concerns Identified

- **Identity resolution** — the F7 root; one authoritative resolver consumed by resolve, commit,
  reset, and lookup, handling simple↔variation oscillation without minting suffixed duplicates.
- **Resumability & checkpointing** — per-product (not per-phase) checkpoint; `last_sync` advances
  only on full completion; an interrupted run must leave no bare/duplicate product.
- **Read-only invariant** — enforced boundary so Preflight + Health Check share resolve logic with
  commit yet provably write nothing.
- **Change-set vocabulary** — one product+category diff structure powering forecast (FR-16),
  result/deep-links (FR-15), and reset preview (FR-17), so "before" and "after" always agree.
- **Backward compatibility** — 6.9 floor + existing meta/settings contracts; the rewrite must be a
  drop-in for the existing Mapper/Client/Lookup collaborators where feasible.
- **Observability** — `Skwirrel_WC_Sync_Logger` remains the substrate; diagnostics surface it as
  customer-readable signal (logging is not the UI).

## Starter Template Evaluation

### Primary Technology Domain

WordPress/WooCommerce plugin (server-side PHP integration service). **Brownfield** — an
existing, shipping plugin (v3.10.2), not a greenfield build.

### Decision: No starter template — extend the existing plugin codebase

Starter-template evaluation does **not apply** to this chapter. There is no scaffold to
generate; the architectural foundation is the existing plugin and its established tooling,
which already encode every decision a starter would. The work is to *evolve* this foundation
(new sync core + diagnostics), not to re-platform it.

### Foundation already established (the de-facto "starter")

**Language & Runtime:**
- PHP 8.3+ with `declare(strict_types=1)`; must run on 8.3 (no 8.4-only syntax).
- Targets WordPress 7.0+ (primary) / 6.9+ (floor); WooCommerce 8.0+ (9.6+ brands; current 10.x);
  HPOS-compatible.

**Code Organization & Patterns (fixed, non-negotiable):**
- No Composer autoloader in the plugin — classes loaded via `require_once` in
  `skwirrel-pim-sync.php`; new classes registered in TWO places (require + hook-wire in
  `Skwirrel_WC_Sync_Plugin`).
- Singletons via private constructor + `::instance()`.
- Hard WPCS naming: class `Skwirrel_WC_Sync_{Name}`, file `class-skwirrel-wc-sync-{slug}.php`.
- Shippable code only under `plugin/skwirrel-pim-sync/`; dev tooling at repo root.

**Styling / UI:**
- No build step, no JS framework. Admin UI = server-rendered PHP forms + 2 static CSS files
  (`assets/admin.css`, `assets/product-documents.css`). Templates follow the WC overridable pattern.

**Testing Framework:**
- Pest ^3 (unit, stub bootstrap, `tests/Unit/`) + wp-env Docker integration (`tests/Integration/`).
- Test commands run from repo root (`vendor/bin/pest`).

**Build / Quality Tooling:**
- PHPStan ^2 (level 6, `phpstan-baseline.neon`) + PHP_CodeSniffer ^3.7 with WPCS ^3.0.
- Three gates green before release: pest + phpstan + phpcs (auto-fix via phpcbf).

**Release / Deployment:**
- Version bumped in header + `SKWIRREL_WC_SYNC_VERSION`; changelog in CHANGELOG.md + readme.txt;
  tag `X.Y.Z` triggers WordPress.org SVN deploy. Translations across 7 locales.

**Integrations (existing):**
- Skwirrel JSON-RPC 2.0 API (`getProducts`/`getProductsByFilter`/`getGroupedProducts`).
- WP 7.0 Connectors API for credentials (adapter exists), with legacy-option fallback.

**Note:** No initialization story is needed — the foundation already exists. The first
implementation stories belong to the new sync core (Step 4 onward).

## Core Architectural Decisions

_No new external technology is introduced this chapter; all language/framework/tooling versions are
fixed by the existing stack and compatibility posture (no versions to verify). The decisions below
concern internal structure._

### Decision Priority Analysis

**Critical Decisions (block implementation):**
- D1 Sync-core `resolve → commit` separation (keystone for FR-15/16/17)
- D2 Per-product-atomic orchestration + work-ledger checkpointing (fixes F6)
- D3 Single Identity Resolver (fixes F7 duplicates)

**Important Decisions (shape architecture):**
- D4 Per-run marker for FR-15 deep-links
- D5 Diagnostics/Health engine (FR-4–8)
- D6 Compatibility guard + safe degradation (FR-9/10)
- D7 Storage for preview & history

**Deferred (out of scope this chapter):**
- Sync-speed/performance optimization (later chapter) — design for correctness + resumability now.
- Per-field diff in preview (later) — count + list depth only.
- FR-5 HS Code empty-attribute follow-up (needs payload; small, separate).

### D1 — Sync Core: `resolve → commit`

A **`Resolver`** builds a read-only **`Change_Set`** (intended creates / updates / removes for
products **and** category structure — created / renamed / removed-or-orphaned, with re-homed product
counts). A **`Committer`** applies a `Change_Set`. **Preflight (FR-16) = Resolver only; Commit =
Resolver + Committer**, so the forecast can never drift from the applied result. The read-only
invariant is structural, not a convention.
- **Affects:** FR-15, FR-16, FR-17, the per-product orchestrator, all Pillar-B preview surfaces.
- **Rejected:** a `process($item, $dry_run)` boolean threaded through the existing upserter —
  re-introduces dry-run/real drift (the failure mode being eliminated).

### D2 — Orchestration & Checkpoint Store (fixes F6)

Extend the existing `Skwirrel_WC_Sync_Queue` custom table into a **per-product work-ledger** (one row
per product, status `pending → done`). Each product is processed **atomically** (identity + fields +
media committed together on detection), followed by a **thin deferred relations / variable-assembly
pass**. `skwirrel_wc_sync_last_sync` advances **only** when the ledger is fully drained; an
interrupted run resumes from the ledger. **Action Scheduler remains the trigger**, not the per-item
processor.
- **Affects:** FR-12 (delta correctness), F4 (images no longer stranded), resumability/safe-degradation.
- **Rejected:** new dedicated ledger table (no benefit over extending Queue); one AS action per product (heavy).

### D3 — Identity Resolver (fixes F7)

One `Skwirrel_WC_Sync_Identity_Resolver` is the single source of upsert-key precedence
(`ext → internal_product_code → manufacturer_product_code → product_id`). Identity meta
(`_skwirrel_external_id` / `_skwirrel_product_id`) is written **first** in the atomic per-product step.
On SKU collision it **reuses** the matched product instead of minting a suffixed duplicate
(`…-{product_id}`), and reconciles simple↔variation **in place** without blanking the SKU. Consumed by
resolve, commit, reset, and lookup alike.
- **Affects:** F7 root fix; FR-15 marker correctness; FR-17 scoping.
- **Note:** the fixed precedence must never be reordered or bypassed (project-context rule).

### D4 — Per-Run Marker for Deep-Links (FR-15)

Every touched product **and** term is stamped with `_skwirrel_last_run_id`, plus a run-ledger entry.
Deep-links open the **native WooCommerce All Products** list filtered by a registered query var on that
meta (and Products → Categories for term changes) — no custom list screen, consistent with the
"no JS framework / server-rendered" constraint. The marker also correlates preflight forecast ↔ result.
- **Affects:** FR-15, FR-8 (readable result), preview↔result symmetry.
- **Rejected:** per-run ID lists in an option/table + a bespoke filtered screen (more surface, less native).

### D5 — Diagnostics / Health Engine (FR-4–8)

A `Skwirrel_WC_Sync_Health_Check` runner iterates a **registry of check objects**, each returning
`{ status (healthy/warning/problem/undetermined), plain-language verdict, evidence, attribution
(ours/environment) }`. **Conflict signatures (FR-6) are data entries** — hook-collision probes seeded
with image/media optimizers, caching/performance plugins, and permalink/SEO plugins, expandable from
support tickets. The Diagnostics Report (FR-7) serializes results **minus secrets** (token never
exported) plus an environment snapshot. Entirely **read-only**; a failed check degrades to
"could not determine," never a fatal error.
- **Affects:** FR-4, FR-5, FR-6, FR-7, FR-8; observability (surfaces `Skwirrel_WC_Sync_Logger`).

### D6 — Compatibility Guard & Safe Degradation (FR-9/10)

A `Skwirrel_WC_Sync_Compatibility_Guard` gates sync entry (WP/WC version range vs the 7.0-primary /
6.9-floor posture). On detected incompatibility or a mid-run abort, the plugin enters an explicit
**"sync paused (reason)"** state surfaced in admin with how to resume — never a fatal error.
Partial-write corruption is **structurally prevented** by D2's per-product-atomic commit (all-or-nothing
per product).
- **Affects:** FR-9, FR-10; complements the Connectors-forward credential path (FR-11, adapter exists).

### D7 — Storage for Preview & History

The preview `Change_Set` lives in a **per-user transient** (ephemeral, auto-expiring) — preview is
throwaway. Run history extends the existing `skwirrel_wc_sync_history` option (readable sync state,
FR-8). Credentials continue to resolve via `Skwirrel_WC_Sync_Connectors::get_token()` with legacy
`skwirrel_wc_sync_auth_token` fallback (FR-11 — already built).
- **Affects:** FR-16 (preview lifecycle), FR-8 (history), FR-11 (credentials).

### Decision Impact Analysis

**Implementation sequence (dependency-ordered):**
1. **D3 Identity Resolver** — foundational; everything else resolves identity through it.
2. **D1 Resolver + Change_Set model** — the shared data structure & read-only resolution.
3. **D2 Work-ledger orchestration + Committer** — atomic per-product apply on top of D1/D3.
4. **D4 Per-run marker** — stamped during D2's commit; enables FR-15.
5. **FR-16 Preflight + FR-15 result deep-links** — thin layers over D1/D4 (Resolver + marker).
6. **FR-17 Reset** — Skwirrel-scoped inverse, previewed via the D1 resolve path.
7. **D5 Health/diagnostics + D6 Compatibility guard** — largely independent; can proceed in parallel.
8. **D7 storage/history wiring** — supports 5–6 and FR-8.

**Cross-component dependencies:**
- D1 depends on D3 (resolution needs identity); D2 depends on D1+D3; D4 depends on D2; FR-15/16/17 depend on D1+D4.
- D5/D6 are loosely coupled (consume sync state + logger, but don't depend on the core rewrite) — de-risks scheduling.
- All preview/diagnostics paths inherit the **read-only invariant** from D1; FR-17 is the single gated destructive exception.

## Implementation Patterns & Consistency Rules

### Scope note
Foundational conventions are already binding (see `project-context.md` / `.claude/rules/*`):
WPCS class+file naming, `::instance()` singletons, register-in-two-places, escape-output /
sanitize-input, translatable strings (text domain `skwirrel-pim-sync`), `_skwirrel_*` meta
discipline, logging only via `Skwirrel_WC_Sync_Logger`, WC CRUD / HPOS, token never exported.
The rules below cover the **new** components only — where agents could otherwise diverge.

### Naming Patterns (new components)

**Class & file names** (follow the existing `Skwirrel_WC_Sync_{Name}` →
`class-skwirrel-wc-sync-{slug}.php` rule). Canonical names for this chapter:
- `Skwirrel_WC_Sync_Identity_Resolver`        — D3 single identity source
- `Skwirrel_WC_Sync_Change_Set`               — D1 read-only diff value object
- `Skwirrel_WC_Sync_Resolver`                 — D1 builds a Change_Set (no writes)
- `Skwirrel_WC_Sync_Committer`                — D1 applies a Change_Set (only writer)
- `Skwirrel_WC_Sync_Reset_Service`            — FR-17 Skwirrel-scoped reset
- `Skwirrel_WC_Sync_Health_Check`             — D5 runner
- `Skwirrel_WC_Sync_Health_Check_{Area}`      — individual checks (e.g. `_Connection`, `_Schedule`, `_Environment`)
- `Skwirrel_WC_Sync_Conflict_Signature_{Name}`— FR-6 signature entries
- `Skwirrel_WC_Sync_Compatibility_Guard`      — D6
- `Skwirrel_WC_Sync_Run_Ledger`               — D2/D4 per-run record (extends Queue table usage)

**New meta keys** (contracts, `_skwirrel_*` prefix, documented in CLAUDE.md when added):
- `_skwirrel_last_run_id` — stamped on every touched product AND term (FR-15 deep-links).
- Existing keys unchanged: `_skwirrel_external_id`, `_skwirrel_product_id`, `_skwirrel_synced_at`,
  `_skwirrel_category_id`. **No parallel keys for existing data.**

**Run ID format:** `Y-m-d_His` + 4-char suffix (e.g. `2026-06-11_142233_a1b2`). Generated **once**
at run start by the orchestrator (a side-effect boundary), passed down — never minted inside
resolve/mapper logic (keeps mappers deterministic/testable).

**Hook names** (extension points, `skwirrel_wc_sync_*` convention):
- `skwirrel_wc_sync_health_checks` (filter) — register/extend check objects.
- `skwirrel_wc_sync_conflict_signatures` (filter) — extend FR-6 signature set from add-ons/support.
- `skwirrel_wc_sync_change_set` (filter) — inspect/annotate a Change_Set before commit (read-only consumers).

**Admin deep-link query var:** `skwirrel_run` on the WC product list
(`edit.php?post_type=product&skwirrel_run={run_id}`); terms filtered analogously on the categories screen.

### The Change-Set Vocabulary (single source of truth)

One vocabulary is shared by **preflight forecast, sync result, and reset preview** — they MUST agree.
- **Product actions:** `create` | `update` | `remove`.
- **Category actions:** `create` | `rename` | `remove` | `orphan`.
- `Change_Set` shape (value object, no behaviour that writes):
  - `->products`: list of `{ action, identity, sku, name, reason? }`
  - `->categories`: list of `{ action, term_id?, old_name?, new_name?, parent_change?, rehomed_count }`
  - `->run_id`, `->mode` (full|delta), `->generated_at`
- These action strings are **stable machine constants** (class consts), NOT translated. The
  human-facing label is translated separately. Agents must reuse the constants, never invent
  synonyms ("deleted"/"added"/"moved").

### Process Patterns

**Read-only invariant (critical):**
- `Resolver`, `Health_Check`, and any preview path MUST NOT call a writing API —
  no `->save()`, `wp_insert_*`, `wp_update_*`, `update_option`, `wp_set_object_terms`, `wc_create_*`,
  scheduling, or transient writes that affect sync state. The **only** writer is `Committer`
  (and the gated `Reset_Service`).
- Enforcement: unit tests assert a resolve/preflight pass performs zero writes (spy/stub the data
  stores). A review checklist line: "does this method write? then it does not belong in resolve."

**Error handling & degradation:**
- Each health check is wrapped in try/catch; a failure degrades that check to
  `status = undetermined` with the caught reason as evidence — **never** a fatal error or a
  white-screened admin (FR-4/FR-10).
- Per-product commit is **atomic and isolated**: a failure on product X logs + marks its ledger row
  `failed` and continues; it must not abort the run or corrupt other products (D2/F6 fix).
- `last_sync` advances **only** after the ledger fully drains (no checkpoint-before-completion).

**Status & attribution enums (stable strings):**
- Health status: `healthy` | `warning` | `problem` | `undetermined`.
- Fault attribution: `ours` | `environment` | `undetermined` (FR-5 must cite evidence for any non-`undetermined`).
- Ledger item status: `pending` | `done` | `failed`.

### Enforcement Guidelines

**All implementers (human or AI) MUST:**
- Resolve identity ONLY through `Identity_Resolver` — never re-implement the upsert-key precedence.
- Reuse the Change-Set action constants; never introduce parallel status vocabularies.
- Keep resolve/preview/health paths write-free; route every mutation through `Committer`/`Reset_Service`.
- Write `_skwirrel_synced_at` AND `_skwirrel_last_run_id` on every committed product/term.
- Register each new class in BOTH `skwirrel-pim-sync.php` (require) and `Skwirrel_WC_Sync_Plugin` (hooks).
- Add new strings as translatable; keep machine enum values untranslated.
- Keep the three gates green (pest / phpstan L6 / phpcs) before every commit.

### Examples

**Good:** `$changes = $resolver->resolve($mode); // returns Change_Set, writes nothing`
then `$committer->commit($changes);` — preflight calls only the first line.

**Anti-pattern:** `$upserter->process($product, $dry_run = true)` with `if (!$dry_run) $p->save();`
— a single path with a boolean is forbidden (re-creates forecast/result drift; the read-only
guarantee becomes unprovable).

**Anti-pattern:** minting `4250366870007-14768` on SKU collision — the Identity_Resolver reuses the
matched product instead (D3/F7).

## Project Structure & Boundaries

### Convention
The plugin uses a **flat `includes/` directory** with no autoloader (every class `require_once`d in
`skwirrel-pim-sync.php` and hook-wired in `Skwirrel_WC_Sync_Plugin`). New components follow the same
flat convention — logical grouping is conveyed by the `class-skwirrel-wc-sync-*` name prefix, NOT by
new sub-directories. Each new class is registered in those TWO places.

### Project Tree (new files marked ⭐; refactored ✎; unchanged listed sparingly)

```
plugin/skwirrel-pim-sync/
├── skwirrel-pim-sync.php                                  ✎ add require_once for new classes
├── includes/
│   │  ── Sync core (D1/D2/D3) ──
│   ├── class-skwirrel-wc-sync-identity-resolver.php       ⭐ D3 — single upsert-key authority (fixes F7)
│   ├── class-skwirrel-wc-sync-change-set.php              ⭐ D1 — read-only diff value object (products + categories)
│   ├── class-skwirrel-wc-sync-resolver.php                ⭐ D1 — builds Change_Set, writes nothing
│   ├── class-skwirrel-wc-sync-committer.php               ⭐ D1 — applies Change_Set (sole writer)
│   ├── class-skwirrel-wc-sync-run-ledger.php              ⭐ D2/D4 — per-product work-ledger + run marker
│   ├── class-skwirrel-wc-sync-service.php                 ✎ orchestrator: drives Resolver→Committer over the ledger
│   ├── class-skwirrel-wc-sync-product-upserter.php        ✎ write logic folds into Committer; identity → Identity_Resolver
│   ├── class-skwirrel-wc-sync-product-lookup.php          ✎ feeds Identity_Resolver
│   ├── class-skwirrel-wc-sync-product-mapper.php          (unchanged) pure mapper feeding Resolver
│   ├── class-skwirrel-wc-sync-category-sync.php           ✎ category resolution → Change_Set categories
│   ├── class-skwirrel-wc-sync-purge-handler.php           ✎ remove logic → Committer remove-actions + Reset_Service
│   ├── class-skwirrel-wc-sync-queue.php                   ✎ extended/owned by Run_Ledger
│   │  ── Control surfaces (FR-15/16/17) ──
│   ├── class-skwirrel-wc-sync-reset-service.php           ⭐ FR-17 — Skwirrel-scoped opt-in reset (gated writer)
│   ├── class-skwirrel-wc-sync-product-deeplinks.php       ⭐ FR-15 — `skwirrel_run` query var on WC product/category lists
│   ├── class-skwirrel-wc-sync-admin-preflight.php         ⭐ FR-16 — preflight screen (renders Change_Set forecast)
│   │  ── Diagnostics / health (FR-4–8, D5) ──
│   ├── class-skwirrel-wc-sync-health-check.php            ⭐ D5 — runner over a check registry
│   ├── class-skwirrel-wc-sync-health-check-connection.php ⭐ FR-4 — API/connection check
│   ├── class-skwirrel-wc-sync-health-check-schedule.php   ⭐ FR-4 — scheduler armed? (guards the F2 class of break)
│   ├── class-skwirrel-wc-sync-health-check-environment.php⭐ FR-9 — WP/WC/PHP/version range
│   ├── class-skwirrel-wc-sync-health-check-last-sync.php  ⭐ FR-8 — last-run outcome
│   ├── class-skwirrel-wc-sync-conflict-signature.php      ⭐ FR-6 — signature base + registry
│   ├── class-skwirrel-wc-sync-diagnostics-report.php      ⭐ FR-7 — token-redacted export + env snapshot
│   ├── class-skwirrel-wc-sync-admin-health.php            ⭐ FR-4–8 — Health/Diagnostics admin screen
│   │  ── Resilience (FR-9/10/11) ──
│   ├── class-skwirrel-wc-sync-compatibility-guard.php     ⭐ D6 — gates sync entry; "sync paused (reason)" state
│   ├── class-skwirrel-wc-sync-connectors.php              (exists) FR-11 credential path + legacy fallback
│   │  ── Legibility (FR-1–3, UX-led) ──
│   ├── class-skwirrel-wc-sync-guided-setup.php            ⭐ FR-1 — first-run flow controller (UX defines screens)
│   ├── class-skwirrel-wc-sync-admin-settings.php          ✎ intent-grouping + setting-relation hook (FR-3)
│   └── … (other existing classes unchanged) …
├── templates/
│   └── single-product/                                    (unchanged)
├── assets/
│   ├── admin.css ✎ · dashboard.css ✎                      (preflight/health styling — server-rendered)
│   └── s.png                                              (Connectors logo)
└── languages/                                             ✎ new translatable strings → .pot + 7 locales

tests/
├── Unit/
│   ├── IdentityResolverTest.php                           ⭐ precedence + SKU-collision reuse (F7 guard)
│   ├── ResolverChangeSetTest.php                          ⭐ Change_Set shape + NO-WRITES assertion (read-only invariant)
│   ├── ChangeSetVocabularyTest.php                        ⭐ action-constant stability (create/update/remove; create/rename/remove/orphan)
│   ├── HealthCheckTest.php                                ⭐ status/attribution enums + degrade-to-undetermined
│   ├── CompatibilityGuardTest.php                         ⭐ version-range gating
│   └── … existing unit tests (ActionSchedulerRearm, CategoryRename, ProductMapper*, …) …
└── Integration/
    ├── ResolverCommitterIntegrationTest.php               ⭐ resolve→commit parity; preflight writes nothing on real WP
    ├── RunLedgerResumeIntegrationTest.php                 ⭐ interrupted run resumes; no bare/duplicate products (F4/F6/F7)
    ├── ResetServiceIntegrationTest.php                    ⭐ FR-17 removes only Skwirrel-owned products
    ├── DeepLinkFilterIntegrationTest.php                  ⭐ FR-15 `skwirrel_run` filter returns exactly the run's items
    └── … existing integration tests (SyncService, PurgeHandler, SyncSafety, …) …
```

### Architectural Boundaries

**Read-only boundary (the central invariant):**
- WRITE side: `Committer` + `Reset_Service` (gated) are the ONLY classes that mutate products/terms/options.
- READ side: `Resolver`, all `Health_Check_*`, `Diagnostics_Report`, preflight — never write.
- Crossing point: the `Change_Set` value object — produced read-only, consumed by the writer.

**Identity boundary:** `Identity_Resolver` is the sole owner of upsert-key precedence and
simple↔variation reconciliation. `Resolver`, `Committer`, `Reset_Service`, `Product_Lookup` all
resolve identity through it — no other class re-implements the precedence.

**External API boundary:** `JsonRpc_Client` is the only caller of the Skwirrel API. `Resolver`
consumes **mapped** data (via `Product_Mapper`), never raw API payloads. Credentials cross via
`Connectors::get_token()` (+ legacy fallback) — token never leaves this boundary into exports.

**Data boundary:** `Run_Ledger` owns the custom work-ledger table (per-product status + run_id).
Catalog writes go through WC CRUD (HPOS-safe). Run history extends the `skwirrel_wc_sync_history`
option; preview `Change_Set` lives in a per-user transient (ephemeral).

**UI boundary:** admin screens (`admin-health`, `admin-preflight`, `guided-setup`, `admin-settings`)
render server-side PHP only; FR-15 deep-links are native WC list URLs (`?skwirrel_run=…`), no custom
list table, no JS framework.

### Requirements → Structure Mapping

| FR / Driver | Primary files |
|---|---|
| F7 duplicates (D3) | `identity-resolver` (+ `product-lookup`) |
| Resolve/commit core (D1) | `resolver`, `change-set`, `committer` |
| F4/F6 resumability (D2) | `run-ledger`, `service` (orchestrator), `committer` |
| FR-15 deep-links (D4) | `product-deeplinks`, `run-ledger` (run_id stamp) |
| FR-16 preflight | `admin-preflight`, `resolver`, `change-set` |
| FR-17 reset | `reset-service`, `admin-preflight` (preview), `identity-resolver` (scope) |
| FR-4/5/8 health | `health-check`, `health-check-*`, `admin-health`, `history` |
| FR-6 conflicts | `conflict-signature` (+ registry filter) |
| FR-7 report | `diagnostics-report` |
| FR-9/10 resilience | `compatibility-guard`, `service` (paused state) |
| FR-11 credentials | `connectors` (exists) |
| FR-1/2/3 legibility | `guided-setup`, `admin-settings` |

### Integration Points & Data Flow
1. **Trigger** (manual / Action Scheduler / loopback) → `Service` orchestrator.
2. `Compatibility_Guard` gate → if incompatible, set "paused (reason)" and stop.
3. `Service` → `JsonRpc_Client` (fetch) → `Product_Mapper` (map) → `Resolver` (build `Change_Set`).
4. **Preflight path** stops here: render `Change_Set` (no writes).
5. **Commit path:** `Run_Ledger` enqueues per-product items → `Committer` applies each atomically
   (identity via `Identity_Resolver`, stamps `_skwirrel_synced_at` + `_skwirrel_last_run_id`) →
   thin deferred relations/variable-assembly pass → advance `last_sync` only when ledger drains.
6. Result + run_id → `history`; counts deep-link via `product-deeplinks`.
7. **Diagnostics** (independent): `Health_Check` runs checks + conflict signatures → `admin-health`
   / `diagnostics-report` (read-only, surfaces `Logger`).

## Architecture Validation Results

### Coherence Validation ✅
- D1–D7 reinforce rather than conflict; the resolve→commit split is the spine, D3 feeds it, D2
  executes it, D4 rides D2, D5/D6 are loosely coupled and inherit the read-only invariant.
- Patterns and naming/hook rules extend (don't fight) existing WPCS/project-context rules.
- Flat `includes/` tree and boundaries realise every decision; no decision lacks a home file.

### Requirements Coverage Validation
All 17 FRs architecturally supported; FR-14 documents-path integration test still to author (◐);
FR-1/2/3 UI specifics await the UX step (expected). NFRs covered; performance deliberately deferred.

### Review Refinements (Party-Mode — fold into the decisions)
A second-implementer review (Architect / Senior Eng / Test Architect) surfaced five sharpenings.
These AMEND the referenced decisions:

1. **D2 — "per-product-atomic" → "per-ENTITY-atomic; ledger row = (entity, phase)".**
   - The atomic unit is a **simple product OR a product group**, not a member product.
   - Ledger phases are explicit: `RESOLVE → UPSERT_CORE → MEDIA → RELATIONS/VARIATION_ASSEMBLY`.
     `UPSERT_CORE` (post+meta, local) is the real atomic step; `MEDIA` (network I/O) keys on the
     existing `_skwirrel_url_hash` so re-fire skips; `VARIATION_ASSEMBLY` is the deferred pass and runs
     only after every group member's `UPSERT_CORE` is `done`.
   - Ledger columns: `phase`, `status(pending|running|done|failed)`, `attempts`, `claimed_at`, `idempotency_key`.
   - After assembly: `wc_delete_product_transients($parent_id)` (else storefront/admin disagree on variations — F4-like ghost).

2. **D3 — simple↔variation = two ONE-WAY gated transitions, never a free bidirectional reconcile.**
   - Convert IN PLACE via `Committer::convert_simple_to_variation($wc_id, $parent_id)`; NEVER delete-recreate
     (delete-recreate loses post_id/permalink/media AND re-mints the suffixed-SKU duplicate = F7).
   - A group member is NEVER committed as simple "provisionally" — it parks `pending_assembly`.
   - simple→variation only with a confirmed parent in the deferred pass; variation→simple only when the
     group genuinely left the payload. Bidirectional-in-one-run is what oscillates.

3. **D1/D5 — the read-only invariant must be ENFORCED, and split into two claims.**
   - (i) `Change_Set` is an immutable value object (readonly props, holds scalars/arrays — NEVER a `WC_Product`).
   - (ii) Resolver/Health are *persistence-read-only w.r.t. Skwirrel-owned data* (NOT side-effect-free —
     WC reads still warm caches; scope health checks to bounded COUNT/EXISTS/sampled queries, never full hydration).
   - Enforcement: a runtime write-guard (only `Committer`/`Reset_Service` may write; throws otherwise) PLUS a
     phpstan/phpcs sniff forbidding `wp_insert_post`/`wp_update_post`/`->save(`/`update_*_meta` in resolver/health files.

4. **D2/D4 — idempotency via an ATOMIC CLAIM, not a status flag; run_id allocated once.**
   - Claim: `UPDATE … SET status='running',claimed_at=NOW(),attempts=attempts+1 WHERE idempotency_key=%s
     AND status IN('pending','failed') AND claimed_at IS NULL` → affected-rows 1 = own it, 0 = skip
     (TOCTOU-safe under AS double-fire + loopback). `idempotency_key = sha1(run_id:product_id:phase)`.
   - Stale-claim reaper resets `running` rows older than N minutes = crash recovery.
   - `run_id` is allocated ONCE per `run_sync`, persisted in the ledger header, READ BACK by every
     continuation request (never re-minted) — and is the SAME value as D4's `_skwirrel_last_run_id`.

5. **D7 — the preview transient is NEVER a commit input; commit ALWAYS re-resolves.**
   - Transients are evictable and a large Change_Set may not survive preview→commit; treating the stored
     preview as commit intent is a TOCTOU bug. Preview is display-only; commit re-runs the (cheap) Resolver.

### Gap Analysis Results
**Critical gaps:** none.
**Important gaps — now with resolution direction:**
1. **Migration (phased → ledger):** on the upgrade hook, **fence** via `skwirrel_wc_sync_migrating` that
   D6's Compatibility_Guard treats as a hard pause ("paused: upgrading"); **void in-flight phased state +
   set `skwirrel_wc_sync_force_full_sync`** (the old run was non-resumable by definition — don't migrate a
   corpse); schema migration **forward-only/additive** with a `schema_gen` tag (ignore, don't interpret,
   legacy rows). Downgrade ⇒ documented "requires a full resync". → migration story.
2. **Variable assembly:** resolved by refinement #1/#2 above. → assembly story carries the phase/parent rules.
3. **FR-14 documents-path 7.0 integration test:** still to author.
4. **Pillar A UI:** UX step (next in sequence).

### Non-Negotiable Test Gate (block release)
1. **Resolver idempotency property test** (unit): `resolve(state_after_commit)` is a no-op/REUSE — collapses the resume-duplication class cheaply.
2. **Crash-resume golden-state test** (integration) via an injectable crash seam (`before_ledger_mark`), parameterized over EVERY commit boundary: interrupted final state == uninterrupted final state (snapshot+diff).
3. **Variable-assembly crash-between-parent-and-variations test** (integration): orphaned shell reconciles, never duplicates.
4. **Migrate-mid-run (half-drained ledger) test** (integration): the duplicate-key invariant holds.
5. **Read-only write-guard** wrapping all preflight + health executions: any write throws.
Plus a reusable canary run at the END of every integration test:
`SELECT meta_value,COUNT(*) FROM …postmeta WHERE meta_key='_skwirrel_external_id' GROUP BY meta_value HAVING COUNT(*)>1` → empty.
Required seams: **clock** (deterministic timestamps), **crash** (throwable hook), **real ledger as SUT** (not mocked).
Note: if the crash seam can't be cleanly injected, the commit→ledger-mark boundary isn't truly atomic — fix the code, not the test.

### Architecture Completeness Checklist
**Requirements Analysis:** [x] context [x] scale [x] constraints [x] cross-cutting concerns
**Architectural Decisions:** [x] critical decisions (versions N/A) [x] stack [x] integration patterns [x] performance (deferred by decision)
**Implementation Patterns:** [x] naming [x] structure [x] communication (hooks + change-set vocab + ledger claim) [x] process (read-only enforcement, error/degradation, enums)
**Project Structure:** [x] tree [x] boundaries [x] integration points [x] requirements→structure map

### Architecture Readiness Assessment
**Overall Status:** READY WITH MINOR GAPS — all 16 checklist items confirmed, no critical gaps; the
review refinements are folded into the decisions and the four important gaps now carry explicit
resolution direction to be executed in stories (migration, variable-assembly) plus the FR-14 test and
the UX step.
**Confidence Level:** High (raised by the review — the two D2/D3 sharpenings close the gap where the
fixes for F4/F6/F7 could have re-collided).
**Key Strengths:** structural read-only guarantee; one root + one fix for F4/F6/F7; brownfield-respectful;
loosely-coupled diagnostics de-risk sequencing; the review's invariants make the resume path provably safe.
**Future Enhancement:** deferred sync-speed chapter (benefits from per-entity-atomic); per-field preview diff; richer conflict-signature library.

### Implementation Handoff
**First Implementation Priority:** test harness FIRST (recording-`$wpdb` fake + crash/clock seams +
integration claim-race), then D3 `Identity_Resolver` (F7 lock), then D1 `Resolver`+`Change_Set`, then
D2 `Committer`+`Run_Ledger` with the (entity,phase) ledger + atomic claim. UX step runs in parallel for Pillar A.
**AI Agent Guidelines:** follow decisions/patterns/boundaries exactly; route ALL writes through
`Committer`/`Reset_Service`; resolve identity only via `Identity_Resolver`; commit re-resolves (never trusts the preview); keep the three gates + the five non-negotiable tests green.
