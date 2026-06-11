---
title: Skwirrel PIM sync for WooCommerce — Simplicity, Diagnostics & WP 7.0 Resilience
status: ready-for-architecture
created: 2026-06-10
updated: 2026-06-11
---

# PRD: Skwirrel PIM sync for WooCommerce — "Simple, Self-Diagnosing, Update-Proof"
*Working title — confirm.*

## 0. Document Purpose

This PRD is for the Skwirrel product/engineering team and the downstream BMad workflows (UX, Architecture, Epics & Stories). It defines the **next maturity chapter** of an already-shipping plugin (v3.10.2) — not a feature land-grab. Requirements are grouped into features with globally numbered FRs nested under them; cross-cutting quality lives in its own NFR section; assumptions are tagged inline and indexed in §9. It builds on the existing technical reference (`CLAUDE.md`, `.claude/rules/*`, `project-context.md`) and does not duplicate them — where an FR touches existing architecture, it references it rather than restating it.

> **Priority 0 — read first.** The plugin **had issues on WordPress 7.0**: since 7.0 shipped, it no longer worked correctly. **This was the first goal of this PRD — and it is now done: WP 7.0 operation was restored and shipped in v3.10.2 (2026-06-11).** §4.0 is retained as the record of what was actually broken and fixed (the highest-priority gap — Open Question 7 — is now closed). The PRD's *forward* work is therefore the three maturity pillars (A simplicity, B diagnostics, C resilience). FR IDs are stable reference handles, **not** a priority order.

## 1. Vision

Skwirrel PIM sync connects a WooCommerce shop to the Skwirrel PIM system so that product data — descriptions, prices, images, documents, categories, ETIM attributes, variations — flows in automatically and stays current, with no manual data entry. Today it does this well. But it is operated by people who are **not** webshop engineers — store owners and marketeers who want a working catalog, not a configuration project. As the plugin has grown, its **settings and their interdependencies have multiplied**, and when something looks wrong, the hardest question to answer is the most common one: *"is this the plugin, or is it another plugin, the theme, or the WordPress stack?"*

This chapter makes the plugin **simple to operate, honest about its own health, and resilient to change.** Three things must become true. **(1) Configuration is legible** — sensible defaults, guided setup, and a settings surface a non-technical user can navigate without fear. **(2) The plugin diagnoses itself** — a customer (not a developer) can, in minutes and without contacting support, see whether a problem originates in Skwirrel's plugin or somewhere else in their site, with the evidence to act on it. **(3) Updates don't hurt** — a WordPress or WooCommerce update (the WP 7.0 era and beyond), or a newly installed plugin, does not silently break the sync. *(Sync speed is a real goal too, but a later chapter — out of scope here; see §4.4 / §5.)*

Success is a customer who installs the plugin, configures it correctly the first time, trusts what it tells them, and never fears the "Update" button.

## 2. Target User

### 2.1 Jobs To Be Done
- **Functional:** "Get my Skwirrel product catalog into my WooCommerce shop and keep it current — without entering data by hand."
- **Functional:** "Set this up correctly without hiring a developer."
- **Emotional (control):** "When my shop looks wrong, I want to know *whether it's this plugin's fault* without filing a support ticket and waiting."
- **Emotional (safety):** "I want to click 'Update WordPress' and trust that my shop won't break."
- **Contextual (speed):** "When I change a product in Skwirrel, I want to see it live in my shop quickly."
- **Social:** "I don't want to look incompetent to my customers because my product pages are broken or stale."

### 2.2 Non-Users (v1)
- **Agencies / developers building bespoke integrations** — **secondary audience (decided 2026-06-11): they *can* use the plugin and we must not actively remove control they rely on (see SM-C2), but the design target is the non-technical owner and we do not optimize for power-user extensibility in this chapter.**
- **Stores not using Skwirrel PIM** — out of scope by definition.
- **Headless / non-WooCommerce storefronts** — this chapter targets the standard WP-admin + WooCommerce surface.

### 2.3 Key User Journeys

- **UJ-1. Nadia sets up the sync without calling anyone.**
  - **Persona + context:** Nadia runs a small building-supplies shop; she's comfortable in WP-admin but is not a developer and is on a tight budget.
  - **Entry state:** Plugin freshly installed, WooCommerce already active, Skwirrel API token in hand.
  - **Path:** She opens the plugin, lands on a **guided setup** rather than a wall of fields; pastes her token; the plugin verifies the connection live and confirms it can reach Skwirrel; she accepts sensible defaults for the handful of choices that matter; she runs a first sync.
  - **Climax:** A clear "Connected ✓ — 1,240 products synced" result with a plain-language summary, not a log dump.
  - **Resolution:** Catalog is live; she knows where to look if something changes.
  - **Edge case:** Token wrong → one specific, plain-language error ("the token was rejected by Skwirrel") with the one next step, not a stack trace.

- **UJ-2. Marco answers "is it us or them?" himself.**
  - **Persona + context:** Marco, a marketeer, notices product images stopped appearing after he installed an unrelated optimization plugin.
  - **Entry state:** Shop live, something visibly wrong, no developer on hand.
  - **Path:** He opens the plugin's **Health / Diagnostics** screen; it runs a self-check and reports in plain language: the Skwirrel connection is healthy, the last sync succeeded, but **a detected conflict** — another plugin is intercepting image handling — is the likely cause, with a "copy report for support" button.
  - **Climax:** He learns in two minutes that the Skwirrel plugin is *not* the culprit and which plugin is implicated — and either fixes it himself or sends a precise report to support.
  - **Resolution:** Problem attributed correctly; no wasted support round-trip blaming the wrong component.
  - **Edge case:** If the diagnostics *do* implicate the Skwirrel plugin, the report says so honestly and points to the relevant setting or known issue.

- **UJ-3. Saskia updates WordPress without fear.**
  - **Persona + context:** Saskia owns the shop and sees the "WordPress 7.x available" prompt.
  - **Entry state:** Live shop, pending WP/WC update.
  - **Path:** Before/after the update, the plugin's health check confirms compatibility with the running WP/WC versions; if the plugin detects an incompatibility, it warns *before* damage and degrades safely rather than breaking the sync.
  - **Climax:** She updates; the next sync runs normally; the health screen stays green.
  - **Resolution:** Trust in the "Update" button is preserved.

- **UJ-4. Pieter reorganizes the category tree without fear of a silent purge.**
  - **Persona + context:** Pieter manages the Skwirrel side; he's about to delete a category subtree (e.g. *Webshop → Radiocom* and all its submenus) and rename another, and wants to know the blast radius before it hits the live shop. *(Drawn from a real customer case, 2026-06-11.)*
  - **Entry state:** Live shop; a structural category change staged upstream in Skwirrel.
  - **Path:** Before committing, he runs a **preflight** (FR-16). It forecasts not just product counts but the **category-structure impact** — "1 category + 6 subcategories would be removed; 240 products re-homed/detached; 1 category renamed *Draagtassen → Hoesjes*." He sees the structural blast radius first.
  - **Climax:** A change he'd otherwise have made blind — and feared — is legible up front. He proceeds; the result screen (FR-15) confirms exactly what changed, clickable down to the affected products and categories.
  - **Resolution:** He trusts category reorganizations. And if he ever wants a true clean slate, the opt-in **"start over / clean all"** (FR-17) is there — also previewed before it runs.
  - **Edge case:** A rename's propagation isn't instant (real-world: delete only lands on a full sync with no collection filter and no attached products); sync state (FR-8) explains the *why* rather than leaving it a mystery.

## 3. Glossary

- **Sync** — One execution of the product-import process from Skwirrel PIM into WooCommerce. May be **full** or **delta**. (Existing concept; see `.claude/rules/sync-service.md`.)
- **Health Check** — An on-demand, self-service self-diagnostic run that evaluates the plugin's connection, configuration, recent sync outcomes, and environment, and produces a plain-language verdict.
- **Diagnostics Report** — The exportable/copyable output of a Health Check, intended for the customer to read and optionally hand to support.
- **Conflict** — A detected interaction with another plugin, the theme, or the WP/WC stack that is the likely or confirmed cause of a problem (e.g., another plugin hooking the same image/attachment pipeline).
- **Fault Attribution** — The Health Check's determination of *whether* an observed problem originates in the Skwirrel plugin ("ours") or elsewhere ("environment").
- **Guided Setup** — A first-run, step-ordered configuration experience that replaces the full settings wall for new users.
- **Sensible Defaults** — Pre-selected setting values that work for the typical non-technical store without modification.
- **Environment Snapshot** — A captured summary of WP version, WC version, PHP version, active plugins, theme, and relevant server limits, included in a Diagnostics Report.
- **Setting Relation** — A dependency between two settings where one's value changes whether another applies or is valid (the "relations" that currently confuse users).

## 4. Features

### 4.0 Restore Correct Operation on WordPress 7.0 *(Priority 0 — ✅ DELIVERED in v3.10.2, 2026-06-11)*
**Status:** **RESOLVED and shipped in v3.10.2.** Root causes were found, fixed, and verified on real clean-DB *and* delta sync runs on the WP 7.0 / WooCommerce 10.x stack. The original "Connectors token path" hypothesis was **refuted** — a successful production run authenticated fine through that path. The actual breaks were several independent WP/WC-7.0-era changes:
- **(F2 — the headline) Scheduled syncs silently stopped after an auto-update.** The recurring Action Scheduler job was armed only on activation/settings-save; a WP.org auto-update skips activation, so the schedule was never re-armed. Manual "Sync now" kept working, masking it. *Fix:* re-arm on every plugin version change + idempotent self-heal on admin load.
- **(F1) WP 7.0 Connectors registration emitted a `_doing_it_wrong` notice** — registered without the now-required non-empty `type`. *Fix:* `type => 'service'`.
- **(F3) Category renames/re-parenting didn't propagate** — a category matched by Skwirrel ID was left untouched. *Fix:* reconcile via `wp_update_term()`, only when the value actually differs.
- **(Documents) All product documents/downloads silently failed to attach** — the approved-download-directory pre-check called WooCommerce's removed `is_approved_directory()`, which threw and skipped the registration, so WC rejected every file. *Fix:* use `is_valid_path()`; the uploads dir now auto-registers.
- Plus polish: corrected the Connections-Screen link (`options-connectors.php`) and branded `logo_url`.

This was the top-priority deliverable and it gated everything below; that gate is now passed. The diagnostics work in §4.2 exists partly *because* "is it us or the stack?" was hard to answer for exactly this class of break — every root cause here was a silent WP/WC change the plugin couldn't self-report. Realizes UJ-3. The forward work in this PRD is now Pillars A/B/C. *(Original hypothesis-stage description retained in git history.)*

**Functional Requirements:**

#### FR-13: Syncs work on WordPress 7.0 — ✅ MET (v3.10.2)
Full and delta syncs complete successfully on WP 7.0, authenticating correctly whether the API token is sourced from the Connectors store (7.0 path) or the legacy option (6.9 fallback). The plugin's core capabilities (connect, sync, images, documents, categories, variations, admin screens) function correctly on WP 7.0, matching pre-7.0 behavior. **Verified:** clean-DB full sync (1,321 created, 0 failed) and a delta sync (1,199 updated, 0 failed) both completed on the WP 7.0 / WC 10.x stack with documents attaching and no fatal admin errors.

**Consequences (testable):**
- On WP 7.0, a sync authenticates and retrieves the API token via the Connectors path without error; a sync run completes (created/updated counts as expected), it does not fail at the auth/connection step.
- The token-resolution path has a verified fallback: if the Connectors store is unavailable or empty, the plugin falls back to the legacy `skwirrel_wc_sync_auth_token` option rather than breaking the sync.
- A full sync and a delta sync both complete on WP 7.0 with no fatal errors on any plugin admin screen.
- No regression is introduced on the WP 6.9 floor by the 7.0 fix (both versions verified).
- `[ASSUMPTION: Connectors token-resolution is the root cause. If investigation finds the break is elsewhere (e.g., a different 7.0 API change), FR-13 stays the goal but the specifics update — see Open Question 7.]`

#### FR-14: Regression coverage for the WP 7.0 breaks — ◐ PARTIAL (v3.10.2)
The fixes are covered by automated tests so the breaks cannot silently return.

**Consequences (testable):**
- ✅ Unit coverage added for the scheduler re-arm (`ActionSchedulerRearmTest`) and the category rename/re-parent reconciliation (`CategoryRenameTest`), including the cyclic-parent guard. All three gates (`pest` / `phpstan` L6 / `phpcs`) pass.
- ◐ **Still open:** a wp-env **integration** test that runs a sync on a real WP 7.0 + WooCommerce 10.x environment and asserts it completes *with documents attaching* (the `is_valid_path` path) — currently verified by manual real-stack runs, not yet automated. **Carry into the Architecture/Dev track.**
- The shipped unit tests fail before their respective fixes and pass after, and are part of the pre-release gate.

**Notes:** `[RESOLVED 2026-06-11]` The WP 7.0 break is no longer a hypothesis — it was a **cluster** of independent WP/WC-7.0 changes (see §4.0 Status), not a single Connectors-token fault (that hypothesis was refuted by a successful production run). The one remaining gap is **automated** integration coverage of the documents/`is_valid_path` path on a real 7.0 stack; until that exists, the documents fix is guarded only by manual real-run verification. This automation is the FR-14 remainder to close in the next track.

### 4.1 Guided Setup & Configuration Simplification *(Pillar A)*
**Description:** Replace the "wall of settings" first impression with a **Guided Setup** for new installs, and reorganize the ongoing settings surface so a non-technical user can understand it. Settings are grouped by intent (Connection, What to sync, How it looks, Advanced), **Sensible Defaults** are pre-applied so an unconfigured install is already a working install, and **Setting Relations** are made visible (a setting that has no effect given another setting's value is shown as such, not silently inert). Realizes UJ-1.

**Functional Requirements:**

#### FR-1: Guided first-run setup
A new user can complete initial configuration through an ordered, step-based flow (connect → verify → choose essentials → first sync) instead of the full settings page. Realizes UJ-1.

**Consequences (testable):**
- On a fresh install with no saved settings, opening the plugin presents Guided Setup, not the full settings table.
- The flow cannot advance past "connect" until the API connection is verified live against Skwirrel.
- Completing the flow results in a working configuration that can run a sync with zero additional field edits.
- Guided Setup is dismissable and does not reappear once setup is complete.

#### FR-2: Sensible defaults for an unconfigured install
The plugin ships defaults such that a freshly connected install syncs correctly without the user touching advanced options.

**Consequences (testable):**
- With only a valid token provided, a sync completes successfully using defaults.
- Defaults match the documented values in `project-context.md` / settings tables; no default produces a destructive action (e.g., purge is off by default).

#### FR-3: Intent-grouped settings with visible relations
The settings surface groups options by purpose and indicates when a setting is inactive because of another setting's value (a Setting Relation).

**Consequences (testable):**
- Each setting belongs to exactly one named group.
- When setting B has no effect given setting A's current value, the UI marks B as inactive/not-applicable with a one-line reason.
- No setting's meaning depends on undocumented interaction with another.

**Notes:** `[NOTE FOR PM]` The exact grouping taxonomy and which settings to demote/hide is a UX deliverable — flagged for `bmad-ux`.

### 4.2 Integrated Diagnostics, Fault Isolation & Sync Transparency *(Pillar B — core of this PRD)*
**Description:** A first-class, in-dashboard **Health Check** that a non-technical customer runs themselves to answer *"is it us or the environment?"*. It evaluates connection, configuration, recent sync outcomes, and the environment; detects **Conflicts** with other plugins/theme/stack; and produces a plain-language **Fault Attribution** plus an exportable **Diagnostics Report**. This is where the existing logging ("we log things") becomes *integrated* — surfaced as customer-readable signal rather than buried log files. This pillar also owns **sync transparency & control** — the user's "complete control" goal made concrete: sync results whose counts deep-link to the exact affected products (FR-15), a **preflight/dry-run** before committing (FR-16), and an **opt-in "start over / clean all" reset** (FR-17). The through-line: make the plugin's **structural and destructive operations** (renames, deletes, mass changes, resets) legible and safe — that is where customer fear and "is it us?" confusion actually live. Realizes UJ-2 and the control thread of UJ-1/UJ-3.

**Functional Requirements:**

#### FR-4: Self-service Health Check
A customer can run a Health Check from the plugin admin and receive a plain-language verdict covering connection, last-sync status, configuration sanity, and environment. Realizes UJ-2, UJ-3.

**Consequences (testable):**
- The Health Check is runnable by a user with `manage_woocommerce` capability without any code/CLI/log access.
- The result states an overall status (healthy / warning / problem) and a per-area breakdown.
- The verdict is phrased in non-technical language (no stack traces as the primary output).
- A Health Check run completes within a bounded time and never crashes the admin if a check fails (a failed check degrades to "could not determine," not a fatal error).

#### FR-5: Fault Attribution ("ours vs. environment")
The Health Check explicitly states whether a detected problem most likely originates in the Skwirrel plugin or elsewhere (another plugin, theme, or WP/WC stack).

**Consequences (testable):**
- When the Skwirrel connection/config is healthy but a symptom exists, the report attributes likely cause to the environment and names the implicated component when detectable.
- When the plugin itself is the cause, the report says so and links to the relevant setting or known-issue guidance.
- Attribution never silently blames "the plugin" or "the user" without evidence; every attribution cites the signal it is based on.

#### FR-6: Conflict detection
The plugin detects known classes of Conflict with other plugins/theme that affect its operation (e.g., another plugin intercepting the image/attachment pipeline, overriding product permalinks, or altering WC product save behavior).

**Consequences (testable):**
- A curated set of known conflict signatures is checked during a Health Check.
- A detected Conflict names the conflicting component and the affected capability (images, permalinks, variations, etc.).
- Absence of a known signature yields "no known conflicts detected," not a false "all clear" guarantee.

**Out of Scope:**
- Automatically resolving or disabling conflicting plugins — detection and guidance only in this chapter.

#### FR-7: Exportable Diagnostics Report with Environment Snapshot
A customer can copy/export a Diagnostics Report (including an Environment Snapshot) to hand to support in one action.

**Consequences (testable):**
- The report includes WP, WC, PHP versions, active plugins, theme, relevant server limits, plugin version, and the latest Health Check verdict.
- The report excludes secrets — the API auth token is never present in the export (consistent with the existing "token never in settings export" rule).
- Export is a single user action (copy to clipboard or download).

#### FR-8: Surfaced sync state & history in plain language
Recent sync outcomes are presented as readable status (what happened, when, how many created/updated/failed/trashed), not only as raw logs.

**Consequences (testable):**
- The last sync result and a short history are visible in the admin without opening WooCommerce log files.
- Each entry shows trigger (manual/scheduled/purge), outcome, and counts.
- A failed sync surfaces a plain-language reason and the next step.

#### FR-15: Clickable result counts → deep-link to the affected products
The created / updated / trashed counts in a sync result are not just numbers — each is a link that opens the WooCommerce **All Products** screen filtered to exactly those products, so the user can see *which* products were added, changed, or removed in that run. Realizes the "complete control" goal as traceability. *(User-requested, 2026-06-11.)*

**Consequences (testable):**
- Clicking "added (N)" opens the WC product list filtered to the N products created by that sync run; "changed (M)" and "removed (K)" do likewise.
- The filter is scoped to that specific run (e.g., by the run's timestamp/batch marker via `_skwirrel_synced_at` or a per-run marker), not "all Skwirrel products."
- "Removed" deep-links to the trashed items (WC Products → Trash, filtered) since purge moves to trash, not permanent delete.
- **Category-structure changes are surfaced in the result too** (mirroring the FR-16 forecast): categories created / renamed / removed in that run, linking to the WooCommerce Products → Categories screen where useful. So the before (preflight) and after (result) speak the same product-*and*-category language.
- A count of zero is not a dead link — it renders as plain text, not a link.

**Notes:** `[NOTE FOR PM/UX/ARCH]` Requires a per-run identity marker on touched products so the WC list can be filtered to one run. The exact filter mechanism (query var, admin URL with meta filter, or a small custom list view) is a UX + Architecture decision; the PRD fixes the *behavior* (counts are traceable to their products), not the implementation.

#### FR-16: Preflight / preview before sync (dry-run) — products *and* category structure
Before committing a sync, the user can run a **preflight** (preview / dry-run) that reports what the sync *would* do, **at both levels**, without writing any changes:
- **Products** — how many would be added, changed, and removed, and (at least for removals) which ones.
- **Category structure** — which categories would be **created, renamed, or removed/orphaned**, including how many products a removal/rename would **re-home or detach**. So a *structural* change — deleting a category subtree (e.g. Radiocom + its submenus) or a rename that moves products — is visible *before* it happens, not discovered after.

Realizes UJ (safety/control); pairs with FR-15 (same vocabulary, before vs. after — preflight shows the *forecast*, FR-15 shows the *result*). *(User-requested, 2026-06-11; user's term "preflight sync"; category-level forecast added 2026-06-11 from real customer evidence — the scary changes are at the category-tree level, not the product level.)*

**Consequences (testable):**
- A preview run mutates nothing: no products or categories created/updated/renamed/trashed, no `_skwirrel_synced_at` writes, no schedule side effects (consistent with the read-only diagnostics NFR).
- The preview reports product counts in the same added/changed/removed terms as a real result, and surfaces the would-be-removed products specifically (highest-risk) so an unintended purge is caught before it happens.
- **The preview reports category-structure changes specifically:** categories that would be created, renamed (old → new), and removed/orphaned, and for each removal/rename, the count of products that would be re-homed or detached. A whole-subtree deletion is shown as such (parent + descendants), not as an opaque number.
- The preview reflects the *current* settings (collection filter, delta vs. full, purge on/off) so it previews the sync the user would actually run.

**Out of Scope (this chapter):**
- A full field-level diff per product ("this description changed from X to Y") — preview reports *which* products/categories and *what kind* of change (create/rename/remove), not a per-field redline. `[ASSUMPTION: count- and list-level preview (products + category structure) is sufficient for v1; per-field diff is a later enhancement — confirm.]`

**Notes:** `[NOTE FOR PM/ARCH]` Preview is the heavier of the two control features: it implies a sync pipeline that can run in a "resolve + compare, don't write" mode. This dovetails with the planned **hybrid per-product-atomic sync rewrite** (the headline Architecture-step input) — a per-product resolve step that can compute the intended change is exactly what a dry-run needs. Recommend the Architecture step design the sync core so that preview and commit share one resolution path.

#### FR-17: Opt-in "Start over / Clean all" reset
An explicit, **opt-in** action that removes all Skwirrel-managed products so the user can re-sync from a clean slate ("start over"). Scoped strictly to products the plugin created/owns — it **never** touches non-Skwirrel products. Builds on the existing reset escape-hatch (3.9.1), formalized as a first-class, guarded control. *(User-requested, 2026-06-11.)*

**Consequences (testable):**
- The action removes **only** products carrying Skwirrel identity meta (`_skwirrel_external_id` / `_skwirrel_product_id`); any product the plugin did not create is left untouched. *(This is the core safety property the user emphasized — "delete all is only Skwirrel-related.")*
- It is **never** automatic: it requires explicit user confirmation and is never triggered by a sync, an update, or any default setting (consistent with the no-destructive-defaults NFR).
- Before executing, it shows what will be removed (count, and ideally the list) — reusing the FR-16 preflight vocabulary so "clean all" is itself previewable.
- After a clean, the next sync rebuilds the catalog from Skwirrel as a fresh full sync.
- `[ASSUMPTION: removal = move to Trash (recoverable), consistent with the existing purge behavior, rather than permanent delete — confirm. A "permanently delete" variant could be a second, even-more-guarded option.]`

**Feature-specific NFRs:**
- Diagnostics and preview run read-only — a Health Check (FR-4), the sync-state surface (FR-8), and Preflight (FR-16) must never mutate products, settings, or trigger a sync as a side effect.
- **FR-17 is the deliberate exception:** it *is* destructive by design, so it is gated by explicit opt-in + confirmation + a preflight-style "what will be removed" summary, and is scoped to Skwirrel-owned products only. Destructiveness is never a default or a side effect.

### 4.3 WP 7.0-Era Resilience & Safe Updates *(Pillar C)*
**Description:** Make WordPress/WooCommerce updates (the WP 7.0 era and beyond) and newly installed plugins *non-events* for the customer: detect incompatibility early, degrade safely instead of breaking, and prefer the modern WP 7.0 Connectors API path while keeping the 6.9 floor working. This pillar is the user-facing expression of the well-tested backbone in §10. Realizes UJ-3.

**Functional Requirements:**

#### FR-9: Compatibility self-check against running WP/WC
The Health Check verifies the plugin is operating against a supported WP/WC version range and warns when the environment drifts outside it.

**Consequences (testable):**
- Running on WP < 6.9 surfaces a clear "below minimum" warning (consistent with `Requires at least: 6.9`).
- Running on a WP/WC version newer than tested surfaces an informational "untested version" notice rather than failing silently.
- The check reflects the 7.0+ primary / 6.9+ floor posture.

#### FR-10: Safe degradation, not breakage
When the plugin encounters an unsupported or conflicting environment condition, it degrades to a safe state (sync paused with a clear reason) rather than producing fatal errors or corrupt/partial catalog state.

**Consequences (testable):**
- An incompatibility detected mid-operation halts safely without partial-write corruption of product data.
- The admin shows why sync is paused and how to resume.
- A WP/WC update does not produce a fatal error on the plugin's admin screens.

#### FR-11: Connectors API as the forward path
Where WP 7.0's Connectors API applies (e.g., credential management), the plugin uses it, with a graceful fallback only where 6.9 compatibility requires the legacy mechanism. *(Builds on existing 3.10.0 Connectors integration.)*

**Consequences (testable):**
- On WP 7.0+, credential management uses the Connectors API path.
- On WP 6.9, the legacy path remains functional.
- Neither path exposes the auth token in exports.

### 4.4 Sync Performance — Deferred to a later chapter
**Decision (2026-06-11): sync speed comes later.** Sync *performance* (full-sync wall-clock time, delta speed) is **not** a goal of this chapter — it is explicitly deferred to a future chapter. The accepted baseline (~8–12 min for a full pass over ~1,200 products; the attribute-merge + relations tail and redundant per-variation writes dominate) is recorded as known debt, to be addressed later — likely as a natural outcome of the hybrid per-product-atomic sync rewrite when that lands in the Architecture track. This chapter retains only one thing here, and it is a **correctness** property, not a speed target:

**Functional Requirements:**

#### FR-12: Delta correctness regression guard (not a speed goal)
A delta sync must touch only products that actually changed — this is a correctness guarantee, carried forward, **not** a performance target.

**Consequences (testable):**
- A delta sync with no upstream changes touches **zero** products (regression guard — consistent with the 3.10.1 delta-checkpoint fix).
- A delta sync with K changed products touches on the order of K products, not the whole catalog.
- **No** wall-clock budget (full *or* delta) is asserted this chapter — sync speed is out of scope (see §5 Non-Goals, §10).

## 5. Non-Goals (Explicit)
- **Not adding new PIM/sync data capabilities** (new field types, new product models) in this chapter — the theme is simplicity, diagnostics, and resilience. `[ASSUMPTION: user did not explicitly answer the non-goals question — confirm this boundary.]`
- **Not building a developer-extensibility/API layer** for third parties — audience is non-technical owners.
- **Not auto-fixing or disabling conflicting plugins** — diagnostics detect and guide; they do not remediate automatically.
- **Not a full settings-engine rewrite for its own sake** — reorganize for legibility, don't re-platform.
- **Not replacing WooCommerce logging** — integrate and surface it, don't reinvent the logging substrate.
- **Not optimizing sync speed/performance** — *(decided 2026-06-11)* sync speed (full *and* delta wall-clock) is deferred to a later chapter. This chapter keeps only the delta *correctness* guard (FR-12); it sets no performance budget.

## 6. MVP Scope

### 6.1 In Scope
- **FIRST — Restore WP 7.0 operation (FR-13) + regression coverage (FR-14).** This precedes and gates all other scope below; nothing else ships on a plugin that's broken on 7.0.
- Guided Setup for new installs (FR-1) + sensible defaults (FR-2) + intent-grouped settings with visible relations (FR-3).
- Self-service Health Check (FR-4) with Fault Attribution (FR-5) and exportable Diagnostics Report (FR-7).
- A first, curated set of Conflict signatures (FR-6).
- Plain-language sync state & history (FR-8) **+ clickable result counts that deep-link to the affected products (FR-15)** — FR-15 is low-cost, high-value and ships with FR-8.
- Compatibility self-check + safe degradation (FR-9, FR-10).
- Delta **correctness** regression guard (FR-12) — sync *speed* is deferred to a later chapter; no wall-clock budget here.
- **Preflight / preview-before-sync (FR-16)** at **count + list depth** (counts + which products, especially removals; no per-field diff — decided 2026-06-11). In scope, but the heaviest item; its dependence on the hybrid sync-core rewrite makes it the most likely candidate to phase if the Architecture step shows it can't share the commit path cheaply. `[NOTE FOR PM: FR-16 is the scope's swing item — confirm MVP-required vs. fast-follow once Architecture sizes it.]`
- **Opt-in "start over / clean all" reset (FR-17)** — opt-in, confirmed, Skwirrel-scoped; pairs with FR-16 preflight.

### 6.2 Out of Scope for MVP
- Automated conflict remediation — deferred. *(Detection first; remediation is a trust risk.)*
- Exhaustive conflict-signature library — start curated, expand by support data. `[NOTE FOR PM: which conflicts hurt customers most today should come from support tickets.]`
- Localized re-translation of all new diagnostic/setup strings into all 7 locales at launch — **decided (2026-06-11): English-first.** All new strings are translatable from day one (text domain `skwirrel-pim-sync`), but full nl/de/fr locale coverage may land shortly after the feature rather than gating its release.
- Connectors API expansion beyond credentials — defer to a later release.

## 7. Success Metrics

*Derived from the user's stated definition: "updates in wp wont harm this. complete control and fast syncs." Note: of the three, **"fast syncs" is deferred to a later chapter** (decided 2026-06-11) — so this chapter's metrics cover update-resilience and control, not speed. Targets are `[ASSUMPTION]` pending confirmation.*

**Primary**
- **SM-0 — Works on WP 7.0 (gating): ✅ MET (v3.10.2).** Every enumerated 7.0 symptom (F1 connector notice, F2 scheduler stoppage, F3 category rename, documents/`is_approved_directory`) was reproduced, fixed, and verified on real full + delta sync runs with no fatal admin errors. Regression coverage is in place for the scheduler and category fixes (unit), with the documents-path integration test still to be automated (FR-14 remainder). Validates FR-13, FR-14. *(This was the binary release gate for the whole effort — now passed.)*
- **SM-1 — Update survival:** Share of WP/WC updates on live installs after which the next sync succeeds without manual intervention. Target: ≥ 99%. Validates FR-9, FR-10, FR-11. `[ASSUMPTION: target]`
- **SM-2 — Self-served fault attribution:** Share of "is it broken?" situations a customer resolves or correctly attributes via the Health Check without a support ticket. Target: a majority (≥ 60%). Validates FR-4, FR-5, FR-6, FR-7. `[ASSUMPTION: target + measurement method]`
- **SM-3 — Delta correctness (not speed):** A delta sync with no upstream changes touches **zero** products; a delta with K changes touches ~K products. **Sync speed (full or delta wall-clock) is deferred to a later chapter (2026-06-11) and is not a metric here.** Validates FR-12.

**Secondary**
- **SM-4 — First-time setup success:** Share of new installs that reach a successful first sync via Guided Setup without editing advanced settings. Target: high majority. Validates FR-1, FR-2, FR-3.
- **SM-5 — Support deflection:** Reduction in support tickets attributable to misconfiguration or misattributed (non-Skwirrel) faults. Validates FR-3, FR-5, FR-6. `[ASSUMPTION: baseline needed from support data.]`

**Counter-metrics (do not optimize)**
- **SM-C1 — Correctness is never traded away:** No change in this chapter (and none in the later speed chapter) may improve any metric by skipping products, weakening validation, or zeroing prices the PIM omitted. *(Ties to the existing "don't zero-out prices" rule; pre-emptively binds the deferred speed work.)*
- **SM-C2 — Don't oversimplify into helplessness:** Hiding settings (SM-4) must not remove control that real users need; "simple" must not become "can't fix it." Counterbalances SM-4.
- **SM-C3 — Don't cry wolf:** Conflict/compat warnings (SM-2) must not generate false positives that train users to ignore them. Counterbalances SM-2.

## 8. Open Questions
7. **✅ ANSWERED (2026-06-11) — What exactly is broken on WP 7.0?** Enumerated and fixed in v3.10.2: (F2) scheduled syncs silently stopped after auto-update; (F1) Connectors `_doing_it_wrong` notice; (F3) category renames/moves not propagating; (documents) all downloadable files rejected via the removed `is_approved_directory()` WC method. The Connectors-token hypothesis was refuted by a successful production run. *(Remaining: automate the documents-path 7.0 integration test — FR-14 remainder, not a blocker for the PRD.)*
1. **✅ ANSWERED (2026-06-11)** — Developers/agencies are a **secondary** audience: usable, control not removed (SM-C2), but not optimized for. (§2.2.)
2. **⛔ OPEN** — Concrete targets for SM-1 (update survival), SM-2 (self-served attribution), and the SM-5 support-ticket baseline. *(Needs support-data input; SM-3 is now resolved via the delta-first decision.)*
3. **✅ ANSWERED (2026-06-11)** — **Sync speed is deferred to a later chapter** (superseded the earlier "delta-first" answer). No wall-clock budget, full or delta, this chapter; only the delta *correctness* guard remains (FR-12). (§4.4, §5, §10.)
4. **⛔ OPEN** — Which specific Conflicts hurt customers most today? Seeds the FR-6 signature set. *(Needs support-ticket input — start curated, expand from real tickets.)*
5. **✅ ANSWERED (2026-06-11)** — **English-first**; locales translatable from day one, full coverage may trail. (§6.2.)
6. **✅ ANSWERED (2026-06-11)** — "Complete control" includes **preflight/preview (FR-16)**, **clickable result→product deep-links (FR-15)**, and an **opt-in "start over / clean all" reset (FR-17)**. FR-16 depth = **count + list** for v1 (which products, esp. removals); **no per-field diff**. FR-17 removal = Trash (recoverable) by default `[confirm]`.
8. **✅ CONFIRMED (2026-06-11)** — §5 boundary holds: **no new PIM/catalog data capabilities** this chapter. The small operational data introduced by FR-15 (per-run marker) / FR-17 (reset) is diagnostic/control plumbing, not catalog data — inside the boundary.

## 9. Assumptions Index
- ~~§4.0 — The concrete WP 7.0 symptoms are not yet enumerated.~~ **RESOLVED 2026-06-11** — enumerated and fixed in v3.10.2 (Open Question 7).
- ~~§2.2 — Developers/agencies are a secondary, not primary, audience.~~ **CONFIRMED 2026-06-11** — secondary, don't block.
- ~~§4.4 / §7 / §10 — Concrete sync-speed target is TBD.~~ **RESOLVED 2026-06-11** — sync speed deferred to a later chapter; no wall-clock budget here, only the delta-correctness guard.
- ~~§1 / §2 — "Complete control" interpreted as visibility/safety only.~~ **EXPANDED 2026-06-11** — now explicitly includes preview (FR-16) + clickable result→product deep-links (FR-15).
- ~~§6.2 — Locale coverage at launch.~~ **CONFIRMED 2026-06-11** — English-first, locales trail.
- ~~§4.x / FR-16: count/list-level preview sufficient for v1.~~ **CONFIRMED 2026-06-11** — count + list, no per-field diff.
- ~~§5: no new PIM/sync data features.~~ **CONFIRMED 2026-06-11** — boundary holds; operational data (FR-15 marker, FR-17 reset) is inside it.
- **STILL OPEN** — FR-17: reset removal = Trash (recoverable) vs. permanent delete. *(Recommend Trash; confirm.)*
- **STILL OPEN** — §7: quantitative targets SM-1 (≥99%), SM-2 (≥60%), SM-5 baseline are inferred and need support-data confirmation (Open Questions 2, 4).

---

## 10. Cross-Cutting NFRs

- **Test backbone (the "well-tested updates" requirement):** New behavior ships with Pest unit coverage and, where it touches real WP/WC, integration coverage (wp-env). The three quality gates (`pest`, `phpstan` level 6, `phpcs`) must pass before release. Diagnostics and compatibility logic in particular are priority test targets. *(This is the engineering expression of the user's "well tested updates" goal.)*
- **Safe updates / no destructive defaults:** No new default may enable a destructive action (purge stays opt-in). Mid-operation failures must not leave partial/corrupt catalog state (FR-10).
- **Performance posture — deferred:** **Decision (2026-06-11): sync speed comes later** — no wall-clock budget (full or delta) this chapter. The only retained guarantee is **delta correctness** (delta touches only changed products; zero when nothing changed). **Real datapoints, recorded as known debt for the later perf chapter:** a full pass over ~1,156–1,321 products (with ETIM attributes + relations) runs ≈8–12 min; the attribute-merge and relations phases plus redundant per-variation category/brand writes dominate the tail. These fall to the Architecture step's hybrid per-product-atomic rewrite when performance becomes the focus.
- **Observability:** Diagnostics surface existing `Skwirrel_WC_Sync_Logger` output as customer-readable signal; logging remains the substrate, not the UI. Health Checks are read-only and side-effect-free.
- **Security:** The API auth token is never exposed in any Diagnostics Report or export (extends the existing settings-export rule to the new diagnostics surface).
- **Compatibility posture:** WP 7.0+ primary target, WP 6.9+ floor, WC 8.0+ (9.6+ for brands), HPOS-compatible; Connectors API preferred with 6.9 fallback.
- **Accessibility & i18n:** New admin UI follows WP admin conventions and `manage_woocommerce` gating; all new user-facing strings are translatable (text domain `skwirrel-pim-sync`), English source.

## 11. Why Now
WordPress 7.0 has shipped and **the plugin had issues on it — now fixed and shipped in v3.10.2 (2026-06-11).** The acute, live problem for the install base is resolved; the urgency now shifts to *durability*. The 7.0 break was instructive: every root cause was a **silent** WP/WC change the plugin couldn't self-report (a schedule that lapsed unnoticed, a WC method that vanished and dropped every document). That is precisely the "is it us or the stack?" failure class this PRD's Pillar B diagnostics exist to surface — the recovery proved the need rather than removing it. Beyond the fix, 7.0 continues to expand that failure surface and introduces the Connectors API. The plugin has reached a maturity point where accumulated configuration complexity is itself the dominant adoption/support cost for a non-technical audience. Addressing simplicity + self-diagnosis + update-resilience now — at the 7.0 inflection — protects the install base through the platform transition and converts the team's existing logging investment into customer-visible value.
