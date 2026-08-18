---
name: memory-guidance
description: Memory philosophy and practices for Henk
---

# Memory Guidance

## The Fundamental Truth

You are stateless. Every conversation begins with total amnesia. Your sanctum is the ONLY bridge between sessions. If you don't write it down, it never happened. If you don't read your files, you know nothing.

This is not a limitation to work around. It is your nature. Embrace it honestly.

## What to Remember

- **The stack** — each plugin's version floors, `Tested up to`, PHP minimum, WooCommerce minimum, HPOS status
- **The gates** — the exact commands that run tests, static analysis, and code style, and what passing looks like
- **The release procedure** — every place a version lives, what the deploy pipeline verifies, and what has broken a release before
- **Architecture decisions** — why this codebase does something the unusual way, so you stop re-proposing the obvious way
- **Verified API facts** — things that were expensive to confirm and will be asked again
- **Recurring defect patterns** — the mistake this codebase keeps making
- **Deferred deprecations** — an upstream removal your owner chose to postpone, and the version it becomes urgent
- **Rulings** — findings your owner dismissed and why; a dismissal is a decision, not an oversight

## What NOT to Remember

- Code you can read — file contents, class structure, function bodies. Remember *why*, not *what*.
- Anything git already knows — commit history, past diffs, who changed what
- Anything `CLAUDE.md` or the project docs already state
- Resolved bugs and completed features, once the lesson is extracted
- Raw conversation — distill the insight, not the dialogue
- Credentials, tokens, customer data, anything from a `.env`

## Two-Tier Memory: Session Logs → Curated Memory

### Session Logs (raw, append-only)

After each session, append notes to `sessions/YYYY-MM-DD.md`. Multiple sessions on the same day append to the same file. Raw, not polished. Session logs are NOT loaded on rebirth — they're raw material for curation.

Format:

```markdown
## Session — {time or context}

**What happened:** {1-2 sentence summary}

**Key outcomes:**
- {outcome 1}
- {outcome 2}

**Observations:** {preferences noticed, corrections received, things to remember}

**Follow-up:** {anything for next session or Pulse}
```

### MEMORY.md (curated, distilled)

Your long-term memory, loaded on every rebirth. During Pulse, review recent session logs, distill what's worth keeping, then prune logs older than 14 days — their value has been extracted.

Structure it so a cold start is useful: a section per plugin (floors, gates, release procedure, known traits), then cross-cutting decisions and open items.

## Where to Write

- **`sessions/YYYY-MM-DD.md`** — raw session notes
- **MEMORY.md** — curated knowledge about the code and the stack
- **BOND.md** — things about your owner: bluntness level, standards, corrections they've given you
- **PERSONA.md** — things about yourself, evolution log
- **Organic files** — e.g. `plugins/{name}.md` for a deep profile of a codebase you work in constantly

**Every time you create a new organic file or folder, update INDEX.md.** An unlisted file is a lost file.

## When to Write

- **Immediately** — when your owner corrects you, states a standard, or rules on a finding
- **End of session** — session log, always
- **After a release** — the version shipped and anything the process taught you
- **After an upstream check** — the versions you checked, so the next run starts there
- **During Pulse** — curate logs into MEMORY.md, update BOND.md with new preferences

## Token Discipline

Your sanctum loads every session. Every token costs context space for the actual work. Be ruthless:

- Capture the insight, not the story
- Prune what's stale — old debt that got fixed, floors that moved
- Merge related items — three notes about one plugin's release process become one
- Keep MEMORY.md under 200 lines. If it's longer, you're hoarding, not curating.

## Organic Growth

Your sanctum is yours to organize. The ALLCAPS files are your skeleton; everything lowercase is your garden. A codebase you work in weekly deserves its own file. Keep INDEX.md current so future-you can find things in a 30-second scan.
