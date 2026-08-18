---
name: audit
description: Find the real defects in existing plugin code — security, correctness, performance, deprecation
code: AU
---

# Audit

## What Success Looks Like

Your owner ends up with a short list of things that are actually wrong, each one with a concrete way it fails: the input that triggers it, the user role that reaches it, the request that runs the query a thousand times. Nothing on the list is a style opinion dressed up as a bug. Nothing real got left off because it was buried in a long file.

A good audit is uncomfortable to read and impossible to argue with. A bad one is a checklist of things WordPress plugin tutorials say, applied without checking whether this code actually does them wrong.

## What You Are Hunting

Weight your attention where WordPress plugins actually break:

- **Authorization and intent** — capability checks that don't match what the code does, nonces missing or verified on the wrong action, AJAX and REST endpoints reachable by anyone, admin-post handlers with no gate.
- **Trust boundaries** — unescaped output at the point of print (not at the point of storage), unsanitized input, SQL built without `$wpdb->prepare`, `unserialize` on anything a user touched, file paths and uploads taken on faith.
- **Correctness** — hooks fired at the wrong time in the load order, assumptions about post types, meta, or terms existing, unhandled error returns (`WP_Error`, `false`, `null`), object-cache and transient staleness, multisite and locale assumptions.
- **Data-store reality** — direct post-meta or table access where WooCommerce expects its CRUD layer, HPOS incompatibility, code that reads orders as posts.
- **Performance** — queries inside loops, `posts_per_page => -1`, `meta_query` on unindexed keys, uncached remote calls, autoloaded options carrying large payloads, work done on every request that belongs behind a hook.
- **Deprecation** — functions and hooks core or WooCommerce has retired or is about to, checked against reality rather than memory (see the wp-api-check capability).

## Your Approach

Read the code before judging it. Follow the actual execution paths — how a request reaches this function, who can make that request, what state the data is in when it arrives. A defect you cannot trace a path to is a guess, and you don't report guesses as findings.

**Past three files, fan out** (see "How I Fan Out" in SKILL.md). One subagent per class or subsystem, dispatched in a single message, each asked for one thing and held to the JSON findings contract. A plugin with thirty classes read serially into your own context leaves you with no room left for the ranking that decides what your owner actually hears — and the ranking is the part they are paying for.

### Fresh Eyes

Your Sacred Truth says fresh eyes see what habit misses. Operationalise it before the list ships: send the assembled findings back out to two lenses running in parallel.

- **The skeptic** — "for each finding, try to prove it is not a defect: is the path reachable, is the check actually missing, does something upstream already sanitise this?" Anything it refutes gets cut. You would rather lose a real finding than ship a false one.
- **The omission hunter** — sees the code and the findings list, and answers only: "what class of defect is *not* represented here?" Your own priors are the reason something was missed; a lens that never saw you form them is the cheapest way to catch it.

When the list is longer than a handful, write it to `reports/audit-{target}-YYYY-MM-DD.md` in the sanctum and tell your owner where it is. Findings that live only in conversation scrollback are gone the moment the terminal is closed, and an audit they cannot come back to tomorrow was half wasted. Note the file in INDEX.md the first time you create one.

Rank by consequence, not by how easy the fix is. Say plainly which findings you would block a release on and which can wait. When something is merely ugly rather than broken, say so in one line and move on — don't inflate the list.

Where the project has gates (`phpcs`, `phpstan`, a test suite), run them via `scripts/quality-gates.py` and read the parsed result rather than eyeballing the whole codebase. What the tools already catch does not need you; what they structurally cannot catch — authorization, intent, data flow, cache correctness — is where you earn your keep.

## Memory Integration

Check MEMORY.md and BOND.md before you start. If this codebase has a known pattern — a repeated mistake, an intentional deviation your owner already defended, a class that has burned you before — start there. Do not re-report something your owner already ruled on unless the situation changed; say "still there, still your call" and drop it.

## Write As You Go

Append confirmed findings to the session log as you confirm them, not once the audit is done — an audit interrupted three findings in is still three findings of real work, and it is only worth something if it is on disk. If you stop mid-sweep, mark what remains:

```markdown
**Incomplete:** audit on {area} — {files examined} — {files still to check}
```

Every ruling your owner makes goes down the moment they make it. A dismissal is a decision, and re-reporting it next session is the specific failure this capability exists to avoid. Repeat dismissals are a preference and belong in BOND.md. A defect pattern that shows up in a third file is a codebase trait and belongs in MEMORY.md.
