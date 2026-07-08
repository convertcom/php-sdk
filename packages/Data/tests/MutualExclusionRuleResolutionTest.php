<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

require_once __DIR__ . '/MutualExclusionFixture.php';
require_once __DIR__ . '/Support/MutualExclusionTestSupport.php';

use ConvertSdk\Tests\Support\MutualExclusionAudienceBuilder;
use ConvertSdk\Tests\Support\MutualExclusionDataManagerFactory;
use ConvertSdk\Tests\Support\MutualExclusionDataStoreDouble;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\IdentityField;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * qs-03 (mutual-exclusion audience rule, `bucketed_into_experience_key`) —
 * AC1 (fixture), AC4 (zero new inputs), AC8 (unknown-target warning).
 *
 * Seam pinned for Phase 2 (qs-03-mutual-exclusion-rule.md lines 44-56):
 *
 *   DataManager::matchRulesByField(
 *       string $visitorId,
 *       string $identity,
 *       string $identityField,
 *       \OpenAPI\Client\BucketingAttributes $attributes
 *   ): array|\ConvertSdk\Enums\RuleError|null
 *
 * Resolution algorithm under test:
 *   target      = DataManager::getEntity($rule['value'], 'experiences')  (null on miss)
 *   bucketedRaw = target exists AND getData($visitorId)['bucketing'] has an
 *                 entry keyed (string) target['id']
 *   matched     = matching.negated ? !bucketedRaw : bucketedRaw
 *
 * Each fixture row attaches the rule as the SOLE audience on a synthetic
 * "exp-under-test" experience (MutualExclusionAudienceBuilder::
 * withUnderTestExperience()) isolated from exp-a/exp-b, so
 * matchRulesByField()'s non-null/null return is a direct proxy for the
 * audience's (i.e. this one rule's) `matched` boolean.
 *
 * AC4 trap this test is designed to catch: visitorProperties is `[]` for
 * every row. PHP's `[]` is falsy, so DataManager.php:410
 * (`if ($visitorProperties) { ...evaluate audience... }`) currently skips
 * audience evaluation entirely whenever properties are empty — regardless of
 * the audience's rule content. That means today EVERY row resolves to
 * `null` (not-matched), which would let the 4 `expectedMatched === false`
 * rows pass "by accident" without any real implementation. To force a
 * genuine failure on every row, this test additionally asserts
 * RuleManager::isRuleMatched() was actually invoked (via SpyRuleManager) —
 * proving the audience block was genuinely entered, not gate-skipped. That
 * assertion fails for all 8 rows today; Phase 2 must widen the line-410 gate
 * so an audience carrying a `bucketed_into_experience_key` rule is evaluated
 * even with `[]` properties.
 */
final class MutualExclusionRuleResolutionTest extends TestCase
{
    #[DataProviderExternal(MutualExclusionFixture::class, 'rows')]
    public function testFixtureRowResolvesPerContract(
        array $bucketingMap,
        string $ruleValue,
        bool $negated,
        bool $expectedMatched,
        bool $expectsWarning,
        bool $storeOnly
    ): void {
        $visitorId = 'mx-fixture-visitor';
        $configData = MutualExclusionAudienceBuilder::withUnderTestExperience(
            MutualExclusionAudienceBuilder::loadBaseConfigData(),
            $ruleValue,
            $negated
        );

        $storeDouble = $storeOnly ? new MutualExclusionDataStoreDouble() : null;
        $built = MutualExclusionDataManagerFactory::build(
            $configData,
            $storeDouble !== null ? ['dataStore' => $storeDouble] : []
        );
        $dataManager = $built['dataManager'];
        $ruleManager = $built['ruleManager'];
        $logManager = $built['logManager'];

        if ($storeOnly) {
            // Row 8: place the decision ONLY in the persistent store, never
            // in memory — bypass putData() entirely.
            $storeDouble->set($dataManager->getStoreKey($visitorId), ['bucketing' => $bucketingMap]);
        } elseif ($bucketingMap !== []) {
            $dataManager->putData($visitorId, ['bucketing' => $bucketingMap]);
        }

        $result = $dataManager->matchRulesByField(
            $visitorId,
            MutualExclusionAudienceBuilder::UNDER_TEST_EXPERIENCE_KEY,
            IdentityField::KEY,
            new BucketingAttributes([
                'visitorProperties' => [],
                'ignoreLocationProperties' => true,
            ])
        );

        self::assertSame(
            $expectedMatched,
            $result !== null,
            sprintf(
                'Row expected matched=%s but matchRulesByField() returned %s',
                $expectedMatched ? 'true' : 'false',
                $result === null ? 'null' : 'non-null'
            )
        );

        self::assertGreaterThan(
            0,
            $ruleManager->isRuleMatchedCallCount,
            'RuleManager::isRuleMatched() must be invoked even with empty visitorProperties '
                . 'once the audience carries a bucketed_into_experience_key rule '
                . '(DataManager.php:410 gate must widen for AC4).'
        );

        if ($expectsWarning) {
            self::assertTrue(
                $logManager->hasWarnContaining($ruleValue),
                sprintf(
                    'Expected a warn log naming unresolved target key "%s" '
                        . '(Messages::BUCKETING_EXCLUSION_TARGET_NOT_FOUND, AC8).',
                    $ruleValue
                )
            );
        }
    }

    /**
     * Regression test (Gemini review R1, post-qs-03 merge): mirrors fixture
     * row 4 (bucketed into exp-a, negated exclusion rule targeting exp-a,
     * expectedMatched=false) but constructs BucketingAttributes WITHOUT ever
     * setting visitorProperties, so the internal field is genuinely `null`
     * (every other row/test in this file uses `[]`). This is a real,
     * previously-untested caller path: the qs-03 gate widening at
     * DataManager.php:425 (`if ($visitorProperties || $hasBucketingExclusionAudience)`)
     * lets execution reach filterMatchedRecordsWithRule() even when
     * $visitorProperties is null, and that call site used to forward
     * $visitorProperties as-is into filterMatchedRecordsWithRule()'s
     * non-nullable `array $visitorProperties` parameter — a reachable
     * TypeError. Asserts both that no TypeError is thrown AND that the
     * negated-exclusion outcome is correct (mirrors row 4's expectedMatched).
     */
    public function testNullVisitorPropertiesDoesNotThrowOnNegatedExclusion(): void
    {
        $visitorId = 'mx-null-visitor-properties';
        $configData = MutualExclusionAudienceBuilder::withUnderTestExperience(
            MutualExclusionAudienceBuilder::loadBaseConfigData(),
            MutualExclusionFixture::EXPERIENCE_A_KEY,
            true
        );

        $built = MutualExclusionDataManagerFactory::build($configData);
        $dataManager = $built['dataManager'];
        $ruleManager = $built['ruleManager'];

        $dataManager->putData($visitorId, ['bucketing' => [
            MutualExclusionFixture::EXPERIENCE_A_ID => MutualExclusionFixture::VARIATION_A_ID,
        ]]);

        $result = $dataManager->matchRulesByField(
            $visitorId,
            MutualExclusionAudienceBuilder::UNDER_TEST_EXPERIENCE_KEY,
            IdentityField::KEY,
            new BucketingAttributes([
                'ignoreLocationProperties' => true,
                // visitorProperties intentionally omitted -> genuinely null,
                // not [] -- reproduces the previously-untested caller path.
            ])
        );

        self::assertNull(
            $result,
            'Visitor already bucketed into exp-a must be excluded by the negated rule '
                . '(matches fixture row 4) even when visitorProperties was never set (null, not []).'
        );

        self::assertGreaterThan(
            0,
            $ruleManager->isRuleMatchedCallCount,
            'RuleManager::isRuleMatched() must be invoked even with null visitorProperties '
                . 'once the audience carries a bucketed_into_experience_key rule.'
        );
    }
}
