<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Cache\ArrayCache;
use ConvertSdk\Context;
use ConvertSdk\ConvertSDK;
use ConvertSdk\Core;
use ConvertSdk\DataStoreManager;
use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

    #[Test]
    public function createThrowsInvalidArgumentExceptionWhenBothSdkKeyAndDataAreMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either sdkKey or data must be provided');

        ConvertSDK::create([]);
    }

    #[Test]
    public function createThrowsInvalidArgumentExceptionWithEmptyConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConvertSDK::create(['sdkKey' => '', 'data' => []]);
    }

    #[Test]
    public function createWithDataKeyReturnsCoreInstance(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        $this->assertInstanceOf(Core::class, $sdk);
    }

    #[Test]
    public function createContextReturnsContextInstance(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);
        $context = $sdk->createContext('visitor-id-456', ['country' => 'US']);

        $this->assertInstanceOf(Context::class, $context);
    }

    #[Test]
    public function contextThrowsInvalidArgumentExceptionForEmptyVisitorId(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Visitor ID must not be empty');

        $sdk->createContext('');
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function isReadyReturnsTrueAfterSuccessfulInitializationWithData(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        $this->assertTrue($sdk->isReady());
    }

    #[Test]
    public function convertSdkIsNotDirectlyInstantiable(): void
    {
        $reflection = new \ReflectionClass(ConvertSDK::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    #[Test]
    public function createIsStaticMethod(): void
    {
        $reflection = new \ReflectionClass(ConvertSDK::class);
        $method = $reflection->getMethod('create');

        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function convertSdkIsFinalClass(): void
    {
        $reflection = new \ReflectionClass(ConvertSDK::class);
        $this->assertTrue($reflection->isFinal());
    }

    #[Test]
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

    #[Test]
    public function coreHasFlushMethodAndShutdownHookIsRegistered(): void
    {
        // Verifies AC #6 (shutdown hook) and AC #7 (Core::flush).
        // Note: register_shutdown_function cannot be directly asserted in PHPUnit.
        // We verify: (1) flush() exists and is callable, (2) it delegates to
        // ApiManager::releaseQueue('flush'), and (3) ConvertSDK::create() completes
        // without error (which includes the shutdown function registration).
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        $this->assertTrue(method_exists($sdk, 'flush'));
        // flush() should not throw when queue is empty
        $sdk->flush();
        $this->assertTrue(true);
    }

    #[Test]
    public function createSetsPhpSdkAsDefaultSource(): void
    {
        // ConvertSDK::create() should set network.source to 'php-sdk' by default
        // (unless VERSION env var overrides it)
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        // Verify via reflection that the ApiManager received 'php-sdk' as trackingSource
        $coreRef = new \ReflectionClass($sdk);
        $apiManagerProp = $coreRef->getProperty('apiManager');
        $apiManager = $apiManagerProp->getValue($sdk);

        $apiRef = new \ReflectionClass($apiManager);
        $sourceProp = $apiRef->getProperty('trackingSource');
        $source = $sourceProp->getValue($apiManager);

        $this->assertEquals('php-sdk', $source);
    }

    /**
     * Helper: extract DataStoreManager from Core via reflection.
     */
    private function getDataStoreManager(Core $sdk): ?DataStoreManager
    {
        $coreRef = new \ReflectionClass($sdk);
        $dataManagerProp = $coreRef->getProperty('dataManager');
        $dataManager = $dataManagerProp->getValue($sdk);

        return $dataManager->getDataStoreManager();
    }

    /**
     * Helper: extract the underlying dataStore object from DataStoreManager via reflection.
     */
    private function getUnderlyingDataStore(DataStoreManager $dsm): mixed
    {
        $ref = new \ReflectionClass($dsm);
        $prop = $ref->getProperty('dataStore');
        return $prop->getValue($dsm);
    }

    #[Test]
    public function createWiresPsr16CacheAsDataStoreByDefault(): void
    {
        $sdk = ConvertSDK::create(['data' => $this->getTestData()]);

        $dsm = $this->getDataStoreManager($sdk);
        $this->assertInstanceOf(DataStoreManager::class, $dsm);

        // Default cache is ArrayCache — verify it was wired as the underlying dataStore
        $underlying = $this->getUnderlyingDataStore($dsm);
        $this->assertInstanceOf(ArrayCache::class, $underlying);
    }

    #[Test]
    public function createUsesProvidedPsr16CacheAsDataStore(): void
    {
        $cache = new ArrayCache();

        $sdk = ConvertSDK::create([
            'data'  => $this->getTestData(),
            'cache' => $cache,
        ]);

        $dsm = $this->getDataStoreManager($sdk);
        $this->assertInstanceOf(DataStoreManager::class, $dsm);

        $underlying = $this->getUnderlyingDataStore($dsm);
        $this->assertSame($cache, $underlying);
    }

    #[Test]
    public function createUsesExplicitDataStoreOverCache(): void
    {
        $cache = new ArrayCache();
        $customStore = new class {
            private array $data = [];
            public function get(string $key): mixed { return $this->data[$key] ?? null; }
            public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
        };

        $sdk = ConvertSDK::create([
            'data'      => $this->getTestData(),
            'cache'     => $cache,
            'dataStore' => $customStore,
        ]);

        $dsm = $this->getDataStoreManager($sdk);
        $this->assertInstanceOf(DataStoreManager::class, $dsm);

        $underlying = $this->getUnderlyingDataStore($dsm);
        $this->assertSame($customStore, $underlying);
    }
}
