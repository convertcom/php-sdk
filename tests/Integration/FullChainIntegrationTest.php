<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ConvertSdk\ConvertSDK;
use ConvertSdk\Core;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Exception\InvalidArgumentException;
use OpenAPI\Client\BucketingAttributes;

class FullChainIntegrationTest extends TestCase
{
    private Core $sdk;
    private BucketingAttributes $qualifyingAttributes;
    private array $configData;
    private string $environment;
    private int $previousErrorReporting;

    private function sdkCreateOptions(array $overrides = []): array
    {
        return array_merge([
            'data' => $this->configData,
            'environment' => $this->environment,
            'network' => ['tracking' => false],
        ], $overrides);
    }

    protected function setUp(): void
    {
        // Suppress E_DEPRECATED only — pre-existing SDK issues with PHP 8.4
        // (end() on objects, optional params before required).
        // Warnings and errors are NOT suppressed to catch real issues.
        $this->previousErrorReporting = error_reporting();
        error_reporting($this->previousErrorReporting & ~E_DEPRECATED);

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

    protected function tearDown(): void
    {
        error_reporting($this->previousErrorReporting);
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
}
