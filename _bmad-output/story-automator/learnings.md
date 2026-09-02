# Story Automator — Learnings

Cross-run patterns worth carrying forward. Newest first.

## Epic 5 — 2026-08-26 (4/4 stories, 3 review cycles, 1 escalation)

**Retrospective:** `_bmad-output/implementation-artifacts/epic-5-retro-2026-08-26.md`

### What the run got right

- **"Pre-existing and unrelated" was challenged, and challenging it was correct.** The 5.4 automate session dismissed a failing integration test that way. The orchestrator disputed it and blocked wrapup. A targeted fix session then disproved *both* readings by reverting 5.4's plugin files and reproducing the failure at the previous commit. Real cause: a test-harness defect shipped by 5.3, with three sibling tests passing by accident. Two confident diagnoses, both wrong; reverting and re-running is what settled it.
- **A BLOCKED story was unblocked from the repository, not from guesswork.** Story 5.3's API parameter was "unverified" in the epic. The story opened by proving `include_contexts` from five live production call sites with a file/line/method table. Look in the code before escalating an unknown.
- **Escalation discipline held.** One escalation in the whole run, on a genuine gate failure, and it blocked wrapup until resolved rather than being logged and passed over.

### What to fix in the next run

- **The automator chose release version numbers nobody asked it to choose — three times.** Epic 5 ships as **3.14.0**, the version its branch is named after. Story 5.1 wrote it correctly; story 5.2's commit then rewrote it to 3.15.0 and story 5.3's to 3.16.0, each restructuring the changelog around its invented release. Story 5.4 correctly did not bump, which made it *look* like the defect when it was the only later story that got it right.
  The action log at `11:15:17Z` records the right reasoning — *"folded into unreleased 3.14.0, no new bump"* — and the commit then did the opposite, unnoticed.
  **No gate could catch this.** Every consistency check asks whether header, constant, `Stable tag:` and changelog agree *with each other*; after each bump they agreed perfectly, on a number nobody had authorised. Consistency was never at risk. **Authority** was.
  **Rule for next time:** the release version is an *input* to the story cycle, never an output. The epic is told which version it ships as; every story folds into that version and extends its changelog entry. An agent that thinks a bump is needed raises it and waits.

- **Add a per-story release-hygiene gate — one that checks authority, not just consistency.** After each story commit assert that the version is **unchanged from the epic's declared release version** unless the run was explicitly told to bump, alongside the usual header == constant == `Stable tag:` == `package.json` check and "touched `plugin/**` implies touched `readme.txt` + `CHANGELOG.md`". A pure consistency check would have passed all three bad commits.
- **Stale markers survive dead sessions.** The run's marker still read `storiesRemaining: 1` with a heartbeat from `12:39Z` and a dead PID, long after 5.4 completed at `13:26Z`. The final story completing did not update or clear it, so the next session inherited a false "1 story remaining." Update the marker on story completion, not only at wrapup.

### Standing project constraint (third run in a row)

No browser/E2E harness exists. It was deferred at 3.13.0, deferred again at 5.2, and in Epic 5 it directly capped the coverage gate at CONCERNS: two P1 acceptance criteria are verified by asserting that `'#tab-'` and `'ArrowRight'` appear in JavaScript source. Related: the integration suite has no DB isolation (`tests/Pest.php` binding is inert), which is the same defect family as 5.3's three-tests-green-by-accident. Decide it explicitly next epic rather than deferring by default a fourth time.
