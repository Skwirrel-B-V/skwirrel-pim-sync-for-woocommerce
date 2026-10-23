# Matt Pocock skills in this workspace

Some skills in this folder are copied from
[github.com/mattpocock/skills](https://github.com/mattpocock/skills) (MIT, see
the `LICENSE` in each folder). They are real files, not symlinks or a plugin,
so everyone on the team runs the same version.

- **Version:** the tag in `scripts/mattpocock-patches/VERSION`.
- **Which skills:** the `SKILLS` list at the top of
  `scripts/update-mattpocock-skills.sh` (today: `triage`, `tdd`,
  `code-review`, `diagnosing-bugs`, `domain-modeling`, `codebase-design`,
  `pr`, `grilling`).
- **Used by:** `fix-bug-issue`, `pick-up-issue`, and `review-pr` (wraps
  `code-review`).

Don't edit these folders by hand: `make mattpocock-skills` overwrites them.
Change a patch instead.

## Our edits

Kept as patch files in `scripts/mattpocock-patches/`, applied on every copy.

| Patch | What | Why |
|---|---|---|
| `01-triage-github-board.patch` | `triage` reads and writes GitHub issues through `gh`; triage states map to the Development board fields instead of labels; no `.out-of-scope/` folder (`OUT-OF-SCOPE.md` removed); closing always asks first; the `disable-model-invocation` flag (Claude) and `allow_implicit_invocation: false` (Codex, `agents/openai.yaml`) are removed. | We track work on the project board, not with labels, and `fix-bug-issue` must be able to call `triage` itself, in Claude and in Codex. |
| `02-code-review-github.patch` | `code-review` fetches the issue with `gh` instead of `docs/agents/issue-tracker.md`. | We don't run `/setup-matt-pocock-skills`. |
| `03-code-review-pr-handoff.patch` | `code-review` hands off to the workspace `review-pr` skill when the changes belong to an open PR (unless `review-pr` called it). | So `/code-review` on a PR posts on the PR like Codex (👀, threads with badges, 👌) instead of only reporting in the chat. |

## Updating

The `04-pr-review-handoff.patch` patch adds the Skwirrel Developer Handoff v1.0
block (from the QA team's `Playwright_Skwirrel_2` repo) after the upstream `pr`
body. Upstream Summary / Evidence / Merge Danger stays as it is. The handoff
adds change summary, impact and risk, developer self review, developer
verification, known issues, environment, and optional QA steps. The AI fills
everything the evidence supports and never ticks the self-review boxes. Keep the
handoff headings as they are: the QA Analyzer parses them.

```bash
make mattpocock-skills                  # recopy the pinned version
make mattpocock-skills VERSION=v1.4.0   # move to a newer tag
```

The script copies to a staging folder first. If a patch no longer applies,
it stops and changes nothing. Redo that patch on the new version, using git's
index to hold the clean upstream copy:

```bash
scripts/update-mattpocock-skills.sh --no-patches v1.4.0   # clean upstream, no edits
git add -A .claude/skills                                  # stage the clean copy
# redo the edit in .claude/skills/<skill>/ (see the table above)
git diff -- .claude/skills/<skill> > scripts/mattpocock-patches/<nn>-<name>.patch
git reset -q .claude/skills                                # unstage again
make mattpocock-skills VERSION=v1.4.0                      # must apply cleanly now
```

**Adding or removing a skill:** edit `SKILLS` in the script. When you remove
one, also delete its folder in `.claude/skills/`; the script does not.

## Name clash

A skill in `~/.claude/skills/` wins over one here with the same name. If you
have personal copies of any of the skills above (or `grill-me`), remove them,
or you run a different version than the team.

`/code-review` now runs this skill instead of Claude Code's built-in review.
The built-in one (including the paid `ultra` cloud review) is still reachable
as `/claude:code-review`.
