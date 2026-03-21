<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Config;

use PHPUnit\Framework\TestCase;
use ConvertSdk\Core;
use ConvertSdk\Cache\ArrayCache;
use ConvertSdk\Config\ConfigValidator;
use ConvertSdk\Exception\ConfigFetchException;
use ConvertSdk\Exception\ConfigValidationException;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\ExperienceManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use Psr\SimpleCache\CacheInterface;

class CoreConfigFlowTest extends TestCase
{
    private function validConfigData(): ConfigResponseData
    {
        return new ConfigResponseData([
            'account_id' => '10022898',
            'project' => ['id' => '10025986', 'name' => 'Test Project'],
        ]);
    }

    private function makeConfig(array $overrides = []): Config
    {
        $defaults = [
            'data' => new ConfigResponseData([]),
            'api' => [
                'endpoint' => [
                    'config' => 'http://cdn.example.com',
                    'track' => 'http://track.example.com',
                ],
            ],
            'environment' => 'staging',
        ];

        return new Config(array_merge($defaults, $overrides));
    }

    private function makeDependencies(
        ?ApiManagerInterface $apiManager = null,
        ?DataManagerInterface $dataManager = null,
        ?EventManagerInterface $eventManager = null,
    ): array {
        return [
            'dataManager' => $dataManager ?? $this->createMock(DataManagerInterface::class),
            'eventManager' => $eventManager ?? $this->createMock(EventManagerInterface::class),
            'experienceManager' => $this->createMock(ExperienceManagerInterface::class),
            'featureManager' => $this->createMock(FeatureManagerInterface::class),
            'segmentsManager' => $this->createMock(SegmentsManagerInterface::class),
            'apiManager' => $apiManager ?? $this->createMock(ApiManagerInterface::class),
            'loggerManager' => $this->createMock(LogManagerInterface::class),
        ];
    }

    /** @test */
    public function directDataInitializationBypassesHttp(): void
    {
        $configData = $this->validConfigData();

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->never())->method('getConfig');

        $dataManager = $this->createMock(DataManagerInterface::class);
        $dataManager->expects($this->once())
            ->method('setConfigData')
            ->with($configData);

        $config = $this->makeConfig(['data' => $configData]);
        $deps = $this->makeDependencies($apiManager, $dataManager);
        $cache = new ArrayCache();

        $core = new Core($config, $deps, $cache);
        $this->assertInstanceOf(Core::class, $core);
    }

    /** @test */
    public function sdkKeyInitializationCallsApiManagerWhenCacheEmpty(): void
    {
        $configData = $this->validConfigData();

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->once())
            ->method('getConfig')
            ->willReturn($configData);

        $dataManager = $this->createMock(DataManagerInterface::class);
        $dataManager->expects($this->once())
            ->method('setConfigData')
            ->with($configData);

        $config = $this->makeConfig([
            'sdkKey' => 'test_sdk_key',
            'data' => new ConfigResponseData([]),
        ]);

        $deps = $this->makeDependencies($apiManager, $dataManager);
        $cache = new ArrayCache();

        $core = new Core($config, $deps, $cache);
        $this->assertInstanceOf(Core::class, $core);
    }

    /** @test */
    public function cacheHitReturnsCachedConfigWithoutHttpCall(): void
    {
        $configData = $this->validConfigData();
        $sdkKey = 'cached_sdk_key';

        $cache = new ArrayCache();
        $cacheKey = 'convert_sdk.config.' . $sdkKey;
        $cache->set($cacheKey, $configData, 300);

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->never())->method('getConfig');

        $dataManager = $this->createMock(DataManagerInterface::class);
        $dataManager->expects($this->once())
            ->method('setConfigData')
            ->with($configData);

        $config = $this->makeConfig([
            'sdkKey' => $sdkKey,
            'data' => new ConfigResponseData([]),
        ]);

        $deps = $this->makeDependencies($apiManager, $dataManager);

        $core = new Core($config, $deps, $cache);
        $this->assertInstanceOf(Core::class, $core);
    }

    /** @test */
    public function cacheMissFetchesViaHttpAndStoresInCache(): void
    {
        $configData = $this->validConfigData();
        $sdkKey = 'fetchable_key';

        $cache = new ArrayCache();
        $cacheKey = 'convert_sdk.config.' . $sdkKey;

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->once())
            ->method('getConfig')
            ->willReturn($configData);

        $config = $this->makeConfig([
            'sdkKey' => $sdkKey,
            'data' => new ConfigResponseData([]),
        ]);

        $deps = $this->makeDependencies($apiManager);

        $core = new Core($config, $deps, $cache, 600);

        // Verify cache was populated
        $cached = $cache->get($cacheKey);
        $this->assertInstanceOf(ConfigResponseData::class, $cached);
        $this->assertSame('10022898', $cached->getAccountId());
    }

    /** @test */
    public function apiManagerRuntimeExceptionIsWrappedInConfigFetchException(): void
    {
        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->once())
            ->method('getConfig')
            ->willThrowException(new \RuntimeException('HTTP 500 from server', 500));

        $capturedError = null;
        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->expects($this->once())
            ->method('fire')
            ->willReturnCallback(function ($event, $args, $err) use (&$capturedError) {
                $capturedError = $err;
            });

        $config = $this->makeConfig([
            'sdkKey' => 'failing_key',
            'data' => new ConfigResponseData([]),
        ]);

        $deps = $this->makeDependencies($apiManager, null, $eventManager);
        $cache = new ArrayCache();

        $core = new Core($config, $deps, $cache);
        $this->assertInstanceOf(Core::class, $core);

        // Verify the RuntimeException was wrapped in ConfigFetchException
        $this->assertInstanceOf(ConfigFetchException::class, $capturedError);
        $this->assertStringContainsString('HTTP 500', $capturedError->getMessage());
        $this->assertSame(500, $capturedError->getStatusCode());
    }

    /** @test */
    public function malformedConfigTriggersValidationError(): void
    {
        $badConfig = new ConfigResponseData([
            // Missing account_id and project
        ]);

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->once())
            ->method('getConfig')
            ->willReturn($badConfig);

        $eventManager = $this->createMock(EventManagerInterface::class);
        // Fires Ready event with validation error
        $eventManager->expects($this->once())
            ->method('fire');

        $config = $this->makeConfig([
            'sdkKey' => 'bad_config_key',
            'data' => new ConfigResponseData([]),
        ]);

        $deps = $this->makeDependencies($apiManager, null, $eventManager);
        $cache = new ArrayCache();

        // The ConfigValidationException is caught by initialize()
        $core = new Core($config, $deps, $cache);
        $this->assertInstanceOf(Core::class, $core);
    }

    /** @test */
    public function directDataWithInvalidConfigFiresErrorEvent(): void
    {
        $badConfig = new ConfigResponseData([
            // Missing account_id
            'project' => ['id' => '123'],
        ]);

        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->expects($this->once())
            ->method('fire');

        $config = $this->makeConfig(['data' => $badConfig]);
        $deps = $this->makeDependencies(null, null, $eventManager);
        $cache = new ArrayCache();

        $core = new Core($config, $deps, $cache);
        $this->assertInstanceOf(Core::class, $core);
    }

    /** @test */
    public function customDataRefreshIntervalIsUsed(): void
    {
        $configData = $this->validConfigData();
        $sdkKey = 'custom_ttl_key';

        $cache = new ArrayCache();

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->once())
            ->method('getConfig')
            ->willReturn($configData);

        $config = $this->makeConfig([
            'sdkKey' => $sdkKey,
            'data' => new ConfigResponseData([]),
        ]);

        $deps = $this->makeDependencies($apiManager);

        // Use custom TTL of 600 seconds
        $core = new Core($config, $deps, $cache, 600);

        // The config should be cached
        $cacheKey = 'convert_sdk.config.' . $sdkKey;
        $this->assertTrue($cache->has($cacheKey));
    }

    /** @test */
    public function expiredCacheTriggersFreshHttpFetch(): void
    {
        $configData = $this->validConfigData();
        $sdkKey = 'expiry_test_key';
        $cacheKey = 'convert_sdk.config.' . $sdkKey;

        // Pre-populate cache with TTL=1 second
        $cache = new ArrayCache();
        $cache->set($cacheKey, $configData, 1);

        // Wait for cache to expire
        sleep(2);

        // ApiManager should be called since cache expired
        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->once())
            ->method('getConfig')
            ->willReturn($configData);

        $config = $this->makeConfig([
            'sdkKey' => $sdkKey,
            'data' => new ConfigResponseData([]),
        ]);

        $deps = $this->makeDependencies($apiManager);

        $core = new Core($config, $deps, $cache, 300);

        // Cache should be re-populated with fresh data
        $this->assertTrue($cache->has($cacheKey));
        $this->assertInstanceOf(ConfigResponseData::class, $cache->get($cacheKey));
    }

    /** @test */
    public function sdkKeyWithSpecialCharsProducesHashedCacheKey(): void
    {
        $configData = $this->validConfigData();
        $sdkKey = '10022898/10025986'; // Contains slash — not PSR-16 safe

        $cache = new ArrayCache();

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->once())
            ->method('getConfig')
            ->willReturn($configData);

        $config = $this->makeConfig([
            'sdkKey' => $sdkKey,
            'data' => new ConfigResponseData([]),
        ]);

        $deps = $this->makeDependencies($apiManager);

        $core = new Core($config, $deps, $cache);

        // The key should be hashed since it contains '/'
        $expectedKey = 'convert_sdk.config.' . substr(hash('sha256', $sdkKey), 0, 16);
        $this->assertTrue($cache->has($expectedKey));
    }
}
