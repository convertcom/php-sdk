# Release Process

## Release Chain Overview

```
PR merged to main
  -> CI workflow runs (lint, analyze, test)
  -> Release workflow triggers (after CI passes)
    -> semantic-release analyzes commits since last tag
    -> If feat:/fix:/refactor: found:
      -> Calculate next version (custom rollover logic)
      -> Generate/update CHANGELOG.md
      -> Run monorepo-builder bump-interdependency to sync all package versions
      -> Commit version bumps + CHANGELOG
      -> Create git tag (v1.x.y)
      -> Push tag to monorepo
    -> Packagist webhook on the monorepo detects new tag
      -> Auto-publishes new version of convertcom/php-sdk
```

## Versioning Scheme

This project uses a **digit-capped semver** scheme. Each version position is capped at 9 and rolls over to the next position:

| Scenario | Current | Bump Type | Next |
|----------|---------|-----------|------|
| Normal patch | 1.0.3 | patch | 1.0.4 |
| Patch at cap | 1.0.9 | patch | 1.1.0 |
| Normal minor | 1.2.5 | minor | 1.3.0 |
| Minor at cap | 1.9.3 | minor | 2.0.0 |
| Full cap | 1.9.9 | patch | 2.0.0 |
| Breaking change | 1.2.5 | major | 2.0.0 |

Major bumps happen either directly via `BREAKING CHANGE` commits (standard semver) or via rollover when a digit exceeds 9.

## Commit Convention

Only conventional commits trigger releases:

| Commit Type | Release Type | In CHANGELOG |
|-------------|-------------|-------------|
| `fix:` | patch | Yes (Bug Fixes) |
| `feat:` | patch | Yes (Features) |
| `refactor:` | minor | Yes (Refactoring) |
| `BREAKING CHANGE` (footer) | **major** | Yes |
| `chore:`, `docs:`, `ci:`, `test:`, `style:`, `perf:` | no release | No |

## Automated Flow

When a PR merges to `main`:

1. **CI workflow** runs lint, static analysis, monorepo validation, and tests (6-job matrix)
2. **Release workflow** triggers after CI passes (via `workflow_run`)
3. **semantic-release** analyzes commits since the last tag
4. If releasable commits exist, it:
   - Calculates the next version using the rollover plugin
   - Updates `CHANGELOG.md`
   - Runs `monorepo-builder bump-interdependency` and `monorepo-builder release` to sync all internal package versions (internal version sync is preserved for future split reactivation)
   - Commits changes with `[skip ci]` to prevent infinite loops
   - Creates and pushes a git tag (e.g., `v1.1.0`)
5. **Packagist webhook** on the monorepo detects the new tag and auto-publishes `convertcom/php-sdk`

## Environment Requirements

### Yarn node linker — **do not change**

The repo ships a `.yarnrc.yml` with:

```yaml
nodeLinker: node-modules
```

This is **load-bearing** for the release pipeline. Do not remove it, and do not switch to yarn's PnP linker.

**Why:** `@semantic-release/release-notes-generator` loads the `conventionalcommits` preset via `import-from-esm`, which performs string-based dynamic imports by walking `node_modules/`. Yarn's default PnP linker does not produce a `node_modules/` tree and enforces strict dependency boundaries, so the dynamic import fails with `Cannot find module 'conventional-changelog-conventionalcommits'` — breaking every `yarn release` run. The same failure mode would surface for several other semantic-release plugins that use dynamic preset loading.

The classic `node-modules` linker eliminates this class of problem without changing what yarn installs or locks — only where the files live on disk. This repo uses yarn solely to run semantic-release, so PnP's strict-dependency benefits are not in use; the `node-modules` linker is the correct choice.

If you see `Cannot find module '<some preset>'` errors from semantic-release plugins in CI or locally, check `.yarnrc.yml` is present and contains `nodeLinker: node-modules`, then re-run `yarn install`.

## Packagist Setup

One-time setup for the monorepo:

1. Go to [packagist.org](https://packagist.org) > Submit > enter the monorepo GitHub URL
2. Enable the **GitHub Service Hook** on the monorepo, or manually configure a webhook:
   - URL: `https://packagist.org/api/github?username=PACKAGIST_USERNAME`
   - Add the Packagist API token as a secret in the GitHub repo settings
3. Alternative: use Packagist's **auto-update** feature (polls GitHub periodically)

## Manual Release

To verify what semantic-release would do without actually releasing:

```bash
yarn release --dry-run
```

This analyzes commits and prints the calculated version without creating tags or commits. To run the full pipeline (including `generateNotes`) on a feature branch for pre-merge verification, push the branch to `origin` first, then:

```bash
yarn release --dry-run --branches $(git rev-parse --abbrev-ref HEAD)
```

The `--branches` override lets semantic-release treat the current branch as a release branch for the dry-run only; no tags or commits are created.

## Prerequisites

One-time setup items required before the automated pipeline works:

- [ ] **Monorepo registered on Packagist** pointing at the monorepo GitHub URL, with the GitHub webhook configured (see Packagist Setup above)
- [ ] `yarn install` run once to generate `yarn.lock` (committed to repo)
- [ ] After merging this branch to `main`, tag the merge commit as `v1.0.0` to establish the baseline:
  ```bash
  git checkout main && git pull && git tag v1.0.0 && git push origin v1.0.0
  ```
  **Important:** Tag the merge commit itself -- do NOT create the tag before merging, or semantic-release will see all branch commits as new and produce an unwanted release.

## Troubleshooting

### No release created after merge

- Check that commits use conventional format (`feat:`, `fix:`, `refactor:`)
- `chore:`, `docs:`, `ci:`, `test:` commits do NOT trigger releases
- Run `yarn release --dry-run` locally to debug

### Version rollover unexpected

- Review the rollover truth table in the versioning scheme section
- Check `scripts/rollover-version-plugin.mjs` for the translation logic
- The plugin logs its analysis: `logical=<type>, lastVersion=<ver>, effective=<type>`

### CI re-runs after release commit

- The release commit message includes `[skip ci]` -- this should prevent it
- If CI still runs, verify the CI workflow respects `[skip ci]` in its trigger conditions

### `Cannot find module '<preset>'` from a semantic-release plugin

- Confirm `.yarnrc.yml` contains `nodeLinker: node-modules` (see Environment Requirements)
- Run `yarn install` to regenerate `node_modules/`
- Do not commit `.pnp.*` files or switch the linker to PnP

### How to re-enable split publishing

If you need to publish individual packages (`convertcom/php-sdk-api`, `convertcom/php-sdk-bucketing`, etc.) to their own Packagist entries, follow the [Reactivating 12-Package Split Publishing](#reactivating-12-package-split-publishing) section below.

---

# Reactivating 12-Package Split Publishing

This section describes how to switch from the current single-package publishing model (`convertcom/php-sdk` published from the monorepo root) back to 12 individually published packages, each in its own read-only split repository on GitHub.

## When to Reactivate

The split publishing strategy is appropriate when:

- Consumers need to install individual sub-packages independently (e.g., `convertcom/php-sdk-bucketing` without the full SDK)
- Package-level versioning diverges (one package gets a breaking change while others stay stable)
- Downstream CI pipelines depend on per-package Packagist webhooks for fine-grained dependency tracking

Until one of these scenarios materializes, the single-package model is simpler to maintain and has no consumer-facing downsides.

## Reactivation Prerequisites

- GitHub org admin access to `convertcom` (to create repos and manage secrets)
- Packagist account with publish rights on `convertcom/*` packages
- The monorepo checked out locally with push access to `main`

## Step 1: Create 12 Split Repositories

Create empty repositories (no README, no license, no initial commit) under the `convertcom` GitHub organization:

```bash
for repo in php-sdk-api php-sdk-bucketing php-sdk-data php-sdk-enums \
            php-sdk-event php-sdk-experience php-sdk-logger php-sdk \
            php-sdk-rules php-sdk-segments php-sdk-types php-sdk-utils; do
  gh repo create "convertcom/$repo" --public \
    --description "Convert PHP SDK - ${repo#php-sdk-}" --confirm
done
```

The split action pushes the first commit to each repo. Do not initialize them with any content.

## Step 2: Configure SPLIT_TOKEN PAT

1. Create a **fine-grained Personal Access Token** (Settings > Developer settings > Fine-grained tokens) with:
   - Repository access: select all 12 split repos created above
   - Permissions: Contents (Read and write)
2. Add the token as a repository secret named `SPLIT_TOKEN` in the monorepo's Settings > Secrets and variables > Actions.

The split workflow and release workflow both need this token. `GITHUB_TOKEN` cannot trigger other workflows (GitHub limitation), so a PAT is required when the release tag push must trigger the split workflow.

## Step 3: Configure Packagist Webhooks

Register each of the 12 packages on [Packagist](https://packagist.org):

1. Go to packagist.org > Submit > enter the split repo URL (e.g., `https://github.com/convertcom/php-sdk-api`)
2. Enable the **GitHub Service Hook** on each split repo, or manually configure a webhook:
   - URL: `https://packagist.org/api/github?username=PACKAGIST_USERNAME`
   - Add the Packagist API token as a webhook secret
3. Alternative: use Packagist's **auto-update** feature (polls GitHub periodically)

Also update the monorepo's Packagist entry to point back to the `convertcom/php-sdk` split repo (instead of the monorepo), or deregister the monorepo from Packagist entirely.

## Step 4: Reactivate split.yml

Edit `.github/workflows/split.yml` and change the `on:` block from:

```yaml
on:
  workflow_dispatch:
```

back to:

```yaml
on:
  push:
    branches: [main]
    tags: ['v*']
```

This re-enables automatic split propagation on every push to `main` and on every version tag.

## Step 5: Revert Root composer.json to Monorepo Form

The root `composer.json` needs to be reverted from "published library" mode back to "monorepo aggregator" mode. Reference commit `556084c` for the exact pre-change state.

Changes required:

1. Rename `"name"` from `"convertcom/php-sdk"` to `"convertcom/php-sdk-monorepo"`
2. Change `"type"` from `"library"` to `"project"`
3. Add `"private": true`
4. Restore the 12 path `"repositories"` entries:
   ```json
   "repositories": [
       { "type": "path", "url": "packages/Api" },
       { "type": "path", "url": "packages/Bucketing" },
       { "type": "path", "url": "packages/Data" },
       { "type": "path", "url": "packages/Enums" },
       { "type": "path", "url": "packages/Event" },
       { "type": "path", "url": "packages/Experience" },
       { "type": "path", "url": "packages/Logger" },
       { "type": "path", "url": "packages/Rules" },
       { "type": "path", "url": "packages/Segments" },
       { "type": "path", "url": "packages/Types" },
       { "type": "path", "url": "packages/Utils" },
       { "type": "path", "url": "packages/Php-sdk" }
   ]
   ```
5. Replace the aggregated `"autoload"` and external `"require"` with internal package requires:
   ```json
   "require": {
       "php": "^8.2",
       "convertcom/php-sdk-api": ">=1.0.0",
       "convertcom/php-sdk-data": ">=1.0.0",
       "convertcom/php-sdk-enums": ">=1.0.0",
       "convertcom/php-sdk-event": ">=1.0.0",
       "convertcom/php-sdk-logger": ">=1.0.0",
       "convertcom/php-sdk-utils": ">=1.0.0",
       "convertcom/php-sdk": ">=1.0.0"
   }
   ```
6. Remove the root-level `"autoload"` block (PSR-4 autoloading is handled by each package's own `composer.json` via path repositories)

Run `rm -rf vendor composer.lock && composer install && composer test` to verify everything resolves correctly.

## Step 6: Revert release.yml

Edit `.github/workflows/release.yml`:

1. In the checkout step, change `token: ${{ secrets.GITHUB_TOKEN }}` to `token: ${{ secrets.SPLIT_TOKEN }}`
2. In the semantic-release env, change `GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}` to `GITHUB_TOKEN: ${{ secrets.SPLIT_TOKEN }}`

This ensures the tag push from semantic-release triggers the split workflow (PAT-based pushes trigger other workflows; `GITHUB_TOKEN` pushes do not).

## Step 7: Verify

1. **Dry-run release:**
   ```bash
   yarn release --dry-run
   ```
   Confirm semantic-release calculates the next version without errors.

2. **Manual-dispatch split:**
   Go to Actions > Split Monorepo > Run workflow (on `main`). All 12 matrix jobs should succeed now that the split repos exist.

3. **End-to-end test:**
   Push a `feat:` commit to `main`. Verify:
   - CI passes
   - Release workflow creates a tag
   - Split workflow triggers on the tag and propagates to all 12 repos
   - Packagist shows the new version for each package
