<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

require_once __DIR__ . '/Support/FeaturePathTestDoubles.php';

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Context;
use ConvertSdk\DataManager;
use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Event\EventManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\Tests\Support\FeaturePathCountingApiManager;
use ConvertSdk\Tests\Support\FeaturePathRecordingDataStore;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CAP-1 (SPEC-per-call-bucketing-attributes) — Context::runFeature()/runFeatures()
 * forward the full BucketingAttributes object, mirroring runExperience()/runExperiences().
 */
class ContextFeatureBucketingAttributesTest extends TestCase
{
    private $config;
    private $bucketingManager;
    private $ruleManager;
    private $eventManager;
    private $apiManager;
    private $loggerManager;
    private $dataManager;
    private $experienceManager;
    private $featureManager;
    private $segmentsManager;
    private $context;
    private $visitorId = 'XXX';

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $configuration = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, [
            'api' => [
                'endpoint' => [
                    'config' => 'http://127.0.0.1:9501',
                    'track' => 'http://127.0.0.1:9501',
                ],
            ],
            'events' => [
                'batch_size' => 5,
                'release_interval' => 1000,
            ],
        ]);
        $configuration['data'] = new ConfigResponseData($configuration['data']);
        if (isset($configuration['sdkKey'])) {
            unset($configuration['sdkKey']);
        }

        $this->config = new Config($configuration);
        $this->loggerManager = new LogManager();
        $bucketingConfig = $this->config->getBucketing();
        $this->bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $this->ruleManager = new RuleManager();
        $this->eventManager = new EventManager();
        $this->apiManager = new ApiManager($this->config, $this->eventManager, $this->loggerManager);
        $this->dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager
        );
        $this->experienceManager = new ExperienceManager(dataManager: $this->dataManager);
        $this->featureManager = new FeatureManager(dataManager: $this->dataManager);
        $this->segmentsManager = new SegmentsManager($this->config, $this->dataManager, $this->ruleManager);

        $this->context = new Context(
            $this->config,
            $this->visitorId,
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
        );
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
    }

    private function buildContext(string $visitorId): Context
    {
        return new Context(
            $this->config,
            $visitorId,
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
        );
    }

    /** @return array<string, mixed> */
    private function forwardedAttributesFixture(): array
    {
        return [
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something'],
            'enableTracking' => false,
            'forceVariationId' => '100299461',
            'ignoreLocationProperties' => true,
            'updateVisitorProperties' => true,
            'suppressPersistence' => true,
        ];
    }

    private function assertAllAttributesForwarded(?BucketingAttributes $captured): void
    {
        $this->assertNotNull($captured);
        $this->assertFalse($captured->enableTracking);
        $this->assertSame('100299461', $captured->forceVariationId);
        $this->assertTrue($captured->ignoreLocationProperties);
        $this->assertTrue($captured->updateVisitorProperties);
        $this->assertTrue($captured->suppressPersistence);
        $this->assertSame(['url' => 'https://convert.com/'], $captured->locationProperties);
        $this->assertSame(['varName3' => 'something'], $captured->visitorProperties);
        $this->assertSame($this->config->getEnvironment(), $captured->environment);
        // Caller did not supply typeCasting — Context must not invent a default.
        $this->assertNull($captured->typeCasting);
    }

    public function testRunFeatureForwardsAllBucketingAttributes(): void
    {
        $realManager = $this->featureManager;
        $captured = null;

        $spy = $this->createMock(FeatureManagerInterface::class);
        $spy->method('runFeature')->willReturnCallback(
            function (string $visitorId, string $featureKey, BucketingAttributes $attributes, ?array $experienceKeys = null) use ($realManager, &$captured) {
                $captured = $attributes;
                return $realManager->runFeature($visitorId, $featureKey, $attributes, $experienceKeys);
            }
        );

        $context = new Context(
            $this->config,
            $this->visitorId,
            $this->eventManager,
            $this->experienceManager,
            $spy,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
        );

        $context->runFeature('feature-1', new BucketingAttributes($this->forwardedAttributesFixture()));

        $this->assertAllAttributesForwarded($captured);
    }

    public function testRunFeaturesForwardsAllBucketingAttributes(): void
    {
        $realManager = $this->featureManager;
        $captured = null;

        $spy = $this->createMock(FeatureManagerInterface::class);
        $spy->method('runFeatures')->willReturnCallback(
            function (string $visitorId, BucketingAttributes $attributes, ?array $filter = null) use ($realManager, &$captured) {
                $captured = $attributes;
                return $realManager->runFeatures($visitorId, $attributes, $filter);
            }
        );

        $context = new Context(
            $this->config,
            $this->visitorId,
            $this->eventManager,
            $this->experienceManager,
            $spy,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
        );

        $context->runFeatures(new BucketingAttributes($this->forwardedAttributesFixture()));

        $this->assertAllAttributesForwarded($captured);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: FeatureStatus}> */
    public static function locationGateCasesProvider(): array
    {
        return [
            'no flag, no locationProperties — rejected' => [[], FeatureStatus::Disabled],
            'ignoreLocationProperties: true, no locationProperties — bypasses the gate' => [
                ['ignoreLocationProperties' => true],
                FeatureStatus::Enabled,
            ],
            "ignoreLocationProperties: 'true' (string) — strict comparison, does not bypass" => [
                ['ignoreLocationProperties' => 'true'],
                FeatureStatus::Disabled,
            ],
        ];
    }

    #[DataProvider('locationGateCasesProvider')]
    public function testIgnoreLocationPropertiesGatesRunFeature(array $extraAttributes, FeatureStatus $expectedStatus): void
    {
        $attributes = array_merge(['visitorProperties' => ['varName3' => 'something']], $extraAttributes);
        $result = $this->context->runFeature('feature-2', new BucketingAttributes($attributes));

        $this->assertInstanceOf(BucketedFeature::class, $result);
        $this->assertSame($expectedStatus, $result->status);
    }

    #[DataProvider('locationGateCasesProvider')]
    public function testIgnoreLocationPropertiesGatesRunFeatures(array $extraAttributes, FeatureStatus $expectedStatus): void
    {
        $attributes = array_merge(['visitorProperties' => ['varName3' => 'something']], $extraAttributes);
        $results = $this->context->runFeatures(new BucketingAttributes($attributes));

        $byKey = [];
        foreach ($results as $feature) {
            $byKey[$feature->featureKey] = $feature;
        }

        $this->assertArrayHasKey('feature-2', $byKey);
        $this->assertSame($expectedStatus, $byKey['feature-2']->status);
    }

    public function testForceVariationIdBindsOnRunFeature(): void
    {
        $attributesFor = fn (string $forceVariationId) => new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something'],
            'forceVariationId' => $forceVariationId,
        ]);

        $enabled = $this->buildContext('force-run-feature-enabled-target')
            ->runFeature('feature-2', $attributesFor('100299456'));
        $this->assertInstanceOf(BucketedFeature::class, $enabled);
        $this->assertSame(FeatureStatus::Enabled, $enabled->status);
        $this->assertArrayHasKey('price', $enabled->variables);

        $disabled = $this->buildContext('force-run-feature-disabled-target-v2')
            ->runFeature('feature-2', $attributesFor('100299457'));
        $this->assertInstanceOf(BucketedFeature::class, $disabled);
        $this->assertSame(FeatureStatus::Disabled, $disabled->status);
    }

    public function testForceVariationIdBindsOnRunFeatures(): void
    {
        $attributesFor = fn (string $forceVariationId) => new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something'],
            'forceVariationId' => $forceVariationId,
        ]);

        $enabledResults = $this->buildContext('force-run-features-enabled-target-v2')
            ->runFeatures($attributesFor('100299456'));
        $enabledByKey = [];
        foreach ($enabledResults as $feature) {
            $enabledByKey[$feature->featureKey] = $feature;
        }
        $this->assertArrayHasKey('feature-2', $enabledByKey);
        $this->assertSame(FeatureStatus::Enabled, $enabledByKey['feature-2']->status);

        $disabledResults = $this->buildContext('force-run-features-disabled-target')
            ->runFeatures($attributesFor('100299457'));
        $disabledByKey = [];
        foreach ($disabledResults as $feature) {
            $disabledByKey[$feature->featureKey] = $feature;
        }
        $this->assertArrayHasKey('feature-2', $disabledByKey);
        $this->assertSame(FeatureStatus::Disabled, $disabledByKey['feature-2']->status);
    }

    // Spy the third argument, not the DTO — D-5 (SPEC-per-call-bucketing-attributes).
    public function testRunFeaturesForwardsExperienceKeysAsExperiencesFilter(): void
    {
        $realManager = $this->featureManager;
        $capturedFilter = null;

        $spy = $this->createMock(FeatureManagerInterface::class);
        $spy->method('runFeatures')->willReturnCallback(
            function (string $visitorId, BucketingAttributes $attributes, ?array $filter = null) use ($realManager, &$capturedFilter) {
                $capturedFilter = $filter;
                return $realManager->runFeatures($visitorId, $attributes, $filter);
            }
        );

        $context = new Context(
            $this->config,
            $this->visitorId,
            $this->eventManager,
            $this->experienceManager,
            $spy,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
        );

        $context->runFeatures(new BucketingAttributes([
            'experienceKeys' => ['test-experience-ab-fullstack-3'],
        ]));

        $this->assertIsArray($capturedFilter);
        // The filter key is 'experiences' — FeatureManagerInterface's own docblock says
        // 'experienceKeys', but every implementation reads 'experiences'.
        $this->assertSame(['test-experience-ab-fullstack-3'], $capturedFilter['experiences'] ?? null);
        $this->assertArrayNotHasKey('features', $capturedFilter);
    }

    /** @return array<string, mixed> */
    private function locationAndVisitorPropertiesFixture(): array
    {
        return [
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something'],
        ];
    }

    /**
     * @param array<int, BucketedFeature> $features
     * @return array<string, BucketedFeature>
     */
    private function featuresByKey(array $features): array
    {
        $byKey = [];
        foreach ($features as $feature) {
            $byKey[$feature->featureKey] = $feature;
        }
        return $byKey;
    }

    /**
     * CAP-2 (SPEC-per-call-bucketing-attributes) — narrowing to an experience that does not
     * carry a feature must disable that feature without omitting it from the result array.
     */
    public function testRunFeaturesNarrowsToFilteredExperienceButKeepsEveryDeclaredFeature(): void
    {
        $unfiltered = $this->featuresByKey(
            $this->context->runFeatures(new BucketingAttributes($this->locationAndVisitorPropertiesFixture()))
        );
        $this->assertSame(FeatureStatus::Enabled, $unfiltered['feature-1']->status);
        $this->assertSame(FeatureStatus::Enabled, $unfiltered['feature-2']->status);

        $narrowed = $this->featuresByKey($this->context->runFeatures(new BucketingAttributes(
            $this->locationAndVisitorPropertiesFixture() + ['experienceKeys' => ['test-experience-ab-fullstack-3']]
        )));

        $this->assertArrayHasKey('feature-1', $narrowed);
        $this->assertArrayHasKey('feature-2', $narrowed);
        $this->assertSame(FeatureStatus::Disabled, $narrowed['feature-2']->status);
    }

    /** @return array<string, array{0: ?array<int, string>, 1: FeatureStatus, 2: FeatureStatus}> */
    public static function experienceKeysFilterCasesProvider(): array
    {
        return [
            'experienceKeys absent — every experience evaluated' => [
                null,
                FeatureStatus::Enabled,
                FeatureStatus::Enabled,
            ],
            'experienceKeys empty array — every experience, not "match nothing"' => [
                [],
                FeatureStatus::Enabled,
                FeatureStatus::Enabled,
            ],
            'one unknown key among known — the known key still resolves' => [
                ['does-not-exist', 'test-experience-ab-fullstack-3'],
                FeatureStatus::Enabled,
                FeatureStatus::Disabled,
            ],
            'every key unknown — zero experiences, every feature disabled' => [
                ['does-not-exist-1', 'does-not-exist-2'],
                FeatureStatus::Disabled,
                FeatureStatus::Disabled,
            ],
        ];
    }

    #[DataProvider('experienceKeysFilterCasesProvider')]
    public function testRunFeaturesHonoursExperienceKeysFilter(
        ?array $experienceKeys,
        FeatureStatus $expectedFeature1Status,
        FeatureStatus $expectedFeature2Status
    ): void {
        $attributesData = $this->locationAndVisitorPropertiesFixture();
        if ($experienceKeys !== null) {
            $attributesData['experienceKeys'] = $experienceKeys;
        }

        $byKey = $this->featuresByKey($this->context->runFeatures(new BucketingAttributes($attributesData)));

        $this->assertSame($expectedFeature1Status, $byKey['feature-1']->status);
        $this->assertSame($expectedFeature2Status, $byKey['feature-2']->status);
    }

    /**
     * CAP-2 (SPEC-per-call-bucketing-attributes) — the order of experienceKeys must not affect
     * the result: DataManager::getItemsByKeys() iterates the declared list, not the filter.
     */
    public function testRunFeaturesIgnoresExperienceKeysOrder(): void
    {
        $configOrder = $this->featuresByKey($this->context->runFeatures(new BucketingAttributes(
            $this->locationAndVisitorPropertiesFixture() + [
                'experienceKeys' => ['test-experience-ab-fullstack-3', 'test-experience-ab-fullstack-4'],
            ]
        )));
        $reversedOrder = $this->featuresByKey($this->context->runFeatures(new BucketingAttributes(
            $this->locationAndVisitorPropertiesFixture() + [
                'experienceKeys' => ['test-experience-ab-fullstack-4', 'test-experience-ab-fullstack-3'],
            ]
        )));

        // feature-2 is only carried by test-experience-ab-fullstack-2, excluded from this
        // filter — Disabled here (rather than Enabled, seen unfiltered) proves the filter
        // was actually applied rather than merely that the call is deterministic.
        $this->assertSame(FeatureStatus::Enabled, $configOrder['feature-1']->status);
        $this->assertSame(FeatureStatus::Disabled, $configOrder['feature-2']->status);
        $this->assertSame(FeatureStatus::Enabled, $reversedOrder['feature-1']->status);
        $this->assertSame(FeatureStatus::Disabled, $reversedOrder['feature-2']->status);
        $this->assertEquals($configOrder, $reversedOrder);
    }

    /**
     * CAP-1 (SPEC-per-call-bucketing-attributes) — suppressPersistence must gate BOTH the
     * sticky-decision write and the tracking enqueue on a non-preview context, unlike
     * enableTracking: false (ContextFeatureTrackingSuppressionTest), which only gates the
     * enqueue and still persists.
     */
    public function testSuppressPersistenceProducesNoWritesOnNonPreviewContext(): void
    {
        $attributes = fn () => new BucketingAttributes(
            $this->locationAndVisitorPropertiesFixture() + ['suppressPersistence' => true, 'enableTracking' => true]
        );

        $featureRig = $this->buildSuppressionRig('bucketing-attrs-suppress-persistence-feature');
        $featureRig['context']->runFeature('feature-1', $attributes());
        $this->assertSame(0, $featureRig['apiManager']->enqueueCalls);
        $this->assertSame(0, $featureRig['dataStore']->setCalls);

        $featuresRig = $this->buildSuppressionRig('bucketing-attrs-suppress-persistence-features');
        $featuresRig['context']->runFeatures($attributes());
        $this->assertSame(0, $featuresRig['apiManager']->enqueueCalls);
        $this->assertSame(0, $featuresRig['dataStore']->setCalls);
    }

    /**
     * bucketing-attributes.md forceVariationId property 4 — a force disagreeing with a
     * stored decision recomputes and writes through as the visitor's new sticky decision.
     */
    public function testForceVariationIdDisagreeingWithStoredDecisionWritesThrough(): void
    {
        $visitorId = 'bucketing-attrs-force-write-through';
        $experienceId = '100218246'; // test-experience-ab-fullstack-3, carries feature-1
        $rig = $this->buildSuppressionRig($visitorId);

        $natural = $rig['context']->runFeature('feature-1', new BucketingAttributes($this->locationAndVisitorPropertiesFixture()));
        $this->assertInstanceOf(BucketedFeature::class, $natural);
        $storedNatural = $rig['dataManager']->getData($visitorId)['bucketing'][$experienceId] ?? null;
        $this->assertNotNull($storedNatural, 'the natural call must have bucketed and persisted a decision to disagree with');

        $forcedVariationId = $storedNatural === '100299460' ? '100299461' : '100299460';

        $forced = $rig['context']->runFeature('feature-1', new BucketingAttributes(
            $this->locationAndVisitorPropertiesFixture() + ['forceVariationId' => $forcedVariationId]
        ));
        $this->assertInstanceOf(BucketedFeature::class, $forced);
        $this->assertGreaterThan(0, $rig['dataStore']->setCalls, 'a disagreeing force must still write through to the dataStore');
        $storedAfterForce = $rig['dataManager']->getData($visitorId)['bucketing'][$experienceId] ?? null;
        $this->assertSame($forcedVariationId, $storedAfterForce, 'a disagreeing force must overwrite the stored decision');

        $reread = $rig['context']->runFeature('feature-1', new BucketingAttributes($this->locationAndVisitorPropertiesFixture()));
        $this->assertInstanceOf(BucketedFeature::class, $reread);
        $storedAfterReread = $rig['dataManager']->getData($visitorId)['bucketing'][$experienceId] ?? null;
        $this->assertSame($forcedVariationId, $storedAfterReread, 'a subsequent no-force call must return the now-sticky forced decision');
    }

    /** @return array{context: Context, apiManager: FeaturePathCountingApiManager, dataStore: FeaturePathRecordingDataStore, dataManager: DataManager} */
    private function buildSuppressionRig(string $visitorId): array
    {
        $bucketingConfig = $this->config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $eventManager = new EventManager();
        $apiManager = new FeaturePathCountingApiManager(new ApiManager($this->config, $eventManager, $this->loggerManager));
        $dataManager = new DataManager($this->config, $bucketingManager, $ruleManager, $eventManager, $apiManager, $this->loggerManager);
        $dataStore = new FeaturePathRecordingDataStore();
        $dataManager->setDataStore($dataStore);

        $experienceManager = new ExperienceManager(dataManager: $dataManager);
        $featureManager = new FeatureManager(dataManager: $dataManager);
        $segmentsManager = new SegmentsManager($this->config, $dataManager, $ruleManager);

        $context = new Context(
            $this->config,
            $visitorId,
            $eventManager,
            $experienceManager,
            $featureManager,
            $dataManager,
            $segmentsManager,
            $apiManager,
        );

        return ['context' => $context, 'apiManager' => $apiManager, 'dataStore' => $dataStore, 'dataManager' => $dataManager];
    }
}
