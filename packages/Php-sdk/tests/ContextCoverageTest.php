<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Context;
use ConvertSdk\DataManager;
use ConvertSdk\Enums\EntityType;
use ConvertSdk\Event\EventManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Context methods with zero/low coverage:
 * getConfigEntity, getConfigEntityById, getVisitorData, releaseQueues
 */
class ContextCoverageTest extends TestCase
{
    private Config $config;
    private DataManager $dataManager;
    private EventManager $eventManager;
    private ApiManager $apiManager;
    private ExperienceManager $experienceManager;
    private FeatureManager $featureManager;
    private SegmentsManager $segmentsManager;
    private LogManager $loggerManager;
    private Context $context;

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
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $this->eventManager = new EventManager();
        $this->apiManager = new ApiManager($this->config, $this->eventManager, $this->loggerManager);
        $this->dataManager = new DataManager(
            $this->config,
            $bucketingManager,
            $ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager
        );
        $this->experienceManager = new ExperienceManager(dataManager: $this->dataManager);
        $this->featureManager = new FeatureManager(dataManager: $this->dataManager);
        $this->segmentsManager = new SegmentsManager($this->config, $this->dataManager, $ruleManager);

        $this->context = new Context(
            $this->config,
            'test-visitor-coverage',
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

    // All entity keys/IDs below are sourced from packages/Php-sdk/tests/test-config.json.
    // If test-config.json changes, these must be updated to match.

    public function testGetConfigEntityShouldReturnExperienceByKey(): void
    {
        $result = $this->context->getConfigEntity('test-experience-ab-fullstack-2', EntityType::Experience->value);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame('test-experience-ab-fullstack-2', $result['key']);
    }

    public function testGetConfigEntityShouldReturnVariationByKey(): void
    {
        $result = $this->context->getConfigEntity('100299456-original-page', EntityType::Variation->value);
        $this->assertIsArray($result);
        $this->assertSame('100299456-original-page', $result['key']);
    }

    public function testGetConfigEntityByIdShouldReturnExperienceById(): void
    {
        $result = $this->context->getConfigEntityById('100218245', EntityType::Experience->value);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame('100218245', $result['id']);
    }

    public function testGetConfigEntityByIdShouldReturnVariationById(): void
    {
        $result = $this->context->getConfigEntityById('100299456', EntityType::Variation->value);
        $this->assertIsArray($result);
        $this->assertSame('100299456', $result['id']);
    }

    public function testGetVisitorDataShouldReturnArray(): void
    {
        $result = $this->context->getVisitorData();
        $this->assertIsArray($result);
    }

    public function testReleaseQueuesShouldNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        // releaseQueues should work without error even when no data store is set
        $this->context->releaseQueues('test');
    }
}
