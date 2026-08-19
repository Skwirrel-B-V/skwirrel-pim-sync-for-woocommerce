---
name: upstream-watch
description: Turn new WordPress and WooCommerce releases into a concrete list of what breaks in this plugin
code: UW
---

# Upstream Watch

## What Success Looks Like

Your owner learns that a release affects their code before a customer does. The output is not "WordPress 7.1 is out" — it is "WordPress 7.1 removes X, you call it in three places, here they are, here is the replacement." When a release genuinely changes nothing for these plugins, you say that in one line and stop. Silence and noise are both failures; a short accurate answer is the goal.

## Your Approach

Establish the gap first with `scripts/upstream-versions.py` — current WordPress and WooCommerce releases against what each plugin declares it is tested up to and requires. That gives you the versions worth reading about. If the script cannot reach the network, say so and work from what the project and your memory already contain rather than guessing at version numbers.

Then read what actually changed in that gap: release notes, the field guide for a WordPress major, WooCommerce release and developer-facing changelogs, deprecation and removal notices. You are looking for a small set of things:

- APIs removed, deprecated, or changed in signature or behaviour
- Hooks retired, renamed, or moved to a different point in the load order
- Data-layer shifts — HPOS milestones, schema and table changes, block and template changes affecting overridden templates
- Minimum PHP or platform bumps that move the floor under this code
- Behaviour changes with no deprecation notice at all, which are the ones that bite hardest

Then do the part that matters: search the plugin code for each one. An impact report without file and line references is gossip.

That search is the fan-out point (see "How I Fan Out" in SKILL.md). One subagent per deprecation or changed API, dispatched together, each asked the same narrow question — "does this codebase use X, and where?" — and each returning only the JSON findings contract. A dozen independent searches run in one turn instead of a dozen sequential reads that fill your context before you can weigh anything. For everything you find, say how bad it is — breaks on upgrade, degrades quietly, or only matters when they raise their floor — and what the fix is.

When there is no impact, say so plainly and note the versions you checked so the next run starts from there instead of re-reading the same notes.

## Memory Integration

MEMORY.md holds which plugins you watch, their declared floors and `Tested up to` values, and the last upstream versions you checked. Update those every run — that record is what keeps this capability cheap on the second run and every one after.

## Write As You Go

Write the versions checked to MEMORY.md as soon as you have them — that record is what makes the next run cheap, and it is worthless if it dies with the session. Findings go down as you find them, one platform at a time.

A deferred deprecation is a commitment with a deadline. Record it in MEMORY.md the moment your owner defers it, with the removal version attached, and keep it visible until it is dealt with. Raise it again as that version approaches.

Unattended runs record the same way — see PULSE.md for where findings go so they are actually read.

If you stop partway:

```markdown
**Incomplete:** upstream-watch — {platforms checked} — {platforms outstanding}
```
