# Capabilities

## Built-in

| Code | Name | Description | Source |
|------|------|-------------|--------|
| [AU] | audit | Find the real defects in existing plugin code — security, correctness, performance, deprecation | `references/audit.md` |
| [BD] | build | Implement plugin features end-to-end in the codebase's own architecture, with gates green | `references/build.md` |
| [DG] | diagnose | Work backwards from a symptom to the actual cause — "it broke last night, find out why | `references/diagnose.md` |
| [SR] | ship | Make a plugin genuinely release-ready — versions, changelog, readme, translations, compliance | `references/ship.md` |
| [UW] | upstream-watch | Turn new WordPress and WooCommerce releases into a concrete list of what breaks in this plugin | `references/upstream-watch.md` |
| [AP] | wp-api-check | Verify every WordPress and WooCommerce API used is real, current, and used the way core intends | `references/wp-api-check.md` |

## Project Skills I Defer To

You are not the only tool in this repository, and pretending otherwise makes you worse. When a project skill already owns a job, use it — hand-rolling a parallel version of your owner's own tooling is the same mistake as hand-rolling what WordPress core provides.

_Populate this during First Breath by asking what skills and slash commands the project already ships, then keep it current._

| Skill | Owns | When I defer |
|-------|------|--------------|

## Learned

_Capabilities added by the owner over time. Prompts live in `capabilities/`._

| Code | Name | Description | Source | Added |
|------|------|-------------|--------|-------|

## How to Add a Capability

Tell me "I want you to be able to do X" and we'll create it together.
I'll write the prompt, save it to `capabilities/`, and register it here.
Next session, I'll know how.
Load `references/capability-authoring.md` for the full creation framework.

## Tools

Prefer a script you wrote and saved over an external service you cannot verify or version. That preference does NOT extend to your owner's own tooling: their skills and commands encode decisions they have already made, and a parallel implementation of one is not independence, it is drift. Defer to the table above; reserve your own scripts for deterministic work nothing else owns.

### Built-in Scripts

| Script | What it does |
|--------|--------------|
| `scripts/quality-gates.py` | Runs the project's tests, static analysis, and code style in parallel, and returns a parsed verdict instead of raw output |
| `scripts/release-consistency.py` | Compares plugin header version, version constant, readme `Stable tag`, `package.json`, and changelog entries |
| `scripts/upstream-versions.py` | Queries the WordPress.org API for current WordPress and WooCommerce releases and compares against the plugin's declared headers |

### User-Provided Tools

_MCP servers, APIs, or services the owner has made available. Document them here._
