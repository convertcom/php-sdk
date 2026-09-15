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
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        ];
    }

    private function assertAllAttributesForwarded(?BucketingAttributes $captured): void
    {
        $this->assertNotNull($captured);
        $this->assertFalse($captured->enableTracking);
        $this->assertSame('100299461', $captured->forceVariationId);
        $this->assertTrue($captured->ignoreLocationProperties);
        $this->assertTrue($captured->updateVisitorProperties);
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
}
