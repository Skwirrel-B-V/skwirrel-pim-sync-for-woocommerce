# L1 Structure & Capabilities Scan — bmad-agent-wp (Henk)

**Scanner:** StructureBot (L1)
**Target:** `.claude/skills/bmad-agent-wp`
**Agent class:** autonomous memory agent, evolvable capabilities, configuration-style First Breath
**Pre-pass:** `structure-capabilities-prepass.json` (status: warning, 16 issues — 8 high, 8 medium, 0 critical)

---

## Assessment

Structurally this is a sound memory-agent bootloader. All four bootloader-required sections are present (The Three Laws, The Sacred Truth, On Activation, Session Close), `references/first-breath.md` exists, `assets/` carries the full sanctum template set, and every capability in the routing table resolves to a real file with valid frontmatter. There are **no critical findings**.

The pre-pass's 16 issues are, on inspection, **all false positives for this agent class** — they apply stateless-workflow expectations (config headers, progression gates) to outcome-focused capability prompts that deliberately reject numbered procedures. They are preserved below as required, but the real defects lie elsewhere: a path-resolution ambiguity between the skill bundle and the sanctum copies of `references/` and `scripts/`, a `CAPABILITIES-template.md` that claims to be a fallback the code can never reach, and two small sanctum-inventory gaps.

---

## Sections Found

### SKILL.md (bootloader)

| Section | Line | Status |
|---|---|---|
| Frontmatter (`name`, `description`) | 1–4 | Present, `name` kebab-case, description has "Use when" |
| Identity seed paragraph | 8 | Present (free-flowing, correct for a bootloader) |
| The Three Laws | 10 | Present, includes Mission |
| The Sacred Truth | 20 | Present |
| Conventions | 24 | Present (non-standard but useful — path resolution rules) |
| On Activation | 29 | Present, three-branch |
| Session Close | 39 | Present |

No invalid sections (`On Exit` / `Exiting`) present. Per memory-agent rules, missing Overview / Identity / Communication Style / Principles sections are **not** flagged — Communication Style lives in `assets/PERSONA-template.md` (lines 10–15, populated), Principles live in `assets/CREED-template.md` (Core Values, lines 15–22, populated).

### Sanctum templates (`assets/`)

INDEX, PERSONA, CREED, BOND, MEMORY, PULSE, CAPABILITIES — all seven present. `scripts/init-sanctum.py:39-46` materialises six of them; CAPABILITIES.md is generated instead (see finding S-2).

### Non-capability references

`memory-guidance.md` (memory discipline — the replacement for `save-memory.md`) and `capability-authoring.md` (evolvability framework) are both present and correctly excluded from capability discovery by the `code:` frontmatter filter in `init-sanctum.py:127`.

---

## Capabilities Inventory

All five routed capabilities resolve. Routing lives in `assets/CAPABILITIES-template.md:7-13` and is regenerated at birth from `references/*.md` frontmatter.

| Code | Name | Target file | Exists | Frontmatter | Structural notes |
|---|---|---|---|---|---|
| AU | audit | `references/audit.md` (40 ln) | Yes | `name`, `description`, `code` | Full four-part shape: Success / What You Are Hunting / Approach / Memory Integration / After the Session. Cross-references api-check rather than duplicating it. Clean. |
| BD | build | `references/build.md` (31 ln) | Yes | complete | Outcome-focused, binds to project conventions and `CLAUDE.md`/`AGENTS.md`. Clean. |
| AP | api-check | `references/api-check.md` (34 ln) | Yes | complete | Tightest of the five; names its own failure mode explicitly (line 13). Clean. |
| SR | ship | `references/ship.md` (33 ln) | Yes | complete | Code `SR` for `ship` is non-mnemonic (`SH` free); cosmetic only. |
| UW | upstream-watch | `references/upstream-watch.md` (35 ln) | Yes | complete | Clean. |

**No orphaned capabilities. No dead routing rows. No duplicate codes** (AU/BD/AP/SR/UW all unique).

**Scripts referenced by capabilities** — `scripts/quality-gates.py` (audit:32, build:21, ship:23), `scripts/release-consistency.py` (ship:15), `scripts/upstream-versions.py` (upstream-watch:15), plus PULSE-template:25,33. All three exist, each with a matching test in `scripts/tests/`. Table in CAPABILITIES-template:34-38 matches the files on disk.

**Over-specification check:** none found. Capability prompts do not repeat identity or communication style, contain no step-by-step procedures the persona would supply, and there are no per-platform adapter files. `capability-authoring.md:53` states the design rule explicitly ("outcome-focused — what success looks like, not a numbered procedure") and all five prompts honour it. The five capabilities are genuinely distinct verbs, not proliferation.

---

## Key Findings

### Pre-pass findings (preserved as-is)

The pre-pass reports 16 issues across all 8 files in `references/`:

- **8 × HIGH — "No progression condition keywords found"** (`api-check.md:35`, `audit.md:41`, `build.md:32`, `capability-authoring.md:78`, `first-breath.md:82`, `memory-guidance.md:92`, `ship.md:34`, `upstream-watch.md:36`)
- **8 × MEDIUM — "No config header with language variables found"** (line 1 of each of the same files)

**StructureBot assessment:** these are deterministic checks written for stateless step-based workflows and do not map onto this agent class. Progression conditions gate numbered workflow steps; these files intentionally have no steps. Per-file language config headers are redundant here because language is resolved once at activation (`SKILL.md:31`) and persisted to `BOND.md` (`BOND-template.md:6`, `first-breath.md:10`). Recommend the report treat all 16 as **not applicable** rather than as defects — but do not delete them from the ledger; if the checks are to keep running against memory agents, the pre-pass script needs an `is_memory_agent` branch.

### Scanner findings

**S-1 · MEDIUM · Path convention collides with the sanctum's duplicated `references/` and `scripts/`**
`SKILL.md:26` declares bare paths resolve from the skill root. But `init-sanctum.py:264-273` copies every non-`first-breath` reference **and** every script into `{sanctum}/references/` and `{sanctum}/scripts/`, and the generated CAPABILITIES.md points at `references/audit.md` etc. (`init-sanctum.py:235`, `sanctum_refs_path = "references"`). At runtime `references/audit.md` and `scripts/quality-gates.py` therefore name two different files, and the sanctum copy is frozen at birth date — a skill update never reaches an already-born Henk. `SKILL.md:34,35,41` also uses bare `PULSE.md` / `references/memory-guidance.md` while the prose says "from sanctum", contradicting the stated convention.
*Fix:* state a third rule in Conventions — `{sanctum}`-prefixed paths — and make CAPABILITIES generation and SKILL.md use it explicitly. Separately, decide whether copying references into the sanctum is worth the staleness; if the intent is self-containment, add a re-sync step to Pulse.

**S-2 · MEDIUM · `assets/CAPABILITIES-template.md` claims to be a fallback that is unreachable**
The file's own subtitle (line 3) says "Normally this file is generated by `scripts/init-sanctum.py`". But `CAPABILITIES-template.md` is not in `TEMPLATE_FILES` (`init-sanctum.py:39-46`) and `generate_capabilities_md()` has no fallback branch that reads it — it is never opened under any code path. It is therefore an unversioned second copy of the routing table that will silently drift from the generator (it already differs: the generated text drops "Next session, I'll know how." onto the following line, and the asset is what a reader of the repo sees as the routing table).
*Fix:* either wire it in as a real fallback when `discover_capabilities()` returns empty, or delete it and make the generator the single source of truth. Whichever way, keep exactly one copy of the routing table.

**S-3 · LOW · On Activation states its sanctum path after the branches that depend on it**
`SKILL.md:29-37`: branches 1–3 all hinge on whether the sanctum exists, but the sanctum location is only given at line 37, below them. Branch 1 also never says *how* to detect "no sanctum" — the script is idempotent and reports `"created": false`, so behaviour is safe, but the instruction leans on the model inferring the check.
*Fix:* move the sanctum path line above the numbered list and make branch 1 read "if `{sanctum}` does not exist".

**S-4 · LOW · Headless task variants are undocumented in the bootloader**
`SKILL.md:34` knows only `--headless`. `PULSE-template.md:41-47` defines four additional routes (`--headless:memory`, `:upstream`, `:audit`, `:ship`). Since PULSE.md is only loaded *after* the headless branch is taken, the routing table is reachable — so this is documentation, not breakage — but nothing in the skill bundle tells an owner the sub-tasks exist.
*Fix:* one line in On Activation, or in the First Breath Pulse section (`first-breath.md:52-54`), listing the variants.

**S-5 · LOW · `capabilities/` folder is missing from the INDEX template**
`init-sanctum.py:260` creates `{sanctum}/capabilities/`, and `capability-authoring.md:66-68` instructs learned capabilities be saved there and noted in INDEX.md. But `INDEX-template.md:14-17` lists only `references/`, `scripts/`, and `sessions/`. On an evolvable agent the folder that holds its learned abilities should be in the index from birth.
*Fix:* add `- \`capabilities/\` — learned capability prompts` to INDEX-template's References & Scripts section.

**S-6 · LOW · Description trigger clause is broad on the verb "built"**
`SKILL.md:3` — "…or wants plugin code audited, built, API-checked, or made release-ready." The named-persona half ("asks to talk to Henk") is properly conservative and the two-part format is correct. But "built" is a bare high-frequency verb, and this workspace also ships `bmad-build` and `quick-dev`, both of which claim general build intent in the same repo. Risk is wrong-agent activation on a plain "add a setting to the plugin".
*Fix:* narrow to quoted, domain-anchored phrases — e.g. "audit this plugin", "check these WordPress APIs", "make this plugin release-ready" — and let plain build requests fall to the general build skill unless Henk is named.

**S-7 · LOW · Ship capability code `SR` is non-mnemonic**
`references/ship.md:4` — `SH` is unclaimed. Purely cosmetic; only worth changing if no sanctum has been born yet, since the code is a user-facing trigger.

---

## Strengths (preserve)

- **Identity seed is excellent.** `SKILL.md:8` is one paragraph that primes behaviour rather than announcing a title — "he has never once invented a hook to make an answer sound complete" is a testable behavioural commitment, and it is reinforced consistently in CREED Core Values ("Verified beats plausible"), Boundaries, and the `api-check` failure mode. Identity, style, and capabilities are mutually consistent throughout.
- **Principles are genuinely guiding, not platitudes.** `CREED-template.md:17-22` — "Core already did it", "The codebase outranks your taste", "Escape at output, check at entry, prepare at the query" — each resolves a real ambiguity a WordPress agent will hit.
- **Communication Style ships with concrete, persona-matched guidance** (`PERSONA-template.md:13-15`) and is mirrored by an Anti-Patterns section in CREED (`:54-60`) that says what *not* to do. Style and identity do not contradict.
- **Capability discovery is data-driven.** Adding a `references/*.md` with a `code:` field registers a capability with no code change; files without `code:` are auto-excluded. `first-breath.md` is correctly on the skill-only list so it never pollutes the sanctum.
- **Dominion is real and specific** (`CREED-template.md:68-79`) — read/write split, explicit deny zones for `.env`, production DBs, and customer data, backed by matching Boundaries ("never tag, push or deploy on your own initiative").
- **Every script has a test.** `scripts/tests/` covers all three working scripts plus init-sanctum.

---

## Memory & Headless Status

**Memory: configured and consistent.**
Sanctum path is stated once in `SKILL.md:37` as `{project-root}/_bmad/memory/bmad-agent-wp/` and matches `init-sanctum.py:225-227` (`project_root / "_bmad" / "memory" / SKILL_NAME`) exactly. The pre-pass found a single memory path with no inconsistencies. Two-tier memory (`sessions/YYYY-MM-DD.md` raw → MEMORY.md curated, 200-line cap) is defined in `memory-guidance.md:34-58` and re-stated consistently in `MEMORY-template.md:7` and `PULSE-template.md:11-21`. Save triggers are explicit in three places: `SKILL.md` Session Close, `memory-guidance.md:72-78` ("When to Write"), and per-capability "After the Session" sections in all five prompts. Template variable substitution (`{user_name}`, `{communication_language}`, `{birth_date}`, `{project_root}`, `{sanctum_path}`) is complete — every placeholder used in the templates is supplied by `init-sanctum.py:250-256`. Remaining `{...}` seed prose is intentional and `first-breath.md:80` instructs cleanup.

**Headless: configured, minor documentation gap.**
Wake prompt exists (`PULSE-template.md`), default no-task behaviour is defined as an explicit priority order (Memory Curation → Upstream Watch → Rot Audit → Ship-Readiness → Self-Improvement), tasks are documented in a routing table, and quiet hours are captured at First Breath. Only gap is S-4: the sub-task flags never surface in the bootloader or First Breath script.

**First Breath: complete for the configuration style.** Eight discovery questions (`first-breath.md:30-39`) each map to a named sanctum destination (`:60-69`), save-as-you-go is enforced (`:16-18`), urgency detection defers the questionnaire to real work (`:20-22`), and evolvability is surfaced to the owner (`:49-50`).

---

## Severity Roll-up

| Severity | Count | Source |
|---|---|---|
| Critical | 0 | — |
| High | 8 | All pre-pass "progression" checks — assessed as not applicable to this agent class |
| Medium | 10 | 8 pre-pass "config header" checks (not applicable) + S-1, S-2 |
| Low | 5 | S-3, S-4, S-5, S-6, S-7 |

Excluding the inapplicable pre-pass checks, the agent carries **2 medium and 5 low** structural findings and no blockers.
