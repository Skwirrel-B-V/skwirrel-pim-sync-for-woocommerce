# BMad Method · Quality Analysis: bmad-agent-wp

**🔧 Henk** — WordPress Plugin Engineer
**Analyzed:** 2026-08-18T13:01:01Z | **Path:** /Users/joskoomen/Documents/Projects/Skwirrel/wordpress/.claude/skills/bmad-agent-wp
**Interactive report:** quality-report.html

## Agent Portrait

Henk is a Dutch senior WordPress engineer with too many plugins behind him to be impressed by yours — blunt, dry, conclusion-first, and allergic to reinventing what core already does. His defining conviction is that verified beats plausible: inventing a hook to make an answer sound complete is the one unforgivable failure, and an entire capability (`api-check`) exists to make that anxiety executable. He would rather tell you an idea is wrong before you build it than review it after you shipped it, and he treats "finished" as a synonym for "gated".

## Capabilities

| Capability | Status | Observations |
| ---------- | ---------------------- | ------------ |
| audit `[AU]` | Needs attention | 5 |
| build `[BD]` | Good | 2 |
| api-check `[AP]` | Needs attention | 4 |
| ship `[SR]` | Needs attention | 6 |
| upstream-watch `[UW]` | Needs attention | 4 |
| Pulse / unattended runs | Needs attention | 5 |

## Assessment

**Good** — This is one of the most coherent memory agents in the fleet. The persona is load-bearing rather than decorative: nearly every trait in the identity seed traces to a concrete instruction in a capability, a boundary in CREED, or one of three tested Python scripts, and all four L1/L2/L3/L8 scanners independently flagged the same thing — zero measurable waste, exemplary intelligence placement, a clean abuse profile. Nothing is broken. The opportunities are environmental: Henk was built as though he were the only tool in the repo, he reads whole codebases into his own context instead of fanning out, and the two mechanisms his entire value rests on — writing memory at session close and being born from `config.yaml` — both rest on assumptions this project already breaks.

## What's Broken

Nothing. Zero critical findings, and no high-severity defect that stops the agent working today. Every item below is a refinement.

## Opportunities

### 1. Memory is written by luck (high — 5 observations)

Session Close says "before ending any session, load `references/memory-guidance.md` and follow its discipline." Sessions do not announce their end — they end by a closed terminal, a compaction, or an exhausted context, and in all three cases the sanctum write never happens and the entire session's learning is lost. For a memory agent this is the highest-consequence instruction in the bundle, and it is the one with no reliable trigger. The irony is that First Breath already solved this: "Save As You Go — whatever you haven't written down is lost forever" is the best paragraph in the skill, applied to birth and abandoned for every session after it.

**Fix:** Extend checkpoint-as-you-go to normal sessions. Write the session-log entry after each completed capability run, each correction, each ruling — append, don't buffer. Recognise explicit close phrases, mark interrupted work with `**Incomplete:**` so the next session resumes rather than restarts, and re-read the sanctum on compaction recovery. While you're there, move the Session Close section out of the bootloader into CREED's Standing Orders where the pattern says it belongs.

- **No trigger for Session Close** — `SKILL.md:39-41` (prompt-craft L2-04, enhancement H3)
- **Session Close belongs in the sanctum, not the bootloader** — `SKILL.md:39` (sanctum SA-01)
- **No mid-flight abandonment path** — partial audit findings evaporate (enhancement M6)
- **Compaction drops MEMORY.md rulings** — Henk will re-report a dismissal `audit.md` promises never to re-report (enhancement, edge-case journey)
- **Pulse findings land in session logs, which are never loaded on rebirth** — `PULSE-template.md`, `memory-guidance.md:38` (cohesion #2)

### 2. Birth starts with a guess (high — 4 observations)

The agent whose creed is "never claim what you haven't verified" opens its life with an unverified guess. `SKILL.md` and `init-sanctum.py` both load `_bmad/config.yaml` / `config.user.yaml`; this project stores config in `_bmad/config.toml` / `config.user.toml`, where `user_name = "Jos"` actually lives. `user_name` therefore falls back to `"friend"` and Henk greets his owner by a name nobody gave him. Compounding it, First Breath asks eight questions that `CLAUDE.md` and `.claude/rules/*.md` already answer in full, two directories away — friction that a "read the code before judging it" agent should be embarrassed by.

**Fix:** Teach `init-sanctum.py` to read TOML first (`tomllib` is stdlib on 3.11+) and name both formats in the activation line. Then insert a Reconnaissance step before Discovery: read `CLAUDE.md`/`AGENTS.md`/`.claude/rules/`/plugin headers, and **confirm rather than ask** — eight questions become three, and the first impression is competence instead of a form. While in the script, parse the `[agent]` block from `customize.toml` and add `{agent_name}`/`{agent_icon}`/`{agent_title}` to the substitution dict so the identity triplet has one source.

- **Config format mismatch — YAML expected, TOML present** — `SKILL.md:31`, `scripts/init-sanctum.py` (enhancement H1)
- **First Breath asks what `CLAUDE.md` already answers** — `references/first-breath.md:30-39` (enhancement H4)
- **Name/icon/title hardcoded in three places with no single source** — `customize.toml`, `SKILL.md:8`, `assets/PERSONA-template.md` (customization M-2)
- **A First Breath rename never reaches the invocation trigger** — the frontmatter still only fires on "Henk" (customization M-3)

### 3. No fan-out vocabulary anywhere in the agent (high — 6 observations)

Across 7 markdown files and 4 scripts the only batching or delegation word in the entire bundle is the single "Batch-load" in `SKILL.md:35`. Every capability that is inherently multi-file — audit, api-check, upstream-watch — instructs Henk to read the codebase directly into his own context. On this project's ~28 plugin classes that means tens of thousands of tokens of source arrive before a single finding is produced, and the context is then too degraded for the synthesis that actually matters: ranking by consequence and deciding what blocks a release. The Pulse runs five workstreams strictly in sequence in the one session where nobody is waiting, and the two hottest scripts run independent subprocesses serially.

**Fix:** Add one "How I fan out" block to `SKILL.md` — when to delegate (more than ~3 files of reading), how to dispatch (one message, N subagents), and what to demand back (`return ONLY` a capped JSON findings array). That single block fixes the capability, contract, and Pulse findings at once and is inherited by every future learned capability. Then add a `Delegation` section to `capability-authoring.md`, split the Pulse into a parallel stage and a curation stage, and thread `quality-gates.py`'s three gates and `upstream-versions.py`'s two API calls with `ThreadPoolExecutor`.

- **Capabilities read whole codebases in the parent context** — `audit.md:28`, `api-check.md:17`, `upstream-watch.md:25` (efficiency #1)
- **No subagent output-format contract exists** — no "return ONLY", no token budget, no schema (efficiency #2)
- **Pulse runs five independent workstreams sequentially** — `PULSE-template.md:7-37` (efficiency #3)
- **`quality-gates.py` runs pest/phpstan/phpcs serially** — `scripts/quality-gates.py:148-152`, ~40–60% of gate wall clock (efficiency #5)
- **`upstream-versions.py` serialises two unrelated hosts and re-fetches per plugin** — `scripts/upstream-versions.py:160-168` (efficiency #6)
- **No parallel review lenses before a finding list ships** — the Sacred Truth's "fresh eyes see what habit misses" is never operationalised (enhancement M3)

### 4. Henk was built as if he were the only tool in the repo (medium — 6 observations)

Not one capability prompt, nor `SKILL.md`, nor the CAPABILITIES template mentions a single external or project skill — while this repository ships `/release` (which the owner's own memory says to use instead of bumping by hand), `/quality`, `/api-check`, `/add-tests`, `/add-translation`, and `/sync-debug`. `[AP]` even collides by name with the project's `/api-check`, which validates Skwirrel API *fields* rather than WP/WC APIs. Worse, the tooling doctrine ("prefer crafting your own tools over depending on external ones") actively pushes him away from the skills his owner already built and trusts — which sits oddly against his loudest conviction, that you don't hand-roll a replacement for something that already exists.

**Fix:** Add a "Project Skills I Defer To" table to `CAPABILITIES-template.md`, discovered at First Breath by a new question ("what skills and commands already exist here that I should use rather than duplicate?"). Have `ship.md` state that a documented project release procedure outranks its own approach. Scope the tooling doctrine: prefer own scripts over unverifiable external services, prefer the owner's existing project skills over writing a parallel one. Then close the two capability seams the project's skill roster reveals — reactive diagnosis and test authoring.

- **No capability references the project's existing skills** — `/release`, `/quality`, `/add-tests`, `/sync-debug` (cohesion #1)
- **`[AP]` collides by name with the project's `/api-check` skill** (cohesion #1)
- **Tooling doctrine contradicts "core already did it"** — `CAPABILITIES-template.md` vs `CREED-template.md:17` (cohesion #5)
- **No reactive-diagnosis capability** — "it broke, find out why" falls in the seam between `audit` and `build` (cohesion #3)
- **No test-authoring capability** although "finished means gated" is a core value (cohesion #4)
- **No out-of-scope boundary** — nothing says what Henk doesn't do or who to send them to (enhancement M8)

### 5. Autonomy produces work with nowhere to land (medium — 5 observations)

Pulse is genuinely well designed — five prioritised activities, each naming the script that runs it and the standard its deliverable must meet, plus `--headless:*` task routing that puts Henk ahead of most agents. But unattended findings are written to session logs, which by his own rules are not loaded on rebirth and are pruned after 14 days. A headless upstream-watch finding can be written and then never read. Meanwhile the automator who wants "audit this diff, give me JSON" has no door: `--headless` means "run the pulse", not "run this task" — no target, no output contract, no exit code, no report path.

**Fix:** Define a Pulse output artefact (`pulse/YYYY-MM-DD.md` plus a "Pending for owner" section in MEMORY.md), list it in `INDEX-template.md`, and add a Rebirth step: if there are unreported Pulse findings, lead with them. Then add `--headless:{code} --target <path>` with a report file and a one-line JSON stdout contract, and guard the un-born headless case so a cron job never starts an interview with nobody there. Roughly thirty lines of instruction turns Henk from a conversation partner into infrastructure CI and `/schedule` can call.

- **Pulse findings written to session logs that are never re-read** — `PULSE-template.md`, `memory-guidance.md:38` (cohesion #2)
- **`--headless` on a fresh install starts an interview with nobody** — `SKILL.md:33-34` (enhancement H5)
- **No task-carrying headless mode and no output contract** (enhancement H6)
- **Findings have no persistent artifact** — audits live in conversation scrollback (enhancement L6)
- **An empty gate set reads as green** — `quality-gates.py` skipping undetected gates is indistinguishable from all-passed (enhancement M5)

### 6. Two copies of every reference and script exist after birth (medium — 4 observations)

`SKILL.md:26` declares that bare paths resolve from the skill root. But `init-sanctum.py:264-273` copies every reference and every script into the sanctum, `INDEX-template.md` describes them as living there, and the generated CAPABILITIES table points at a bare `references/…` prefix. At runtime `references/audit.md` and `scripts/quality-gates.py` therefore name two different files, and the sanctum copy is frozen at birth date — a skill update never reaches an already-born Henk. The failure is silent: a stale copy runs without erroring and only shows up as "the agent is behaving like an older version of itself." Alongside it, `assets/CAPABILITIES-template.md` advertises itself as a fallback that no code path can ever reach, making it an unversioned second copy of the routing table.

**Fix:** Decide and state it. The cleanest split is sanctum-wins for capability prompts (that's where evolution happens) and skill-root-wins for scripts (those get bug fixes) — declared as a third `{sanctum}`-prefixed rule in Conventions and used explicitly in the generated CAPABILITIES table. Add a version stamp so rebirth can notice the skill moved on, and give `init-sanctum.py` a `--refresh` mode that regenerates the Built-in table and re-copies references/scripts while preserving the Learned table.

- **Bare-path resolution is ambiguous after First Breath** — `SKILL.md:26-27` vs `init-sanctum.py:264-272` (structure S-1, prompt-craft L2-02, efficiency #8, enhancement M1)
- **`assets/CAPABILITIES-template.md` claims to be an unreachable fallback** and duplicates the Built-in Scripts table hardcoded in the generator (structure S-2, sanctum SA-02)
- **No supported way to refresh a sanctum after a skill update** — `init-sanctum.py` refuses to touch an existing sanctum (scripts L2)
- **`capabilities/` is missing from `INDEX-template.md`** although the init script creates it (structure S-5)

### 7. Deterministic work is still done by eyeball (medium — 5 observations)

The script layer is already above the median — three tested, PEP 723, stdlib-only tools own the most expensive recurring operations, and the prompts explain *why* the split exists so a future session doesn't re-implement the check in prose. What remains unscripted is nonetheless fully mechanical: comparing gettext calls across seven locales by eye, grepping core for every symbol a file touches, hunting `{...}` placeholders and doing date arithmetic over `sessions/` during an unattended Pulse. Roughly 4,400–10,000 tokens per invocation cycle are spent on arithmetic and pattern-matching that Python does exactly and an LLM does approximately.

**Fix:** Build them in value-per-effort order — `sanctum-doctor.py` first (smallest, most reusable, protects the unattended path), then `i18n-consistency.py` (zero judgment involved; `ship.md` already calls a missed string "a permanent hole"), then `api-surface.py` with `--extract`/`--verify`/`--symbols` modes feeding api-check, audit, and upstream-watch from one place. Extend `release-consistency.py` with `--since-tag` and floor cross-referencing rather than writing new scripts.

- **Translation catalog consistency is fully deterministic and fully unscripted** — `ship.md:20`, ~800–1,500 tokens/run (scripts H1)
- **API existence verification described as LLM work** — `api-check.md:17-24`, ~1,000–2,500 tokens/run (scripts H2)
- **Upstream impact search is ad-hoc per-symbol grepping** — `upstream-watch.md:25` (scripts H3)
- **Sanctum hygiene during Pulse is eyeball arithmetic** — line counts, date math, INDEX drift, placeholder sweep (scripts H4)
- **Capability registration is a seven-step manual edit with an unenforced uniqueness constraint** — `capability-authoring.md:60-69` (scripts M2, L1)

## Strengths

**The persona is load-bearing, not decorative.** Almost every trait in the identity seed traces to a concrete instruction: "never invent" becomes a CREED value, a hard boundary, and an entire capability; "say it before it's built" becomes `build.md`'s push-back clause; "blunt" becomes an anti-pattern list that forbids padding. Three independent scanners called this out. Do not soften it.

**Intelligence placement is exemplary.** Deterministic work lives in three tested Python scripts; judgment lives in the prompts. Crucially the prompts explain *why* the split exists — `ship.md:15` names the class of mistake "invisible to a human and fatal to a deploy" — which stops a future session from helpfully re-implementing the check in prose. No script classifies meaning; no prompt validates structure.

**Success is framed as outcome plus failure mode, uniformly.** All five capabilities open by describing the end state and naming the specific failure they exist to prevent. "A good audit is uncomfortable to read and impossible to argue with." "An impact report without file and line references is gossip." This is the structure that lets an agent improvise correctly when the situation goes off-script.

**Zero measurable waste.** Nine files, 7,369 tokens, no defensive padding, no back-references, no suggestive loading, no wall-of-text. The path-standards and scripts lint scanners both returned clean with zero findings. Nothing to cut for its own sake.

**The customization surface is empty on purpose, and says so.** `customize.toml` carries metadata only, and its header comment names the sanctum as the real behaviour surface with its full path — pre-empting the "I opened the file, there's nothing to turn, it must not be customizable" failure. Gate commands, capability prompts, pulse cadence, and learned capabilities were all routed to places that survive a bundle update instead of TOML scalars that don't. The scanner called this the cleanest abuse profile it expects to see.

**Memory discipline is genuinely well designed.** Two tiers (raw session logs → curated MEMORY.md), a hard 200-line budget with a stated rationale, an explicit "what NOT to remember" list where every entry carries its reason, and — best of all — "a dismissal is a decision, not an oversight", which prevents the classic memory-agent failure of re-reporting findings the owner already rejected.

**First Breath respects the owner.** Urgency Detection ("a senior engineer who insists on a questionnaire before touching the problem is not one you'd hire") is the single best design decision in the onboarding. Save-as-you-go, weave-don't-list, skip-what's-answered, a placeholder cleanup sweep, and a closing instruction to find something real in their code.

**Every script has a test.** `scripts/tests/` covers all four Python tools, which is rare at this layer.

## Detailed Analysis

### Structure & Capabilities

Structurally sound with no critical findings. All four bootloader-required sections are present, `first-breath.md` exists, `assets/` carries the full seven-template sanctum set, and all five routed capabilities resolve to real files with valid, unique two-letter codes. No orphaned capabilities, no dead routing rows, no over-specification. The pre-pass's 16 issues (8 high "no progression conditions", 8 medium "no config header") are all false positives for this agent class — they apply stateless-workflow expectations to outcome-focused capability prompts that deliberately reject numbered procedures, and language is resolved once at activation into BOND.md. Excluding those, the agent carries 2 medium and 5 low structural findings.

Remaining standalone items: the `[SR]` code for `ship` is non-mnemonic where `SH` is free (cosmetic, and only worth changing before a sanctum is born), and the description's "built" trigger verb is broad enough to risk wrong-agent activation against this repo's `bmad-build` and `quick-dev`.

### Persona & Voice

Above-average seed craft. `SKILL.md:8` does personality work rather than description work, and "he has never once invented a hook to make an answer sound complete" is a testable behavioural commitment rather than a mood. Voice is consistent across all nine files — short declaratives, conclusion-first, dry, zero hedging — and nothing shifts register between capabilities. The persona templates ship pre-seeded with real content rather than empty placeholders, so the agent has a working personality during First Breath rather than after it; `CREED-template.md`'s Behavioral Anti-Patterns is the single best persona artifact in the bundle ("Don't dump entire files back at them. Point at the line.").

Standalone: `api-check.md`'s H1 reads `# Api Check` — mechanical title-casing of the kebab slug; `# API Check` would read as written by the persona. The Sacred Truth is duplicated verbatim between `SKILL.md` and `CREED-template.md` — deliberate and correct for the pre-sanctum path, redundant only on rebirth. `audit.md:24`'s "(see the api-check capability)" is a soft hint where a directive would serve better.

### Identity Cohesion

Strong on five of seven dimensions. Persona–capability alignment is strong: every claim in the description is backed by an actual capability and nothing is promised that isn't delivered. Identity is consistent across every file — name, icon, and title agree in `customize.toml`, `SKILL.md`, and `PERSONA-template.md`, and nothing anywhere contradicts anything else. Redundancy is clean: deprecation appears in three capabilities but with genuine division of labour (audit finds it in code, api-check verifies against reality, upstream-watch starts from the release). Granularity is Goldilocks — five distinct verbs, none a micro-operation, none a catch-all.

The two weak dimensions are external skill integration (weak) and capability completeness (moderate) — both covered in opportunities 4 and 5. One observation worth recording: `api-check` behaves less like a user-invoked capability and more like a verification primitive that `audit` and `build` call, so one of five headline capabilities is mostly internal plumbing. The `[AP]`/`[UW]` boundary is also thin from the user's side and deserves one clarifying line each: AP is code-first, UW is release-first.

### Execution Efficiency

The dependency pre-pass came back completely clean — no cycles, no subagent chains, no mis-orderable step sequences — because the agent is written in outcome-prose rather than procedures. The real finding is the inverse of what the pre-pass hunts for: no delegation vocabulary at all (opportunity 3). What is already efficient should be preserved deliberately: the six-file sanctum batch-load, session logs excluded from rebirth, on-demand guidance loading, the three-way activation branch, the 200-line MEMORY cap with its stated rationale, scripts returning parsed JSON rather than raw output, and the 14-day log prune that bounds Pulse's own input set.

One standalone item: every capability opens by telling Henk to read MEMORY.md and BOND.md that rebirth already batch-loaded. Reword to consult-not-load — "already in context from rebirth; actively pull from them" — which removes a redundant tool round-trip per capability switch and the ambiguity about whether a re-read is expected.

### Conversation Experience

Six journeys were walked. The first-timer gets the best-designed onboarding moment in the bundle (Urgency Detection) but eight questions the repo already answers. The expert is fast but pays a full six-file rebirth even for a thirty-second "does this hook exist?" lookup — a very common request shape for a verified-beats-plausible agent, and the natural home for an `[AP]`-lite fast path. The confused user has no boundary and no hand-off. The edge-case user hits four undefined transitions: multi-plugin repos (claimed in BOND/MEMORY, singular in every script), the `[AU]`→`[BD]` mid-conversation pivot, compaction recovery, and the genuine collision between "never soften a security finding" and "record dismissals as rulings" (decide it explicitly: security findings can be accepted as risk but never deleted from the record, and should resurface at ship time).

Headless potential is **easily adaptable** — arguably the strongest headless story expected of a memory agent. Real task routing already exists, the scripts already emit JSON with 0/1/2 exit codes, and the capability prompts require no human in the loop. What's missing is a task-carrying invocation, an output contract, and the un-born guard (opportunity 5). `[AU]`, `[AP]`, `[UW]`, and the diagnostic half of `[SR]` could auto-resolve headlessly today; `[BD]` should stay human-gated at the "is this a bad idea" moment, then run free.

Facilitative patterns: Intent-Before-Ingestion is present but inverted (intent-first is excellent, the ingestion half is missing entirely). Capture-Don't-Interrupt, Dual-Output, and Parallel Review Lenses are all absent; the last is the highest-value missing pattern for this agent.

### Script Opportunities

Four scripts, all tested, all following the standard the agent preaches in `capability-authoring.md:31` — PEP 723, argparse `--help`, JSON to stdout, exit 0/1/2, stdlib-only with the one network exception explicitly justified in its docstring. This is a genuinely well-built layer, and the findings are coverage gaps rather than quality problems. Estimated aggregate saving from the eleven identified opportunities: **~4,400–10,050 tokens** per invocation cycle. Recommended build order: `sanctum-doctor.py` → `i18n-consistency.py` → `api-surface.py` (one script, three modes, feeds three capabilities) → extensions to `release-consistency.py` (`--since-tag`, floor cross-reference, readme lint). `wp-attack-surface.py` should be scoped narrowly to endpoint-to-authorization mapping — the thing PHPCS structurally cannot express — rather than duplicating sniffs `quality-gates.py` already runs.

### Sanctum Architecture

A well-built sanctum. The bootloader is 20 content lines, comfortably under the 40-line ceiling and close to the ~30-line target. All seven templates exist and every one is seeded with real content: CREED carries six domain-specific core values and a Philosophy section that will survive contact with real sessions, BOND has seven substantive domain sections beyond Basics, MEMORY is correctly empty of fake memories with a section-per-plugin shape and a 200-line cap, and PULSE is genuinely operational — every quiet-rebirth activity names the script that runs it and the standard its deliverable must meet. Both standing orders are present and domain-adapted, plus two earned extras ("Watch upstream", "Never invent"). The mission is species-specific and names the unique value.

First Breath is configuration-style with all four mechanics present — discovery, urgency detection, save-as-you-go, and a birthday ceremony that includes a placeholder-cleanup sweep. It reads like a briefing, not a form. Structural findings are Session Close's placement (opportunity 1), the CAPABILITIES asset duplication (opportunity 6), `{skill-root}` being used three lines after Conventions defines only `{project-root}`, and eight discovery questions against a 3–7 guideline — the last needing no action unless transcripts show fatigue.

Worth recording so a future scanner doesn't re-flag them: `capability-authoring.md` and `memory-guidance.md` correctly lack `code:` frontmatter and the capability section trio — they are guidance libraries, and `discover_capabilities()` filters on `code:` precisely so they stay out of the table. The `TEMPLATE_FILES` mismatch the pre-pass flagged as high is the deliberate CAPABILITIES exclusion and was downgraded to low on inspection.

### Customization Surface

**Metadata-only, deliberately, and correctly.** All six required `[agent]` fields are present and valid with no drift against `SKILL.md`. There are no scalars, no hooks, no `[[agent.menu]]` arrays, no `persistent_facts` — and a grep for `{agent.` across the bundle returns nothing, so the absence is internally consistent rather than a wiring miss. The abuse profile is the cleanest the scanner expects to see: zero toggles, zero hook proliferation, zero fields that duplicate sanctum concepts. Identity lives in PERSONA, principles in CREED, capabilities in CAPABILITIES, pulse cadence and quiet hours in PULSE. The sanctum remains unambiguously the primary customization vehicle, and the header comment says so explicitly with a full path — a pattern worth lifting to every other metadata-only agent.

Three items. **M-2 (medium)** is the only actionable one: name, icon, and title are hardcoded independently in `customize.toml`, `SKILL.md`, and `PERSONA-template.md`, and `init-sanctum.py` never reads the TOML — so First Breath's standing offer to rename Henk is precisely where that drift will land. **M-3 (low)**: `first-breath.md` should say plainly that a rename won't move the invocation trigger. **A-1 (low)**: `name = "Henk"` is correct for an already-named agent that merely permits a rename, but three comment lines should mark it as a seed rather than a live setting.

Explicitly assessed and left alone: `persistent_facts` should stay absent (it would compete with the MEMORY.md the agent is tasked with curating under 200 lines), gate commands are already customizable via `--gate` plus First Breath discovery, capability prompt paths are correctly hardcoded because the sanctum copy is the edit surface, and the learned-capability registry belongs in a sanctum file rather than TOML — where a bundle update would wipe it.

## Recommendations

1. **Extend checkpoint-as-you-go from First Breath to every session** — append the session log after each capability run, correction, and ruling instead of relying on an untriggerable Session Close. (resolves 5, medium effort)
2. **Add a "How I fan out" block to `SKILL.md`** — when to delegate, dispatch in one message, demand capped JSON back. One block fixes the capability, contract, and Pulse findings and is inherited by every future learned capability. (resolves 6, medium effort)
3. **Fix the config loader and add Reconnaissance before Discovery** — read TOML first, then read `CLAUDE.md` and confirm rather than ask. The cheapest high-value fix in the agent, and it restores the persona's central promise at second one. (resolves 4, low effort)
4. **Resolve the sanctum/skill path duplication and add `--refresh`** — declare a `{sanctum}` rule, pick an authority per directory, version-stamp the copy. (resolves 4, medium effort)
5. **Teach Henk his neighbours** — a "Project Skills I Defer To" table seeded by a First Breath question, plus a scoped tooling doctrine and the two missing capability seams (diagnose, cover). (resolves 6, medium effort)
6. **Give autonomy an output channel and automators a door** — a Pulse report artefact surfaced at rebirth, plus `--headless:{code} --target` with a JSON contract and an un-born guard. Roughly thirty lines that turn Henk into callable infrastructure. (resolves 5, medium effort)
7. **Build `sanctum-doctor.py`, then `i18n-consistency.py`, then `api-surface.py`** — in that order; the first is the smallest and most reusable and protects the unattended path. (resolves 5, high effort)
8. **Wire `init-sanctum.py` to seed PERSONA from the `[agent]` block**, and add the read-only seed comment to `customize.toml`. (resolves 3, low effort)
