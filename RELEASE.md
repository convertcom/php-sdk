# Release Process

## Release Chain Overview

```
PR merged to main
  → CI workflow runs (lint, analyze, test)
  → Release workflow triggers (after CI passes)
    → semantic-release analyzes commits since last tag
    → If feat:/fix:/refactor: found:
      → Calculate next version (custom rollover logic)
      → Generate/update CHANGELOG.md
      → Run monorepo-builder bump-interdependency to sync all package versions
      → Commit version bumps + CHANGELOG
      → Create git tag (v1.x.y)
      → Push tag to monorepo
    → Split workflow triggers on tag push
      → Propagates tag to all 12 split repos
    → Packagist webhook on each split repo detects new tag
      → Auto-publishes new version
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

**There is no direct major bump.** Major versions only happen via rollover when minor exceeds 9.

## Commit Convention

Only conventional commits trigger releases:

| Commit Type | Release Type | In CHANGELOG |
|-------------|-------------|-------------|
| `fix:` | patch | Yes (Bug Fixes) |
| `feat:` | patch | Yes (Features) |
| `refactor:` | minor | Yes (Refactoring) |
| `BREAKING CHANGE` (footer) | minor | Yes |
| `chore:`, `docs:`, `ci:`, `test:`, `style:`, `perf:` | no release | No |

## Automated Flow

When a PR merges to `main`:

1. **CI workflow** runs lint, static analysis, monorepo validation, and tests (6-job matrix)
2. **Release workflow** triggers after CI passes (via `workflow_run`)
3. **semantic-release** analyzes commits since the last tag
4. If releasable commits exist, it:
   - Calculates the next version using the rollover plugin
   - Updates `CHANGELOG.md`
   - Runs `monorepo-builder bump-interdependency` and `monorepo-builder release` to sync all 12 packages
   - Commits changes with `[skip ci]` to prevent infinite loops
   - Creates and pushes a git tag (e.g., `v1.1.0`)
5. **Split workflow** triggers on the tag push, propagating it to all 12 split repos
6. **Packagist webhooks** detect the new tags and auto-publish

## Split Repository List

All packages are published under the `convertcom` organization:

| Package | Split Repo | Packagist |
|---------|-----------|-----------|
| Api | `convertcom/php-sdk-api` | `convertcom/php-sdk-api` |
| Bucketing | `convertcom/php-sdk-bucketing` | `convertcom/php-sdk-bucketing` |
| Data | `convertcom/php-sdk-data` | `convertcom/php-sdk-data` |
| Enums | `convertcom/php-sdk-enums` | `convertcom/php-sdk-enums` |
| Event | `convertcom/php-sdk-event` | `convertcom/php-sdk-event` |
| Experience | `convertcom/php-sdk-experience` | `convertcom/php-sdk-experience` |
| Logger | `convertcom/php-sdk-logger` | `convertcom/php-sdk-logger` |
| Php-sdk | `convertcom/php-sdk` | `convertcom/php-sdk` |
| Rules | `convertcom/php-sdk-rules` | `convertcom/php-sdk-rules` |
| Segments | `convertcom/php-sdk-segments` | `convertcom/php-sdk-segments` |
| Types | `convertcom/php-sdk-types` | `convertcom/php-sdk-types` |
| Utils | `convertcom/php-sdk-utils` | `convertcom/php-sdk-utils` |

## Packagist Webhook Setup

One-time setup per split repository:

1. Go to [packagist.org](https://packagist.org) → your package page → **Settings**
2. Enable **GitHub Service Hook**, or manually configure the webhook:
   - URL: `https://packagist.org/api/github?username=PACKAGIST_USERNAME`
   - Add the Packagist API token as a secret in the GitHub repo settings
3. Alternative: use Packagist's **auto-update** feature (polls GitHub — less real-time but zero config)

## Manual Release

To verify what semantic-release would do without actually releasing:

```bash
yarn release --dry-run
```

This analyzes commits and prints the calculated version without creating tags or commits.

## Prerequisites

One-time setup items required before the automated pipeline works:

- [ ] **12 empty split repos** created on GitHub under `convertcom` org (no README, no license, no initial commit — the split action pushes the first commit):
  ```
  php-sdk-api, php-sdk-bucketing, php-sdk-data, php-sdk-enums,
  php-sdk-event, php-sdk-experience, php-sdk-logger, php-sdk,
  php-sdk-rules, php-sdk-segments, php-sdk-types, php-sdk-utils
  ```
  Batch create via CLI:
  ```bash
  for repo in php-sdk-api php-sdk-bucketing php-sdk-data php-sdk-enums php-sdk-event php-sdk-experience php-sdk-logger php-sdk php-sdk-rules php-sdk-segments php-sdk-types php-sdk-utils; do
    gh repo create "convertcom/$repo" --public --description "Convert PHP SDK - ${repo#php-sdk-}" --confirm
  done
  ```
- [ ] `SPLIT_TOKEN` PAT secret configured with write access to all 12 split repos
- [ ] Packagist webhooks configured on each split repo
- [ ] Initial `v1.0.0` tag on `main` (semantic-release needs a starting point):
  ```bash
  git checkout main && git pull && git tag v1.0.0 && git push origin v1.0.0
  ```
- [ ] `yarn install` run once to generate `yarn.lock` (committed to repo)

## Troubleshooting

### No release created after merge

- Check that commits use conventional format (`feat:`, `fix:`, `refactor:`)
- `chore:`, `docs:`, `ci:`, `test:` commits do NOT trigger releases
- Run `yarn release --dry-run` locally to debug

### Version rollover unexpected

- Review the rollover truth table in the versioning scheme section
- Check `scripts/rollover-version-plugin.mjs` for the translation logic
- The plugin logs its analysis: `logical=<type>, lastVersion=<ver>, effective=<type>`

### Split workflow doesn't trigger after release

- Tags pushed with `GITHUB_TOKEN` may not trigger other workflows (GitHub limitation)
- Fix: change the release workflow checkout to use `token: ${{ secrets.SPLIT_TOKEN }}` instead
- PAT-based pushes DO trigger other workflows

### CI re-runs after release commit

- The release commit message includes `[skip ci]` — this should prevent it
- If CI still runs, verify the CI workflow respects `[skip ci]` in its trigger conditions
