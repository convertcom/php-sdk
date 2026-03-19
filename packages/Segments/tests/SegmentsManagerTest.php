<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\BucketingManager;
use ConvertSdk\RuleManager;
use ConvertSdk\EventManager;
use ConvertSdk\ApiManager;
use ConvertSdk\DataManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\LogManager;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorSegments;
use PHPUnit\Framework\TestCase;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Utils\ObjectUtils;

class SegmentsManagerTest extends TestCase
{
    /** @var array Configuration array */
    protected static $configuration;

    /** @var BucketingManager */
    private $bucketingManager;

    /** @var RuleManager */
    private $ruleManager;

    /** @var EventManager */
    private $eventManager;

    /** @var ApiManager */
    private $apiManager;

    /** @var DataManager */
    protected static $dataManager;

    /** @var SegmentsManager */
    protected static $segmentsManager;

    /** @var string Visitor ID for testing */
    private $visitorId = 'XXX';
    private $batchSize = 5;
    private $releaseTimeout = 1000;


    /**
     * Set up dependencies before each test.
     */
    public static function setUpBeforeClass(): void
    {
        // Load configuration from a test-config.json file
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        self::$configuration = ObjectUtils::ObjectDeepMerge($defaultConfig, $testConfig, [
            'api' => [
                'endpoint' => [
                    'config' => 'http://localhost:8090',
                    'track' => 'http://localhost:8090',
                ],
            ],
            'events' => [
                'batch_size' => 10, // Adjust as needed
                'release_interval' => 1000, // Adjust as needed
            ],
        ]);
        self::$configuration['data'] = new ConfigResponseData(self::$configuration['data']);
        if (isset(self::$configuration['sdkKey'])) {
            unset(self::$configuration['sdkKey']);
        }

        // Create Config object
        $config = new Config(self::$configuration);

        // Initialize all manager instances with dependencies
        $bucketingConfig = $config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $eventManager = new EventManager($config);
        $apiManager = new ApiManager($config, $eventManager);
        $loggerManager = new LogManager($config);
        self::$dataManager = new DataManager(
            $config,
            $bucketingManager,
            $ruleManager,
            $eventManager,
            $apiManager,
            $loggerManager
        );
        self::$segmentsManager = new SegmentsManager($config, self::$dataManager, $ruleManager);
    }

    protected function setUp(): void
    {
    }

    /**
     * Test that the SegmentsManager class is defined.
     */
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(SegmentsManager::class));
    }

    /**
     * Test that the segmentsManager instance is of the correct class.
     */
    public function testInstanceIsCorrect(): void
    {
        $this->assertInstanceOf(SegmentsManager::class, self::$segmentsManager);
    }

    /**
     * Test that a new SegmentsManager instance is successfully created.
     */
    public function testCreateNewSegmentsManagerInstance(): void
    {
        $this->assertInstanceOf(SegmentsManager::class, self::$segmentsManager);
    }

    /**
     * Test that segments are successfully updated in the DataStore.
     */
    public function testUpdateSegmentsInDataStore(): void
    {
        $segments = ['country' => 'US'];
        self::$segmentsManager->putSegments($this->visitorId, $segments);
        $localSegments = self::$dataManager->getData($this->visitorId);
        $this->assertEquals($segments['country'], $localSegments["segments"]["country"] ?? null);
    }

    public function testUpdateCustomSegments(): void
    {
        $segments = ['country' => 'US'];
        self::$segmentsManager->putSegments($this->visitorId, $segments);

        $segmentKey = 'test-segments-1';
        $segmentId = '200299434';
        $updatedSegments = self::$segmentsManager->selectCustomSegments(
            $this->visitorId,
            [$segmentKey],
            ['enabled' => true]
        );
        $this->assertInstanceOf(VisitorSegments::class, $updatedSegments);
        $this->assertEquals([$segmentId], $updatedSegments->getCustomSegments());
    }

    public function testKeepCustomSegmentsIntactIfAlreadySet(): void
    {
        $segmentKey = 'test-segments-1';
        // First call to set the segment
        self::$segmentsManager->selectCustomSegments(
            $this->visitorId,
            [$segmentKey],
            ['enabled' => true]
        );
        // Second call should return null since segment is already set
        $updatedSegments = self::$segmentsManager->selectCustomSegments(
            $this->visitorId,
            [$segmentKey],
            ['enabled' => true]
        );
        $this->assertNull($updatedSegments);
    }

    /**
     * Test that custom segments remain intact if the segment key is not found.
     */
    public function testKeepCustomSegmentsIntactIfKeyNotFound(): void
    {
        $segmentKey = 'test-segments-2';
        $updatedSegments = self::$segmentsManager->selectCustomSegments(
            $this->visitorId,
            [$segmentKey]
        );
        $this->assertNull($updatedSegments);
    }
}