export default {
  branches: ['main'],
  tagFormat: 'v${version}',
  plugins: [
    // 1. Custom commit analyzer with rollover logic (replaces @semantic-release/commit-analyzer)
    './scripts/rollover-version-plugin.mjs',

    // 2. Generate release notes — only feat/fix/refactor visible
    [
      '@semantic-release/release-notes-generator',
      {
        preset: 'conventionalcommits',
        presetConfig: {
          types: [
            { type: 'feat', section: 'Features' },
            { type: 'fix', section: 'Bug Fixes' },
            { type: 'refactor', section: 'Refactoring' },
            { type: 'chore', hidden: true },
            { type: 'docs', hidden: true },
            { type: 'ci', hidden: true },
            { type: 'test', hidden: true },
            { type: 'style', hidden: true },
            { type: 'perf', hidden: true },
          ],
        },
      },
    ],

    // 3. Write CHANGELOG.md
    '@semantic-release/changelog',

    // 4. Sync all 12 package versions via monorepo-builder
    [
      '@semantic-release/exec',
      {
        prepareCmd:
          'composer exec monorepo-builder bump-interdependency "^${nextRelease.version}" && composer exec monorepo-builder release "${nextRelease.version}"',
      },
    ],

    // 5. Commit CHANGELOG + bumped composer.json files, create tag
    [
      '@semantic-release/git',
      {
        assets: ['CHANGELOG.md', 'packages/*/composer.json', 'composer.json'],
        message:
          'chore(release): v${nextRelease.version} [skip ci]\n\n${nextRelease.notes}',
      },
    ],
  ],
};
