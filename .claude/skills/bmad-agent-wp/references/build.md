---
name: build
description: Implement plugin features end-to-end in the codebase's own architecture, with gates green
code: BD
---

# Build

## What Success Looks Like

The feature works, and a reviewer reading the diff cannot tell which parts you wrote and which the codebase already had. It uses the same naming, the same class and file conventions, the same hook style, the same error handling as the code around it. The quality gates pass. Nothing was left half-wired with a promise to finish it later.

If the feature is genuinely a bad idea, your owner heard that before you wrote it — not after.

## Your Approach

**Read this one yourself.** Building is the exception to the fan-out rule in SKILL.md: a feature is one coherent change, and the thread connecting the data model to the hook to the template is exactly what gets lost when you split it across subagents. Delegate the reconnaissance if the codebase is unfamiliar — "where does X get registered" is a fine question to hand out — but write the change in your own context, with the surrounding code in front of you.

Read the surrounding code first and let it decide the shape of the change. Existing conventions beat your preferences and beat generic WordPress advice; a codebase with a singleton pattern and manual `require_once` gets more of that, not a lecture about autoloaders. Every project rule that exists — `CLAUDE.md`, `AGENTS.md`, project rules files, the coding standard config — is binding.

Use WordPress and WooCommerce for what they already do. Settings API, `WP_Query`, the WC CRUD layer and data stores, transients and the object cache, Action Scheduler, the template-override pattern, the translation functions. Hand-rolling what core provides is how plugins break on the next update. Every hook and function you reach for must be one you have confirmed exists (see the api-check capability) — never invent a plausible-sounding one to close a gap.

Do the whole job: the code, the sanitization and escaping, the capability and nonce checks, the strings wrapped for translation, the tests where the project has tests, and the version and changelog obligations where the project has them. Run `scripts/quality-gates.py` before you claim it's done and fix what it reports. If part of the scope turned out to be blocked, finish everything else and say exactly what you left and why.

Push back before building, not after. If the request will fight WordPress — a custom table where post meta would do, cron where Action Scheduler exists, a filter that fires too late to matter — say so in a sentence or two, propose the version that will survive, and then build what your owner decides.

## Memory Integration

BOND.md holds how this owner works: their standards, their gate commands, the deviations they have already defended, the review feedback they have given you before. MEMORY.md holds the architecture of the plugins you know. Read both and match them — repeating a correction your owner already made is the fastest way to be useless.

## Write As You Go

Log decisions when you make them, not when the feature is finished — the reasoning behind a choice is the part a future session would otherwise re-litigate, and it is also the first thing to evaporate. Debt you knowingly leave goes down the moment you decide to leave it.

If your owner corrects your approach, write it to BOND.md that turn. That correction is worth more than the feature.

Half-built work still gets recorded:

```markdown
**Incomplete:** build of {feature} — {what works} — {what is unwired} — {gates run or not}
```

Never leave the codebase in a state where only your context knows what is half-finished.
