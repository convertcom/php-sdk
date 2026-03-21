<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\DataManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\RuleManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\LogManager;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Enums\GoalDataKey;
use ConvertSdk\Interfaces\ApiManagerInterface;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use ConvertSdk\Config\DefaultConfig;
use PHPUnit\Framework\TestCase;

class RevenueReportingTest extends TestCase
{
    private Config $config;
    private DataManager $dataManager;
    private string $visitorId = 'revenue-test-visitor';

    /** @var ApiManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private ApiManagerInterface $apiManagerMock;

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $overrides = [
            'api' => [
                'endpoint' => [
                    'config' => 'http://localhost:8090',
                    'track' => 'http://localhost:8090'
                ]
            ],
            'events' => [
                'batch_size' => 10,
                'release_interval' => 1000
            ]
        ];
        $mergedConfig = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, $overrides);
        $mergedConfig['data'] = new ConfigResponseData($mergedConfig['data']);
        if (isset($mergedConfig['sdkKey'])) {
            unset($mergedConfig['sdkKey']);
        }
        $this->config = new Config($mergedConfig);

        $bucketingConfig = $this->config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $eventManager = new EventManager();
        $loggerManager = new LogManager();

        $this->apiManagerMock = $this->createMock(ApiManagerInterface::class);

        $this->dataManager = new DataManager(
            $this->config,
            $bucketingManager,
            $ruleManager,
            $eventManager,
            $this->apiManagerMock,
            $loggerManager,
            true
        );
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
    }

    /**
     * Test 7.1: trackConversion with GoalData sends TWO events (conversion + transaction)
     */
    public function testConversionWithGoalDataSendsTwoEvents(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [
            ['key' => GoalDataKey::Amount->value, 'value' => 99.99],
            ['key' => GoalDataKey::TransactionId->value, 'value' => 'txn-abc'],
        ];

        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue');

        $result = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            null,
            $goalData
        );
        $this->assertTrue($result);
    }

    /**
     * Test 7.2: Transaction event payload contains goalData array with correct key-value pairs
     */
    public function testTransactionEventContainsGoalData(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [
            ['key' => GoalDataKey::Amount->value, 'value' => 99.99],
            ['key' => GoalDataKey::TransactionId->value, 'value' => 'txn-abc-123'],
        ];

        $capturedEvents = [];
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvents) {
                $capturedEvents[] = $event;
            });

        $this->dataManager->convert($this->visitorId, $goalKey, null, $goalData);

        // First event is conversion (no goalData), second is transaction (with goalData)
        $this->assertCount(2, $capturedEvents);

        $conversionData = $capturedEvents[0]->getData();
        $this->assertArrayNotHasKey('goalData', $conversionData);

        $transactionData = $capturedEvents[1]->getData();
        $this->assertArrayHasKey('goalData', $transactionData);
        $this->assertEquals($goalData, $transactionData['goalData']);
    }

    /**
     * Test 7.3: Transaction event payload contains bucketingData
     */
    public function testTransactionEventContainsBucketingData(): void
    {
        $goalKey = 'goal-without-rule';
        $bucketingData = ['exp1' => 'var1'];
        $goalData = [['key' => GoalDataKey::Amount->value, 'value' => 50.0]];

        $this->dataManager->putData($this->visitorId, ['bucketing' => $bucketingData]);

        $capturedEvents = [];
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvents) {
                $capturedEvents[] = $event;
            });

        $this->dataManager->convert($this->visitorId, $goalKey, null, $goalData);

        // Transaction event (second) should have bucketingData
        $transactionData = $capturedEvents[1]->getData();
        $this->assertArrayHasKey('bucketingData', $transactionData);
        $this->assertEquals($bucketingData, $transactionData['bucketingData']);
    }

    /**
     * Test 7.5: GoalData with Amount (float), TransactionId (string), ProductsCount (int) all serialize correctly
     */
    public function testGoalDataTypesSerializeCorrectly(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [
            ['key' => GoalDataKey::Amount->value, 'value' => 99.99],
            ['key' => GoalDataKey::TransactionId->value, 'value' => 'txn-abc'],
            ['key' => GoalDataKey::ProductsCount->value, 'value' => 3],
        ];

        $capturedEvent = null;
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvent) {
                $data = $event->getData();
                if (isset($data['goalData'])) {
                    $capturedEvent = $event;
                }
            });

        $this->dataManager->convert($this->visitorId, $goalKey, null, $goalData);

        $this->assertNotNull($capturedEvent);
        $transactionData = $capturedEvent->getData();
        $actualGoalData = $transactionData['goalData'];

        $this->assertCount(3, $actualGoalData);
        $this->assertEquals('amount', $actualGoalData[0]['key']);
        $this->assertIsFloat($actualGoalData[0]['value']);
        $this->assertEquals(99.99, $actualGoalData[0]['value']);

        $this->assertEquals('transactionId', $actualGoalData[1]['key']);
        $this->assertIsString($actualGoalData[1]['value']);
        $this->assertEquals('txn-abc', $actualGoalData[1]['value']);

        $this->assertEquals('productsCount', $actualGoalData[2]['key']);
        $this->assertIsInt($actualGoalData[2]['value']);
        $this->assertEquals(3, $actualGoalData[2]['value']);
    }

    /**
     * Test 7.6: GoalData with all 5 CustomDimension keys serialize correctly
     */
    public function testCustomDimensionKeysSerializeCorrectly(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [
            ['key' => GoalDataKey::CustomDimension1->value, 'value' => 'dim1-val'],
            ['key' => GoalDataKey::CustomDimension2->value, 'value' => 'dim2-val'],
            ['key' => GoalDataKey::CustomDimension3->value, 'value' => 'dim3-val'],
            ['key' => GoalDataKey::CustomDimension4->value, 'value' => 'dim4-val'],
            ['key' => GoalDataKey::CustomDimension5->value, 'value' => 'dim5-val'],
        ];

        $capturedEvent = null;
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvent) {
                $data = $event->getData();
                if (isset($data['goalData'])) {
                    $capturedEvent = $event;
                }
            });

        $this->dataManager->convert($this->visitorId, $goalKey, null, $goalData);

        $this->assertNotNull($capturedEvent);
        $transactionData = $capturedEvent->getData();
        $actualGoalData = $transactionData['goalData'];

        $this->assertCount(5, $actualGoalData);
        $this->assertEquals('customDimension1', $actualGoalData[0]['key']);
        $this->assertEquals('customDimension2', $actualGoalData[1]['key']);
        $this->assertEquals('customDimension3', $actualGoalData[2]['key']);
        $this->assertEquals('customDimension4', $actualGoalData[3]['key']);
        $this->assertEquals('customDimension5', $actualGoalData[4]['key']);
    }

    /**
     * Test 7.7: trackConversion with ruleData — rules match -> events sent
     */
    public function testConversionWithRuleMatchSendsEvents(): void
    {
        $goalKey = 'increase-engagement';
        $goalData = [['key' => GoalDataKey::Amount->value, 'value' => 25.0]];

        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue');

        $result = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            ['action' => 'buy'],
            $goalData
        );
        $this->assertTrue($result);
    }

    /**
     * Test 7.8: trackConversion with ruleData — rules don't match -> no events, returns false
     */
    public function testConversionWithRuleMismatchReturnsFalse(): void
    {
        $goalKey = 'increase-engagement';
        $goalData = [['key' => GoalDataKey::Amount->value, 'value' => 25.0]];

        $this->apiManagerMock->expects($this->never())
            ->method('enqueue');

        $result = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            ['action' => 'sell'],
            $goalData
        );
        $this->assertFalse($result);
    }

    /**
     * Test 7.9: Conversion without goalData sends only conversion event (no transaction)
     */
    public function testConversionWithoutGoalDataSendsOnlyConversionEvent(): void
    {
        $goalKey = 'goal-without-rule';

        $capturedEvent = null;
        $this->apiManagerMock->expects($this->once())
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvent) {
                $capturedEvent = $event;
            });

        $result = $this->dataManager->convert($this->visitorId, $goalKey);
        $this->assertTrue($result);
        $this->assertNotNull($capturedEvent);

        $data = $capturedEvent->getData();
        $this->assertArrayNotHasKey('goalData', $data);
    }

    /**
     * Test: All 8 GoalDataKey values together in a single transaction event
     */
    public function testAll8GoalDataKeysInSingleTransaction(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [
            ['key' => GoalDataKey::Amount->value, 'value' => 149.99],
            ['key' => GoalDataKey::ProductsCount->value, 'value' => 5],
            ['key' => GoalDataKey::TransactionId->value, 'value' => 'txn-all-keys'],
            ['key' => GoalDataKey::CustomDimension1->value, 'value' => 'premium'],
            ['key' => GoalDataKey::CustomDimension2->value, 'value' => 'annual'],
            ['key' => GoalDataKey::CustomDimension3->value, 'value' => 'usd'],
            ['key' => GoalDataKey::CustomDimension4->value, 'value' => 'web'],
            ['key' => GoalDataKey::CustomDimension5->value, 'value' => 'checkout-v2'],
        ];

        $capturedEvent = null;
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvent) {
                $data = $event->getData();
                if (isset($data['goalData'])) {
                    $capturedEvent = $event;
                }
            });

        $this->dataManager->convert($this->visitorId, $goalKey, null, $goalData);

        $this->assertNotNull($capturedEvent);
        $transactionData = $capturedEvent->getData();
        $this->assertCount(8, $transactionData['goalData']);

        // Verify all keys are present
        $keys = array_map(fn($item) => $item['key'], $transactionData['goalData']);
        foreach (GoalDataKey::cases() as $case) {
            $this->assertContains($case->value, $keys, "GoalDataKey::{$case->name} should be in payload");
        }
    }

    /**
     * Test 7.11: Repeat trigger with forceMultipleTransactions + goalData -> transaction only
     */
    public function testRepeatTriggerWithForceOnlySendsTransaction(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [['key' => GoalDataKey::Amount->value, 'value' => 30.0]];
        $conversionSetting = ['forceMultipleTransactions' => true];

        $capturedEvents = [];
        $this->apiManagerMock->expects($this->exactly(3))
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvents) {
                $capturedEvents[] = $event;
            });

        // First call: conversion + transaction = 2 events
        $this->dataManager->convert($this->visitorId, $goalKey, null, $goalData, null, $conversionSetting);
        $this->assertCount(2, $capturedEvents);

        // Second call with force: transaction only = 1 event
        $this->dataManager->convert($this->visitorId, $goalKey, null, $goalData, null, $conversionSetting);
        $this->assertCount(3, $capturedEvents);

        // Third event (second call) should be transaction (has goalData)
        $thirdEventData = $capturedEvents[2]->getData();
        $this->assertArrayHasKey('goalData', $thirdEventData);
    }
}
