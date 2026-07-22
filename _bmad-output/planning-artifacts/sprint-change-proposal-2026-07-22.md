# Sprint Change Proposal — Epic 1 as-built re-baseline

- **Date:** 2026-07-22
- **Author:** Jos (via correct-course workflow)
- **Scope classification:** Moderate (backlog reorganization — PO/DEV)
- **Affected artifacts:** `epics.md`, `sprint-status.yaml` (both updated by this proposal); `prd.md` unaffected; Epic 2 flagged for a dependency
- **Trigger:** Epic 1 ("Reliable, resumable, duplicate-free sync core") — plan vs. code divergence

---

## Section 1 — Issue Summary

**Problem statement.** `sprint-status.yaml` (last touched 2026-06-12) showed all 18 Epic 1 items as
`backlog`, but the sync core was in fact substantially built. On inspection the cause is deeper than a
stale checkbox: **Epic 1 was authored as a ground-up rewrite** — new `Identity_Resolver`, `Resolver` +
immutable `Change_Set`, `Run_Ledger`, `Committer`, `Reset_Service`, plus a recording-`$wpdb` test
harness with crash/clock seams — **and none of those classes were ever built.** Instead, across releases
3.11.0→3.11.3, the team **hardened the existing queue-based phase-cursor core** and achieved most of
Epic 1's *outcomes* by different means. Some of this work was done outside the BMAD tracking flow, so the
plan never caught up. Plan and code have diverged in **approach**, not just in status.

**How discovered.** During work on the `feat/deprecated-lifecycle` branch, the developer flagged that
Epic 1 "is solved already a lot" while the tracker said otherwise. A two-pass evidence audit
(stories 1.1–1.10, then the Revisions-v2 items 1.11–1.15) reconciled every story against the actual code.

**Evidence (representative).**
- Grep for every planned rewrite class (`Run_Ledger`, `Change_Set`, `Identity_Resolver`, `Committer`,
  `Reset_Service`, `expectNoWrites`, recording-`$wpdb`, `before_ledger_mark`) → **zero hits**.
- As-built mechanisms are real and tested: `resolve_sku_identity()` + `IdentityReuseTest` (F7);
  draft-until-complete + `_skwirrel_synced_at` (`PerProductAtomicIntegrationTest`); content-hash change
  gate (`ContentHashTest`, `UnchangedGateTest`); `SyncMutex` (`SyncMutexTest`); `check_abort()`
  boundary-honored AJAX endpoints; WP7 documents path via `ensure_uploads_approved_download_directory()`.

---

## Section 2 — Impact Analysis

### Epic Impact
- **Epic 1** — cannot be "completed as originally planned" because its architecture was not adopted.
  Re-baselined to as-built: **9 done, 5 dropped, 6 open**. Stays `in-progress` (slim remainder).
- **Epic 2 ("See and control what a sync changes")** — **has an unmet dependency.** Its preflight
  (FR-16) needs a `Change_Set` forecast, and its result deep-links (FR-15) need a
  `_skwirrel_last_run_id` per-entity stamp. Neither exists on the queue core today. These are carried as
  the load-bearing open stories 1.3 and 1.7 (kept in Epic 1) and must land before those Epic 2 stories
  start.
- **Epics 3 & 4** — no direct impact from this change; they build on the same queue core, which is sound.

### Story Impact (Epic 1 final verdicts)

| Story | Verdict | Evidence / rationale |
|---|---|---|
| 1.1 Test harness, seams & canary | **DONE** | Pest unit+integration suites shipped; crash/clock seams deferred (only needed to prove a ledger) |
| 1.2 Identity resolver / SKU reuse (F7) | **DONE** | `resolve_sku_identity()` (`product-upserter.php`) + `IdentityReuseTest` |
| 1.3 Resolver + Change_Set | **OPEN (rescoped)** | No `Change_Set`; reduced to a read-only forecast on the queue core — Epic 2 FR-16 dep |
| 1.4 Read-only enforcement | **DROPPED** | Ledger/resolver-split only; no consumer under as-built |
| 1.5a Run_Ledger table + DAO | **DROPPED** | No ledger; superseded by queue phase-cursor |
| 1.5b Committer-through-ledger | **DONE** | Outcome via draft-until-complete + `_skwirrel_synced_at` + unchanged gate |
| 1.5c Atomic claim / reaper / crash-resume | **DROPPED** | Concurrency via `SyncMutex`/heartbeat; crash-resume golden test not built |
| 1.6 Variable / grouped assembly | **DONE** | `sync_grouped_products_first()`, in-place variation update, transient flush |
| 1.7 Per-run marker & run header | **OPEN (rescoped)** | Run header done; `_skwirrel_last_run_id` stamp missing — Epic 2 FR-15 dep |
| 1.8 Resumable progress UI | **DONE** | Reactive banner/toast shipped; explicit Resume affordance deferred |
| 1.9a/1.9b Phased→ledger migration | **DROPPED** | Nothing to migrate to |
| 1.10 WP7 documents-path | **DONE** | `ensure_uploads_approved_download_directory()` via `is_valid_path()` (regression test → 1.14) |
| 1.11 Progress + abort AJAX | **DONE** | `wp_ajax_..._status` + `..._abort`, boundary-honored via `check_abort()` |
| 1.12 Clean uninstall / deactivate | **OPEN (absent)** | No `uninstall.php`, no deactivate hook — real gap, not ledger-related |
| 1.13 No-orphaned-variation suite | **OPEN (absent)** | Only queue-row orphans tested; WC-level invariant unpinned |
| 1.14 Regression-canary suite | **OPEN (4/6)** | Missing object-cache-bust test + real price-zero-out *behavior* test |
| 1.15 Upgrade-from-3.10.2 smoke | **OPEN (absent)** | Activation/upgrade path untested against real 3.10.2 fixtures |

### Artifact Conflicts
- **PRD (`prd.md`)** — no conflict. Core goals (duplicate-free, resumable, safe updates) still hold; the
  MVP is achievable. Only the *implementation approach* changed.
- **Architecture (`architecture.md`)** — describes the ledger/Resolver design (D1–D7). It now documents an
  **intended** design that was superseded. Not rewritten by this proposal; flagged as a follow-up so
  architecture reflects the queue-based reality. (Recommended, non-blocking.)
- **UX design** — no conflict; the shipped reactive banner satisfies the progress-UI intent (Resume
  affordance deferred).
- **`epics.md`, `sprint-status.yaml`** — updated by this proposal (see Section 4).

### Technical Impact
- No code changes are mandated by this proposal — it reconciles plan to code.
- The 6 open stories are genuine remaining work on the queue core (all ledger-independent).

---

## Section 3 — Recommended Approach

**Selected: Direct Adjustment (re-baseline to as-built).** Chosen over a rollback (there is nothing to
roll back — the as-built core is shipping and healthy) and over an MVP review (MVP goals are intact).

**Rationale.**
- **Effort:** Low for the re-baseline itself (documentation/tracking only).
- **Risk:** Low — accepting a tested, shipped core; no behavioral change.
- **Momentum & sustainability:** Keeps the team on the architecture they actually maintain, rather than
  reviving a large rewrite whose outcomes are already met.
- **Trade-off accepted:** the ledger's *provable* crash-resume golden-state guarantee is not present;
  crash-safety rests on draft-until-complete + `SyncMutex` + "advance `last_sync` only on completion."
  Judged acceptable given the shipped behavior and test coverage.

---

## Section 4 — Detailed Change Proposals (applied)

### 4.1 `sprint-status.yaml`
- `epic-1: backlog → in-progress`; `last_updated → 2026-07-22`.
- Marked **done:** 1-1, 1-2, 1-5b, 1-6, 1-8, 1-10, 1-11.
- Marked **open (backlog):** 1-3, 1-7, 1-12, 1-13, 1-14, 1-15 (each with an inline reason comment).
- Commented out as **DROPPED** (history-preserving): 1-4, 1-5a, 1-5c, 1-9a, 1-9b.

### 4.2 `epics.md`
- Added a **"Revisions v3 — as-built re-baseline"** authoritative banner at the top of Epic 1 mapping
  every story to DONE/DROPPED/OPEN with the reason.
- Rescoped **Story 1.3** to a read-only Change_Set forecast on the queue core (no ledger dep).
- Rescoped **Story 1.7** to just the `_skwirrel_last_run_id` product+term stamp (run header already done).

### 4.3 Follow-up (not applied here, recommended)
- Update `architecture.md` so the sync-core section documents the queue-based phase-cursor design rather
  than the superseded ledger design.

---

## Section 5 — Implementation Handoff

**Scope: Moderate → Product Owner / Developer.**

- **PO / DEV:** accept the re-baselined tracking; schedule the 6 open Epic 1 stories. Sequencing:
  1.3 + 1.7 are prerequisites for Epic 2 (do them before Epic 2 preflight/deep-link stories start);
  1.12–1.15 are independent hardening and can land any time after their subjects exist.
- **DEV:** the 6 open stories are real code/test work on the queue core (no ledger).
- **Architect (optional follow-up):** reconcile `architecture.md` to the as-built core.

**Success criteria.**
- `sprint-status.yaml` and `epics.md` reflect reality (done ✅).
- Epic 2 does not start its preflight/deep-link stories until 1.3 and 1.7 land.
- Epic 1 closes to `done` only when 1.3, 1.7, 1.12, 1.13, 1.14, 1.15 are complete.
