---
status: review
baseline_revision: 0f7c3c4964b7789f74d7c14f307c1c083a9a22a4
depends_on:
  # Story written, code NOT built as of baseline. Shares the Field mapping group and the
  # ID-or-code predicate — whichever runs first creates them. See "Dependency reality check".
  - 6-1-stock-from-custom-class-simple
pinned_by:
  - 6-4-non-destructive-mapping-test-suite   # greps for this story's setting key names
context:
  - _bmad-output/project-context.md
  - _bmad-output/planning-artifacts/epics.md#story-63
  - _bmad-output/implementation-artifacts/6-1-stock-from-custom-class-simple.md
  - _bmad-output/implementation-artifacts/6-4-non-destructive-mapping-test-suite.md
  - .claude/rules/product-mapping.md
  - .claude/rules/sync-service.md
  - .claude/rules/admin-settings.md
  - CLAUDE.md
---

# Story 6.3: Title, short and long description from custom classes

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a store owner,
I want product copy to come from the custom-class fields my PIM actually authors,
so that the shop shows the text my team maintains rather than an ERP description.

## Acceptance Criteria

**1 — Three optional mapping fields exist in the Field mapping group**

**Given** the Field mapping settings group
**When** the settings screen renders
**Then** it contains three further optional fields — **Product title**, **Short description**, **Long description** — each accepting a product-level custom-feature ID or code, each independently empty by default
**And** when Story 5.1's tab registration is present the group registers as its own tab; when it is not, it renders as an ordinary field group (`.skw-fieldgroup`) on the existing settings view.

**2 — A resolved non-empty value wins**

**Given** a mapped feature that resolves to a non-empty value on the product's **product-level** custom classes
**When** the product syncs
**Then** that value is written to the corresponding WooCommerce field (`post_title` / `post_excerpt` / `post_content`), overriding the existing source chain.

**3 — A missing value never clears anything (NFR-9)**

**Given** a mapped feature that is absent, `not_applicable`, or resolves to an empty value
**When** the product syncs
**Then** the existing resolution chain applies unchanged — title falls back to `product_erp_description` → `_product_translations[].product_model` → `.product_description`; short description to `_product_translations[].product_description`; long description to `product_long_description` → `product_marketing_text` → `product_web_text`
**And** the mapping never makes the outcome *worse* than it is today: a configured-but-unresolved mapping falls through to the chain rather than short-circuiting it to `''`.

*Scope note (from Story 6.4's analysis): all three chains already bottom out at `''`, and the upsert paths write that empty string unconditionally, so a payload carrying neither an ERP description nor translations already yields a blank title today. That is pre-existing behaviour, not something this story introduces or must fix. If you find the blanking is newly reachable **through the mapping path**, that is a defect to report.*

**4 — Unconfigured means byte-for-byte unchanged**

**Given** none of the three feature IDs is configured
**When** any product syncs
**Then** no mapping lookup influences the result and behaviour is exactly what it was before this story — including for variations and for the virtual-product content path.

**5 — Long-description markup is sanitised, not stripped**

**Given** a long-description value containing markup
**When** it is written
**Then** it passes through `wp_kses_post()` — formatting survives, unsafe markup does not.

**6 — Language selection follows the existing setting**

**Given** a value carrying custom-class translations (`I` type `translated_texts`, or `_custom_value_translations`)
**When** it is resolved
**Then** language selection follows the existing `image_language` setting through the extractor's existing exact → two-letter-prefix → first-entry chain — not a second parallel implementation, and not a new `content_language` twin setting.

**7 — Slugs are not rewritten**

**Given** a product whose title changes because of this mapping
**When** it syncs
**Then** its slug is **not** rewritten — `update_slug_on_resync` behaviour is untouched, so existing product URLs and their SEO value survive
**And** the mapped title is never fed into `Skwirrel_WC_Sync_Slug_Resolver::resolve()`.

**8 — The change gate sees the change**

**Given** a mapped content value changes upstream while nothing else about the product does
**When** a delta sync runs
**Then** the product is **not** skipped by the unchanged gate
**And given** a mapping setting is changed while upstream data is unchanged
**Then** the next run reprocesses every product once.
*(Both halves are believed to hold already — verify and pin, do not rebuild. See Dev Notes.)*

**9 — The three mappings are independent**

**Given** only the long description is configured
**When** a product syncs
**Then** title and short description are left entirely to their existing chains.

**10 — Gates pass**

`vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G` (level 6) and `vendor/bin/phpcs` all pass from the repo root.

## Tasks / Subtasks

- [x] **Task 1 — Settings: the Field mapping group + three fields (AC: 1, 4)**
  - [x] In `class-skwirrel-wc-sync-admin-dashboard.php`, add the three fields to the **Field mapping** `.skw-fieldgroup`. Story 6.1 places that group after *Product status handling* (`:1141`) and before *Advanced* (`:1195`) — use the same slot. **If 6.1 landed first, extend its group; never create a second group with the same title.**
  - [x] Three `.skw-field` text inputs: **`title_feature_id`**, **`short_description_feature_id`**, **`long_description_feature_id`**. These exact key names are what Story 6.4 greps for as its prerequisite gate — do not rename them. Placeholder `e.g. 812 or PRODUCT_TITLE`, hint explaining ID-or-code and that empty = use the normal source.
  - [x] In `class-skwirrel-wc-sync-admin-settings.php::sanitize_settings()` (alongside the `custom_class_*` block, ~line 416-429) sanitize all three with `sanitize_text_field( trim( ... ) )`, defaulting to `''`.
  - [x] Add the three keys with `''` defaults to `Skwirrel_WC_Sync_Service::get_options()`'s `$defaults` array (~line 2337).
  - [x] Do **not** add them to the `compute_sync_signature()` `$ignore` denylist — they must bust the change gate (AC 8).

- [x] **Task 2 — Resolver: one value for one feature ID-or-code (AC: 2, 3, 6)**
  - [x] Add `Skwirrel_WC_Sync_Custom_Class_Extractor::resolve_text_feature_value( array $product, string $mapping, string $lang ): string` — the **text sibling** of the `resolve_numeric_feature_value()` Story 6.1 adds. Same matching, same traversal, different value extraction.
  - [x] Keep it **pure**, as 6.1 requires of its twin: no `get_option()`, no WC calls. `$lang` is injected by the caller, not defaulted from the settings option, so `tests/Unit/` can drive it on the stub bootstrap.
  - [x] Factor the ID-or-code matching out of whichever of the two lands second so both share one predicate. Do not ship two traversals that can drift.
  - [x] `$mapping` is matched numerically against `custom_feature_id` / `custom_class_feature_id`, or case-insensitively against `custom_feature_code` — following `get_custom_feature_values_for_ids()` (`:302`) for the ID shape and `filter_custom_classes()` (`:75`) for the code shape. Do not invent a third.
  - [x] Product level only: call `collect_custom_classes( $product )` with `$include_trade_items = false`. Never `_trade_item_custom_classes`.
  - [x] Skip `not_applicable` features. Return `''` when nothing matches — never `null`, so callers stay branch-simple.
  - [x] Normalise a numeric `$ref` as a **strict positive platform integer**: reject `0`, negatives, decimals, and digit strings that overflow `PHP_INT_MAX`. Treat a rejected ref as *unconfigured*, not as a match. (Direct carry-over from Story 2.6's re-review — see Learnings.)
  - [x] **Handle `B` (big text) as well as `T`** — `format_custom_feature_value()` covers `T` but *not* `B`; `B` lives in `big_text_value` and is currently only read by `get_custom_class_text_meta()` (`CC_META_TYPES = [ 'B' ]`). A long description is almost certainly a `B` feature, so a naive reuse of `format_custom_feature_value()` silently resolves to empty and the story ships doing nothing. See Dev Notes.
  - [x] Handle `I` (internationalised text) through the existing `translated_texts` branch so AC 6's language chain applies.
  - [x] For every other type, delegate to `format_custom_feature_value()` so `A`/`M`/`L`/`N`/`R`/`D` behave as they do in the attribute table. Note 6.1's warning that this returns *display* strings (unit appended for `N`, hardcoded `Ja`/`Nee` for `L`) — correct for prose, wrong for a quantity, which is exactly why the two resolvers differ.

- [x] **Task 3 — Apply the mapping in the mapper, not at the call sites (AC: 2, 3, 4, 5, 9)**
  - [x] Add `Skwirrel_WC_Sync_Product_Mapper::set_content_mapping( string $title_ref, string $short_ref, string $long_ref ): void`, mirroring the existing `set_status_handling()` injection precedent (keeps the mapper deterministic under unit test).
  - [x] In `get_name()`: if a title ref is configured, resolve it; return it when non-empty, otherwise fall through to the existing chain **unchanged**.
  - [x] Same shape in `get_short_description()` and `get_long_description()`, each guarded by its own ref (AC 9).
  - [x] Run the long-description value through `wp_kses_post()` before returning it (AC 5). Leave title and short description on their current handling.
  - [x] Wire the injection in `Skwirrel_WC_Sync_Service` next to `apply_status_handling()` (~line 55), from the same frozen per-run options copy, so a mid-run settings save cannot split one run across two mappings.

- [x] **Task 4 — Verify the paths you did not touch still hold (AC: 4, 7, 8)**
  - [x] Confirm `set_name()`/`set_short_description()`/`set_description()` at upserter lines 331/342-343, 1770/1780-1781 and 2361-2377 all now inherit the mapping through the mapper with no call-site edits.
  - [x] Confirm the virtual-product content path (2361-2377) keeps its `'' !== $value` guards — with the mapping applied inside the mapper, an unmapped/empty feature still falls back and those guards still protect the parent.
  - [x] Confirm no mapped title reaches `slug_resolver->resolve()` — the resolver reads `$product` raw (`resolve_raw_value()`), not the mapper, so this should already hold. Pin it with a test rather than assuming (AC 7).

- [x] **Task 5 — Tests (AC: 2, 3, 4, 5, 6, 7, 8, 9, 10)**
  - [x] Extend `tests/Unit/ProductMapperNameTest.php`: mapped title wins; mapped-but-empty falls back to `product_erp_description`; unmapped is unchanged.
  - [x] New `tests/Unit/ContentMappingTest.php` for short + long description: value wins, empty falls back, `B`-type resolves, markup survives `wp_kses_post()` while `<script>` does not, the three refs work independently.
  - [x] Extend `tests/Unit/CustomClassExtractorTest.php` for `resolve_text_feature_value()`: match by ID, match by code (case-insensitive), `not_applicable` skipped, trade-item classes never consulted, unknown ref returns `''`.
  - [x] Same file: a malformed mapping (`0`, `-5`, `1.5`, `99999999999999999999`) resolves to `''` and does not match any feature.
  - [x] Same file: a `B`-type feature resolves from `big_text_value` — this is the assertion that catches the single most likely way to ship this story doing nothing.
  - [x] Extend `tests/Unit/ContentHashTest.php`: a changed `_custom_classes` value changes the payload signature; a changed mapping setting changes `compute_sync_signature()`.
  - [x] Add a `wp_kses_post()` stub to `tests/bootstrap.php` — it is **not** currently stubbed. Make the stub strip `<script>` so the sanitisation assertion is real, not a pass-through.
  - [x] Run all three gates from the repo root.

- [x] **Task 6 — Release hygiene (AC: 10)**
  - [x] New translatable strings → regenerate `languages/skwirrel-pim-sync.pot` and update all 7 locales' `.po`/`.mo`.
  - [x] Version bump (header + `SKWIRREL_WC_SYNC_VERSION`), `CHANGELOG.md` + `readme.txt` entries. Prefer the `/release` skill over bumping by hand.

## Dev Notes

### Dependency reality check — read this first

Epic 6's stated implementation order is **6.1 → 6.2 → 6.3 → 6.5 → 6.4**, and 6.1 is what "establishes the resolver and the settings group that 6.2 and 6.3 consume."

Story 6.1 is **written** (`6-1-stock-from-custom-class-simple.md`, `ready-for-dev`) but **not implemented**. Verified against baseline `0f7c3c4`: no `stock_feature_id`/`field_mapping` key exists anywhere in `plugin/skwirrel-pim-sync/includes/`.

So whichever of 6.1 and 6.3 runs first **creates** the Field mapping group and the shared matching predicate; the second **extends** them. Both story files are written to be idempotent in that respect — but the dev must actually check rather than assume, because the two stories were drafted in parallel:

```
grep -rn "stock_feature_id\|Field mapping" plugin/skwirrel-pim-sync/includes/
```

Empty → you are first: build the group and the predicate. Non-empty → you are second: extend, and do not duplicate.

The two resolvers are deliberately **siblings, not one shared function**: 6.1's `resolve_numeric_feature_value()` reads raw `numeric_value` (a unit-suffixed display string is wrong for a stock quantity), while this story's `resolve_text_feature_value()` wants the formatted prose. Share the traversal and the ID-or-code predicate; keep the value extraction separate.

Running 6.3 ahead of 6.1 inverts the epic's stated order. It is not blocking — 6.3 is self-contained under the plan above — but flag it to the PM if stock is the more urgent client ask.

### Current state of the files you will touch

**`class-skwirrel-wc-sync-product-mapper.php`** — the three getters are short and pure-ish (lines 162-196):

- `get_name()`: `product_erp_description` → `pick_translation()['product_model']` → `['product_description']` → `''`
- `get_short_description()`: `pick_translation()['product_description']` → `''`
- `get_long_description()`: `product_long_description` → `product_marketing_text` → `product_web_text` → `''`

The constructor (line 112) reads `image_language` from `skwirrel_wc_sync_settings` directly and hands it to the Etim/Custom-class/Attachment extractors. `set_status_handling()` (line ~198) is the deliberate counter-pattern: run-scoped state is *injected*, with a comment saying why — "keeps that method a pure function of its argument + this injected state, so it stays deterministic in unit tests." **Follow the injection pattern for the content mapping**, not the constructor read.

**`class-skwirrel-wc-sync-custom-class-extractor.php`** — the shape you need mostly exists:

- `get_custom_feature_values_for_ids()` (line 302) is the closest analogue: IDs only, returns `[id => ['label','value','slug']]`, product-level only, skips `not_applicable`, falls back to the `image_language` option when `$lang` is `''`. Model your method on it.
- `format_custom_feature_value()` (line 465) formats `A`, `M`, `L`, `N`, `R`, `D`, `T`, `I`. **It does not handle `B`.**
- `get_custom_class_text_meta()` (line 251) is the only reader of `B`, via `CC_META_TYPES = [ 'B' ]` and `$feat['big_text_value']`.
- `pick_custom_translation()` (line 561) is the exact → two-letter-prefix → first-entry chain AC 6 requires. Reuse it.
- `parse_custom_class_filter()` (line 98) already discriminates numeric IDs from string codes (lowercasing codes). Reuse that convention so an ID-or-code field behaves like the existing ones.

Note that `format_custom_feature_value()` returns *display-formatted* strings — `N` gets its unit appended, `L` returns the hardcoded Dutch `'Ja'`/`'Nee'`. For a title or description that is almost certainly fine (nobody maps a boolean to a product title), but do not be surprised by it, and do not "fix" the hardcoded Ja/Nee here — that is a separate concern with its own blast radius.

**`class-skwirrel-wc-sync-product-upserter.php`** — five write sites consume the three getters:

| Line | Path |
|------|------|
| 331, 342-343 | simple/parent upsert |
| 1770, 1780-1781 | the second upsert path (queue/per-product-atomic) |
| 2361-2377 | virtual-product content applied onto a variable parent |
| 3217 | `get_name()` used only as a log label |

**This is the argument for Task 3's design.** Applying the mapping inside the mapper's three getters means all five sites inherit it correctly with zero call-site edits. Applying it at the call sites means editing four places and getting the virtual-product path subtly wrong. Do it in the mapper.

The virtual-content path already guards each write with `'' !== $value` and only saves when something changed — that guard composes correctly with a mapper that falls back, and would compose *incorrectly* with a mapper that returns `''` on an unmapped feature. Another reason Task 2 returns `''` and the getters fall through rather than short-circuit.

**Slug (AC 7)** — both upsert paths already gate slug writes behind `if ( $is_new || $this->slug_resolver->should_update_on_resync() )`, and `Skwirrel_WC_Sync_Slug_Resolver::resolve()` reads the raw `$product` array via `resolve_raw_value()` — it never calls the mapper. So a mapped title cannot leak into a slug today. **Verify and pin, do not change.**

### AC 8 is mostly already true — verify, don't build

Both halves of the change-gate requirement appear to be satisfied by existing machinery. Confirm each with a test rather than writing new gate code:

- **Upstream value change.** `Skwirrel_WC_Sync_Product_Upserter::payload_signature()` (line ~180) hashes a key-sorted JSON of the *entire raw payload* with only `HASH_EXCLUDE_KEYS = [ 'product_updated_on' ]` stripped (plus whatever the `skwirrel_wc_sync_content_hash_exclude` filter removes). `_custom_classes` is part of that payload, so a changed feature value already changes the hash.
- **Settings change.** `Skwirrel_WC_Sync_Service::compute_sync_signature()` (line 2369) is a **denylist**, not an allowlist — every setting is hashed except a fixed list of connection/perf/logging keys. The comment says so explicitly: "a newly-added output setting is covered automatically instead of slipping past the gate." Adding three new settings keys is therefore sufficient, *provided you do not add them to `$ignore`*.

The one thing to actually check: that `_custom_classes` is present in the payload at hash time. It is fetched only when `sync_custom_classes` (or the trade-item twin) is enabled — see `class-skwirrel-wc-sync-service.php:274-307`, `:1814-1822`, `:2090-2098`. **A shop that maps a content field but has not enabled "Sync custom classes" will get no data at all.** Story 6.1's AC 6 already owns this warning and names all three request builders that must agree (`:274-310`, `:1814-1824`, `:2090-2098`). If 6.1 has landed, this story inherits it — just extend the warning to cover the content mappings. If 6.1 has not landed, build it here. Either way: surface it in the field hint, and do **not** silently auto-enable the include flag — that changes payload size and cost for every request.

### Learnings carried from Story 2.6 (the most recent shipped story)

2.6's two review rounds produced findings that map directly onto this story's surface. Apply them up front rather than earning them again:

- **Fail closed on malformed input.** 2.6 accepted partial data as authoritative and had to be reworked. Here: a ref that is malformed, zero, negative, decimal, or integer-overflowing must be treated as *unconfigured* — never as a match, and never as "resolve to something".
- **Distinguish absent from empty.** 2.6 accepted a missing `products` key as an authoritative empty page. Here: a feature that is absent, one that is `not_applicable`, and one whose value is `''` must all land on the same safe branch (fall back to the existing chain) — but write the test for each of the three separately, because they take different code paths to get there.
- **A test must actually exercise the branch it claims.** Several 2.6 tests passed while proving nothing (a "grouped filtering disabled" test that returned no groups). For AC 4 in particular: an "unconfigured changes nothing" test must run against a product that *does* carry custom-class data, or it proves only that empty input produces empty output.
- **Translations are part of done, not a follow-up.** 2.6 needed a separate pass to compile seven catalogues. Task 6 is in scope for this story.

### Do not

- Do not add a `content_language` / `description_language` setting. `image_language` is the language axis (this is the same rule Story 6.5 states for documents). A separate axis is a new FR, not a silent addition here.
- Do not read `_trade_item_custom_classes`. Product level only — the epic is explicit, twice.
- Do not touch `update_slug_on_resync` or the slug resolver.
- Do not zero, blank, or "reset" a WooCommerce field because the PIM omitted a value. That is the whole point of NFR-9, and it is the same guarantee the `prices_managed_outside_skwirrel` path already implements.
- Do not weaken or regenerate `phpstan-baseline.neon` to absorb new findings.
- Do not apply `wp_kses_post()` to the title or short description. The AC scopes it to the long description; over-sanitising a title would strip legitimate `&` entities and is a behaviour change nobody asked for.

### Project Structure Notes

- Two files change per new setting, always: markup in `class-skwirrel-wc-sync-admin-dashboard.php`, sanitisation in `class-skwirrel-wc-sync-admin-settings.php`. Defaults live in a third, `Skwirrel_WC_Sync_Service::get_options()`.
- No new class is introduced, so no `require_once` in `skwirrel-pim-sync.php` and no hook wiring in `Skwirrel_WC_Sync_Plugin` are needed. (If you do add a class, both are mandatory — there is no autoloader.)
- Settings markup follows the existing `.skw-fieldgroup` / `.skw-fieldgroup-title` / `.skw-field-row` / `.skw-field` / `.skw-label` / `.skw-input` / `.skw-field-hint` vocabulary. No new CSS should be needed.
- All new strings are translatable with the literal text domain `'skwirrel-pim-sync'`; all output escaped (`esc_attr`, `esc_html`, `esc_attr_e`, `esc_html_e`).

### Testing

- Pest, not class-based PHPUnit: `test()`, `beforeEach()`, `expect()`, `dataset()`/`with()`. File naming `{Subject}Test.php`.
- Unit tests run on the stub bootstrap (`tests/bootstrap.php`) with no Docker. `get_option()` is stubbed there (line 108); `wp_kses_post()` is **not** — you must add it.
- Existing files to extend rather than duplicate: `ProductMapperNameTest.php`, `CustomClassExtractorTest.php`, `ProductMapperCustomClassTest.php`, `ContentHashTest.php`, `UnchangedGateTest.php`, `SlugResolverTest.php`.
- Story 6.4 will fold the NFR-9 cases into a canary suite later. Write them here anyway — 6.4 must never be a prerequisite for 6.3's coverage.
- All three gates from the **repo root** (`plugin/skwirrel-pim-sync/` has no `composer.json`): `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs`. `vendor/bin/phpcbf` auto-fixes style.

### References

- Story source: `_bmad-output/planning-artifacts/epics.md` — "Story 6.3: Title, short and long description from custom classes (FR-19, NFR-9)"
- Epic ordering + the 6.1-establishes-the-group claim: `epics.md`, "Epic 6" preamble
- Sibling precedent for language resolution and the no-twin-setting rule: `epics.md`, "Story 6.5", implementation notes
- Non-destructive precedent in code: `class-skwirrel-wc-sync-product-upserter.php:1795-1800` (`prices_managed_outside_skwirrel`)
- Injection precedent: `class-skwirrel-wc-sync-product-mapper.php::set_status_handling()` + `class-skwirrel-wc-sync-service.php::apply_status_handling()` (line 55)
- Change gate: `class-skwirrel-wc-sync-product-upserter.php:149-188`, `class-skwirrel-wc-sync-service.php:2369`
- Sibling stories drafted in parallel — read before starting: `6-1-stock-from-custom-class-simple.md` (shared group + predicate), `6-4-non-destructive-mapping-test-suite.md` (pins this story's NFR-9 behaviour and greps for its setting keys)
- Conventions: `_bmad-output/project-context.md`, `CLAUDE.md`, `.claude/rules/product-mapping.md`, `.claude/rules/admin-settings.md`

## Dev Agent Record

### Agent Model Used

claude-opus-5 (in-session, orchestration `orchestration-6-20260826-143018`)

### Debug Log References

- `vendor/bin/pest` — 580 passed (1235 assertions)
- integration suite via wp-env — 191 passed (1651 assertions)
- `vendor/bin/phpstan analyse --memory-limit=2G` — no errors (level 6, baseline unchanged)
- `vendor/bin/phpcs` — 34/34 clean

### Completion Notes List

- **Dependency reality check: 6.1 and 6.2 had already landed this session, so this story was second.** The Field mapping group and the ID-or-code matching already existed; both were **extended, not duplicated**. No second group with the same title was created.
- **One traversal, two sibling resolvers.** The ID-or-code matching was factored out of 6.1's `resolve_numeric_feature_value()` into a private `matching_features()` generator that both resolvers now share, so their matching cannot drift. Value extraction stays separate by design: stock needs the raw `numeric_value`, prose wants the formatted display value. 6.1's and 6.2's 21 existing tests were re-run against the refactor before anything new was added, confirming it is behaviour-preserving.
- **`B` (big text) is handled explicitly — this is the story's main trap.** `format_custom_feature_value()` covers `T` but not `B`, and a long description is very likely a `B` feature, so a naive delegation would have shipped a mapping that silently resolved to nothing. `resolve_text_feature_value()` reads `big_text_value` for `B` first, then delegates everything else (`A`/`M`/`L`/`N`/`R`/`D`/`T`/`I`) to the existing formatter, which brings AC 6's exact → prefix → first language chain along for free. A test asserts the `B` path directly.
- **Malformed refs fail closed (2.6's learning, applied up front).** `normalize_feature_ref()` accepts only strict positive platform integers: `0`, negatives, decimals and digit strings past `PHP_INT_MAX` are treated as *unconfigured*, never as a match. A test pins that a malformed ref cannot match a feature whose own ID is `0`. This applies to both resolvers, so 6.1's stock mapping inherited the hardening.
- **The mapping is applied in the mapper, so all five write sites inherit it with no call-site edits.** `set_content_mapping()` follows the existing `set_status_handling()` injection precedent and is wired from the same frozen per-run options copy in `apply_status_handling()`, so a settings save mid-run cannot split one run across two mappings. The virtual-product content path's `'' !== $value` guards compose correctly because the getters fall through rather than short-circuit.
- **Absent, `not_applicable` and empty are tested separately** — they reach the safe branch by three different code paths, and 2.6's review showed that collapsing them into one assertion proves less than it appears to.
- **AC 4's test deliberately runs against a product that carries resolvable custom-class data**, so it proves the mapping is off rather than merely that empty input yields empty output — the "a test must exercise the branch it claims" learning.
- **AC 7 and AC 8 were verified, not built.** `Skwirrel_WC_Sync_Slug_Resolver` contains zero references to the mapper (`grep -c mapper` → 0) and reads the raw payload via `resolve_raw_value()`, so a mapped title cannot reach a slug; a test pins it by configuring a mapping, asserting the mapped title wins, and asserting the slug still comes from the raw SKU. For AC 8, `_custom_classes` is part of the hashed payload and the three new keys are absent from `compute_sync_signature()`'s `$ignore` denylist — both pinned in `ContentHashTest.php`.
- **`wp_kses_post()` was not stubbed and now is.** The stub strips `script`/`style` elements with their content and inline event handlers, so the sanitisation assertion is real rather than a pass-through. `wp_kses_post()` is applied to the long description only; a test pins that a title keeps `&` and `<3` untouched, since over-sanitising a title would strip legitimate entities.
- **The FR-18 include-flag machinery was extended to cover the content mappings.** `Skwirrel_WC_Sync_Service::has_field_mapping()` now checks all four mapping keys, so configuring only a content mapping still requests `include_custom_classes`, and the missing-collection-ID warning names all four. The field group carries a hint pointing at the custom class collection ID for the same reason. The include flag is not auto-enabled silently.
- **Task 6 (release hygiene) deliberately not performed.** Per the user's explicit direction this work stays on **3.14.0**: no version bump, no CHANGELOG/readme version section, and no `.pot`/`.po`/`.mo` regeneration. The new strings all use the literal `'skwirrel-pim-sync'` domain and will be collected at release time by `/release`.
- **One pre-existing test pin updated, not weakened.** Story 5.1's `SettingsTabsIntegrationTest` enumerates every settings input name; the three new fields were added to that list. The assertion still compares an exact set.
- **Scope note from AC 3 confirmed, not "fixed".** All three chains still bottom out at `''` for a payload with neither an ERP description nor translations. That is pre-existing behaviour; the mapping path does not make it newly reachable, because an unresolved mapping falls through to the same chain rather than short-circuiting.

### File List

- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-custom-class-extractor.php` (modified) — `resolve_text_feature_value()`, shared `matching_features()` generator, `normalize_feature_ref()`
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-mapper.php` (modified) — `set_content_mapping()`, `mapped_content()`, three getters extended
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php` (modified) — content-mapping injection, four-key `has_field_mapping()`, defaults
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-settings.php` (modified) — sanitization of all four mapping keys
- `plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-admin-dashboard.php` (modified) — three fields in the Field mapping group, tab field registration
- `tests/bootstrap.php` (modified) — `wp_kses_post()` stub
- `tests/Unit/ContentMappingTest.php` (new) — 10 tests
- `tests/Unit/CustomClassExtractorTest.php` (modified) — 7 tests for the text resolver and malformed refs
- `tests/Unit/ProductMapperNameTest.php` (modified) — 3 tests for the title mapping
- `tests/Unit/ContentHashTest.php` (modified) — 2 tests pinning AC 8
- `tests/Unit/SlugResolverTest.php` (modified) — 1 test pinning AC 7
- `tests/Integration/SettingsTabsIntegrationTest.php` (modified) — input-name pin extended

## Change Log

| Date | Version | Description |
|------|---------|-------------|
| 2026-08-25 | 0.1 | Story drafted from epics.md against baseline 0f7c3c4 |
| 2026-08-26 | 1.0 | Implemented: three content field mappings resolved through a shared traversal, applied in the mapper so all five write sites inherit them. No version bump (stays on 3.14.0). |
