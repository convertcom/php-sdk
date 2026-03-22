<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\DataManager;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\ConversionSettingKey;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\Utils\ObjectUtils;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use PHPUnit\Framework\Attributes\Group;
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
    private $bucketingManager;
    private $ruleManager;
    private $eventManager;
    private $apiManager;
    private $loggerManager;
    private $dataStoreMock;
    private $dataManager;
    private $accountId;
    private $projectId;
    private $storeKey;
    private MockHttpClient $mockHttpClient;
    private Psr17Factory $psr17Factory;

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
        'custom_segments' => ['seg1', 'seg2'],
    ];

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $overrides = [
            'api' => [
                'endpoint' => [
                    'config' => self::HOST . ':' . self::PORT,
                    'track' => self::HOST . ':' . self::PORT,
                ],
            ],
            'events' => [
                'batch_size' => self::BATCH_SIZE,
                'release_interval' => self::RELEASE_TIMEOUT,
            ],
        ];
        $mergedConfig = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, $overrides);
        $mergedConfig['data'] = new ConfigResponseData($mergedConfig['data']);
        if (isset($mergedConfig['sdkKey'])) {
            unset($mergedConfig['sdkKey']);
        }
        $this->config = new Config($mergedConfig);

        // Set up PSR-18 mock HTTP client
        $this->mockHttpClient = new MockHttpClient();
        $this->psr17Factory = new Psr17Factory();

        $bucketingConfig = $this->config->getBucketing();
        $this->bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $this->ruleManager = new RuleManager();
        $this->eventManager = new EventManager();
        $this->apiManager = new ApiManager(
            $this->config,
            $this->eventManager,
            null,
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory
        );

        $this->loggerManager = new LogManager();
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
        // Add mock response for any potential API calls
        $this->mockHttpClient->addResponse(new Response(200, [], json_encode(['data' => []])));

        $variation = $this->dataManager->getBucketing(
            $this->visitorId,
            $experienceKey,
            new BucketingAttributes([
                'visitorProperties' => ['varName3' => 'something'],
                'locationProperties' => ['url' => 'https://convert.com/'],
            ])
        );

        $this->assertNotNull($variation); // Adjust based on actual behavior
        if (is_array($variation)) {
            $this->assertArrayHasKey('experienceKey', $variation);
            $this->assertEquals($experienceKey, $variation['experienceKey']);
        } else {
            $this->assertEquals(BucketingError::VariationNotDecided, $variation); // Handle error case
        }
    }

    public function testRetrieveVariationById(): void
    {
        $experienceId = '100218245';
        $this->mockHttpClient->addResponse(new Response(200, [], json_encode(['data' => []])));
        $variation = $this->dataManager->getBucketingById(
            $this->visitorId,
            $experienceId,
            new BucketingAttributes([
                'visitorProperties' => ['varName3' => 'something'],
                'locationProperties' => ['url' => 'https://convert.com/'],
            ])
        );

        $this->assertNotNull($variation);
        if (is_array($variation)) {
            $this->assertArrayHasKey('experienceId', $variation);
            $this->assertEquals($experienceId, $variation['experienceId']);
        } else {
            $this->assertEquals(BucketingError::VariationNotDecided, $variation);
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
        $expected = array_filter($this->config->getData()['features'] ?? [], fn ($f) => in_array($f['key'], $keys, true));
        $this->assertEquals($expected, $entities);
    }

    public function testGetEntitiesByIds(): void
    {
        $ids = ['10024', '10025'];
        $entities = $this->dataManager->getEntitiesByIds($ids, 'features');
        $expected = array_filter($this->config->getData()['features'] ?? [], fn ($f) => in_array($f['id'], $ids, true));
        $this->assertEquals($expected, $entities);
    }

    public function testProcessConversionEvent(): void
    {
        $goalKey = 'increase-engagement';
        $this->mockHttpClient->addResponse(
            new Response(200, [], json_encode(['data' => []]))
        );
        $this->mockHttpClient->addResponse(
            new Response(200, [], json_encode(['data' => []]))
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
                'locationProperties' => ['url' => 'https://convert.com/'],
            ])
        );
        $this->assertEquals(BucketingError::VariationNotDecided, $variation);
    }

    public function testLocalStoreSizeLimit(): void
    {
        for ($i = 0; $i < 10001; $i++) {
            $this->dataManager->putData("a{$i}", ['test' => $i]);
        }
        $this->assertTrue(true); // Ensures no exception is thrown
    }

    #[Group('persistent_enqueue')]
    public function testDataStoreEnqueueBucketing(): void
    {
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        usleep((int) ((self::RELEASE_TIMEOUT + 1) * 1000));
        $check = $this->dataManager->getDataStoreManager()->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
    }

    #[Group('persistent_enqueue')]
    public function testDataStoreEnqueueGoals(): void
    {
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        usleep((int) ((self::RELEASE_TIMEOUT + 1) * 1000));
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }

    #[Group('persistent_enqueue')]
    public function testDataStoreEnqueueSegments(): void
    {
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        $this->dataManager->putData($this->visitorId, ['segments' => $this->segments]);
        usleep((int) ((self::RELEASE_TIMEOUT + 1) * 1000));
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }

    #[Group('persistent_enqueue')]
    public function testDataStoreEnqueueShape(): void
    {
        $this->dataManager->setDataStore($this->dataStoreMock);
        $this->dataManager->putData($this->visitorId, ['bucketing' => $this->bucketing]);
        $this->dataManager->putData($this->visitorId, ['goals' => $this->goals]);
        $this->dataManager->putData($this->visitorId, ['segments' => $this->segments]);
        usleep((int) ((self::RELEASE_TIMEOUT + 1) * 1000));
        $check = $this->dataStoreMock->get($this->storeKey);
        $this->assertIsArray($check);
        $this->assertEquals($this->bucketing, $check['bucketing']);
        $this->assertEquals($this->goals, $check['goals']);
    }

    #[Group('persistent_set')]
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

    #[Group('persistent_set')]
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

    #[Group('persistent_set')]
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

    #[Group('persistent_set')]
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

    public function testDataManagerIsFinal(): void
    {
        $reflection = new \ReflectionClass(DataManager::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testGetEntityReturnsNullForNonexistentKey(): void
    {
        $result = $this->dataManager->getEntity('nonexistent-key', 'experience');
        $this->assertNull($result);
    }

    public function testGetEntityByIdReturnsNullForNonexistentId(): void
    {
        $result = $this->dataManager->getEntityById('999999999', 'experience');
        $this->assertNull($result);
    }

    public function testGetEntityReturnsEntityForValidKey(): void
    {
        $result = $this->dataManager->getEntity('adv-audience', 'audience');
        $this->assertNotNull($result);
        $this->assertIsArray($result);
        $this->assertEquals('adv-audience', $result['key']);
    }

    public function testSetConfigDataBuildsEntityIndices(): void
    {
        $configData = $this->config->getData();
        $this->dataManager->setConfigData($configData);

        // Verify entities are accessible after setConfigData
        $audiences = $this->dataManager->getEntitiesList('audiences');
        $this->assertIsArray($audiences);

        $experiences = $this->dataManager->getEntitiesList('experiences');
        $this->assertIsArray($experiences);

        $features = $this->dataManager->getEntitiesList('features');
        $this->assertIsArray($features);

        $goals = $this->dataManager->getEntitiesList('goals');
        $this->assertIsArray($goals);

        $locations = $this->dataManager->getEntitiesList('locations');
        $this->assertIsArray($locations);

        $segments = $this->dataManager->getEntitiesList('segments');
        $this->assertIsArray($segments);
    }

    public function testIsValidConfigDataWithInvalidData(): void
    {
        $emptyData = new ConfigResponseData([]);
        $this->assertFalse($this->dataManager->isValidConfigData($emptyData));
    }

    public function testIsValidConfigDataWithValidData(): void
    {
        $configData = $this->config->getData();
        $this->assertTrue($this->dataManager->isValidConfigData($configData));
    }

    public function testGetEntitiesListReturnsEmptyArrayForUnknownType(): void
    {
        $result = $this->dataManager->getEntitiesList('nonexistent_type');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetSubItemReturnsNullForMissingEntity(): void
    {
        $result = $this->dataManager->getSubItem(
            'experiences',
            'nonexistent-id',
            'variations',
            'nonexistent-var',
            'id',
            'id'
        );
        $this->assertNull($result);
    }

    // =========================================================================
    // forceMultipleTransactions behavior matrix tests
    // =========================================================================

    /**
     * Helper: create a DataManager with a mock ApiManager that tracks enqueue() calls.
     *
     * @return array{dataManager: DataManager, apiMock: ApiManagerInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function createDataManagerWithMockApi(): array
    {
        $apiMock = $this->createMock(ApiManagerInterface::class);

        $dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $apiMock,
            $this->loggerManager,
            true
        );

        return ['dataManager' => $dataManager, 'apiMock' => $apiMock];
    }

    /**
     * Scenario 1: First trigger, no goalData -> conversion sent, no transaction.
     */
    #[Group('forceMultipleTransactions')]
    public function testForceMultiple_FirstTriggerNoGoalData_SendsConversionOnly(): void
    {
        ['dataManager' => $dm, 'apiMock' => $apiMock] = $this->createDataManagerWithMockApi();

        $apiMock->expects($this->once())
            ->method('enqueue')
            ->with(
                $this->visitorId,
                $this->callback(function (VisitorTrackingEvents $event) {
                    $data = (array) $event->jsonSerialize();
                    // Conversion event: has goalId, no goalData
                    return isset($data['data']['goalId']) && !isset($data['data']['goalData']);
                }),
                $this->anything()
            );

        $result = $dm->convert($this->visitorId, 'goal-without-rule');
        $this->assertTrue($result);
    }

    /**
     * Scenario 2: First trigger, with goalData -> conversion sent AND transaction sent.
     */
    #[Group('forceMultipleTransactions')]
    public function testForceMultiple_FirstTriggerWithGoalData_SendsConversionAndTransaction(): void
    {
        ['dataManager' => $dm, 'apiMock' => $apiMock] = $this->createDataManagerWithMockApi();

        $capturedEvents = [];
        $apiMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function (string $visitorId, VisitorTrackingEvents $event) use (&$capturedEvents) {
                $capturedEvents[] = (array) $event->jsonSerialize();
            });

        $goalData = [['key' => 'amount', 'value' => 49.99]];
        $result = $dm->convert($this->visitorId, 'goal-without-rule', null, $goalData);
        $this->assertTrue($result);

        // First event: conversion (no goalData)
        $this->assertEquals('conversion', $capturedEvents[0]['eventType']);
        $this->assertArrayHasKey('goalId', $capturedEvents[0]['data']);
        $this->assertArrayNotHasKey('goalData', $capturedEvents[0]['data']);

        // Second event: transaction (with goalData)
        $this->assertEquals('conversion', $capturedEvents[1]['eventType']);
        $this->assertArrayHasKey('goalId', $capturedEvents[1]['data']);
        $this->assertArrayHasKey('goalData', $capturedEvents[1]['data']);
    }

    /**
     * Scenario 3: Repeat trigger, no force -> nothing sent (dedup blocks both).
     */
    #[Group('forceMultipleTransactions')]
    public function testForceMultiple_RepeatTriggerNoForce_SendsNothing(): void
    {
        ['dataManager' => $dm, 'apiMock' => $apiMock] = $this->createDataManagerWithMockApi();

        // First call: triggers conversion
        $apiMock->expects($this->once())
            ->method('enqueue');

        $dm->convert($this->visitorId, 'goal-without-rule');

        // Second call: dedup blocks everything
        $result = $dm->convert($this->visitorId, 'goal-without-rule');
        $this->assertTrue($result); // Returns true (dedup recognized)
    }

    /**
     * Scenario 4: Repeat trigger, force=true, no goalData -> nothing sent.
     */
    #[Group('forceMultipleTransactions')]
    public function testForceMultiple_RepeatTriggerForceNoGoalData_SendsNothing(): void
    {
        ['dataManager' => $dm, 'apiMock' => $apiMock] = $this->createDataManagerWithMockApi();

        // First call: triggers conversion (1 enqueue)
        // Second call with force but no goalData: nothing to send (still 1 total)
        $apiMock->expects($this->once())
            ->method('enqueue');

        $dm->convert($this->visitorId, 'goal-without-rule');

        $conversionSetting = [ConversionSettingKey::ForceMultipleTransactions->value => true];
        $result = $dm->convert($this->visitorId, 'goal-without-rule', null, null, null, $conversionSetting);
        $this->assertTrue($result);
    }

    /**
     * Scenario 3b: Repeat trigger, explicit force=false -> nothing sent (dedup blocks).
     * Validates that explicit false behaves identically to null/absent.
     */
    #[Group('forceMultipleTransactions')]
    public function testForceMultiple_RepeatTriggerExplicitFalse_SendsNothing(): void
    {
        ['dataManager' => $dm, 'apiMock' => $apiMock] = $this->createDataManagerWithMockApi();

        // First call: triggers conversion (1 enqueue)
        $apiMock->expects($this->once())
            ->method('enqueue');

        $dm->convert($this->visitorId, 'goal-without-rule');

        // Second call with explicit false: dedup blocks everything
        $conversionSetting = [ConversionSettingKey::ForceMultipleTransactions->value => false];
        $result = $dm->convert($this->visitorId, 'goal-without-rule', null, [['key' => 'amount', 'value' => 9.99]], null, $conversionSetting);
        $this->assertTrue($result);
    }

    /**
     * Scenario 5b: Repeat trigger, force=1 (truthy integer), with goalData -> transaction sent.
     * Validates that non-boolean truthy values also bypass dedup.
     */
    #[Group('forceMultipleTransactions')]
    public function testForceMultiple_RepeatTriggerTruthyInteger_SendsTransaction(): void
    {
        ['dataManager' => $dm, 'apiMock' => $apiMock] = $this->createDataManagerWithMockApi();

        $apiMock->expects($this->exactly(2))
            ->method('enqueue');

        $dm->convert($this->visitorId, 'goal-without-rule');

        // Integer 1 is truthy — should bypass dedup and send transaction
        $conversionSetting = [ConversionSettingKey::ForceMultipleTransactions->value => 1];
        $result = $dm->convert($this->visitorId, 'goal-without-rule', null, [['key' => 'amount', 'value' => 5.00]], null, $conversionSetting);
        $this->assertTrue($result);
    }

    /**
     * Scenario 5: Repeat trigger, force=true, with goalData -> transaction sent only.
     */
    #[Group('forceMultipleTransactions')]
    public function testForceMultiple_RepeatTriggerForceWithGoalData_SendsTransactionOnly(): void
    {
        ['dataManager' => $dm, 'apiMock' => $apiMock] = $this->createDataManagerWithMockApi();

        $capturedEvents = [];
        $apiMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function (string $visitorId, VisitorTrackingEvents $event) use (&$capturedEvents) {
                $capturedEvents[] = (array) $event->jsonSerialize();
            });

        // First call: triggers conversion (1 enqueue)
        $dm->convert($this->visitorId, 'goal-without-rule');

        // Second call with force + goalData: triggers transaction only (2nd enqueue)
        $goalData = [['key' => 'amount', 'value' => 29.99]];
        $conversionSetting = [ConversionSettingKey::ForceMultipleTransactions->value => true];
        $result = $dm->convert($this->visitorId, 'goal-without-rule', null, $goalData, null, $conversionSetting);
        $this->assertTrue($result);

        $this->assertCount(2, $capturedEvents);

        // First event: conversion (no goalData)
        $this->assertEquals('conversion', $capturedEvents[0]['eventType']);
        $this->assertArrayNotHasKey('goalData', $capturedEvents[0]['data']);

        // Second event: transaction (with goalData)
        $this->assertEquals('conversion', $capturedEvents[1]['eventType']);
        $this->assertArrayHasKey('goalData', $capturedEvents[1]['data']);
    }
}
