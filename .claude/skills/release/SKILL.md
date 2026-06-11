---
name: release
version: "1.0.0"
description: Prepare a Skwirrel PIM sync release — bump the version in every file (plugin header, constant, readme.txt Stable tag, package.json, package-lock.json), verify the changelog entries exist, regenerate translations when strings changed, run the quality gates, and verify deploy-consistency before tag/push. Use when the user says "prep a release", "bump to X.Y.Z", "release X.Y.Z", or "cut a release".
---

# Release — Skwirrel PIM sync for WooCommerce

Automates the release checklist in [`docs/release.md`](../../docs/release.md). A WordPress.org deploy is triggered by **pushing a tag `X.Y.Z`** (no `v` prefix); the deploy's `verify-version` job hard-fails unless the plugin header, constant, `Stable tag:`, and a `= X.Y.Z =` readme changelog entry all match the tag. This skill gets all of that right before you tag.

## Args
- A target version `X.Y.Z` (e.g. `3.10.3`). If omitted, infer the current version from the plugin header and **ask** whether this is a patch/minor/major bump, or take an explicit version. Never guess silently.

## Procedure

### 1. Resolve current + target version
- Read current from `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` `Version:` header.
- Confirm the target `X.Y.Z` with the user if not passed as an arg.

### 2. Bump the version in EVERY file (all must match exactly)
Update each occurrence to the target version:

| File | What |
|------|------|
| `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` | `Version:` header **and** `define( 'SKWIRREL_WC_SYNC_VERSION', '…' )` |
| `plugin/skwirrel-pim-sync/readme.txt` | `Stable tag:` |
| `package.json` | `"version"` |
| `package-lock.json` | `"version"` — **two** self-version occurrences near the top (top-level + `packages[""]`). Leave dependency versions untouched. |

> `README.md` (repo root) carries **no** hardcoded version — do not add one.
> Historical version strings in code comments, tests, `CHANGELOG.md` history, `MIGRATION_VERSION`, and `_bmad-output/` docs are intentional — never rewrite them.

### 3. Changelog (both files — the readme one is mandatory for deploy)
- `CHANGELOG.md`: a `## [X.Y.Z]` section (dev-facing, detailed — symptom / root cause / fix style).
- `plugin/skwirrel-pim-sync/readme.txt`: a `= X.Y.Z =` block under `== Changelog ==` (terser, user-facing bullets). **The deploy fails if this is missing.**
- If a `## [X.Y.Z]` / `= X.Y.Z =` already exists (e.g. work was staged under this version), expand it rather than duplicating.

### 4. Translations — only when translatable strings changed
Check whether this release added/changed any `__()`/`esc_html__()` etc. strings (text domain `skwirrel-pim-sync`). If yes:
- **Regenerate the POT**: `wp i18n make-pot plugin/skwirrel-pim-sync plugin/skwirrel-pim-sync/languages/skwirrel-pim-sync.pot --domain=skwirrel-pim-sync` (requires wp-cli). If wp-cli is unavailable, tell the user and fall back to updating strings manually / via the `add-translation` skill.
- **Update each locale** `.po` (nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB) with the new/changed msgids (`msgmerge --update <locale>.po skwirrel-pim-sync.pot` if available, else hand-edit).
- **Compile every `.mo`**: `for f in plugin/skwirrel-pim-sync/languages/*.po; do msgfmt "$f" -o "${f%.po}.mo"; done` (`msgfmt` is available locally).
- If no strings changed, **skip** this step and say so.

### 5. Quality gates (from repo root — all must pass)
```bash
vendor/bin/phpcbf            # autofix style first
vendor/bin/phpcs
vendor/bin/phpstan analyse   # if it OOMs locally: php -d memory_limit=2G vendor/bin/phpstan analyse
vendor/bin/pest
```
Do not weaken the phpstan baseline or tests to pass — fix findings.

### 6. Verify deploy-consistency (mirror the `verify-version` job)
Confirm the target version appears identically in all four deploy-checked spots, then report a tidy table:
```bash
grep -h "Version:\|SKWIRREL_WC_SYNC_VERSION\|Stable tag:\|= X.Y.Z =" \
  plugin/skwirrel-pim-sync/skwirrel-pim-sync.php plugin/skwirrel-pim-sync/readme.txt
```
All four must read the target version. If any mismatch, fix before proceeding.

### 7. Commit & tag — ONLY when the user confirms
Per project rule, commit/push only when the user asks. When they do:
- If on the default branch, branch first (or merge the release branch to `main` first).
- `git commit -am "Release X.Y.Z: <summary>"` (end the message with the Co-Authored-By line).
- `git tag X.Y.Z` (no `v` prefix) on the bump commit.
- `git push origin main && git push origin X.Y.Z` — **the tag push triggers the WordPress.org deploy.**
- After pushing, watch GitHub Issues/email: a failed deploy auto-opens a `release-failure` issue.

## Guardrails
- The deploy environment's allowed-tag pattern uses **fnmatch, not regex** — `[0-9]*.[0-9]*.[0-9]*` is valid; `[0-9]+...` silently blocks every deploy.
- Never tag/push without explicit user confirmation.
- Every change bumps the version — there is no "no-op" release.
