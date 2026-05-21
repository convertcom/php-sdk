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

    // 3. Publish a GitHub Release on the new tag with the generated notes.
    //    No branch commits — Packagist consumes the git tag directly; the
    //    GitHub Release surfaces release notes on github.com and Packagist.
    '@semantic-release/github',
  ],
};
