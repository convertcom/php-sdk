<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Config;

use ConvertSdk\Cache\ArrayCache;
use ConvertSdk\Core;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Exception\ConfigFetchException;
use ConvertSdk\Exception\ConfigValidationException;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\ExperienceManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

    #[Test]
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

        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $deps['loggerManager'],
        );
        $this->assertInstanceOf(Core::class, $core);
    }

    #[Test]
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

        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $deps['loggerManager'],
        );
        $this->assertInstanceOf(Core::class, $core);
    }

    #[Test]
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

        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $deps['loggerManager'],
        );
        $this->assertInstanceOf(Core::class, $core);
    }

    #[Test]
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

        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            600,
            $deps['loggerManager'],
        );

        // Verify cache was populated
        $cached = $cache->get($cacheKey);
        $this->assertInstanceOf(ConfigResponseData::class, $cached);
        $this->assertSame('10022898', $cached->getAccountId());
    }

    #[Test]
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

        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $deps['loggerManager'],
        );
        $this->assertInstanceOf(Core::class, $core);

        // Verify the RuntimeException was wrapped in ConfigFetchException
        $this->assertInstanceOf(ConfigFetchException::class, $capturedError);
        $this->assertStringContainsString('HTTP 500', $capturedError->getMessage());
        $this->assertSame(500, $capturedError->getStatusCode());
    }

    #[Test]
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
        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $deps['loggerManager'],
        );
        $this->assertInstanceOf(Core::class, $core);
    }

    #[Test]
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

        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $deps['loggerManager'],
        );
        $this->assertInstanceOf(Core::class, $core);
    }

    #[Test]
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
        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            600,
            $deps['loggerManager'],
        );

        // The config should be cached
        $cacheKey = 'convert_sdk.config.' . $sdkKey;
        $this->assertTrue($cache->has($cacheKey));
    }

    #[Test]
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

        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            300,
            $deps['loggerManager'],
        );

        // Cache should be re-populated with fresh data
        $this->assertTrue($cache->has($cacheKey));
        $this->assertInstanceOf(ConfigResponseData::class, $cache->get($cacheKey));
    }

    #[Test]
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

        $core = new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $deps['apiManager'],
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $deps['loggerManager'],
        );

        // The key should be hashed since it contains '/'
        $expectedKey = 'convert_sdk.config.' . substr(hash('sha256', $sdkKey), 0, 16);
        $this->assertTrue($cache->has($expectedKey));
    }
}
