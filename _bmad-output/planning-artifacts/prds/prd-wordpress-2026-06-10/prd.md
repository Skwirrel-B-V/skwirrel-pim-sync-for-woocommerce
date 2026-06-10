---
title: Skwirrel PIM sync for WooCommerce — Simplicity, Diagnostics & WP 7.0 Resilience
status: draft
created: 2026-06-10
updated: 2026-06-10
---

# PRD: Skwirrel PIM sync for WooCommerce — "Simple, Self-Diagnosing, Update-Proof"
*Working title — confirm.*

## 0. Document Purpose

This PRD is for the Skwirrel product/engineering team and the downstream BMad workflows (UX, Architecture, Epics & Stories). It defines the **next maturity chapter** of an already-shipping plugin (v3.10.2) — not a feature land-grab. Requirements are grouped into features with globally numbered FRs nested under them; cross-cutting quality lives in its own NFR section; assumptions are tagged inline and indexed in §9. It builds on the existing technical reference (`CLAUDE.md`, `.claude/rules/*`, `project-context.md`) and does not duplicate them — where an FR touches existing architecture, it references it rather than restating it.

> **Priority 0 — read first.** The plugin currently **has issues on WordPress 7.0**: since 7.0 shipped, it no longer works correctly. **The first goal of this PRD is to make it work again on WP 7.0** (§4.0). Everything else — simplicity, diagnostics, broader resilience — follows that. FR IDs are stable reference handles, **not** a priority order: §4.0 / FR-13–FR-14 are the highest priority despite their later numbers.

## 1. Vision

Skwirrel PIM sync connects a WooCommerce shop to the Skwirrel PIM system so that product data — descriptions, prices, images, documents, categories, ETIM attributes, variations — flows in automatically and stays current, with no manual data entry. Today it does this well. But it is operated by people who are **not** webshop engineers — store owners and marketeers who want a working catalog, not a configuration project. As the plugin has grown, its **settings and their interdependencies have multiplied**, and when something looks wrong, the hardest question to answer is the most common one: *"is this the plugin, or is it another plugin, the theme, or the WordPress stack?"*

This chapter makes the plugin **simple to operate, honest about its own health, and resilient to change.** Three things must become true. **(1) Configuration is legible** — sensible defaults, guided setup, and a settings surface a non-technical user can navigate without fear. **(2) The plugin diagnoses itself** — a customer (not a developer) can, in minutes and without contacting support, see whether a problem originates in Skwirrel's plugin or somewhere else in their site, with the evidence to act on it. **(3) Updates don't hurt** — a WordPress or WooCommerce update (the WP 7.0 era and beyond), or a newly installed plugin, does not silently break the sync; and when the catalog does sync, it syncs **fast**.

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
- **Agencies / developers building bespoke integrations** — they *can* use the plugin, but the design target is the non-technical owner; we do not optimize for power-user extensibility in this chapter. `[ASSUMPTION: confirm developers are explicitly a secondary, not primary, audience.]`
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

### 4.0 Restore Correct Operation on WordPress 7.0 *(Priority 0 — first, blocks everything else)*
**Description:** Since WordPress 7.0 shipped, the plugin has issues — **syncs break**. The leading hypothesis is the **WP 7.0 Connectors module integration** (added in 3.10.0): the API auth token migrated to the Connectors credential store, and on WP 7.0 the sync appears unable to operate correctly through that path — so a sync that can't authenticate (or that fails inside the Connectors-coupled code) breaks. Before any maturity work, the plugin must be **made to work again on WP 7.0**: confirm the root cause (Connectors path is the prime suspect, not yet confirmed), fix it, and lock it with regression tests so it can't recur. This feature is the immediate, top-priority deliverable; the diagnostics work in §4.2 partly exists *because* "is it us or the stack?" was hard to answer for exactly this kind of issue. Realizes UJ-3.

**Functional Requirements:**

#### FR-13: Syncs work on WordPress 7.0
Full and delta syncs complete successfully on WP 7.0, authenticating correctly whether the API token is sourced from the Connectors store (7.0 path) or the legacy option (6.9 fallback). The plugin's core capabilities (connect, sync, images, documents, categories, variations, admin screens) function correctly on WP 7.0, matching pre-7.0 behavior. Realizes the "make it work again" goal.

**Consequences (testable):**
- On WP 7.0, a sync authenticates and retrieves the API token via the Connectors path without error; a sync run completes (created/updated counts as expected), it does not fail at the auth/connection step.
- The token-resolution path has a verified fallback: if the Connectors store is unavailable or empty, the plugin falls back to the legacy `skwirrel_wc_sync_auth_token` option rather than breaking the sync.
- A full sync and a delta sync both complete on WP 7.0 with no fatal errors on any plugin admin screen.
- No regression is introduced on the WP 6.9 floor by the 7.0 fix (both versions verified).
- `[ASSUMPTION: Connectors token-resolution is the root cause. If investigation finds the break is elsewhere (e.g., a different 7.0 API change), FR-13 stays the goal but the specifics update — see Open Question 7.]`

#### FR-14: Regression coverage for the WP 7.0 / Connectors break
The fix is covered by automated tests — including token-resolution through both the Connectors path and the legacy fallback — so the break cannot silently return.

**Consequences (testable):**
- A test exercises token resolution via the Connectors store (7.0) and via the legacy option (6.9 fallback); both yield a usable token.
- An integration test runs a sync on a WP 7.0 + Connectors environment and asserts it completes.
- These tests fail before the fix and pass after, and are part of the pre-release gate (`pest` / wp-env integration).

**Notes:** `[NOTE FOR PM]` Root cause is a strong hypothesis (Connectors token path), **not yet confirmed — and a real production log of a *successful* run (2026-06-08) complicates it**: that run authenticated fine and updated 1,156 products, so the Connectors/token path is not always the failure. The same log surfaced a concrete WC-API incompatibility: `Call to undefined method WooCommerce ...ApprovedDirectories\Register::is_approved_directory()` (caught as a WARNING when auto-approving the downloads directory). This is a textbook "is it us or the stack?" break — a WC method the plugin calls that no longer exists on the newer stack. **Two candidate failure modes now exist** (Connectors token resolution; removed/renamed WC `ApprovedDirectories` API), and likely more. Recommended immediate step: a focused investigation (`bmad-investigate`) over (a) the Connectors token-resolution path AND (b) every WC/WP core method the plugin calls that may have been removed/changed in 7.0-era WC, using a *failing* sync log as the primary input. FR-13's specifics stay generic until a broken-run log is captured.

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

### 4.2 Integrated Diagnostics & Fault Isolation *(Pillar B — core of this PRD)*
**Description:** A first-class, in-dashboard **Health Check** that a non-technical customer runs themselves to answer *"is it us or the environment?"*. It evaluates connection, configuration, recent sync outcomes, and the environment; detects **Conflicts** with other plugins/theme/stack; and produces a plain-language **Fault Attribution** plus an exportable **Diagnostics Report**. This is where the existing logging ("we log things") becomes *integrated* — surfaced as customer-readable signal rather than buried log files. Realizes UJ-2.

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

**Feature-specific NFRs:**
- Diagnostics must run read-only — a Health Check must never mutate products, settings, or trigger a sync as a side effect.

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

### 4.4 Fast Syncs *(success dimension: "fast syncs")*
**Description:** Make syncs visibly fast for the typical catalog, because perceived speed is part of "complete control." This is primarily realized through the NFR performance budget in §10, surfaced to the user via sync state (FR-8).

**Functional Requirements:**

#### FR-12: Predictable, bounded sync performance
A sync of a typical catalog completes within a target time budget, and delta syncs only touch changed products.

**Consequences (testable):**
- A delta sync with no upstream changes touches zero products (regression guard — consistent with the 3.10.1 delta-checkpoint fix).
- Sync time for a reference catalog stays within the budget defined in §10. `[ASSUMPTION: concrete target TBD — see §10.]`

## 5. Non-Goals (Explicit)
- **Not adding new PIM/sync data capabilities** (new field types, new product models) in this chapter — the theme is simplicity, diagnostics, and resilience. `[ASSUMPTION: user did not explicitly answer the non-goals question — confirm this boundary.]`
- **Not building a developer-extensibility/API layer** for third parties — audience is non-technical owners.
- **Not auto-fixing or disabling conflicting plugins** — diagnostics detect and guide; they do not remediate automatically.
- **Not a full settings-engine rewrite for its own sake** — reorganize for legibility, don't re-platform.
- **Not replacing WooCommerce logging** — integrate and surface it, don't reinvent the logging substrate.

## 6. MVP Scope

### 6.1 In Scope
- **FIRST — Restore WP 7.0 operation (FR-13) + regression coverage (FR-14).** This precedes and gates all other scope below; nothing else ships on a plugin that's broken on 7.0.
- Guided Setup for new installs (FR-1) + sensible defaults (FR-2) + intent-grouped settings with visible relations (FR-3).
- Self-service Health Check (FR-4) with Fault Attribution (FR-5) and exportable Diagnostics Report (FR-7).
- A first, curated set of Conflict signatures (FR-6).
- Plain-language sync state & history (FR-8).
- Compatibility self-check + safe degradation (FR-9, FR-10).
- Performance budget definition + delta-touch regression guard (FR-12).

### 6.2 Out of Scope for MVP
- Automated conflict remediation — deferred. *(Detection first; remediation is a trust risk.)*
- Exhaustive conflict-signature library — start curated, expand by support data. `[NOTE FOR PM: which conflicts hurt customers most today should come from support tickets.]`
- Localized re-translation of all new diagnostic strings into all 7 locales — strings will be translatable from day one, but full locale coverage may trail the feature. `[NOTE FOR PM: confirm locale expectations for launch.]`
- Connectors API expansion beyond credentials — defer to a later release.

## 7. Success Metrics

*Derived from the user's stated definition: "updates in wp wont harm this. complete control and fast syncs." Targets are `[ASSUMPTION]` pending confirmation.*

**Primary**
- **SM-0 — Works on WP 7.0 (gating):** The plugin operates correctly on WordPress 7.0 — every enumerated 7.0 symptom is reproduced, fixed, and regression-covered; full and delta sync succeed with no fatal admin errors. Target: 100% of known 7.0 issues resolved before this chapter ships. Validates FR-13, FR-14. *(This is the binary release gate for the whole effort.)*
- **SM-1 — Update survival:** Share of WP/WC updates on live installs after which the next sync succeeds without manual intervention. Target: ≥ 99%. Validates FR-9, FR-10, FR-11. `[ASSUMPTION: target]`
- **SM-2 — Self-served fault attribution:** Share of "is it broken?" situations a customer resolves or correctly attributes via the Health Check without a support ticket. Target: a majority (≥ 60%). Validates FR-4, FR-5, FR-6, FR-7. `[ASSUMPTION: target + measurement method]`
- **SM-3 — Sync speed:** Time to complete a sync for a reference catalog; delta sync with no changes touches zero products. Target: within §10 budget. Validates FR-8, FR-12.

**Secondary**
- **SM-4 — First-time setup success:** Share of new installs that reach a successful first sync via Guided Setup without editing advanced settings. Target: high majority. Validates FR-1, FR-2, FR-3.
- **SM-5 — Support deflection:** Reduction in support tickets attributable to misconfiguration or misattributed (non-Skwirrel) faults. Validates FR-3, FR-5, FR-6. `[ASSUMPTION: baseline needed from support data.]`

**Counter-metrics (do not optimize)**
- **SM-C1 — Don't trade speed for correctness:** Sync speed (SM-3) must not be improved by skipping products, weakening validation, or zeroing prices the PIM omitted. Counterbalances SM-3. *(Ties to the existing "don't zero-out prices" rule.)*
- **SM-C2 — Don't oversimplify into helplessness:** Hiding settings (SM-4) must not remove control that real users need; "simple" must not become "can't fix it." Counterbalances SM-4.
- **SM-C3 — Don't cry wolf:** Conflict/compat warnings (SM-2) must not generate false positives that train users to ignore them. Counterbalances SM-2.

## 8. Open Questions
7. **(Highest priority) What exactly is broken on WP 7.0?** Enumerate the concrete symptoms — which capabilities fail (admin screens? sync? images? Connectors/token? variations? permalinks?), what the user sees, and any error messages. This is the single most important missing input; FR-13/FR-14 stay generic until it's answered. *(Listed first by importance though numbered 7.)*
1. Are developers/agencies explicitly a non-audience for this chapter, or a secondary one we still must not block? (Affects FR-3 / Non-Goals.)
2. What are the concrete targets for SM-1/SM-2/SM-3, and what's the current support-ticket baseline (SM-5)?
3. What is the reference catalog size and acceptable sync-time budget for §10 / FR-12?
4. Which specific Conflicts hurt customers most today? (Seeds the FR-6 signature set — needs support-ticket input.)
5. Locale expectations: must all new diagnostic strings ship in all 7 locales at launch, or English-first with locales trailing?
6. Does "complete control" include any *undo / preview-before-sync* capability, or is visibility (FR-8) sufficient for this chapter?

## 9. Assumptions Index
- §4.0 — The concrete WP 7.0 symptoms are not yet enumerated; FR-13/FR-14 are written against categories of breakage pending the specifics (Open Question 7). **Highest-priority gap.**
- §2.2 — Developers/agencies are a secondary, not primary, audience. *(Confirm.)*
- §4.4 / §7 / §10 — Concrete sync-speed target is TBD.
- §5 — This chapter adds no new PIM/sync *data* features (user did not explicitly answer the non-goals question).
- §7 — All quantitative success targets (SM-1 ≥99%, SM-2 ≥60%, etc.) are inferred and need confirmation.
- §1 / §2 — "Complete control" interpreted as customer-facing visibility/safety, not team release tooling.

---

## 10. Cross-Cutting NFRs

- **Test backbone (the "well-tested updates" requirement):** New behavior ships with Pest unit coverage and, where it touches real WP/WC, integration coverage (wp-env). The three quality gates (`pest`, `phpstan` level 6, `phpcs`) must pass before release. Diagnostics and compatibility logic in particular are priority test targets. *(This is the engineering expression of the user's "well tested updates" goal.)*
- **Safe updates / no destructive defaults:** No new default may enable a destructive action (purge stays opt-in). Mid-operation failures must not leave partial/corrupt catalog state (FR-10).
- **Performance budget (fast syncs):** Define a target sync-time budget for a reference catalog; delta syncs must not touch unchanged products. **Real datapoint (2026-06-08 production run):** a full pass over ~1,156 products (1,100 simple + 56 variable groups, with ETIM attributes + relations) took ≈12 minutes end-to-end. Use this as the baseline to beat — the "fast syncs" goal means measurably reducing this, especially the attribute-merge and relations phases which dominate the tail. `[ASSUMPTION: concrete target, e.g. "<X min for 1,200 products," to be set with the team.]`
- **Observability:** Diagnostics surface existing `Skwirrel_WC_Sync_Logger` output as customer-readable signal; logging remains the substrate, not the UI. Health Checks are read-only and side-effect-free.
- **Security:** The API auth token is never exposed in any Diagnostics Report or export (extends the existing settings-export rule to the new diagnostics surface).
- **Compatibility posture:** WP 7.0+ primary target, WP 6.9+ floor, WC 8.0+ (9.6+ for brands), HPOS-compatible; Connectors API preferred with 6.9 fallback.
- **Accessibility & i18n:** New admin UI follows WP admin conventions and `manage_woocommerce` gating; all new user-facing strings are translatable (text domain `skwirrel-pim-sync`), English source.

## 11. Why Now
WordPress 7.0 has shipped **and the plugin currently has issues on it** — this is an active, live problem for the install base, not a future risk. That alone forces action now. Beyond the immediate fix, 7.0 also expands the surface for "is it us or the stack?" failures and introduces the Connectors API. The plugin has reached a maturity point where accumulated configuration complexity is itself the dominant adoption/support cost for a non-technical audience. Addressing simplicity + self-diagnosis + update-resilience now — at the 7.0 inflection — protects the install base through the platform transition and converts the team's existing logging investment into customer-visible value.
