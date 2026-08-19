---
name: quality-gate-environment
description: PHPStan's parallel workers OOM on Jos's machine and integration tests need Docker running — how to actually get a green gate locally.
metadata:
  type: project
---

`vendor/bin/phpstan analyse` fails on Jos's machine with "Child process error: PHPStan process
crashed because it reached configured PHP memory limit": the *parallel worker* dies, not the main
process. `--memory-limit=2G` fixes it and is now the documented command everywhere (composer
`analyse` script, CLAUDE.md, AGENTS.md, README.md, docs/, .claude/rules/testing.md).

`--memory-limit=1G` is NOT enough — it still crashes a worker, which is easy to misread as "the flag
does not reach the workers at all". It does; 1G is simply too low. Running single-process with
`--debug` also works, but it is much slower and is a diagnostic, not the everyday command.

Integration tests (`npm run test:integration`) need Docker/OrbStack running; the socket at
`~/.orbstack/run/docker.sock` is often absent, and then the suite cannot be started at all.

**Why:** both are local environment quirks, not code problems. Reading the parallel-worker crash as
"my change broke static analysis" wastes a cycle, and silently skipping integration tests without
saying so hides real risk.

**How to apply:** run `vendor/bin/phpstan analyse --memory-limit=2G` (or `composer analyse`). If a
worker still crashes, raise the limit before suspecting the change — do not conclude the flag is
ineffective from a single too-low value. When Docker is down, say plainly in the report that the
integration suite was not executed rather than implying all gates passed.
