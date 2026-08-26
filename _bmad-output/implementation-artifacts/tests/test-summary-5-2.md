# Test Automation Summary — Story 5.2 (Required field markers and inline errors)

**Workflow:** `bmad-qa-generate-e2e-tests` · **Date:** 2026-08-26 · **Story:** `5-2-required-field-markers-and-inline-errors.md` · **Baseline:** `3756e99`

> Story 5.1's summary is kept intact at `test-summary.md`; this run writes its own file rather than
> overwriting it.

## Framework detected

No new framework introduced. The project already has two Pest suites and no browser harness:

| Suite | Command | Bootstrap |
|---|---|---|
| Unit | `vendor/bin/pest` | `tests/bootstrap.php` (WP/WC stubs, no Docker) |
| Integration | `npm run test:integration` | real WP 7.0 + WooCommerce via wp-env |

No Playwright/Cypress was added. "E2E" here means: render the real settings screen for a real
administrator against a real WordPress, parse it with `DOMDocument`/`DOMXPath`, and assert what a
browser and a screen reader actually receive. That is the highest fidelity this project supports,
and `project-context.md` records the decision not to write tests that pretend to cover the browser.

## Gaps found and closed

The story shipped with 10 unit + 13 integration tests. Auditing them against the five acceptance
criteria surfaced 15 machine-checkable claims that nothing asserted. All were written and pass.

### Unit — `tests/Unit/AdminSettingsRequiredFieldsTest.php` (+4, now 14)

| Test | AC | Gap it closes |
|---|---|---|
| the rule table covers exactly the conditionally required fields | 2 | The registry and `conditional_required_rules()` were never cross-checked. A conditional field with no rule renders a marker the toggle can never follow; an unconditional one with a rule renders `data-skw-req-when` pointing at a condition that does not govern it |
| each governing key makes its field required on its own | 2 | The three custom-class keys were only tested as a set. This proves the rule is an OR, so a rule that silently became an AND cannot pass |
| the strings this story added are in the POT and in all seven locales | 5 | **AC5's translation half had zero automated coverage.** Checks the marker's `required` string and both new validation messages as msgids in the POT + 7 `.po` files (wrapped msgids are unwrapped before matching) |
| every locale ships a compiled catalogue next to its source | 5 | A regenerated `.po` that was never compiled is a translation nobody sees — only the `.mo` is loaded at runtime. Asserts 7 `.mo` files exist and are no older than their `.po` |

### Integration — `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` (+11, now 24)

| Test | AC | Gap it closes |
|---|---|---|
| no control blocks submit without a marker and a registry entry behind it | 1 | **The core AC1 claim — "brought into the same treatment rather than left inconsistent" — was never asserted.** Sweeps every `required` input/select/textarea on the screen and demands an id, a registry entry saying it is required, `aria-required`, and a visible marker. A new bare `required` now fails the suite |
| every marker on the screen is a named character, not colour alone | 1, NFR-7 | The story sampled one marker. This turns all four on at once, asserts the count is four, and checks each for the `aria-hidden` `*`, a non-empty `screen-reader-text` name, and containment in its own field's `<label>` |
| no field that validation can reject calls itself optional in its label | 2 | **AC2's "`custom_collection_id`'s label no longer reads '(optional)'" was unasserted.** Applied to every registry field, so the contradiction cannot reappear on another one |
| custom_collection_id carries no required state at all while nothing consumes it | 2 | The off state only checked the `required` attribute; `aria-required` and the hidden marker could both have leaked. Asserts the full off state and its mirror-image on state |
| an inline message is added to the field description, not swapped in for the hint | 3, T4 | **A message that silently replaced the hint still passed the old assertion** (which only checked that the error id was among the referenced ids). Asserts the clean-render baseline, both ids present, error announced before hint, and every id resolving |
| every mapped error code renders at the field it names | 3 | Only two of the three mapped codes were exercised through the screen. Loops `error_field_map()`, so a fourth rule added later is covered the day it lands |
| a rejected value is kept in the field so it can be corrected | 3 | Dev Notes fact 2 — validation is deliberately non-blocking and the rejected value round-trips into the input so it can be fixed. Nothing pinned that; a change that started discarding rejected input would have broken the fix loop silently |
| the failing field ids reach the browser through the localized seam | 4, T5 | **The seam has two halves and only the DOM half was tested.** Reads `errorFields` back out of `wp_localize_script`'s data on the real handle |
| the localized seam is empty when nothing failed | 4, T5 | The negative case: a consumer must never open a tab for an error that is not there |
| the marker toggle reads its conditions off the markup and keeps no copy of them | 2, T3 | The toggle script was only asserted *present*. Asserts it rides the existing handle, binds by `data-skw-req-when` on `change`, touches `aria-required` — and that **no governing settings key appears anywhere in the inline JS**, which is the "do not duplicate the condition booleans" rule made enforceable |
| the marker and message styles ship in the dashboard sheet | 1, T6 | `.skw-req`, `.skw-req[hidden]` and `.skw-field-error` were unverified; a hidden marker relies on the CSS rule as well as the attribute |

## Mutation check

The new tests were verified to bite, not merely pass. Two mutations were applied to
`class-skwirrel-wc-sync-admin-dashboard.php` and reverted:

| Mutation | Killed by |
|---|---|
| `aria-required="true"` dropped from `render_field_state_attrs()` | *no control blocks submit without a marker…*, *custom_collection_id carries no required state at all…* (+3 pre-existing) |
| the hint id dropped from `aria-describedby` | *an inline message is added to the field description, not swapped in for the hint* |

## Results

```
vendor/bin/pest                                → 443 passed (832 assertions)     [was 439]
npm run test:integration                       → 140 passed (1464 assertions)    [was 129]
                                                 1 pre-existing deprecation (SyncSafety, unrelated)
vendor/bin/phpstan analyse --memory-limit=2G   → No errors
vendor/bin/phpcs                               → 34/34 clean
```

## Coverage against the acceptance criteria

| AC | Status |
|---|---|
| 1 — required markers, consistently applied, never colour-alone | ✅ fully covered — including the consistency sweep that is the AC's actual claim |
| 2 — conditional requirement matches what save enforces | ⚠️ near-full — the registry↔sanitiser agreement, the rule table, both full states and the no-JS initial state are all asserted; the *live* toggle firing on a click is browser-only, but the script is now pinned to reading its conditions off the markup |
| 3 — validation errors render at the field | ✅ fully covered — all three codes, ARIA wiring with the hint preserved, summary-plus-inline exactly once, unmapped code still surfacing, success notice suppressed, rejected value retained |
| 4 — tab-aware seam | ✅ fully covered — both halves of the seam (DOM attribute and localized `errorFields`), plus the panel-resolution path and the empty case |
| 5 — translations and gates | ✅ now covered — POT + 7 locales + compiled `.mo` freshness are asserted; all three gates pass |

## Deliberately not automated

No browser harness exists in this repo, and none was added for one inline script. These stay manual
and are recorded in the test file's header rather than faked:

- ticking a governing checkbox actually flipping the marker in a live browser (the story proved this
  by running the real inline script against the real rendered HTML in jsdom — see its Verification
  section; the *conditions-live-in-PHP-only* property that makes it trustworthy is now asserted);
- how the marker and the message look, and whether `#dc2626` passes contrast in context;
- native browser validation firing on a `required` control.

## Next steps

- Run both suites in CI (integration needs the wp-env stack).
- The three items above remain the shortlist that would justify a Playwright harness — together with
  story 5.1's, they are the only claims across Chapter 2 that no PHP test can reach.
