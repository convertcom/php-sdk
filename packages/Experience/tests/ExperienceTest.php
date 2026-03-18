<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\BucketingAttributes;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\BucketingManager;
use ConvertSdk\RuleManager;
use ConvertSdk\EventManager;
use ConvertSdk\ApiManager;
use ConvertSdk\LogManager;
use ConvertSdk\DataManager;
use ConvertSdk\ExperienceManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Test class for ExperienceManager.
 */
class ExperienceManagerTest extends TestCase
{
    /** @var DataManager */
    private $dataManager;

    /** @var ExperienceManager */
    private $experienceManager;

    /** @var string */
    private $accountId;

    /** @var string */
    private $projectId;

    /** @var string Visitor ID for testing */
    private $visitorId = 'XXX';

    /** @var int Release timeout in milliseconds */
    private $releaseTimeout = 1000;

    /** @var int Test timeout in milliseconds */
    private $testTimeout = 1100; // release_timeout + 100

    /** @var int Batch size for events */
    private $batchSize = 5;

    /**
     * Set up the test environment before each test.
     */
    protected function setUp(): void
    {
        // Load test configuration from JSON file
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();

        // Merge configurations
        $configuration = array_replace_recursive($defaultConfig, $testConfig, [
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
        $configuration['data'] = new ConfigResponseData($configuration['data']);
        if (isset($configuration['sdkKey'])) {
            unset($configuration['sdkKey']);
          }
        // Create Config object
        $config = new Config($configuration);

        // Extract account and project IDs
        $this->accountId = $configuration['data']['account_id'];
        $this->projectId = $configuration['data']['project']['id'];

        // Instantiate managers with dependencies
        $bucketingManager = new BucketingManager($config);
        $ruleManager = new RuleManager($config);
        $eventManager = new EventManager($config);
        $apiManager = new ApiManager($config, $eventManager);
        $loggerManager = new LogManager($config);
        $this->dataManager = new DataManager(
            $config,
            $bucketingManager,
            $ruleManager,
            $eventManager,
            $apiManager,
            $loggerManager
        );

        $this->experienceManager = new ExperienceManager($config, ['dataManager' => $this->dataManager]);
    }

    /**
     * Test that the ExperienceManager class is defined.
     */
    public function testExperienceManagerIsDefined(): void
    {
        $this->assertTrue(class_exists(ExperienceManager::class));
    }

    /**
     * Test that the ExperienceManager instance is correctly constructed.
     */
    public function testExperienceManagerConstructor(): void
    {
        $this->assertInstanceOf(ExperienceManager::class, $this->experienceManager);
    }

    /**
     * Test getting the list of all experiences.
     */
    public function testGetList(): void
    {
        $entities = $this->experienceManager->getList();
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);

        $this->assertIsArray($entities);
        $this->assertCount(3, $entities);
        $this->assertEquals($testConfig['data']['experiences'], $entities);
    }

    /**
     * Test getting an experience by key.
     */
    public function testGetExperience(): void
    {
        $experienceKey = 'test-experience-ab-fullstack-2';
        $experienceId = '100218245';
        $entity = $this->experienceManager->getExperience($experienceKey);

        $this->assertIsObject($entity);
        $this->assertEquals($experienceId, $entity['id']);
    }

    /**
     * Test getting an experience by ID.
     */
    public function testGetExperienceById(): void
    {
        $experienceKey = 'test-experience-ab-fullstack-2';
        $experienceId = '100218245';
        $entity = $this->experienceManager->getExperienceById($experienceId);

        $this->assertIsObject($entity);
        $this->assertEquals($experienceKey, $entity['key']);
    }

    /**
     * Test getting multiple experiences by an array of keys.
     */
    public function testGetExperiences(): void
    {
        $experienceKeys = [
            'test-experience-ab-fullstack-2',
            'test-experience-ab-fullstack-3',
            'test-experience-ab-fullstack-4',
        ];
        $entities = $this->experienceManager->getExperiences($experienceKeys);
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);

        $this->assertIsArray($entities);
        $this->assertCount(3, $entities);
        $this->assertEquals($testConfig['data']['experiences'], $entities);
    }

    /**
     * Test selecting a variation for a specific visitor by experience key.
     */
    public function testSelectVariation(): void
    {
        $experienceKey = 'test-experience-ab-fullstack-2';
        $variation = $this->experienceManager->selectVariation(
            $this->visitorId,
            $experienceKey,
            new BucketingAttributes([
                'visitorProperties' => ['varName3' => 'something'],
                'locationProperties' => ['url' => 'https://convert.com/'],
            ])
        );
        $this->assertIsArray($variation);
        $this->assertEquals($experienceKey, $variation['experienceKey']);
    }

    /**
     * Test selecting a variation for a specific visitor by experience ID.
     */
    public function testSelectVariationById(): void
    {
        $experienceId = '100218245';
        $variation = $this->experienceManager->selectVariationById(
            $this->visitorId,
            $experienceId,
            new BucketingAttributes([
                'visitorProperties' => ['varName3' => 'something'],
                'locationProperties' => ['url' => 'https://convert.com/'],
            ])
        );

        $this->assertIsArray($variation);
        $this->assertEquals($experienceId, $variation['experienceId']);
    }

    /**
     * Test selecting all variations across all experiences for a specific visitor.
     */
    public function testSelectVariations(): void
    {
        $variationIds = ['100299456', '100299457', '100299460', '100299461'];
        $variations = $this->experienceManager->selectVariations(
            $this->visitorId,
            new BucketingAttributes([
                'visitorProperties' => ['varName3' => 'something'],
                'locationProperties' => ['url' => 'https://convert.com/'],
            ])
        );

        $this->assertIsArray($variations);
        $this->assertCount(2, $variations);
        $selectedVariationIds = array_column($variations, 'id');
        foreach ($selectedVariationIds as $id) {
            $this->assertContains($id, $variationIds);
        }
    }

    /**
     * Test getting a variation by experience key and variation key.
     */
    public function testGetVariation(): void
    {
        $experienceKey = 'test-experience-ab-fullstack-2';
        $variationKey = '100299457-variation-1';
        $variationId = '100299457';
        $variation = $this->experienceManager->getVariation($experienceKey, $variationKey);

        $this->assertIsObject($variation);
        $this->assertEquals($variationId, $variation['id']);
    }

    /**
     * Test getting a variation by experience ID and variation ID.
     */
    public function testGetVariationById(): void
    {
        $experienceId = '100218245';
        $variationKey = '100299457-variation-1';
        $variationId = '100299457';
        $variation = $this->experienceManager->getVariationById($experienceId, $variationId);

        $this->assertIsObject($variation);
        $this->assertEquals($variationKey, $variation['key']);
    }
}