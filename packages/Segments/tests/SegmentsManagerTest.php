<?php

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
    private $configuration;

    /** @var BucketingManager */
    private $bucketingManager;

    /** @var RuleManager */
    private $ruleManager;

    /** @var EventManager */
    private $eventManager;

    /** @var ApiManager */
    private $apiManager;

    /** @var DataManager */
    private $dataManager;

    /** @var SegmentsManager */
    private $segmentsManager;

    /** @var string Visitor ID for testing */
    private $visitorId = 'XXX';
    private $batchSize = 5;
    private $releaseTimeout = 1000;


    /**
     * Set up dependencies before each test.
     */
    protected function setUp(): void
    {
        // Load configuration from a test-config.php file
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $this->configuration = ObjectUtils::ObjectDeepMerge($defaultConfig, $testConfig, [
          'api' => [
              'endpoint' => [
                  'config' => 'http://localhost:8090',
                  'track' => 'http://localhost:8090',
              ],
          ],
          'events' => [
              'batch_size' => $this->batchSize,
              'release_interval' => $this->releaseTimeout,
          ],
      ]);
      $this->configuration['data'] = new ConfigResponseData($this->configuration['data']);
          if (isset($this->configuration['sdkKey'])) {
              unset($this->configuration['sdkKey']);
            }
        // Create Config object
        $config = new Config($this->configuration);
        // Initialize all manager instances with dependencies
        $this->bucketingManager = new BucketingManager($config);
        $this->ruleManager = new RuleManager($config);
        $this->eventManager = new EventManager($config);
        $this->apiManager = new ApiManager($config, $this->eventManager);
        $this->loggerManager = new LogManager($config);
        $this->dataManager = new DataManager($config,
          $this->bucketingManager,
          $this->ruleManager,
          $this->eventManager,
          $this->apiManager,
          $this->loggerManager
        );
        $this->segmentsManager = new SegmentsManager($config,
        $this->dataManager,
        $this->ruleManager
        );
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
        $this->assertInstanceOf(SegmentsManager::class, $this->segmentsManager);
    }

    /**
     * Test that a new SegmentsManager instance is successfully created.
     */
    public function testCreateNewSegmentsManagerInstance(): void
    {
        $this->assertInstanceOf(SegmentsManager::class, $this->segmentsManager);
    }

    /**
     * Test that segments are successfully updated in the DataStore.
     */
    public function testUpdateSegmentsInDataStore(): void
    {
        $segments = ['country' => 'US'];
        $this->segmentsManager->putSegments($this->visitorId, $segments);
        $localSegments = $this->dataManager->getData($this->visitorId);
        $this->assertEquals($segments['country'], $localSegments->getSegments()['country'] ?? null);
    }

    /**
     * Test that custom segments are successfully updated for a specific visitor.
     */
    public function testUpdateCustomSegments(): void
    {
        $segmentKey = 'test-segments-1';
        $segmentId = '200299434';
        $updatedSegments = $this->segmentsManager->selectCustomSegments(
            $this->visitorId,
            [$segmentKey],
            ['enabled' => true]
        );
        $this->assertInstanceOf(VisitorSegments::class, $updatedSegments);
        $this->assertEquals([$segmentId], $updatedSegments->getCustomSegments());
    }

    /**
     * Test that custom segments remain intact if already set for a visitor.
     */
    public function testKeepCustomSegmentsIntactIfAlreadySet(): void
    {
        $segmentKey = 'test-segments-1';
        // First call to set the segment
        $this->segmentsManager->selectCustomSegments(
            $this->visitorId,
            [$segmentKey],
            ['enabled' => true]
        );
        // Second call should return null if no update occurs
        $updatedSegments = $this->segmentsManager->selectCustomSegments(
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
        $updatedSegments = $this->segmentsManager->selectCustomSegments(
            $this->visitorId,
            [$segmentKey]
        );
        $this->assertNull($updatedSegments);
    }
}