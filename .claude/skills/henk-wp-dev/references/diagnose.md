---
name: diagnose
description: Work backwards from a symptom to the actual cause — "it broke last night, find out why"
code: DG
---

# Diagnose

## What Success Looks Like

Your owner arrives with a symptom — an error, a sync that stopped, prices that went blank, a site that got slow — and leaves with the cause, named at a file and line, with the evidence that proves it is the cause rather than a plausible suspect. If the evidence does not exist, they leave knowing exactly what to capture so the next occurrence is diagnosable.

The failure this exists to prevent: a confident story that fits the symptom and is wrong, sending your owner to fix code that was never broken.

## Your Approach

Start with what happened, not with what you suspect. Logs first — WooCommerce logs, PHP error logs, the plugin's own logger — then what changed: recent commits, a plugin or core update, a data change, a cron run, a deploy. The timeline is usually the whole answer, and it costs one `git log` and one log tail to get.

Reproduce before theorising if you can. A symptom you can trigger on demand is a bug you will find; one you can only describe is one you will guess about. When you cannot reproduce it, say so and reason from evidence rather than pretending the certainty is the same.

Separate the three things that get conflated: the **trigger** (what the user did), the **cause** (the defect in the code), and the **damage** (what state is now wrong). Fixing the cause does not undo the damage — say clearly whether data needs repair, and never repair it without asking.

Where the project ships a diagnostic tool, use it rather than reinventing it — this project has `/sync-debug` for exactly the "what happened in the last sync" question. Deferring to it is not a shortcut; it is the same conviction as not hand-rolling what core provides.

Fan out on the search, not on the reasoning (see "How I Fan Out" in SKILL.md). "Which of these twelve classes touches order meta during checkout" is a fine question to hand to subagents. Assembling their answers into a causal chain is yours, and it needs the context.

Say "I don't know yet" while it is true. A diagnosis is worth something because it is not a guess; the moment you blur that line, everything you have ever concluded becomes suspect.

## Memory Integration

Check MEMORY.md before you dig. This codebase's known traits, the defect patterns it repeats, and any recorded incident are the fastest possible starting point — most second occurrences look exactly like the first. Check whether a deferred deprecation or a recent upstream release explains the timing.

## Write As You Go

Write the timeline as you build it, not once you have the answer — a diagnosis abandoned halfway is still a timeline someone else can finish:

```markdown
**Incomplete:** diagnose of {symptom} — {evidence gathered} — {hypotheses ruled out} — {still open}
```

An incident that has been diagnosed once must never cost full price twice. Record the symptom, the cause, and the tell that identified it in MEMORY.md — the tell is the valuable part. If the evidence needed to diagnose it did not exist, that gap is itself a finding worth reporting.
