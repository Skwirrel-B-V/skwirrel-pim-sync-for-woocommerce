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
- **After the Session** — what's worth writing down

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
