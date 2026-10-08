# Execution Efficiency Analysis — bmad-agent-wp (Henk)

**Scanner:** L3 — Execution Efficiency
**Target:** `.claude/skills/bmad-agent-wp`
**Pre-pass:** `execution-deps-prepass.json` — status `pass`, 0 issues, empty dependency graph, no cycles, no sequential patterns, no subagent-chain violations.

## Assessment

Structurally this agent is clean: the pre-pass found no dependency cycles, no subagent-from-subagent chains, and no numbered step sequences to mis-order — because the agent is written in outcome-prose rather than procedures, there is very little for a dependency analyser to catch. The real efficiency problem is the opposite of what the pre-pass looks for: **the agent has no delegation or parallelization vocabulary at all.** Across 7 markdown files and 4 scripts, the only occurrence of any batching/parallel/delegation word is the single "Batch-load" in `SKILL.md:35`. Every capability that is inherently multi-file — audit, api-check, upstream-watch — instructs the agent to read the codebase directly into the main context, and the Pulse runs five independent workstreams strictly in sequence in an unattended session where nothing forces serialization.

The memory-loading strategy, by contrast, is correct and should not be touched: 6 sanctum identity files batch-loaded on rebirth, session logs explicitly excluded, capability references and `memory-guidance.md` loaded on demand.

## Key Findings

### 1. Capabilities read whole codebases in the parent context — no delegation guidance anywhere

**Severity: High**
**Files:** `references/audit.md:28`, `references/api-check.md:17`, `references/upstream-watch.md:25`, `references/build.md:17`

Current pattern — the agent is told to do all the reading itself:

- `audit.md:28` — "Read the code before judging it. Follow the actual execution paths — how a request reaches this function…"
- `api-check.md:17` — "Confirm from the actual sources available to you — the installed core and plugin sources in the project (`wp-content`, `vendor`, a wp-env container)…"
- `upstream-watch.md:25` — "Then do the part that matters: search the plugin code for each one."

In the host project this agent was built for, that means ~28 plugin classes plus WooCommerce/core sources. A full audit or api-check pass pulls tens of thousands of tokens of source into the parent context before a single finding is produced, and the context is then too degraded for the synthesis step that actually matters (ranking by consequence, deciding what blocks a release).

Efficient alternative — add an explicit delegation paragraph to `audit.md`, `api-check.md`, and `upstream-watch.md` along the lines of:

> When the surface is more than a few files, do not read them yourself. Split by subsystem or by class and dispatch one subagent per slice **in a single message** so they run concurrently. Give each one the hunt list from this capability, the project's floors from MEMORY.md, and require it to return ONLY a JSON array of `{file, line, severity, claim, execution_path}` — no prose, no code quotation, under 400 tokens. You read the returned findings, not the code. Reserve direct reads for the handful of files a finding must be confirmed in.

`build.md:17` ("Read the surrounding code first") is correctly a parent-side read — the builder needs the conventions in working context — so it should be exempted explicitly rather than swept into the same rule.

Estimated saving: 60–85% of parent context on a multi-class audit; wall-clock roughly linear in the number of parallel slices.

### 2. No subagent output-format contract exists

**Severity: Medium (becomes High the moment finding 1 is implemented)**
**Files:** all of `references/*.md`

There is no "ONLY return", no token budget, no JSON schema, and no return-format language anywhere in the skill. If an owner (or the agent itself, under `capability-authoring.md`) starts delegating, subagents will return unbounded prose and hand back most of the context saving. `references/capability-authoring.md:53-58` prescribes the four sections a new capability must have — What Success Looks Like, Your Approach, Memory Integration, After the Session — and none of them cover delegation or return format, so every future learned capability inherits the gap.

Fix: add a fifth optional section to `capability-authoring.md` ("Delegation — when this fans out, and the exact JSON each subagent returns"), and put a concrete return schema in each of the three fan-out capabilities.

### 3. Pulse runs five independent workstreams sequentially

**Severity: Medium**
**File:** `assets/PULSE-template.md:7-37`

"work through these in priority order" then: Memory Curation (19) → Upstream Watch (23-25) → Rot Audit (27-29) → Ship-Readiness (31-33) → Self-Improvement (35-37).

Only two of these share inputs. Memory Curation and Self-Improvement both consume `sessions/`, and Self-Improvement can only conclude "MEMORY.md was wrong" after curation has run — a genuine dependency. Upstream Watch, Rot Audit, and Ship-Readiness Check are mutually independent: different scripts, different files, no shared state. In an unattended `--headless` run — the one context where nobody is waiting on interactive turns and long-running background work is free — running them one after another is pure wall-clock waste, and each one's raw material (release notes, one swept subsystem, a consistency report) lands in the same context.

Efficient alternative: state the two stages explicitly.

```
Stage 1 (parallel, one message): Upstream Watch | Rot Audit | Ship-Readiness
  — each as its own subagent, each returning only its findings block
Stage 2 (after Stage 1 lands): Memory Curation → Self-Improvement
  — parent-side, needs the Stage 1 results plus sessions/
```

Caveat worth writing into the file: this only holds when Pulse is the top-level session. If `--headless` is itself invoked as a subagent, the fan-out is not available and it must run serially — the agent should be told to check rather than fail.

Estimated saving: roughly 3× wall clock on the independent stage; the Rot Audit's swept source never touches parent context.

### 4. Every capability re-reads MEMORY.md and BOND.md that rebirth already loaded

**Severity: Medium**
**Files:** `references/audit.md:36`, `references/build.md:27`, `references/ship.md:29`, `references/api-check.md:30`, `references/upstream-watch.md:31`, plus `SKILL.md:35`

`SKILL.md:35` batch-loads `MEMORY.md` and `BOND.md` on every rebirth. Then all five capabilities open with an imperative to fetch them again — "Check MEMORY.md and BOND.md before you start" (audit), "Read both and match them" (build), "Read it before you start" (ship), "MEMORY.md carries…" (api-check/upstream-watch). Read as written, that is 2 redundant file reads per capability invocation, and in a session where the owner switches between capabilities it compounds.

Efficient alternative: reword to consult-not-load — "MEMORY.md and BOND.md are already in context from rebirth; before you start, actively pull from them: …". Only `references/memory-guidance.md` (correctly not loaded at startup) should carry a read instruction.

Estimated saving: small in tokens (a few thousand per switch) but it removes a redundant tool round-trip per capability and removes the ambiguity about whether a *re-read* is expected because something changed mid-session.

### 5. `quality-gates.py` runs independent gates sequentially

**Severity: Medium**
**File:** `scripts/quality-gates.py:148-152`

```python
results = []
for name, command in gates.items():
    ...
    results.append(run_gate(name, command, project_root, args.timeout))
```

The three default gates (tests, static analysis, code style) are independent read-only subprocesses on the same tree. On the host project that's `pest` + `phpstan analyse` + `phpcs` — phpstan alone is typically the long pole, and the wall clock is currently the sum rather than the max. This script is invoked from `audit.md:32`, `build.md:21`, `ship.md:23`, and the Pulse — it is the single most-run piece of tooling in the agent.

Efficient alternative: `concurrent.futures.ThreadPoolExecutor` over `gates.items()` (the work is subprocess-bound, so threads suffice and the stdlib-only constraint in `capability-authoring.md:31` is preserved), collecting into a dict keyed by gate name to keep output ordering deterministic. Keep a `--serial` escape hatch for gates that turn out to contend (a shared test DB, a wp-env container).

Estimated saving: roughly (sum − max) of gate runtimes on every audit, build, ship, and Pulse run — commonly 40–60% of the gate wall clock.

### 6. `upstream-versions.py` fetches two independent APIs sequentially, and re-fetches per plugin

**Severity: Medium**
**File:** `scripts/upstream-versions.py:160-168`, and `assets/PULSE-template.md:25`

Two issues in one script:

a) The WordPress.org core-offers call and the WooCommerce plugin-info call are made in a serial `for` loop with a 15s timeout each (`--timeout` default). Two unrelated hosts, no ordering requirement — worst case 30s of blocking where 15s would do.

b) The CLI takes a single `plugin_dir` (`upstream-versions.py:133`), while `PULSE-template.md:25` says "Run `scripts/upstream-versions.py` against the plugin**s** in MEMORY.md". For an owner with more than one plugin that is N subprocess launches, each independently hitting the same two upstream APIs for the same two answers. The upstream half of the work is per-run; only the declared-headers half is per-plugin.

Efficient alternative: accept `plugin_dir` as `nargs="+"`, fetch upstream once, and emit a `{"upstream": {...}, "plugins": [...]}` report; run the two fetches concurrently with a small `ThreadPoolExecutor(2)`. Both changes are stdlib-only.

Estimated saving: halves the network block on a single-plugin run; on N plugins, drops 2N network calls to 2 and N process launches to 1.

### 7. Ship serializes two independent scripts on a presentational "first"

**Severity: Low**
**File:** `references/ship.md:15` and `:23`

"Run `scripts/release-consistency.py` **first** and read the parsed report…" then, four bullets later, "Are the gates actually green? Run `scripts/quality-gates.py`." Nothing in the consistency report changes how the gates are run or interpreted — the ordering is rhetorical, not causal, but "first" reads as a hard dependency. Both should be dispatched in one message as two concurrent Bash calls, then interpreted together.

Estimated saving: one script's full runtime per ship run (the gates run is the long one).

### 8. Post-birth path duplication invites reading the same reference twice

**Severity: Low**
**Files:** `SKILL.md:26-27`, `scripts/init-sanctum.py:264-271`, `assets/INDEX-template.md:15-16`

`init-sanctum.py` copies `references/` and `scripts/` into the sanctum at birth, and the generated CAPABILITIES table points at `references/{name}.md` with `sanctum_refs_path = "references"` — which is byte-identical to the bare skill-root path that `SKILL.md:26` defines ("Bare paths resolve from the skill root"). After birth two copies of every capability prompt exist under indistinguishable references. The correctness angle belongs to the structure scanner; the efficiency angle is that an agent unsure which copy is authoritative will read both, doubling every capability load, and any drift between them costs a reconciliation read on top.

Fix: disambiguate the generated table (`sanctum/references/...` vs `references/...`) or stop copying references into the sanctum and copy only the scripts.

## Optimization Opportunities

**Add a "How I fan out" section to SKILL.md.** The single highest-leverage change. One short block in the activation file — when to delegate (more than ~3 files of reading), how to dispatch (one message, N subagents), what to demand back (JSON, capped, "return ONLY") — would fix findings 1, 2, and 3 at once and would be inherited by every learned capability rather than needing to be re-stated in each. Impact: this is the difference between an agent that can audit a 28-class plugin and one that runs out of usable context halfway through.

**Make the Pulse a background-first design.** `--headless` is the ideal place for long-running background subagents: nobody is waiting, no clarification is possible mid-run anyway, and results are written to the sanctum rather than returned conversationally. Consider having Stage 1 write per-workstream JSON to a scratch file that Stage 2's curation consumes — this is the "large results → temp files" aggregation pattern, and it keeps the Pulse's context free for the curation judgment that determines how smart the next rebirth is.

**Concurrency pass over the two long-running scripts.** `quality-gates.py` and `upstream-versions.py` are both trivially parallelizable with stdlib threads and are the two scripts on every hot path. Combined, this is the cheapest wall-clock win in the skill — perhaps 30 lines of change across two files.

## What's Already Efficient

- **`SKILL.md:35` — the 6-file sanctum batch-load is correct and should be preserved.** It is explicitly a batch, and for a memory agent these files *are* the identity; this is not "loading all memory unnecessarily."
- **Session logs excluded from rebirth** (`memory-guidance.md:38`, `assets/INDEX-template.md:12`). Raw material stays out of startup context and is only touched during Pulse curation. Textbook.
- **On-demand guidance loading.** `memory-guidance.md` loads at session close and during Pulse (`SKILL.md:41`, `PULSE-template.md:7`); `capability-authoring.md` loads only when a capability is being created (`CAPABILITIES-template.md:26`); `first-breath.md` loads only on the no-sanctum branch (`SKILL.md:33`). None of these sit in the startup path.
- **Three-way activation branch** (`SKILL.md:33-35`). First Breath, Quiet Rebirth, and Rebirth each load only their own path's files — the headless branch notably loads `PULSE.md` and skips the full identity batch.
- **Hard 200-line cap on MEMORY.md** (`memory-guidance.md:87`, `PULSE-template.md:17`) with an explicit rationale ("Your sanctum loads every session. Every token costs context space for the actual work"). This is the right instinct expressed as an enforceable number, and it is the reason the batch-load stays cheap.
- **Scripts return parsed JSON, not raw output** (`audit.md:32` "read the parsed result rather than eyeballing the whole codebase"; `ship.md:15` "read the parsed report rather than opening files one at a time"). This is deterministic-work-to-scripts done properly and already avoids a large class of context bloat.
- **Prune-logs-at-14-days** (`memory-guidance.md:58`) bounds the Pulse's own input set so curation cost does not grow without limit.
- **First Breath's "Save As You Go"** (`first-breath.md:18`) trades write efficiency for durability, and says why. That is a deliberate, correctly-justified inefficiency — do not "optimize" it into a single end-of-session write.
