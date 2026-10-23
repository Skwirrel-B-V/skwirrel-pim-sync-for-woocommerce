---
name: feedback-to-issues
description: Turn customer feedback, even messy notes, into clear proposed issues. Gathers context, sorts the findings with you, asks questions per finding, then writes a document.
---

For Codex, first read `.claude/skills/CODEX.md` in the workspace root.


# Feedback to issues

For customer feedback: call notes, an email, a visit report, a support
thread, often messy. Turns it into the same document `idea-to-spec` writes,
so a developer or PO can create the issues from it.

- **Claude chat** (anyone): ends with the document to share. Never creates
  issues, even if a GitHub connector is available.
- **Claude Code**: after the user approves the document, carries straight on
  with the workspace's `create-github-issue` skill, starting from that
  document.

Write in plain language; the reader may be from sales, support, product or
development.

## Shared files

The question method, issue shaping and document format are shared with
`idea-to-spec`:

- In Claude chat: `references/shared/` inside this skill
  (`grilling.md`, `shaping-issues.md`, `document-template.md`).
- In Claude Code: the same files in the workspace's
  `.claude/skills/idea-to-spec/references/`.

## Steps

1. **Read the feedback** the user pasted or uploaded, all of it, before
   asking anything.
2. **Context round**: one round of at most four questions, each with your
   suggestion based on what the feedback already says:
   1. **Source:** which customer, from whom (by role, not name), when, and
      via which channel (call, email, visit, support).
   2. **Product area:** which part of Skwirrel it is about.
   3. **Customer situation:** which version or environment, and what they
      use it for.
   4. **Urgency:** is something blocked, or is it a wish?
   In Claude Code, also look in the code for what the feedback is about.
   **Never** connect to the customer's environment or tenant (live data)
   without the user's explicit go-ahead for that specific tenant.
3. **Sort the findings** with [references/sorting.md](references/sorting.md):
   a numbered list the user checks, corrects and chooses from.
4. **Grill per finding** that goes forward, with `grilling.md`, one finding
   at a time, in rounds. Don't re-ask what the feedback or the context round
   already answered.
5. **Shape the issues** with `shaping-issues.md`. Mark each proposed issue as
   a bug or an enhancement; bugs later go to `fix-bug-issue`.
6. **Write the document** with `document-template.md`, including the
   feedback-only parts (source line, quotes per problem, "Not taken
   forward"). In Claude chat as a Markdown document (an artifact) the user
   can copy or download.
7. **Finish:**
   - Claude chat: "Share this document with a developer or the PO. They turn
     it into issues with `/create-github-issue` in Claude Code."
   - Claude Code: ask "Is this document right?" On a yes, continue with
     `create-github-issue`, starting from this document. If no finding went
     forward (only praise or answered questions), stop after the document:
     there is nothing to create.

## Privacy

Feedback notes often hold names, email addresses and phone numbers. The
document and the issues contain **no personal data**: people by role only
("their purchasing manager"). The customer's name appears only when the work
is customer-specific. Quotes are shortened to the part that matters and
cleaned of personal details.

Adapted from Matt Pocock's `grilling`, `to-spec` and `to-tickets` skills
(MIT); see [CREDITS.md](CREDITS.md).
