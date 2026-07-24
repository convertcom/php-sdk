<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

/**
 * qs-03 (mutual-exclusion audience rule, `bucketed_into_experience_key`) — the
 * single hoisted, contract-exact 8-row fixture table from
 * `qs-03-mutual-exclusion-rule.md` lines 66-77. Expected values ARE the
 * contract; never recompute or "improve" them independently of the spec.
 *
 * Pairs with `mutual-exclusion-config.json` in this directory, which defines
 * the two experiences the fixture targets:
 *   - exp-a: id "100111", variation "100901" (a/b_fullstack, active)
 *   - exp-b: id "100222", variation "100902" (a/b_fullstack, active)
 * "exp-zz" (rows 6/7) is deliberately absent from that config — it is the
 * unknown-target case (AC8).
 *
 * NOT PSR-4/classmap autoloadable: the root composer.json maps `ConvertSdk\Tests\`
 * only to `packages/Utils/tests/` (composer.json:52), and this file's name does
 * not match PHPUnit's `*Test.php` directory-collector pattern. Any consumer —
 * in this package or another — MUST `require_once` this file's absolute path
 * before referencing the class (see `MutualExclusionFixtureTest.php` in this
 * same directory for the in-package pattern). Once loaded, consume rows via
 * PHPUnit's `#[DataProviderExternal(MutualExclusionFixture::class, 'rows')]`
 * attribute (PHPUnit 11).
 */
final class MutualExclusionFixture
{
    public const EXPERIENCE_A_KEY = 'exp-a';
    public const EXPERIENCE_A_ID = '100111';
    public const VARIATION_A_ID = '100901';

    public const EXPERIENCE_B_KEY = 'exp-b';
    public const EXPERIENCE_B_ID = '100222';
    public const VARIATION_B_ID = '100902';

    /** Target key that does not exist in mutual-exclusion-config.json (AC8). */
    public const UNKNOWN_EXPERIENCE_KEY = 'exp-zz';

    /**
     * Row shape (positional, matches spec table columns 1:1 plus two derived
     * flags):
     *
     *   0 => bucketingMap    array  What getData($visitorId)['bucketing'] must
     *                               resolve to (merged in-memory + store) at
     *                               evaluation time. Keyed by (string) target
     *                               experience id, per DataManager's stored
     *                               bucketing-map shape.
     *   1 => ruleValue       string The `bucketed_into_experience_key` rule's
     *                               `value` (a target experience KEY, not id).
     *   2 => negated         bool   `matching.negated` on the rule.
     *   3 => expectedMatched bool   The contract's expected `matched` result.
     *   4 => expectsWarning  bool   true only for rows 6/7 (AC8) — the
     *                               unknown-target-key warning must fire,
     *                               naming `ruleValue`.
     *   5 => storeOnly       bool   true only for row 8 (AC3) — the fixture's
     *                               bucketingMap MUST be placed exclusively in
     *                               the persistent DataStore/cache, never in
     *                               the in-memory bucketing map, to prove
     *                               cross-request attribution. false for every
     *                               other row means "in-memory is sufficient"
     *                               — it does not forbid also exercising a
     *                               persistent store for those rows.
     *
     * @return array<string, array{0: array<string, string>, 1: string, 2: bool, 3: bool, 4: bool, 5: bool}>
     */
    public static function rows(): array
    {
        return [
            'row1_emptyMap_notNegated_expA' => [
                [],
                self::EXPERIENCE_A_KEY,
                false,
                false,
                false,
                false,
            ],
            'row2_emptyMap_negated_expA' => [
                [],
                self::EXPERIENCE_A_KEY,
                true,
                true,
                false,
                false,
            ],
            'row3_bucketedIntoA_notNegated_expA' => [
                [self::EXPERIENCE_A_ID => self::VARIATION_A_ID],
                self::EXPERIENCE_A_KEY,
                false,
                true,
                false,
                false,
            ],
            'row4_bucketedIntoA_negated_expA' => [
                [self::EXPERIENCE_A_ID => self::VARIATION_A_ID],
                self::EXPERIENCE_A_KEY,
                true,
                false,
                false,
                false,
            ],
            'row5_bucketedIntoB_negated_expA' => [
                [self::EXPERIENCE_B_ID => self::VARIATION_B_ID],
                self::EXPERIENCE_A_KEY,
                true,
                true,
                false,
                false,
            ],
            'row6_unknownTarget_notNegated' => [
                [],
                self::UNKNOWN_EXPERIENCE_KEY,
                false,
                false,
                true,
                false,
            ],
            'row7_unknownTarget_negated' => [
                [],
                self::UNKNOWN_EXPERIENCE_KEY,
                true,
                true,
                true,
                false,
            ],
            'row8_storeOnlyBucketing_negated_expA' => [
                [self::EXPERIENCE_A_ID => self::VARIATION_A_ID],
                self::EXPERIENCE_A_KEY,
                true,
                false,
                false,
                true,
            ],
        ];
    }
}
