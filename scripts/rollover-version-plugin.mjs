import { sync as parseSync } from 'conventional-commits-parser';

/**
 * Custom semantic-release analyzeCommits plugin.
 *
 * Determines the logical release type from conventional commits, then
 * translates it into the semver bump that respects the digit-capped
 * rollover scheme (each position capped at 9).
 *
 * Commit mapping:
 *   fix: / feat:           → logical patch
 *   refactor: / BREAKING CHANGE → logical minor
 *   everything else        → no release
 *
 * Rollover rules:
 *   logical patch: patch<9 → patch | minor<9 → minor | else → major
 *   logical minor: minor<9 → minor | else → major
 */

function getLogicalType(commits) {
  let hasMinor = false;
  let hasPatch = false;

  for (const commit of commits) {
    const parsed = parseSync(commit.message);
    if (!parsed) continue;

    const type = parsed.type;
    const hasBreaking =
      parsed.notes?.some((note) => note.title === 'BREAKING CHANGE') ?? false;

    if (type === 'refactor' || hasBreaking) {
      hasMinor = true;
    } else if (type === 'feat' || type === 'fix') {
      hasPatch = true;
    }
  }

  if (hasMinor) return 'minor';
  if (hasPatch) return 'patch';
  return null;
}

function getEffectiveReleaseType(logicalType, lastVersion) {
  const parts = lastVersion.replace(/^v/, '').split('.');
  const minor = parseInt(parts[1], 10) || 0;
  const patch = parseInt(parts[2], 10) || 0;

  if (logicalType === 'patch') {
    if (patch < 9) return 'patch';
    if (minor < 9) return 'minor';
    return 'major';
  }

  if (logicalType === 'minor') {
    if (minor < 9) return 'minor';
    return 'major';
  }

  return null;
}

export async function analyzeCommits(pluginConfig, context) {
  const { commits, lastRelease, logger } = context;

  const logicalType = getLogicalType(commits);
  if (!logicalType) {
    logger.log('No releasable commits found.');
    return null;
  }

  const lastVersion = lastRelease?.version || '1.0.0';
  const effectiveType = getEffectiveReleaseType(logicalType, lastVersion);

  logger.log(
    'Rollover analysis: logical=%s, lastVersion=%s, effective=%s',
    logicalType,
    lastVersion,
    effectiveType,
  );

  return effectiveType;
}
