<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ConvertSdk\BucketingManager;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Utils\StringUtils;

class BucketingManagerTest extends TestCase
{
    private const DEFAULT_MAX_TRAFFIC = 10000;
    private const MAX_HASH = 4294967296;

    private BucketingManager $bucketingManager;

    protected function setUp(): void
    {
        $this->bucketingManager = new BucketingManager();
    }

    public function testShouldExposeBucketingManager(): void
    {
        $this->assertTrue(class_exists(BucketingManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfBucketingManagerInstance(): void
    {
        $this->assertInstanceOf(BucketingManager::class, new BucketingManager());
    }

    public function testShouldCreateNewBucketingManagerInstanceWithDefaultConfig(): void
    {
        $bucketingManager = new BucketingManager();
        $this->assertInstanceOf(BucketingManager::class, $bucketingManager);
    }

    public function testShouldCreateNewBucketingManagerInstanceWithProvidedConfig(): void
    {
        $bucketingManager = new BucketingManager(maxTraffic: 5000, hashSeed: 1234);
        $this->assertInstanceOf(BucketingManager::class, $bucketingManager);
    }

    public function testSelectBucketReturnsCorrectVariation(): void
    {
        $buckets = [
            '100234567' => 30,
            '100234568' => 30,
            '100234569' => 30,
            '100234570' => 10
        ];

        // Value 100 should fall in first bucket (0-3000 range)
        $this->assertSame('100234567', $this->bucketingManager->selectBucket($buckets, 100));

        // Value 3500 should fall in second bucket (3000-6000 range)
        $this->assertSame('100234568', $this->bucketingManager->selectBucket($buckets, 3500));

        // Value 6500 should fall in third bucket (6000-9000 range)
        $this->assertSame('100234569', $this->bucketingManager->selectBucket($buckets, 6500));

        // Value 9500 should fall in fourth bucket (9000-10000 range)
        $this->assertSame('100234570', $this->bucketingManager->selectBucket($buckets, 9500));
    }

    public function testSelectBucketReturnsStringVariationId(): void
    {
        // PHP coerces numeric string keys to integers internally.
        // selectBucket must always return a string to match JS SDK behavior
        // (Object.keys returns strings in JS).
        $buckets = [
            '100234567' => 50,
            '100234568' => 50,
        ];

        $result = $this->bucketingManager->selectBucket($buckets, 100);
        $this->assertIsString($result);
        $this->assertSame('100234567', $result);
    }

    public function testShouldSelectABucket(): void
    {
        $testVariations = [
            '100234567' => 30,
            '100234568' => 30,
            '100234569' => 30,
            '100234570' => 10
        ];
        $variationId1 = $this->bucketingManager->selectBucket($testVariations, 100);
        $variationId2 = $this->bucketingManager->selectBucket($testVariations, 200);
        $this->assertNotNull($variationId1);
        $this->assertSame($variationId1, $variationId2);
    }

    public function testShouldSelectAnotherBucket(): void
    {
        $testVariations = [
            '100234567' => 30,
            '100234568' => 30,
            '100234569' => 30,
            '100234570' => 10
        ];
        $variationId1 = $this->bucketingManager->selectBucket($testVariations, 6000);
        $variationId2 = $this->bucketingManager->selectBucket($testVariations, 6500);
        $this->assertNotNull($variationId1);
        $this->assertSame($variationId1, $variationId2);
    }

    public function testSelectBucketBoundaryValues(): void
    {
        $buckets = [
            'A' => 30,
            'B' => 70,
        ];

        // Just below boundary (2999.99) → first bucket
        $this->assertSame('A', $this->bucketingManager->selectBucket($buckets, 2999.99));

        // Exactly at boundary (3000.0) → falls to NEXT bucket (condition is $value < $prev)
        $this->assertSame('B', $this->bucketingManager->selectBucket($buckets, 3000.0));

        // Just above boundary (3000.01) → second bucket
        $this->assertSame('B', $this->bucketingManager->selectBucket($buckets, 3000.01));

        // Value 0 → first bucket
        $this->assertSame('A', $this->bucketingManager->selectBucket($buckets, 0.0));
    }

    public function testSelectBucketReturnsNullForZeroPercentVariation(): void
    {
        $testVariations = [
            '100234567' => 0,
            '100234568' => 0,
            '100234569' => 0,
            '100234570' => 0
        ];
        $variationId = $this->bucketingManager->selectBucket($testVariations, 6000);
        $this->assertNull($variationId);
    }

    public function testShouldNotSelectABucketAndReturnNull(): void
    {
        $testVariations = [
            '100234567' => 30,
            '100234568' => 10,
            '100234569' => 30,
            '100234570' => 30
        ];
        $variationId = $this->bucketingManager->selectBucket($testVariations, self::DEFAULT_MAX_TRAFFIC + 1);
        $this->assertNull($variationId);
    }

    public function testShouldReturnAValueGeneratedWithHelpOfMurmurhashBasedOnVisitorId(): void
    {
        $value = $this->bucketingManager->getValueVisitorBased('100123456');
        $this->assertIsInt($value);
    }

    public function testShouldReturnDifferentValuesGeneratedWithHelpOfMurmurhashBasedOnVisitorIdWithSeeds(): void
    {
        $value1 = $this->bucketingManager->getValueVisitorBased('100123456', ['seed' => 11223344]);
        $value2 = $this->bucketingManager->getValueVisitorBased('100123456', ['seed' => 99887766]);
        $this->assertNotEquals($value1, $value2);
    }

    public function testGetValueVisitorBasedFormula(): void
    {
        $visitorId = 'visitor-456';
        $experienceId = '100234567';
        $seed = 9999;

        // Compute expected value manually using the formula
        $hash = StringUtils::generateHash($experienceId . strval($visitorId), $seed);
        $expectedValue = intval(($hash / self::MAX_HASH) * self::DEFAULT_MAX_TRAFFIC);

        $actualValue = $this->bucketingManager->getValueVisitorBased($visitorId, [
            'experienceId' => $experienceId,
            'seed' => $seed,
        ]);

        $this->assertSame($expectedValue, $actualValue);
    }

    public function testDeterministicBucketing(): void
    {
        $testVariations = [
            '100234567' => 10,
            '100234568' => 30,
            '100234569' => 60,
            '100234570' => 0
        ];
        $visitorId = '01ABCD';

        // Run 1000 times — must produce same result every time
        $firstResult = $this->bucketingManager->getBucketForVisitor($testVariations, $visitorId);
        $this->assertNotNull($firstResult);

        for ($i = 0; $i < 999; $i++) {
            $result = $this->bucketingManager->getBucketForVisitor($testVariations, $visitorId);
            $this->assertSame($firstResult['variationId'], $result['variationId']);
            $this->assertSame($firstResult['bucketingAllocation'], $result['bucketingAllocation']);
        }
    }

    public function testDifferentVisitorsDifferentBuckets(): void
    {
        $testVariations = [
            'A' => 50,
            'B' => 50,
        ];

        $results = ['A' => 0, 'B' => 0];
        $visitorCount = 1000;

        for ($i = 0; $i < $visitorCount; $i++) {
            $bucket = $this->bucketingManager->getBucketForVisitor(
                $testVariations,
                'visitor-' . $i,
                ['experienceId' => 'exp-test']
            );
            if ($bucket !== null) {
                $results[$bucket['variationId']]++;
            }
        }

        // With 50/50 split and 1000 visitors, each bucket should get
        // at least 30% (statistical tolerance for hash distribution)
        $this->assertGreaterThan($visitorCount * 0.30, $results['A']);
        $this->assertGreaterThan($visitorCount * 0.30, $results['B']);
    }

    public function testGetBucketForVisitorCallsDebugOnLogger(): void
    {
        /** @var LogManagerInterface&MockObject $logManager */
        $logManager = $this->createMock(LogManagerInterface::class);

        $debugCalls = [];
        $logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function () use (&$debugCalls): void {
                $debugCalls[] = func_get_args();
            });

        $bucketingManager = new BucketingManager(logManager: $logManager);

        $buckets = ['A' => 50, 'B' => 50];
        $bucketingManager->getBucketForVisitor($buckets, 'visitor-456', ['experienceId' => 'exp-1']);

        // Verify getBucketForVisitor summary log was emitted
        $summaryLogs = array_filter($debugCalls, fn($call) => $call[0] === 'BucketingManager.getBucketForVisitor()');
        $this->assertNotEmpty($summaryLogs, 'Expected debug log from getBucketForVisitor()');

        $summaryLog = reset($summaryLogs);
        $this->assertArrayHasKey('visitorId', $summaryLog[1]);
        $this->assertArrayHasKey('experienceId', $summaryLog[1]);
        $this->assertArrayHasKey('bucketValue', $summaryLog[1]);
        $this->assertArrayHasKey('selectedVariationId', $summaryLog[1]);
        $this->assertSame('visitor-456', $summaryLog[1]['visitorId']);
        $this->assertSame('exp-1', $summaryLog[1]['experienceId']);
    }

    public function testNoExceptionWhenLogManagerIsNull(): void
    {
        $bucketingManager = new BucketingManager(logManager: null);

        $buckets = ['A' => 50, 'B' => 50];
        $result = $bucketingManager->getBucketForVisitor($buckets, 'visitor-123', ['experienceId' => 'exp-1']);

        $this->assertNotNull($result);
        $this->assertIsArray($result);
    }
}
