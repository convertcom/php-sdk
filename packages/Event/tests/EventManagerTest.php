<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * EventManager Module Unit Tests
 */

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\EventManager;
use ConvertSdk\Enums\SystemEvents;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;

class EventManagerTest extends TestCase
{
    /**
     * @var EventManager
     */
    protected $eventManager;

    protected function setUp(): void
    {
        // Minimal Config instance for setup
        $config = new Config(['environment' => 'test', 'sdkKey' => 'test-key']);
        $this->eventManager = new EventManager($config, []);
    }

    public function testShouldExposeEventManager()
    {
        $this->assertTrue(class_exists(EventManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfEventManagerInstance()
    {
        $config = new Config(['environment' => 'test', 'sdkKey' => 'test-key']);
        $em = new EventManager($config, []);
        $this->assertInstanceOf(EventManager::class, $em);
        $reflection = new \ReflectionClass($em);
        $this->assertEquals('EventManager', $reflection->getShortName());
    }

    public function testShouldSuccessfullyCreateNewEventManagerInstanceWithDefaultConfig()
    {
        // Mimic TypeScript's empty config
        $config = new Config(['environment' => 'test', 'sdkKey' => 'test-key']); // Adjust based on Config requirements
        $em = new EventManager($config, []);
        $this->assertInstanceOf(EventManager::class, $em);
        $reflection = new \ReflectionClass($em);
        $this->assertEquals('EventManager', $reflection->getShortName());
    }

    public function testShouldCreateNewEventManagerInstance()
    {
        // Load test configuration from JSON file
        $configPath = __DIR__ . '/test-config.json';
        $configuration = json_decode(file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('Invalid JSON in test-config.json: ' . json_last_error_msg());
        }
        // Ensure required fields (adjust based on actual Config constructor)
        $configuration['environment'] = $configuration['environment'] ?? 'test';
        if (!isset($configuration['sdkKey']) && !isset($configuration['data'])) {
            $configuration['sdkKey'] = 'test-key';
        }
        if (isset($configuration['data'])) {
            $configuration['data'] = new ConfigResponseData($configuration['data']);
        }
        $config = new Config($configuration);
        $em = new EventManager($config, []);
        $this->assertInstanceOf(EventManager::class, $em);
        $reflection = new \ReflectionClass($em);
        $this->assertEquals('EventManager', $reflection->getShortName());
    }

    public function testShouldSubscribeToEventAndBeFiredWithProvidedDataAndNoErrors()
    {
        $args = [
            'foo' => 'bar',
            'some' => [
                'test' => [
                    'data' => 'value'
                ]
            ]
        ];
        $called = 0;
        $callback = function ($inputArgs, $err) use ($args, &$called) {
            $this->assertEquals($args, $inputArgs);
            $this->assertNull($err);
            $called++;
        };
        $this->eventManager->on('EVENT1', $callback);
        $this->eventManager->fire('EVENT1', $args);
        $this->assertEquals(1, $called);
    }

    public function testShouldNotBeFiredBecauseEventListenersAreRemoved()
    {
        $called = 0;
        $callback = function ($inputArgs, $err) use (&$called) {
            $called++;
        };
        $this->eventManager->on('EVENT2', $callback);
        $this->eventManager->removeListeners('EVENT2');
        $this->eventManager->fire('EVENT2', []);
        $this->assertEquals(0, $called);
    }

    public function testDeferredEventListenerShouldBeFiredEvenIfSubscribedAfterTheEvent()
    {
        $called = 0;
        $callback = function ($inputArgs, $err) use (&$called) {
            $this->assertNull($err);
            $called++;
        };
        $this->eventManager->fire('EVENT2', ['deferred' => true], null, true);
        $this->eventManager->on('EVENT2', $callback);
        $this->assertEquals(1, $called);
    }

    public function testShouldSubscribeToEventAndBeFiredWithErrorProvided()
    {
        $called = 0;
        $callback = function ($inputArgs, $err) use (&$called) {
            $this->assertInstanceOf(\Error::class, $err);
            $this->assertNull($inputArgs);
            $called++;
        };
        $this->eventManager->on('EVENT3', $callback);
        $this->eventManager->fire('EVENT3', null, new \Error('Custom error message'));
        $this->assertEquals(1, $called);
    }
}