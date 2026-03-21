<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\ConvertSDK;
use ConvertSdk\Core;
use ConvertSdk\Context;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Exception\InvalidArgumentException;

class ConvertSDKTest extends TestCase
{
    /**
     * Get test config data array for initialization without HTTP calls.
     *
     * @return array<string, mixed>
     */
    private function getTestData(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/test-config.json'), true)['data'];
    }

    /** @test */
    public function createThrowsInvalidArgumentExceptionWhenBothSdkKeyAndDataAreMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either sdkKey or data must be provided');

        ConvertSDK::create([]);
    }

    /** @test */
    public function createThrowsInvalidArgumentExceptionWithEmptyConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConvertSDK::create(['sdkKey' => '', 'data' => []]);
    }

    /** @test */
    public function createWithDataKeyReturnsCoreInstance(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        $this->assertInstanceOf(Core::class, $sdk);
    }

    /** @test */
    public function createContextReturnsContextInstance(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);
        $context = $sdk->createContext('visitor-id-456', ['country' => 'US']);

        $this->assertInstanceOf(Context::class, $context);
    }

    /** @test */
    public function contextThrowsInvalidArgumentExceptionForEmptyVisitorId(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Visitor ID must not be empty');

        $sdk->createContext('');
    }

    /** @test */
    public function bucketedVariationDtoIsReadonlyWithExpectedProperties(): void
    {
        $dto = new BucketedVariation(
            experienceId: 'exp-1',
            experienceKey: 'my-experiment',
            variationId: 'var-1',
            variationKey: 'variation-a',
            changes: [['type' => 'custom', 'data' => []]],
        );

        $this->assertEquals('exp-1', $dto->experienceId);
        $this->assertEquals('my-experiment', $dto->experienceKey);
        $this->assertEquals('var-1', $dto->variationId);
        $this->assertEquals('variation-a', $dto->variationKey);
        $this->assertIsArray($dto->changes);
        $this->assertCount(1, $dto->changes);

        // Verify class is readonly
        $reflection = new \ReflectionClass(BucketedVariation::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    /** @test */
    public function bucketedFeatureDtoIsReadonlyWithExpectedProperties(): void
    {
        $dto = new BucketedFeature(
            featureId: 'feat-1',
            featureKey: 'my-feature',
            status: FeatureStatus::Enabled,
            variables: ['enabled' => true, 'caption' => 'Click'],
        );

        $this->assertEquals('feat-1', $dto->featureId);
        $this->assertEquals('my-feature', $dto->featureKey);
        $this->assertEquals(FeatureStatus::Enabled, $dto->status);
        $this->assertIsArray($dto->variables);
        $this->assertTrue($dto->variables['enabled']);

        // Verify class is readonly
        $reflection = new \ReflectionClass(BucketedFeature::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    /** @test */
    public function isReadyReturnsTrueAfterSuccessfulInitializationWithData(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        $this->assertTrue($sdk->isReady());
    }

    /** @test */
    public function convertSdkIsNotDirectlyInstantiable(): void
    {
        $reflection = new \ReflectionClass(ConvertSDK::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    /** @test */
    public function createIsStaticMethod(): void
    {
        $reflection = new \ReflectionClass(ConvertSDK::class);
        $method = $reflection->getMethod('create');

        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
    }

    /** @test */
    public function convertSdkIsFinalClass(): void
    {
        $reflection = new \ReflectionClass(ConvertSDK::class);
        $this->assertTrue($reflection->isFinal());
    }

    /** @test */
    public function createWithPsr3LoggerWorks(): void
    {
        $logger = new \Psr\Log\NullLogger();
        $sdk = ConvertSDK::create([
            'data' => $this->getTestData(),
            'logger' => $logger,
        ]);

        $this->assertInstanceOf(Core::class, $sdk);
        $this->assertTrue($sdk->isReady());
    }
}
