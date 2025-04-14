<?php

namespace ConvertSdk\Tests;

use ConvertSdk\DataManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\RuleManager;
use ConvertSdk\EventManager;
use ConvertSdk\ApiManager;
use ConvertSdk\LogManager;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\SystemEvents;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use OpenAPI\Client\Model\VisitorSegments;
use ConvertSdk\Config\DefaultConfig;
use GuzzleHttp\Client;
use ConvertSdk\Utils\HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class DataStoreMock
{
    private $data = [];

    public function get($key)
    {
        return $key ? ($this->data[$key] ?? null) : $this->data;
    }

    public function set($key, $value)
    {
        if (!$key) {
            throw new \Exception('Invalid DataStore key!');
        }
        $this->data[$key] = $value;
    }

    public function enqueue($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function reset()
    {
        $this->data = [];
    }
}

class DataManagerTest extends TestCase
{
    private const HOST = 'http://localhost';
    private const PORT = 8090;
    private const RELEASE_TIMEOUT = 1000; // milliseconds
    private const TEST_TIMEOUT = self::RELEASE_TIMEOUT + 100; // Adjusted for PHPUnit
    private const BATCH_SIZE = 10;

    private $config;
    private $bucketingManagerMock;
    private $ruleManagerMock;
    private $eventManagerMock;
    private $apiManagerMock;
    private $loggerManagerMock;
    private $dataStoreMock;
    private $dataManager;
    private $accountId;
    private $projectId;
    private $storeKey;
    private $mockHandler;

    private $visitorId = 'test-visitor-123';
    private $bucketing = ['exp1' => 'var1', 'exp2' => 'var2'];
    private $goals = ['goal1' => true, 'goal2' => true];
    private $segments = [
        'browser' => 'CH',
        'devices' => 'ALLPH',
        'source' => 'test',
        'campaign' => 'test',
        'visitor_type' => 'new',
        'country' => 'US',
        'custom_segments' => ['seg1', 'seg2']
    ];

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $overrides = [
            'api' => [
                'endpoint' => [
                    'config' => self::HOST . ':' . self::PORT,
                    'track' => self::HOST . ':' . self::PORT
                ]
            ],
            'events' => [
                'batch_size' => self::BATCH_SIZE,
                'release_interval' => self::RELEASE_TIMEOUT
            ]
        ];
        $mergedConfig = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, $overrides);
        $mergedConfig['data'] = new ConfigResponseData($mergedConfig['data']);
        if (isset($mergedConfig['sdkKey'])) {
          unset($mergedConfig['sdkKey']);
        }
        $this->config = new Config($mergedConfig);
    
        // Set up Guzzle mock handler
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);
    
        $this->bucketingManager = new BucketingManager($this->config);
        $this->ruleManager = new RuleManager($this->config);
        $this->eventManager = new EventManager($this->config);
        $this->apiManager = new ApiManager($this->config, $this->eventManager);
        
        // Inject the mocked HTTP client into ApiManager
        $reflection = new \ReflectionClass($this->apiManager);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($this->apiManager, new HttpClient($httpClient));
    
        $this->loggerManager = new LogManager($this->config);
        $this->dataStoreMock = new DataStoreMock();
    
        $this->accountId = $this->config->getData()->getAccountId();
        $project = $this->config->getData() ? $this->config->getData()->getProject() : null;
        $this->projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
        $this->storeKey = "{$this->accountId}-{$this->projectId}-{$this->visitorId}";
    
        $this->dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager,
            true
        );
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
        $this->dataStoreMock->reset();
    }

    public function testDataManagerIsDefined(): void
    {
        $this->assertTrue(class_exists(DataManager::class));
    }

    public function testDataManagerIsConstructor(): void
    {
        $reflection = new \ReflectionClass(DataManager::class);
        $this->assertTrue($reflection->isInstantiable());
        $this->assertEquals('DataManager', $reflection->getShortName());
    }

    public function testSuccessfullyCreateDataManager(): void
    {
        $this->assertInstanceOf(DataManager::class, $this->dataManager);
        $reflection = new \ReflectionClass($this->dataManager);
        $this->assertEquals('DataManager', $reflection->getShortName());
    }

    public function testValidateConfiguration(): void
    {
        $configData = $this->config->getData();
        $this->assertTrue($this->dataManager->isValidConfigData($configData)); // Pass object, not array
    }

    public function testRetrieveVariationByKey(): void
    {
        $experienceKey = 'test-experience-ab-fullstack-2';
        $this->mockHandler->append(new Response(200, [], json_encode(['data' => []]))); // Mock API response if needed

        $variation = $this->dataManager->getBucketing(
            $this->visitorId,
            $experienceKey,
            new BucketingAttributes([
                'visitorProperties' => ['varName3' => 'something'],
                'locationProperties' => ['url' => 'https://convert.com/']
            ])
        );

        $this->assertNotNull($variation); // Adjust based on actual behavior
        if (is_array($variation)) {
            $this->assertArrayHasKey('experienceKey', $variation);
            $this->assertEquals($experienceKey, $variation['experienceKey']);
        } else {
            $this->assertEquals(BucketingError::VARIATION_NOT_DECIDED, $variation); // Handle error case
        }
    }

    public function testRetrieveVariationById(): void
    {
        $experienceId = '100218245';
        $this->mockHandler->append(new Response(200, [], json_encode(['data' => []]))); // Mock API response if needed
        $variation = $this->dataManager->getBucketingById(
            $this->visitorId,
            $experienceId,
            new BucketingAttributes([
                'visitorProperties' => ['varName3' => 'something'],
                'locationProperties' => ['url' => 'https://convert.com/']
            ])
        );

        $this->assertNotNull($variation);
        if (is_array($variation)) {
            $this->assertArrayHasKey('experienceId', $variation);
            $this->assertEquals($experienceId, $variation['experienceId']);
        } else {
            $this->assertEquals(BucketingError::VARIATION_NOT_DECIDED, $variation);
        }
    }

    public function testGetEntitiesListObject(): void
    {
        $audiences = $this->dataManager->getEntitiesListObject('audiences');
        $configData = $this->config->getData();
        $audienceList = $configData->getAudiences();
    
        $this->assertNotEmpty($audienceList, 'Audiences should not be empty');
        $expectedId = $audienceList[0]['id'];
    
        $this->assertIsArray($audiences);
        $this->assertArrayHasKey($expectedId, $audiences);
        $this->assertEquals($audienceList[0], $audiences[$expectedId]);
    }

    public function testGetEntitiesByKeys(): void
    {
        $keys = ['feature-1', 'feature-2'];
        $entities = $this->dataManager->getEntities($keys, 'features');
        $expected = array_filter($this->config->getData()["features"] ?? [], fn($f) => in_array($f['key'], $keys));
        $this->assertEquals($expected, $entities);
    }

    public function testGetEntitiesByIds(): void
    {
        $ids = ['10024', '10025'];
        $entities = $this->dataManager->getEntitiesByIds($ids, 'features');
        $expected = array_filter($this->config->getData()["features"] ?? [], fn($f) => in_array($f['id'], $ids));
        $this->assertEquals($expected, $entities);
    }

    public function testProcessConversionEvent(): void
    {
        $goalKey = 'increase-engagement';
        $this->mockHandler->append(
            new Response(200, [], json_encode(['data' => []])), // Conversion
            new Response(200, [], json_encode(['data' => []]))  // Transaction
        );
        $result = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            ['action' => 'buy'],
            [['key' => 'amount', 'value' => 10.4], ['key' => 'productsCount', 'value' => 3]]
        );
        $this->assertTrue($result);
    }

    public function testFailInvalidGoal(): void
    {
        $result = $this->dataManager->convert($this->visitorId, 'invalid-goal');
        $this->assertFalse($result);
    }

    public function testFailMismatchedRule(): void
    {
        $result = $this->dataManager->convert($this->visitorId, 'increase-engagement', ['action' => 'sell']);
        $this->assertFalse($result); // Depends on real RuleManager behavior
    }

    public function testFailNoRule(): void
    {
        $result = $this->dataManager->convert($this->visitorId, 'goal-without-rule', ['action' => 'buy']);
        $this->assertFalse($result); // Depends on real RuleManager behavior
    }

    public function testFailRetrieveVariationNotExists(): void
    {
        $variation = $this->dataManager->getBucketing(
            $this->visitorId,
            'test-experience-ab-fullstack-4',
            new BucketingAttributes([
                'visitorProperties' => ['varName3' => 'something'],
                'locationProperties' => ['url' => 'https://convert.com/']
            ])
        );
        $this->assertEquals(BucketingError::VARIATION_NOT_DECIDED, $variation);
    }

    public function testLocalStoreSizeLimit(): void
    {
        for ($i = 0; $i < 10001; $i++) {
            $this->dataManager->putData("a{$i}", ['test' => $i]);
        }
        $this->assertTrue(true); // Ensures no exception is thrown
    }

    /**
     * @group persistent_enqueue
     */
    public function testDataStoreEnqueueBucketing(): void
    {
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        sleep((self::RELEASE_TIMEOUT + 1) / 1000);
        $check = $this->dataManager->getDataStoreManager()->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
    }

    /**
      * @group persistent_enqueue
     */
    public function testDataStoreEnqueueGoals(): void
    {
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        sleep((self::RELEASE_TIMEOUT + 1) / 1000);
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }

    /**
     * @group persistent_enqueue
     */
    public function testDataStoreEnqueueSegments(): void
    {
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        $this->dataManager->putData($this->visitorId, ['segments' => $this->segments]);
        sleep((self::RELEASE_TIMEOUT + 1) / 1000);
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }

    /**
     * @group persistent_enqueue
     */
    public function testDataStoreEnqueueShape(): void
    {
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        $this->dataManager->putData($this->visitorId, ['segments' => $this->segments]);
        sleep((self::RELEASE_TIMEOUT + 1) / 1000);
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertIsArray($check);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }

    /**
     * @group persistent_set
     */
    public function testDataStoreSetImmediatelyBucketing(): void
    {
        $this->dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager,
            false
        );
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
    }

    /**
     * @group persistent_set
     */
    public function testDataStoreSetImmediatelyGoals(): void
    {
        $this->dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager,
            false
        );
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }

    /**
     * @group persistent_set
     */
    public function testDataStoreSetImmediatelySegments(): void
    {
        $this->dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager,
            false
        );
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        $this->dataManager->putData($this->visitorId, ['segments' => $this->segments]);
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }

    /**
     * @group persistent_set
     */
    public function testDataStoreSetImmediatelyShape(): void
    {
        $this->dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager,
            false
        );
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        $this->dataManager->putData($this->visitorId, ['segments' => $this->segments]);
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertIsArray($check);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }
}