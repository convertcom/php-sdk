<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

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
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Counts real enqueue() calls for the suppressPersistence behavioural case below —
 * scoped to this file (rather than reusing ContextFeatureTrackingSuppressionTest's
 * identically-shaped double) so this file loads standalone under PHPUnit's
 * single-file runner, which does not autoload sibling test files.
 */
final class SuppressPersistenceCountingApiManager implements ApiManagerInterface
{
    public int $enqueueCalls = 0;

    public function __construct(private readonly ApiManagerInterface $inner)
    {
    }

    public function request(string $method, array $path, array $data = [], array $headers = []): array
    {
        return $this->inner->request($method, $path, $data, $headers);
    }

    public function enqueue(string $visitorId, VisitorTrackingEvents $eventRequest, ?VisitorSegments $segments = null): void
    {
        $this->enqueueCalls++;
        $this->inner->enqueue($visitorId, $eventRequest, $segments);
    }

    public function releaseQueue(?string $reason = null): void
    {
        $this->inner->releaseQueue($reason);
    }

    public function enableTracking(): void
    {
        $this->inner->enableTracking();
    }

    public function disableTracking(): void
    {
        $this->inner->disableTracking();
    }

    public function setData(ConfigResponseData $data): void
    {
        $this->inner->setData($data);
    }

    public function getConfig(): ConfigResponseData
    {
        return $this->inner->getConfig();
    }

    public function getConfigForExperience(string $experienceId): ConfigResponseData
    {
        return $this->inner->getConfigForExperience($experienceId);
    }
}

/** Minimal duck-typed visitor dataStore — DataManager only calls get()/set(). */
final class SuppressPersistenceRecordingDataStore
{
    public int $setCalls = 0;

    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $data): void
    {
        $this->setCalls++;
        $this->data[$key] = $data;
    }
}

/**
 * CAP-1 (SPEC-per-call-bucketing-attributes) — Context::runFeature()/runFeatures()
 * must forward the full BucketingAttributes object (like runExperience()/
 * runExperiences() already do), not the five-key literal they build today.
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

    /**
     * CAP-2 (SPEC-per-call-bucketing-attributes) — Context::runFeatures() must forward the
     * caller's experienceKeys as the ['experiences' => ...] filter FeatureManager::runFeatures()
     * reads. Assert the spy's third argument, not the captured DTO: the DTO already carries
     * experienceKeys once CAP-1 spreads it, which would pass with the filter still null.
     */
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

    /** @return array{context: Context, apiManager: SuppressPersistenceCountingApiManager, dataStore: SuppressPersistenceRecordingDataStore} */
    private function buildSuppressionRig(string $visitorId): array
    {
        $bucketingConfig = $this->config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $eventManager = new EventManager();
        $apiManager = new SuppressPersistenceCountingApiManager(new ApiManager($this->config, $eventManager, $this->loggerManager));
        $dataManager = new DataManager($this->config, $bucketingManager, $ruleManager, $eventManager, $apiManager, $this->loggerManager);
        $dataStore = new SuppressPersistenceRecordingDataStore();
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

        return ['context' => $context, 'apiManager' => $apiManager, 'dataStore' => $dataStore];
    }
}
