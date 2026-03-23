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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FullChainIntegrationTest extends TestCase
{
    private const EXPERIENCE_KEY = 'test-experience-ab-fullstack-4';
    private const FEATURE_TYPED_KEY = 'feature-2';
    private const FEATURE_BASIC_KEY = 'feature-1';
    private const GOAL_KEY = 'increase-engagement';

    private BucketingAttributes $qualifyingAttributes;
    private array $configData;
    private string $environment;

    /** @var string[] */
    private static array $originalStrategies;

    /**
     * MockClientStrategy toggling uses ClassDiscovery global static state.
     * Not safe for parallel test runners (e.g., paratest).
     */
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

    public static function authModes(): array
    {
        return [
            'static' => ['static'],
            'live' => ['live'],
            'live-secret' => ['live-secret'],
        ];
    }

    private function skipIfLiveDisabled(string $mode): void
    {
        if ($mode === 'live' && !getenv('CONVERT_STAGING_SDK_KEY')) {
            $this->markTestSkipped('Live tests require CONVERT_STAGING_SDK_KEY env var');
        }
        if ($mode === 'live-secret' && (!getenv('CONVERT_STAGING_SDK_KEY2') || !getenv('CONVERT_STAGING_SDK_KEY2_SECRET'))) {
            $this->markTestSkipped('Live-secret tests require CONVERT_STAGING_SDK_KEY2 and CONVERT_STAGING_SDK_KEY2_SECRET env vars');
        }
    }

    private function createSdk(string $mode, array $overrides = []): Core
    {
        if ($mode === 'live' || $mode === 'live-secret') {
            // Restore real HTTP strategies for live CDN fetch
            ClassDiscovery::setStrategies(self::$originalStrategies);
            try {
                $config = [
                    'environment' => 'staging',
                    'network' => ['tracking' => false, 'cacheLevel' => 'low'],
                ];
                if ($mode === 'live-secret') {
                    $config['sdkKey'] = getenv('CONVERT_STAGING_SDK_KEY2');
                    $config['sdkKeySecret'] = getenv('CONVERT_STAGING_SDK_KEY2_SECRET');
                } else {
                    $config['sdkKey'] = getenv('CONVERT_STAGING_SDK_KEY');
                }
                return ConvertSDK::create(array_merge($config, $overrides));
            } finally {
                // Re-add mock strategy for subsequent static tests
                ClassDiscovery::prependStrategy(MockClientStrategy::class);
            }
        }
        // Static mode
        return ConvertSDK::create(array_merge([
            'data' => $this->configData,
            'environment' => $this->environment,
            'network' => ['tracking' => false],
        ], $overrides));
    }

    private function createTrackingEnabledSdk(string $mode): Core
    {
        return $this->createSdk($mode, [
            'network' => ($mode === 'live' || $mode === 'live-secret')
                ? ['tracking' => true, 'cacheLevel' => 'low']
                : ['tracking' => true],
        ]);
    }

    protected function setUp(): void
    {
        $json = file_get_contents(__DIR__ . '/static-config.json');
        $this->configData = json_decode($json, true);
        $this->environment = 'staging';

        // Experience -4 uses pricing-location (rule: location=pricing), no audiences
        $this->qualifyingAttributes = new BucketingAttributes([
            'locationProperties' => ['location' => 'pricing'],
            'typeCasting' => true,
        ]);
    }

    // -- Happy Path --------------------------------------------------------

    #[DataProvider('authModes')]
    public function testSdkInitializesAndIsReady(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $this->assertInstanceOf(Core::class, $sdk);
        $this->assertTrue($sdk->isReady());
    }

    #[DataProvider('authModes')]
    public function testReadyEventFiredOnInit(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $events = [];

        $sdk = $this->createSdk($mode);

        // Deferred event: listener attached after create() still receives the Ready event
        $sdk->on('ready', function ($args, $err) use (&$events) {
            $events[] = ['args' => $args, 'err' => $err];
        });

        $this->assertCount(1, $events, 'Ready event should fire exactly once');
        $this->assertNull($events[0]['err'], 'Ready event should have no error');
    }

    #[DataProvider('authModes')]
    public function testCreateContextAndRunExperience(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $context = $sdk->createContext('visitor-integration-test', null);
        $this->assertNotNull($context);

        $result = $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);

        $this->assertInstanceOf(BucketedVariation::class, $result);
        $this->assertSame(self::EXPERIENCE_KEY, $result->experienceKey);
        $this->assertContains($result->variationId, ['1003180877', '1003180878']);
        $this->assertNotEmpty($result->variationKey, 'variationKey should be non-empty');
        $this->assertNotEmpty($result->changes);
    }

    #[DataProvider('authModes')]
    public function testBucketingDeterminism(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $context = $sdk->createContext('determinism-visitor', null);
        $results = [];

        for ($i = 0; $i < 10; $i++) {
            $variation = $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);
            $this->assertNotNull($variation);
            $results[] = $variation->variationId;
        }

        $unique = array_unique($results);
        $this->assertCount(1, $unique, 'Same visitor must always get the same variation');
    }

    #[DataProvider('authModes')]
    public function testBucketingEventFiredOnExperience(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $bucketingEvents = [];

        $sdk->on('bucketing', function ($args, $err) use (&$bucketingEvents) {
            $bucketingEvents[] = ['args' => $args, 'err' => $err];
        });

        $context = $sdk->createContext('event-spy-visitor', null);
        $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);

        $this->assertNotEmpty($bucketingEvents, 'Bucketing event should fire when running an experience');
    }

    #[DataProvider('authModes')]
    public function testRunFeatureWithTypedVariables(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $context = $sdk->createContext('feature-typed-visitor', null);
        $result = $context->runFeature(self::FEATURE_TYPED_KEY, $this->qualifyingAttributes);

        $this->assertInstanceOf(BucketedFeature::class, $result);
        $this->assertSame(FeatureStatus::Enabled, $result->status);
        $this->assertSame(self::FEATURE_TYPED_KEY, $result->featureKey);

        // Verify typed variables
        $this->assertIsFloat($result->variables['price']);
        $this->assertIsInt($result->variables['button-height']);

        // JSON variable should be decoded to array/object
        $additionalData = $result->variables['additionalData'];
        $this->assertIsArray($additionalData);
        $this->assertSame('bar', $additionalData['foo']);
        $this->assertSame(2, $additionalData['v']);
    }

    #[DataProvider('authModes')]
    public function testFullChainInitContextBucketFeatureVerify(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        // Init
        $sdk = $this->createSdk($mode);
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
        $variation = $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);
        $this->assertInstanceOf(BucketedVariation::class, $variation);

        // Feature
        $feature = $context->runFeature(self::FEATURE_BASIC_KEY, $this->qualifyingAttributes);
        $this->assertInstanceOf(BucketedFeature::class, $feature);
        $this->assertSame(FeatureStatus::Enabled, $feature->status);

        // Verify events
        $this->assertCount(1, $readyEvents, 'Ready event should fire exactly once');
        $this->assertNotEmpty($bucketingEvents, 'Bucketing events should fire for experience and feature runs');

        // Verify determinism: re-run same experience, expect same variationId
        $secondRun = $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);
        $this->assertNotNull($secondRun);
        $this->assertSame($variation->variationId, $secondRun->variationId);
    }

    // -- Negative Paths ----------------------------------------------------

    public function testCreateWithoutSdkKeyOrDataThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConvertSDK::create([]);
    }

    #[DataProvider('authModes')]
    public function testRunFeatureWithUnknownKeyReturnsNull(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $context = $sdk->createContext('null-feature-visitor', null);
        $result = $context->runFeature('completely-nonexistent-feature-key', $this->qualifyingAttributes);
        $this->assertNull($result);
    }

    #[DataProvider('authModes')]
    public function testRunExperienceWithNonQualifyingVisitorReturnsNull(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $context = $sdk->createContext('non-qualifying-visitor', null);

        $nonQualifyingAttributes = new BucketingAttributes([
            'locationProperties' => ['location' => 'nonexistent'],
        ]);

        $result = $context->runExperience(self::EXPERIENCE_KEY, $nonQualifyingAttributes);
        $this->assertNull($result);
    }

    #[DataProvider('authModes')]
    public function testRunExperienceWithAudienceAndNoVisitorPropertiesReturnsNull(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $context = $sdk->createContext('audience-no-props-visitor', null);

        // Experience -1 has audience adv-audience (desktop=true AND browser!="CH" OR mobile=true).
        // Omitting visitorProperties means audience rules can't be evaluated → null.
        $locationOnlyAttributes = new BucketingAttributes([
            'locationProperties' => ['location' => 'pricing'],
        ]);

        $result = $context->runExperience('test-experience-ab-fullstack-1', $locationOnlyAttributes);
        $this->assertNull($result, 'Experience with audiences should return null when visitorProperties is not provided');
    }

    // -- Conversion Tracking -----------------------------------------------

    #[DataProvider('authModes')]
    public function testTrackConversion(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createTrackingEnabledSdk($mode);

        $queueReleasedEvents = [];
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-basic');
        $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);

        $result = $context->trackConversion(self::GOAL_KEY);
        $sdk->flush();

        $this->assertNull($result, 'trackConversion should return null on success');
        $this->assertNotEmpty($queueReleasedEvents, 'ApiQueueReleased should fire (tracking POST sent)');
    }

    #[DataProvider('authModes')]
    public function testConversionEventFiredOnTrackConversion(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createTrackingEnabledSdk($mode);

        $conversionEvents = [];
        $sdk->on(SystemEvents::Conversion->value, function ($args) use (&$conversionEvents) {
            $conversionEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-event');
        $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);
        $context->trackConversion(self::GOAL_KEY);

        $this->assertCount(1, $conversionEvents, 'Conversion event should fire exactly once');
        $this->assertSame('tracking-visitor-event', $conversionEvents[0]['visitorId']);
        $this->assertSame(self::GOAL_KEY, $conversionEvents[0]['goalKey']);
    }

    #[DataProvider('authModes')]
    public function testGoalDeduplication(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createTrackingEnabledSdk($mode);

        $queueReleasedEvents = [];
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-dedup');
        $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);

        // First call — should enqueue and release on flush
        $context->trackConversion(self::GOAL_KEY);
        $sdk->flush();
        $countAfterFirst = count($queueReleasedEvents);
        $this->assertGreaterThan(0, $countAfterFirst, 'First conversion should trigger API queue release');

        // Second call — deduplicated, nothing enqueued, flush is no-op
        $secondResult = $context->trackConversion(self::GOAL_KEY);
        $sdk->flush();
        $this->assertNull($secondResult, 'Deduplicated conversion should still return null (same as first call)');
        $this->assertCount($countAfterFirst, $queueReleasedEvents, 'Second conversion should be deduplicated (no new API queue release)');
    }

    #[DataProvider('authModes')]
    public function testTrackConversionWithRevenue(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createTrackingEnabledSdk($mode);

        $queueReleasedEvents = [];
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-revenue');
        $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);

        $result = $context->trackConversion(self::GOAL_KEY, new ConversionAttributes(
            conversionData: [
                new GoalData(GoalDataKey::Amount, 49.99),
                new GoalData(GoalDataKey::TransactionId, 'txn-integration-001'),
            ],
        ));

        $sdk->flush();

        $this->assertNull($result, 'Revenue conversion should return null on success');
        // Revenue tracking enqueues conversion + transaction events, flushed as a single batched release
        $this->assertGreaterThanOrEqual(1, count($queueReleasedEvents), 'Revenue conversion should trigger at least 1 API release');

        // Verify released payload contains visitor data
        $lastEvent = end($queueReleasedEvents);
        $this->assertArrayHasKey('visitors', $lastEvent);
    }

    #[DataProvider('authModes')]
    public function testForceMultipleTransactions(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createTrackingEnabledSdk($mode);

        $queueReleasedEvents = [];
        $sdk->on(SystemEvents::ApiQueueReleased->value, function ($args) use (&$queueReleasedEvents) {
            $queueReleasedEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-force');
        $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);

        // First call with goalData: conversion + transaction enqueued, flushed as batch
        $context->trackConversion(self::GOAL_KEY, new ConversionAttributes(
            conversionData: [new GoalData(GoalDataKey::Amount, 25.00)],
        ));
        $sdk->flush();
        $countAfterFirst = count($queueReleasedEvents);
        $this->assertGreaterThanOrEqual(1, $countAfterFirst, 'First revenue conversion should trigger at least 1 release');

        // Second call with forceMultipleTransactions: transaction event should still be sent
        $context->trackConversion(self::GOAL_KEY, new ConversionAttributes(
            conversionData: [new GoalData(GoalDataKey::Amount, 25.00)],
            conversionSetting: [ConversionSettingKey::ForceMultipleTransactions->value => true],
        ));
        $sdk->flush();
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

    #[DataProvider('authModes')]
    public function testTrackConversionWithNonexistentGoalReturnsFalse(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        $sdk = $this->createSdk($mode);
        $conversionEvents = [];
        $sdk->on(SystemEvents::Conversion->value, function ($args) use (&$conversionEvents) {
            $conversionEvents[] = $args;
        });

        $context = $sdk->createContext('tracking-visitor-fake-goal');
        $result = $context->trackConversion('totally-fake-goal');

        $this->assertFalse($result, 'Non-existent goal should return false');
        $this->assertEmpty($conversionEvents, 'No conversion event should fire for non-existent goal');
    }

    // -- Complete Chain ----------------------------------------------------

    #[DataProvider('authModes')]
    public function testCompleteChainInitThroughFlush(string $mode): void
    {
        $this->skipIfLiveDisabled($mode);
        // Init with tracking enabled
        $sdk = $this->createTrackingEnabledSdk($mode);
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
        $variation = $context->runExperience(self::EXPERIENCE_KEY, $this->qualifyingAttributes);
        $this->assertInstanceOf(BucketedVariation::class, $variation);

        // Feature — runFeature
        $feature = $context->runFeature(self::FEATURE_BASIC_KEY, $this->qualifyingAttributes);
        $this->assertInstanceOf(BucketedFeature::class, $feature);
        $this->assertSame(FeatureStatus::Enabled, $feature->status);

        // Track — conversion
        $result = $context->trackConversion(self::GOAL_KEY);
        $this->assertNull($result, 'trackConversion should return null on success');

        // Flush — release all queued events as a single batched POST
        $sdk->flush();

        // Verify all event types fired
        $this->assertCount(1, $readyEvents, 'Ready event should fire exactly once');
        $this->assertNotEmpty($bucketingEvents, 'Bucketing events should fire for experience and feature runs');
        $this->assertCount(1, $conversionEvents, 'Conversion event should fire once');
        $this->assertSame('complete-chain-visitor', $conversionEvents[0]['visitorId']);
        $this->assertNotEmpty($queueReleasedEvents, 'API queue should be released (tracking POST sent)');
    }
}
