---
story_key: 5-2-required-field-markers-and-inline-errors
epic: 5
story: 2
status: ready-for-dev
created: 2026-08-25
requirements: [FR-22, UX-DR14, NFR-7]
---

# Story 5.2: Required fields are marked, and errors point at the field

Status: ready-for-dev

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

- [ ] **T1 — Required-field registry** (AC1, AC2)
  - [ ] Add a single source of truth for "which fields are required, and under what condition" — a private static method on `Skwirrel_WC_Sync_Admin_Settings` (e.g. `required_fields( array $opts ): array`) returning `[ field_id => bool $required ]`.
  - [ ] Derive the conditional rules from the *same* expressions `sanitize_settings()` uses. Do not duplicate the boolean logic in two places — extract it (e.g. `is_custom_collection_id_required( array $input ): bool`) and call it from both.
  - [ ] Unit-test the registry against every condition combination.
- [ ] **T2 — Render markers** (AC1, AC2)
  - [ ] In `render_page_settings()`, emit `*` inside the `<label>` and `aria-required="true"` + `required` on the input for each currently-required field.
  - [ ] Remove the hardcoded `required` from `super_category_id`; keep `collection_ids` required (it is unconditional).
  - [ ] Give the `*` an accessible name (e.g. `<span class="skw-req" aria-hidden="true">*</span><span class="screen-reader-text">required</span>` — `screen-reader-text` is a WP core class, no new CSS needed for it).
  - [ ] Retitle `custom_collection_id`'s label: drop "(optional)".
- [ ] **T3 — Live conditional toggle** (AC2)
  - [ ] Extend the existing inline script in `enqueue_assets()` (same string-concatenation style — there is no build step and no JS file): on change of the three governing checkboxes, toggle the `*` span, `aria-required` and `required` on the governed input.
  - [ ] Ship the correct initial state server-side so the page is right with JS off.
- [ ] **T4 — Inline error rendering** (AC3, AC4)
  - [ ] At the top of `render_page_settings()`, read errors **once**: `$errors = get_settings_errors( Skwirrel_WC_Sync_Admin_Settings::OPTION_KEY );` and index them by `code`.
  - [ ] Map error `code` → field id: `super_category_id_required` → `super_category_id`, `collection_ids_required` → `collection_ids`, `custom_collection_id_required` → `custom_collection_id`. Put this map next to the registry from T1 so a new validation rule has one obvious place to land.
  - [ ] Render a `<p class="skw-field-error" id="{field}-error" role="alert">` inside the field block; set `aria-invalid="true"` and `aria-describedby="{field}-error"` on the input. If the field already has a `.skw-field-hint`, reference both ids in `aria-describedby`.
  - [ ] Render the summary: call `settings_errors( Skwirrel_WC_Sync_Admin_Settings::OPTION_KEY )` in `maybe_show_notices()` (or immediately before the form). Any error not in the code→field map still surfaces there — never swallow one.
  - [ ] Suppress the "Settings saved." notice when any collected message has `type === 'error'`.
- [ ] **T5 — The 5.1 seam** (AC4)
  - [ ] Emit `data-skw-error-field="{field_id}"` on the `.skw-field` wrapper of every failing field, and expose the failing field ids to JS (add a key to the existing `wp_localize_script( 'skwirrel-pim-sync-admin', 'skwirrelPimSync', ... )` array, e.g. `errorFields`).
  - [ ] Document the contract in a code comment on that key: 5.1 reads it to decide which tab to open and mark.
- [ ] **T6 — Styles** (AC1, AC3)
  - [ ] Add `.skw-req` and `.skw-field-error` to `assets/dashboard.css` next to `.skw-field-hint` (~line 1032). Match the file's existing token style; `--skw-c-red` / `#dc2626` is the established error colour (see `.skw-c-red`, line 838).
  - [ ] Error state must not be colour-alone: the message text itself carries the meaning.
- [ ] **T7 — Tests** (AC5)
  - [ ] `tests/Unit/AdminSettingsRequiredFieldsTest.php` — the registry and the shared condition helpers, across all checkbox combinations. Pest syntax (`test()`, `expect()`), matching `tests/Unit/AdminSettingsEndpointUrlTest.php`.
  - [ ] Assert `sanitize_settings()` and the registry agree: for a given input array, a field the registry marks required is exactly a field `sanitize_settings()` will add an error for when empty. This is the regression that matters — the two drifting apart is the whole bug class.
  - [ ] The stub bootstrap (`tests/bootstrap.php`) has **no** `add_settings_error` stub. Add one that records into a global array (mirroring the existing `_test_meta_values` pattern) so the assertion above is possible.
- [ ] **T8 — Release chores** (AC5)
  - [ ] Regenerate `languages/skwirrel-pim-sync.pot` + all 7 `.po`/`.mo`.
  - [ ] Bump version (header + `SKWIRREL_WC_SYNC_VERSION`), CHANGELOG.md + readme.txt. Use `/release` — do not hand-bump.
  - [ ] Run all three gates from the repo root.

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

### Debug Log References

### Completion Notes List

### File List

## Change Log

- 2026-08-25 — Story drafted (create-story). Baseline: 3.13.1.
