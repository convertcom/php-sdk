<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Integration;

use ConvertSdk\ConvertSDK;
use ConvertSdk\Core;
use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\DTO\GoalData;
use ConvertSdk\Enums\ConversionSettingKey;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Enums\GoalDataKey;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Exception\InvalidArgumentException;
use Http\Discovery\ClassDiscovery;
use Http\Discovery\Strategy\MockClientStrategy;
use OpenAPI\Client\BucketingAttributes;
use PHPUnit\Framework\TestCase;

class FullChainIntegrationTest extends TestCase
{
    private Core $sdk;
    private BucketingAttributes $qualifyingAttributes;
    private array $configData;
    private string $environment;

    /** @var string[] */
    private static array $originalStrategies;

    public static function setUpBeforeClass(): void
    {
        /** @var string[] $strategies */
        $strategies = iterator_to_array(ClassDiscovery::getStrategies());
        self::$originalStrategies = $strategies;
        ClassDiscovery::prependStrategy(MockClientStrategy::class);
    }

    public static function tearDownAfterClass(): void
    {
        ClassDiscovery::setStrategies(self::$originalStrategies);
    }

    private function sdkCreateOptions(array $overrides = []): array
    {
        return array_merge([
            'data' => $this->configData,
            'environment' => $this->environment,
            'network' => ['tracking' => false],
        ], $overrides);
    }

    private function createTrackingEnabledSdk(): Core
    {
        return ConvertSDK::create($this->sdkCreateOptions([
            'network' => ['tracking' => true],
        ]));
    }

    protected function setUp(): void
    {
        $json = file_get_contents(__DIR__ . '/../../packages/Php-sdk/tests/test-config.json');
        $config = json_decode($json, true);
        $this->configData = $config['data'];
        $this->environment = $config['environment'];

        $this->sdk = ConvertSDK::create($this->sdkCreateOptions());

        $this->qualifyingAttributes = new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something'],
            'typeCasting' => true,
        ]);
    }

    // ── Happy Path ──────────────────────────────────────────────────

    public function testSdkInitializesAndIsReady(): void
    {
        $this->assertInstanceOf(Core::class, $this->sdk);
        $this->assertTrue($this->sdk->isReady());
    }

    public function testReadyEventFiredOnInit(): void
    {
        $events = [];

        $sdk = ConvertSDK::create($this->sdkCreateOptions());

        // Deferred event: listener attached after create() still receives the Ready event
        $sdk->on('ready', function ($args, $err) use (&$events) {
            $events[] = ['args' => $args, 'err' => $err];
        });

        $this->assertCount(1, $events, 'Ready event should fire exactly once');
        $this->assertNull($events[0]['err'], 'Ready event should have no error');
    }

    public function testCreateContextAndRunExperience(): void
    {
        $context = $this->sdk->createContext('visitor-integration-test', null);
        $this->assertNotNull($context);

        $result = $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);

        $this->assertInstanceOf(BucketedVariation::class, $result);
        $this->assertSame('test-experience-ab-fullstack-2', $result->experienceKey);
        $this->assertNotEmpty($result->variationId, 'variationId should be non-empty');
        $this->assertNotEmpty($result->variationKey, 'variationKey should be non-empty');
        $this->assertNotEmpty($result->changes);
    }

    public function testBucketingDeterminism(): void
    {
        $context = $this->sdk->createContext('determinism-visitor', null);
        $results = [];

        for ($i = 0; $i < 10; $i++) {
            $variation = $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);
            $this->assertNotNull($variation);
            $results[] = $variation->variationId;
        }

        $unique = array_unique($results);
        $this->assertCount(1, $unique, 'Same visitor must always get the same variation');
    }

    public function testBucketingEventFiredOnExperience(): void
    {
        $bucketingEvents = [];

        $this->sdk->on('bucketing', function ($args, $err) use (&$bucketingEvents) {
            $bucketingEvents[] = ['args' => $args, 'err' => $err];
        });

        $context = $this->sdk->createContext('event-spy-visitor', null);
        $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);

        $this->assertNotEmpty($bucketingEvents, 'Bucketing event should fire when running an experience');
    }

    public function testRunFeatureWithTypedVariables(): void
    {
        $context = $this->sdk->createContext('feature-typed-visitor', null);
        $result = $context->runFeature('feature-2', $this->qualifyingAttributes);

        $this->assertInstanceOf(BucketedFeature::class, $result);
        $this->assertSame(FeatureStatus::Enabled, $result->status);
        $this->assertSame('feature-2', $result->featureKey);

        // Verify typed variables
        $this->assertIsFloat($result->variables['price']);
        $this->assertIsInt($result->variables['button-height']);

        // JSON variable should be decoded to array/object
        $additionalData = $result->variables['additionalData'];
        $this->assertIsArray($additionalData);
        $this->assertSame('bar', $additionalData['foo']);
        $this->assertSame(2, $additionalData['v']);
    }

    public function testFullChainInitContextBucketFeatureVerify(): void
    {
        // Init
        $sdk = ConvertSDK::create($this->sdkCreateOptions());
        $this->assertTrue($sdk->isReady());

        // Attach event spies
        $readyEvents = [];
        $bucketingEvents = [];

        $sdk->on('ready', function ($args, $err) use (&$readyEvents) {
            $readyEvents[] = ['args' => $args, 'err' => $err];
        });
        $sdk->on('bucketing', function ($args, $err) use (&$bucketingEvents) {
            $bucketingEvents[] = ['args' => $args, 'err' => $err];
        });

        // Context
        $context = $sdk->createContext('full-chain-visitor');
        $this->assertNotNull($context);

        // Bucket
        $variation = $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);
        $this->assertInstanceOf(BucketedVariation::class, $variation);

        // Feature
        $feature = $context->runFeature('feature-1', $this->qualifyingAttributes);
        $this->assertInstanceOf(BucketedFeature::class, $feature);
        $this->assertSame(FeatureStatus::Enabled, $feature->status);

        // Verify events
        $this->assertCount(1, $readyEvents, 'Ready event should fire exactly once');
        $this->assertNotEmpty($bucketingEvents, 'Bucketing events should fire for experience and feature runs');

        // Verify determinism: re-run same experience, expect same variationId
        $secondRun = $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);
        $this->assertNotNull($secondRun);
        $this->assertSame($variation->variationId, $secondRun->variationId);
    }

    // ── Negative Paths ──────────────────────────────────────────────

    public function testCreateWithoutSdkKeyOrDataThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConvertSDK::create([]);
    }

    public function testRunFeatureWithUnknownKeyReturnsNull(): void
    {
        $context = $this->sdk->createContext('null-feature-visitor', null);
        $result = $context->runFeature('completely-nonexistent-feature-key', $this->qualifyingAttributes);
        $this->assertNull($result);
    }

    public function testRunExperienceWithNonQualifyingVisitorReturnsNull(): void
    {
        $context = $this->sdk->createContext('non-qualifying-visitor', null);

        $nonQualifyingAttributes = new BucketingAttributes([
            'locationProperties' => ['url' => 'https://wrong-url.com/'],
            'visitorProperties' => [],
        ]);

        $result = $context->runExperience('test-experience-ab-fullstack-2', $nonQualifyingAttributes);
        $this->assertNull($result);
    }

    // ── Conversion Tracking ─────────────────────────────────────────

    public function testTrackConversionForGoalWithoutRules(): void
    {
        $sdk = $this->createTrackingEnabledSdk();

        $queueReleasedEvents = [];
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-basic');
        $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);

        $result = $context->trackConversion('goal-without-rule');

        $this->assertNull($result, 'trackConversion should return null on success');
        $this->assertNotEmpty($queueReleasedEvents, 'ApiQueueReleased should fire (tracking POST sent)');
    }

    public function testConversionEventFiredOnTrackConversion(): void
    {
        $sdk = $this->createTrackingEnabledSdk();

        $conversionEvents = [];
        $sdk->on(SystemEvents::Conversion->value, function ($args) use (&$conversionEvents) {
            $conversionEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-event');
        $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);
        $context->trackConversion('goal-without-rule');

        $this->assertCount(1, $conversionEvents, 'Conversion event should fire exactly once');
        $this->assertSame('tracking-visitor-event', $conversionEvents[0]['visitorId']);
        $this->assertSame('goal-without-rule', $conversionEvents[0]['goalKey']);
    }

    public function testGoalDeduplication(): void
    {
        $sdk = $this->createTrackingEnabledSdk();

        $queueReleasedEvents = [];
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-dedup');
        $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);

        // First call — should enqueue and release
        $context->trackConversion('goal-without-rule');
        $countAfterFirst = count($queueReleasedEvents);
        $this->assertGreaterThan(0, $countAfterFirst, 'First conversion should trigger API queue release');

        // Second call — deduplicated, no new enqueue or release
        $secondResult = $context->trackConversion('goal-without-rule');
        $this->assertNull($secondResult, 'Deduplicated conversion should still return null (same as first call)');
        $this->assertCount($countAfterFirst, $queueReleasedEvents, 'Second conversion should be deduplicated (no new API queue release)');
    }

    public function testTrackConversionWithRevenue(): void
    {
        $sdk = $this->createTrackingEnabledSdk();

        $queueReleasedEvents = [];
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-revenue');
        $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);

        $result = $context->trackConversion('goal-without-rule', new ConversionAttributes(
            conversionData: [
                new GoalData(GoalDataKey::Amount, 49.99),
                new GoalData(GoalDataKey::TransactionId, 'txn-integration-001'),
            ],
        ));

        $this->assertNull($result, 'Revenue conversion should return null on success');
        // Revenue tracking sends conversion + transaction events, each triggers a release
        $this->assertGreaterThanOrEqual(2, count($queueReleasedEvents), 'Revenue conversion should trigger at least 2 API releases (conversion + transaction)');

        // Verify released payload contains visitor data
        $lastEvent = end($queueReleasedEvents);
        $this->assertArrayHasKey('visitors', $lastEvent);
    }

    public function testForceMultipleTransactions(): void
    {
        $sdk = $this->createTrackingEnabledSdk();

        $queueReleasedEvents = [];
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-force');
        $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);

        // First call with goalData: conversion + transaction = 2 enqueues
        $context->trackConversion('goal-without-rule', new ConversionAttributes(
            conversionData: [new GoalData(GoalDataKey::Amount, 25.00)],
        ));
        $countAfterFirst = count($queueReleasedEvents);
        $this->assertGreaterThanOrEqual(2, $countAfterFirst, 'First revenue conversion should trigger at least 2 releases');

        // Second call with forceMultipleTransactions: transaction event should still be sent
        $context->trackConversion('goal-without-rule', new ConversionAttributes(
            conversionData: [new GoalData(GoalDataKey::Amount, 25.00)],
            conversionSetting: [ConversionSettingKey::ForceMultipleTransactions->value => true],
        ));
        $this->assertGreaterThan($countAfterFirst, count($queueReleasedEvents), 'forceMultipleTransactions should allow repeat transaction');

        // Verify the forced release carried a transaction (visitor data with goalData)
        $lastRelease = $queueReleasedEvents[array_key_last($queueReleasedEvents)];
        $this->assertArrayHasKey('visitors', $lastRelease);
        $lastVisitor = $lastRelease['visitors'][0] ?? null;
        $this->assertNotNull($lastVisitor, 'Released payload should contain visitor data');
        $this->assertArrayHasKey('events', $lastVisitor);
        $lastEvent = end($lastVisitor['events']);
        $this->assertArrayHasKey('goalData', $lastEvent['data'] ?? [], 'Forced repeat release should contain a transaction event with goalData');
    }

    public function testTrackConversionWithNonexistentGoalReturnsFalse(): void
    {
        $conversionEvents = [];
        $this->sdk->on(SystemEvents::Conversion->value, function ($args) use (&$conversionEvents) {
            $conversionEvents[] = $args;
        });

        $context = $this->sdk->createContext('tracking-visitor-fake-goal');
        $result = $context->trackConversion('totally-fake-goal');

        $this->assertFalse($result, 'Non-existent goal should return false');
        $this->assertEmpty($conversionEvents, 'No conversion event should fire for non-existent goal');
    }

    // ── Complete Chain ──────────────────────────────────────────────

    public function testCompleteChainInitThroughFlush(): void
    {
        // Init with tracking enabled
        $sdk = $this->createTrackingEnabledSdk();
        $this->assertTrue($sdk->isReady());

        // Attach event spies
        $readyEvents = [];
        $bucketingEvents = [];
        $conversionEvents = [];
        $queueReleasedEvents = [];

        $sdk->on('ready', function ($args, $err) use (&$readyEvents) {
            $readyEvents[] = ['args' => $args, 'err' => $err];
        });
        $sdk->on('bucketing', function ($args, $err) use (&$bucketingEvents) {
            $bucketingEvents[] = ['args' => $args, 'err' => $err];
        });
        $sdk->on(SystemEvents::Conversion->value, function ($args) use (&$conversionEvents) {
            $conversionEvents[] = $args;
        });
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        // Context
        $context = $sdk->createContext('complete-chain-visitor');
        $this->assertNotNull($context);

        // Bucket — runExperience
        $variation = $context->runExperience('test-experience-ab-fullstack-2', $this->qualifyingAttributes);
        $this->assertInstanceOf(BucketedVariation::class, $variation);

        // Feature — runFeature
        $feature = $context->runFeature('feature-1', $this->qualifyingAttributes);
        $this->assertInstanceOf(BucketedFeature::class, $feature);
        $this->assertSame(FeatureStatus::Enabled, $feature->status);

        // Track — conversion
        $result = $context->trackConversion('goal-without-rule');
        $this->assertNull($result, 'trackConversion should return null on success');

        // Flush — release any remaining queue items (may be no-op if auto-released on enqueue)
        $releasedBeforeFlush = count($queueReleasedEvents);
        $sdk->flush();
        // flush() is called for completeness; with tracking=true, enqueue auto-releases,
        // so flush may find an empty queue — either way the chain is exercised without error.

        // Verify all event types fired
        $this->assertCount(1, $readyEvents, 'Ready event should fire exactly once');
        $this->assertNotEmpty($bucketingEvents, 'Bucketing events should fire for experience and feature runs');
        $this->assertCount(1, $conversionEvents, 'Conversion event should fire once');
        $this->assertSame('complete-chain-visitor', $conversionEvents[0]['visitorId']);
        $this->assertNotEmpty($queueReleasedEvents, 'API queue should be released (tracking POST sent)');
    }
}
