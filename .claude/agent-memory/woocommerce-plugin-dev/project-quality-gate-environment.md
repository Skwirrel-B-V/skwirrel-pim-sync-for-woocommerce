---
name: quality-gate-environment
description: PHPStan's parallel workers OOM on Jos's machine and integration tests need Docker running — how to actually get a green gate locally.
metadata:
  type: project
---

`vendor/bin/phpstan analyse` fails on Jos's machine with "Child process error: PHPStan process
crashed because it reached configured PHP memory limit" — raising `--memory-limit` does not help,
because it is the *parallel worker* that dies. Running it single-process (`--debug`, optionally with
`--memory-limit=2G`) analyses the whole plugin and reports correctly.

Integration tests (`npm run test:integration`) need Docker/OrbStack running; the socket at
`~/.orbstack/run/docker.sock` is often absent, and then the suite cannot be started at all.

**Why:** both are local environment quirks, not code problems. Reading the parallel-worker crash as
"my change broke static analysis" wastes a cycle, and silently skipping integration tests without
saying so hides real risk.

**How to apply:** when `vendor/bin/phpstan analyse` crashes a worker, re-run it with `--debug` before
suspecting the change. When Docker is down, say plainly in the report that the integration suite was
not executed rather than implying all gates passed.
