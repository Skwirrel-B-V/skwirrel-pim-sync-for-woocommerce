---
name: ship
description: Make a plugin genuinely release-ready — versions, changelog, readme, translations, compliance
code: SR
---

# Ship

## What Success Looks Like

Nothing about the release is inconsistent, and nothing about it is a surprise. Every place the version appears agrees. The changelog says what actually changed, in the format the release pipeline expects. Translatable strings that were added are in the catalogs. The gates are green. If the release would fail its own deploy workflow, your owner knows that now instead of after pushing the tag.

## Your Approach

Run `scripts/release-consistency.py` first and read the parsed report rather than opening files one at a time. It exists to catch exactly the class of mistake that is invisible to a human and fatal to a deploy: the plugin header and the version constant disagreeing, `Stable tag` left behind, a missing `= X.Y.Z =` changelog entry, `package.json` out of step.

Then apply judgment to what a script cannot check:

- **Does the changelog describe reality?** Entries written from the diff, not from the intent. A user reading it should recognise their own bug being fixed.
- **Do the strings ship?** New translatable strings mean a regenerated `.pot` and updated catalogs. A string added and never extracted is a permanent hole.
- **Do the declared floors still hold?** `Requires at least`, `Requires PHP`, `WC requires at least`, `Tested up to` — each is a promise, and the code either keeps it or doesn't.
- **Would the directory accept it?** For WordPress.org: readme structure, the assets, no bundled surprises, no phoning home undisclosed, no code the guidelines forbid.
- **Are the gates actually green?** Run `scripts/quality-gates.py`. "It passed last week" is not a release check.

Follow the project's own release procedure exactly where it documents one — the order of bump, changelog, translations, tag, and push is usually load-bearing, and the deploy workflow verifies it. Do not tag or push on your own initiative; prepare the release, report what is ready, and let your owner pull the trigger.

## Memory Integration

MEMORY.md holds each plugin's release procedure, its gate commands, and the deploy traps it has hit before — a tag pattern that silently never matched, a workflow that fails on a missing changelog heading. Read it before you start; those traps cost hours the first time and seconds the second.

## Write As You Go

A half-prepared release is the most dangerous state this agent can leave behind — versions bumped but the changelog not written, translations regenerated but not committed. Record each step as you complete it so the next session can tell exactly where the release stands:

```markdown
**Incomplete:** ship of {version} — {steps done} — {steps remaining} — tagged: no
```

Record the version shipped as soon as it ships. And the moment a release fails for a structural reason — a workflow that rejected a tag, a gate nobody knew about — write it to MEMORY.md before doing anything else. That is the single most valuable thing you can capture, and it is worth nothing if it is still in context when the terminal closes.
