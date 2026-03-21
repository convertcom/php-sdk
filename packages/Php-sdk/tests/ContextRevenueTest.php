<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\BucketingManager;
use ConvertSdk\RuleManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\DataManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\LogManager;
use ConvertSdk\Context;
use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\DTO\GoalData;
use ConvertSdk\Enums\GoalDataKey;
use ConvertSdk\Interfaces\ApiManagerInterface;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Utils\ObjectUtils;

class ContextRevenueTest extends TestCase
{
    private Context $context;
    private DataManager $dataManager;
    private string $visitorId = 'ctx-revenue-visitor';

    /** @var ApiManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private ApiManagerInterface $apiManagerMock;

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $configuration = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, [
            'api' => [
                'endpoint' => [
                    'config' => 'http://127.0.0.1:9501',
                    'track' => 'http://127.0.0.1:9501'
                ]
            ],
            'events' => [
                'batch_size' => 5,
                'release_interval' => 1000
            ]
        ]);
        $configuration['data'] = new ConfigResponseData($configuration['data']);
        if (isset($configuration['sdkKey'])) {
            unset($configuration['sdkKey']);
        }

        $config = new Config($configuration);
        $loggerManager = new LogManager();
        $bucketingConfig = $config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $eventManager = new EventManager();

        $this->apiManagerMock = $this->createMock(ApiManagerInterface::class);

        $this->dataManager = new DataManager(
            $config,
            $bucketingManager,
            $ruleManager,
            $eventManager,
            $this->apiManagerMock,
            $loggerManager
        );
        $experienceManager = new ExperienceManager(dataManager: $this->dataManager);
        $featureManager = new FeatureManager(dataManager: $this->dataManager);
        $segmentsManager = new SegmentsManager($config, $this->dataManager, $ruleManager);

        $this->context = new Context(
            $config,
            $this->visitorId,
            $eventManager,
            $experienceManager,
            $featureManager,
            $this->dataManager,
            $segmentsManager,
            $this->apiManagerMock,
        );
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
    }

    /**
     * Test: trackConversion with DTO GoalData sends TWO events (conversion + transaction)
     * This tests the full consumer API flow: DTO GoalData -> Context mapping -> DataManager
     */
    public function testTrackConversionWithDtoGoalDataSendsTwoEvents(): void
    {
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue');

        $result = $this->context->trackConversion('goal-without-rule', new ConversionAttributes(
            conversionData: [
                new GoalData(GoalDataKey::Amount, 99.99),
                new GoalData(GoalDataKey::TransactionId, 'txn-abc'),
            ]
        ));

        $this->assertNull($result);
    }

    /**
     * Test: Transaction event from DTO GoalData contains correct key-value pairs
     */
    public function testDtoGoalDataSerializesCorrectlyInPayload(): void
    {
        $capturedEvents = [];
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvents) {
                $capturedEvents[] = $event;
            });

        $this->context->trackConversion('goal-without-rule', new ConversionAttributes(
            conversionData: [
                new GoalData(GoalDataKey::Amount, 99.99),
                new GoalData(GoalDataKey::TransactionId, 'txn-abc-123'),
                new GoalData(GoalDataKey::ProductsCount, 3),
            ]
        ));

        $this->assertCount(2, $capturedEvents);

        // Transaction event (second) should have goalData
        $transactionData = $capturedEvents[1]->getData();
        $this->assertArrayHasKey('goalData', $transactionData);

        $goalData = $transactionData['goalData'];
        $this->assertCount(3, $goalData);
        $this->assertEquals(['key' => 'amount', 'value' => 99.99], $goalData[0]);
        $this->assertEquals(['key' => 'transactionId', 'value' => 'txn-abc-123'], $goalData[1]);
        $this->assertEquals(['key' => 'productsCount', 'value' => 3], $goalData[2]);
    }

    /**
     * Test: ConversionAttributes with only conversionData (no ruleData, no conversionSetting)
     */
    public function testConversionAttributesWithOnlyConversionData(): void
    {
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue');

        $attrs = new ConversionAttributes(
            conversionData: [new GoalData(GoalDataKey::Amount, 50.0)]
        );

        $this->assertNull($attrs->ruleData);
        $this->assertNull($attrs->conversionSetting);
        $this->assertNotNull($attrs->conversionData);

        $result = $this->context->trackConversion('goal-without-rule', $attrs);
        $this->assertNull($result);
    }

    /**
     * Test: ConversionAttributes with all three fields populated
     */
    public function testConversionAttributesWithAllFieldsPopulated(): void
    {
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue');

        $result = $this->context->trackConversion('increase-engagement', new ConversionAttributes(
            ruleData: ['action' => 'buy'],
            conversionData: [
                new GoalData(GoalDataKey::Amount, 149.99),
                new GoalData(GoalDataKey::CustomDimension1, 'premium-plan'),
            ],
            conversionSetting: ['forceMultipleTransactions' => true],
        ));

        $this->assertNull($result);
    }

    /**
     * Test: trackConversion with ruleData mismatch and goalData -> no events
     */
    public function testRuleMismatchWithGoalDataSendsNothing(): void
    {
        $this->apiManagerMock->expects($this->never())
            ->method('enqueue');

        $result = $this->context->trackConversion('increase-engagement', new ConversionAttributes(
            ruleData: ['action' => 'sell'],
            conversionData: [new GoalData(GoalDataKey::Amount, 25.0)],
        ));

        $this->assertFalse($result);
    }

    /**
     * Test: DTO GoalData with all 5 CustomDimension keys via Context
     */
    public function testAllCustomDimensionsViaContext(): void
    {
        $capturedEvent = null;
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue')
            ->willReturnCallback(function ($visitorId, VisitorTrackingEvents $event) use (&$capturedEvent) {
                $data = $event->getData();
                if (isset($data['goalData'])) {
                    $capturedEvent = $event;
                }
            });

        $this->context->trackConversion('goal-without-rule', new ConversionAttributes(
            conversionData: [
                new GoalData(GoalDataKey::CustomDimension1, 'val1'),
                new GoalData(GoalDataKey::CustomDimension2, 'val2'),
                new GoalData(GoalDataKey::CustomDimension3, 'val3'),
                new GoalData(GoalDataKey::CustomDimension4, 'val4'),
                new GoalData(GoalDataKey::CustomDimension5, 'val5'),
            ]
        ));

        $this->assertNotNull($capturedEvent);
        $goalData = $capturedEvent->getData()['goalData'];
        $this->assertCount(5, $goalData);
        $this->assertEquals('customDimension1', $goalData[0]['key']);
        $this->assertEquals('customDimension2', $goalData[1]['key']);
        $this->assertEquals('customDimension3', $goalData[2]['key']);
        $this->assertEquals('customDimension4', $goalData[3]['key']);
        $this->assertEquals('customDimension5', $goalData[4]['key']);
    }

    /**
     * Test: Backward compatibility — plain array conversionData still works
     */
    public function testPlainArrayConversionDataStillWorks(): void
    {
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue');

        $result = $this->context->trackConversion('goal-without-rule', new ConversionAttributes(
            conversionData: [
                ['key' => 'amount', 'value' => 10.3],
                ['key' => 'productsCount', 'value' => 2],
            ]
        ));

        $this->assertNull($result);
    }

    /**
     * Test: Repeat trigger with forceMultipleTransactions + DTO GoalData -> transaction only
     */
    public function testRepeatTriggerWithForceAndDtoGoalData(): void
    {
        // First call: conversion + transaction = 2
        // Second call with force: transaction = 1
        // Total = 3
        $this->apiManagerMock->expects($this->exactly(3))
            ->method('enqueue');

        $attrs = new ConversionAttributes(
            conversionData: [new GoalData(GoalDataKey::Amount, 50.0)],
            conversionSetting: ['forceMultipleTransactions' => true],
        );

        $this->context->trackConversion('goal-without-rule', $attrs);
        $this->context->trackConversion('goal-without-rule', $attrs);
    }
}
