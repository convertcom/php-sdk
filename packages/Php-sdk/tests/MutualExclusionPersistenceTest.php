<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

require_once __DIR__ . '/../../Data/tests/MutualExclusionFixture.php';
require_once __DIR__ . '/../../Data/tests/Support/MutualExclusionTestSupport.php';

use ConvertSdk\ConvertSDK;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Tests\Support\MutualExclusionAudienceBuilder;
use ConvertSdk\Tests\Support\MutualExclusionDataStoreDouble;
use ConvertSdk\Tests\Support\MutualExclusionLogCapture;
use OpenAPI\Client\BucketingAttributes;
use PHPUnit\Framework\TestCase;

/**
 * qs-03 (mutual-exclusion audience rule) — AC3 (persistence, fixture row 8),
 * through the PUBLIC ConvertSDK/Context API.
 *
 * Contract (qs-03-mutual-exclusion-rule.md line 83): "With a persistent
 * store backing: A's decision written in one request excludes the visitor
 * from B in a NEW request/SDK instance."
 *
 * Two SEPARATE ConvertSDK::create() instances (fresh Core/DataManager memory
 * each — `_bucketedVisitors` never shared) wired to the SAME
 * MutualExclusionDataStoreDouble via the public `dataStore` config key
 * (ConvertSDK.php:156-157: `$dataStore = $configuration['dataStore'] ?? $cache;
 * $dataManager->setDataStore($dataStore);`). The second instance's DataManager
 * has an empty in-memory store for this visitor — its getData() can ONLY see
 * exp-a's decision by reading it back out of the shared store, exactly
 * fixture row 8 ("bucketed only in the persistent store").
 *
 * Genuineness note: like MutualExclusionIntegrationTest's "excluded"
 * scenario, DataManager.php:410's current `if ($visitorProperties)` gate
 * ALSO returns null for `[]` properties regardless of the store — so a bare
 * `assertNull($variationB)` would coincidentally pass today without any real
 * cross-instance persistence check. A `logger.customLoggers` PSR-3 capture
 * (public config, see ConvertSDK::create()) proves
 * DataManager::filterMatchedRecordsWithRule() was genuinely invoked on
 * $sdk2's instance (it traces unconditionally on every call,
 * DataManager.php:1363..1371).
 */
final class MutualExclusionPersistenceTest extends TestCase
{
    public function testExpADecisionInOneInstanceExcludesVisitorFromExpBInANewInstance(): void
    {
        $configData = MutualExclusionAudienceBuilder::withExclusionOnExperienceB(
            MutualExclusionAudienceBuilder::loadBaseConfigData()
        );
        $sharedStore = new MutualExclusionDataStoreDouble();
        $visitorId = 'mx-ac3-visitor';
        $attributes = new BucketingAttributes(['ignoreLocationProperties' => true]);

        $sdk1 = ConvertSDK::create([
            'data' => $configData,
            'environment' => 'staging',
            'network' => ['tracking' => false],
            'dataStore' => $sharedStore,
        ]);
        $context1 = $sdk1->createContext($visitorId);
        $variationA = $context1->runExperience(MutualExclusionFixture::EXPERIENCE_A_KEY, $attributes);
        self::assertInstanceOf(BucketedVariation::class, $variationA, 'First instance must bucket the visitor into exp-a.');

        // Sanity: the shared store double actually received exp-a's write —
        // otherwise the second instance's exclusion below would be
        // impossible to attribute to persistence at all.
        self::assertNotEmpty($sharedStore->setCalls, 'exp-a\'s decision must be written to the shared persistent store.');

        // NEW instance, same shared store, same visitor id — fresh in-memory
        // DataManager state for this visitor (row 8: bucketing map present
        // ONLY in the persistent store from this instance's point of view).
        $logCapture = new MutualExclusionLogCapture();
        $sdk2 = ConvertSDK::create([
            'data' => $configData,
            'environment' => 'staging',
            'network' => ['tracking' => false],
            'dataStore' => $sharedStore,
            'logger' => [
                'logLevel' => LogLevel::Trace,
                'customLoggers' => [$logCapture],
            ],
        ]);
        $context2 = $sdk2->createContext($visitorId);

        $variationB = $context2->runExperience(MutualExclusionFixture::EXPERIENCE_B_KEY, $attributes);

        self::assertTrue(
            $logCapture->hasMessageContaining('filterMatchedRecordsWithRule'),
            'DataManager::filterMatchedRecordsWithRule() must be invoked on the NEW instance even with empty '
                . 'visitorProperties (DataManager.php:410 gate must widen for AC4) — otherwise a null result below '
                . 'is coincidental, not real cross-instance persistence.'
        );
        self::assertNull(
            $variationB,
            'A visitor bucketed into exp-a by a PRIOR instance must be excluded from exp-b on a NEW instance sharing the same persistent store.'
        );
    }
}
