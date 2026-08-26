---
status: done
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

Status: done

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

- [x] **T1 — Tab registry** (AC: 1, 7)
  - [x] Add the registry to `Skwirrel_WC_Sync_Admin_Dashboard` (or a small new class if it grows past ~60 lines — see Project Structure Notes before creating one): slug, label, order.
  - [x] Seed the four tabs: `connection`, `what-to-sync`, `how-it-looks`, `advanced`.
  - [x] Expose the extension point and document it in the docblock with a one-line usage example.
- [x] **T2 — Restructure `render_page_settings()`** (AC: 1, 2, 5)
  - [x] Move the eight `.skw-fieldgroup` blocks into four `role="tabpanel"` wrappers per the table in AC 1. **Move, do not retype** — copy the blocks verbatim; every `id`, `name`, `class` and hint string stays identical.
  - [x] Emit the tab strip above the panels, inside the `.skw-section`, above the `<form>` or as its first child (either is fine; keep the strip out of the submit payload).
  - [x] Keep `settings_fields()`, `wp_nonce_field()`, the submit button and the Danger Zone exactly where they are relative to the form.
  - [x] Default state = all panels visible; the script collapses to the active one.
- [x] **T3 — Behaviour script** (AC: 3, 4, 5, 6)
  - [x] Extend the existing inline script in `Skwirrel_WC_Sync_Admin_Settings::enqueue_assets()` (handle `skwirrel-pim-sync-admin`) — do not add a new script handle or an asset file for ~60 lines of JS; the file has no build step and every other behaviour lives there.
  - [x] Activation: click + Left/Right/Home/End, `aria-selected`/`tabindex` roving, `hidden` on inactive panels.
  - [x] Initial tab resolution order: **first errored tab → `#tab-` fragment → first tab**.
  - [x] `history.replaceState` the fragment on activation.
  - [x] Any user-facing string goes through `wp_localize_script`'s `skwirrelPimSync` object (the existing pattern) — never a literal in the JS.
- [x] **T4 — Error marking** (AC: 3)
  - [x] Map each `add_settings_error()` code to its tab. Prefer deriving it from the registry (code → field id → tab) over a second hardcoded list that will drift when Story 5.2 adds inline errors.
  - [x] Render the marker server-side (icon + count), so it is present with JS off too.
- [x] **T5 — Styles** (AC: 1, 6)
  - [x] Add `.skw-tabs` / `.skw-tab` / `.skw-tabpanel` to `assets/dashboard.css`, using the existing tokens. Do **not** touch `admin.css` (it is the legacy `form-table` sheet).
  - [x] Visible focus ring matching the existing input focus treatment; respect the 782px breakpoint already in the sheet.
- [x] **T6 — Tests** (AC: 2, 7, 8)
  - [x] Unit: the tab registry — default four tabs, order, registration of a fifth, unknown-slug handling.
  - [x] Unit: error-code → tab resolution for all three existing error codes.
  - [x] Integration: render the settings page for an admin and assert **every** input `name` present before the change is still present after it (the anti-regression net for AC 2). Capture the baseline name list from `git stash`-ed markup or from a fixture generated on the pre-change revision.
  - [x] Integration: save from a non-default tab and assert unrelated stored keys are unchanged.
- [x] **T7 — Gates and release**
  - [x] `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs` — all green from the repo root.
  - [x] New translatable strings (four tab labels + any marker text) → regenerate `.pot` and update all 7 locales.
  - [x] Version bump + CHANGELOG.md + readme.txt via `/release`; never by hand.

### Review Findings

- [x] [Review][Patch] Named external tab renderers are never invoked [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php:982] — fixed by invoking every callable renderer after the built-in renderer switch; the integration suite now exercises the documented named callback.
- [x] [Review][Patch] Reject a non-array tab-filter result before normalising [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php:822] — fixed by validating the filter result and falling back to the built-in registry.
- [x] [Review][Patch] Preserve the built-in registry when filtering removes every tab [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php:1118] — fixed by restoring the built-in registry before the form can render without its inputs.
- [x] [Review][Patch] Keep the Advanced group’s existing hint byte-for-byte [plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php:1648] — restored the original hint text as required by the presentation-only scope.
- [x] [AI-Review][Critical] The completed non-default-tab save regression test never persisted the option or asserted that unrelated stored values survived — fixed by strengthening the rendered payload assertion to exact key equality and by saving/asserting values from all four tabs.
- [x] [AI-Review][High] A filter could remove or replace one built-in panel without triggering the empty-registry fallback, omitting fields and allowing `sanitize_settings()` to clear their values — fixed by making built-in renderers/error maps invariant while keeping registration additive.
- [x] [AI-Review][Medium] Duplicate messages for one field inflated a badge announced as multiple failing fields — fixed by counting unique failing fields per tab.
- [x] [AI-Review][Medium] Error metadata routed `batch_size` to Advanced and named the nonexistent `log_retention_days` field — fixed and pinned with representative field-to-tab tests.
- [x] [AI-Review][Medium] The custom focus ring removed the browser outline but had no forced-colour fallback — fixed with a system-colour outline under `forced-colors: active`.
- [x] [AI-Review][Low] Release/story notes described automatic activation as manual and claimed an Advanced hint was reworded after that wording had been restored — corrected to match the shipped code.

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

claude-opus-5 (Claude Code, `/bmad-dev-story 5.1`)

### Debug Log References

- Unit: `vendor/bin/pest` — 414 passed (18 new in `tests/Unit/SettingsTabsTest.php`).
- Integration: `npm run test:integration` — 106 passed, 1 pre-existing deprecation (9 new in `tests/Integration/SettingsTabsIntegrationTest.php`).
- Gates: `vendor/bin/phpcs` clean (34 files), `vendor/bin/phpstan analyse --memory-limit=2G` — no errors.
- Behaviour verification: the inline tab script was extracted and exercised against the **real rendered settings HTML** (dumped from wp-env) in a jsdom DOM. See "Verification" below.

### Completion Notes List

**What was built**

The eight `.skw-fieldgroup` blocks were *moved*, not retyped: each is now its own private
`render_fieldgroup_*()` method on `Skwirrel_WC_Sync_Admin_Dashboard`, holding the original markup
byte-for-byte (only dedented by two tabs). Four `render_settings_panel_*()` methods compose them
into the panels named in AC 1. `render_page_settings()` is now a registry read, a tab strip, a panel
loop, the untouched submit button and the untouched Danger Zone.

Proof the move was mechanical: the multiset of `name="…"` attributes in the file is **identical**
before and after (`diff` over the sorted attribute list is empty); only three `id=` attributes were
added (`tab-{slug}`, `panel-{slug}`, `skwirrel-settings-errors`).

**Decisions that needed making**

1. **Native `required` inside a hidden panel** (the trap called out in Dev Notes). The `required`
   attributes were **kept**; instead, a click on the submit button opens the panel holding
   `form.querySelector(":invalid")` before the browser validates. Dropping `required` would have
   removed the only client-side signal on `skwirrel_subdomain`, which has no server-side counterpart.
   Story 5.2 builds on this: the marker layer can reuse the same "first invalid control → its tab"
   resolution.
2. **No new class.** Per Project Structure Notes, the registry is three static methods on the
   existing dashboard class (`get_settings_tabs()`, `normalize_settings_tabs()`,
   `count_errors_by_tab()`), plus `first_settings_tab()`. No new file, no bootstrap wiring, no
   CLAUDE.md class-map row.
3. **The panel dispatcher is a `switch`, not `call_user_func( [ $this, $name ] )`.** A dynamic method
   call makes the four panel renderers invisible to PHPStan (`method.unused` at level 6). Built-in
   panels are dispatched by name through the switch; anything registered from outside supplies its
   own callable, which is what AC 7 actually needs.
4. **Error routing derives from the registry**, not a second list: each tab declares the field IDs
   validation can flag, and a code is matched against them directly and with a trailing `_required`
   stripped (`super_category_id_required` → `super_category_id`) — the convention the sanitiser
   already follows.

**One thing the story assumed that was not true**

- **AC 3 says "the existing top-of-page notice keeps rendering."** There was none. `add_settings_error()`
  wrote to the `settings_errors` transient and nothing on this screen ever read it: core only
  auto-prints settings errors for `options-general.php` children (`wp-admin/options-head.php`), and
  this screen lives under its own top-level menu. So the three sanitiser messages have been invisible
  since they were written. Marking a tab as "has an error" while the error itself is unreadable would
  be half a feature, so the messages are now rendered as a `.skw-notice.skw-notice-error` block above
  the tab strip. This is page-level, not the field-level inline layer Story 5.2 owns.

**Also worth knowing for review**

- `.skw-fieldgroup:last-of-type` now resolves per panel, so each panel's last group loses its bottom
  border. That is the intended outcome, but it is a visual change — worth a glance.
- The tab strip is rendered **outside** the `<form>`, and every tab is `<button type="button">`; a bare
  `<button>` inside a form defaults to `type="submit"` and would have saved the settings on every tab
  click.
- No new script handle, no new stylesheet, no new dependency, no new `?tab=`-style query var.
- `include_languages`/`image_language` were added to the Connection→How-it-looks field lists for error
  routing only; neither field currently raises a settings error.

### Verification

**Machine-checked** (in this repo, re-runnable):

- Every input `name` the pre-tabs form rendered is still rendered, asserted against the real screen
  rendered for a real administrator (`tests/Integration/SettingsTabsIntegrationTest.php`). The
  baseline list is checked in and carries the revision it was captured from.
- Panels are inside the form, between its open and close tags; no `input`/`select`/`textarea` is
  `disabled`; the tab strip is before the form; every tab control is `type="button"`.
- `aria-controls` → panel and `aria-labelledby` → tab resolve in both directions; exactly one tab is
  `aria-selected="true"` and three are `-1` on `tabindex`.
- The Danger Zone is still outside and after the form; every element ID the inline admin script binds
  by still exists.
- A full submit round-trips through `sanitize_settings()` with one field per tab intact and no
  cross-group error raised.
- An errored tab is marked server-side with an icon **and** a count, and is the tab the server
  pre-selects.
- The registry: default four tabs and their order, a fifth registered through
  `skwirrel_wc_sync_settings_tabs` landing at its order position, ties keeping registration order,
  malformed entries dropped, and error-code → tab resolution for all three existing codes
  (`tests/Unit/SettingsTabsTest.php`).

**Verified in a real DOM, but not committed as a test** (there is no browser/E2E harness in this
repo, and adding one is out of scope). The inline script was extracted from `enqueue_assets()` and
run in jsdom against the settings HTML dumped from wp-env. All of the following passed:

- exactly one panel visible after init, and every hidden panel still holds its inputs, none disabled;
- click activation, `aria-selected` / roving `tabindex` updates, focus following activation;
- Left/Right wrapping, Home, End, and an unrelated key being ignored;
- `#tab-{slug}` deep-linking, unknown and non-tab fragments falling back to the first tab, an errored
  tab overriding the fragment;
- activation writing only the fragment — `?page=…&tab=settings` is left intact (AC 4's collision);
- clicking Save with an emptied `skwirrel_subdomain` on a hidden panel opening that panel.

**NOT verified — needs a human with a browser** (stated plainly rather than faked):

- how the focus ring *looks* (it is defined to match `.skw-input:focus`, but nobody has looked at it);
- real Chrome/Firefox native-validation behaviour for the required-in-hidden-panel case — the
  workaround is exercised in jsdom, and jsdom is not a browser;
- the visual result of `.skw-fieldgroup:last-of-type` resolving per panel, and the strip's layout at
  the 782px breakpoint;
- screen-reader announcement of the error badge.

Everything needed for that check is running: `npm run env:start` is up, the screen is at
`http://localhost:8888/wp-admin/admin.php?page=skwirrel-pim-sync&tab=settings`.

### File List

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php` — modified (tab
  registry, error routing, panel loop, eight field groups extracted into methods)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php` — modified (tab
  behaviour added to the existing inline script)
- `plugin/skwirrel-pim-sync/assets/dashboard.css` — modified (`.skw-tabs` / `.skw-tab` /
  `.skw-tabpanel`, `.skw-notice-error`, 782px rules)
- `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` — modified (version 3.14.0)
- `plugin/skwirrel-pim-sync/readme.txt` — modified (Stable tag + `= 3.14.0 =` changelog)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot` — regenerated
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-{nl_NL,nl_BE,de_DE,fr_FR,fr_BE,en_US,en_GB}.po` — modified
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-{nl_NL,nl_BE,de_DE,fr_FR,fr_BE,en_US,en_GB}.mo` — recompiled
- `CHANGELOG.md` — modified (3.14.0)
- `package.json`, `package-lock.json` — modified (version 3.14.0)
- `tests/bootstrap.php` — modified (loads the dashboard class for unit tests)
- `tests/Unit/SettingsTabsTest.php` — added
- `tests/Integration/SettingsTabsIntegrationTest.php` — added
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — modified (story status)

## Senior Developer Review (AI)

**Reviewer:** Jos (AI-assisted)

**Date:** 2026-08-26

**Outcome:** Approve after automatic fixes

**Issues:** 1 Critical, 1 High, 3 Medium, 1 Low — all fixed; no action items remain.

### Review scope and evidence

- Story status was already `done` instead of the workflow's expected `review`; the explicit review request was treated as authority to continue, and the status remains `done` because no Critical issue remains.
- Story context was loaded from the frontmatter references, `_bmad-output/project-context.md`, the Epic 5 specification, architecture rules, UX design/experience documents, `CLAUDE.md`, and `.claude/rules/admin-settings.md`.
- Every acceptance criterion and checked task was traced to the changed PHP, CSS, unit tests, and integration tests. The story File List covers the application-source changes; unrelated `.claude`, `.gitignore`, generated story-automator, and `_bmad-output` runtime changes were excluded from code review as required by the workflow.
- External references checked: [WordPress `get_settings_errors()`](https://developer.wordpress.org/reference/functions/get_settings_errors/), [WAI-ARIA APG Tabs Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/), and [the browser `invalid` event](https://developer.mozilla.org/en-US/docs/Web/API/HTMLInputElement/invalid_event).

### Acceptance-criteria result

All eight acceptance criteria are implemented after fixes: the eight field groups remain in one full settings form across four registered panels; errors mark/open their panel; fragment deep links avoid `?tab=`; the no-JavaScript baseline exposes all panels; ARIA/keyboard/focus behaviour is present; the extension point is additive and deterministic; and the existing ID-bound behaviours remain represented by integration assertions.

### Validation

- `vendor/bin/pest` — **429 passed, 673 assertions**.
- `vendor/bin/phpstan analyse --memory-limit=2G --debug` — **no errors** (`--debug` avoids the sandbox-prohibited local worker socket).
- `vendor/bin/phpcs` — **clean, 34 files**.
- `msgfmt --check-format` — **all seven PO files valid**.
- `git diff --check` and PHP syntax checks — **clean**.
- `npm run test:integration` — **not rerun in this review environment** because access to `/Users/joskoomen/.orbstack/run/docker.sock` was denied. The pre-review implementation record reports 106 passing integration tests; the strengthened integration assertions are syntax/style checked but still need a Docker-enabled rerun.

No Critical issues remain. Story and sprint status are both `done`.

## Change Log

| Date | Version | Change |
|---|---|---|
| 2026-08-26 | 3.14.0 | Senior developer review: auto-fixed registry data-loss hardening, save-regression coverage, error counting/routing, forced-colour focus, and documentation mismatches; status remains done. |
| 2026-08-25 | 3.14.0 | Story 5.1 implemented: settings grouped into four registered tabs, error-marked and deep-linkable, with the save payload unchanged. Sanitiser errors are now rendered on the screen (they never were). |
