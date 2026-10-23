---
name: create-github-issue
description: Create well-formed GitHub issues for Skwirrel 2.0, Skwirrel 1.0 (SVN/Trac-synced) or any other repo - from an idea, an idea-to-spec document, or an issue you already have in mind. Asks what is still open in rounds, shows the drafts, and after one yes creates one issue or a parent with sub-issues, ready for people and for agents (each issue carries an Agent Brief), and files them on the Development board. Use when the user wants to create, file or open GitHub issues, or turn an idea or spec document into issues.
---

For Codex, first read `.claude/skills/CODEX.md` in the workspace root.


# Create GitHub issues

Turns an idea, a document from `idea-to-spec`, or an issue the user already
has in mind into GitHub issues that a person **and** an agent
(`pick-up-issue`, `fix-bug-issue`) can start on without further questions.

Claude Code only: needs the `gh` CLI, authenticated. Run `gh auth status`
first; if it fails, ask the user to run `gh auth login` and wait.

Uses, from the Skwirrel workspace's `.claude/skills/` (also when you started
inside one of its repos):

- `idea-to-spec/references/`: `grilling.md` (how to ask), `shaping-issues.md`
  (how to split), `document-template.md` (the document format).
- `triage/AGENT-BRIEF.md`: the principles and examples behind the Agent
  Brief. The template in step 6 is complete on its own; read this file too
  when it is there.

**Never assume an answer the user hasn't given.** Suggest, then ask.

## 1. Starting point

- **A document from `idea-to-spec` or `feedback-to-issues`** (pasted, a file
  path, or handed over by `feedback-to-issues` in this session): read it,
  including the header lines (customer-specific). If "Open questions" has
  real questions, grill about **those only**. If it says "None", skip the
  grilling. Its "Proposed issues" are the starting point.
- **An idea:** grill about the whole idea with `grilling.md`. If the user
  wants decisions recorded in the repo's docs, use the `grilling` and
  `domain-modeling` skills together.
- **"I know exactly what I want":** no grilling. Usually one issue. Ask only
  what a complete issue still needs (context, "done when" checks, edge cases,
  what is out of scope), in the same round as the fields in step 3.

Files or screenshots to attach: the GitHub API cannot upload attachments.
Embed images that already have a URL (`![description](url)`); otherwise say
the user has to drag the file into the issue on github.com afterwards, and
link the issue in the report.

## 2. Shape the issues

Follow `shaping-issues.md`: one issue, or a parent plus sub-issues, blockers
first, each with a size suggestion, "done when" checks and edge cases.

## 3. Fields, asked once for the whole set

Ask in rounds with `AskUserQuestion` (up to four questions per call), each
with your suggestion. Reuse what a document already answers.

1. **Repo:**

   | Option | Resolves to |
   |---|---|
   | **Skwirrel 2.0** | `Skwirrel-B-V/skwirrel-2.0` (the repo behind the `skwirrel-core` entry in this workspace's `repos.manifest`) |
   | **Skwirrel 1.0** (SVN task) | `Skwirrel-B-V/skwirrel-core`: a GitHub repo synced from the legacy SVN/Trac system. **Confusing but correct:** a *different* repo than the one above, despite the workspace's local `skwirrel-core` directory pointing at `skwirrel-2.0`. Don't conflate the two. |
   | **Other** | Ask for the full `owner/repo` (or GitHub URL) as free text. |

   This is the default for the whole set. When the work spans repos (core,
   an addon, deploy scripts), each issue goes where its change lands: suggest
   the repo per issue now, so step 4 reads the right code; the drafts show it
   again. A parent goes in the repo where most of the work is.

2. **Customer-specific?** If yes: which customer. Derive a PascalCase tag
   ("ppc" → `Ppc`, "acme corp" → `AcmeCorp`) and prefix every title with it:
   `[AcmeCorp] Fix pricing sync timeout`. Titles from a document often carry
   the tag already: keep it, never add it twice. Confirm the tag if the casing is
   ambiguous.
3. **Development board?** yes/no. If yes: **iteration**. Fetch the current and
   upcoming iterations first (they roll forward every two weeks), and offer
   them plus **"No — backlog"**:
   ```bash
   gh api graphql -f query='
   query { organization(login: "Skwirrel-B-V") { projectV2(number: 2) {
     fields(first: 30) { nodes { ... on ProjectV2IterationField {
       configuration { iterations { id title startDate duration } } } } }
   } } }'
   ```
   A number not in the list is not a current or upcoming iteration: say so
   and fall back to backlog unless they pick a listed one.
4. **Assignee:** nobody, or a GitHub username (per issue if it differs).
5. **Skwirrel 1.0 only:** the Trac ticket URL (optional if none exists yet).
6. **Customer-specific only:** the ActiveCollab URL for time tracking
   (optional).

Size (XS / S / M / L / XL, the board's `Size` field) is suggested per issue
in the drafts.

## 4. Read the code for the Agent Brief

For each issue, look in the repo chosen for it in step 3 (under the
workspace's `repositories/`) for what the change touches: the types, functions, config
shapes, screens or commands involved, and how it behaves **now**. Follow the
`AGENT-BRIEF.md` principles: describe interfaces and behaviour, **no file
paths or line numbers**. Anything you cannot find stays "To be determined by
the developer"; never invent it. No code at hand (a different repo, not
cloned): say so in the brief instead of guessing.

## 5. Drafts, then one yes

Show every issue in full: title, repo, size, which one is the parent, and the
whole body (step 6). If an issue's repo has a different owner than the
parent's, say it will be linked as "Related" instead of as a sub-issue. Ask: "Create these N issues?" Change what the user asks for,
then ask again. Nothing goes to GitHub before that yes.

## 6. Issue body

Skip empty sections. The human part comes first, the Agent Brief last.

```markdown
## Context
<problem and desired outcome in plain language, with examples; for a
sub-issue: one line pointing at the parent (`#<n>`, or `<owner>/<repo>#<n>`
when the parent is in another repo), then what this piece changes>

## Trac ticket
<Skwirrel 1.0 only>

## Time tracking
<customer-specific only: the ActiveCollab URL>

## Definition of Done
- [ ] <do X, expect Y>

## Test suggestions
- <from the "done when" checks and the agreed edge cases; never invented>

## Blocked by
- #<n>  (a blocker in another repo: <owner>/<repo>#<n>)

## Agent Brief

**Category:** bug / enhancement
**Summary:** <one line: what needs to happen>

**Current behavior:**
<what happens now, found in the code or reproduced>

**Desired behavior:**
<what should happen, including the edge cases and error conditions>

**Key interfaces:**
- `<TypeOrFunction>`: <what changes and why>  (or: To be determined by the developer)

**Acceptance criteria:**
- [ ] <the same checks as the Definition of Done, each independently testable>

**Out of scope:**
- <what this issue must not change>
```

The parent's body is the whole idea: problem, desired outcome, decisions,
what is not part of it, and the list of its sub-issues (filled in once they
exist). It gets no Agent Brief: its sub-issues are the work.

## 7. Create

1. **A single issue:** create it, then go to "Board" (item 3 below).
   **A parent with sub-issues:** create the parent, then **each sub-issue,
   blockers first**, so "Blocked by" can use real numbers. Two issues that
   block each other: create both, then edit "Blocked by" into their bodies
   (`gh issue edit <n> --repo <owner>/<repo> --body-file ...`, always with
   that issue's own repo).
   Every issue with an Agent Brief (a single issue, every sub-issue; not
   the parent) gets the **`ready-for-agent`** label at creation, as in Matt
   Pocock's `to-tickets`: the brief makes it agent-grabbable by
   construction. Create the label first if the repo doesn't have it.
   ```bash
   gh label list --repo "$REPO" --search ready-for-agent --json name -q '.[].name' | grep -qx ready-for-agent \
     || gh label create ready-for-agent --repo "$REPO" --description "Spec is complete; an agent can pick it up"
   gh issue create --repo "$REPO" --title "$TITLE" --body-file "$BODY_FILE" \
     --label ready-for-agent ${ASSIGNEE:+--assignee "$ASSIGNEE"}   # parent: no --label
   ```
2. **Link sub-issues** to the parent (skip for a single issue). A sub-issue
   in another repo links the same way, using its own repo in the first call.
   GitHub only allows sub-issues under a parent **with the same owner** (for
   us: Skwirrel-B-V). Check this in step 5, before anything is created: an
   issue under another owner gets no sub-issue link, only a line under
   "Related" in the parent body.
   ```bash
   id=$(gh api repos/<sub-owner>/<sub-repo>/issues/<sub> --jq .id)
   gh api repos/<owner>/<repo>/issues/<parent>/sub_issues -X POST -F sub_issue_id=$id
   ```
   Then update the parent body with the list of sub-issue links
   (`gh issue edit <parent> --repo <parent-owner>/<parent-repo> --body-file ...`).
3. **Board** (only if the user said yes): add every issue and set its fields.
   ```bash
   ITEM_ID=$(gh project item-add 2 --owner Skwirrel-B-V --url "$ISSUE_URL" --format json -q .id)
   PROJECT_ID=PVT_kwDODyqF3M4BbQUh

   # Size (single-select field id: PVTSSF_lADODyqF3M4BbQUhzhWCHKY)
   gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
     --field-id PVTSSF_lADODyqF3M4BbQUhzhWCHKY --single-select-option-id "$SIZE_OPTION_ID"

   # Developer acceptance. = Accepted (field id: PVTSSF_lADODyqF3M4BbQUhzhWCY1k,
   # option Accepted=7a2ffadb), for every issue that got ready-for-agent
   gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
     --field-id PVTSSF_lADODyqF3M4BbQUhzhWCY1k --single-select-option-id 7a2ffadb

   # Iteration (field id: PVTIF_lADODyqF3M4BbQUhzhWCHKg), only if one was picked, not backlog
   gh project item-edit --project-id "$PROJECT_ID" --id "$ITEM_ID" \
     --field-id PVTIF_lADODyqF3M4BbQUhzhWCHKg --iteration-id "$ITERATION_ID"
   ```
   `Size` option ids: `XS=b7df5122` `S=b24ac221` `M=677e45dd` `L=4966f9ae`
   `XL=bad90977`. These ids are stable configuration on project #2; if
   `gh project item-edit` reports an unknown field or option id, re-run this
   query rather than guessing, the board may have been reconfigured:
   ```bash
   gh api graphql -f query='
   query { organization(login: "Skwirrel-B-V") { projectV2(number: 2) {
     fields(first: 30) { nodes { ... on ProjectV2SingleSelectField { id name options { id name } } } }
   } } }'
   ```
   (The board also auto-adds sub-issues of a parent that is on it; adding them
   yourself is harmless and gives you the item ID for Size.)

If a step fails halfway, stop and report what already exists, with links.
Never create the same issue twice.

## 8. Report

The links to every issue, and anything left to do by hand (attachments to
drag in, a Trac link once the ticket exists). Mention that the issues are
ready for `/pick-up-issue` or, for bugs, `/fix-bug-issue`.

## Rules

- Don't create anything before the one yes in step 5.
- Don't invent a customer tag, assignee, iteration or technical detail the
  user or the code didn't give.
- Don't add issues to the board unless the user said yes.
- The `ready-for-agent` label and **Accepted** go only on issues with an
  Agent Brief, never on the parent.
- No customer names in issues that aren't customer-specific, and no personal
  data at all (names, email addresses, phone numbers): people by role only.
- A proposed issue of type **bug** gets the `[BUG]` title prefix (after a
  customer tag) and Agent Brief category `bug`, so `fix-bug-issue` picks it
  up.
