<?php
declare(strict_types=1);

require_once __DIR__ . '/Helpers/TestableApiManager.php';


use PHPUnit\Framework\TestCase;
use ConvertSdk\Api\ApiManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Utils\ObjectUtils;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Class ApiManagerTest
 *
 * Mimics the TypeScript tests for the API Manager.
 */
final class ApiManagerTest extends TestCase
{
    private string $host = 'http://localhost';
    private int $port = 8090;
    private int $releaseTimeout = 1000; // in milliseconds
    private int $batchSize = 5;
    
    // Update test configuration: wrap the account and project info in a 'data' key.
    private array $testConfig = [
        'data' => [
            'account_id' => '100414055',
            'project' => ['id' => '100415443'],
        ],
    ];

    /**
     * Get a merged configuration array (test config, default config, and overrides).
     *
     * @return array
     */
    private function getConfiguration(): array
    {
        $defaultConfig = []; // Or use DefaultConfig::getDefault() if available
        $overrides = [
            'api' => [
                'endpoint' => [
                    'config' => $this->host . ':' . $this->port,
                    'track'  => $this->host . ':' . $this->port,
                ],
            ],
            'events' => [
                'batch_size' => $this->batchSize,
                'release_interval' => $this->releaseTimeout,
            ],
            // For testing, we set tracking enabled.
            'network' => [
                'tracking' => true,
                'source'   => 'php-sdk',
            ],
        ];
        return ObjectUtils::objectDeepMerge($this->testConfig, $defaultConfig, $overrides);
    }

    public function testApiManagerIsDefined(): void
    {
        $this->assertTrue(class_exists(ApiManager::class), 'ApiManager class should exist.');
    }

    public function testApiManagerConstructorName(): void
    {
        $reflection = new ReflectionClass(ApiManager::class);
        $this->assertEquals('ApiManager', $reflection->getShortName(), 'Constructor name should be "ApiManager".');
    }

    public function testDefaultApiManagerInstance(): void
    {
        $apiManager = new ApiManager([]);
        $this->assertInstanceOf(ApiManager::class, $apiManager, 'Should be an instance of ApiManager.');
    }

    public function testApiManagerInstanceWithConfiguration(): void
    {
        $configuration = $this->getConfiguration();
        $eventManager = new EventManager($configuration);
        $apiManager = new ApiManager($configuration, ['eventManager' => $eventManager]);
        $this->assertInstanceOf(ApiManager::class, $apiManager, 'ApiManager instance created with dependencies.');
    }

    public function testApiManagerRequest(): void
    {
        // Use the TestableApiManager to simulate a request.
        $configuration = $this->getConfiguration();
        $apiManager = new TestableApiManager($configuration);
        
        $testPayload = [
            'foo'  => 'bar',
            'some' => [
                'test' => [
                    'data' => 'value'
                ]
            ]
        ];
        // Simulate a POST request and expect the payload to be echoed back.
        $promise = $apiManager->request('post', [
            'base'  => $this->host . ':' . $this->port,
            'route' => '/test'
        ], $testPayload);
        $response = $promise->wait();
        $this->assertEquals($testPayload, $response['data'], 'The test payload should match the response.');
    }

    public function testEnqueueRequestsReleaseBySize(): void
    {
        $configuration = $this->getConfiguration();
        $apiManager = new TestableApiManager($configuration);
        // Enqueue events until reaching batch size.
        for ($i = 0; $i < $this->batchSize; $i++) {
            $apiManager->enqueue("visitor1", ['event' => "event$i"]);
        }
        // The overridden releaseQueue should have been called automatically.
        $this->assertTrue($apiManager->released, 'Queue should be released when batch size is reached.');
    }

    public function testEnqueueRequestsReleaseByTimeout(): void
    {
        $configuration = $this->getConfiguration();
        $apiManager = new TestableApiManager($configuration);
        // Enqueue a single event.
        $apiManager->enqueue("visitor1", ['event' => "event1"]);
        // Manually trigger the startQueue (which is overridden to release immediately).
        $apiManager->startQueue();
        $this->assertTrue($apiManager->released, 'Queue should be released when timeout occurs.');
    }

    public function testEventFiringOnQueueRelease(): void
    {
        $configuration = $this->getConfiguration();
        $eventManagerMock = $this->createMock(EventManager::class);
        $eventManagerMock->expects($this->once())
            ->method('fire')
            ->with(
                $this->equalTo(SystemEvents::API_QUEUE_RELEASED),
                $this->arrayHasKey('reason')
            );

        $apiManager = new TestableApiManager($configuration, ['eventManager' => $eventManagerMock]);
        for ($i = 0; $i < $this->batchSize; $i++) {
            $apiManager->enqueue("visitor1", ['event' => "event$i"]);
        }
        // The mock's expectation verifies that fire() was called only once.
    }
}
