---
status: final
updated: 2026-06-11
sources:
  - prd: '_bmad-output/planning-artifacts/prds/prd-wordpress-2026-06-10/prd.md'
  - architecture: '_bmad-output/planning-artifacts/architecture.md'
  - design: './DESIGN.md'
---

# Skwirrel PIM Sync — Experience Spine (EXPERIENCE.md)

> Owns *how it works*: IA, behaviour, states, interactions, accessibility, flows. Visual tokens live
> in DESIGN.md, referenced as `{path.to.token}`. Both spines win on conflict with any mock.

## Foundation

- **Form factor:** WordPress `wp-admin`, desktop-first, responsive to the wp-admin 782px breakpoint
  (admins occasionally manage on tablets). No mobile app.
- **UI system:** the existing **`.skw-dashboard` design system** (DESIGN.md) layered on wp-admin.
  Server-rendered PHP; **vanilla JS only** for progressive enhancement (AJAX polling, modals, confirm
  dialogs) — no React/Vue, no build step. Screens must work with JS off for all destructive paths.
- **Capability gate:** `manage_woocommerce`. Single submenu page **WooCommerce → Skwirrel PIM**
  (slug `skwirrel-pim-sync`); sub-screens are query-arg views of that page, not new menu items.
- **Audience:** non-technical store owners/marketeers. Plain language is a hard requirement; technical
  evidence is always available but never the primary content.

## Information Architecture

The **Hub** (`.skw-dashboard`) stays the home: dark header → last-result **status_card** →
**action_block_grid** → recent-syncs section. The grid is the primary navigation; new surfaces are
new blocks. Proposed block set (current + new), in priority order:

1. **Sync Now** *(existing, reworked)* → opens the **Sync flow** with a **Preflight** step (FR-16).
2. **Health & Diagnostics** *(new)* → the self-check screen (FR-4–8). The block carries a **standing
   verdict badge** (healthy/warning/problem) from the last check and re-runs on open, so the hub shows
   health at a glance. *(Confirmed.)*
3. **Sync History** *(existing)* → date-grouped history; counts deep-link to filtered products (FR-15).
4. **Settings** *(existing, reworked)* → **four intent groups** (FR-3): **Connection** (token, endpoint)
   · **What to sync** (categories, brands, grouped products, selections, collections) · **How it looks**
   (images, slugs/permalinks, language) · **Advanced** (timeout, retries, batch size, purge, verbose).
   Relation-disabled fields dim + show "Inactive because *{setting}* is off". *(Confirmed.)*
5. **Sync Logs / Debug** *(existing)* → dark log viewer (unchanged).
6. **Danger Zone → Start over** *(existing, reworked)* → reset with preview + confirm (FR-17).

**First-run override:** when no valid configuration exists, the page renders **Guided Setup** (FR-1)
instead of the hub. Once setup completes it never returns (dismissable; gated on a stored flag).

**Surface closure:** every PRD need maps to a surface, and every surface has a flow that lands there —
FR-1→Guided Setup, FR-3→Settings, FR-4–7→Health, FR-8+FR-15→History/status, FR-16→Preflight step,
FR-17→Start-over, FR-10→paused status_card/banner. No orphan needs, no orphan screens.

## Voice and Tone

Microcopy is **plain, calm, specific** (brand voice lives in DESIGN.md). Rules:
- Lead with the outcome in human terms: "Connected ✓ — 1,240 products synced", not a result object.
- Name the one next step; never a stack trace as the message. "The token was rejected by Skwirrel —
  check it in Settings → Connectors." (the bad-token case from UJ-1).
- Attribution is honest and evidence-cited: "This looks like another plugin, not Skwirrel — *Imagify*
  is handling image uploads" (UJ-2). Never a bare "something went wrong".
- Destructive copy is explicit about scope and reversibility: "This moves **all 1,240 Skwirrel
  products** to Trash. Your other products are untouched. You can restore from Trash."
- Numbers are exact and tabular; avoid "some/many". Counts are nouns you can click.

## Component Patterns (behavioural)

- **action_block** — whole card is one link/affordance; keyboard-focusable; an optional status badge
  (Health). Disabled blocks (e.g. Sync Now while paused) show a reason, don't 404.
- **status_card** — three semantic variants: success, error, **paused (warning)**. Always carries a
  timestamp and, on problem/paused, a one-line reason + action link.
- **progress_ledger** *(replaces the 7-phase banner — confirmed)* — shows **resumable per-item
  progress**: **"X of Y products · resumable"** (`committed / total`, tabular-nums), a current activity
  line, and a **"Resume"** affordance if a run was interrupted. Polls via AJAX; degrades to a static
  "in progress, refresh to update" with JS off.
- **change_set_table** *(new — shared by Preflight forecast, Result, Reset preview)* — two stacked
  blocks with one vocabulary (DESIGN tokens for badges/counts):
  - **Products:** rows for **added / changed / removed**, each a count that is a **link** to the WC
    products list filtered by `?skwirrel_run=…` (removed → Trash view).
  - **Category structure:** rows for **created / renamed (old→new) / removed-or-orphaned**, each with a
    **"re-homes N products"** sub-count. A whole-subtree delete is shown nested (parent + children),
    not as one opaque number.
- **health_check_row** — `{check name}` + status pill + plain verdict + **attribution chip**
  (ours / environment / undetermined) + optional **Details** disclosure (evidence, technical). A check
  that errors renders as **undetermined**, never breaks the page.
- **conflict_item** — names the implicated component + the affected capability ("images",
  "permalinks") + guidance. "No known conflicts detected" is stated as *not a guarantee*.
- **confirm_dialog** *(destructive)* — preview of impact (a `change_set_table` for reset) + a required
  **"I understand" checkbox** opt-in + primary **danger** button. Never a one-click destructive action.
  *(Confirmed: checkbox, not typed-confirm; reset removes to Trash/recoverable.)*
- **setting_field** — label + control + hint; **relation-aware**: when inactive due to another setting,
  it dims and shows "Inactive because *{setting}* is off" rather than disappearing. *(Confirmed.)*

## State Patterns

Every data surface defines: **loading · empty · ready · partial/degraded · error**.
- **Health:** running (spinner, bounded time) → verdict; any single check → undetermined on failure;
  whole run never white-screens (FR-4/FR-10).
- **Sync:** idle → preflight (forecast) → committing (progress_ledger) → result (status_card +
  change_set). **Interrupted → resumable**: the ledger shows "Paused at N/Total — Resume", and
  `last_sync` is *not* advanced (architecture D2). No bare/partial product is ever presented as done.
- **Paused (FR-10):** status_card warning variant + reason ("Paused: WooCommerce updating" /
  "incompatible version") + how to resume. Sync Now disabled with that reason.
- **Preflight:** computing → forecast ready (change_set) → "Commit" / "Cancel". If the catalog changed
  between preview and commit, **commit re-resolves** (architecture D7) and shows the fresh forecast —
  the preview is never silently committed.
- **Empty:** first-run → Guided Setup; no history → friendly empty state; no conflicts → explicit
  "none detected (not a guarantee)".

## Interaction Primitives

- **Confirm-before-destroy:** reset and delete paths require preview + explicit opt-in; danger styling.
- **Deep-link out:** result/forecast counts navigate to native WC list views (`?skwirrel_run=…`) —
  no custom list table; the user lands in WooCommerce they already know.
- **Copy report:** Health exports a token-redacted Diagnostics Report to clipboard/download in one
  action (FR-7); a "Copied ✓" affordance confirms.
- **Poll, don't block:** long operations report progress via AJAX polling against the ledger; the page
  never hangs; abort is always available.
- **Disclosure for depth:** technical evidence sits behind "Details"; logs behind the dark viewer.

## Accessibility Floor

- Keyboard: every action block, disclosure, dialog, and deep-link is tabbable with visible focus
  (the blue focus ring `{elevation.focus_ring}`); modals trap focus and close on Esc.
- Status is **never colour-only** — pair every status colour with an icon and a text label (badge
  text, "Healthy"/"Problem"), so the success/error/paused states read without colour vision.
- Plain-language verdicts satisfy cognitive accessibility; technical detail is opt-in, not forced.
- Respect wp-admin's high-contrast and reduced-motion preferences; the pulse/spinner animations honour
  `prefers-reduced-motion`. `[ASSUMPTION]`
- All controls have real labels; counts-as-links have descriptive text ("View 40 changed products"),
  not bare numbers, for screen readers.

## Change-Set Presentation *(invented — core to FR-15/16/17)*

One presentation, three contexts, identical vocabulary so "before" and "after" always agree:
- **Preflight (forecast):** "If you sync now: **+12 added · 40 changed · 3 removed**; categories:
  **1 created · 1 renamed (Draagtassen→Hoesjes) · 6 removed (re-homes 240 products)**." Removed +
  category-removal are visually emphasised (warning) as the highest-risk rows.
- **Result (after):** same layout, past tense, counts now deep-link to the actually-affected products.
- **Reset preview:** a forecast scoped to "remove **all** Skwirrel products (N)", same table, danger framing.
The category block is the headline for structural changes (Pieter's journey) — never collapsed away.

## Fault Attribution & Conflicts *(invented — core to FR-5/6)*

The mental model the UI must deliver is **"is it us or the environment?"** answered in one glance:
- A single top **verdict** with attribution: *Healthy* · *Problem in your environment* · *Problem in
  Skwirrel* — each backed by the check that produced it.
- Conflicts name the *other* component when detected; the plugin is honest when the fault is its own.
- Every attribution **cites evidence** (the check + signal); "Copy report for support" packages it.
- Tone never blames the user; it points at a setting or a component, with a next step.

## Key Flows

**Flow 1 — Nadia sets up without calling anyone (UJ-1, FR-1/2).**
Nadia (small building-supplies shop, comfortable in wp-admin, not a developer) installs the plugin.
The page shows **Guided Setup**, not a wall of fields. Step 1 *Connect*: she pastes her token; the
plugin verifies live against Skwirrel and can't advance until it's green. Step 2 *Essentials*: a
handful of choices, sensible defaults pre-filled (purge **off** by default). Step 3 *First sync*: she
clicks Sync — and is offered a **Preflight** first. **Climax:** "Connected ✓ — 1,240 products synced",
a plain summary, not a log dump. **Edge:** bad token → "The token was rejected by Skwirrel" + the one
next step. *Resolves:* catalog live; she knows where health/history live.

**Flow 2 — Marco answers "is it us or them?" himself (UJ-2, FR-4–7).**
Marco (marketeer) notices images stopped appearing after installing an optimisation plugin. He opens
**Health & Diagnostics** and runs the check. **Climax:** in two minutes the verdict reads *"Skwirrel is
healthy — the last sync succeeded. A conflict was detected: **Imagify** is intercepting image
uploads."* with a **Copy report for support** button. *Resolves:* fault attributed correctly, no
wasted ticket. **Edge:** if the fault *is* Skwirrel's, the report says so and links the setting.

**Flow 3 — Saskia updates WordPress without fear (UJ-3, FR-9/10).**
Saskia sees "WordPress 7.x available". Health confirms compatibility before/after. During the update
the sync **pauses safely** — the status_card shows *"Paused: WordPress updating"* with how to resume —
rather than half-writing the catalog. **Climax:** she updates; the next sync runs; health stays green.
*Resolves:* trust in the Update button preserved.

**Flow 4 — Pieter reorganises the category tree without fear (UJ-4, FR-16/17).**
Pieter is about to delete *Webshop → Radiocom* and all its submenus in Skwirrel, and rename another. He
runs **Preflight** first. It forecasts not just products but **category structure**: *"1 category + 6
subcategories would be removed · 240 products re-homed · 1 category renamed Draagtassen→Hoesjes."* He
sees the blast radius before committing. **Climax:** a change he'd have made blind is legible up front;
he commits, and the result screen confirms exactly what changed, **clickable down to the products and
categories**. **Edge:** if he wants a clean slate, **Start over** (FR-17) is there — also previewed,
opt-in, and scoped to Skwirrel products only. *Resolves:* he trusts category reorganisations.

## Open Items
- ✅ progress_ledger = **"X of Y products · resumable"**, retires the 7-phase banner. *(Confirmed.)*
- ✅ Health verdict **badge on the hub block**. *(Confirmed.)*
- ✅ Reset confirm = **preview + "I understand" checkbox** (Trash/recoverable). *(Confirmed.)*
- ✅ Settings = **four intent groups** (Connection / What to sync / How it looks / Advanced). *(Confirmed.)*
- `[OPEN]` **Guided Setup essential subset** — the flow is Connect → Essentials → First sync; "Essentials"
  = the Connection group + the most-used *What to sync* toggles (sync categories, grouped products),
  with everything else deferred to Advanced. Confirm the exact essential toggles with Jos before stories.
- `[NOTE FOR UX]` Optional at Finalize: render key-screen HTML mocks (Hub with health badge · Preflight
  change-set · Health verdict) for visual reference, or ship spine-only since the design system already exists.
