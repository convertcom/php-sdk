<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

require_once __DIR__ . '/../../Data/tests/MutualExclusionFixture.php';
require_once __DIR__ . '/../../Data/tests/Support/MutualExclusionTestSupport.php';

use ConvertSdk\ConvertSDK;
use ConvertSdk\Core;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Tests\Support\MutualExclusionAudienceBuilder;
use ConvertSdk\Tests\Support\MutualExclusionLogCapture;
use OpenAPI\Client\BucketingAttributes;
use PHPUnit\Framework\TestCase;

/**
 * qs-03 (mutual-exclusion audience rule) — AC2 (end-to-end exclusion), AC4
 * (zero new inputs), through the PUBLIC Context API
 * (Context::runExperience(), see packages/Php-sdk/src/Context.php:172).
 *
 * Modeled on tests/Integration/FullChainIntegrationTest.php's
 * `ConvertSDK::create(['data' => ..., 'environment' => ..., 'network' =>
 * ['tracking' => false]])` + `createContext()->runExperience()` pattern.
 *
 * Config: the real exp-a/exp-b from mutual-exclusion-config.json, with the
 * negated `bucketed_into_experience_key` rule (targeting exp-a) attached as
 * exp-b's SOLE audience (`matching_options.audiences = all`) — see
 * MutualExclusionAudienceBuilder::withExclusionOnExperienceB().
 *
 * AC4: no visitorAttributes are ever passed to createContext(), and no
 * visitorProperties are ever set on the BucketingAttributes passed to
 * runExperience() — Context::getVisitorProperties() resolves this to `[]`.
 * `ignoreLocationProperties: true` is set purely to bypass the (unrelated)
 * location-matching gate at DataManager.php:353-397, which independently
 * requires either locationProperties or ignoreLocationProperties for ANY
 * experience lacking a location restriction — orthogonal to qs-03, and not
 * a "new application input" in the AC4 sense (it carries no visitor data).
 *
 * Genuineness note: for the "excluded" scenario, DataManager.php:410's
 * current `if ($visitorProperties)` gate ALSO happens to return null with
 * `[]` properties (audience block skipped entirely) — the SAME null a
 * correct exclusion would produce. To avoid a coincidental pass, this test
 * additionally injects a `logger.customLoggers` PSR-3 capture (public config
 * — see ConvertSDK::create()'s `logger.logLevel`/`logger.customLoggers`) and
 * asserts DataManager::filterMatchedRecordsWithRule() was actually invoked
 * (it traces unconditionally on every call, DataManager.php:1363..1371) —
 * proving the audience block was genuinely entered, not gate-skipped.
 */
final class MutualExclusionIntegrationTest extends TestCase
{
    private function noLocationGateAttributes(): BucketingAttributes
    {
        return new BucketingAttributes(['ignoreLocationProperties' => true]);
    }

    /** @return array{sdk: Core, logCapture: MutualExclusionLogCapture} */
    private function createSdkWithLogCapture(): array
    {
        $configData = MutualExclusionAudienceBuilder::withExclusionOnExperienceB(
            MutualExclusionAudienceBuilder::loadBaseConfigData()
        );
        $logCapture = new MutualExclusionLogCapture();
        $sdk = ConvertSDK::create([
            'data' => $configData,
            'environment' => 'staging',
            'network' => ['tracking' => false],
            'logger' => [
                'logLevel' => LogLevel::Trace,
                'customLoggers' => [$logCapture],
            ],
        ]);

        return ['sdk' => $sdk, 'logCapture' => $logCapture];
    }

    public function testVisitorBucketedIntoExpAIsExcludedFromExpB(): void
    {
        ['sdk' => $sdk, 'logCapture' => $logCapture] = $this->createSdkWithLogCapture();
        $context = $sdk->createContext('mx-ac2-visitor-excluded');

        $variationA = $context->runExperience(MutualExclusionFixture::EXPERIENCE_A_KEY, $this->noLocationGateAttributes());
        self::assertInstanceOf(BucketedVariation::class, $variationA, 'Visitor must bucket into exp-a first.');

        $variationB = $context->runExperience(MutualExclusionFixture::EXPERIENCE_B_KEY, $this->noLocationGateAttributes());

        self::assertTrue(
            $logCapture->hasMessageContaining('filterMatchedRecordsWithRule'),
            'DataManager::filterMatchedRecordsWithRule() must be invoked even with empty visitorProperties '
                . 'once exp-b\'s audience carries a bucketed_into_experience_key rule '
                . '(DataManager.php:410 gate must widen for AC4) — otherwise a null result below is coincidental, not a real exclusion.'
        );
        self::assertNull($variationB, 'Visitor already bucketed into exp-a must be excluded from exp-b.');
    }

    public function testVisitorWhoNeverRanExpABucketsIntoExpBNormally(): void
    {
        ['sdk' => $sdk] = $this->createSdkWithLogCapture();
        $context = $sdk->createContext('mx-ac2-visitor-unbucketed');

        $variationB = $context->runExperience(MutualExclusionFixture::EXPERIENCE_B_KEY, $this->noLocationGateAttributes());
        self::assertInstanceOf(
            BucketedVariation::class,
            $variationB,
            'A visitor who never ran exp-a must bucket into exp-b normally (negated exclusion dissolves).'
        );
    }
}
