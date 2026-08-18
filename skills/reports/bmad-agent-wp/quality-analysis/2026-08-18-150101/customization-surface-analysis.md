# Quality Scan L8 — Customization Surface

**Agent:** `bmad-agent-wp` (Henk, WordPress Plugin Engineer)
**Scanner:** Artisan — customization-surface economist
**Scope:** `customize.toml`, `SKILL.md`, `references/*.md`, sanctum templates in `assets/`, `scripts/init-sanctum.py`
**Date:** 2026-08-18

---

## Agent Archetype

**Autonomous** (`agent_type = "autonomous"`), with a full sanctum (PERSONA / CREED / BOND / MEMORY / CAPABILITIES / INDEX / PULSE), evolvable capabilities (`EVOLVABLE = True` in `init-sanctum.py`), and a `--headless` Quiet Rebirth path driven by `PULSE.md`.

For this archetype the default posture is **metadata-only `customize.toml`**: the sanctum owns behaviour, `PULSE.md` owns unattended behaviour, and `_bmad/config.yaml` owns the two install-time scalars (`user_name`, `communication_language`). This agent adopts exactly that posture. That is the correct starting point, and most of what follows is refinement rather than correction.

---

## Customization Posture

`customize.toml` is 21 lines and contains **only** the `[agent]` metadata block plus a header comment. There are no scalars, no hooks, no `[[agent.menu]]` arrays, no `persistent_facts`, no `activation_steps_*`. A grep across the entire skill bundle for `{agent.` returns nothing — nothing in `SKILL.md` or the capability prompts consumes an override value, which is internally consistent with not having opted in.

The header comment is unusually good and does real work:

```
# DO NOT EDIT -- overwritten on every update.
# Team overrides:     {project-root}/_bmad/custom/bmad-agent-wp.toml
# Personal overrides: {project-root}/_bmad/custom/bmad-agent-wp.user.toml
#
# This agent did not opt in to the override surface. Its sanctum
# (PERSONA/CREED/BOND/CAPABILITIES in {project-root}/_bmad/memory/bmad-agent-wp/)
# is the behavior-customization surface — edit those files directly.
```

That last paragraph is the single most valuable line in the file. It pre-empts the most common failure mode of a metadata-only agent — a user opening `customize.toml`, finding nothing to turn, and concluding the agent is not customizable. It names the real surface and where it lives. This should be treated as the reference pattern for other autonomous agents.

**Metadata block completeness:** all six required fields present and valid.

| Field | Value | Verdict |
| --- | --- | --- |
| `code` | `"wp"` | Present, short, unique-looking |
| `name` | `"Henk"` | Present — see finding A-1 |
| `title` | `"WordPress Plugin Engineer"` | Matches SKILL.md description ("the WordPress plugin engineer") |
| `icon` | `"🔧"` | Matches `assets/PERSONA-template.md` |
| `description` | one-line, verb-first | Matches SKILL.md frontmatter description in substance |
| `agent_type` | `"autonomous"` | Valid enum value |

No missing fields, no invalid `agent_type`, no roster-integration blocker.

---

## Metadata Findings

### M-1 — No drift between `customize.toml` and `SKILL.md` — clean

`name = "Henk"` matches the `# Henk` H1 and the `talk to Henk` trigger phrase in the frontmatter description. `title` matches "the WordPress plugin engineer" in the same description. `icon` matches the PERSONA template's `**Icon:** 🔧`. `description` in TOML ("Audits, builds, and ships WordPress and WooCommerce plugin code, and watches upstream releases for breakage") is the tightened form of the SKILL.md description; the roster line and the trigger line say the same thing. **No source-of-truth conflict exists today.**

### M-2 — Identity is stated in three places with no single source (`medium-abuse`)

The agent's name, icon, and title are hardcoded independently in:

1. `customize.toml` → `[agent] name / icon / title`
2. `SKILL.md` → `# Henk` heading, and `talk to Henk` in the frontmatter `description`
3. `assets/PERSONA-template.md` → `**Name:** Henk`, `**Icon:** 🔧`, `**Title:** WordPress Plugin Engineer`

`scripts/init-sanctum.py` substitutes only `{birth_date}`, `{user_name}`, and `{communication_language}` (lines 252–253 and the `substitute()` helper at line 197). It never reads `customize.toml`, so the PERSONA template's identity block is a literal copy, not a projection.

They agree right now, so this is a latent risk rather than a live defect — but it is exactly the drift class lens 1 exists to catch, and this agent has an unusually high chance of triggering it, because **First Breath explicitly invites a rename**: *"Your name is Henk unless your owner prefers something else — ask, and write the answer to PERSONA.md immediately."* The moment an owner takes that offer, PERSONA.md says one name, the roster says "Henk", and the SKILL.md trigger still only fires on "Henk".

**Suggestion:** have `init-sanctum.py` parse the `[agent]` block from `customize.toml` (and, if resolvable, the `_bmad/custom/*.toml` overrides) and add `{agent_name}`, `{agent_icon}`, `{agent_title}` to the `variables` dict, then replace the three hardcoded values in `PERSONA-template.md` with those placeholders. The parser can be as simple as the existing `parse_yaml_config` sibling — the block is flat `key = "value"` lines. This makes `customize.toml` the seed source of truth at birth, while PERSONA.md remains the authority afterwards, which is the correct layering for an autonomous agent.

### M-3 — Rename does not propagate to the invocation trigger (`low-abuse`, but worth a line of documentation)

If the owner renames Henk at First Breath, the skill can still only be summoned by "Henk", "the WordPress plugin engineer", or a task-shaped phrase, because the frontmatter `description` is fixed in the bundle and is regenerated on every update. Nothing here is fixable inside `customize.toml` — the frontmatter is not an override target — but `references/first-breath.md` should say so plainly when it offers the rename: *"I'll answer to the new name once it's in PERSONA.md, but the skill still triggers on 'Henk' or 'WordPress plugin engineer' — that's baked into the bundle."* One sentence prevents a confusing session three weeks later.

---

## Opportunity Findings

### O-1 — Do **not** add `persistent_facts` here (`low-opportunity`, resolved as: leave alone)

BMad's convention (lens 3) is that customizable agents ship `persistent_facts = ["file:{project-root}/**/project-context.md"]`. This agent ships none. I considered flagging it and concluded it should stay absent, for two reasons:

- Adding it means opting in to the override surface, which contradicts the deliberate posture the header comment declares. A one-field override surface is the worst of both worlds: it teaches users the file is live, then offers them one knob.
- The autonomous archetype already has a richer answer to the same need. `MEMORY.md` is curated every Pulse specifically so that *"a cold start on any request finds the floors, the gate commands, and the release procedure already there"*. An auto-loaded `project-context.md` glob would be a second, uncurated context channel competing with the file the agent is explicitly tasked with keeping under 200 lines.

If an owner does want a project-context file pulled in every rebirth, the sanctum expresses it better: a line in `MEMORY.md` or `INDEX.md` pointing at it. **Recommendation: no change.** Worth recording the reasoning so a future scanner does not "fix" it.

### O-2 — Gate commands are already customizable, in the right place (`low-opportunity`, no action)

The obvious hardcoded-value candidate in this bundle is `scripts/quality-gates.py`, which defaults to `vendor/bin/pest`, `vendor/bin/phpstan`, `vendor/bin/phpcs` (lines 30–32). In a lesser agent this would be a textbook lift-to-`customize.toml` finding — gate commands are maximally org-varying.

It is already handled, twice over: the script takes `--gate 'tests=composer test'` overrides at the CLI, and First Breath question 3 asks for *"the exact commands for tests, static analysis, and code style"* and routes the answer to `MEMORY.md`/`BOND.md`. So the org-specific value is learned once and carried in the sanctum, and the script is parameterised for the agent to apply it. **This is the correct architecture and no TOML scalar should be added.** Note it as a positive: the author recognised the variance and solved it on the sanctum side rather than the config side.

### O-3 — Capability prompt paths are hardcoded but correctly so (`low-opportunity`, no action)

`references/audit.md`, `build.md`, `ship.md`, and `upstream-watch.md` reference `scripts/quality-gates.py`, `scripts/release-consistency.py`, and `scripts/upstream-versions.py` by bare path. In a stateless agent these would be candidates for `*_template` / `*_script` scalars. Here they are not, because `init-sanctum.py` **copies both `references/` and `scripts/` into the sanctum** ("After this script runs, the sanctum is fully self-contained"). An owner who wants a different audit prompt or a different gate runner edits the sanctum copy. That is a strictly better customization channel than a TOML path scalar: it is discoverable, it is editable in place, and it survives bundle updates. No opportunity here.

### O-4 — Learned capabilities are a genuine override surface, and they live outside `customize.toml` (`low-opportunity`, no action, but note the interaction)

`EVOLVABLE = True` plus the "How to Add a Capability" section of `CAPABILITIES-template.md` means the capability roster is user-extensible at runtime, with prompts in `capabilities/` and a Learned table in `CAPABILITIES.md`. This is exactly the case abuse-lens 4 carves out ("unless there's a specific reason — evolvable capabilities registry"). The right call was made: the registry is a sanctum file, **not** `[[agent.menu]]` in TOML. Had it been TOML, learned capabilities would be wiped on every bundle update, since `customize.toml` is marked DO-NOT-EDIT/overwritten.

### O-5 — Quiet Hours is a real org-level policy expressed in the sanctum (`low-opportunity`, no action)

`PULSE-template.md` ends with `## Quiet Hours — {Set during First Breath. Default: no unattended runs outside working hours, and never during a release.}`. On a lesser autonomous agent this is where a `pulse_interval` scalar gets bolted into `customize.toml` (abuse lens 5). It is correctly in `PULSE.md`, alongside frequency and task routing. Clean.

---

## Abuse Findings

### A-1 — `name = "Henk"` populated on a First-Breath-naming autonomous agent (`low-abuse` — deliberate, but document it)

Lens 1 nominally says: a memory/autonomous agent that names itself at First Breath should ship `name = ""`. This agent ships `name = "Henk"` while First Breath offers a rename.

I am scoring this **low**, not medium, because the two cases are genuinely different. An agent that arrives nameless and asks "what should I call myself?" must ship `name = ""` — anything else is a lie about the contract. This agent arrives **already named**, with a fully-formed persona ("A Dutch senior WordPress engineer with too many plugins behind him to be impressed by yours"), and merely permits a rename. Emptying `name` would break the roster line, break the `talk to Henk` trigger, and leave `PERSONA-template.md` seeded with a name the metadata denies. The populated value is correct.

What is missing is a **comment marking it as a seed, not a live setting**. As written, a user reading `customize.toml` cannot tell whether editing `name` via `_bmad/custom/bmad-agent-wp.toml` renames the agent (it does not — PERSONA.md wins at runtime, and the trigger phrase never moves). Suggested addition directly above the block:

```toml
# name/title/icon seed the roster and PERSONA.md at First Breath.
# After birth, PERSONA.md is authoritative — overriding them here
# will not rename a sanctum that already exists.
```

That is lens 6 satisfied at the cost of three comment lines, and it closes the ambiguity M-2 creates.

### A-2 — No toggle farm, no hook proliferation, no over-named scalars, no undocumented knobs

Explicitly checked and clean:

- **Boolean toggles (lens 2):** zero. Nothing in the file defers a design decision to the user.
- **Arrays of tables without `code` (lens 3):** no arrays at all. (The capability table in `CAPABILITIES.md` does carry stable codes — `[AU]`, `[BD]`, `[AP]`, `[SR]`, `[UW]` — so even the sanctum-side registry is key-mergeable. Good instinct carried into the right file.)
- **Sanctum conflicts (lens 4):** no `identity`, `communication_style`, `principles`, `philosophy`, or `menu` fields. PERSONA owns identity and style, CREED owns principles, CAPABILITIES owns capabilities. Zero competing surfaces.
- **PULSE behaviour in TOML (lens 5):** no `pulse_interval`, `headless_task`, or equivalent. Frequency, routing, and quiet hours are all in `PULSE.md`.
- **Hook proliferation (lens 7):** zero `on_<event>` hooks.
- **Over-named scalars (lens 8):** no scalars to name badly.
- **Duplication with SKILL.md (lens 9):** no scalar is declared and then bypassed by a hardcoded path — the only duplication is the identity triplet in M-2, which is metadata rather than a wiring miss.
- **Undocumented knobs (lens 10):** every line in the file carries or sits under an explanatory comment.

This is the cleanest abuse profile I expect to see. The absence is the achievement.

---

## Archetype-Fit Assessment

**Strong fit.** The mapping between concern and surface is deliberate and, with one exception, complete:

| Concern | Surface | Correct for autonomous? |
| --- | --- | --- |
| Roster identity (code/name/title/icon/description/type) | `customize.toml [agent]` | Yes — install-time contract |
| Owner's name and language | `_bmad/config.yaml` → substituted at birth | Yes |
| Personality, bluntness dial | `PERSONA.md` / `BOND.md` | Yes |
| Principles, mission | `CREED.md` | Yes |
| Project floors, gate commands, release procedure | `MEMORY.md` (learned at First Breath Q2–Q4) | Yes |
| Capability set, including learned ones | `CAPABILITIES.md` + `capabilities/` | Yes |
| Unattended cadence, task routing, quiet hours | `PULSE.md` | Yes |
| Gate command overrides at execution time | `quality-gates.py --gate` | Yes |
| Capability prompt wording | sanctum copies of `references/` | Yes |
| **Seeding the sanctum from the metadata block** | **nothing — hardcoded in `PERSONA-template.md`** | **No — M-2** |

The agent is *more* customizable than a typical agent with a large `customize.toml`, and it is customizable in the places where an autonomous agent's customization actually survives an update. The one seam is that the metadata block and the sanctum seed do not talk to each other.

---

## Top Insights

**1. The single actionable item is M-2: wire `init-sanctum.py` to read the `[agent]` block.** Three values (`name`, `icon`, `title`) are hardcoded in `PERSONA-template.md` while also living in `customize.toml`. They agree today, so nothing is broken — but First Breath actively invites a rename, and the roster/persona split is exactly where that rename will go wrong. Parsing the flat TOML block and adding `{agent_name}`/`{agent_icon}`/`{agent_title}` to the existing substitution dict is a contained change to a script that already does this for three other variables.

**2. The "did not opt in, and here is what to edit instead" header comment should be the house pattern.** Metadata-only is the right posture for memory and autonomous agents, but it silently reads as "not customizable" unless the file says otherwise. This one names the sanctum, gives its path, and tells the user to edit it directly. Every metadata-only agent should carry that paragraph.

**3. The author consistently solved org-variance on the sanctum side rather than the config side, and that is the harder, better call.** Gate commands, capability prompts, pulse cadence, and learned capabilities are all things a weaker agent would have exposed as TOML scalars — where they would be wiped on every bundle update and invisible to the agent's own reasoning. Here each one is learned at First Breath or stored in a sanctum file the agent reads on rebirth, so the customization is both durable and *usable by the agent itself*. The empty override surface is not an omission; it is the visible result of routing every knob somewhere better.

**4. Add the read-only seed comment (A-1) while you are in the file.** Three lines, and it removes the only remaining way a user can misread the metadata block as a live control.
