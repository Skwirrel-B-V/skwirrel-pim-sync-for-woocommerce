# Release process

## TL;DR — what triggers what

| You do… | What runs | Publishes to WP.org? |
|---|---|---|
| Push to `main` (or open a PR) | `CI` workflow — Pest, PHPStan, PHPCS | **No** |
| Push to a release branch | Nothing special (CI only if it's a PR or `main`) | **No** |
| Push a tag matching `X.Y.Z` (e.g. `3.3.1`) | `Deploy to WordPress.org` workflow | **Yes** |
| Manually run `Deploy to WordPress.org` via Actions UI | Same deploy workflow | **Yes** |

There is no "release branch" concept wired up. There is no auto-deploy on `main`. The only way code reaches WordPress.org is by pushing a tag (or by running the deploy workflow manually against a version that already exists in the files on `main`).

## The two workflows

Both live in `.github/workflows/`.

### `ci.yml` — `CI`
- **Triggers:** `push` to `main`, any `pull_request`
- **What it does:** installs composer deps at the repo root, then runs `vendor/bin/pest --testsuite=Unit`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs`
- **Does not touch SVN.** This is just quality-gate CI.

### `deploy.yml` — `Deploy to WordPress.org`
- **Triggers:**
  - `push` of a tag matching `[0-9]+.[0-9]+.[0-9]+` (three numeric segments, no `v` prefix)
  - `workflow_dispatch` — manual run, requires you to type the version (must match what's already on `main`)
- **Environment:** `wordpress-org` (GitHub environment — can be used to gate with manual approval if desired)

## What happens when you push a tag

1. **`verify-version` job** runs first. It resolves the version from the tag name (or the manual input), then checks four things must match it exactly:
   - `Version:` header in `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php`
   - `SKWIRREL_WC_SYNC_VERSION` constant in the same file
   - `Stable tag:` in `plugin/skwirrel-pim-sync/readme.txt`
   - A changelog entry `= X.Y.Z =` exists in `plugin/skwirrel-pim-sync/readme.txt`

   If any of these don't match, the job fails and nothing is published.

2. **`deploy` job** runs `10up/action-wordpress-plugin-deploy@stable` with:
   - `SLUG=skwirrel-pim-sync`
   - `BUILD_DIR=./plugin/skwirrel-pim-sync` → uploaded to SVN `/trunk/` and `/tags/X.Y.Z/`
   - `ASSETS_DIR=./plugin/assets` → uploaded to SVN `/assets/` (banners, icons, screenshots)
   - `VERSION=X.Y.Z` from the verified version
   - `generate-zip: true` → produces a plugin ZIP that's attached to the workflow run as an artifact

   SVN credentials come from repository secrets `SVN_USERNAME` and `SVN_PASSWORD` (set under `Settings → Secrets and variables → Actions`). If WP.org account has 2FA, use an app password.

3. **`notify-failure` job** runs only if either of the above failed. It auto-opens a GitHub issue titled `Release X.Y.Z failed to deploy to WordPress.org`, labelled `release-failure`, with a link to the failing run and the common causes. Watch Issues + your GitHub email after pushing a tag.

## End-to-end release steps

1. On `main`, bump the version in all three places (must match exactly):
   - `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` → `Version:` header
   - `plugin/skwirrel-pim-sync/skwirrel-pim-sync.php` → `SKWIRREL_WC_SYNC_VERSION` constant
   - `plugin/skwirrel-pim-sync/readme.txt` → `Stable tag:`

2. Add changelog entries:
   - `CHANGELOG.md` (dev-facing)
   - `plugin/skwirrel-pim-sync/readme.txt` under `== Changelog ==` — a line `= X.Y.Z =` followed by bullets. The deploy workflow fails if this entry is missing.

3. Update translations if any translatable strings changed (`.pot`, `.po`, `.mo` under `plugin/skwirrel-pim-sync/languages/`).

4. Run the quality checks locally from the repo root (CI re-runs them, but faster to catch here):
   ```bash
   vendor/bin/pest
   vendor/bin/phpstan analyse
   vendor/bin/phpcs
   ```

5. Commit, push `main`, then tag and push the tag:
   ```bash
   git commit -am "Release X.Y.Z"
   git push origin main
   git tag X.Y.Z
   git push origin X.Y.Z
   ```

   The `main` push triggers `CI`. The tag push triggers `Deploy to WordPress.org`.

## Redeploying a version

If an SVN deploy was interrupted or needs to be re-run without touching `main`:

1. Open `Actions` → `Deploy to WordPress.org` in GitHub
2. Click `Run workflow`
3. Enter the version (e.g. `3.3.0`)
4. The version-consistency check still runs, so the version you type must already match the plugin header, constant, and readme on the branch the workflow runs against.

## Required GitHub secrets

Set once under `Settings → Secrets and variables → Actions`:

| Secret | Value |
|---|---|
| `SVN_USERNAME` | Your wordpress.org username |
| `SVN_PASSWORD` | Your wordpress.org account password (use an app password if 2FA is enabled) |

## SVN layout (for context)

WordPress.org stores the plugin at `https://plugins.svn.wordpress.org/skwirrel-pim-sync/`:

```
/assets/      ← banners, icons, screenshots — from plugin/assets/
/trunk/       ← current dev version — from plugin/skwirrel-pim-sync/
/tags/X.Y.Z/  ← snapshot of trunk at release time
```

The `Stable tag:` line in `trunk/readme.txt` tells WP.org which `/tags/X.Y.Z/` folder to actually serve users. Bumping it together with the plugin version is what actually ships the new code — forget it and the tag lands in SVN but users keep getting the old version.

`plugin/skwirrel-pim-sync/.distignore` controls which files are excluded from the `/trunk/` upload.
