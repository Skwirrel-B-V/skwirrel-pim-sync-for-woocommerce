# Pulse

**Default frequency:** weekly — confirm with your owner during First Breath and write their answer here.

## On Quiet Rebirth

When invoked via `--headless` without a specific task, load `references/memory-guidance.md` for memory discipline, then work through these in priority order.

### Memory Curation

Your goal: when your owner activates you next session and you read MEMORY.md, you should have everything you need to be effective and nothing you don't. MEMORY.md is the single most important file in your sanctum — it determines how smart you are on rebirth.

**What good curation looks like:**
- A cold start on any request finds the floors, the gate commands, and the release procedure already there
- No entry exists that you'd skip over because it's stale, resolved, or obvious
- Patterns across sessions are surfaced — the defect this codebase keeps making, the correction your owner keeps giving
- The file is under 200 lines. If it's longer, you're hoarding, not curating.

**Source material:** Read recent session logs in `sessions/`. Extract what matters and let the rest go. Logs older than 14 days can be pruned once their value is captured.

**Also maintain:** Update INDEX.md if new organic files have appeared. Check BOND.md — has anything about your owner changed that should be reflected?

### Upstream Watch

Run `scripts/upstream-versions.py` against the plugins in MEMORY.md. If WordPress or WooCommerce has moved past what they're tested up to, read what actually changed and search the code for it — the deliverable is "here is what breaks in your plugin, at these lines", never "a new version exists". If nothing is affected, record the versions checked and say nothing further. Check deferred deprecations in MEMORY.md while you're here; if a removal version is approaching, raise it.

### Rot Audit

Sweep one area you haven't looked at recently — a class, a subsystem, the code around a recent change. Look for what the gates structurally cannot catch: missing capability checks, unescaped output, unprepared queries, queries in loops, deprecated calls, static-analysis baseline entries that have quietly grown. Depth over breadth; one area examined properly beats the whole codebase skimmed.

### Ship-Readiness Check

Run `scripts/release-consistency.py`. A version mismatch or a missing changelog entry found now costs a minute; found during a deploy it costs an afternoon. Report only what's actually inconsistent.

### Self-Improvement

Reflect on recent sessions. Where were you wrong, and was it because MEMORY.md was wrong? Did your owner correct the same thing twice — and is that in BOND.md now? Did a verification take twenty minutes that a script could do in one? Note findings in the session log for discussion next session, and propose the capability or script if there's a clear gap.

## Task Routing

| Task | Action |
|------|--------|
| `--headless` (no task) | Full priority order above |
| `--headless:memory` | Memory curation only |
| `--headless:upstream` | Upstream watch only — new WP/Woo releases and their impact |
| `--headless:audit` | Rot audit only |
| `--headless:ship` | Release-consistency check only |

## Quiet Hours
{Set during First Breath. Default: no unattended runs outside working hours, and never during a release.}

## State
_Maintained by the agent. Last upstream versions checked, last area audited, pending items._
