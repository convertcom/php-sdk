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
use PHPUnit\Framework\TestCase;

/**
 * qs-03 (mutual-exclusion audience rule) — AC5 (read-only).
 *
 * Contract (qs-03-mutual-exclusion-rule.md line 53): "The check is read-only:
 * never triggers bucketing of the target, never writes, never tracks."
 *
 * Exercises DataManager::matchRulesByField() (see MutualExclusionRuleResolutionTest
 * for the full seam signature/contract) directly, in isolation from the wider
 * bucketing pipeline, so a passing implementation can't hide a read-only
 * violation behind other legitimate writes an experience decision would make.
 * The visitor is NOT bucketed into the target (exp-a) at evaluation time —
 * proving the exclusion CHECK itself never buckets exp-a to find out whether
 * it applies.
 */
final class MutualExclusionReadOnlyTest extends TestCase
{
    public function testEvaluatingExclusionRuleTriggersNoBucketingWriteOrTracking(): void
    {
        $visitorId = 'mx-readonly-visitor';
        $configData = MutualExclusionAudienceBuilder::withUnderTestExperience(
            MutualExclusionAudienceBuilder::loadBaseConfigData(),
            MutualExclusionFixture::EXPERIENCE_A_KEY,
            true // negated: true — matches when visitor is NOT bucketed into exp-a
        );

        $storeDouble = new MutualExclusionDataStoreDouble();
        $built = MutualExclusionDataManagerFactory::build($configData, ['dataStore' => $storeDouble]);
        $dataManager = $built['dataManager'];
        $bucketingManager = $built['bucketingManager'];
        $apiManager = $built['apiManager'];

        // Sanity: visitor has no stored bucketing decision anywhere yet. Note:
        // with a persistent store wired, getData() always merges to at least
        // `[]` (never null — ObjectUtils::objectDeepMerge() of two empty
        // arrays), so the sanity check targets the 'bucketing' sub-key.
        self::assertSame([], $dataManager->getData($visitorId)['bucketing'] ?? [], 'Visitor must start unbucketed everywhere.');

        $result = $dataManager->matchRulesByField(
            $visitorId,
            MutualExclusionAudienceBuilder::UNDER_TEST_EXPERIENCE_KEY,
            IdentityField::KEY,
            new BucketingAttributes([
                'visitorProperties' => [],
                'ignoreLocationProperties' => true,
            ])
        );

        // AC1/row2 shape: negated + not-bucketed => matched. Asserted here too
        // so a future "false negative" implementation (e.g. one that silently
        // buckets the target to force a result) can't slip through unnoticed
        // even though this file's focus is the read-only spies below.
        self::assertNotNull($result, 'Sanity: exclusion rule should resolve matched=true (negated, not bucketed).');

        // matchRulesByField() never buckets ANYTHING itself (bucketing only
        // happens downstream in _getBucketingByField()/_retrieveBucketing(),
        // which this test never calls) — so ANY recorded bucketing call at
        // all, for exp-a or exp-under-test, is already a read-only violation.
        self::assertSame(
            [],
            $bucketingManager->bucketedExperienceIds,
            'Evaluating the exclusion rule must never bucket its target experience (exp-a).'
        );

        self::assertSame(
            [],
            $storeDouble->setCalls,
            'Evaluating the exclusion rule must never write to the persistent store.'
        );

        self::assertSame(
            [],
            $apiManager->enqueuedVisitorIds,
            'Evaluating the exclusion rule must never enqueue a tracking event.'
        );
    }
}
