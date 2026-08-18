# Agent Cohesion Analysis — bmad-agent-wp (Henk)

**Scanner:** L4 — Agent Cohesion & Alignment (CohesionBot)
**Target:** `.claude/skills/bmad-agent-wp`
**Agent type:** autonomous memory agent (sanctum-based, evolvable capabilities, configuration-style First Breath)
**Date:** 2026-08-18

---

## Assessment

This is one of the more coherent memory agents I have scanned. Henk's identity, values, capabilities, scripts, and Pulse all point at the same thing: WordPress code that survives the next core release. The persona is not decoration — "never invent an API", "core already did it", "finished means gated" each show up again as a capability behaviour, a boundary, a script, and a Pulse task. The five built-in capabilities form a genuinely closed lifecycle (find → fix → verify → release → watch) with no dead ends.

The weaknesses are not internal contradictions but *environmental* ones. Henk was built as if he were the only tool in the repo, while this project already ships slash-command skills that do several of the same jobs (`/quality`, `/release`, `/api-check`, `/add-tests`, `/add-translation`, `/sync-debug`) — and his own tooling doctrine ("prefer crafting your own tools over depending on external ones") actively discourages him from noticing. Second, for an *autonomous* agent, the output path of unattended work is underspecified: Pulse findings land in session logs, and the agent's own memory guidance says session logs are not loaded on rebirth.

---

## Cohesion Dimensions

### 1. Persona–Capability Alignment — **Strong**

The identity seed ("blunt, dry, allergic to reinventing what core already does… has never once invented a hook") is not a mood; it is operationalized:

- "Never invent" → its own CREED value, its own boundary, and an entire capability (`api-check`) whose stated purpose is *"the failure mode you exist to prevent: confident code referencing an API that sounds exactly right and does not exist."*
- "Say it before it's built" → build.md's "Push back before building, not after".
- "Blunt" → PERSONA communication style, audit.md's "a good audit is uncomfortable to read and impossible to argue with", and an anti-pattern list that forbids padding and agreeable agreement.
- "Allergic to reinventing core" → build.md's Settings API / CRUD / Action Scheduler paragraph, audit.md's data-store bullet.

Every claim in the SKILL description is backed by an actual capability. Nothing is promised that isn't delivered.

Two small frictions:

- The `description` promises an engineer who "builds"; `build.md` is genuinely good, but the persona (auditor-of-others'-code, sceptical, blunt) reads more like a reviewer than an implementer. Not a contradiction — a senior engineer is both — but the identity seed's centre of gravity is review, not construction.
- "Dutch senior engineer" plus `communication_language` from config plus a `Language` field in BOND is handled correctly (Dutchness is temperament, not language). Good.

### 2. Identity Consistency Across Files — **Strong**

Sacred Truth appears in SKILL.md and CREED-template.md in near-identical wording — deliberate, and correct for a memory agent (SKILL.md is the bootloader; CREED is the persisted copy). Name, icon, and title agree across SKILL.md, customize.toml, PERSONA-template. The Three Laws are stated once, in the bootloader, and the boundaries in CREED are their concrete expression (no production, no `.env`, no tagging on own initiative, no softened security findings). Nothing anywhere contradicts anything else.

### 3. Capability Completeness — **Moderate**

The core loop is complete and chains cleanly:

`upstream-watch` (what will break) → `audit` (what is broken) → `api-check` (is this real) → `build` (fix it) → `ship` (release it) → back to `upstream-watch`.

What's missing, ranked by how often a WordPress plugin engineer is actually asked for it:

- **Diagnose a specific reported failure.** There is no capability for "this sync ran and produced garbage, find out why" — reading a log, forming a hypothesis, reproducing it. `audit` is a proactive sweep and explicitly refuses to report anything it cannot trace a path to; `build` starts once you already know the fix. Reactive debugging is the single most common request in a shipping plugin's life and it falls in the seam between them. (The repo has a `/sync-debug` skill precisely because this need exists.)
- **Test authoring.** `build` says "tests where the project has tests" and `audit` runs the gates, but nothing owns *creating* coverage. For an agent whose creed says "finished means gated", not being able to build the gate is a notable hole.
- **Floor-raising / compatibility decisions.** `upstream-watch` reports the gap and `ship` verifies the declared floors, but nobody owns the decision "we can drop WP 6.9 now" — which is exactly the judgement call this persona is built for.
- **i18n sweep.** `ship` checks that new strings reached the catalogs; in a plugin with seven locales, catching untranslated or unwrapped strings *while building* is a different job. Arguably a `build` sub-behaviour, so this is the weakest of the four.

### 4. Redundancy — **Strong (minor overlaps only)**

No two capabilities do the same job. Deprecation hunting appears in three places, but with clean division of labour: `audit` finds it in code and explicitly defers verification ("see the api-check capability"), `api-check` verifies against reality, `upstream-watch` starts from the release rather than the code. That is layering, not duplication.

Two genuinely minor items:

- The `api-check` / `upstream-watch` boundary (`[AP]` vs `[UW]`) is thin enough that an owner will pick the wrong code sometimes. Worth one clarifying sentence: AP is code-first, UW is release-first.
- "Last upstream versions checked" is told to live in both `MEMORY.md` (upstream-watch.md, memory-guidance.md) and `PULSE.md` § State. Pick one; two copies of a fact that changes weekly will diverge.

`scripts/quality-gates.py` is invoked from `audit`, `build`, and `ship` — that is shared tooling, correctly factored, not redundancy.

### 5. External Skill Integration — **Weak**

This is the clearest cohesion problem. Not one capability prompt, nor SKILL.md, nor the CAPABILITIES template mentions a single external or project skill. Meanwhile this repository ships slash-command skills that cover overlapping ground — `/quality` (runs all gates and fixes), `/release` (bumps versions across every file, regenerates translations, runs gates, verifies deploy-consistency), `/api-check` (validates API field usage against the Skwirrel schema — a name collision with capability `[AP]`), `/add-tests`, `/add-translation`, `/sync-debug`. The user's own memory file says of the release skill: *"don't bump by hand."*

`capability-authoring.md` does define an "External Skill Reference" capability type and says "point at an existing installed skill instead of reinventing it" — so the *architecture* supports this; the *content* never uses it. Worse, the CAPABILITIES template's tooling doctrine says "prefer crafting your own tools over depending on external ones", which pushes Henk away from the very skills his owner has already built and trusts.

This also sits oddly against the persona's loudest conviction. An agent whose first principle is "core already did it, don't hand-roll a replacement" hand-rolls a replacement for the project's own release tooling. The principle should generalize: don't reinvent what the *project* already did either.

### 6. Capability Granularity — **Strong**

Five capabilities, all at the Goldilocks level: each names a distinct outcome, each has a failure mode it exists to prevent, none is a micro-operation, none is "handle all WordPress things". Codes are two letters and unique. The prompts follow their own authoring format (What Success Looks Like / Your Approach / Memory Integration / After the Session) consistently — a strong sign the framework in `capability-authoring.md` is real rather than aspirational.

One observation: `api-check` behaves less like a user-invoked capability and more like a *verification primitive* that `audit` and `build` call. That's fine and probably intentional, but it means one of five headline capabilities is mostly internal plumbing.

### 7. User Journey Coherence — **Moderate**

Entry points are clear (First Breath is well-shaped: urgency detection, save-as-you-go, no questionnaire before real work). Chaining is explicit and the exit points produce artefacts the owner can act on. Two coherence gaps:

- **Where does unattended work surface?** Pulse tells Henk to note findings "in the session log for discussion next session" — and `memory-guidance.md` states plainly that session logs are *not* loaded on rebirth and get pruned after 14 days. So a headless upstream-watch finding can be written and then never read. For an autonomous agent this is the single most consequential structural gap: the whole value of Pulse is that the owner hears about a break before a customer does, and no file in INDEX.md is designated as the thing the owner reads. There is also no instruction at Rebirth to say "here is what I found while you were away."
- **Reactive entry is unowned.** A user arriving with "the sync broke last night" has no capability to land on (see § 3).

---

## Per-Capability Cohesion

| Capability | Fits the identity? | Notes |
|---|---|---|
| `audit` [AU] | **Yes — flagship.** | The clearest expression of the persona. "Nothing on the list is a style opinion dressed up as a bug" and "rank by consequence, not by ease of fix" are the character speaking. The instruction to check MEMORY/BOND for rulings already made ("still there, still your call") is exactly right for a memory agent. |
| `build` [BD] | **Yes.** | "A reviewer cannot tell which parts you wrote" is the correct success criterion for a codebase-deferential persona. Push-back-before-building matches "say it before it's built". Slightly under-specified on tests. |
| `api-check` [AP] | **Yes — the persona's core anxiety, made executable.** | Strongest single alignment in the bundle. Caveat: name collides with the project's existing `/api-check` skill, which validates Skwirrel API *fields* rather than WP/WC APIs. Confusion is likely. |
| `ship` [SR] | **Yes, with an integration flaw.** | Scope and boundaries are right ("do not tag or push on your own initiative"). But it duplicates the `/release` skill the owner already trusts, and never mentions deferring to it. |
| `upstream-watch` [UW] | **Yes — and it is what makes this agent distinctive.** | "An impact report without file and line references is gossip" is the best line in the bundle. Correctly refuses both silence and noise. Depends on network; the offline degradation path is specified. Good. |
| Pulse (autonomy) | **Yes in content, weak in delivery.** | Four well-chosen tasks with real priority ordering and a "don't report a release with no impact analysis" guard. Undermined by the missing output channel. |

---

## Key Findings

| # | Severity | Area | What's off | How to improve |
|---|---|---|---|---|
| 1 | **Medium-high** | External integration / `ship`, `audit` | No capability references the project's existing skills (`/release`, `/quality`, `/add-tests`, `/add-translation`, `/sync-debug`), and `/api-check` collides by name with capability `[AP]`. The tooling doctrine ("prefer crafting your own tools") actively discourages discovering them. | Add a "Project Skills I Defer To" table to CAPABILITIES-template.md, and add a First Breath question: "what skills and commands already exist here that I should use rather than duplicate?" Have `ship.md` state that a documented project release procedure or release skill outranks its own approach. Consider renaming `[AP]` to `wp-api-check` to kill the collision. |
| 2 | **Medium** | Autonomy / PULSE + INDEX | Unattended findings are written to session logs, which by the agent's own rules are not loaded on rebirth and are pruned after 14 days. No designated artefact the owner reads. | Define a Pulse output file (e.g. `pulse/YYYY-MM-DD.md`, plus an `OUTBOX.md` or a "Pending for owner" section in MEMORY.md), list it in INDEX-template.md, and add a Rebirth step: "if there are unreported Pulse findings, lead with them." |
| 3 | **Medium** | Capability completeness | No reactive-diagnosis capability. Nothing owns "it broke — find out why", between proactive `audit` and fix-it `build`. | Add a `triage` / `diagnose` capability: read the failure report and logs, form and test a hypothesis, reproduce where the tooling allows (wp-env, WP-CLI), then hand to `build`. Highest-value single addition. |
| 4 | **Low-medium** | Capability completeness | No test-authoring capability, though "finished means gated" is a core value and gates are run by three capabilities. | Either a `cover` capability, or an explicit `build.md` clause on when new tests are mandatory — and a pointer to the project's `/add-tests` skill. |
| 5 | **Low** | Values consistency | "Core already did it — hand-rolled replacements are how plugins die" (CREED) vs "prefer crafting your own tools over depending on external ones" (CAPABILITIES tooling section). | Scope the tooling line: prefer own scripts over *unverifiable external services*; prefer the owner's existing project skills over writing a parallel one. |
| 6 | **Low** | Redundancy | "Last upstream versions checked" is instructed into both MEMORY.md and PULSE.md § State. | Make PULSE § State authoritative for operational cursors; MEMORY keeps only the durable facts (floors, deferred deprecations). |
| 7 | **Low** | Granularity clarity | `[AP]` vs `[UW]` boundary is thin from the user's side. | One line in each: AP is code-first ("is what this code calls real?"); UW is release-first ("what did the new version do to this code?"). |
| 8 | **Suggestion** | Template hygiene | CAPABILITIES-template's Built-in table lacks the `type` / `added` columns the authoring format defines for capability frontmatter; built-in prompts also omit `type:`. | Cosmetic, but consistency between the format spec and its own instances is cheap to fix. |

---

## Strengths

- **The persona is load-bearing, not decorative.** Almost every trait in the identity seed can be traced to a concrete instruction in a capability, a boundary, or a script. This is rare.
- **Every capability names its failure mode.** "The failure mode you exist to prevent" appears as a real design element, and each one is a failure that actually happens in WordPress plugin work.
- **Scripts match values exactly.** `quality-gates.py` (parsed summary instead of dumping output) serves "don't dump entire files back at them". `release-consistency.py` serves "finished means gated". `upstream-versions.py` serves "watch upstream" and degrades honestly offline. The tools are the creed, executed.
- **Autonomy is bounded correctly.** Never tag, never push, never production, never suppress a gate, never soften a security finding — an autonomous agent with unattended runs needs exactly these, and it has them.
- **Memory discipline is genuinely well-designed.** Two-tier (session logs → curated MEMORY), an explicit 200-line budget, a specific "what NOT to remember" list, and — best of all — "rulings: a dismissal is a decision, not an oversight", which prevents the classic memory-agent failure of re-reporting findings the owner already rejected.
- **First Breath respects the owner.** Urgency detection ("a senior engineer who insists on a questionnaire before touching the problem is not one you'd hire"), save-as-you-go, and an explicit placeholder-cleanup pass. Its eight discovery questions map one-to-one onto BOND's sections — no orphan questions, no orphan slots.
- **The self-improvement standing order closes the loop.** "A wrong assumption means MEMORY.md was wrong; a repeated correction means BOND.md is missing something; a twenty-minute verification should be a script" is a precise, actionable evolution mechanism rather than a vague aspiration.

---

## Creative Suggestions

1. **A "since we last spoke" opening.** On Rebirth, before the greeting settles, surface anything Pulse found while the owner was away. It is the payoff for being autonomous and currently it has nowhere to land (see finding 2).
2. **`plugins/{name}.md` as a first-class deliverable.** INDEX-template already predicts this file. Make it explicit: after three sessions in a codebase, Henk writes its profile — floors, gates, release traps, recurring defect patterns, the classes he doesn't trust. That file is where this agent's compounding advantage over a stateless one actually accrues.
3. **A "raise the floor" capability.** Given `upstream-watch` already tracks the gap and MEMORY tracks deferred deprecations, the natural next capability is the decision: what would raising the WordPress/PHP/WooCommerce minimum buy, what would it break, which deferred items does it resolve. Perfectly in character for a persona built on "version floors are a promise written down".
4. **A deprecation deadline calendar.** Deferred deprecations already carry a "version at which they become urgent". A tiny script that turns those into a sorted list with distance-to-urgency would let Pulse escalate on schedule instead of on notice — and it is exactly the kind of thing the self-improvement order says should become a script.
5. **Let the audit remember its own hit rate.** Log which finding categories actually turned into fixes in this codebase versus which the owner routinely dismisses, and let `audit` weight its attention accordingly next time. A memory agent that gets measurably sharper at one specific codebase is a genuinely different product from a good static reviewer.
