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

This analyzes commits and prints the calculated version without creating tags or commits.

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

### How to re-enable split publishing

If you need to publish individual packages (`convertcom/php-sdk-api`, `convertcom/php-sdk-bucketing`, etc.) to their own Packagist entries, see [PACKAGE_SPLIT.md](PACKAGE_SPLIT.md) for the step-by-step reactivation guide.
