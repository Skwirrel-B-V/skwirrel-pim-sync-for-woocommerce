---
status: ready-for-dev
baseline_revision: 0f7c3c4964b7789f74d7c14f307c1c083a9a22a4
context:
  - _bmad-output/project-context.md
  - _bmad-output/planning-artifacts/epics.md
  - _bmad-output/planning-artifacts/ux-designs/ux-wordpress-2026-06-11/DESIGN.md
  - _bmad-output/planning-artifacts/ux-designs/ux-wordpress-2026-06-11/EXPERIENCE.md
  - CLAUDE.md
  - .claude/rules/admin-settings.md
---

# Story 5.1: Tabbed settings navigation

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner,
I want the settings grouped into tabs I can move between,
so that I can find the setting I need without scrolling past forty I don't.

## Acceptance Criteria

**1 — Every field lands in exactly one of four tabs**

**Given** the settings screen (`?page=skwirrel-pim-sync&tab=settings`)
**When** it renders
**Then** the eight existing `.skw-fieldgroup` blocks are distributed over four tabs — **Connection** · **What to sync** · **How it looks** · **Advanced** — with no field added, removed, renamed or re-ordered within its group.
**And** the re-home map is exactly:

| Existing fieldgroup (`admin-dashboard.php`) | Tab |
|---|---|
| API Connection (L775) | Connection |
| Sync Options (L892) | What to sync |
| Product status handling (L1142) | What to sync |
| Media & Language (L995) | How it looks |
| Permalinks (L1065) | How it looks |
| Scheduling (L845) | Advanced |
| Sync Logs (L1104) | Advanced |
| Advanced (L1194) | Advanced |

**And** the Danger Zone (`#skwirrel-danger-zone`, L1213-1240) stays exactly where it is — outside the `<form>`, outside the tab set, below everything.
**And** the "Save settings" button (L1207-1209) stays outside the panels, so it is visible from every tab.
**And** the markup uses the existing `.skw-*` component vocabulary; no second visual language, no new font/colour (DESIGN.md "Do's and Don'ts").

**2 — Saving is byte-for-byte the same request as before**

**Given** the tabbed screen with a non-default tab active
**When** I submit
**Then** **one** POST to `options.php` carries **every** field, including those on inactive tabs — panels are hidden with CSS/`hidden`, never removed from the DOM, never disabled, never split into per-tab `register_setting()` groups.
**And** `sanitize_settings()` (`admin-settings.php:315`) receives the identical `$input` array it receives today. This is not optional: it validates **across** groups — `super_category_id` is required when `sync_categories` is on (L339), and `custom_collection_id` is required when `sync_custom_classes` / `sync_trade_item_custom_classes` / `sync_grouped_products` is on (L406) — and those pairs now sit on **different tabs**. A per-tab save would make those rules fire against missing input and wipe stored values.
**And** saving from any tab leaves every other tab's stored values unchanged (regression test, AC 7).

**3 — A tab that holds an error is marked, and opens first**

**Given** a save that produced one or more `add_settings_error()` messages (today: `super_category_id_required`, `collection_ids_required`, `custom_collection_id_required` — all three land on **What to sync**)
**When** the screen re-renders
**Then** each tab containing at least one failing field is marked with an **icon or a count**, not colour alone (NFR-7 / EXPERIENCE.md Accessibility Floor: "status is never colour-only").
**And** the first tab with an error is the tab that opens, overriding both the default tab and any `#tab-` fragment.
**And** the existing top-of-page notice keeps rendering — this story does not move errors inline (that is Story 5.2), it only makes sure a tab does not hide the field an existing error is about.

**4 — Deep-linking via `#tab-{slug}`, without colliding with `?tab=`**

**Given** a URL ending in `#tab-{slug}` for a known slug
**When** the page loads
**Then** that tab opens.
**And** the mechanism uses the **fragment**, never a query var. `?tab=` is already taken by the page-level menu (`render_page()`, `admin-settings.php:1867-1876`, allowed values `dashboard|sync|history|settings|debug`) and by `highlight_active_tab()` (L261) which decides which top-level submenu row gets `.current`. Introducing a second `?tab=` value would break the menu highlight and route the request away from the settings view.
**And** activating a tab updates the fragment (`history.replaceState`, not a navigation) so the URL is copyable.
**And** an unknown or absent fragment falls back to the first tab.

**5 — No field is unreachable without JavaScript**

**Given** JavaScript is unavailable or has not run yet
**When** the screen renders
**Then** every panel is visible as a sequential section, exactly as the page reads today — the "hide the inactive panels" state is applied *by script*, so the no-JS baseline is the current, fully-scrolling page.
**And** there is no flash of all-panels-then-collapse on a normal load beyond what a `<head>`-level style or an early inline class toggle avoids; a brief flash is acceptable, a permanently hidden field is not.

**6 — Keyboard and assistive technology**

**Given** keyboard-only navigation
**When** I reach the tab strip
**Then** it follows the ARIA tabs pattern: a `role="tablist"` container, `role="tab"` controls each with `aria-selected` and `aria-controls`, and `role="tabpanel"` panels each with `aria-labelledby`; the active tab is `tabindex="0"` and inactive tabs `tabindex="-1"`, with Left/Right (and Home/End) moving between them.
**And** focus is visible using the existing blue focus ring (`.skw-input:focus` / `.skw-block:focus` precedent in `dashboard.css`), not the browser default removed.
**And** moving focus into a panel and back out works; nothing traps focus (this is a tab strip, not a modal).

**7 — Extensible registration**

**Given** Epic 6 needs to add a **Field mapping** tab
**When** it does so
**Then** it registers a tab through a documented extension point and renders its own panel body — **without editing this story's tab strip markup or its panel loop**.
**And** tab order is deterministic and controlled by the registration (not by hash order).
**And** the extension point is internal to the plugin (a static registry method or a filter); if a filter, it is named `skwirrel_wc_sync_settings_tabs` and documented in the class docblock.

**8 — The regressions this must not cause**

**Given** the tabbed screen
**When** any of the following is exercised
**Then** it behaves exactly as before:
- **Test connection** (`#skwirrel-test-connection` + `#skwirrel-test-result`, Connection tab) — the inline JS in `enqueue_assets()` binds by ID; IDs must survive.
- **Subdomain → hidden `endpoint_url`** mirroring (`skwApplySubdomain`, `admin-settings.php:1515`) and the token-link rewrite.
- **Image-language `_custom` reveal** (`#image_language_select` → `#image_language_custom_wrap`, How it looks).
- **Refresh statuses** scan (Product status handling → What to sync) and its unsaved-edits message.
- **The Permalinks group is not part of the settings option.** It renders a read-only summary plus `#skwirrel-update-slug-resync`, which saves over its own AJAX action (`handle_save_slug_resync`, `admin-settings.php:1089`) while sitting *inside* the form. Hiding its panel must not break that select, and it must still not submit with the form.
- **Danger Zone confirmations** (`#skwirrel-purge-form`, `#skwirrel-reset-settings-form`) — untouched, but verify the JS still binds now that markup moved around them.

## Tasks / Subtasks

- [ ] **T1 — Tab registry** (AC: 1, 7)
  - [ ] Add the registry to `Skwirrel_WC_Sync_Admin_Dashboard` (or a small new class if it grows past ~60 lines — see Project Structure Notes before creating one): slug, label, order.
  - [ ] Seed the four tabs: `connection`, `what-to-sync`, `how-it-looks`, `advanced`.
  - [ ] Expose the extension point and document it in the docblock with a one-line usage example.
- [ ] **T2 — Restructure `render_page_settings()`** (AC: 1, 2, 5)
  - [ ] Move the eight `.skw-fieldgroup` blocks into four `role="tabpanel"` wrappers per the table in AC 1. **Move, do not retype** — copy the blocks verbatim; every `id`, `name`, `class` and hint string stays identical.
  - [ ] Emit the tab strip above the panels, inside the `.skw-section`, above the `<form>` or as its first child (either is fine; keep the strip out of the submit payload).
  - [ ] Keep `settings_fields()`, `wp_nonce_field()`, the submit button and the Danger Zone exactly where they are relative to the form.
  - [ ] Default state = all panels visible; the script collapses to the active one.
- [ ] **T3 — Behaviour script** (AC: 3, 4, 5, 6)
  - [ ] Extend the existing inline script in `Skwirrel_WC_Sync_Admin_Settings::enqueue_assets()` (handle `skwirrel-pim-sync-admin`) — do not add a new script handle or an asset file for ~60 lines of JS; the file has no build step and every other behaviour lives there.
  - [ ] Activation: click + Left/Right/Home/End, `aria-selected`/`tabindex` roving, `hidden` on inactive panels.
  - [ ] Initial tab resolution order: **first errored tab → `#tab-` fragment → first tab**.
  - [ ] `history.replaceState` the fragment on activation.
  - [ ] Any user-facing string goes through `wp_localize_script`'s `skwirrelPimSync` object (the existing pattern) — never a literal in the JS.
- [ ] **T4 — Error marking** (AC: 3)
  - [ ] Map each `add_settings_error()` code to its tab. Prefer deriving it from the registry (code → field id → tab) over a second hardcoded list that will drift when Story 5.2 adds inline errors.
  - [ ] Render the marker server-side (icon + count), so it is present with JS off too.
- [ ] **T5 — Styles** (AC: 1, 6)
  - [ ] Add `.skw-tabs` / `.skw-tab` / `.skw-tabpanel` to `assets/dashboard.css`, using the existing tokens. Do **not** touch `admin.css` (it is the legacy `form-table` sheet).
  - [ ] Visible focus ring matching the existing input focus treatment; respect the 782px breakpoint already in the sheet.
- [ ] **T6 — Tests** (AC: 2, 7, 8)
  - [ ] Unit: the tab registry — default four tabs, order, registration of a fifth, unknown-slug handling.
  - [ ] Unit: error-code → tab resolution for all three existing error codes.
  - [ ] Integration: render the settings page for an admin and assert **every** input `name` present before the change is still present after it (the anti-regression net for AC 2). Capture the baseline name list from `git stash`-ed markup or from a fixture generated on the pre-change revision.
  - [ ] Integration: save from a non-default tab and assert unrelated stored keys are unchanged.
- [ ] **T7 — Gates and release**
  - [ ] `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs` — all green from the repo root.
  - [ ] New translatable strings (four tab labels + any marker text) → regenerate `.pot` and update all 7 locales.
  - [ ] Version bump + CHANGELOG.md + readme.txt via `/release`; never by hand.

## Dev Notes

### What this story actually is

A **presentation refactor of one method**. `Skwirrel_WC_Sync_Admin_Dashboard::render_page_settings()` (`includes/class-skwirrel-wc-sync-admin-dashboard.php:761-1242`) already renders eight `.skw-fieldgroup` divs inside a single `<form action="options.php">`. This story wraps those eight divs in four panels and puts a tab strip on top. It changes **zero** settings semantics, **zero** option keys, and **zero** sanitize logic.

The single way to get this wrong is to treat it as a settings refactor and start splitting the form. Don't.

### Current state of the files you will touch

**`includes/class-skwirrel-wc-sync-admin-dashboard.php`** — 1323 lines.
- `render( $active_view )` L47: the page shell (header bar, notices slot, sync banner, `switch` on view, log modal). Untouched.
- `render_page_settings()` L761: the target. Structure today:
  - L767-768 `.skw-section` + title + desc
  - L770-772 `<form id="skwirrel-sync-settings-form" class="skw-settings-form">` + `wp_nonce_field('options-options')` + `settings_fields('skwirrel_wc_sync')`
  - L775, 845, 892, 995, 1065, 1104, 1142, 1194 — the eight `.skw-fieldgroup` opens
  - L1206-1209 submit button, L1210 `</form>`, L1211 `</div>`
  - L1213-1240 `#skwirrel-danger-zone` — a **sibling section outside the form**
- Note `.skw-fieldgroup:last-of-type` in `dashboard.css:867` strips the bottom border. Once groups are split across panels, "last of type" resolves per panel — that is the correct outcome, but check it visually rather than assuming.

**`includes/class-skwirrel-wc-sync-admin-settings.php`** — 1946 lines.
- `register_settings()` L274: one `register_setting('skwirrel_wc_sync', 'skwirrel_wc_sync_settings')`. One option, one sanitize callback. Keep it that way.
- `sanitize_settings()` L315-~470: the cross-group validator. Three `add_settings_error()` calls at L340 (`super_category_id_required`), L396 (`collection_ids_required`), L406 (`custom_collection_id_required`).
- `enqueue_assets( $hook )` L1436: gated on `strpos($hook, PAGE_SLUG)`. Enqueues `admin.css` + `dashboard.css` + Inter, registers the **src-less** script handle `skwirrel-pim-sync-admin` (footer), `wp_localize_script`s `skwirrelPimSync`, then `wp_add_inline_script`s every behaviour on the page. **This is where your tab script goes.**
- `render_page()` L1859 and `highlight_active_tab()` L261 — the owners of `?tab=`. Read AC 4 before touching anything named "tab".

**`assets/dashboard.css`** — 1545 lines, the real stylesheet (`.skw-*`, scoped to `.skw-dashboard`). `.skw-settings-form` L857, `.skw-fieldgroup` L861. One `@media (max-width: 782px)` block at L1476. **No `prefers-reduced-motion` block exists** — if you add a transition, add the guard with it.

**`assets/admin.css`** — 51 lines of legacy `form-table` rules. Not the place for this.

### Things that will bite

- **`sanitize_settings()` runs on the whole array or not at all.** Checkbox fields are read with `! empty( $input[...] )` — an absent key is a **false**, not "unchanged". Any change that drops fields from the POST silently turns off every checkbox on the tabs you didn't submit. This is the disaster this story is one careless refactor away from.
- **The Permalinks fieldgroup submits nothing.** It is a read-only `.skw-summary-table` plus `#skwirrel-update-slug-resync`, saved via its own AJAX action against the separate `skwirrel_wc_sync_permalinks` option. It lives inside the form only by layout accident. Keep it that way — do not "fix" it into a form field.
- **Every behaviour on this page binds by element ID from one inline script.** Moving markup is safe only while IDs are preserved. `grep` the inline script in `enqueue_assets()` for `getElementById` before you finish and check each one still resolves.
- **`skw-checkbox-indent`** marks two fields (`use_virtual_product_content`, `sync_trade_item_custom_classes`) as visually subordinate to the checkbox above them. Keep them adjacent to their parent — do not let a panel boundary separate a pair.
- **`super_category_id` and `collection_ids` carry HTML5 `required`.** A `required` field inside a `hidden` panel is a real browser problem: Chrome/Firefox refuse to submit and report "An invalid form control with name='…' is not focusable". If you hide panels with `display:none`/`hidden`, you **must** open the offending tab before native validation runs, or drop the native `required` in favour of the server-side check that already exists. Decide this deliberately and note the choice in the completion notes — Story 5.2 owns required-field marking and will build on whatever you pick.

### The ARIA tabs pattern (what "keyboard-operable" means here)

WAI-ARIA APG *Tabs* pattern, manual-activation variant:
- `<div role="tablist" aria-label="…">` containing `<button type="button" role="tab" id="tab-{slug}" aria-controls="panel-{slug}" aria-selected="true|false" tabindex="0|-1">`.
- `<div role="tabpanel" id="panel-{slug}" aria-labelledby="tab-{slug}" tabindex="0" [hidden]>`.
- Tab key enters the strip once (roving `tabindex`), Left/Right move between tabs, Home/End jump to ends, Tab from the strip moves into the active panel.
- `<button type="button">` matters — a bare `<button>` inside a `<form>` defaults to `type="submit"` and every tab click will save the settings.

WordPress's own `.nav-tab` / `.nav-tab-active` classes are an option for the visuals, but they are anchor-based and carry no ARIA. This screen has its own design system; use `.skw-*` and put the ARIA on yourself.

### Do not

- Do not add a `?tab=`, `?section=` or `?stab=` query var. Fragment only. (AC 4.)
- Do not split `register_setting()` or introduce a second option key.
- Do not disable inputs on inactive panels (`disabled` inputs are not submitted — same disaster as dropping them).
- Do not create a new stylesheet or a new JS file. No build step exists.
- Do not touch `admin.css`.
- Do not renumber, retitle or re-copy the existing fieldgroups. Anything that reads as a wording improvement belongs to a later story; keeping this diff mechanically reviewable is what makes AC 2 verifiable.

### Project Structure Notes

- If a new class is warranted, it is `Skwirrel_WC_Sync_Settings_Tabs` in `includes/class-skwirrel-wc-sync-settings-tabs.php` (WPCS `InvalidClassFileName` is a hard gate on the name), registered in **two** places: the `require_once` list in `skwirrel-pim-sync.php` **and** its hook wiring in `Skwirrel_WC_Sync_Plugin`. There is no autoloader.
- Prefer *not* creating one. A static registry array plus a renderer on the existing dashboard class is the smaller, more reviewable change, and the four classes shipped since the docs were written (`connectors`, `deprecated-status`, `pim-link`, `run-links`) are already absent from the CLAUDE.md class map — adding a fifth undocumented one has a cost.
- If you do add a class, add its row to the class map in `CLAUDE.md`.

### Testing

- Unit tests run on the stub bootstrap (`tests/bootstrap.php`, no WP). Pest syntax: `test()`, `beforeEach()`, `expect()` — never class-based PHPUnit, never `$this->assert*`.
- Integration tests need `npm run env:start` + `npm run test:integration`. **Read `tests/Integration/AdminMenuIntegrationTest.php` first** — it is the closest precedent (admin surface, asserts the outcome core produces rather than the calls the plugin makes) and it documents its own limits honestly. Copy that discipline.
- **Known trap, recorded in `deferred-work.md`:** the integration suite has **no DB isolation** — the `uses( WP_UnitTestCase::class )->in('Integration')` binding in `tests/Pest.php` is inert because the integration phpunit config points at `tests/Integration` and never loads `tests/Pest.php`. There are no transactions and no rollback; `tests/Integration/bootstrap.php` does manual cleanup and `tests/Integration/README.md` is wrong about this. Clean up after yourself explicitly.
- **There is zero browser/E2E coverage of the admin UI** — no Playwright, no Cypress, no `tests/e2e/`. Which means: the tab strip *rendering*, the keyboard interaction, the focus ring, and the "required field inside a hidden panel" browser behaviour **cannot be asserted by any test in this repo**. Verify those four by hand in wp-env and say so plainly in the completion notes. Do not write a test that pretends to cover them.
- Whatever is machine-checkable, check: the input-name inventory (AC 2), the registry (AC 7), the error→tab map (AC 3).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 5.1: Tabbed settings navigation (FR-3, UX-DR13)]
- [Source: _bmad-output/planning-artifacts/epics.md#UX-DR13: Tabbed settings navigation]
- [Source: _bmad-output/planning-artifacts/epics.md#As-built facts that change how Chapter 2 must be built] — the `?tab=` collision and the "reuse `.skw-*`" constraint
- [Source: _bmad-output/planning-artifacts/epics.md#Chapter 2 — Requirements Inventory] — FR-3 (amended), supersedes Story 4.1
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-wordpress-2026-06-11/DESIGN.md#Components] — `settings_form`, `section_card`, focus ring, "reuse `.skw-*`, don't reinvent"
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-wordpress-2026-06-11/EXPERIENCE.md#Accessibility Floor] — never colour-only; visible focus
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-wordpress-2026-06-11/EXPERIENCE.md#Information Architecture] — the four intent groups
- [Source: _bmad-output/project-context.md#Language & Framework Rules] — class/file naming, two-place registration, escaping, text domain
- [Source: .claude/rules/admin-settings.md] — settings keys, sanitization rules, capability + page slug
- [Source: _bmad-output/implementation-artifacts/deferred-work.md#From the top-level admin menu work (3.13.0, 2026-08-19)] — integration isolation gap, no E2E
- Code: `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php:761-1242`
- Code: `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php:261,274,315,1436,1859`
- Code: `plugin/skwirrel-pim-sync/assets/dashboard.css:857-880`

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log
