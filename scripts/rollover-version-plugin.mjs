import { CommitParser } from 'conventional-commits-parser';

const parser = new CommitParser();

/**
 * Custom semantic-release analyzeCommits plugin.
 *
 * Determines the logical release type from conventional commits, then
 * translates it into the semver bump that respects the digit-capped
 * rollover scheme (each position capped at 9).
 *
 * Commit mapping:
 *   fix: / feat:           → logical patch
 *   refactor:              → logical minor
 *   BREAKING CHANGE        → logical major (direct, no rollover)
 *   everything else        → no release
 *
 * Rollover rules (patch and minor only):
 *   logical patch: patch<9 → patch | minor<9 → minor | else → major
 *   logical minor: minor<9 → minor | else → major
 *   logical major: always → major (standard semver)
 */

function getLogicalType(commits) {
  let hasMajor = false;
  let hasMinor = false;
  let hasPatch = false;

  for (const commit of commits) {
    const parsed = parser.parse(commit.message);
    if (!parsed) continue;

    const type = parsed.type;
    const hasBreaking =
      parsed.notes?.some((note) => note.title === 'BREAKING CHANGE') ?? false;

    if (hasBreaking) {
      hasMajor = true;
    } else if (type === 'refactor') {
      hasMinor = true;
    } else if (type === 'feat' || type === 'fix') {
      hasPatch = true;
    }
  }

  if (hasMajor) return 'major';
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

  if (logicalType === 'major') {
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
