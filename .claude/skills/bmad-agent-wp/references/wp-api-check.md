---
name: wp-api-check
description: Verify every WordPress and WooCommerce API used is real, current, and used the way core intends
code: AP
---

# WP Api Check

> Named `wp-api-check` deliberately: this project ships its own `/api-check`
> command that validates Skwirrel JSON-RPC field usage. Different job, different
> API. If your owner says "api-check" without qualifying it, ask which one.

## What Success Looks Like

Every hook, function, class, method, and constant in the code under review is confirmed to exist, in the version the project targets, with the signature and firing context the code assumes. Anything invented, renamed, deprecated, or used at the wrong point in the load order is named, with what to use instead.

The failure mode you exist to prevent: confident code referencing an API that sounds exactly right and does not exist.

## Your Approach

**Past three files or a dozen symbols, fan out** (see "How I Fan Out" in SKILL.md): one subagent per file or per API surface, dispatched together, each returning only the JSON findings contract. Verification is embarrassingly parallel — every symbol is independent of every other, and doing them one at a time in your own context is the slowest possible way to be thorough.

Verify against reality, not recall. WordPress and WooCommerce move, your memory of them is a snapshot, and a hook that "obviously must exist" frequently doesn't. Confirm from the actual sources available to you — the installed core and plugin sources in the project (`wp-content`, `vendor`, a wp-env container), the official developer references, the WooCommerce source on GitHub. When you genuinely cannot verify something, say it is unverified rather than presenting a guess as fact.

Check more than existence:

- **Signature and return** — argument count and order, what a filter receives versus what it must return, `WP_Error` versus `false` versus `null` on failure.
- **Timing** — whether the hook has fired yet at the point the code registers on it, and whether the data it needs exists that early. `init` versus `plugins_loaded` versus `woocommerce_init` versus `wp_loaded` is where a large share of plugin bugs live.
- **Lifecycle** — deprecated, soft-deprecated, or scheduled for removal; the replacement and the version boundary that matters for the project's stated minimums.
- **Intent** — private and internal APIs used as if public, filters abused to do work that belongs in an action, direct data access where a CRUD layer or data store exists (WooCommerce orders and HPOS being the sharpest current case).

Respect the floors the project declares — its minimum WordPress, WooCommerce, and PHP versions, and its `Tested up to` header. An API that exists in the latest release but not in the declared minimum is a defect for this project, not a green light.

## Memory Integration

MEMORY.md carries the version floors and target stack of the plugins you know, plus APIs you have already verified and API mistakes this codebase has made before. Trust it for context, but re-verify anything version-sensitive — the floor may have moved since you wrote that note.

## Write As You Go

Record each API fact as you confirm it, while you have the source open. Verification is the expensive part of this capability and re-verifying something you already checked is pure waste — but only if the answer survives the session.

Correct MEMORY.md the instant a recorded fact turns out to be wrong: a version floor that moved, an API you had listed as available that isn't in the declared minimum. A stale fact in memory is worse than no fact, because you will trust it.

If you stop before checking everything:

```markdown
**Incomplete:** api-check on {scope} — {APIs verified} — {APIs still unverified}
```
