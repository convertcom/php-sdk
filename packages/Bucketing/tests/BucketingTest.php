<?php

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\BucketingManager;
use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Enums\LogMethod;
use ConvertSdk\Interfaces\LogMethodMapInterface;

class BucketingTest extends TestCase
{
    const TESTS_AMOUNT = 10000;
    const DEFAULT_MAX_TRAFFIC = 10000;

    protected $bucketingManager;

    protected function setUp(): void
    {
        $this->bucketingManager = new BucketingManager();
    }

    protected function getTestResultsForVisitor($bucketingManager, $testVariations, $visitorId, $amount = self::TESTS_AMOUNT)
    {
        $results = [];
        for ($i = 0; $i < $amount; $i++) {
            $variationId = $bucketingManager->getBucketForVisitor($testVariations, $visitorId)['variationId'];
            if (!isset($results[$variationId])) {
                $results[$variationId] = 0;
            }
            $results[$variationId]++;
        }
        return $results;
    }

    public function testShouldExposeBucketingManager()
    {
        $this->assertTrue(class_exists(BucketingManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfBucketingManagerInstance()
    {
        $this->assertInstanceOf(BucketingManager::class, new BucketingManager());
    }

    public function testShouldCreateNewBucketingManagerInstanceWithDefaultConfig()
    {
        $bucketingManager = new BucketingManager();
        $this->assertInstanceOf(BucketingManager::class, $bucketingManager);
    }

    public function testShouldCreateNewBucketingManagerInstanceWithProvidedConfig()
    {
        $jsonConfig = file_get_contents(__DIR__ . '/test-config.json');
        $configuration = json_decode($jsonConfig, true);
        $bucketingManager = new BucketingManager($configuration);
        $this->assertInstanceOf(BucketingManager::class, $bucketingManager);
    }

    public function testShouldSelectABucket()
    {
        $testVariations = [
            '100234567' => 30,
            '100234568' => 30,
            '100234569' => 30,
            '100234570' => 10
        ];
        $variationId1 = $this->bucketingManager->selectBucket($testVariations, 100);
        $variationId2 = $this->bucketingManager->selectBucket($testVariations, 200);
        $this->assertContains($variationId1, array_keys($testVariations));
        $this->assertEquals($variationId1, $variationId2);
    }

    public function testShouldSelectAnotherBucket()
    {
        $testVariations = [
            '100234567' => 30,
            '100234568' => 30,
            '100234569' => 30,
            '100234570' => 10
        ];
        $variationId1 = $this->bucketingManager->selectBucket($testVariations, 6000);
        $variationId2 = $this->bucketingManager->selectBucket($testVariations, 6500);
        $this->assertContains($variationId1, array_keys($testVariations));
        $this->assertEquals($variationId1, $variationId2);
    }

    public function testShouldNotSelectABucketAndReturnNull()
    {
        $testVariations = [
            '100234567' => 0,
            '100234568' => 0,
            '100234569' => 0,
            '100234570' => 0
        ];
        $variationId = $this->bucketingManager->selectBucket($testVariations, 6000);
        $this->assertNull($variationId);

        $testVariations = [
            '100234567' => 30,
            '100234568' => 10,
            '100234569' => 30,
            '100234570' => 30
        ];
        $variationId = $this->bucketingManager->selectBucket($testVariations, self::DEFAULT_MAX_TRAFFIC + 1);
        $this->assertNull($variationId);
    }

    public function testShouldReturnAValueGeneratedWithHelpOfMurmurhashBasedOnVisitorId()
    {
        $value = $this->bucketingManager->getValueVisitorBased('100123456');
        $this->assertIsInt($value);
    }

    public function testShouldReturnDifferentValuesGeneratedWithHelpOfMurmurhashBasedOnVisitorIdWithSeeds()
    {
        $value1 = $this->bucketingManager->getValueVisitorBased('100123456', ['seed' => 11223344]);
        $value2 = $this->bucketingManager->getValueVisitorBased('100123456', ['seed' => 99887766]);
        $this->assertNotEquals($value1, $value2);
    }

    public function testShouldReturnTheSameBucketBasedOnVisitorStringForEveryAttempt()
    {
        $testVariations = [
            '100234567' => 10,
            '100234568' => 30,
            '100234569' => 60,
            '100234570' => 0
        ];
        $visitorId = '01ABCD';
        $results = $this->getTestResultsForVisitor($this->bucketingManager, $testVariations, $visitorId);
        $this->assertCount(1, array_keys($results));
        foreach ($results as $variationId => $attempts) {
            $this->assertContains($variationId, array_keys($testVariations));
            $this->assertEquals(self::TESTS_AMOUNT, $attempts);
        }
    }
}