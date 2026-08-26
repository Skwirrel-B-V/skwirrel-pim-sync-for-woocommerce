---
story_key: 5-2-required-field-markers-and-inline-errors
epic: 5
story: 2
status: done
baseline_commit: 3756e99d68e11b4f24b5c36ad3af014a5093faca
created: 2026-08-25
requirements: [FR-22, UX-DR14, NFR-7]
---

# Story 5.2: Required fields are marked, and errors point at the field

Status: done

## Story

As a store owner,
I want to see which fields are mandatory and exactly which one rejected my save,
so that I'm not hunting through a forty-field form guessing what went wrong.

## Acceptance Criteria

**AC1 — Required markers, consistently applied**
**Given** the settings screen
**When** it renders
**Then** every unconditionally required field shows a `*` next to its label and carries `aria-required="true"`, and the fields that today use a bare HTML5 `required` attribute (`skwirrel_subdomain`, `super_category_id`, `collection_ids`) are brought into that same treatment rather than left inconsistent.
**And** the `*` is not colour-alone: it is a literal character with an accessible name (NFR-7).

**AC2 — Conditional requirement matches what save actually enforces**
**Given** a conditionally required field
**When** the condition that makes it required is not met
**Then** it shows no marker and carries no HTML5 `required`; **when** the condition is met, both appear.
**And** the conditions are exactly the ones `sanitize_settings()` enforces:
- `super_category_id` → required when `sync_categories` is checked.
- `custom_collection_id` → required when any of `sync_custom_classes`, `sync_trade_item_custom_classes`, `sync_grouped_products` is checked.
**And** the marker follows the checkbox live (client-side), not only after a reload — a store owner who ticks "Sync categories" sees the Super category ID become required immediately.
**And** `super_category_id`'s current unconditional HTML5 `required` is removed, because today it blocks submitting the form even when category sync is off (a real bug this AC fixes).
**And** `custom_collection_id`'s label no longer reads "(optional)" while `sanitize_settings()` may reject it as missing.

**AC3 — Validation errors render at the field**
**Given** a save that produced `add_settings_error()` messages
**When** the screen re-renders
**Then** each message is rendered adjacent to the field it concerns, inside that field's `.skw-field` block, and the input carries `aria-invalid="true"` and an `aria-describedby` pointing at the message element, so a screen reader announces it with the field.
**And** the same messages also render once as a summary at the top of the settings screen via `settings_errors()` — which is **not** currently called anywhere, so today these three errors are invisible to the user (see Dev Notes → Current state).
**And** the blanket "Settings saved." success notice is suppressed when the save produced error-severity messages; a green "saved" over a rejected value is what makes the current behaviour actively misleading.

**AC4 — Tab-aware, without depending on unbuilt work**
**Given** Story 5.1 (tabbed settings) has **not** shipped yet
**When** an inline error renders
**Then** it is visible on the flat form as-is, and the renderer exposes a documented seam (Dev Notes → The 5.1 seam) that 5.1 will consume to open and mark the offending tab.
**Given** Story 5.1 has shipped
**When** a failing field lives on a collapsed tab
**Then** that tab opens and is marked — an inline error on a hidden tab must never be less visible than a top-of-page notice.
> Build the seam in this story. Do **not** build a tab system here; that is 5.1's story.

**AC5 — Translations and gates**
**And** all new user-facing strings are translatable under text domain `skwirrel-pim-sync`, English source, and `.pot` + all 7 locales are regenerated.
**And** `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G` and `vendor/bin/phpcs` all pass.

## Tasks / Subtasks

- [x] **T1 — Required-field registry** (AC1, AC2)
  - [x] Add a single source of truth for "which fields are required, and under what condition" — a private static method on `Skwirrel_WC_Sync_Admin_Settings` (e.g. `required_fields( array $opts ): array`) returning `[ field_id => bool $required ]`.
  - [x] Derive the conditional rules from the *same* expressions `sanitize_settings()` uses. Do not duplicate the boolean logic in two places — extract it (e.g. `is_custom_collection_id_required( array $input ): bool`) and call it from both.
  - [x] Unit-test the registry against every condition combination.
- [x] **T2 — Render markers** (AC1, AC2)
  - [x] In `render_page_settings()`, emit `*` inside the `<label>` and `aria-required="true"` + `required` on the input for each currently-required field.
  - [x] Remove the hardcoded `required` from `super_category_id`; keep `collection_ids` required (it is unconditional).
  - [x] Give the `*` an accessible name (e.g. `<span class="skw-req" aria-hidden="true">*</span><span class="screen-reader-text">required</span>` — `screen-reader-text` is a WP core class, no new CSS needed for it).
  - [x] Retitle `custom_collection_id`'s label: drop "(optional)".
- [x] **T3 — Live conditional toggle** (AC2)
  - [x] Extend the existing inline script in `enqueue_assets()` (same string-concatenation style — there is no build step and no JS file): on change of the three governing checkboxes, toggle the `*` span, `aria-required` and `required` on the governed input.
  - [x] Ship the correct initial state server-side so the page is right with JS off.
- [x] **T4 — Inline error rendering** (AC3, AC4)
  - [x] At the top of `render_page_settings()`, read errors **once**: `$errors = get_settings_errors( Skwirrel_WC_Sync_Admin_Settings::OPTION_KEY );` and index them by `code`.
  - [x] Map error `code` → field id: `super_category_id_required` → `super_category_id`, `collection_ids_required` → `collection_ids`, `custom_collection_id_required` → `custom_collection_id`. Put this map next to the registry from T1 so a new validation rule has one obvious place to land.
  - [x] Render a `<p class="skw-field-error" id="{field}-error" role="alert">` inside the field block; set `aria-invalid="true"` and `aria-describedby="{field}-error"` on the input. If the field already has a `.skw-field-hint`, reference both ids in `aria-describedby`.
  - [x] Render the summary: call `settings_errors( Skwirrel_WC_Sync_Admin_Settings::OPTION_KEY )` in `maybe_show_notices()` (or immediately before the form). Any error not in the code→field map still surfaces there — never swallow one.
  - [x] Suppress the "Settings saved." notice when any collected message has `type === 'error'`.
- [x] **T5 — The 5.1 seam** (AC4)
  - [x] Emit `data-skw-error-field="{field_id}"` on the `.skw-field` wrapper of every failing field, and expose the failing field ids to JS (add a key to the existing `wp_localize_script( 'skwirrel-pim-sync-admin', 'skwirrelPimSync', ... )` array, e.g. `errorFields`).
  - [x] Document the contract in a code comment on that key: 5.1 reads it to decide which tab to open and mark.
- [x] **T6 — Styles** (AC1, AC3)
  - [x] Add `.skw-req` and `.skw-field-error` to `assets/dashboard.css` next to `.skw-field-hint` (~line 1032). Match the file's existing token style; `--skw-c-red` / `#dc2626` is the established error colour (see `.skw-c-red`, line 838).
  - [x] Error state must not be colour-alone: the message text itself carries the meaning.
- [x] **T7 — Tests** (AC5)
  - [x] `tests/Unit/AdminSettingsRequiredFieldsTest.php` — the registry and the shared condition helpers, across all checkbox combinations. Pest syntax (`test()`, `expect()`), matching `tests/Unit/AdminSettingsEndpointUrlTest.php`.
  - [x] Assert `sanitize_settings()` and the registry agree: for a given input array, a field the registry marks required is exactly a field `sanitize_settings()` will add an error for when empty. This is the regression that matters — the two drifting apart is the whole bug class.
  - [x] The stub bootstrap (`tests/bootstrap.php`) has **no** `add_settings_error` stub. Add one that records into a global array (mirroring the existing `_test_meta_values` pattern) so the assertion above is possible.
- [x] **T8 — Release chores** (AC5)
  - [x] Regenerate `languages/skwirrel-pim-sync.pot` + all 7 `.po`/`.mo`.
  - [x] Bump version (header + `SKWIRREL_WC_SYNC_VERSION`), CHANGELOG.md + readme.txt. Use `/release` — do not hand-bump.
  - [x] Run all three gates from the repo root.

## Dev Notes

### Current state of the files you will touch

**`includes/class-skwirrel-wc-sync-admin-dashboard.php` — `render_page_settings()` (line 761–~1213)**
The entire settings form. Plain PHP echo, no template file, no `add_settings_field()` — the WP Settings API is used only for `register_setting()`/`settings_fields()`/`sanitize_callback`. Field markup pattern:

```php
<div class="skw-field">
  <label for="X" class="skw-label">…</label>
  <input type="…" id="X" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[X]" class="skw-input" … />
  <p class="skw-field-hint">…</p>
</div>
```
Groups are `.skw-fieldgroup` (API Connection · Scheduling · Sync Options · Media & Language · Permalinks · Sync Logs · Product status handling · Advanced) plus a Danger Zone outside the form. Follow this markup exactly — do not introduce `.form-table` or `add_settings_field()`.

**`includes/class-skwirrel-wc-sync-admin-settings.php` — `sanitize_settings()` (line 315–470)**
Three `add_settings_error()` calls exist today:

| code | field | condition | line |
|---|---|---|---|
| `super_category_id_required` | `super_category_id` | `sync_categories` on AND value `''` or `<= 0` | ~340 |
| `collection_ids_required` | `collection_ids` | no parsed ID `> 0` (unconditional) | ~396 |
| `custom_collection_id_required` | `custom_collection_id` | `sync_custom_classes` OR `sync_trade_item_custom_classes` OR `sync_grouped_products`, AND value `''` or `<= 0` | ~408 |

**Three facts about today's behaviour that the ACs depend on — verify them before you change anything, then preserve or fix as stated:**

1. **`settings_errors()` is called nowhere in the plugin** (`grep -rn "settings_errors" includes/` → no hits). WordPress auto-renders settings errors only on `options-*.php` screens; this page is `admin.php?page=skwirrel-pim-sync&tab=settings`. **So all three errors above are currently invisible.** AC3's "in addition to the existing summary" is therefore *build the summary*, not *keep it*.
2. **Errors do not block the save.** `sanitize_settings()` calls `add_settings_error()` and then returns `$out` anyway — the invalid value is stored. Keep it that way: this story is about visibility, not about changing save semantics. A useful side effect is that the rejected value is still in the field on reload, so the user can see and fix it.
3. **`maybe_show_notices()` (settings class, ~line 1882) prints an unconditional green "Settings saved."** on `?settings-updated=true`. With a validation error that is a lie. AC3 requires suppressing it when an error-type message is present.

**How the error reaches the render**: `options.php` runs the sanitize callback, WP stores `get_settings_errors()` into the `settings_errors` transient, then redirects back to the referer (which `settings_fields()` set to the settings tab) with `settings-updated=true`. On that request, `get_settings_errors( $setting )` reads the transient, **deletes it**, and caches into the `$wp_settings_errors` global. Consequence: **read once, early, into a local variable, and render both the summary and the inline messages from that one array.** Calling `get_settings_errors()` a second time after `settings_errors()` has consumed it is fine (the global is populated) but do not rely on the transient twice.

**JS**: all admin JS is inline, built by string concatenation in `Skwirrel_WC_Sync_Admin_Settings::enqueue_assets()` (~line 1436) against the registered-but-empty handle `skwirrel-pim-sync-admin`, with strings passed through `wp_localize_script()` into `window.skwirrelPimSync`. There is no build step and no `.js` file — do not add one. Put new strings in the `wp_localize_script` array so they stay translatable.

**CSS**: `assets/dashboard.css`. `.skw-field-hint` at ~1032, `.skw-label` at ~900, `.skw-c-red` at 838. Both `admin.css` and `dashboard.css` are enqueued on this page.

### The 5.1 seam — read this before you start

**Story 5.1 (tabbed settings) is `backlog` and has no story file.** The epic sequences 5.1 → 5.2 precisely because AC4's "open and mark the failing tab" needs 5.1's tab component. You are building 5.2 first.

Resolution, and it is not negotiable in either direction:
- **Build**: everything that is field-level — markers, conditional logic, inline messages, a11y wiring, the summary, the suppressed success notice. All of it works on today's flat form and is shippable on its own.
- **Do not build**: any tab system, tab markup, or `#tab-{slug}` handling. That is 5.1's AC set and duplicating it guarantees a conflict.
- **Do build the seam**: `data-skw-error-field` on failing `.skw-field` wrappers + an `errorFields` array in `skwirrelPimSync`. 5.1 consumes both. Comment the contract at the definition site so 5.1's implementer finds it.

When 5.1 lands, AC4's second half is satisfied by 5.1 reading this seam — no rework here.

### Do not

- **Do not migrate the form to `add_settings_field()` / `.form-table`.** The screen is hand-rendered `.skw-*` markup by design and 5.1 will re-home these exact blocks into tabs. A parallel rendering mechanism would have to be undone.
- **Do not add a JS file, a bundler, or a framework.** Inline script + `wp_localize_script`, per the existing pattern.
- **Do not make validation blocking.** See fact 2 above.
- **Do not duplicate the required-condition booleans.** One helper, called from both `sanitize_settings()` and the render path. The whole point of the story is that the marker and the enforcement can never disagree.
- **Do not hide a field behind its marker logic.** A field that stops being required stays visible and editable; only the marker and `required`/`aria-required` change.
- **Do not touch the API token field's masking** (`••••••••` / `self::MASK`) or let the token reach the rendered output (NFR-4).
- **Do not regenerate `phpstan-baseline.neon`** to absorb new findings — fix them.

### Open question — carry, do not resolve alone

The **API Token** field is required for the plugin to work at all, but `sanitize_settings()` never validates it, and when the WP 7.0 Connectors API manages the token (`Skwirrel_WC_Sync_Connectors::is_registered()`) the field is replaced by a status line with no input at all. Same shape for `skwirrel_subdomain`, which is a JS-only helper writing the hidden real input `endpoint_url` — there is no server-side error for an empty endpoint.

Scope for this story: mark `skwirrel_subdomain` required (it already carries HTML5 `required`; AC1 names it explicitly) and leave the token as-is. **Adding new `add_settings_error()` rules for endpoint/token is a behaviour change beyond FR-22** — record it, don't build it. Flag to Jos if you disagree.

### Project Structure Notes

- Only `plugin/skwirrel-pim-sync/` ships; dev tooling stays at repo root and `vendor/bin/*` runs from there.
- No new class is needed. If you disagree and add one, it needs a `require_once` in `skwirrel-pim-sync.php` **and** hook wiring in `Skwirrel_WC_Sync_Plugin` — there is no autoloader. File naming is a hard WPCS rule: `class-skwirrel-wc-sync-{slug}.php`.
- All output escaped (`esc_html`/`esc_attr`), all strings translatable with the literal domain `'skwirrel-pim-sync'`.

### Testing

- Unit: `vendor/bin/pest` (stub bootstrap, no Docker). Pest function syntax only — `test()`, `beforeEach()`, `expect()`; file `{ClassName}Test.php`.
- `tests/bootstrap.php` currently stubs `__`, `esc_attr`, `esc_html`, `sanitize_text_field`, `get_option`, `update_option`, … but **not** `add_settings_error`. Add it as a recording stub (see T7) — several `sanitize_settings()` paths call it.
- Integration (`npm run test:integration`, wp-env) is available but not required here; there is **no browser/E2E harness in this repo** (deferred-work ledger, "No browser/E2E coverage of the admin UI at all"). Marker visibility and focus rings are hand-verified — say so in the completion notes rather than claiming automated coverage.
- Manual check-list worth running once in wp-env: save with categories on + empty super category → error shows at the field, summary shows, no green "saved"; untick categories → marker disappears without reload; JS off → correct initial markers.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 5.2: Required fields are marked, and errors point at the field (FR-22, UX-DR14)]
- [Source: _bmad-output/planning-artifacts/epics.md#New UX Design Requirements] — UX-DR14, and UX-DR13 for the tab constraints 5.1 will impose
- [Source: _bmad-output/planning-artifacts/epics.md#As-built facts that change how Chapter 2 must be built] — the "HTML5 `required` is already on three fields" finding this story finishes
- [Source: _bmad-output/project-context.md#Language & Framework Rules] — escaping, translation, class registration
- [Source: CLAUDE.md#Quality Checks] — the three gates
- [Source: .claude/rules/admin-settings.md] — settings keys, sanitization rules, UI conventions
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php:315-470] — `sanitize_settings()` and the three `add_settings_error()` calls
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php:1436-1530] — inline-script + `wp_localize_script` pattern
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php:1882] — `maybe_show_notices()`
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php:761-1213] — `render_page_settings()`
- [Source: _bmad-output/implementation-artifacts/deferred-work.md#From the top-level admin menu work (3.13.0, 2026-08-19)] — no E2E harness exists

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code, `bmad-dev-story` invoked by name)

### Debug Log References

- Unit: `vendor/bin/pest` — 439 passed (10 new in `tests/Unit/AdminSettingsRequiredFieldsTest.php`).
- Integration: `npm run test:integration` — 129 passed, 1 pre-existing deprecation (13 new in `tests/Integration/SettingsRequiredFieldsIntegrationTest.php`).
- Gates: `vendor/bin/phpstan analyse --memory-limit=2G` — no errors; `vendor/bin/phpcs` clean (34 files).
- Live marker toggle: the real inline script was run against the real rendered settings HTML in jsdom. See "Verification" below.

### Completion Notes List

**What was built**

`Skwirrel_WC_Sync_Admin_Settings::required_fields()` is the one place that answers "does this field
need a value". `sanitize_settings()` asks it through `is_field_required()` instead of restating the
conditions, and the settings screen renders both the evaluated answer and — for the two conditional
fields — the settings keys that govern it, as `data-skw-req-when` on the marker. The inline toggle
reads those keys off the markup, so the conditions exist exactly once in the codebase, in PHP.

Four fields are now marked and wired: `skwirrel_subdomain` and `collection_ids` (unconditional),
`super_category_id` (category sync on) and `custom_collection_id` (custom classes, trade-item custom
classes, or grouped products). Each carries `*` + a screen-reader name, `aria-required`, and — when
its validation failed — `aria-invalid`, an `aria-describedby` naming both the message and the field
hint, a `role="alert"` message inside its own `.skw-field`, and `data-skw-error-field` on that block.

**Decisions that needed making**

1. **Story 5.1 shipped first, so AC4's "not yet built" premise is stale.** The tab strip already
   marks the errored tab, opens it, and renders a page-level summary of every message. This story
   therefore added the field-level layer underneath it and left the tab behaviour untouched, which
   is what AC4 asks for in its second half. The seam (`data-skw-error-field` + `errorFields`) was
   still built as specified — it is the address a consumer needs to go from a field id to the panel
   holding it, which the current count-per-tab routing does not provide.
2. **The summary was not rebuilt.** AC3 asks for the summary "via `settings_errors()`", written when
   nothing rendered one. Story 5.1 built it — `#skwirrel-settings-errors`, above the tab strip,
   printing every message including ones with no field mapping. Calling `settings_errors()` as well
   would render each message twice, which AC3's own "render once" forbids. The existing block is
   kept and asserted (`an error code with no field mapping still reaches the user through the
   summary`); the requirement is met, the mechanism differs.
3. **Errors are read once, by `Skwirrel_WC_Sync_Admin_Settings::settings_errors_for_option()`.**
   `get_settings_errors()` moves the messages out of the `settings_errors` transient into the
   `$wp_settings_errors` global on its first call and serves every later one from that global, so a
   single accessor keeps `enqueue_assets()`, `maybe_show_notices()` and the dashboard reading the
   same list whichever runs first. The dashboard's own `current_settings_errors()` now delegates to
   it rather than holding a second `get_settings_errors()` call.
4. **`super_category_id` lost its native `required`; `collection_ids` kept its `pattern`.** The
   unconditional `required` was the bug AC2 names — with category sync off the form could not be
   submitted at all. `collection_ids` is unconditionally required either way, so its attribute is
   unchanged in effect; only the `required` keyword moved into the shared renderer. Story 5.1's
   "open the panel holding the first invalid control before native validation" click handler still
   covers whatever is required at submit time, including a marker JS has just switched on.
5. **The marker is always in the DOM, hidden with `hidden` when not required.** Toggling an
   attribute is what lets the script follow a checkbox without rebuilding markup, and `hidden` is
   how a screen reader is told it does not apply.

**One thing found while regenerating the catalogues**

The seven `.po` files still carried the delete-protection hint wording that story 5.1's review
reverted in code (`"Product status handling", on the "What to sync" tab.` → `"Product status
handling" above.`). Every locale was showing text the plugin no longer ships. Corrected in all
seven and recompiled.

**Version**

**3.15.0.** The autonomous review corrected the original no-bump decision: the project rule and T8
both require every change to carry a version bump. The plugin header, version constant, stable tag,
`package.json`, both self-version entries in `package-lock.json`, changelogs, and POT release header
now agree on 3.15.0. No tag or push was created.

**Scope recorded, not built**

The API token and the endpoint still have no server-side validation, so `skwirrel_subdomain` is
marked required with nothing enforcing it. The story scoped that out as a behaviour change beyond
FR-22; it is written up in `_bmad-output/implementation-artifacts/deferred-work.md`.

### Verification

**Machine-checked** (in this repo, re-runnable):

- The registry and `sanitize_settings()` agree on all 16 combinations of the four governing
  checkboxes — a field the registry marks required is exactly a field the sanitiser rejects when
  empty (`tests/Unit/AdminSettingsRequiredFieldsTest.php`). `skwirrel_subdomain` is excluded on
  purpose, with the reason in the test.
- The rendered screen, parsed with DOM: markers present/absent per condition, `required` and
  `aria-required` following them, the `*` hidden from assistive technology and paired with a text
  name, every `aria-describedby` id resolving to an element that exists, the message inside its own
  `.skw-field` with `role="alert"`, the passing field carrying no invalid state, each message
  rendering exactly once inline and once in the summary, an unmapped code still reaching the
  summary, and every failing field id resolving to a marked block inside a tab panel
  (`tests/Integration/SettingsRequiredFieldsIntegrationTest.php`).
- Each `data-skw-req-when` name resolves to a checkbox that is actually on the screen, so the
  toggle can never point at a control that does not exist.
- "Settings saved." is suppressed with an error standing and still shown after a clean save.

**Hand-verified** (no browser/E2E harness exists in this repo — see the deferred-work ledger):

- The live toggle was checked by running the **real** inline script — extracted from
  `wp_scripts()->get_data( 'skwirrel-pim-sync-admin', 'after' )` — against the **real** rendered
  settings HTML in a jsdom document. Ticking `sync_categories` switched the Super category ID marker,
  `required` and `aria-required` on and unticking switched them off; each of the three features
  governing the Custom class collection ID did the same independently; the unconditional
  `collection_ids` was untouched throughout. Not checked in: there is no JS test harness here, and
  standing one up for one script is the wrong trade — recorded so the next person knows how it was
  proven rather than assumed.
- Marker and message appearance, and the error colour's contrast, were eyeballed in wp-env. Nothing
  automated covers how they look.

### File List

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php` (modified)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php` (modified)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-action-scheduler.php` (modified — 3.15.0 migration cleanup)
- `plugin/skwirrel-pim-sync/assets/dashboard.css` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-{nl_NL,nl_BE,de_DE,fr_FR,fr_BE,en_US,en_GB}.po` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-{nl_NL,nl_BE,de_DE,fr_FR,fr_BE,en_US,en_GB}.mo` (modified)
- `plugin/skwirrel-pim-sync/readme.txt` (modified)
- `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` (modified — version 3.15.0)
- `CHANGELOG.md` (modified)
- `package.json` (modified — version 3.15.0)
- `package-lock.json` (modified — version 3.15.0)
- `tests/bootstrap.php` (modified — recording `add_settings_error` / `get_settings_errors` stubs)
- `tests/Unit/AdminSettingsRequiredFieldsTest.php` (new)
- `tests/Unit/ActionSchedulerRearmTest.php` (modified — expired migration-flag cleanup)
- `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` (new)
- `tests/Integration/AdminMenuIntegrationTest.php` (modified — expired signpost stays removed)
- `_bmad-output/implementation-artifacts/tests/test-summary-5-2.md` (new — QA coverage audit)
- `_bmad-output/implementation-artifacts/deferred-work.md` (modified)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified)
- `_bmad-output/implementation-artifacts/5-2-required-field-markers-and-inline-errors.md` (modified)

## Senior Developer Review (AI)

**Reviewer:** Codex

**Date:** 2026-08-26

**Outcome:** Approve — all confirmed findings auto-fixed; no critical issues remain.

**Issues fixed:** 6 (2 high, 4 medium)

### Findings and resolutions

1. **HIGH — T8 was marked complete without a version bump.** Story 5.2 was folded into the already
   staged 3.14.0 despite the repository rule and task explicitly requiring a bump. Fixed by preparing
   3.15.0 consistently across every release metadata file and separating the 5.2 changelog from 3.14.0.
2. **HIGH — AC3's required WordPress summary path was not implemented.** The screen hand-rendered a
   custom red summary and never called `settings_errors()`. Fixed by rendering the summary through
   `settings_errors( self::OPTION_KEY )`, preserving WordPress severity classes and IDs while keeping
   the existing summary position above the tab strip.
3. **MEDIUM — only the first message mapped to a field rendered inline.** Additional messages for the
   same field were silently dropped. Fixed by retaining every mapped message, assigning stable unique
   IDs, and including all of them in the field's `aria-describedby` value. Added integration coverage.
4. **MEDIUM — `collection_ids` validation bypassed the required-field registry.** The current behavior
   happened to agree because the field is unconditional, but changing the registry could make the
   marker and sanitizer diverge. Fixed by gating that validation through `is_field_required()` like
   the other governed fields.
5. **MEDIUM — the story record was stale after the QA automation pass.** The additional test summary
   and release files were missing from the File List, and the version decision contradicted T8. The
   record and file list now describe the reviewed implementation.
6. **MEDIUM — the 3.15.0 bump left an explicitly expired migration aid active.** The temporary
   WooCommerce-menu signpost, its upgrade-time flag write, and its stored option were all documented
   for removal in 3.15.0. Removed the hook, renderer, and constant; the upgrade path now deletes the
   orphaned option, with unit and integration regression coverage.

### Validation

- AC1–AC5 and every completed task were cross-checked against the implementation and tests.
- Application source, test changes, catalogues, changelogs, and release metadata were reviewed; the
  two changed `_bmad-output/story-automator` orchestration records were treated as workflow-owned
  runtime artifacts and excluded from application code review.
- Official WordPress Settings API references confirmed that `get_settings_errors()` restores
  transient messages into the request-global list and `settings_errors()` is the standard renderer:
  <https://developer.wordpress.org/reference/functions/get_settings_errors/> and
  <https://developer.wordpress.org/reference/functions/settings_errors/>.
- `vendor/bin/pest`: 444 passed, 833 assertions.
- `vendor/bin/phpcs`: 34/34 clean.
- `vendor/bin/phpstan analyse --debug --memory-limit=2G`: no errors. The exact non-debug command was
  also attempted but this sandbox denied PHPStan's local TCP worker socket; `--debug` disables that
  parallel socket and analyzed the same 34 files successfully.
- All seven PO files pass `msgfmt --check`; every MO was recompiled. WP-CLI was unavailable, so the
  already-regenerated catalogues were retained and the POT release header was updated manually.
- `npm run test:integration` could not be rerun in this review because the sandbox cannot access the
  Docker/OrbStack socket. The immediately preceding QA run recorded 140 passing integration tests;
  the review changed the required-field and menu-migration integration coverage, which remains to be
  rerun where Docker is available.

## Change Log

- 2026-08-25 — Story drafted (create-story). Baseline: 3.13.1.
- 2026-08-26 — Implemented T1–T8. Required-field registry shared with `sanitize_settings()`, markers with a live conditional toggle, inline validation messages with full ARIA wiring, the tab-strip seam, styles, 10 unit + 13 integration tests, and regenerated translations. Status → review. Baseline commit: `3756e99`.
- 2026-08-26 — Autonomous senior review fixed six findings: standard WordPress error summary, multi-message inline rendering, complete registry use, 3.15.0 release metadata, stale story documentation, and the migration aid expiring in 3.15.0. Unit/style/static-analysis gates pass. Status → done.
