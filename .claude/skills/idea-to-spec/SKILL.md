---
name: idea-to-spec
description: Turn an idea into a clear, shareable document. Asks questions until the idea is clear, then writes a spec with proposed issues for a developer or PO.
---

For Codex, first read `.claude/skills/CODEX.md` in the workspace root.


# Idea to spec

Helps anyone at Skwirrel turn a rough idea into a document a developer or
PO can act on. Made for Claude chat, where everyone can use it; it works the
same in Claude Code.

This skill **never creates issues**. The document is the hand-off: a
developer or PO turns it into GitHub issues with `/create-github-issue` in
Claude Code.

Write everything in plain language. The reader may be from sales, support,
product or development. No jargon unless the user uses it first.

## Steps

1. **Grill.** Follow [references/grilling.md](references/grilling.md) until
   every question about the idea is answered or written down as open.
2. **Shape the issues.** Follow
   [references/shaping-issues.md](references/shaping-issues.md) to split the
   idea into proposed issues.
3. **Write the document** with
   [references/document-template.md](references/document-template.md). In
   Claude chat as a Markdown document (an artifact) the user can copy or
   download; in Claude Code as a `.md` file where the user asks for it.
4. **Hand it over.** End with: "Share this document with a developer or the
   PO. They turn it into issues with `/create-github-issue` in Claude Code."

## Rules

- One question round at a time; never guess an answer the user hasn't given.
- Don't invent customers, sizes or technical details; suggest, then ask.
- Even if a GitHub connector is available: don't create issues.
- Keep the document readable for someone who wasn't in the conversation.

Grilling and issue shaping are adapted from Matt Pocock's `grilling`,
`to-spec` and `to-tickets` skills (MIT); see [CREDITS.md](CREDITS.md).
