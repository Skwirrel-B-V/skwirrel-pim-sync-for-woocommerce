---
name: codex-pr-babysitter
description: Babysit a GitHub pull request through repeated Codex review cycles. Triggers "@codex review", waits for the Codex bot, triages and verifies every finding, replies in each thread, fixes what is worth fixing, pushes, and re-triggers until Codex is clean or a limit is hit. Reads human replies inside Codex threads as context. Use only when the owner explicitly requests Codex review, babysitting, or handling Codex findings, or a caller has already obtained that authorization. Saying a task or PR is ready alone does not start monitoring.
---

For Codex, first read `.claude/skills/CODEX.md` in the workspace root.


# Codex PR Babysitter

An autonomous review loop against **one reviewer only: the Codex GitHub bot**. Everything else on the PR is noise for the purposes of this skill.

Requires: a GitHub remote and the Codex GitHub app installed on the repo, plus either an
authenticated `gh` CLI (local) or the GitHub MCP server (managed/remote sessions).

⚠ **In a cloud/managed session this loop is event-based only — never polled.** Phase 3
Step 2's waiting apparatus is for local runs. See **Running this without `gh`** near the
end before running it anywhere but a laptop.

## Scope rules (non-negotiable)

- Only act on findings **opened by** the Codex bot account: `chatgpt-codex-connector` in GraphQL (`author.login`), `chatgpt-codex-connector[bot]` in REST. Both spellings are the same account.
- **Human replies inside a Codex thread are read as context.** They are usually the most valuable input on the PR: a teammate saying "we do it this way on purpose" or "yes, fix this" settles the verdict faster than any code trace. Read the whole thread, not just the first comment.
- A human reply does not make a thread yours to answer twice. At most one reply per thread per round, and none at all if the human input was noise.
- The trigger phrase is always `@codex review`. Nothing else.
- Threads opened by anyone else, and standalone human comments, CodeRabbit, Copilot, Sonar, CI bots: **read nothing, reply to nothing, fix nothing**. If one looks important, mention it once in the final report and move on.
- Never merge. Never force-push. Never rebase. Never close the PR.
- Never use `@codex fix it`. This skill does the fixing.

### Weighing human replies

Human replies in a Codex thread are input, not orders. Judge each one before using it.

**Use it** when it carries information you cannot get from the code:

- Product or business intent. "Retry is capped at 3 because the payment provider charges per attempt."
- Repo history. "We tried the mutex here, it deadlocked under load."
- An explicit decision from someone who owns the code. "Out of scope, we handle this in the billing rewrite."
- A correction of the bot. "Codex is wrong, that path is unreachable."

Treat these as strong evidence. If a human owner already settled the finding and no code change is needed, reply once confirming you followed their call, then resolve. If a human contradicts a fix you made in an earlier round, revert it and say so.

**Ignore it** when it adds nothing:

- Reactions with no content. "lol", "+1", "same", emoji-only.
- Vague drive-by opinions with no reasoning. "feels wrong", "hmm not sure about this".
- Comments about something else entirely: CI flakiness, merge conflicts, deploy timing, unrelated tickets.
- Bikeshedding on naming or formatting that no repo convention supports.

Ignoring means exactly that: no reply, no fix, no mention in the thread. Do not argue with a dumb comment, and do not thank someone for an emoji.

**Ask when it is ambiguous.** Use AskUserQuestion, batched with the other ASK items, when:

- A human reply conflicts with your own verified read of the code.
- Two humans disagree in the same thread.
- A human asks for something that would change what the PR is for. Scope changes are never yours to accept, no matter who asked.
- The reply is a question directed at the PR author rather than a decision.

When in doubt about whether a reply is signal or noise, ask. A wasted question costs one prompt. Acting on noise costs a commit and the user's trust.

## Completion and duplicate protection

Share the per-PR ledger with `review-pr`: reviewed head/review ID, pending
trigger/head, handled findings, round counts, terminal state and stop reason.
Preserve it across callers and resumed turns; do not reset budgets on re-entry.
Use existing session/task state and GitHub review evidence, not heartbeat posts.

Before baseline tests, subscriptions or triggers, read PR state/head and
available review evidence once:
- Merged or closed: finish immediately.
- Existing clean Codex result: reuse it and finish, unless the owner explicitly
  requests a fresh review. Later commits alone do not override this rule.
- Review already pending: reuse its trigger and wait for its result. Never post
  a second trigger for that head.
- Same head already reviewed with findings: handle those findings. Without a
  pushed fix, do not trigger another review merely to confirm rejection or
  deferral; report any unresolved blocker instead of claiming success.
- Terminal run: stay stopped unless the owner explicitly requests a restart.

At **every stop condition**, record the reason and stop all monitoring owned
by this run. Cancel its local polling process, background jobs, timers and PR
activity subscription through the supported mechanism. Do not claim cleanup
succeeded without confirmation; report an unavailable cancellation mechanism.
Ignore late events for terminal runs without more GitHub reads or model work.
Waiting for merge or manual tests is not a reason to keep babysitting.
A completed run never schedules another check-in.

## Phase 1. Preflight

```bash
gh pr view --json number,url,state,isDraft,additions,deletions,changedFiles,headRefName,baseRefName,headRefOid
```

Then:

1. Abort if the PR is closed or merged. Warn if it is a draft: Codex reviews drafts only when explicitly triggered.
2. Confirm the local branch matches `headRefName` and the working tree is clean. Stop and ask if it is dirty.
3. Find the verification commands (test, lint, typecheck, build) from `package.json`, `Makefile`, `composer.json`, `CONTRIBUTING.md` or CI config. If you cannot find them, ask once. Do not guess.
4. Run them to get a baseline. If the suite is already red before you touch anything, say so and ask whether to continue. A red baseline makes the "tests went red" stop condition useless.
5. Compute `PR_SIZE = additions + deletions`.

## Phase 2. Configuration gate

**If `PR_SIZE > 1500`**, first ask a single confirmation with AskUserQuestion:

> This PR is {PR_SIZE} lines across {changedFiles} files. Large PRs burn review budget fast and Codex quality drops. Start the loop?
> Options: **Start** / **Split first** / **Cancel**

On "Split first", stop and give a concrete split suggestion by file cluster. Do not start the loop.

**Then always** ask the configuration block in one AskUserQuestion call, four questions:

| Question | Options | Default |
|---|---|---|
| Max lines to review per pass | 2000 / 4000 / No limit / Custom | **2000** |
| Re-validate the whole PR diff every round | No (delta only) / Yes | **No (delta only)** |
| Max review rounds | 3 / 5 / 10 / 20 | **3** |
| Write a summary comment each round | No / Yes | **No** |

Skip this call entirely if the user already stated the settings in their prompt (for example "babysit this PR, 5 rounds, no summary"). Echo the resolved settings back in one line before starting either way.

**What the settings actually mean:**

- **Max lines per pass** is the diff budget you may read when verifying findings. If the full diff exceeds it, do not read the whole thing. Read the hunks the findings point at plus surrounding context, and say in the round header that you are working from partial context.
- Read the initial diff within budget. On later rounds, delta-only includes the
  changed commits, finding locations and affected callers/tests; expand context
  when a dependency requires it. Do not re-read unrelated files.
- **Re-validate whole diff = Yes**: each round you re-read the complete PR diff (within budget) before judging findings, so regressions introduced by your own earlier fixes get caught. **No**: only read `git diff` since the previous round plus the files the findings touch. Faster, cheaper, blinder.
- **Max review rounds** counts `@codex review` triggers you post. The convergence stops below will almost always fire first.
- **Summary** adds one top-level comment per round describing what changed. Off by default because it clutters the PR.

## Phase 3. The loop

Announce `Round N/max` at the start of every round.

### Step 1. Trigger

Apply completion and duplicate protection before every trigger. Verify the PR
is still open and the pushed head differs from the last reviewed head. The
first authorized trigger and an explicitly requested fresh review are the
exceptions to the changed-head requirement. Enforce the remaining round budget.

Post a **fresh top-level comment**, not a thread reply. Codex only picks up top-level PR comments.

```bash
gh pr comment <number> --body "@codex review"
```

Capture the returned comment URL. Extract the comment id, you need it to read the reaction.

### Step 2. Wait

**Local runs only.** There is no event stream a local CLI can subscribe to. GitHub webhooks push to a public HTTP endpoint, which a laptop does not have. So this is polling. Make it cheap and make it fail fast.

In a cloud/managed session, skip this entire step — subscribe to PR activity and end the turn instead. See **Running this without `gh`**.

Codex reacts 👀 when it picks the job up, then either posts a review or reacts 👍 on your trigger comment when it has nothing to say.

**Backoff schedule:**

| Elapsed | Interval |
|---|---|
| 0 to 2 min | 10s |
| 2 to 5 min | 30s |
| 5 min onward | 60s |

**Two checkpoints, not one:**

1. **👀 within 2 minutes.** If Codex has not acknowledged, it almost certainly did not pick up the job: app not installed, workspace deactivated, or the trigger comment was malformed. Stop and report. Do not wait 15 minutes for something that was never queued.
2. **Result within the review timeout.** 15 minutes default, 30 minutes when `PR_SIZE > 1500`. Large diffs genuinely take longer.

Use a bounded local script for waiting; do not wake the model on each tick.
Conditional requests reduce API traffic, not model usage. Keep the `ETag` from each response and send it back as `If-None-Match`. A `304` costs no rate limit quota:

```bash
# reaction on the trigger comment
gh api repos/{owner}/{repo}/issues/comments/{comment_id}/reactions \
  -H "If-None-Match: {last_etag}" --include

# reviews submitted after the trigger timestamp
gh api graphql -f query='
query($owner:String!,$repo:String!,$pr:Int!){
  repository(owner:$owner,name:$repo){
    pullRequest(number:$pr){
      reviews(last:10){nodes{author{login} body state submittedAt url}}
    }
  }
}' -F owner={owner} -F repo={repo} -F pr={number}
```

GraphQL has no conditional request support, so poll the cheap REST reaction endpoint on the fast tier and only run the GraphQL review query when the reaction check changes or on the 60s tier.

**Outcomes:**

- 👍 from the Codex bot and no new review → **clean. Exit the loop.**
- New review from the Codex bot → continue to step 3.
- No 👀 after 2 minutes → **not queued.** Report and stop.
- Timeout reached → report and stop. Do not re-trigger blindly, that doubles the queue.
- A Codex reply containing rate limit, quota, workspace or "connect to GitHub" language → **usage limit. Exit immediately** and surface the exact message.

### Step 3. Read

Pull all review threads and keep only Codex ones. In GitHub, Codex flags only P0 and P1 issues, so volume should be low. High volume is itself a signal that something is off.

```bash
gh api graphql -f query='
query($owner:String!,$repo:String!,$pr:Int!){
  repository(owner:$owner,name:$repo){
    pullRequest(number:$pr){
      reviewThreads(first:100){
        nodes{
          id isResolved isOutdated path line
          comments(first:20){nodes{id databaseId author{login} body createdAt}}
        }
      }
    }
  }
}' -F owner={owner} -F repo={repo} -F pr={number}
```

Filter to threads whose **first comment** author is the Codex bot and where `isResolved` is false. Then read **every comment in those threads**, including replies from humans. The `comments(first:20)` block already returns them.

Keep a ledger of comment ids handled in earlier rounds so you never re-argue a settled thread. A **substantive** new human reply on a handled thread reopens it: read it, act on it, reply once. Noise does not reopen anything, log the id and move on.

### Step 4. Verify before you judge

The single biggest failure mode is fixing a bug that does not exist because the bot misread control flow or hallucinated an API. For every finding:

1. Open the actual file at the actual line. Read it.
2. Trace the claim. Does the code path the bot describes exist?
3. Only then assign a verdict.

Never assign a verdict from the comment text alone.

### Step 5. Triage

| Verdict | Meaning | Action |
|---|---|---|
| **FIX** | Real, in scope, worth fixing now | Fix it this round |
| **FIX-LATER** | Real, but out of scope for this PR | Reply, open an issue, link it |
| **REJECT** | Wrong, hallucinated, or based on a misread | Reply with the evidence, no code change |
| **STYLE** | Preference, no behavioural impact | Fix only if it matches an existing repo convention, otherwise reject |
| **ASK** | Genuinely undecidable without product context | Batch for one question round |

Collect all ASK items and raise them in a single AskUserQuestion call, max four, at the end of triage. Do not interrupt three times per round.

### Step 6. Reply to every thread

Every Codex finding gets a reply. Not every finding gets a commit. Write so any adult reading the PR next month understands the decision without reading the code.

```bash
gh api repos/{owner}/{repo}/pulls/{number}/comments/{databaseId}/replies \
  -f body="..."
```

Use the `databaseId` of the **first** comment in the thread.

Templates:

- FIX: "Correct. `validateToken` did return before the expiry check on the refresh path. Moved the check above the early return in `auth/token.php:88`. Added a test for the expired-refresh case."
- FIX-LATER: "Real issue, but it predates this PR and touches the whole billing module. Out of scope here. Tracked in #412."
- REJECT: "Not applicable. `$items` is guaranteed non-empty by the `count()` guard on line 41, so the null check would be dead code. Leaving as is."
- STYLE: "Fair point, but the codebase consistently uses snake_case for these DTO fields. Keeping it consistent beats the general convention here."
- HUMAN-SETTLED: "@marieke already covered this above. Leaving the retry count at 3 as she described. No change made."

Then resolve the FIX and REJECT threads:

```bash
gh api graphql -f query='mutation($id:ID!){resolveReviewThread(input:{threadId:$id}){thread{isResolved}}}' -F id={threadId}
```

If the team dislikes bots resolving threads, skip this and say so in the report.

### Step 7. Fix and push

- One commit per logical fix, or one commit per round if the fixes are small. Message: `fix: address Codex review round N`.
- Re-run tests, lint and typecheck. **If they go red and you cannot fix it in one attempt, stop the loop and report.**
- Push. Verify the push landed before the next trigger:

```bash
git push && gh pr view --json headRefOid
```

Re-triggering before pushing makes Codex review stale code and wastes a full round. This is a hard gate.

### Step 8. Summary comment (only if enabled)

One top-level comment, after the push, before the next trigger:

```
**Round N summary**
Fixed: 3 (null guard in TokenService, off-by-one in pagination, missing await in sync job)
Rejected: 2 (see threads)
Deferred: 1 (#412)
Tests: 214 passed, 0 failed
```

Post it as a separate comment from the `@codex review` trigger. Do not combine them, the trigger comment should contain nothing but the trigger.

## Stop conditions

Exit the loop on the **first** of these:

1. **Clean.** Codex reacts 👍 or posts a review with no findings. Success.
2. **Round cap** reached.
3. **Usage or rate limit** hit on the Codex side.
4. **Two consecutive rounds with zero FIX verdicts.** Codex will always find one more nitpick. This is the real convergence test.
5. **A rejected finding comes back a third time.** You disagree and the bot will not budge. Escalate to the user.
6. **Tests went red** and one repair attempt failed.
7. **A finding would change what the PR is for.** Scope changes are the user's call, not the loop's.
8. **No 👀 within 2 minutes**, or no result within the review timeout (15 min, 30 min for large PRs).

9. **PR merged or closed, or owner cancelled.** Stop immediately when observed.

Always say which condition fired and apply completion cleanup. A clean result
must belong to the pending trigger and reviewed head; an old thumbs-up is not
evidence for a new round. Resolved threads, rejected findings, or reaching the
cap alone do not make a result clean.

## Final report

```
Codex babysitter finished. Stop reason: clean review received; monitoring stopped.

PR: #241 (1,284 lines, 18 files) · 4 rounds · 12 findings

Fixed 7      Rejected 3      Deferred 1 (#412)      Asked 1
Commits pushed: 4
Tests: 214 passed, 0 failed. Lint clean.

Still open:
- Thread on OrderMapper.php:112. Codex insists on a defensive clone, I disagree twice, your call.
- One human comment from @marieke on the migration file, not handled by this skill.
```

## Running this without `gh`

Every command above assumes the `gh` CLI. Claude Code on the web, in a GitHub
Action, or any managed remote container has **no `gh`** and reaches GitHub through
the GitHub MCP server instead. The loop still runs there, but two of its moving
parts have to change shape. Everything below was verified in a live session on
2026-08-13, not inferred from documentation.

### Command translation

| `gh` command | MCP equivalent |
|---|---|
| `gh pr view --json …` | `pull_request_read` method `get` |
| `gh pr comment <n> --body "@codex review"` | `add_issue_comment` with `issue_number` = the PR number |
| `gh api graphql … reviewThreads` | `pull_request_read` method `get_review_comments` |
| `gh api …/comments/{id}/replies` | `add_reply_to_pull_request_comment` |
| `gh api graphql resolveReviewThread` | `pull_request_review_write` method `resolve_thread` |
| `gh pr checks` / CI status | `pull_request_read` method `get_check_runs` |

Two id systems, and mixing them up costs a round:

- **`threadId`** is the GraphQL node id (`PRRT_kwDO…`), returned as `id` on each
  entry of `review_threads`. It is what `resolve_thread` wants.
- **`commentId`** for a reply is the **numeric** id of the thread's FIRST comment,
  which you read off the end of that comment's `html_url` (`…#discussion_r3774462004`
  → `3774462004`). Passing the node id here fails.

Use `get_check_runs`, not `get_status`: the latter returns
`403 Resource not accessible by integration` for a GitHub App token.

### In the cloud the loop is EVENT-BASED ONLY. No polling. No exceptions.

The skill polls because a local CLI has no way to receive a webhook. A managed
remote session **does**: `subscribe_pr_activity` (claude-code-remote MCP) delivers
Codex reviews into the conversation as `<wake reason="external-event">` envelopes
**carrying the full comment bodies**. Measured: a twelve-finding Codex review
arrived that way with every finding's text intact, no API call and no polling.

The whole waiting apparatus of Phase 3 Step 2 — the backoff table, the two
reaction checkpoints, the ETag advice, the review timeout — exists to simulate an
event you can simply receive here. Do not emulate any of it. Replace Step 2 with:

1. `subscribe_pr_activity` once, **before** triggering.
2. Post `@codex review`.
3. **End the turn.** Say what you are waiting for and stop talking.
4. On activity, check the terminal state first. If active, verify the event
   belongs to the pending review, then triage, reply and fix. Re-trigger only
   after a pushed change and within budget. A clean result stops monitoring.

**No polling substitutes.** Not a `sleep` loop (foreground `sleep` is blocked in
these containers anyway), not a background command, and specifically **not a
`send_later` self check-in** — a scheduled wake-up is polling wearing a different
hat. It burns a turn per tick, produces "nothing changed" noise the user has to
read, and drifts out of date the moment reality moves: check-ins written at
11:00 were still asserting a branch was unpushed at 12:30, an hour after it went
up. The event is the signal. Waiting costs nothing only if you actually wait.

**If the subscription is unavailable, stop and report.** Do not fall back to
polling. A loop that cannot be woken is not a degraded loop, it is a different
and worse one, and the user should get to decide whether to run it elsewhere.

This also rewrites stop condition 8, which is polling-shaped. In event mode there
is no acknowledgement to miss and no timeout to hit: either the session is woken
or it is not. Do no model work while waiting; this is not a guarantee about
platform billing. Drop the
2-minute and 15-minute checks entirely. If the user wants to give up on a review
that never arrives, they will say so — that is their call, not a timer's.

### What genuinely does not work here

Be honest about these rather than faking them:

- **Reaction attribution.** The skill's two checkpoints read 👀 and 👍 *from the
  Codex bot* on the trigger comment. MCP can *add* a reaction but exposes no
  verified way to read *who* reacted. The webhook path replaces checkpoint 1
  (a review arriving is the acknowledgement). Checkpoint 2's "👍 means clean"
  has no substitute — treat a Codex review with zero findings as the clean
  signal, and say in the report that the 👍 path was unavailable.
- **API outages are not git outages.** The GitHub MCP connection returned `404`
  on this repo for the rest of the session after a mid-session reconnect, while
  `git fetch`/`push` kept working through a separate proxy. When that happens:
  branch state is still readable via `git fetch` + `git merge-base`, pushes still
  land, webhooks still deliver — but triggering, reading threads and replying are
  all blocked. Report the outage and stop; do not pretend the loop ran.
- **Repo scope.** These sessions are scoped to an explicit repo list. A PR in a
  repo outside it is unreachable no matter which tool you reach for.

### What does not change

The parts that make this skill worth having are environment-independent, and they
are the parts to keep when everything else is translated: verify every finding
against the actual file before judging it, reply to every thread whether or not
you changed code, reject what is wrong with the evidence that makes it wrong, and
never re-trigger before the fix is pushed.

## Anti-patterns

- Obeying every comment. A bot with 100 percent compliance is a liability, not a reviewer.
- Silent fixes. If you changed code, the thread says what and why.
- Re-triggering without pushing.
- Reading the finding but not the file.
- Bundling unrelated refactors into fix commits.
- Answering the human reviewers. Out of scope, on purpose.
