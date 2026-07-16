<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

require_once __DIR__ . '/MutualExclusionFixture.php';
require_once __DIR__ . '/Support/MutualExclusionTestSupport.php';

use ConvertSdk\Tests\Support\MutualExclusionAudienceBuilder;
use ConvertSdk\Tests\Support\MutualExclusionDataManagerFactory;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\IdentityField;
use OpenAPI\Client\Model\GenericListMatchingOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * qs-03 (mutual-exclusion audience rule) — AC6 (combination semantics).
 *
 * "Identical ALL/ANY combination ... semantics as existing rules"
 * (qs-03-mutual-exclusion-rule.md line 56). Attaches TWO single-rule
 * audiences to the real exp-b — one generic `generic_key_value` rule, one
 * negated `bucketed_into_experience_key` rule targeting exp-a — and drives
 * `experience.settings.matching_options.audiences` (all/any) across a small
 * table proving the new rule type combines exactly like any other rule via
 * DataManager::matchRulesByField() (see MutualExclusionRuleResolutionTest
 * for the full seam signature/contract).
 *
 * Unlike the AC1/AC4/AC8 fixture table, this test legitimately supplies
 * non-empty visitorProperties in some rows to drive the generic rule's
 * outcome — AC6 is about ALL/ANY combination logic, not the zero-input
 * property (that is AC4's concern, covered by MutualExclusionRuleResolutionTest).
 *
 * Every row asserts the exclusion audience's OWN resolution (via
 * SpyRuleManager::lastResultForLogEntryContaining()) against its intended
 * boolean, independently of the ALL/ANY aggregate — this is what forces a
 * genuine failure on every row today: the exclusion rule currently either
 * (a) resolves to a fixed `false` fall-through (no `key` field => "unknown
 * rule_type" path, RuleManager.php's fail-closed default) whenever the
 * audience block is entered, or (b) is never evaluated at all when
 * visitorProperties is `[]` (DataManager.php:410 gate). Neither matches this
 * table's intended values in every row, so relying on the aggregate
 * `matched` alone would let some rows pass by coincidence.
 */
final class MutualExclusionCombinationTest extends TestCase
{
    private const GENERIC_KEY = 'plan';
    private const GENERIC_VALUE = 'enterprise';

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool}>
     *   [matchingOption, genericConditionSatisfied, exclusionIntendedMatched, expectedAggregateMatched]
     */
    public static function combinations(): array
    {
        return [
            'all_bothPass_matches' => [GenericListMatchingOptions::ALL, true, true, true],
            'all_genericFails_noMatch' => [GenericListMatchingOptions::ALL, false, true, false],
            'any_exclusionPasses_matches' => [GenericListMatchingOptions::ANY, false, true, true],
            'any_bothFail_noMatch' => [GenericListMatchingOptions::ANY, false, false, false],
        ];
    }

    #[DataProvider('combinations')]
    public function testGenericAndExclusionRulesCombinePerMatchingOption(
        string $matchingOption,
        bool $genericConditionSatisfied,
        bool $exclusionIntendedMatched,
        bool $expectedAggregateMatched
    ): void {
        $visitorId = 'mx-combination-visitor';
        $configData = MutualExclusionAudienceBuilder::withCombinedAudiencesOnExperienceB(
            MutualExclusionAudienceBuilder::loadBaseConfigData(),
            $matchingOption,
            self::GENERIC_KEY,
            self::GENERIC_VALUE,
            exclusionNegated: true // matches when visitor is NOT bucketed into exp-a
        );

        $built = MutualExclusionDataManagerFactory::build($configData);
        $dataManager = $built['dataManager'];
        $ruleManager = $built['ruleManager'];

        // exclusionIntendedMatched=false requires bucketedRaw=true (negated flips it to false).
        if (!$exclusionIntendedMatched) {
            $dataManager->putData($visitorId, [
                'bucketing' => [MutualExclusionFixture::EXPERIENCE_A_ID => MutualExclusionFixture::VARIATION_A_ID],
            ]);
        }

        $visitorProperties = $genericConditionSatisfied ? [self::GENERIC_KEY => self::GENERIC_VALUE] : [];

        $result = $dataManager->matchRulesByField(
            $visitorId,
            MutualExclusionFixture::EXPERIENCE_B_KEY,
            IdentityField::KEY,
            new BucketingAttributes([
                'visitorProperties' => $visitorProperties,
                'ignoreLocationProperties' => true,
            ])
        );

        self::assertSame(
            $exclusionIntendedMatched,
            $ruleManager->lastResultForLogEntryContaining(MutualExclusionAudienceBuilder::EXCLUSION_AUDIENCE_KEY),
            'The exclusion audience must itself resolve to its intended boolean, independent of the ALL/ANY aggregate.'
        );

        self::assertSame(
            $expectedAggregateMatched,
            $result !== null,
            sprintf(
                'matching_options.audiences=%s, generic=%s, exclusionIntended=%s: expected matched=%s, got %s',
                $matchingOption,
                $genericConditionSatisfied ? 'pass' : 'fail',
                $exclusionIntendedMatched ? 'true' : 'false',
                $expectedAggregateMatched ? 'true' : 'false',
                $result === null ? 'null' : 'non-null'
            )
        );
    }
}
