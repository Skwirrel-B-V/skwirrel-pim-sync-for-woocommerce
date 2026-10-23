# Workspace skills in Codex

The source of truth is `.claude/skills/`. Selected skills are exposed through
relative directory symlinks in `.agents/skills/`; edit the source. Resolve
supporting scripts and references against the source skill directory, even
when working inside a repo or worktree. Load dependent skills directly from
`.claude/skills/<name>/SKILL.md` when absent from Codex's skill catalog.

- Treat “Claude Code” as the local coding-agent mode, including Codex.
  “Claude chat” remains the document-only chat mode. In Codex,
  feedback-to-issues uses the coding-agent continuation; idea-to-spec still
  ends with a document and never creates issues.
- Replace `AskUserQuestion` with an available Codex user-input tool, or a chat
  question when no suitable tool is available. Split batches to fit tool
  limits. Use the environment's approval mechanism for execution permissions;
  clarification tools do not grant approval.
- Replace `Skill` tool calls and skill slash commands with reading the named
  skill's `SKILL.md` and following its instructions.
- Use session tools that actually exist. For a required unavailable design,
  connector, or service, report the missing capability if there is no supported
  alternative. Do not invent tools or claim access.

Discovery does not supply credentials, authorize external changes, or remove
workflow approval requirements. GitHub workflows require authenticated access;
babysitting also requires the Codex GitHub app on the repository.
