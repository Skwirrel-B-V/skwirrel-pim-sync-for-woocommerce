# Script Opportunity Analysis — bmad-agent-wp (Henk)

Scanner: **L6 — ScriptHunter**
Target: `/Users/joskoomen/Documents/Projects/Skwirrel/wordpress/.claude/skills/bmad-agent-wp`
Date: 2026-08-18

---

## Existing Scripts Inventory

| Script | Lines | What it determinises | Tested |
|--------|-------|----------------------|--------|
| `scripts/init-sanctum.py` | 314 | Sanctum scaffolding, template variable substitution, capability discovery from frontmatter, `CAPABILITIES.md` generation | `tests/test-init-sanctum.py` (154) |
| `scripts/quality-gates.py` | 181 | Detects and runs Pest/PHPUnit, PHPStan, PHPCS; returns compact JSON instead of thousands of raw lines | `tests/test-quality-gates.py` (56) |
| `scripts/release-consistency.py` | 227 | Cross-references plugin header `Version:`, PHP version constant, `Stable tag:`, readme changelog entry, `CHANGELOG.md`, `package.json`; reports declared floors | `tests/test-release-consistency.py` (126) |
| `scripts/upstream-versions.py` | 211 | WordPress.org core + plugin API lookup vs declared `Tested up to` / `Requires at least`; `--offline` degradation | `tests/test-upstream-versions.py` (91) |
| `scripts/tests/run-tests.py` | 48 | Test runner | — |

All four follow the standard the agent preaches in `references/capability-authoring.md:31`: PEP 723 block, `argparse` with `--help`, JSON to stdout, exit 0/1/2, stdlib-only (the one network exception in `upstream-versions.py` is explicitly justified in its docstring). This is a genuinely well-built script layer — the findings below are about coverage gaps, not about quality.

---

## Assessment

Henk is already above the median: the three most expensive recurring operations (running gates, checking version consistency, fetching upstream releases) are scripted, tested, and referenced by name from the prompts that need them. The intelligence placement is broadly correct — judgment lives in prompts, mechanics live in Python.

The gap is that **scripting stopped at the front door of each capability**. `ship.md` scripts the version check but leaves the equally deterministic i18n catalog check to the LLM. `api-check.md` and `upstream-watch.md` both ask the LLM to search the codebase for symbols — pure grep work, unscripted. And the entire memory/sanctum layer (`PULSE.md`, `memory-guidance.md`, `first-breath.md`) asks the LLM to do file-age arithmetic, line counting, index-drift detection, and placeholder scanning by eye, on every unattended Pulse run — which is exactly the run where no human is present to notice it drifting.

**Estimated aggregate LLM Tax: ~4,500–10,000 tokens per full capability cycle**, concentrated in four high-severity findings.

---

## Key Findings

### H1 — Translation catalog consistency is fully deterministic and fully unscripted

**Severity: High** · LLM Tax: ~800–1,500 tokens per `ship` invocation

**Where:** `references/ship.md:20`
> "**Do the strings ship?** New translatable strings mean a regenerated `.pot` and updated catalogs. A string added and never extracted is a permanent hole."

**What the LLM currently does:** Opens PHP sources, spots `__()`, `_e()`, `esc_html__()`, `_n()`, `_x()` calls, remembers the string literals and text domains, then opens `languages/*.pot` and each of the seven `.po` files and compares by eye. In this project that is 7 locales × 2 files plus a `.pot` plus the whole `includes/` tree. The prompt itself calls a miss "a permanent hole", i.e. the failure mode is real and the detection is 100% mechanical.

**What a script would do:** `scripts/i18n-consistency.py <plugin-dir>` — regex-extract every gettext call (function name, string literal, text domain, file:line), parse `.pot`/`.po` msgids, and emit JSON:
- strings in code missing from the `.pot`
- msgids in the `.pot` orphaned from code
- text-domain mismatches against the plugin header `Text Domain:`
- per-locale: msgid count vs `.pot`, untranslated (`msgstr ""`) count, `.mo` older than its `.po`
- `_n()` calls whose plural forms are absent

Exit 0 clean / 1 drift / 2 error, matching the house convention. Everything here is unit-testable with fixture files.

**Pre-pass potential:** High — the LLM then only judges *whether a missing string matters for this release*, which is the actual judgment call. **Standalone value:** High — usable as a pre-commit lint independently of Henk. **Reuse:** Any WordPress plugin agent. **`--help` saving:** `ship.md` would not need to describe the check at all, just name the script.

---

### H2 — API existence verification and code-search are described as LLM work

**Severity: High** · LLM Tax: ~1,000–2,500 tokens per `api-check` invocation

**Where:** `references/api-check.md:17` and the checklist at `:19–24`
> "Confirm from the actual sources available to you — the installed core and plugin sources in the project (`wp-content`, `vendor`, a wp-env container)…"

**What the LLM currently does:** Two separable jobs fused into one prompt instruction. Job A — enumerate every WordPress/WooCommerce symbol the code under review touches (hook names in `add_action`/`add_filter`/`do_action`/`apply_filters`, function calls, class and method references, constants). Job B — for each, grep an installed core tree for `function name(`, `do_action( 'name'`, `class Name`, `@deprecated`. Job A is a read-and-list pass over potentially thousands of lines; Job B is N greps whose raw output the LLM must read.

**What a script would do:** `scripts/api-surface.py` with two modes:
- `--extract <path>` → JSON list of distinct WP/WC-looking symbols used, each with kind (hook/function/class/const), file:line occurrences, and for hooks the registered callback, priority, and accepted-args count.
- `--verify <path> --core <wp-root> [--woo <woo-root>]` → for each extracted symbol: `found` / `not_found` / `deprecated` (with the `@deprecated x.y` version and `_deprecated_function()` replacement string lifted straight from the docblock), plus the `do_action`/`apply_filters` site that defines each hook.

This is the single largest zero-token win available. Everything the prompt lists as needing verification *against installed sources* — existence, deprecation status, argument count declared at the `do_action` site — is greppable. What is left for the LLM is exactly what should be: timing/load-order reasoning, intent (private API used as public), and the HPOS/CRUD-layer judgment at `api-check.md:24`.

**Pre-pass potential:** Very high — hand the LLM a JSON table of ~40 symbols instead of a codebase. It also improves accuracy: the current design's failure mode is the LLM *not noticing* a symbol, which a regex cannot do. **Reuse:** `audit.md:24` (deprecation) and `upstream-watch.md:25` consume the same data.

---

### H3 — Upstream impact search is manual grepping

**Severity: High** · LLM Tax: ~300–800 tokens per `upstream-watch` run, more when a release removes several APIs

**Where:** `references/upstream-watch.md:25`
> "Then do the part that matters: search the plugin code for each one. An impact report without file and line references is gossip."

**What the LLM currently does:** After reading release notes and forming a list of removed/renamed symbols, it issues an ad-hoc grep per symbol and reads each result set. On a WordPress major with a dozen deprecations that is a dozen tool round-trips and a dozen result dumps.

**What a script would do:** A `--symbols name1,name2,…` or `--symbols-file removals.json` mode on the same `api-surface.py` from H2: take a symbol list, return `{symbol: [{file, line, context_line}]}` plus a `not_used` list. One call, one compact JSON, file:line references guaranteed present — which is precisely what the prompt says separates a report from gossip.

**Pre-pass potential:** High. **Standalone value:** Medium — useful for any "did we ever call X?" question. **Note:** `upstream-versions.py` already establishes *which* versions to read; this closes the other half of the same capability, so the two scripts pair naturally.

---

### H4 — Sanctum hygiene during Pulse is eyeball arithmetic

**Severity: High** · LLM Tax: ~600–1,200 tokens per Pulse run (and Pulse is the *unattended* path)

**Where:** four separate places asking for the same mechanical work —
- `references/memory-guidance.md:58` / `assets/PULSE-template.md:19` — "prune logs older than 14 days"
- `references/memory-guidance.md:70` / `assets/PULSE-template.md:21` — "Every time you create a new organic file or folder, update INDEX.md. An unlisted file is a lost file." / "Update INDEX.md if new organic files have appeared"
- `references/memory-guidance.md:87` / `assets/PULSE-template.md:17` — "Keep MEMORY.md under 200 lines. If it's longer, you're hoarding"
- `references/first-breath.md:80` — "scan sanctum files for remaining `{...}` placeholder instructions"

**What the LLM currently does:** Lists `sessions/`, parses `YYYY-MM-DD` filenames, does date arithmetic against today, decides which exceed 14 days. Reads `MEMORY.md` and counts lines. Reads `INDEX.md`, lists the sanctum tree, and diffs the two sets in its head. Reads six ALLCAPS files hunting for surviving `{…}` seed text. Four deterministic operations, every one of which an LLM does *approximately*.

**What a script would do:** `scripts/sanctum-doctor.py <sanctum-path>` → one JSON report:
```json
{
  "memory_lines": 213, "memory_limit": 200, "over_limit": true,
  "sessions": [{"file": "2026-08-01.md", "age_days": 17, "prunable": true, "bytes": 2140}],
  "index_drift": {"on_disk_not_indexed": ["plugins/skwirrel.md"], "indexed_not_on_disk": []},
  "placeholders": [{"file": "PULSE.md", "line": 50, "text": "{Set during First Breath...}"}],
  "required_files_missing": [], "capabilities_registered": 5, "capability_files": 6
}
```
Optional `--prune-sessions` to actually delete the aged logs (the LLM keeps the decision, the script does the deletion). Exit 0 healthy / 1 issues found / 2 error.

**Pre-pass potential:** Very high — the LLM should be spending Pulse tokens on *curation judgment* (what's worth keeping from a session log), not on `date` math. **Standalone value:** High. **Reuse:** Every BMAD memory/autonomous agent with a sanctum has these exact four invariants — this is the most reusable script on the list. It would also catch the `capabilities_registered != capability_files` mismatch noted in M4.

---

### M1 — Audit's mechanical smell-scan has no pre-pass

**Severity: Medium** · LLM Tax: ~700–2,000 tokens on large files, partially offset by PHPCS

**Where:** `references/audit.md:19–24` (the "What You Are Hunting" list) and `:32`

**What the LLM currently does:** Reads whole classes looking for `posts_per_page => -1`, queries inside loops, `$wpdb->query` with interpolation, `unserialize()`, autoloaded options with large payloads, and AJAX/REST endpoints with no capability or nonce gate. The prompt correctly says at `:32` that what PHPCS already catches "does not need you" — but nothing computes *which* of those the tools actually caught, so the LLM re-reads everything anyway.

**What a script would do:** `scripts/wp-attack-surface.py <plugin-dir>` — inventory every externally reachable entry point and its gate status:
- `add_action('wp_ajax_*'/'wp_ajax_nopriv_*')`, `admin_post_*`, `register_rest_route` (with its `permission_callback`, flagging `__return_true`), `add_shortcode`, `admin_menu` callbacks
- for each callback body: presence/absence of `current_user_can`, `check_ajax_referer`, `wp_verify_nonce`, `check_admin_referer`
- plus a small, high-signal grep set PHPCS is weak on: `posts_per_page` → `-1`, `add_option(..., …, 'yes')` with a non-scalar default, `get_post_meta` on an order ID (HPOS smell), `WP_Query` inside a `foreach`

Output: JSON of entry points with `gated: true|false|unknown` and file:line.

**Honest caveat:** overlap with `phpcs` (WordPress-Extra sniffs cover escaping, nonces, and unprepared SQL) is real, and `quality-gates.py` already runs it. The script should therefore be scoped to what PHPCS structurally *cannot* express — the endpoint-to-authorization mapping — rather than duplicating sniffs. `audit.md:32` already draws that line; the script would make the line executable instead of aspirational.

**Pre-pass potential:** High for the endpoint inventory, low for the grep set.

---

### M2 — Capability registration is a seven-step manual edit with a uniqueness constraint

**Severity: Medium** · LLM Tax: ~250–500 tokens per new capability, plus a silent-corruption risk

**Where:** `references/capability-authoring.md:60–69` (the flow) and `:44–50` (the frontmatter contract, including "`code`: 2-letter code, **unique across all capabilities**")

**What the LLM currently does:** Writes the file, then hand-edits a markdown table row into `CAPABILITIES.md`, then hand-edits `INDEX.md`. To honour the uniqueness constraint it must first read both the Built-in and Learned tables and mentally collect every code in use (currently AU, BD, AP, SR, UW). Markdown table editing by an LLM is a known source of column-misalignment and duplicate rows, and a collided code produces an agent that silently dispatches to the wrong capability next session.

**What a script would do:** `scripts/register-capability.py <sanctum> --name … --code … --description … --type prompt|script|multi-file|external` — validate the capability file's frontmatter against the documented contract, reject a colliding or malformed code with the list of codes in use, insert the Learned-table row in place, and add the `INDEX.md` entry. Plus `--list-codes` and `--retire <code>` (which per `:75–77` removes the row but keeps the file). This turns steps 5–7 of the documented flow into one call and makes the uniqueness invariant enforced rather than remembered.

**Reuse:** Every evolvable BMAD agent. **Note:** `init-sanctum.py:118` already contains `discover_capabilities()` and `generate_capabilities_md()` — most of the parsing logic exists and could be factored out rather than rewritten.

---

### M3 — WordPress.org readme/directory compliance is a checklist, not a judgment

**Severity: Medium** · LLM Tax: ~300–600 tokens per `ship` invocation

**Where:** `references/ship.md:22`
> "**Would the directory accept it?** For WordPress.org: readme structure, the assets, no bundled surprises, no phoning home undisclosed, no code the guidelines forbid."

**What the LLM currently does:** Reads `readme.txt` and checks structure by recall of the WordPress.org readme spec.

**What a script would do:** `scripts/readme-validate.py <plugin-dir>` — required headers present (`Contributors`, `Tags`, `Requires at least`, `Tested up to`, `Stable tag`, `License`), short description ≤ 150 chars, tag count ≤ 5, required sections (`== Description ==`, `== Installation ==`, `== Changelog ==`), screenshot numbering matching files in `assets/`, banner/icon filenames and dimensions, and a scan for bundled `node_modules`/`.git`/minified-only sources.

The genuinely judgment-shaped half of that prompt line — "no phoning home undisclosed", "no code the guidelines forbid" — correctly stays with the LLM. The structural half should not cost tokens. `release-consistency.py` already parses `readme.txt` for `Stable tag` and the changelog heading, so this is a natural second script (or a `--readme-lint` flag on the existing one).

---

### M4 — Changelog-from-diff has no data pre-pass

**Severity: Medium** · LLM Tax: ~200–500 tokens per `ship` invocation

**Where:** `references/ship.md:19`
> "**Does the changelog describe reality?** Entries written from the diff, not from the intent."

**What the LLM currently does:** Runs `git log`/`git diff` ad hoc, reads raw output, then compares against the changelog entry. The retrieval is deterministic; only the "does this entry describe that change" comparison is not.

**What a script would do:** A `--since-tag <tag>` (defaulting to the latest matching `X.Y.Z` tag) mode on `release-consistency.py`, shelling out via `subprocess` to `git`: commit subjects, changed-file counts grouped by directory, and the parsed changelog bullets for the target version, side by side in JSON. The LLM reads a compact structured comparison instead of a diff.

**Pre-pass potential:** High. **Note:** the project's own `CLAUDE.md` documents that the deploy workflow fails on a missing `= X.Y.Z =` entry — `release-consistency.py` already checks presence; this checks *content plausibility*, which is the remaining half.

---

### M5 — Declared floors are cross-referenced by eye

**Severity: Medium** · LLM Tax: ~150–300 tokens per `ship`/`api-check` invocation

**Where:** `references/ship.md:21` ("each is a promise, and the code either keeps it or doesn't") and `references/api-check.md:26` ("Respect the floors the project declares")

**What the LLM currently does:** Reads `Requires PHP` from the header and separately reasons about whether `composer.json`, `phpstan.neon.dist`, `.phpcs.xml.dist` (`testVersion`), and CI matrix files agree with it.

**What a script would do:** Extend `release-consistency.py` (it already extracts all four floor headers at `HEADER_FIELDS`) to also parse `composer.json` `require.php` / `config.platform.php`, `.phpcs.xml.dist` `<config name="testVersion">`, `phpstan.neon` `phpVersion`, and `.github/workflows/*.yml` PHP matrix entries, and report agreement or divergence. Purely a cross-reference — category 5, no judgment involved.

---

### L1 — Learned-capability frontmatter is unvalidated, and drops silently

**Severity: Low** · LLM Tax: <100 tokens, but with an outsized correctness cost

**Where:** `references/capability-authoring.md:44–50` vs `scripts/init-sanctum.py:118` (`discover_capabilities`)

`discover_capabilities()` requires `name` and `code` and silently skips any file lacking either — so a learned capability written with malformed frontmatter simply vanishes from `CAPABILITIES.md` with no error. Separately, the documented frontmatter contract lists five fields (`name`, `description`, `code`, `added`, `type`) while every shipped built-in carries only three; a validator would force that discrepancy to be resolved one way or the other. A `--validate` mode (on `register-capability.py` from M2, or standalone) is category-9 post-processing validation: check the LLM-authored file meets the structural contract before it is accepted.

---

### L2 — Activation branch and CAPABILITIES.md refresh

**Severity: Low** · LLM Tax: <50 tokens per session

**Where:** `SKILL.md:33–35`

Two small things. First, the "No sanctum → First Breath" branch asks the LLM to determine sanctum existence before running the script — but `init-sanctum.py` already handles the exists case and, with `--json`, returns `{"created": false, "reason": "sanctum already exists"}`. The activation could unconditionally run it with `--json` and branch on that field, removing a directory check. Second, `init-sanctum.py` refuses to touch an existing sanctum entirely, so there is no supported way to regenerate `CAPABILITIES.md` after the built-in set changes (a skill update adding a sixth capability leaves the sanctum's table stale forever). A `--refresh` mode that regenerates only the Built-in table and re-copies `references/`+`scripts/`, preserving the Learned table and every other sanctum file, closes that gap.

---

## Aggregate Savings

| Finding | Capability affected | Est. tokens saved per invocation |
|---------|--------------------|----------------------------------|
| H1 i18n consistency | ship, pulse | 800–1,500 |
| H2 API surface extract + verify | api-check, audit, build | 1,000–2,500 |
| H3 symbol code-search | upstream-watch | 300–800 |
| H4 sanctum-doctor | pulse, first-breath, session close | 600–1,200 |
| M1 attack-surface inventory | audit, pulse rot-audit | 700–2,000 |
| M2 capability registration | capability-authoring | 250–500 |
| M3 readme/directory validation | ship | 300–600 |
| M4 changelog-from-diff pre-pass | ship | 200–500 |
| M5 floor cross-reference | ship, api-check | 150–300 |
| L1 capability frontmatter validation | capability-authoring | <100 |
| L2 activation branch + refresh mode | activation | <50 |
| **Total** | | **~4,400–10,050** |

Sequenced by value-per-effort, the order is: **H4** (most reusable, smallest script, protects the unattended path), **H1** (highest confidence, zero judgment involved, the prompt already calls the failure "permanent"), **H2/H3** (one script, two modes, feeds three capabilities), then **M2/M3/M4** as extensions of scripts that already exist. **M1** should be scoped narrowly to endpoint-authorization mapping to avoid duplicating PHPCS.

Every one of these fits the standard the agent already holds itself to in `references/capability-authoring.md:31` — stdlib Python, PEP 723, `argparse --help`, JSON to stdout, exit 0/1/2 — and every one is unit-testable in the same style as the four existing test files, so the `--help` self-documentation convention lets the prompts reference them by name without inlining an interface.
