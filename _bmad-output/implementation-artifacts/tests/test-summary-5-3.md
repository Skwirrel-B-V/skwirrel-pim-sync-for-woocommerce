# Test Automation Summary — Story 5.3 (Context ID)

**Workflow:** `bmad-qa-generate-e2e-tests` · **Date:** 2026-08-26 · **Story:** `5-3-context-id.md`

## Framework detected

No new framework introduced — the project already has two Pest suites and no browser harness, and
`project-context.md` records the decision not to pretend to cover the browser layer.

| Suite | Command | Bootstrap |
|---|---|---|
| Unit | `vendor/bin/pest` | `tests/bootstrap.php` (WP/WC stubs, no Docker) |
| Integration | `npm run test:integration` | real WP 7.0 + WooCommerce 10.8 via wp-env |

"E2E" here means: drive the real code path against a real WordPress + WooCommerce and assert the
observable outcome — the rendered admin screen, the stored option, or the JSON-RPC request body that
left the plugin.

## What already existed

`tests/Unit/ContextIdTest.php` (45 tests) covers the resolve rule, the sanitiser, the force-full-sync
comparison and the two call sites the stub bootstrap can reach. It is good, and none of it was
weakened. It has three blind spots by construction, and those are what this pass closed.

## Gaps found and closed

### New file — `tests/Integration/ContextIdIntegrationTest.php` (19 tests)

| # | Gap | Why it mattered | AC |
|---|---|---|---|
| 1 | The four `Sync_Service` call sites were pinned by a **source-level regex**, which the unit test itself flags as a limitation | A regex proves the text exists, not that the helper is still called. `run_sync()` now really runs against a stubbed `pre_http_request` endpoint, and the assertion is on the request body: `options.include_contexts` is `[N]` when configured and `[1]` when not | AC-2, AC-3 |
| 2 | `getCategories` on a real run was never observed | The story's own headline risk is a mixed catalogue — products from context N filed under context 1's tree. Now asserted through a real category-syncing run | AC-2 |
| 3 | **AC-1 was entirely untested** — nothing asserted the rendered field | AC-1 is a claim about markup. The screen is now rendered for a real administrator and parsed with DOM: `type=number`, `min=1`, `step=1`, `placeholder=1`, the right `name`, no `required`/`aria-required`, no required marker, a label, a hint wired via `aria-describedby`, and the field inside `panel-connection` | AC-1 |
| 4 | The rejected-value round trip was only asserted at the sanitiser | AC-5's promise is what the user *sees*. Now: the typed value comes back in the field, `aria-invalid="true"`, the inline message renders inside the field's own block with `role="alert"`, and story 5.1's Connection tab carries the error badge and opens first | AC-5 |
| 5 | AC-4 was driven by calling `on_settings_updated()` directly | That passes even if the hook is never registered. Now a real `update_option()` fires the real `update_option_` hook, for both the changed and unchanged cases | AC-4 |
| 6 | **Dev Notes: "`handle_test_connection_ajax()` writes settings — verify you don't disturb it"** — nothing did | A test-connection click must never schedule a full catalogue re-sync. The handler is now driven end to end (with `wp_die` routed to an exception) and asserted to leave `context_id` intact and the flag unset | Dev Notes |
| 7 | **Dev Notes: "Do not add `context_id` to the denylist"** — nothing enforced it | A future denylist edit would silently disable the change gate for this setting. `compute_sync_signature()` is now asserted to differ across default/5/6 | Dev Notes |

### Regressions found in the existing suite and fixed

Story 5.3 ran the unit suite, PHPStan and PHPCS — but not the integration suite, which was **red on
`main` before this pass**. Both failures were caused by the new field:

| File | Failure | Fix |
|---|---|---|
| `tests/Integration/SettingsTabsIntegrationTest.php` | The "no field lost" guard compares the rendered input names against the pre-tabs baseline exactly; `context_id` was unexpected | Added `context_id` at the call site as a field registered *after* the baseline was captured, leaving the baseline list itself an honest historical record |
| `tests/Integration/SettingsRequiredFieldsIntegrationTest.php` | "every mapped error code renders at the field it names" walks `error_field_map()`; the new `context_id` code was never raised, so it produced no message | Added an invalid `context_id` to the input that test saves, so every mapped code is genuinely raised |

Neither assertion was weakened — both still assert exactly what they did before, over the wider set.

### One pollution bug fixed in the new file

`admin_init` does not fire in the test bootstrap, so the settings hooks are not registered — the new
tests call `register_settings()` to make the save path real. That registration is process-global and
leaked the sanitise callback into a later test file (`SweepMembershipIntegrationTest` began scrubbing
the deliberately-invalid `collection_ids` it writes). Teardown now calls `unregister_setting()` and
removes the `update_option_` action, so the suite stays order-independent.

## Mutation check

The headline test was verified to bite: reverting `class-skwirrel-wc-sync-service.php:271` to the
hardcoded `[ 1 ]` fails *"a configured Context ID reaches the product fetch of a real sync run"* and
nothing else. Restored immediately.

## Coverage

| Acceptance criterion | Unit | Integration |
|---|---|---|
| AC-1 — field exists on the Connection group | — | ✅ 2 tests |
| AC-2 — the context reaches every API call | ✅ getCategories, getGroupedProducts · regex for the 4 service sites | ✅ getProductsByFilter + getCategories on a real run |
| AC-3 — empty changes nothing | ✅ | ✅ 3 datasets (never set / empty / invalid) |
| AC-4 — a changed effective context forces a full sync | ✅ direct call | ✅ 8 datasets through the real hook |
| AC-5 — invalid is reported and inert | ✅ | ✅ round trip through the rendered screen |
| AC-6 — coverage + translatable strings | ✅ POT + 7 locales | — |
| Dev Notes — test-connection AJAX, change-gate denylist | — | ✅ 2 tests |

Still browser-only, and deliberately not faked: the number spinner's own client-side `min`/`step`
enforcement (the attributes are asserted), and how the field and its message look.

## Gate results

| Gate | Result |
|---|---|
| `vendor/bin/pest` (Unit) | **489 passed** (975 assertions) |
| `npm run test:integration` | **159 passed** (1529 assertions) — was 138 passed / **2 failed** before this pass |
| `vendor/bin/phpstan analyse --memory-limit=2G` | **No errors** |
| `vendor/bin/phpcs` | **No violations** |

## Next steps

- Story 5.3's three open questions for Jos are unchanged by this pass — in particular, whether the
  API accepts `include_contexts` on `getGroupedProducts` is still unproven against the real API. The
  tests pin the *plugin's* behaviour on both branches; they cannot prove the API's.
- No version bump was made: this pass adds tests only and ships no plugin code.

## Senior review addendum — 2026-08-26

The autonomous senior review found two context-sensitive calls that the original story map and this
test pass had both missed:

- the authoritative selection-membership `getProductsByFilter` sweep, whose result drives grouped
  filtering and removals;
- the settings screen's product-status discovery `getProducts` request.

Both now send `include_contexts` only when a valid Context ID is configured, preserving their exact
legacy request shapes otherwise. Unit coverage was added for both membership branches, and the real-run
integration test now checks the sweep request on the wire. Status discovery gained four integration
datasets (configured / unset / empty / invalid), bringing `ContextIdIntegrationTest.php` from 19 to 23
expected passing datasets. The stray `SKWLOAD-END` debug write was also removed.

Current local validation: `vendor/bin/pest` **495 passed (984 assertions)**, PHPStan **no errors**, PHPCS
**no violations**, and both Context ID test files pass `php -l`. The updated Docker integration suite
could not be re-run in the review sandbox because access to the OrbStack Docker socket was denied; the
last pre-review integration run remains **159 passed (1529 assertions)**.
