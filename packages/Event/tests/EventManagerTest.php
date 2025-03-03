<?php
/**
 * Convert PHP SDK
 * EventManager Module Unit Tests
 */

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\EventManager;
use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Enums\SystemEvents;

class EventManagerTest extends TestCase
{
    /**
     * @var EventManager
     */
    protected $eventManager;

    protected function setUp(): void
    {
        // For tests, we load an empty configuration (or you can load default config if needed)
        // and pass an empty dependency array.
        $this->eventManager = new EventManager([], []);
    }

    public function testShouldExposeEventManager()
    {
        $this->assertTrue(class_exists(EventManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfEventManagerInstance()
    {
        $em = new EventManager([], []);
        $this->assertInstanceOf(EventManager::class, $em);
        $reflection = new \ReflectionClass($em);
        $this->assertEquals('EventManager', $reflection->getShortName());
    }

    public function testShouldSuccessfullyCreateNewEventManagerInstanceWithDefaultConfig()
    {
        $em = new EventManager([], []);
        $this->assertInstanceOf(EventManager::class, $em);
        $reflection = new \ReflectionClass($em);
        $this->assertEquals('EventManager', $reflection->getShortName());
    }

    public function testShouldCreateNewEventManagerInstance()
    {
        // Load test configuration from JSON file.
        $configPath = __DIR__ . '/test-config.json';
        $config = json_decode(file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('Invalid JSON in test-config.json: ' . json_last_error_msg());
        }
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
        // Fire EVENT2 with deferred = true.
        $this->eventManager->fire('EVENT2', ['deferred' => true], null, true);
        // Now subscribe to EVENT2; in our implementation, on() immediately fires deferred events.
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
