# Releasing Skwirrel PIM sync for WooCommerce

Releases are automated. Pushing a git tag in the format `X.Y.Z` triggers the
GitHub Actions deploy workflow, which publishes the plugin to the
WordPress.org SVN repository.

## Step-by-step

1. **Bump the version in three places (must all match exactly):**
   - `skwirrel-pim-sync/skwirrel-pim-sync.php` — `Version:` in the file header
   - `skwirrel-pim-sync/skwirrel-pim-sync.php` — `SKWIRREL_WC_SYNC_VERSION` constant
   - `skwirrel-pim-sync/readme.txt` — `Stable tag:`

2. **Add a changelog entry in two places:**
   - `CHANGELOG.md` — internal/dev-facing changelog
   - `skwirrel-pim-sync/readme.txt` — user-facing changelog under `== Changelog ==`
     (WordPress format: a line `= X.Y.Z =` followed by bullet points)

3. **Run the quality checks locally** (the CI workflow will re-run them, but
   it's faster to catch failures here):
   ```bash
   cd skwirrel-pim-sync
   vendor/bin/pest
   vendor/bin/phpstan analyse
   vendor/bin/phpcs
   ```

4. **Commit, tag, and push:**
   ```bash
   git add -A
   git commit -m "Release X.Y.Z"
   git tag X.Y.Z
   git push origin main
   git push origin X.Y.Z
   ```

5. **GitHub Actions deploys.** The workflow:
   - Verifies the git tag matches the plugin header, constant, readme `Stable tag`,
     and that a changelog entry for `X.Y.Z` exists in `readme.txt`
   - Uploads `skwirrel-pim-sync/` to WP.org SVN `/trunk/` and `/tags/X.Y.Z/`
   - Uploads `assets/` to WP.org SVN `/assets/` (banners, icons, screenshots)
   - Generates a ZIP of the released plugin and attaches it as a workflow artifact
     (downloadable from the workflow run page)

## If the deploy fails

Two things happen on failure:

1. GitHub emails the person who pushed the tag.
2. A GitHub Issue is auto-created in this repo titled
   `Release X.Y.Z failed to deploy to WordPress.org`, labelled `release-failure`,
   with the workflow run URL and a list of common causes.

Watch the Issues tab and your GitHub email after pushing a tag.

## Manual redeploy (workflow_dispatch)

If you need to redeploy a version (for example, the SVN deploy was interrupted
mid-flight), you can re-run the deploy workflow manually:

1. Open `Actions` → `Deploy to WordPress.org` in GitHub
2. Click `Run workflow`
3. Enter the version (e.g., `3.2.3`)
4. The version must already match the plugin header, constant, and readme

The version-consistency check still runs, so the inputs cannot be inconsistent.

## Required GitHub secrets

Set these once under `Settings → Secrets and variables → Actions`:

| Secret | Value |
|---|---|
| `SVN_USERNAME` | Your wordpress.org username |
| `SVN_PASSWORD` | Your wordpress.org account password |

If your wp.org account has 2FA enabled, you'll need an app password instead of
your account password.

## SVN structure (for context)

WordPress.org stores plugins in Subversion at
`https://plugins.svn.wordpress.org/skwirrel-pim-sync/`:

```
/assets/         ← banners, icons, screenshots — populated from plugin/assets/
/trunk/          ← current dev version — populated from plugin/skwirrel-pim-sync/
/tags/X.Y.Z/     ← snapshot of trunk at release time
```

The `Stable tag:` line in `trunk/readme.txt` tells WP.org which `/tags/X.Y.Z/`
folder to actually serve to users. **Always bump it together with the plugin
version** — if you forget, the new code lands in trunk but users keep getting
the old tag.

## Local layout → SVN mapping

```
plugin/                          ← git repo root
├── .github/workflows/           ← CI + Deploy workflows
├── assets/                      ← uploaded to SVN /assets/  (BANNERS/ICONS)
└── skwirrel-pim-sync/           ← uploaded to SVN /trunk/ + /tags/X.Y.Z/  (PLUGIN)
    ├── .distignore              ← controls what gets excluded from /trunk/
    ├── readme.txt               ← Stable tag lives here
    └── skwirrel-pim-sync.php    ← Version header + constant live here
```

The 10up deploy action uses `BUILD_DIR: ./skwirrel-pim-sync` to know which
folder is the plugin, and `ASSETS_DIR: ./assets` for the store assets.
