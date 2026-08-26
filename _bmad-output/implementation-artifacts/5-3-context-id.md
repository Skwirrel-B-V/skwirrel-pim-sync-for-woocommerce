---
status: done
baseline_commit: 7737f8f07ac6c3fca4d24f8b4f3a2d9bd3318ba1
baseline_revision: 0f7c3c4964b7789f74d7c14f307c1c083a9a22a4
context:
  - _bmad-output/project-context.md
  - _bmad-output/planning-artifacts/epics.md
  - .claude/rules/sync-service.md
  - .claude/rules/admin-settings.md
  - CLAUDE.md
---

# Story 5.3: Context ID

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner whose Skwirrel instance serves more than one context,
I want to tell the plugin which context to read,
so that I import the content intended for this shop.

---

## ⚠️ BLOCKER RESOLVED — read this first

The epic marks this story **BLOCKED: exact API parameter name unconfirmed**. It is now confirmed
**from the as-built code**, not from guesswork:

> **The parameter is `include_contexts`, and it takes an array of integer context IDs.**

Evidence — it is already sent, hardcoded to `[ 1 ]`, at **five** live call sites that run against the
production Skwirrel API today:

| File | Line | Method it feeds |
|---|---|---|
| `class-skwirrel-wc-sync-service.php` | 271 | `getProducts` / `getProductsByFilter` (main run) |
| `class-skwirrel-wc-sync-service.php` | 1811 | `getProductsByFilter` (single-product re-sync) |
| `class-skwirrel-wc-sync-service.php` | 2087 | `getProductsByFilter` (grouped members) |
| `class-skwirrel-wc-sync-service.php` | 2222 | `getProductsByFilter` (attribute re-fetch) |
| `class-skwirrel-wc-sync-category-sync.php` | 183 | `getCategories` |

This matches FR-20 exactly — "optional API parameter, API-side default `1`". The hardcoded `[ 1 ]`
**is** that default, written out longhand. This story's real work is turning five hardcoded literals
into one configurable value.

**One residual uncertainty, and it is the only thing worth checking with the API:** `getGroupedProducts`
currently sends **no** `include_contexts` at all (`class-skwirrel-wc-sync-product-upserter.php:~940`,
`class-skwirrel-wc-sync-service.php:~1966`). AC-2 requires the context to reach it. Whether that method
accepts the parameter is unproven. **AC-3's design makes this safe to build regardless**: the parameter is
only ever added to `getGroupedProducts` when a Context ID is actually configured, so no existing install
sends a parameter it does not send today. If Skwirrel rejects it on that method, the fix is confined to
those two lines.

---

## Acceptance Criteria

### AC-1 — The field exists on the Connection group

**Given** the settings screen
**When** the **API Connection** field group renders
**Then** an optional **Context ID** field is present, `type="number"`, `min="1"`, `step="1"`, placeholder `1`,
built from the existing `.skw-field` / `.skw-label` / `.skw-input` components used by every other field in
that group.
**And** its help text states, in plain language, that leaving it empty uses the Skwirrel default context.
**And** it is not marked required — it is genuinely optional.
**And when** Story 5.1's tab registration exists, the field lands on the **Connection** tab by virtue of
living in the API Connection group; nothing in this story may hardcode a tab.

### AC-2 — A configured Context ID reaches every API call

**Given** a Context ID of `N`
**When** any JSON-RPC call is made
**Then** `include_contexts => [ N ]` is sent on `getProducts`, `getProductsByFilter`, `getGroupedProducts`
**and `getCategories`** — at all nine application call sites listed in the Dev Notes, with no site left on `[ 1 ]`.

> **`getCategories` is deliberately in scope even though the epic's AC names only the three product methods.**
> Categories are fetched with `include_contexts => [ 1 ]` hardcoded today. Leaving it there while products
> come from context `N` produces exactly the mixed-context catalog that FR-20's force-full-sync rule exists
> to prevent — products from one context filed under another context's category tree. Including it is the
> smaller risk. Flagged for Jos in Questions below.

### AC-3 — An empty Context ID changes nothing for existing installs

**Given** the Context ID field is empty (every install today, and every install that never sets it)
**When** any JSON-RPC call is made
**Then** the request body is **byte-for-byte what it is today**: the five sites that send
`include_contexts => [ 1 ]` still send it, and the two `getGroupedProducts` sites, membership sweep and
status-discovery request still send **nothing**.

> **This AC deliberately reinterprets the epic's wording.** The epic says the parameter is "omitted
> entirely" when empty, *and* that existing installs "behave exactly as before this story". Under the
> as-built code those two demands contradict each other — today the parameter is sent as `[ 1 ]`, so
> omitting it would be a behaviour change on every existing install. Since the API default is `1`, sending
> `[ 1 ]` and omitting the parameter are semantically identical, so preserving the current wire format
> satisfies the *intent* of both halves and carries zero regression risk. Do not "clean this up" by
> switching empty to omission.

### AC-4 — Changing the effective context forces a full sync

**Given** a shop that has already synced
**When** a save changes the **effective context** — the value `get_context_ids()` resolves to, not the raw
string typed into the field — including empty → set, set → empty, and set → different value
**Then** `skwirrel_wc_sync_force_full_sync` is set, so the next run is a full sync and the catalog cannot
end up a mix of two contexts.
**And** the admin is shown a plain-language notice on save saying a full re-sync will follow — not jargon
about flags or options.

**Given** a save that leaves the effective context unchanged (any other setting edited, a plain re-save, or
one invalid value replaced by another invalid value — both resolve to the default)
**When** the save completes
**Then** the flag is **not** set and no notice is shown — re-saving the settings page must never silently
schedule a full re-sync of the catalog.

> **Compare the resolved context, not the raw string.** AC-5 stores invalid input verbatim, so a raw-string
> comparison would fire the flag on `"abc"` → `"xyz"` even though both resolve to the same default context.
> Comparing what `get_context_ids()` returns makes the rule exactly true: force a full sync precisely when
> the context actually being read changes.

### AC-5 — Invalid values are reported, and never take effect

**Given** a non-numeric value, `0`, or a negative value
**When** the settings are saved
**Then** it is reported via `add_settings_error()` with a message naming the field and the rule
**And** `get_context_ids()` returns `null` for it, so every call site falls back to its current behaviour
(AC-3) — an invalid Context ID is inert, never sent to the API, and never coerced into a different
context.

**Given** the same invalid value
**When** the screen re-renders
**Then** the rejected input is **still in the field**, so the user can see what they typed and correct it.

> **This follows Story 5.2's established convention.** 5.2 pins the house rule: *"Errors do not block the save. `sanitize_settings()` calls `add_settings_error()` and then
> returns `$out` anyway — the invalid value is stored. Keep it that way… a useful side effect is that the
> rejected value is still in the field on reload."* Do **not** special-case this field to restore the
> previous value — that would diverge from every other validated field on the form. Storing the bad value is
> safe here precisely because `get_context_ids()` refuses to resolve it.

### AC-6 — Coverage

**Given** the unit suite
**When** it runs
**Then** it pins: a set Context ID produces `include_contexts => [ N ]`; an empty Context ID leaves the five
default sites at `[ 1 ]` and adds nothing to the two grouped sites, membership sweep or status discovery; a change in **effective** context sets
the force-full-sync flag; an unchanged effective context does not (including invalid → invalid); and each of
non-numeric / `0` / negative raises a settings error while `get_context_ids()` returns `null`.

**And** all new user-facing strings are translatable under `skwirrel-pim-sync` with English source text.

---

## Tasks / Subtasks

- [x] **Task 1 — Settings storage, validation and the helper (AC: 1, 3, 5)**
  - [x] Add `context_id` (string, default `''`) to the settings defaults in
        `Skwirrel_WC_Sync_Service::get_options()` (`class-skwirrel-wc-sync-service.php:2336`).
  - [x] In `Skwirrel_WC_Sync_Admin_Settings::sanitize_settings()`: accept empty as valid; otherwise require
        a positive integer. On failure call `add_settings_error()` and **store the raw value anyway**, per
        5.2's convention (AC-5) — do not restore the previous value, do not coerce.
  - [x] Do **not** add `context_id` to 5.2's `required_fields()` registry — the field is optional.
  - [x] Add `public static function get_context_ids(): ?array` to `Skwirrel_WC_Sync_Admin_Settings` —
        returns `[ (int) $context_id ]` when the setting is a positive integer, `null` when empty/unset.
        Mirror the placement and doc style of the existing `get_auth_token()`.
- [x] **Task 2 — Render the field (AC: 1)**
  - [x] Add the field to the **API Connection** group in `class-skwirrel-wc-sync-admin-dashboard.php`
        (group starts line 775). Place it after Retries, inside the existing `.skw-field-row` pattern.
  - [x] Escape all output (`esc_attr`, `esc_html__`); wrap every string in `__()` / `esc_html__()`.
- [x] **Task 3 — Wire the five existing default-context sites (AC: 2, 3)**
  - [x] Replace each `'include_contexts' => [ 1 ]` with
        `'include_contexts' => Skwirrel_WC_Sync_Admin_Settings::get_context_ids() ?? [ 1 ]` at:
        `service.php:271`, `service.php:1811`, `service.php:2087`, `service.php:2222`,
        `category-sync.php:183`.
  - [x] Confirm by grep that **no** bare `[ 1 ]` context literal remains.
- [x] **Task 4 — Wire the two grouped-product sites (AC: 2, 3)**
  - [x] In `product-upserter.php` `sync_grouped_products_first()` (params array ~line 940) and
        `service.php` single-grouped-sync (params array ~line 1966), add **conditionally**:
        `$ctx = Skwirrel_WC_Sync_Admin_Settings::get_context_ids(); if ( null !== $ctx ) { $params['include_contexts'] = $ctx; }`
  - [x] The conditional is load-bearing for AC-3 — do not collapse it to `?? [ 1 ]`.
- [x] **Task 5 — Force full sync on change (AC: 4)**
  - [x] Extend the existing `on_settings_updated( $old_value, $value )` hook
        (`class-skwirrel-wc-sync-admin-settings.php:286`) — it already receives both old and new values.
  - [x] Compare the **resolved** context of `$old_value` against that of `$value` (same rule as
        `get_context_ids()`: positive int → `[ N ]`, anything else → `null`), not the raw strings. Only on a
        genuine difference call `update_option( 'skwirrel_wc_sync_force_full_sync', true )`.
  - [x] Factor the resolve rule into one private helper used by both `get_context_ids()` and this
        comparison, so the marker and the enforcement can never disagree (the same principle 5.2 applies to
        its required-field conditions).
  - [x] Log the reason via `Skwirrel_WC_Sync_Logger`, matching the wording style of the existing
        force-full-sync log lines in `class-skwirrel-wc-sync-delete-protection.php:440`.
  - [x] Surface the plain-language admin notice (translatable).
- [x] **Task 6 — Tests (AC: 6)**
  - [x] New `tests/Unit/ContextIdTest.php`, Pest style (`test()`, `beforeEach()`, `expect()`).
  - [x] Cover every case listed in AC-6.
- [x] **Task 7 — Quality gates and release hygiene**
  - [x] `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs` — all three
        green from the repo root before commit. Fix findings; never regenerate the PHPStan baseline to hide
        them.
  - [x] Bump `Version:` **and** `SKWIRREL_WC_SYNC_VERSION`; add the entry to `CHANGELOG.md` **and**
        `readme.txt` (`= X.Y.Z =` + `Stable tag:`); regenerate `.pot` and all 7 locales' `.po`/`.mo`.
        Prefer the `/release` skill over bumping by hand.
- [x] **Task 8 — Senior review auto-fixes (AC: 1, 2, 3, 5, 6)**
  - [x] Add the configured context to the selection-membership sweep and status-discovery requests,
        while preserving their previous empty request shape when the field is unset or invalid.
  - [x] Reject positive digit strings larger than `PHP_INT_MAX` instead of casting them to a different ID.
  - [x] Move the Context ID field into the API Connection `.skw-field-row` required by Task 2.
  - [x] Remove the integration test's stray `STDERR` debug write and document all test files.

---

## Dev Notes

### The complete call-site map

Every place a context is or should be sent. Verified by grep against `0f7c3c4`.

| # | File | Line | Today | After this story |
|---|---|---|---|---|
| 1 | `class-skwirrel-wc-sync-service.php` | 271 | `[ 1 ]` | `get_context_ids() ?? [ 1 ]` |
| 2 | `class-skwirrel-wc-sync-service.php` | 1811 | `[ 1 ]` | `get_context_ids() ?? [ 1 ]` |
| 3 | `class-skwirrel-wc-sync-service.php` | 2087 | `[ 1 ]` | `get_context_ids() ?? [ 1 ]` |
| 4 | `class-skwirrel-wc-sync-service.php` | 2222 | `[ 1 ]` | `get_context_ids() ?? [ 1 ]` |
| 5 | `class-skwirrel-wc-sync-category-sync.php` | 183 | `[ 1 ]` | `get_context_ids() ?? [ 1 ]` |
| 6 | `class-skwirrel-wc-sync-product-upserter.php` | ~940 | *absent* | added **only if** configured |
| 7 | `class-skwirrel-wc-sync-service.php` | ~1966 | *absent* | added **only if** configured |
| 8 | `class-skwirrel-wc-sync-product-upserter.php` | ~1175 | *absent* | membership sweep: added **only if** configured, nested in `options` |
| 9 | `class-skwirrel-wc-sync-admin-settings.php` | ~946 | *absent* | status discovery: added **only if** configured |

Sites 1–5 keep `[ 1 ]` when unconfigured; sites 6–9 keep sending nothing. That asymmetry is intentional
(AC-3) — it is what makes the change a no-op for every existing install.

**Out of scope, deliberately:** `getBrands` (`brand-sync.php:117`) and `getCustomClasses`
(`taxonomy-manager.php:56`) take no context parameter today. Adding one is unproven and unrequested. Leave
them.

### Parameter nesting differs by method — do not centralise this in `call()`

It is tempting to inject `include_contexts` once inside
`Skwirrel_WC_Sync_JsonRpc_Client::call()`. **Don't.** The nesting is method-dependent:

- `getProducts` — include flags are **top-level** params (`fetch_products_page()`, `service.php:2324`)
- `getProductsByFilter` — include flags are nested under **`options`** (`service.php:2312`)
- `getCategories` / `getGroupedProducts` — **top-level**

A central injector would have to re-derive that shape from the method name, which is exactly the kind of
implicit rule that breaks the next time a method is added. Nine explicit call sites reading from one
helper is the maintainable version.

### The change gate already handles this — don't build a second mechanism

`compute_sync_signature()` (`service.php:2368`) hashes **every** setting except an explicit denylist
(`endpoint_url`, `auth_type`, `auth_token`, `timeout`, `retries`, `batch_size`, `sync_interval`,
`verbose_logging`, `log_*`, `show_delete_warning`, `protect_from_deletion`). `context_id` is **not** on that
denylist, so it is picked up automatically: changing it busts the content-hash change gate and every product
reprocesses.

That is complementary to AC-4's `force_full_sync` flag, not a substitute — the signature forces
*reprocessing*, the flag forces a *full fetch*. You need both. **Do not add `context_id` to the denylist.**

### `handle_test_connection_ajax()` writes settings — verify you don't disturb it

`handle_test_connection_ajax()` (`admin-settings.php:590`) calls
`update_option( self::OPTION_KEY, $opts, false )` directly, **bypassing `sanitize_settings()`**. It reads the
existing options array and overwrites only `endpoint_url`, `auth_type` and `auth_token`, so `context_id`
survives untouched and AC-4's comparison sees no change — correct behaviour, but it depends on that
read-merge-write shape. If you touch that method, preserve it: a test-connection click must never trip the
force-full-sync flag.

### Existing patterns to follow, not reinvent

- **Validation + error**: copy the shape of the `super_category_id_required` and `collection_ids_required`
  blocks in `sanitize_settings()` (`admin-settings.php:337` and `:389`). Same `add_settings_error()` call,
  same `self::OPTION_KEY` group, same `'error'` severity.
- **Field markup**: the Timeout / Retries pair in the API Connection group
  (`admin-dashboard.php:829-837`) is the closest template — a numeric `.skw-input.skw-input-sm` inside a
  `.skw-field-row`.
- **Force-full-sync logging**: `delete-protection.php:440` and `:483` show the house style — state what
  happened and what will follow, in one sentence.
- **Settings cache**: `on_settings_updated()` already calls `bust_settings_cache()`. Your addition rides
  along; don't add a second cache-bust.

### Coordination with Stories 5.1 / 5.2 — neither blocks this one

All three Epic 5 stories are `ready-for-dev` in parallel. This story is written to land in any order.

- **5.2 (`add_settings_error` stub).** 5.2's task T7 adds a recording `add_settings_error` stub to
  `tests/bootstrap.php`, because the stub bootstrap does not have one today. **Your tests need it too.**
  Whoever lands first adds it; if it is already there, reuse it — do not add a second stub.
- **5.2 (inline errors).** Your validation is a plain `add_settings_error()` call, so it renders as today's
  top-of-page notice before 5.2 lands and is re-homed inline for free afterwards. Nothing to do either way.
- **5.2 (the 5.1 seam).** If 5.2 has landed, its renderer puts `data-skw-error-field` on failing
  `.skw-field` wrappers and lists them in `skwirrelPimSync.errorFields`. Your field gets that automatically
  by using the standard `.skw-field` markup — do not hand-roll a parallel mechanism.
- **5.1 (tabs).** Your field lives in the **API Connection** `.skw-fieldgroup`, which 5.1 re-homes onto the
  Connection tab wholesale. Put the field in that group and 5.1 picks it up with no rework. **Do not
  reference a tab, a tab slug, or a tab registration anywhere in this story's code.**

### Ignore architecture.md's Resolver / Committer vocabulary

`_bmad-output/planning-artifacts/architecture.md` (D1–D7) specifies a `Resolver` / `Change_Set` /
`Committer` / `Run_Ledger` rewrite with a hard "only `Committer` may write" invariant. **That rewrite was
abandoned** in the 2026-07-22 correct-course (`sprint-change-proposal-2026-07-22.md`); Epic 1 shipped as
incremental hardening of the queue core instead. None of those classes exist.

Do not attempt to route this story's `update_option()` calls through a `Committer`, and do not treat the
settings-save path as a "write-free resolve path". Write settings the way `sanitize_settings()` and
`on_settings_updated()` already do. The architecture doc's *foundational* conventions (WPCS naming,
translatable strings, three green gates) do still bind — those are restated in `project-context.md`.

### Project Structure Notes

- Only files under `plugin/skwirrel-pim-sync/` ship. Tests live at the repo root under `tests/`.
- **No new class is needed.** This story adds a static helper to an existing class, so there is no
  `require_once` to add to `skwirrel-pim-sync.php` and no hook to wire in `Skwirrel_WC_Sync_Plugin` — the
  two-place registration rule does not apply here.
- No new meta key, no new option. `context_id` lives inside the existing `skwirrel_wc_sync_settings` array;
  `skwirrel_wc_sync_force_full_sync` already exists.

### Testing standards

- Pest, not class-based PHPUnit: `test()`, `beforeEach()`, `expect()`, `dataset()`/`with()`.
- Unit tests run on the stub bootstrap (`tests/bootstrap.php`) — no Docker.
- `JsonRpc Client` request building is a documented priority test target; this story extends it.
- Don't weaken an existing assertion to make the suite pass.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 5.3: Context ID (FR-20)`]
- [Source: `_bmad-output/planning-artifacts/epics.md#New Functional Requirements` — FR-20]
- [Source: `_bmad-output/planning-artifacts/epics.md#As-built facts that change how Chapter 2 must be built`]
- [Source: `_bmad-output/project-context.md#Sync Architecture Rules`]
- [Source: `.claude/rules/admin-settings.md#Sanitization Rules`]
- [Source: `.claude/rules/sync-service.md#Delta vs Full Sync`]
- [Source: `_bmad-output/implementation-artifacts/5-2-required-field-markers-and-inline-errors.md`] — the
  save-semantics convention (errors don't block the save), the `add_settings_error` test stub, the 5.1 seam
- [Source: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-07-22.md`] — why architecture.md's
  Resolver/Committer design does not apply

---

## Questions for Jos (raised after the story was written — none block dev)

1. **`getCategories` is in scope (AC-2).** The epic's AC names only the three product methods, but
   categories are fetched on context `1` today. Syncing products from context `N` while categories stay on
   context `1` is the mixed-catalog failure FR-20 is meant to prevent. Confirm you want categories to follow
   the Context ID — the story is written assuming yes.
2. **`getGroupedProducts` has never been sent a context.** AC-2 requires it; AC-3's conditional keeps that
   safe for existing installs. Worth one look at the API docs to confirm the method accepts
   `include_contexts` before dev starts — if it doesn't, drop sites 6–7 and the story still delivers.
3. **Multiple contexts?** `include_contexts` is an array, so the API can evidently read several at once. This
   story ships a single-value field per FR-20. Say so if a comma-separated multi-context field is wanted
   instead — it's a small change now and a migration later.

## Dev Agent Record

### Agent Model Used

claude-opus-5 (bmad-dev-story workflow)

### Debug Log References

None — no blocking failures. Two findings surfaced while wiring the tests and were resolved in place:

1. `AdminSettingsRequiredFieldsTest`'s "every error code maps to a field the registry knows about" asserted that `error_field_map()` only ever names fields in `required_fields()`. That invariant held only because every validated field happened to also be required. `context_id` is the first field that is validated *and* optional, so the assertion was widened to check the mapped field against the tab registry — the actual list of field IDs the settings screen renders. The property being protected (no error code pointing at a field that does not exist) is unchanged; the test was not weakened.
2. The unit suite's stub bootstrap has no `wp_cache_delete()` / Action Scheduler API, so driving `on_settings_updated()` end to end needed guarded stubs in the new test file. They are `function_exists`-guarded, so a sibling test file that already declares them still wins and the suite stays order-independent.

### Completion Notes List

- **Task 1** — `context_id` added to `get_options()` defaults (`''`). `sanitize_settings()` accepts empty, otherwise requires a positive integer; on failure it calls `add_settings_error()` and stores the raw value anyway, per story 5.2's convention. `context_id` was deliberately **not** added to `required_fields()`. `get_context_ids(): ?array` sits next to `get_auth_token()` and reads one private `resolve_context_ids()` helper.
- **Task 2** — the field renders in the **API Connection** fieldgroup after Retries, inside the same `.skw-field-row`: `type="number"`, `min="1"`, `step="1"`, `placeholder="1"`, standard `.skw-field` / `.skw-label` / `.skw-input` markup, no required marker, help text that says an empty field uses the Skwirrel default context. No tab is referenced in the markup.
  - One deliberate addition beyond the letter of the task: `context_id` was added to the Connection tab's `fields` array in `get_settings_tabs()`. That array is 5.1's error-routing registry, not a tab reference in the field itself — without the entry a rejected Context ID would still be reported in the summary at the top of the page, but the Connection tab would not carry the error badge and would not open first. Flagged here because AC-1 forbids hardcoding a tab; this is registering the field with the mechanism 5.1 built, and the markup remains tab-agnostic.
- **Task 3** — all five default-to-`[ 1 ]` sites now read `Skwirrel_WC_Sync_Admin_Settings::get_context_ids() ?? [ 1 ]` (`service.php:271`, `:1811`, `:2090`, `:2228`, `category-sync.php:183`). A grep across `includes/` confirms no bare context literal remains, and a unit test pins that.
- **Task 4** — the two `getGroupedProducts` sites add `include_contexts` **only** when a Context ID resolves (`product-upserter.php:948`, `service.php:1973`). The conditional is asserted by test: with no Context ID configured the key is absent from the params entirely.
- **Task 5** — `on_settings_updated()` delegates to a new private `maybe_force_full_sync_on_context_change()`, which compares `resolve_context_ids( $old )` against `resolve_context_ids( $new )` — the resolved context, not the raw string — and only on a genuine difference sets `skwirrel_wc_sync_force_full_sync`, logs via `Skwirrel_WC_Sync_Logger` in the house style, and adds a plain-language `info` settings error. `invalid → different invalid`, `padding-only` and plain re-saves all resolve equal and set nothing. The `info` severity keeps it out of `has_settings_error()`, so the notice never makes the form look like it failed.
- **Task 6** — `tests/Unit/ContextIdTest.php`, 51 tests after review. The `getCategories`, `getGroupedProducts` and membership-sweep call sites are covered **behaviourally** through a recording JSON-RPC client, so the assertions are about the request actually built, not about source text. The four `Sync_Service` content sites sit inside a paginated run the stub bootstrap cannot drive, so they are pinned at the source level and through the integration suite.
- **Task 7** — three gates green from the repo root: `vendor/bin/pest` (489 passed), `vendor/bin/phpstan analyse --memory-limit=2G` (no errors), `vendor/bin/phpcs` (no violations). Version bumped 3.15.0 → **3.16.0** in the plugin header, the constant, `readme.txt` `Stable tag:`, `package.json` and both self-version entries in `package-lock.json`. Changelog entries added to `CHANGELOG.md` and `readme.txt`. The POT was regenerated with wp-cli (via the `wordpress:cli` Docker image — wp-cli is not installed locally), all seven locales `msgmerge`d, the four new strings translated for nl_NL, nl_BE, de_DE, fr_FR and fr_BE, and every `.mo` recompiled with `msgfmt --check`. en_GB/en_US keep the existing convention of leaving `msgstr` empty where the source text already reads correctly.
- **Task 8** — review wired membership sweeps and status discovery, made integer parsing overflow-safe,
  corrected the field-row placement, removed debug output, and expanded the unit/integration coverage.
  The local gates now report 495 unit tests / 984 assertions, no PHPStan errors and no PHPCS violations.
- **Not done, deliberately:** no tag and no push. The story is `done`; releasing 3.16.0 is Jos's call.

### Open items for Jos

The three questions raised when the story was written still stand and none of them blocked the build:

1. **`getCategories` follows the Context ID** — built as the story assumed. Confirm this is what you want; it is the one place the implementation goes beyond the epic's literal AC.
2. **`getGroupedProducts` has never been sent `include_contexts`.** Whether the API accepts it there is still unproven. AC-3's conditional means no existing install is affected either way — but the first shop that *does* set a Context ID will be the first to send that parameter to that method. Worth one check against the API docs before 3.16.0 ships.
3. **Single context only.** `include_contexts` is an array; this ships a single-value field per FR-20.

### File List

- `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` (modified — version bump)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php` (modified)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php` (modified)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php` (modified)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php` (modified)
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php` (modified)
- `plugin/skwirrel-pim-sync/readme.txt` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot` (regenerated)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-{nl_NL,nl_BE,de_DE,fr_FR,fr_BE,en_US,en_GB}.po` (modified)
- `plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync-{nl_NL,nl_BE,de_DE,fr_FR,fr_BE,en_US,en_GB}.mo` (recompiled)
- `tests/Unit/ContextIdTest.php` (new)
- `tests/Unit/AdminSettingsRequiredFieldsTest.php` (modified — widened one assertion, see Debug Log)
- `tests/Integration/ContextIdIntegrationTest.php` (new — rendered field, real save hook and wire requests)
- `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` (modified — raises the new mapped error)
- `tests/Integration/SettingsTabsIntegrationTest.php` (modified — includes the post-baseline field)
- `_bmad-output/implementation-artifacts/tests/test-summary-5-3.md` (new — integration-test automation summary)
- `CHANGELOG.md` (modified)
- `package.json` (modified — version bump)
- `package-lock.json` (modified — version bump)
- `_bmad-output/implementation-artifacts/5-3-context-id.md` (modified — this file)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified — status → done)

### Change Log

| Date | Change |
|---|---|
| 2026-08-26 | Story 5.3 implemented. Optional Context ID setting; one `get_context_ids()` helper feeding the initial seven mapped JSON-RPC call sites; force-full-sync on an effective-context change; validation that reports and stays inert. Version 3.15.0 → 3.16.0, translations regenerated, three gates green. Status → review. |
| 2026-08-26 | Senior review auto-fixes: wired the two omitted context-sensitive calls (membership sweep and status discovery), rejected overflowing integer input, corrected field-row markup, removed test debug output, expanded tests and documentation. Status → done. |

## Senior Developer Review (AI)

**Reviewer:** Codex

**Date:** 2026-08-26

**Outcome:** Approve — all confirmed findings were fixed automatically; no critical issues remain.

### Review context

- Story `5.3` was in `review` when this workflow started; Epic 5 and the project context/standards were
  loaded. No dedicated Epic 5 technical-specification file exists, so the story Dev Notes and Epic 5
  section are the implementation specification.
- Stack reviewed: PHP 8.3+, WordPress Settings API / WordPress 6.9+ compatibility, WooCommerce 8+, Pest,
  PHPStan level 6 and WordPress Coding Standards.
- Git changes were cross-checked against the story File List. Application/test discrepancies are fixed;
  `_bmad-output/story-automator/` remains excluded from source review as required by the workflow.
- Security and performance review found no remaining issue: the existing capability/nonce boundaries and
  separate token storage are unchanged, input resolution is strict and overflow-safe, and the added calls
  only enrich existing request params without adding network round trips.

### Findings and resolutions

1. **CRITICAL — completed Task 2 was not fully implemented.** The Context ID field was rendered after
   the Timeout/Retries row instead of inside the required `.skw-field-row`. Moved it into that row and
   added an integration assertion for the wrapper.
2. **HIGH — AC-2 was partial for the membership sweep.** The selection-membership
   `getProductsByFilter` request still sent `options: []` with a configured context. Because that set
   drives grouped filtering and removals, it could mix contexts or remove the wrong products. The context
   is now added under `options` only when configured; the legacy empty shape remains unchanged otherwise.
3. **HIGH — AC-2 was partial for status discovery.** The settings screen's status-refresh `getProducts`
   request ignored the configured context and could present mappings from context 1. It now conditionally
   sends the configured context while preserving the old request when unset or invalid.
4. **MEDIUM — oversized numeric input could select a different context.** PHP casts an out-of-range digit
   string to `PHP_INT_MAX`; the resolver now checks the decimal string before casting and treats overflow
   as invalid and inert.
5. **MEDIUM — test output pollution.** `ContextIdIntegrationTest.php` ended with a stray `STDERR` debug
   marker. Removed it.
6. **MEDIUM — File List and call-site documentation were incomplete.** Added the new integration tests,
   test summary and the two missed call sites; corrected the contradictory five/six/seven/eight counts.

### Acceptance-criteria validation

| AC | Result | Evidence |
|---|---|---|
| AC-1 | Implemented | Optional number field with `min=1`, `step=1`, placeholder, help text, Connection registration and `.skw-field-row` placement. |
| AC-2 | Implemented | Nine application call sites resolve through `get_context_ids()`; method-specific nesting is preserved. Test Connection remains explicitly outside this story per Epic 5.4. |
| AC-3 | Implemented | Five legacy sites retain `[1]`; four formerly-omitting sites still omit the parameter when unset/invalid. |
| AC-4 | Implemented | The registered option-update hook compares resolved old/new contexts and sets the full-sync flag plus notice only on an effective change. |
| AC-5 | Implemented | Invalid input is stored for correction, reported inline, and resolves to `null`; overflow is also inert. |
| AC-6 | Implemented | Unit and integration coverage maps to every AC; all user-facing strings are translated/catalogued. |

### Verification

- `vendor/bin/pest`: **495 passed, 984 assertions**.
- `vendor/bin/phpstan analyse --memory-limit=2G --debug`: **No errors**. `--debug` was required because
  the sandbox blocks PHPStan's local parallel TCP listener.
- `vendor/bin/phpcs`: **No violations**.
- `msgfmt --check` over all seven `.po` files: **passed**; all `.mo` files are valid GNU catalogues.
- `php -l` for both Context ID test files: **passed**.
- `npm run test:integration -- --filter ContextId`: attempted, but the sandbox denied access to the
  OrbStack Docker socket. The prior implementation run recorded 159 passing integration tests; the new
  review additions are syntax-checked and backed by focused unit coverage, but could not be re-run here.

### Documentation search

No public Skwirrel documentation for `include_contexts` on `getGroupedProducts` was found, so that API
compatibility question remains an external release check. WordPress's official references confirm that
[`update_option_{$option}`](https://developer.wordpress.org/reference/hooks/update_option_option/)
receives old and new values after a successful change and that
[`add_settings_error()`](https://developer.wordpress.org/reference/functions/add_settings_error/) is the
supported Settings API feedback mechanism.

Checklist result: all review checklist items are complete; the Docker integration re-run limitation is
recorded above rather than silently treated as a passing run.
