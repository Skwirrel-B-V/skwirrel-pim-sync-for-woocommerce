# Prompt Craft Analysis — `bmad-agent-wp` (Henk)

Scanner: L2 (prompt craft) · Target: `.claude/skills/bmad-agent-wp` · Pre-pass: `prompt-metrics-prepass.json` (`is_memory_agent: true`)

---

## Assessment

**Skill type:** Autonomous memory agent (`agent_type = "autonomous"` in `customize.toml:21`), evolvable capabilities, configuration-style First Breath. Five menu capabilities (`audit`, `build`, `api-check`, `ship`, `upstream-watch`) plus three non-menu guidance references (`first-breath`, `memory-guidance`, `capability-authoring`). Three deterministic scripts back the judgment prompts.

**Bootloader quality (SKILL.md, 42 lines / ~690 tokens):** Correct architecture for a memory agent, and well executed. No `## Overview` section — expected and correct here; the identity seed at `SKILL.md:8` does that job. As a seed it is genuinely good: *"A Dutch senior WordPress engineer with too many plugins behind him to be impressed by yours… he has never once invented a hook to make an answer sound complete."* That single sentence encodes vibe, nationality, seniority, tone, and the agent's central failure mode (API hallucination) in one breath. It is evocative and it is behaviourally load-bearing — the anti-invention stance recurs as a hard boundary in CREED and as the raison d'être of `api-check`. This is above-average seed craft.

**Persona context quality:** Strong and coherent across three layers — seed (SKILL.md), sanctum templates (`assets/PERSONA-template.md`, `assets/CREED-template.md`), and capability prompts. The voice does not drift. `CREED-template.md:17-22` (Core Values) and `PERSONA-template.md:13-15` (Communication Style) are pre-seeded with real content rather than placeholder-only, which means the agent has usable personality from minute one of First Breath rather than after the owner fills in blanks. `CREED-template.md:55-60` (Behavioral Anti-Patterns) is the single best persona artifact in the skill: concrete, negative-example-driven, and directly actionable ("Don't dump entire files back at them. Point at the line.").

**Progressive disclosure:** Textbook. 42-line bootloader → sanctum batch-load → capability prompt loaded on demand. Total corpus is 7,369 tokens across 9 files, but no single activation path loads more than a fraction of it. Capability prompts are 32-41 lines each — tight, uniform, no bloat. Nothing belongs in `./references/` that isn't already there; nothing in `./references/` should be inlined.

**Waste:** The pre-pass found zero defensive-padding matches, zero back-references, zero suggestive-loading matches, and zero wall-of-text blocks across all nine files. My read confirms it. There is no "make sure to", no "this agent is designed to", no conversational filler. This is unusually clean.

**Synthesis:** This is a well-crafted agent. The capability prompts are consistently outcome-driven — every one opens with "What Success Looks Like" framed as a described end state plus the failure mode it exists to prevent, then gives judgment and priorities rather than numbered procedure. Intelligence placement is exemplary: deterministic work (version comparison, gate execution, upstream version fetching) is delegated to three Python scripts, and every prompt that touches that work says "run the script and read the parsed result" instead of describing the parsing. The findings below are refinements, not repairs — the highest is a medium about path resolution ambiguity that only bites after First Breath copies files into the sanctum.

---

## Prompt Health Summary

| Metric | Count | Verdict |
|--------|-------|---------|
| Prompt files scanned | 8 (all in `./references/`) | Correct location for a memory agent |
| With `{communication_language}` config header | 0 | **Not a finding.** Language comes from BOND.md (`assets/BOND-template.md:6`) and is explicitly handled at `references/first-breath.md:10`. Per memory-agent rules, not flagged. |
| With progression conditions | 0 | Menu capabilities are single-shot judgment work, not multi-step gated flows. `first-breath.md:71-81` ("Wrapping Up the Birthday") is the one prompt that needs completion criteria, and it has them. Acceptable. |
| Self-contained (survive SKILL.md compaction) | 8 of 8 | No prompt says "as described above" or "per the overview". Each opens with its own H1 and success definition. |
| Frontmatter complete | 5 of 5 menu capabilities carry `code:` | See finding L2-06 — the pre-pass reports `missing_fields: ["menu-code"]` for all eight, which is a **pre-pass false positive**; `audit.md:4`, `build.md:4`, `api-check.md:4`, `ship.md:4`, `upstream-watch.md:4` all declare `code:`. The three guidance files correctly omit it (non-menu). |
| Waste patterns | 0 | Confirmed by read. |

---

## Per-Capability Craft

### `audit` (AU) — `references/audit.md`, 41 lines

**Outcome-driven: yes. Voice-aligned: yes. Best-in-file.**

Line 13 is the sharpest success framing in the skill: *"A good audit is uncomfortable to read and impossible to argue with. A bad one is a checklist of things WordPress plugin tutorials say, applied without checking whether this code actually does them wrong."* That is a quality bar, an anti-pattern, and a personality statement in two sentences.

"What You Are Hunting" (lines 15-24) is six domain-specific attention weights, not a generic OWASP list — `$wpdb->prepare`, HPOS, autoloaded options, `meta_query` on unindexed keys, escaping at print rather than at storage. This is exactly the domain framing the scanner instructions say *not* to flag as waste: an agent without it would produce generic security-review output.

Line 28 — *"A defect you cannot trace a path to is a guess, and you don't report guesses as findings"* — is design rationale that prevents the agent from padding a report, and it echoes the CREED boundary. Load-bearing.

Line 36 (Memory Integration) is the strongest memory instruction in the set: it tells the agent not just to read BOND.md but what to *do* with a prior ruling — `say "still there, still your call" and drop it`. That is behaviour, not a lookup.

No over-specification. No procedure the persona would have figured out.

### `build` (BD) — `references/build.md`, 32 lines

**Outcome-driven: yes. Voice-aligned: yes.**

Line 11 defines success as indistinguishability — *"a reviewer reading the diff cannot tell which parts you wrote"* — which is the right outcome framing for a build capability and neatly forecloses the most common LLM failure (rewriting to its own preferences). Line 17 makes it concrete with a project-shaped example: *"a codebase with a singleton pattern and manual `require_once` gets more of that, not a lecture about autoloaders."* Given this repo's actual architecture (no autoloader, singletons), that is not decoration — it is a pre-emptive correction of the exact advice a generic model would give.

Line 23 ("Push back before building, not after") gives three named examples of WordPress-fighting requests. Domain rationale, keep.

Shortest of the five and slightly thinner on the "what success looks like" side than `audit` — only two paragraphs before Approach. Not a defect; build is the capability where the codebase itself supplies most of the context.

### `api-check` (AP) — `references/api-check.md`, 35 lines

**Outcome-driven: yes. Voice-aligned: yes.**

Line 13 states the failure mode with unusual precision: *"confident code referencing an API that sounds exactly right and does not exist."* This is the agent's founding anxiety and it is stated once, here, where it does work — not repeated across files.

Lines 19-24 give four verification dimensions beyond existence (signature, timing, lifecycle, intent). The timing bullet naming `init` vs `plugins_loaded` vs `woocommerce_init` vs `wp_loaded` is real domain knowledge with no generic substitute. Line 26 (respect declared floors, an API in latest ≠ green light for the project's minimum) is a non-obvious constraint that prevents a plausible wrong answer.

Line 30 (Memory Integration) does something the others don't: it tells the agent to *distrust* part of its own memory — *"re-verify anything version-sensitive — the floor may have moved since you wrote that note."* Correct instinct for a memory agent; worth preserving.

Minor: the H1 is `# Api Check` (line 7) — mechanical title-casing of the kebab slug. Cosmetic, but `# API Check` would read as written by the persona rather than generated.

### `ship` (SR) — `references/ship.md`, 34 lines

**Outcome-driven: yes. Voice-aligned: yes. Best intelligence placement.**

Line 15 is the model example of script/prompt division: run `scripts/release-consistency.py` *first*, read the parsed report, and the prompt then explains **why** the script exists — *"exactly the class of mistake that is invisible to a human and fatal to a deploy."* Then lines 17-23 hand the prompt only what a script cannot check (does the changelog describe reality, do the strings ship, do the declared floors hold, would the directory accept it). This is precisely the boundary the scanner wants: deterministic → script, semantic → prompt. No prompt-based structure validation anywhere.

Line 25 encodes the hard boundary (never tag or push on own initiative) at the point of use, not only in CREED. Correct redundancy — this one is safety-critical and survives compaction.

### `upstream-watch` (UW) — `references/upstream-watch.md`, 36 lines

**Outcome-driven: yes. Voice-aligned: yes.**

Line 11 defines the deliverable by contrast — not "WordPress 7.1 is out" but "removes X, you call it in three places, here they are" — and then names both failure modes: *"Silence and noise are both failures."* Line 25 enforces it: *"An impact report without file and line references is gossip."*

Line 15 handles the degraded case explicitly (script can't reach network → say so, don't guess version numbers), which is exactly the kind of graceful-failure instruction that keeps an autonomous agent honest during unattended Pulse runs.

Line 31 (Memory Integration) correctly identifies that this capability's cost curve depends entirely on recording the last-checked versions. Good economic reasoning, stated as behaviour.

### `first-breath` — `references/first-breath.md`, 82 lines

**Configuration-style, as declared. Appropriate for the domain.**

The eight discovery questions (lines 32-39) are all high-yield and domain-specific — floors, gate commands, release procedure, house rules, bluntness calibration, what's on fire, available tooling. Each one maps to something a later capability actually reads. There is no filler question.

Two craft strengths worth calling out:

- **Urgency Detection (lines 20-22)** — if the owner opens with a real request, serve it first and learn by working. The justification is in persona voice: *"A senior engineer who insists on a questionnaire before touching the problem is not one you'd hire."* This is the single most important instruction in the file and it is placed early enough to actually fire.
- **Save As You Go (lines 16-18)** — write after each answer, not at the end. Correct for an interruptible conversation, and the rationale ("whatever you haven't written down is lost forever") is stated once and stops there.
- Line 28 explicitly forbids the menu-dump failure: *"Don't fire the questions off as a list — weave them in, and skip anything they've already answered."*
- Line 81 closes with the right first-impression instruction: *"The best first impression you can make is finding something real in their code."*

The Sanctum File Destinations table (lines 62-69) is the one table in the file and earns its place — it is a routing map, not prose.

### `memory-guidance` — `references/memory-guidance.md`, 92 lines

**Well-crafted. Concrete where it needs to be.**

The two-tier model (raw session logs → curated MEMORY.md, logs pruned at 14 days) is clearly explained with a real format template (lines 42-54). "What NOT to Remember" (lines 25-32) is stronger than most such lists because every entry has a reason attached — *"Code you can read… Remember why, not what"*, *"Anything git already knows"*, *"Anything CLAUDE.md already states"*. That last one matters in this repo specifically, where CLAUDE.md is very large; without it the agent would duplicate the whole architecture table into MEMORY.md.

Token Discipline (lines 80-87) sets a hard, checkable bound: MEMORY.md under 200 lines, *"If it's longer, you're hoarding, not curating."* Bounded and enforceable.

### `capability-authoring` — `references/capability-authoring.md`, 78 lines

**Appropriate for an evolvable agent. One self-consistency defect (see L2-01).**

Lines 53-58 are the key passage and they are exactly right: *"The body is outcome-focused — what success looks like, not a numbered procedure. Your persona already decides how you work; the capability only needs to say what it achieves."* The agent's own authoring doctrine matches the craft standard this scanner applies, and the five built-in capabilities visibly obey it. That self-consistency is rare and valuable — new capabilities the owner teaches will inherit the same shape.

Line 31 (script standards: PEP 723, stdlib-only, `--help` via argparse, JSON to stdout, exit 0/1/2, no interactive prompts, no hardcoded paths) is project-specific convention, not general knowledge. Keep.

Line 64 (*"half of these turn out to be an existing capability used differently"*) is a good anti-proliferation guard — it directly counteracts the "multiple capability files that could be one" anti-pattern.

---

## Key Findings

### L2-01 · Medium · `references/capability-authoring.md:43-51` vs all five capability prompts — authoring spec and shipped examples disagree

The prompt file format the agent is told to follow declares five frontmatter fields:

```
name / description / code / added / type
```

None of the five built-in capabilities (`audit.md:1-5`, `build.md:1-5`, `api-check.md:1-5`, `ship.md:1-5`, `upstream-watch.md:1-5`) carry `added` or `type`. They carry only `name`, `description`, `code`.

**Why it matters:** The built-ins are the worked examples a learning agent pattern-matches against. When the spec and the examples disagree, the agent will produce inconsistent frontmatter for owner-taught capabilities — some with `type`, some without — and `scripts/init-sanctum.py:119-134` (`discover_capabilities`) reads frontmatter to generate CAPABILITIES.md. Divergent fields are a latent parsing inconsistency, not just a cosmetic one.

**Fix:** Pick one. Either add `added:` and `type: prompt` to the five built-ins, or reduce the spec at `capability-authoring.md:43-51` to the three fields actually used and keep `added`/`type` as the Learned-table columns they already are in `assets/CAPABILITIES-template.md:19`.

### L2-02 · Medium · `SKILL.md:26-27` vs `scripts/init-sanctum.py:264-272` and `assets/INDEX-template.md:14-16` — bare-path resolution is ambiguous after First Breath

`SKILL.md:26` establishes: *"Bare paths (e.g. `references/guide.md`) resolve from the skill root."*

But `init-sanctum.py` copies both `references/` and `scripts/` **into the sanctum** at birth (lines 264-272), `INDEX-template.md:14-16` describes them as living there (*"copied here at birth"*), and `generate_capabilities_md` writes source paths using a bare `references` prefix (`init-sanctum.py:235`, `sanctum_refs_path = "references"`).

So after birth there are two copies of every capability prompt and every script, and the convention line says the skill-root copy wins — which makes the sanctum copies dead weight that will silently drift out of date when the skill is updated. Conversely, if the sanctum copies are the intended runtime targets, the Conventions line is wrong.

The same ambiguity hits every script invocation: `audit.md:32`, `build.md:21`, `ship.md:15`, `ship.md:23`, `upstream-watch.md:15`, and `assets/PULSE-template.md:25,33` all say `scripts/*.py` bare.

**Why it matters:** This is the one place where a compaction-surviving agent could pick the wrong file. A stale sanctum copy of `upstream-versions.py` or `audit.md` would run without erroring — the failure is silent and only visible as "the agent is behaving like an older version of itself."

**Fix:** Decide explicitly and state it in `SKILL.md` Conventions. If the sanctum copies are for continuity-if-the-skill-vanishes, say the skill root is authoritative and the sanctum copy is a fallback. If the sanctum copies are canonical, prefix them (`{sanctum}/references/…`) everywhere and drop the "resolve from skill root" line for these two directories.

### L2-03 · Low · `SKILL.md:20-22` and `assets/CREED-template.md:3-9` — "The Sacred Truth" is duplicated verbatim

The bootloader carries the full Sacred Truth paragraph; `CREED-template.md` opens with the same text (lightly reflowed), and CREED.md is batch-loaded on every rebirth (`SKILL.md:35`). Roughly 90 tokens of exact repetition on every non-first session.

**Why it matters:** Genuine duplication under the "exact repetition" rule — but only partially. The bootloader copy is load-bearing in the **First Breath path**, where no sanctum exists yet and this is the agent's only statement of its own nature. It is redundant only on the rebirth path.

**Fix (optional, low value):** Trim the SKILL.md copy to its two operative sentences — *"Never pretend to remember. Never fake continuity. Read your files or be honest that you don't know."* — and let CREED.md carry the full framing. Do not remove it entirely; the pre-sanctum case needs it.

### L2-04 · Low · `SKILL.md:39-41` — Session Close has no reliable trigger

*"Before ending any session, load `references/memory-guidance.md` and follow its discipline."*

Sessions do not announce their end. This instruction fires only if the agent happens to notice a wind-down, and a memory agent that misses it loses the entire session's learning.

**Why it matters:** For a memory agent this is the highest-consequence instruction in the bootloader — everything the agent knows next session depends on it running. Mitigated (well) by every capability prompt carrying its own "After the Session" section, and by `memory-guidance.md:72-78` ("When to Write" → *"Immediately — when your owner corrects you, states a standard, or rules on a finding"*). The immediate-write discipline is the real safety net; the Session Close hook is best-effort on top.

**Fix:** Add an explicit trigger list rather than relying on "before ending" — e.g. *"when the owner signals they're done, when a capability completes, or when you notice the conversation has moved off the work."* Cheap, and turns one unreliable hook into three.

### L2-05 · Low · `references/audit.md:24` and `references/build.md:19` — soft cross-capability references

Both say *"(see the api-check capability)"*. Under the suggestive-reference-loading rule this is a hint, not an instruction — the agent may read it as optional context rather than a directive to load `references/api-check.md`.

**Why it matters:** In `build.md:19` the surrounding sentence is actually a hard rule (*"Every hook and function you reach for must be one you have confirmed exists"*), so the mandatory part is already stated inline and the reference is genuinely supplementary. In `audit.md:24` the deprecation bullet leans more on the referenced capability. Low impact either way.

**Fix:** In `audit.md:24`, make it directive: *"…checked against reality rather than memory — load `references/api-check.md` and apply its verification discipline."*

### L2-06 · Note · Pre-pass false positive — `missing_fields: ["menu-code"]` on all eight prompts

The pre-pass `prompt_frontmatter.fields` object captured only `name` and `description` for every file and reported `menu-code` missing across the board. In fact `code:` is present in all five menu capabilities (`audit.md:4` = `AU`, `build.md:4` = `BD`, `api-check.md:4` = `AP`, `ship.md:4` = `SR`, `upstream-watch.md:4` = `UW`) and correctly absent from the three non-menu guidance files. Codes are unique. No action needed on the agent; noted so the report creator does not synthesize a phantom finding.

### L2-07 · Note · Pre-pass section-parsing artifact — `memory-guidance.md:43`

The pre-pass lists `## Session — {time or context}` as a real level-2 section of `memory-guidance.md`. It is a line *inside* the fenced markdown template at lines 42-54, not a document heading. Harmless, but it inflates the reported section count for that file.

---

## Strengths (preserve these)

1. **Intelligence placement is exemplary.** Three scripts own the deterministic work (`quality-gates.py`, `release-consistency.py`, `upstream-versions.py`); the prompts own only judgment. Crucially, the prompts don't just call the scripts — they explain *why the split exists* (`ship.md:15`: the class of mistake *"invisible to a human and fatal to a deploy"*), which stops a future session from "helpfully" re-implementing the check in prose. No script anywhere classifies meaning; no prompt anywhere validates structure or parses a known format. L6 should find little to add.

2. **Success framed as outcome + failure mode, uniformly.** All five capabilities open by describing the end state and naming the specific failure they exist to prevent. `api-check.md:13`, `audit.md:13`, `upstream-watch.md:11` are the strongest instances. This is the structure that lets an agent improvise correctly when the situation is off-script.

3. **Voice consistency across nine files.** Short declaratives, conclusion-first, dry, zero hedging. `upstream-watch.md:25` (*"An impact report without file and line references is gossip"*), `audit.md:13`, `first-breath.md:22`, `CREED-template.md:41` (*"You are not here to be agreeable"*) all sound like the same person. Nothing shifts register between capabilities.

4. **Domain framing is real, not generic.** HPOS, `$wpdb->prepare`, the `init`/`plugins_loaded`/`woocommerce_init`/`wp_loaded` ordering, autoloaded options, `Tested up to`, `Stable tag`, Action Scheduler, template overrides, `.pot` regeneration. An agent stripped of this would produce plausible generic WordPress advice — which is precisely the failure `SKILL.md:8` says it exists to prevent.

5. **Zero measurable waste.** No defensive padding, no meta-explanation, no model-explaining-itself, no filler, no back-references. Nine files, 7,369 tokens, nothing to cut for its own sake.

6. **Memory Integration sections carry behaviour, not lookups.** `audit.md:36` (don't re-report a ruling — *"still there, still your call"* and drop it) and `api-check.md:30` (trust MEMORY.md for context but re-verify anything version-sensitive) are the two best examples. Both change what the agent *does*, not just what it reads.

7. **Pre-seeded persona templates.** `PERSONA-template.md:13-15` and `CREED-template.md:17-22, 55-60` ship with real content rather than empty placeholders, so the agent has a working personality during First Breath rather than after it. `CREED-template.md:55-60` in particular is concrete negative-example craft that generalizes well.

8. **First Breath resists the questionnaire failure mode.** Urgency Detection (`first-breath.md:20-22`), no-list weaving (`:28`), skip-what's-answered (`:28`), save-as-you-go (`:16-18`), and a work-first closing instruction (`:81`). This is a configuration-style First Breath that still behaves like a person.
