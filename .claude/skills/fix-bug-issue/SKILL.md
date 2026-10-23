---
name: fix-bug-issue
description: Fix a bug reported in a GitHub issue, end to end - find the issue, triage and reproduce it, diagnose the cause, fix it test-first in a worktree, open and review the PR, and hand manual testing to the tester in a [TEST] sub-issue. Takes one issue or a list, and loops over sub-issues of a parent issue after one confirmation. Use when the user says "fix bug #123", "fix-bug-issue", "fix these bugs", "fix this bug" with issue numbers or links, or hands over bug issues to be solved.
---

For Codex, first read `.claude/skills/CODEX.md` in the workspace root.


# Fix a bug issue

The bug flavour of `pick-up-issue`: the same board flow, with triage and
diagnosis up front and a tester hand-off at the end. **Read
`.claude/skills/pick-up-issue/SKILL.md` first** — its ground rules, owner
lookup, resume check, and steps 2-5 apply here unchanged unless this file
says otherwise.

Skills used (all in `.claude/skills/`): `triage`, `diagnosing-bugs`, `tdd`,
`review-pr` (wraps `code-review`), `grilling`, `pr`, `codex-pr-babysitter`.

**Tester:** `vnlashin-tester` (Vladimir). Change it here only; the rest of
this file says "the tester".

**Inside a loop**, a question that only the owner can answer (the checkpoint
in 3.5, "may I close this?") waits for the answer. Anything that needs
someone else (the reporter, access to an environment) is a stop: note it,
skip the item, and go on with the next.

## 1. Find the issues

Takes one or more issues: `/fix-bug-issue 123`, `/fix-bug-issue 123 124 130`,
or one or more URLs, mixed if needed.

- **URL** (`https://github.com/<owner>/<repo>/issues/<n>`): use it as is.
- **Number only**: look it up in the repos you have checked out (`make
  addons` shows the selection; usually `skwirrel-core`) with
  `gh issue view <n> --repo <owner>/<repo>`.
- **Any issue not found, or found in more than one repo: stop and ask**
  which issue is meant, before starting on any of them. Never guess.

## 2. Build the work list and confirm once

1. **Expand sub-issues, all the way down.** For each issue, list **all** its
   sub-issues, open and closed:
   `gh api --paginate repos/<owner>/<repo>/issues/<n>/sub_issues --jq '.[] | "\(.state) \(.html_url) \(.sub_issues_summary.total // 0) \(.title)"'`
   - `[TEST]` sub-issues (tester hand-offs from an earlier run) don't count:
     ignore them here. An issue whose only sub-issues are `[TEST]` issues is a
     bug like any other, so a re-run after a failed test reaches its resume
     check and continues on the existing branch and PR.
   - An issue with **any** other sub-issues is a parent. It gets no PR, only the
     summary comment in step 5. Its open sub-issues go on the work list in
     their order; closed ones are left out. A parent whose sub-issues are all
     closed adds nothing: say so in the list.
   - A sub-issue that has sub-issues of its own (total > 0) is a parent too:
     expand it the same way (GitHub allows several levels).
   - Sub-issues can live in **another repo**: keep each item as
     `<owner>/<repo>#<n>` (from `html_url`) and use that repo for every later
     step. Never assume the parent's repo. If that repo is not checked out
     under `repositories/` (outside your `repos.local` selection), mark the
     item "not checked out" in the list: the owner chooses to clone it first
     (`scripts/addons.sh enable <manifest name>`, then `make clone`) or to
     skip it.
   - An issue without sub-issues goes on the list itself. Drop duplicates by
     `<owner>/<repo>#<n>`, and never expand the same issue twice.
2. **Mark what is not a bug.** An item counts as a bug when its issue type is
   Bug (`gh api repos/<owner>/<repo>/issues/<n> --jq '.type.name'`), its title
   starts with `[BUG]`, or its text clearly describes broken behaviour. Mark
   everything else (features, questions, `[QA]`/`[TEST]` issues) with what it
   looks like instead.
3. **Accept every item before starting any.** Board fields need a board
   item, so first add every **bug** that isn't on the Development board yet
   (marked items wait for the owner's decision in 2.4; add them only if
   they go to `pick-up-issue`): check with
   `gh project item-list 2 --owner Skwirrel-B-V --format json --limit 1000`,
   add with `gh project item-add 2 --owner Skwirrel-B-V --url <issue url>`.
   Then, for each bug, read it as in `pick-up-issue` step 1 (its parent
   too, also when the bug was passed on its own):
   - Already **Needs clarification**? Apply the hard stop from the
     `pick-up-issue` Start gate: it only moves on when a comment answers the
     question.
   - Otherwise set **Developer acceptance.**: **Accepted** if the report
     says what is broken and how to see it, and set **Size** if it is empty
     (estimate it). If not, **Needs clarification** with one comment saying
     exactly what you need to accept it.
   Then run the `pick-up-issue` **Start gate**. Items that fail it stay on
   the list as "skip: <what is needed>" and are never started in this run.
4. **Show the list and ask once.** One line per item: number, title, repo,
   and for marked items "looks like a feature: skip, or handle with
   `pick-up-issue`?". Also mark items that 2.3 **added to the board**.
   Explain that each item gets the full cycle below, one after the other,
   each with its own worktree, branch, PR and test hand-off.
   Ask: "Shall I work through these N issues in this order?" Wait for a yes
   and for a decision on every marked item.
   - A single bug with no sub-issues needs no list and no question. Still do
     the board check (add it if it isn't on the board, say so in one line),
     the acceptance and Start gate from 2.3, and the checkout check: if its
     repo isn't checked out, ask once whether to clone it or stop. Then go to
     step 3, or stop and say what is needed if the gate failed.
5. **Run the loop.** Bugs that passed the Start gate go through steps 3-4;
   items the owner sent to `pick-up-issue` follow that skill instead. An item
   that stops (Needs clarification, wontfix, blocked) is skipped with a note;
   the loop goes on. Every Status or Developer acceptance change, here and
   in 2.3, also updates the parent (`pick-up-issue` "Parent status").

## 3. Per issue: triage, diagnose, agree

1. **Resume check** as in `pick-up-issue`, before repeating acceptance,
   triage or diagnosis. On a resumed item, step 2.3 reuses that check before
   any board write. Existing fix branch/PR: inspect its head, previous
   diagnosis, owner-approved seam and latest failure evidence. Continue at
   the first unfinished step. A failed manual test is reproduced on the
   existing fix head, not automatically on the base branch. Compare the
   base only when needed to distinguish a regression. Re-diagnose and
   revisit the checkpoint only when new evidence changes the cause, seam,
   scope or approved plan. An already merged PR gets cleanup and reporting,
   not a new fix cycle. Stop on a closed unmerged PR and ask whether to resume.
2. **Triage for a new issue or invalidated diagnosis** with the `triage` skill, but only its steps 1 (gather context)
   and 3 (verify the claim), in that order. Do not stop for direction after
   its step 2 and do not post an agent brief: the checkpoint in 3.5 is the
   one stop. Run `git fetch origin` first, then reproduce read-only on the
   repo's fresh base (`origin/<base>`), no code changes yet. The outcome maps to the board (its **Skwirrel tracker**
   section):
   - Reproduced -> it stays **Accepted**, go on.
   - Cannot reproduce, or details missing -> **Needs clarification** with
     a comment saying what you need to reproduce it. Stop for this issue.
   - Already fixed, or not a bug -> comment and **ask before closing**.
3. **Worktree** as in `pick-up-issue` step 3.2, branch `fix/<number>-short-slug`.
   No Status change yet.
4. **Diagnose** with `diagnosing-bugs` in the worktree, **phases 1-4 only**
   (feedback loop, reproduce, hypothesise, instrument). Stop before its phase
   5: the fix belongs to `tdd`. The result is the cause, a red-capable
   command that shows the bug, and a **proposed** seam for the regression
   test. Do not add a test to the repo's suite yet: `tdd` writes no test at
   an unconfirmed seam, so the regression test is written after the
   checkpoint. If no correct seam exists, that is the finding for the
   checkpoint, with two options for the owner: **create the seam** as the
   first task of the fix (then `tdd` as usual), or **fix without a regression
   test**, using the diagnosis command as red and green, and say so in the
   PR and the test hand-off. If no feedback loop can be built at all, stop for this issue
   and say what is needed. Either way, finish with the **cleanup** from its
   phase 6: remove every log line, probe and throwaway harness, so the
   worktree holds no diagnosis leftovers (`git status` clean apart from what
   you deliberately keep, such as the repro command in a note).
5. **One checkpoint with the owner**, in one message:
   "Bug confirmed: <symptom>. Cause: <cause>. I'll fix it at seam <seam>,
   with this task list: <tasks>. OK?" This is also the `tdd` seam approval.
   - Grill only if the fix needs a decision the owner has to make (`grilling`).
   - Split into new sub-issues **only** if parts can ship on their own, and
     only after a yes. A split turns this issue into a parent: remove its
     worktree and branch, give it no PR (only the summary comment in step
     5), and put the new sub-issues on the work list in its place. Add each
     new sub-issue to the Development board and run the acceptance and
     Start gate (both in 2.3), then take it through the full cycle from
     3.1.
   - **On a yes:** post a short triage comment on the issue (symptom, cause,
     task list), then `pick-up-issue` step 2 (Ready and assign) and step 3.1
     (In progress).
   - **On a no**, or when 3.4 stopped: remove the worktree and branch, leave
     Developer acceptance as it is, and do not assign anyone.

## 4. Per issue: fix, PR, review, test hand-off

1. **Fix** with `tdd`: the first red is the regression test at the approved
   seam, written from the diagnosis command. Make it green with the smallest
   change, then one cycle per nearby edge case. If the owner chose "fix
   without a regression test", the diagnosis command is the red and the
   green, and the PR body says why there is no regression test. Run the repo's
   own checks.
2. **PR and review**: `pick-up-issue` steps 4 and 5 (PR body with `pr`,
   `review-pr` rounds within its shared budget, with every thread worked.
   Reuse the recorded Codex decision/result; ask about
   `codex-pr-babysitter` only if no decision has been made. A cap or other
   unresolved stop condition ends this item with a report, not another loop).
3. **Test** (replaces `pick-up-issue` step 6):
   - Write the checks: the issue's reproduction steps, the Definition of Done,
     and regressions near the change. One line each: "do X, expect Y".
   - Apply `pick-up-issue`'s "Reuse completed work": map valid evidence to
     these checks first. Run every remaining check the agent can run (repo tests, the `run` skill / `skw`
     against real data — never fabricated data) and keep short evidence.
     The run can go to a lighter-model subagent, as in `pick-up-issue`
     step 6.4.
   - **A check fails:** set Status back to In progress, fix it with `tdd`,
     push, review the new changes (`pick-up-issue` step 5), and test again.
     Keep the existing review counters. Do not start another review cycle
     after its cap or a terminal stop without explicit owner authorization.
     Never hand over red evidence.
   - **Manual steps left** -> create the `[TEST]` sub-issue below, assigned to
     the tester. **Resuming after a failed test?** Don't create a second one:
     update the open `[TEST]` sub-issue (new steps and evidence, a comment
     on what changed) and assign the tester again. If no manual steps are left,
     close it with that note.
   - **No manual steps** -> one comment on the issue: "Everything checked
     automatically, nothing left for the tester. Ready to merge.", with the
     evidence and the owner mentioned. No sub-issue.
   - Set the issue's **Status = In test**. Do not ask to merge here: with
     manual steps the tester goes first; without them the comment above
     hands the merge to the owner. Report the PR link and stop for this
     issue.

### The [TEST] sub-issue

```bash
gh issue create --repo <owner>/<repo> --assignee <tester> \
  --title "[TEST] <original title> (#<n>)" --body-file <file>
id=$(gh api repos/<owner>/<repo>/issues/<new> --jq .id)
gh api repos/<owner>/<repo>/issues/<n>/sub_issues -X POST -F sub_issue_id=$id
gh project item-add 2 --owner Skwirrel-B-V --url <new issue url>   # then Status = Ready
```

Body (plain language; the tester is not a developer):

```markdown
> *This was generated by AI.*

Fix for #<n> in <PR link>. Test it on <environment / branch>.

## Test by hand
- [ ] <do X> -> expect <Y>
- [ ] ...

## Already checked automatically by Claude
- [x] <what> -> <evidence: test name, command and result>
- [x] ...

If something fails: comment here with what you did and what you saw, and
set #<n> back to **In progress**. The fix goes into the same PR; no new bug
issue.

If everything passes: say so here, comment "Manual tests passed" on the PR,
and close this issue. #<n> is then ready to merge by its owner.
```

## 5. Report

After the last issue (or the only one): for every parent that was expanded,
post one summary comment on it. Then report per issue: final Status, PR
link, [TEST] sub-issue or "all automatic", parent Status, and anything
skipped or still open with what the owner needs to do, one short line each.
