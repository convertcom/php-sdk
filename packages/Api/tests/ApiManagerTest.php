<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\ApiManager;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\SystemEvents;
use OpenAPI\Client\Config;
use ConvertSdk\Config\DefaultConfig;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

class ApiManagerTest extends TestCase
{
    private $apiManager;
    private $eventManagerMock;
    private $loggerManagerMock;
    private MockHttpClient $mockHttpClient;
    private Psr17Factory $psr17Factory;
    private $config;

    private const HOST = 'http://localhost';
    private const PORT = 8090;
    private const RELEASE_TIMEOUT = 1000; // in milliseconds
    private const TEST_TIMEOUT = self::RELEASE_TIMEOUT + 1000;
    private const BATCH_SIZE = 5;

    /**
     * Set up the test environment before each test.
     */
    protected function setUp(): void
    {
        // Mock dependencies
        $this->eventManagerMock = $this->createMock(EventManagerInterface::class);
        $this->loggerManagerMock = $this->createMock(LogManagerInterface::class);
        $this->mockHttpClient = new MockHttpClient();
        $this->psr17Factory = new Psr17Factory();

        // Load and prepare test configuration
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $mergedConfig = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig);
        $overrides = [
            'api' => [
                'endpoint' => [
                    'config' => self::HOST . ':' . self::PORT,
                    'track'  => self::HOST . ':' . self::PORT
                ]
            ],
            'events' => [
                'batch_size' => self::BATCH_SIZE,
                'release_interval' => self::RELEASE_TIMEOUT
            ],
            'mapper' => null // Ensure no invalid mapper value
        ];
        $finalConfig = ObjectUtils::objectDeepMerge($mergedConfig, $overrides);
        if (isset($finalConfig['sdkKey'])) {
            unset($finalConfig['sdkKey']);
        }
        $finalConfig["data"] = new ConfigResponseData($finalConfig["data"]);
        $this->config = new Config($finalConfig);

        // Instantiate ApiManager with mock PSR-18 client
        $this->apiManager = new ApiManager(
            $this->config,
            $this->eventManagerMock,
            $this->loggerManagerMock,
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory
        );
        $this->apiManager->setTimeoutEnabled(false);
    }

    /**
     * Test that ApiManager class is defined.
     */
    public function testApiManagerIsDefined(): void
    {
        $this->assertTrue(class_exists(ApiManager::class));
    }

    /**
     * Test that ApiManager can be instantiated with default config.
     */
    public function testApiManagerInstantiationWithDefaultConfig(): void
    {
        $apiManager = new ApiManager(
            null,
            null,
            null,
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory
        );
        $this->assertInstanceOf(ApiManager::class, $apiManager);
    }

    /**
     * Test that ApiManager can be instantiated with provided config and EventManager.
     */
    public function testApiManagerInstantiationWithConfigAndEventManager(): void
    {
        $this->assertInstanceOf(ApiManager::class, $this->apiManager);
    }

    /**
     * Test sending a JSON payload via ApiManager request method.
     */
    public function testRequestSending(): void
    {
        $testPayload = [
            'foo' => 'bar',
            'some' => ['test' => ['data' => 'value']]
        ];

        // Add mock response
        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'application/json'], '{}')
        );

        $result = $this->apiManager->request(
            'POST',
            ['base' => self::HOST . ':' . self::PORT, 'route' => '/test'],
            $testPayload
        );

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['status']);

        // Verify the request that was sent
        $sentRequest = $this->mockHttpClient->getLastRequest();
        $this->assertEquals('POST', $sentRequest->getMethod());
        $this->assertStringContainsString('/test', (string)$sentRequest->getUri());
        $this->assertEquals('application/json', $sentRequest->getHeaderLine('Content-Type'));

        $sentBody = json_decode($sentRequest->getBody()->getContents(), true);
        $this->assertEquals($testPayload, $sentBody);
    }

    /**
     * Test that N enqueued requests are released before timeout.
     */
    public function testEnqueueAndReleaseBeforeTimeout(): void
    {
        $this->markTestSkipped('Timeout-based tests require an event loop or manual simulation in PHP.');
    }

    /**
     * Test that batch_size enqueued requests are released immediately due to size limit.
     */
    public function testEnqueueAndReleaseOnBatchSize(): void
    {
        $requestData = new VisitorTrackingEvents([
            'eventType' => 'bucketing',
            'data' => ['experienceId' => '11', 'variationId' => '12']
        ]);

        // Add mock response for the release request
        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'application/json'], '{}')
        );

        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            $this->apiManager->enqueue("VID$i", $requestData);
        }

        // Verify a request was sent (queue was released)
        $sentRequest = $this->mockHttpClient->getLastRequest();
        $this->assertNotNull($sentRequest);
        $this->assertEquals('POST', $sentRequest->getMethod());
        $this->assertStringContainsString('/track/', (string)$sentRequest->getUri());
    }

    /**
     * Test that an event is fired when queue is released due to batch size.
     */
    public function testEventFiringOnReleaseDueToSize(): void
    {
        $this->apiManager->setTimeoutEnabled(false);

        $requestData = new VisitorTrackingEvents([
            'eventType' => 'bucketing',
            'data' => ['experienceId' => '11', 'variationId' => '12']
        ]);

        // Add mock response
        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'application/json'], '{"data": "ok"}')
        );

        // Expect event to be fired
        $this->eventManagerMock->expects($this->once())
            ->method('fire')
            ->with(
                SystemEvents::ApiQueueReleased,
                $this->callback(function ($args) {
                    return $args['reason'] === 'size' &&
                           isset($args['result']) &&
                           is_array($args['result']) &&
                           isset($args['visitors']) &&
                           count($args['visitors']) === self::BATCH_SIZE;
                })
            );

        // Enqueue batch_size requests
        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            $this->apiManager->enqueue("VID$i", $requestData);
        }
    }

    /**
     * Test that an event is fired when queue is released due to timeout.
     */
    public function testEventFiringOnReleaseDueToTimeout(): void
    {
        $this->markTestSkipped('Timeout-based tests require an event loop or manual simulation in PHP.');
    }

    /**
     * Test that an event is fired when queue is released with a 500 error.
     */
    public function testEventFiringOnReleaseWithError(): void
    {
        $this->apiManager->setTimeoutEnabled(false);

        $requestData = new VisitorTrackingEvents([
            'eventType' => 'bucketing',
            'data' => ['experienceId' => '11', 'variationId' => '12']
        ]);

        // Configure mock client to throw an exception
        $this->mockHttpClient->addException(
            new \Http\Client\Exception\NetworkException('Server error', $this->psr17Factory->createRequest('POST', 'http://localhost'))
        );

        // Expect event to be fired with error
        $this->eventManagerMock->expects($this->once())
            ->method('fire')
            ->with(
                SystemEvents::ApiQueueReleased,
                $this->callback(function ($args) {
                    return $args['reason'] === 'size';
                }),
                $this->callback(function ($err) {
                    return $err instanceof \Exception && $err->getMessage() === 'Server error';
                })
            );

        // Enqueue batch_size requests
        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            $this->apiManager->enqueue("VID$i", $requestData);
        }
    }

    /**
     * Test that getConfig() returns ConfigResponseData on success (AC #8).
     */
    public function testGetConfigReturnsConfigResponseData(): void
    {
        $configPayload = [
            'data' => [
                'account_id' => '999',
                'project' => ['id' => '888', 'key' => 'test-project'],
                'experiences' => [],
                'features' => [],
                'segments' => [],
                'audiences' => [],
                'goals' => [],
                'locations' => [],
            ]
        ];

        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'application/json'], json_encode($configPayload))
        );

        $result = $this->apiManager->getConfig();

        $this->assertInstanceOf(ConfigResponseData::class, $result);
    }

    /**
     * Test that getConfig() throws RuntimeException on HTTP error (AC #8).
     */
    public function testGetConfigThrowsOnHttpError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to fetch config/');

        $this->mockHttpClient->addException(
            new \Http\Client\Exception\NetworkException(
                'Connection refused',
                $this->psr17Factory->createRequest('GET', 'http://localhost')
            )
        );

        $this->apiManager->getConfig();
    }

    /**
     * Test that request() propagates PSR-18 exceptions to callers.
     */
    public function testRequestPropagatesPsr18Exceptions(): void
    {
        $this->expectException(\Psr\Http\Client\ClientExceptionInterface::class);

        $this->mockHttpClient->addException(
            new \Http\Client\Exception\NetworkException(
                'Connection timeout',
                $this->psr17Factory->createRequest('GET', 'http://localhost')
            )
        );

        $this->apiManager->request('GET', ['base' => 'http://localhost', 'route' => '/test']);
    }

    /**
     * Test that request() returns synchronous array (not PromiseInterface) (AC #8).
     */
    public function testRequestReturnsSynchronousArray(): void
    {
        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'application/json'], '{"key": "value"}')
        );

        $result = $this->apiManager->request('GET', ['base' => 'http://localhost', 'route' => '/test']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('statusText', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertEquals(200, $result['status']);
        $this->assertEquals(['key' => 'value'], $result['data']);
    }

    /**
     * Test that getConfig() calls debug() on logger after successful fetch (AC #2).
     */
    public function testGetConfigLogsDebugOnSuccess(): void
    {
        $configPayload = [
            'data' => [
                'account_id' => '999',
                'project' => ['id' => '888', 'key' => 'test-project'],
                'experiences' => [],
                'features' => [],
                'segments' => [],
                'audiences' => [],
                'goals' => [],
                'locations' => [],
            ]
        ];

        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'application/json'], json_encode($configPayload))
        );

        $this->loggerManagerMock->expects($this->atLeastOnce())
            ->method('debug')
            ->with(
                $this->equalTo('ApiManager.getConfig()'),
                $this->callback(function (array $data): bool {
                    return isset($data['endpoint']) &&
                           $data['status'] === 'success' &&
                           array_key_exists('accountId', $data) &&
                           array_key_exists('projectId', $data);
                })
            );

        $this->apiManager->getConfig();
    }

    /**
     * Test that getConfig() calls error() on logger when fetch fails (AC #6).
     */
    public function testGetConfigLogsErrorOnFailure(): void
    {
        $this->mockHttpClient->addException(
            new \Http\Client\Exception\NetworkException(
                'Connection refused',
                $this->psr17Factory->createRequest('GET', 'http://localhost')
            )
        );

        $this->loggerManagerMock->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->equalTo('ApiManager.getConfig()'),
                $this->callback(function (array $data): bool {
                    return isset($data['endpoint']) &&
                           $data['status'] === 'error' &&
                           isset($data['error']);
                })
            );

        $this->expectException(\RuntimeException::class);
        $this->apiManager->getConfig();
    }

    /**
     * Test that getConfig() logs error on non-2xx HTTP status (AC #6).
     */
    public function testGetConfigLogsErrorOnBadStatus(): void
    {
        $this->mockHttpClient->addResponse(
            new Response(500, ['Content-Type' => 'application/json'], '{"error": "internal"}')
        );

        $this->loggerManagerMock->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->equalTo('ApiManager.getConfig()'),
                $this->callback(function (array $data): bool {
                    return isset($data['endpoint']) &&
                           $data['status'] === 'error' &&
                           $data['httpStatus'] === 500;
                })
            );

        $this->expectException(\RuntimeException::class);
        $this->apiManager->getConfig();
    }
}
