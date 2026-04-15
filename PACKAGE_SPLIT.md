# Reactivating 12-Package Split Publishing

This document describes how to switch from the current single-package publishing model (`convertcom/php-sdk` published from the monorepo root) back to 12 individually published packages, each in its own read-only split repository on GitHub.

## When to Reactivate

The split publishing strategy is appropriate when:

- Consumers need to install individual sub-packages independently (e.g., `convertcom/php-sdk-bucketing` without the full SDK)
- Package-level versioning diverges (one package gets a breaking change while others stay stable)
- Downstream CI pipelines depend on per-package Packagist webhooks for fine-grained dependency tracking

Until one of these scenarios materializes, the single-package model is simpler to maintain and has no consumer-facing downsides.

## Prerequisites

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

## Reference

- Normal release flow: see [RELEASE.md](RELEASE.md)
- Split workflow config: `.github/workflows/split.yml`
- Release workflow config: `.github/workflows/release.yml`
- Monorepo builder config: `monorepo-builder.php`
