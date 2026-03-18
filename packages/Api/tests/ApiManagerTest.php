<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\ApiManager;
use ConvertSdk\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Utils\HttpClient;
use ConvertSdk\Utils\HttpResponseType;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\SystemEvents;
use OpenAPI\Client\Config;
use ConvertSdk\Config\DefaultConfig;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;

class ApiManagerTest extends TestCase
{
    private $apiManager;
    private $eventManagerMock;
    private $loggerManagerMock;
    private $httpClientMock;
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
        $this->httpClientMock = $this->createMock(HttpClient::class);

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

        // Instantiate ApiManager with mocks
        $this->apiManager = new ApiManager($this->config, $this->eventManagerMock, $this->loggerManagerMock);
        $this->apiManager->setTimeoutEnabled(false);
        // Inject mocked HttpClient using reflection (assuming it's a private property)
        $reflection = new \ReflectionClass($this->apiManager);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->apiManager, $this->httpClientMock);

        $eventManagerProperty = $reflection->getProperty('eventManager');
        $eventManagerProperty->setAccessible(true);
        $eventManagerProperty->setValue($this->apiManager, $this->eventManagerMock);
        
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
        $apiManager = new ApiManager();
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
    
        // Mock HTTP client response
        $response = new Response(200, ['Content-Type' => 'application/json'], '{}');
        // Create a pre-resolved promise
        $promise = new Promise();
        $promise->resolve($response); // Resolve it immediately
    
        // Mock the request method with a single config array
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with($this->callback(function ($config) use ($testPayload) {
                return $config['method'] === 'POST' &&
                       $config['baseURL'] === self::HOST . ':' . self::PORT &&
                       $config['path'] === '/test' &&
                       $config['data'] === $testPayload &&
                       $config['headers']['Content-Type'] === 'application/json' &&
                       $config['responseType'] === HttpResponseType::JSON;
            }))
            ->willReturn($promise);
    
        $result = $this->apiManager->request('POST', ['base' => self::HOST . ':' . self::PORT, 'route' => '/test'], $testPayload);
        $this->assertInstanceOf(Promise::class, $result);
        $result->wait(); // Ensure the promise resolves
    }

    /**
     * Test that N enqueued requests are released before timeout.
     */
    public function testEnqueueAndReleaseBeforeTimeout(): void
    {
        $this->markTestSkipped('Timeout-based tests require an event loop or manual simulation in PHP.');

        $this->apiManager->setTimeoutEnabled(true);
    
        $N = self::BATCH_SIZE - 2;
        $requestData = new VisitorTrackingEvents([
            'eventType' => 'bucketing',
            'data' => ['experienceId' => '11', 'variationId' => '12']
        ]);
    
        for ($i = 1; $i <= $N; $i++) {
            $this->apiManager->enqueue("VID$i", $requestData);
        }
    
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->callback(function ($config) use ($N) {
                    $data = $config['data'];
                    return $config['method'] === 'POST' &&
                           strpos($config['path'], '/track/') === 0 &&
                           is_array($data['visitors']) &&
                           count($data['visitors']) === $N;
                })
            )
            ->willReturn(new Promise(function ($resolve) {
                $resolve(new Response(200, ['Content-Type' => 'application/json'], '{}'));
            }));
    
        $promise = $this->apiManager->releaseQueue('timeout');
        $promise->wait();
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
    
        $accountId = $this->config->getData() ? $this->config->getData()->getAccountId() : '';
        $project = $this->config->getData() ? $this->config->getData()->getProject() : null;
        $projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
        $sdkKey = $this->config->getSdkKey() ?: "{$accountId}/{$projectId}";
        $expectedPath = "/track/{$sdkKey}";
    
        $promise = new Promise();
        $promise->resolve(new Response(200, ['Content-Type' => 'application/json'], '{}'));
    
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with($this->callback(function ($config) use ($expectedPath) {
                $data = $config['data'];
                var_dump([
                    'method' => $config['method'],
                    'baseURL' => $config['baseURL'],
                    'path' => $config['path'],
                    'visitors_count' => count($data['visitors']),
                    'expected_batch_size' => self::BATCH_SIZE
                ]);
                return $config['method'] === 'POST' &&
                       $config['baseURL'] === self::HOST . ':' . self::PORT &&
                       $config['path'] === $expectedPath &&
                       isset($data['visitors']) &&
                       count($data['visitors']) === self::BATCH_SIZE;
            }))
            ->willReturn($promise);
    
        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            echo "Enqueuing VID$i for testEnqueueAndReleaseOnBatchSize\n";
            $this->apiManager->enqueue("VID$i", $requestData);
        }
    }

    /**
     * Test that an event is fired when queue is released due to batch size.
     */
    public function testEventFiringOnReleaseDueToSize(): void
    {
        // Disable timeout to ensure size-based release
        $this->apiManager->setTimeoutEnabled(false);
    
        $requestData = new VisitorTrackingEvents([
            'eventType' => 'bucketing',
            'data' => ['experienceId' => '11', 'variationId' => '12']
        ]);
        $serverResponse = ['data' => 'ok'];
    
        // Ensure eventManagerMock is injected
        $reflection = new \ReflectionClass($this->apiManager);
        $eventManagerProperty = $reflection->getProperty('eventManager');
        $eventManagerProperty->setAccessible(true);
        $eventManagerProperty->setValue($this->apiManager, $this->eventManagerMock);
    
        // Expect event to be fired
        $this->eventManagerMock->expects($this->once())
            ->method('fire')
            ->with(
                SystemEvents::ApiQueueReleased,
                $this->callback(function ($args) use ($serverResponse) {
                    // Adjust to match actual $result structure (Response object)
                    $resultData = json_decode($args['result']->getBody()->getContents(), true);
                    return $args['reason'] === 'size' &&
                           $resultData === $serverResponse &&
                           isset($args['visitors']) &&
                           count($args['visitors']) === self::BATCH_SIZE;
                }),
                null
            );
    
        // Mock HTTP client response with synchronous resolution
        $promise = new Promise();
        $response = new Response(200, ['Content-Type' => 'application/json'], json_encode($serverResponse));
        $promise->resolve($response);
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($promise);
    
        // Enqueue batch_size requests
        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            $this->apiManager->enqueue("VID$i", $requestData);
        }
    
        // Process promise queue to trigger 'then' callback
        \GuzzleHttp\Promise\Utils::queue()->run();
    }

    /**
     * Test that an event is fired when queue is released due to timeout.
     */
    public function testEventFiringOnReleaseDueToTimeout(): void
    {
        $this->markTestSkipped('Timeout-based tests require an event loop or manual simulation in PHP.');

        $N = self::BATCH_SIZE - 2;
        $requestData = new VisitorTrackingEvents([
            'eventType' => 'bucketing',
            'data' => ['experienceId' => '11', 'variationId' => '12']
        ]);
        $serverResponse = ['data' => 'ok'];

        // Expect event to be fired
        $this->eventManagerMock->expects($this->once())
            ->method('fire')
            ->with(
                SystemEvents::ApiQueueReleased,
                $this->callback(function ($args) use ($serverResponse) {
                    return $args['reason'] === 'timeout' && $args['result']['data'] === $serverResponse;
                }),
                null
            );

        // Mock HTTP client response
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn(new Promise(function () use ($serverResponse) {
                $this->resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode($serverResponse)));
            }));

        // Enqueue N requests
        for ($i = 1; $i <= $N; $i++) {
            $this->apiManager->enqueue("VID$i", $requestData);
        }

        // Simulate timeout
        sleep(self::RELEASE_TIMEOUT / 1000);
        $this->apiManager->releaseQueue('timeout');
    }

    /**
     * Test that an event is fired when queue is released with a 500 error.
     */
    public function testEventFiringOnReleaseWithError(): void
    {
        // Disable timeout to ensure size-based release
        $this->apiManager->setTimeoutEnabled(false);
    
        $requestData = new VisitorTrackingEvents([
            'eventType' => 'bucketing',
            'data' => ['experienceId' => '11', 'variationId' => '12']
        ]);
    
        // Ensure eventManagerMock is injected
        $reflection = new \ReflectionClass($this->apiManager);
        $eventManagerProperty = $reflection->getProperty('eventManager');
        $eventManagerProperty->setAccessible(true);
        $eventManagerProperty->setValue($this->apiManager, $this->eventManagerMock);
    
        // Expect event to be fired with error
        $this->eventManagerMock->expects($this->once())
            ->method('fire')
            ->with(
                SystemEvents::ApiQueueReleased,
                $this->callback(function ($args) {
                    return $args['reason'] === 'size';
                }),
                $this->callback(function ($err) {
                    return $err instanceof \Exception && $err->getCode() === 500 && $err->getMessage() === 'Server error';
                })
            );
    
        // Mock HTTP client error response with synchronous rejection
        $promise = new Promise();
        $exception = new \Exception('Server error', 500);
        $promise->reject($exception);
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($promise);
    
        // Enqueue batch_size requests
        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            $this->apiManager->enqueue("VID$i", $requestData);
        }
    
        // Process promise queue to trigger rejection handler
        \GuzzleHttp\Promise\Utils::queue()->run();
    }
}