# L5 — Enhancement Opportunities (DreamBot)

**Target:** `.claude/skills/bmad-agent-wp` (Henk, WordPress/WooCommerce plugin engineer)
**Type:** autonomous memory agent, evolvable capabilities, configuration-style First Breath
**Scan date:** 2026-08-18
**Nature of this scan:** purely advisory. Nothing below is a defect. Everything below is an opportunity.

---

## Agent Understanding

Henk is an autonomous memory agent built to be the resident WordPress/WooCommerce plugin engineer for one owner and their plugin(s). He carries five built-in capabilities — audit `[AU]`, build `[BD]`, api-check `[AP]`, ship `[SR]`, upstream-watch `[UW]` — three deterministic Python tools (quality gates, release consistency, upstream version diff), a sanctum in `_bmad/memory/bmad-agent-wp/`, and a PULSE that lets him work unattended. His defining conviction is "verified beats plausible": the one unforgivable failure is inventing an API that sounds right.

His primary user is a solo/small-team plugin maintainer who ships to WordPress.org and lives inside the release pipeline. The three biggest assumptions baked into the design are: (1) that the owner works **one plugin, one repo, interactively**; (2) that a session has a recognisable **end** at which memory gets written; and (3) that `_bmad/config.yaml` **exists**. In this very project, assumption (3) is already false.

---

## User Journeys

### The first-timer — "I've never used a memory agent"

They type "talk to Henk". `init-sanctum.py` fires, First Breath begins. The Urgency Detection block is genuinely excellent — if their first message is a real bug, Henk works the bug and defers the questionnaire. That is the single best design decision in the onboarding.

**Friction:** the First Breath discovery list is eight substantial questions (repo layout, floors, gates, release procedure, house rules, bluntness, current fires, tooling), and the file simultaneously instructs "keep it short — you are a working engineer being briefed, not a chatbot doing onboarding". Those two instructions fight each other. In a repo like this one — where `CLAUDE.md` already answers questions 1, 2, 3, 4 and 5 in full, and `.claude/rules/*.md` adds more — asking the owner to type out what is already written down two directories away reads as not having looked.

**Bright spot:** "Your Capabilities" explicitly tells the owner they can modify, remove, or teach capabilities. Most memory agents forget to say the evolvability out loud.

**Dead-end risk:** nothing tells the first-timer what the two-letter codes are *for* after First Breath ends. `CAPABILITIES.md` lists `[AU]`/`[BD]`/`[AP]`/`[SR]`/`[UW]`, and `capability-authoring.md` ends with "Trigger it with [{code}]" — but no rebirth greeting is required to surface them, and `SKILL.md` never states that a code (or an intent) should cause the corresponding capability prompt to be loaded before work starts.

### The expert — "I know exactly what I want"

Jos types "Henk, audit the sync service". Rebirth batch-loads six sanctum files, greets, and gets to work. Fast, good.

**Friction:** there is no fast-path. Every session pays the full six-file load even for a thirty-second question ("does `woocommerce_before_calculate_totals` fire on the cart page?"). For a `verified-beats-plausible` agent, the one-question lookup is a *very* common shape of request, and it currently costs a full rebirth. There is also no way to say "skip the greeting, just answer".

**Friction:** the expert who has already decided ("just make the change, I know it's right") still gets the CREED-mandated push-back pass in `build.md`. That is usually correct behaviour and occasionally the thing that makes an expert stop using an agent. There is no "you already argued this one, I'm building it" acknowledgement path.

### The confused user — invoked by accident, or with the wrong intent

Someone asks Henk to fix a theme, write a Gutenberg block, or debug a JS bundle. Nothing in `SKILL.md`, `CREED.md`, or any capability says what Henk does when the request is outside plugin engineering. The persona will improvise — probably fine, probably blunt — but there is no defined boundary and no hand-off ("that's front-end; the `ui` skill or Sally would serve you better"). This project has a rich installed skill roster (`bmad-review`, `bmad-build`, `release`, `quality`, `api-check`, `add-tests`, `sync-debug`); Henk knows about none of it.

**Overlap worth naming:** this repo already ships slash-command skills named `release`, `quality`, and `api-check` that do narrower versions of `[SR]`, the gate runner, and `[AP]`. A confused user will not know which to reach for, and Henk cannot tell them.

### The edge-case user — technically valid, unexpected

- **Multi-plugin repo.** `MEMORY.md` is structured per plugin and `BOND.md` says "which plugins they own" (plural). But `release-consistency.py` and `upstream-versions.py` each take exactly one plugin dir, and no capability says how to iterate. PULSE's Upstream Watch says "run against the plugins in MEMORY.md" — one script invocation per plugin, undefined ordering, undefined aggregate report.
- **Mid-conversation pivot.** Owner starts an audit, three findings in decides to just fix one. Nothing covers the `[AU]` → `[BD]` handoff, or whether the un-finished audit findings survive. They probably evaporate.
- **Context compaction.** Long audit of a 3,000-line class. Compaction drops the sanctum load. Henk keeps working with persona intact (it's in the system context) but potentially without `MEMORY.md`'s rulings — and will happily re-report a finding the owner already dismissed, which `audit.md` explicitly promises never to do. Nothing detects or recovers from this.
- **Owner disagrees with a security finding.** `CREED` says never soften a security finding; `audit.md` says record dismissals as rulings. What happens when the owner dismisses a *security* finding is genuinely undefined — those two rules collide.

### The hostile environment — this is where the sharpest findings are

- **`config.yaml` does not exist in this project.** `SKILL.md` loads `_bmad/config.yaml` / `config.user.yaml`; `init-sanctum.py` parses the same two filenames. This repo stores config in `_bmad/config.toml` and `_bmad/config.user.toml`, where `user_name = "Jos"` and `communication_language = "English"` live. Result: Henk's very first act at birth is to seed `BOND.md` with `**Name:** friend` and greet his owner as "friend" — an agent whose entire pitch is *verified beats plausible* opening with an unverified guess about who he's talking to.
- **`--headless` on a fresh install.** `SKILL.md` routes: no sanctum → First Breath; `--headless` → PULSE. A cron job hitting an un-born agent takes branch 1 and starts an interactive interview with nobody there. It will hang or invent answers.
- **No network.** `upstream-versions.py` degrades honestly (`--offline`, unknown upstream). Good. But PULSE's Upstream Watch has no instruction for what to do with a run where the gap could not be established — record nothing? retry next pulse? The state file will silently show no progress.
- **No `uv`, no `vendor/bin`.** `quality-gates.py` detects gates that exist and skips ones that don't — but a project where `vendor/` was never installed produces "zero gates ran", which is structurally indistinguishable from "all gates passed" unless the agent reads carefully. `ship.md` promises "the gates are green"; an empty gate set should never satisfy that promise.
- **Sanctum/skill drift.** `init-sanctum.py` copies `references/` and `scripts/` into the sanctum so it is "fully self-contained", but `SKILL.md`'s conventions say bare paths resolve from the *skill root*, and `audit.md`/`ship.md`/`PULSE.md` reference `scripts/*.py` bare. After a skill update, two divergent copies exist and it is genuinely ambiguous which one runs. If the owner tunes a capability prompt in their sanctum (the customize.toml explicitly tells them the sanctum *is* the customization surface), those edits may never be read.

### The automator — cron, CI, or another agent

`PULSE.md` has real task routing (`--headless:memory|upstream|audit|ship`), which puts Henk ahead of most HITL agents. But the automator that wants **"audit this diff and give me JSON"** has nowhere to go: `--headless` means "run the pulse", not "run this task". There is no way to pass a target (a file, a PR, a plugin dir), no defined output contract, no exit code, and no "where do I find the report" answer. CI cannot use Henk today except as a scheduled sweeper.

---

## Headless Assessment

**Level: Easily adaptable** — arguably the strongest headless story of any memory agent I'd expect to scan, held back by two gaps.

What already works: PULSE with four sub-tasks; three deterministic scripts that already emit JSON to stdout, diagnostics to stderr, and 0/1/2 exit codes; capability prompts written outcome-first rather than as interactive scripts (nothing in `audit.md` or `api-check.md` requires a human in the loop).

What's missing for true headless:

| Gap | Suggested shape |
| --- | --- |
| No task-carrying headless invocation | `--headless:audit --target plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php` — capability code plus a target, routed straight to the capability prompt, no greeting, no questions |
| No output contract | Every headless run writes a report to `sanctum/reports/{date}-{code}.md` and prints a one-line JSON `{status, findings, report_path}` to stdout. Exit 0 clean / 1 findings / 2 error |
| Un-born + headless hangs | Add a guard to the activation routing: `--headless` with no sanctum → run `init-sanctum.py`, seed from config, emit "born headless, First Breath deferred — sanctum is unpersonalized", proceed with defaults. Never interview |
| Confirmation points not marked | `build.md`'s "push back before building" and `ship.md`'s "let your owner pull the trigger" are the only true HITL gates. Mark them explicitly as `if headless: report and stop` so the rest can auto-resolve |

Capabilities that could auto-resolve headlessly today: `[AU]` audit, `[AP]` api-check, `[UW]` upstream-watch, and the diagnostic half of `[SR]` ship. `[BD]` build should stay human-gated at the "is this a bad idea" moment, then run free.

---

## Key Findings

### HIGH-OPPORTUNITY

**H1 — Config format mismatch makes birth start with a guess.**
*Area:* `SKILL.md` On Activation, `scripts/init-sanctum.py`.
Both look for `config.yaml`/`config.user.yaml`; this project (and, plausibly, every current BMad install) uses `config.toml`/`config.user.toml`. `user_name` falls back to `"friend"`, `communication_language` to `"English"` by luck.
*Suggestion:* teach `init-sanctum.py` to read both, TOML first (`tomllib` is stdlib on 3.11+; the PEP 723 block already requires ≥3.10, so bump to ≥3.11 or fall back to a line parser). Update `SKILL.md`'s activation line to name both. Cheapest high-value fix in the whole agent.

**H2 — No capability routing instruction anywhere in `SKILL.md`.**
`SKILL.md` describes activation and session close, but never says: *when the owner asks for work, match it to a capability and load that capability prompt before starting.* `CAPABILITIES.md` is a table of file paths with no imperative attached. The risk is that Henk improvises an audit from persona alone and never reads the 40 lines of hard-won hunting guidance in `audit.md`.
*Suggestion:* add a short "Doing Work" section to `SKILL.md`: match intent or `[CODE]` to a row in `CAPABILITIES.md`, load that prompt, then work. If the request matches nothing, say so and offer the closest capability or the teach-me-a-capability path.

**H3 — Session Close has no trigger, so memory is written by luck.**
"Before ending any session…" assumes the agent can detect an ending. It cannot. Sessions end by the owner closing the terminal, by compaction, or by context exhaustion — and in all three cases the sanctum write never happens and the session is lost. This is the single biggest threat to a memory agent's actual value.
*Suggestion:* replace end-of-session writing with **checkpoint-as-you-go**, exactly as First Breath already does ("Save As You Go" is the right pattern and it's right there in the same skill). Write the session-log entry after each completed capability run, each correction, each ruling — append, don't buffer. Additionally recognise explicit close phrases ("that's it for today", "wrap up") and, on compaction recovery, re-read the sanctum before continuing.

**H4 — First Breath asks what the repo already answers.**
`CLAUDE.md` in this project supplies the plugin layout, floors, gate commands, release procedure, meta keys, and house rules. `.claude/rules/*.md` adds three more technical references. Asking the owner to recite all of it is friction that a "read the code before judging it" agent should be embarrassed by.
*Suggestion:* insert a Reconnaissance step before Discovery — read `CLAUDE.md`/`AGENTS.md`/`.claude/rules/`/`readme.txt`/plugin headers/`composer.json`, then **confirm rather than ask**: "I read your CLAUDE.md. Plugin at `plugin/skwirrel-pim-sync/`, gates are pest + phpstan + phpcs before every commit, releases tag `X.Y.Z` with readme changelog verification. Three things it doesn't tell me: how blunt you want me, what's currently on fire, and what tooling I can use to verify instead of guess." Eight questions becomes three, and the first impression is competence instead of a form. This also directly implements **Intent-Before-Ingestion** correctly (intent already established by the greeting, ingestion targeted).

**H5 — Headless with no sanctum starts an interview with nobody.**
See the automator journey and the headless table. Guard the routing.

**H6 — The automator has no door.**
No task-carrying headless mode, no output contract. Add `--headless:{code}` with an optional `--target`, and a report file + JSON stdout contract. This turns Henk from "an agent Jos talks to" into "an agent the CI pipeline and other skills call", which is a category change in value.

### MEDIUM-OPPORTUNITY

**M1 — Sanctum/skill path ambiguity.**
Bare `references/…` and `scripts/…` paths resolve from the skill root per `SKILL.md` conventions, but `init-sanctum.py` deliberately duplicates both into the sanctum for self-containment, and `customize.toml` tells owners the sanctum is where they customize. Which copy wins is undefined, and a skill upgrade silently leaves the sanctum copies stale.
*Suggestion:* pick one and state it explicitly. Cleanest: **sanctum wins for capability prompts** (that's where evolution happens), **skill root wins for scripts** (those get bug fixes), and add a version stamp to the sanctum so rebirth can notice "the skill moved on since your copy was made" and offer to refresh.

**M2 — Henk doesn't know his neighbours.**
This repo has `release`, `quality`, `api-check`, `add-tests`, `sync-debug`, `bmad-review`, `bmad-build`, `bmad-spec` installed — several overlapping his capabilities, several complementing them (`bmad-review`'s lens set is a genuinely good pairing for `[AU]`).
*Suggestion:* add a "Neighbours" section to `CAPABILITIES.md` under Tools, discovered at First Breath by listing installed skills, so Henk can say "the `add-tests` skill already does that better" or "want me to run `bmad-review`'s edge-case lens over this before I sign off?" Unexpected-connection delight at near-zero cost.

**M3 — No parallel review lenses before a finding list ships.**
`audit.md` produces a list from one perspective. The BMad house pattern — fan out two or three review subagents and reconcile — would catch the finding Henk's own priors miss, and is precisely the "fresh eyes see what habit misses" idea already stated in the Sacred Truth but never operationalised.
*Suggestion:* in `[AU]` and `[SR]`, optionally fan out a skeptic lens ("which of these is a style opinion in disguise?") and an omission lens ("what execution path did he not follow?") before presenting. Degrade to sequential self-review when subagents are unavailable.

**M4 — `[UW]` finds the breakage; nobody is assigned to fix it.**
Upstream Watch reports "you call it in three places, here they are, here's the replacement" — and then stops. `[BD]` build exists but there is no bridge, and `Deferred Deprecations` in MEMORY.md can accumulate indefinitely with no escalation mechanic beyond "raise it as the removal version approaches".
*Suggestion:* give `[UW]` an explicit handoff line ("want me to fix these now? that's `[BD]`") and give deferred deprecations a *dated* form in MEMORY.md (`removal in WC 11.0 — due before 2026-11`) so PULSE can escalate deterministically rather than by vibe.

**M5 — Empty gate set reads as green.**
`quality-gates.py` skipping undetected gates is right; `ship.md`'s "are the gates actually green?" must distinguish *passed* from *not run*.
*Suggestion:* have the script emit an explicit `"gates_run": 0` / `"skipped": [...]` and have `ship.md` treat zero gates run as a blocking unknown, never a pass.

**M6 — No mid-flight abandonment path.**
Owner walks away mid-audit. Nothing preserves the partial finding list. With H3's checkpointing this mostly solves itself, but the capability prompts should also say: when interrupted, write what you have to the session log with a `**Incomplete:**` marker so next session can resume rather than restart.

**M7 — Multi-plugin is claimed but not designed.**
`BOND.md`/`MEMORY.md` assume plural plugins; scripts and capabilities assume singular.
*Suggestion:* record a plugin registry in `MEMORY.md` (path per plugin) and have PULSE loop it, aggregating one report. Cheap now, expensive to retrofit after the owner adds their second plugin.

**M8 — No out-of-scope boundary.**
Define, in one line in `CREED.md` or `SKILL.md`, what Henk *doesn't* do and who to send them to. A blunt "that's not mine, ask X" is entirely in character and prevents confident wandering into theme/JS/infra territory where the persona's confidence is unearned.

### LOW-OPPORTUNITY

**L1 — Quiet hours cannot be self-enforced.** `PULSE.md` records them, but Henk has no scheduler; whatever invokes him decides when he runs. Note in First Breath that quiet hours have to be honoured by the cron/`/loop`/`/schedule` setup, and offer to write that schedule.

**L2 — "Surprise and delight" has no volume control.** The standing order to mention the unrelated thing you noticed is great, and one owner in three will find it noise. Add a BOND.md dial for it.

**L3 — No fast-path for a single verification question.** A `[AP]`-lite "just tell me if this hook exists" that skips full rebirth would fit the agent's core value and get used weekly.

**L4 — Security-finding dismissal conflict.** CREED forbids softening a security finding; `audit.md` records dismissals as rulings. Decide explicitly: security findings can be *accepted as risk* but never *deleted from the record*, and should resurface at `[SR]` ship time.

**L5 — No "what did you learn about me" surface.** Owners of memory agents love and distrust the memory in equal measure. A trivial "show me my BOND" / "what do you think you know about this codebase" command builds trust and catches bad memory early.

**L6 — Findings have no persistent artifact.** Audits live in conversation scrollback. A `sanctum/reports/` directory (needed anyway for H6) gives the owner something to come back to tomorrow — directly addressing the Return Value question.

---

## Facilitative Patterns Check

| Pattern | Status | Note |
| --- | --- | --- |
| Soft Gate Elicitation | **Partial** | First Breath says "weave them in, skip what's answered" — the spirit is there, but there is no explicit "anything else, or shall we move on?" at transitions. Low value to add here; this agent's register is terse by design. |
| Intent-Before-Ingestion | **Present, inverted** | Urgency Detection is a strong implementation of intent-first. But the *ingestion* half is missing entirely — Henk never scans the project before asking. See H4; fixing it strengthens both halves. |
| Capture-Don't-Interrupt | **Missing** | During First Breath the owner will volunteer things across all eight topics out of order, plus war stories. Nothing says to capture silently and keep going. Worth one line: "when they hand you something out of order, write it to the right sanctum file and continue — never redirect them." |
| Dual-Output | **Missing** | Audit and upstream-watch outputs are exactly the shape that downstream skills (`bmad-build`, `bmad-spec`, story automation) would consume. A machine-readable findings JSON alongside the prose list would compound with H6. |
| Parallel Review Lenses | **Missing** | See M3. Highest-value missing pattern for this agent. |
| Three-Mode Architecture | **Partial** | Interactive + PULSE headless exist; the missing third is targeted headless (H6). No Yolo/Guided distinction inside a capability, and this agent probably doesn't need one. |
| Graceful Degradation | **Good for scripts, absent for subagents** | `upstream-versions.py --offline` is a model of honest degradation. Once subagents are introduced (M3), give them the same treatment. |

---

## Top Insights

**1. The agent whose creed is "never claim what you haven't verified" opens its life with an unverified guess.** H1 is a two-line fix with outsized symbolic weight: config lives in TOML, Henk reads YAML, and so the first word out of his mouth at birth is "friend" — a name nobody gave him. Fix the loader and the persona's central promise holds from second one.

**2. Memory written at session close is memory written by luck.** First Breath already knows better — "Save As You Go: whatever you've saved is real, whatever you haven't written down is lost forever" is the best paragraph in the skill. That discipline is applied to birth and abandoned for every session after it. Extending checkpoint-as-you-go to normal sessions is the difference between an agent that *has* memory and one that *intends* to have memory.

**3. Henk is two small changes away from being infrastructure rather than a conversation partner.** He already has JSON-emitting scripts with proper exit codes, outcome-first capability prompts that don't need a human, and PULSE task routing. Add a task-carrying headless invocation with a target and a report-file contract, and he becomes callable by CI, by `/schedule`, and by other skills in this repo — an upstream-watch that opens its own findings file every Monday, an audit that runs on every PR. That is a step change in value for perhaps thirty lines of instruction, and no other finding in this report comes close to that ratio.
