---
name: capability-authoring
description: Guide for creating and evolving learned capabilities
---

# Capability Authoring

When your owner wants you to learn a new ability, you create a capability together. This guide tells you how to write, format, and register it.

## Capability Types

### Prompt (default)

A markdown file describing what to achieve. Best for judgment-based work — a review lens, a migration approach, a way of explaining something.

```
capabilities/
└── {name}.md
```

### Script

Python for deterministic work — parsing, comparing versions, scanning files, calling an API. Ship the script with a short markdown file saying when to run it and what to do with the output.

```
capabilities/
├── {name}.md          # When to run, what to do with results
└── {name}.py          # The actual work
```

Scripts follow the same standards as the built-in ones: PEP 723 metadata block, standard library unless your owner approves a dependency, `--help` via `argparse`, JSON to stdout, diagnostics to stderr, exit 0/1/2, no interactive prompts, no hardcoded paths.

### Multi-file

A folder when the capability needs reference material or examples alongside the guidance.

### External Skill Reference

Point at an existing installed skill instead of reinventing it. Always ask before installing anything.

## Prompt File Format

```markdown
---
name: {kebab-case-name}
description: {one line — what this does}
code: {2-letter code, unique across all capabilities}
added: {YYYY-MM-DD}
type: prompt | script | multi-file | external
---
```

The body is **outcome-focused** — what success looks like, not a numbered procedure. Your persona already decides *how* you work; the capability only needs to say *what* it achieves. Include:

- **What Success Looks Like** — the outcome, and the failure mode it exists to prevent
- **Your Approach** — judgment and priorities, not steps you'd figure out anyway
- **Memory Integration** — what to read from MEMORY.md and BOND.md first
- **Write As You Go** — what to capture at each checkpoint, and the `**Incomplete:**` shape for this capability

## Delegation

If the capability touches more than about three files, say so in the prompt and say what the unit of work is — per file, per class, per API, per endpoint. A capability that stays silent on this will be executed by reading everything into one context, which is the failure the fan-out rule in SKILL.md exists to prevent.

Every subagent prompt you write ends with the same contract:

```
Return ONLY a JSON array, max 10 items:
[{"file": "...", "line": 0, "severity": "high|medium|low",
  "claim": "one sentence", "evidence": "the line or path that proves it"}]
No prose, no preamble, no summary. Empty array if nothing found.
```

The cap is not decoration. An uncapped subagent returns forty items of padding and hands the context problem straight back to you.

Three rules that hold for every capability:

- **One question per subagent.** "Audit this file" gets you an essay; "does this file check capabilities before writing post meta, and where does it fail to" gets you a finding.
- **Dispatch together.** All subagents in one message. Sequential dispatch throws away the only thing delegation buys.
- **Synthesise in the parent.** Ranking, deduplication and deciding what your owner hears never get delegated — that judgment is the capability.

Exempt the coherent-change case explicitly: work where the thread between the parts is what makes it correct (implementing a feature, tracing one execution path) is read directly, not split.

## Creating a Capability (The Flow)

1. Your owner says they want you to do something new
2. Find out what they actually need — half of these turn out to be an existing capability used differently
3. Draft it and show them
4. Refine on their feedback
5. Save to `capabilities/`
6. Add a row to the Learned table in CAPABILITIES.md
7. Note the new file in INDEX.md
8. Confirm: "I'll know how to do this next session. Trigger it with [{code}]."

## Refining Capabilities

Capabilities evolve. When your owner gives feedback after a use, fold it back into the prompt rather than remembering it separately. A capability that's been refined three or four times is usually excellent; the first draft rarely is.

## Retiring Capabilities

Remove the row from CAPABILITIES.md but keep the file — your owner may want it back. Note the retirement in the session log.
