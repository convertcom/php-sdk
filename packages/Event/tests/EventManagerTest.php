<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * EventManager Module Unit Tests
 */

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Enums\SystemEvents;

class EventManagerTest extends TestCase
{
    protected EventManager $eventManager;

    protected function setUp(): void
    {
        $this->eventManager = new EventManager();
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(EventManager::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testImplementsEventManagerInterface(): void
    {
        $this->assertInstanceOf(EventManagerInterface::class, $this->eventManager);
    }

    public function testConstructorWithDefaults(): void
    {
        $em = new EventManager();
        $this->assertInstanceOf(EventManager::class, $em);
        $reflection = new \ReflectionClass($em);
        $this->assertEquals('EventManager', $reflection->getShortName());
    }

    public function testConstructorWithMapper(): void
    {
        $mapper = static fn (mixed $value): mixed => $value;
        $em = new EventManager(mapper: $mapper);
        $this->assertInstanceOf(EventManager::class, $em);
    }

    public function testMapperTransformsArgsBeforePassingToListener(): void
    {
        $mapper = static fn (mixed $value): mixed => is_array($value) ? array_merge($value, ['mapped' => true]) : $value;
        $em = new EventManager(mapper: $mapper);

        $receivedArgs = null;
        $em->on('TEST', function ($args, $err) use (&$receivedArgs) {
            $receivedArgs = $args;
        });
        $em->fire('TEST', ['original' => true]);

        $this->assertIsArray($receivedArgs);
        $this->assertTrue($receivedArgs['original']);
        $this->assertTrue($receivedArgs['mapped']);
    }

    public function testShouldSubscribeToEventAndBeFiredWithProvidedDataAndNoErrors(): void
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

    public function testShouldNotBeFiredBecauseEventListenersAreRemoved(): void
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

    public function testDeferredEventListenerShouldBeFiredEvenIfSubscribedAfterTheEvent(): void
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

    public function testDeferredReplayFiresAllRegisteredListeners(): void
    {
        $earlyCallCount = 0;
        $lateCallCount = 0;

        // Register early listener before the deferred fire
        $this->eventManager->on('DEFERRED', function ($args, $err) use (&$earlyCallCount) {
            $earlyCallCount++;
        });

        // Fire deferred — early listener fires once here
        $this->eventManager->fire('DEFERRED', ['data' => 1], null, true);
        $this->assertEquals(1, $earlyCallCount, 'Early listener fires on original fire()');

        // Register late listener — triggers deferred replay of ALL listeners
        $this->eventManager->on('DEFERRED', function ($args, $err) use (&$lateCallCount) {
            $lateCallCount++;
        });

        // Deferred replay fires ALL listeners (JS SDK parity behavior)
        $this->assertEquals(2, $earlyCallCount, 'Early listener fires again on deferred replay');
        $this->assertEquals(1, $lateCallCount, 'Late listener fires on deferred replay');
    }

    public function testShouldSubscribeToEventAndBeFiredWithErrorProvided(): void
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

    public function testTenListenersOnSingleEventFireCorrectly(): void
    {
        $callCounts = array_fill(0, 10, 0);
        for ($i = 0; $i < 10; $i++) {
            $idx = $i;
            $this->eventManager->on('MULTI_EVENT', function ($args, $err) use (&$callCounts, $idx) {
                $callCounts[$idx]++;
            });
        }

        $this->eventManager->fire('MULTI_EVENT', ['test' => 'data']);

        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals(1, $callCounts[$i], "Listener $i should have been called exactly once");
        }
    }

    public function testSystemEventsEnumUsedForOnAndFire(): void
    {
        $called = 0;
        $this->eventManager->on(SystemEvents::Ready, function ($args, $err) use (&$called) {
            $called++;
        });
        $this->eventManager->fire(SystemEvents::Ready, ['status' => 'ok']);
        $this->assertEquals(1, $called);
    }

    /**
     * @group performance
     */
    public function testPerformanceUnder1msFor10Listeners(): void
    {
        $em = new EventManager();
        for ($i = 0; $i < 10; $i++) {
            $em->on('PERF_EVENT', function ($args, $err) {
                // minimal no-op listener
            });
        }

        // Warm up to avoid cold-start measurement skew
        $em->fire('PERF_EVENT', ['warmup' => true]);

        // Measure over multiple iterations and take the median
        $timings = [];
        for ($run = 0; $run < 10; $run++) {
            $start = hrtime(true);
            $em->fire('PERF_EVENT', ['data' => 'value']);
            $timings[] = (hrtime(true) - $start) / 1_000_000;
        }
        sort($timings);
        $median = $timings[4]; // median of 10

        $this->assertLessThan(1.0, $median, "Median event fire with 10 listeners should complete in <1ms, took {$median}ms");
    }

    public function testExceptionInListenerDoesNotBreakOtherListeners(): void
    {
        $secondCalled = false;
        $this->eventManager->on('ERROR_EVENT', function () {
            throw new \RuntimeException('listener error');
        });
        $this->eventManager->on('ERROR_EVENT', function () use (&$secondCalled) {
            $secondCalled = true;
        });

        $this->eventManager->fire('ERROR_EVENT', []);
        $this->assertTrue($secondCalled, 'Second listener should still fire after first throws');
    }
}
