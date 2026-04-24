<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\BucketingManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Context;
use ConvertSdk\DataManager;
use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Event\EventManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\TestCase;

class ContextConversionTest extends TestCase
{
    private Config $config;
    private Context $context;
    private EventManager $eventManager;
    private DataManager $dataManager;
    private string $visitorId = 'ctx-conv-visitor';

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
        $loggerManager = new LogManager();
        $bucketingConfig = $this->config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $this->eventManager = new EventManager();

        // Mock ApiManager to avoid PHP 8.4 end() deprecation
        $this->apiManagerMock = $this->createMock(ApiManagerInterface::class);

        $this->dataManager = new DataManager(
            $this->config,
            $bucketingManager,
            $ruleManager,
            $this->eventManager,
            $this->apiManagerMock,
            $loggerManager
        );
        $experienceManager = new ExperienceManager(dataManager: $this->dataManager);
        $featureManager = new FeatureManager(dataManager: $this->dataManager);
        $segmentsManager = new SegmentsManager($this->config, $this->dataManager, $ruleManager);

        $this->context = new Context(
            $this->config,
            $this->visitorId,
            $this->eventManager,
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

    public function testTrackConversionWithDto(): void
    {
        $this->apiManagerMock->expects($this->atLeastOnce())
            ->method('enqueue');

        $result = $this->context->trackConversion('increase-engagement', new ConversionAttributes(
            ruleData: ['action' => 'buy'],
            conversionData: [
                ['key' => 'amount', 'value' => 10.3],
                ['key' => 'productsCount', 'value' => 2],
            ]
        ));

        $this->assertNull($result);
    }

    public function testTrackConversionWithNullAttributes(): void
    {
        $this->apiManagerMock->expects($this->once())
            ->method('enqueue');

        $result = $this->context->trackConversion('goal-without-rule');
        $this->assertNull($result);
    }

    public function testTrackConversionNonExistentGoalReturnsFalse(): void
    {
        $this->apiManagerMock->expects($this->never())
            ->method('enqueue');

        $result = $this->context->trackConversion('nonexistent-goal');
        $this->assertFalse($result, 'Non-existent goal should return false, not null');
    }

    public function testTrackConversionFiresSystemEvent(): void
    {
        $eventFired = false;
        $this->eventManager->on(SystemEvents::Conversion, function ($data) use (&$eventFired) {
            $eventFired = true;
            $this->assertEquals('ctx-conv-visitor', $data['visitorId']);
            $this->assertEquals('goal-without-rule', $data['goalKey']);
        });

        $this->context->trackConversion('goal-without-rule');
        $this->assertTrue($eventFired, 'SystemEvents::Conversion should fire on successful tracking');
    }

    public function testTrackConversionWithConversionSetting(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [['key' => 'amount', 'value' => 50.0]];

        // First call: conversion + transaction = 2
        // Second call with force: transaction = 1
        // Total = 3
        $this->apiManagerMock->expects($this->exactly(3))
            ->method('enqueue');

        $this->context->trackConversion($goalKey, new ConversionAttributes(
            conversionData: $goalData,
            conversionSetting: ['forceMultipleTransactions' => true],
        ));

        // Second call — forceMultipleTransactions should allow transaction
        $this->context->trackConversion($goalKey, new ConversionAttributes(
            conversionData: $goalData,
            conversionSetting: ['forceMultipleTransactions' => true],
        ));
    }

    public function testTrackConversionFiresSystemEventWithRuleBasedGoal(): void
    {
        $eventFired = false;
        $this->eventManager->on(SystemEvents::Conversion, function ($data) use (&$eventFired) {
            $eventFired = true;
            $this->assertEquals('ctx-conv-visitor', $data['visitorId']);
            $this->assertEquals('increase-engagement', $data['goalKey']);
        });

        $result = $this->context->trackConversion('increase-engagement', new ConversionAttributes(
            ruleData: ['action' => 'buy'],
        ));
        $this->assertNull($result);
        $this->assertTrue($eventFired, 'SystemEvents::Conversion should fire for rule-based goal on success');
    }

    public function testTrackConversionDoesNotFireEventOnFailure(): void
    {
        $eventFired = false;
        $this->eventManager->on(SystemEvents::Conversion, function () use (&$eventFired) {
            $eventFired = true;
        });

        $result = $this->context->trackConversion('nonexistent-goal');
        $this->assertFalse($result);
        $this->assertFalse($eventFired, 'SystemEvents::Conversion should NOT fire when goal not found');
    }

    public function testTrackConversionDeduplication(): void
    {
        // Only one enqueue call expected — second is deduplicated
        $this->apiManagerMock->expects($this->once())
            ->method('enqueue');

        $this->context->trackConversion('goal-without-rule');
        $this->context->trackConversion('goal-without-rule');
    }
}
