<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Event;

use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;

final class EventManager implements EventManagerInterface
{
    /**
     * @var array<string, array<int, callable>> Listeners indexed by event name.
     */
    private array $listeners = [];

    /**
     * @var array<string, array{args: mixed, err: mixed}> Deferred events indexed by event name.
     */
    private array $deferred = [];

    /**
     * @var \Closure Mapper function for data transformation.
     */
    private \Closure $mapper;

    /**
     * Constructor.
     *
     * @param LogManagerInterface|null $loggerManager Optional logger manager.
     * @param callable|null $mapper Optional mapper function for data transformation.
     */
    public function __construct(
        private readonly ?LogManagerInterface $loggerManager = null,
        ?callable $mapper = null,
    ) {
        $this->mapper = $mapper instanceof \Closure
            ? $mapper
            : ($mapper !== null
                ? \Closure::fromCallable($mapper)
                : static fn (mixed $value): mixed => $value);
    }

    /**
     * Registers a callback function for the given event.
     *
     * @param SystemEvents|string $event The event name or constant.
     * @param callable $fn Callback function receiving ($args, $err).
     * @return void
     */
    public function on(SystemEvents|string $event, callable $fn): void
    {
        $key = $event instanceof \BackedEnum ? $event->value : $event;
        if (!isset($this->listeners[$key])) {
            $this->listeners[$key] = [];
        }
        $this->listeners[$key][] = $fn;

        // Log the registration if a logger is available.
        $this->loggerManager?->trace('EventManager.on()', ['event' => $key]);

        // If there is a deferred event for this event, fire it now.
        if (array_key_exists($key, $this->deferred)) {
            $deferredData = $this->deferred[$key];
            $this->fire($key, $deferredData['args'], $deferredData['err']);
        }
    }

    /**
     * Fires an event with optional arguments and error.
     *
     * @param SystemEvents|string $event The event name or constant.
     * @param array|mixed $args Optional arguments.
     * @param mixed $err Optional error.
     * @param bool $deferred Whether to store the event for later listeners.
     * @return void
     */
    public function fire(SystemEvents|string $event, mixed $args = null, mixed $err = null, bool $deferred = false): void
    {
        $key = $event instanceof \BackedEnum ? $event->value : $event;
        if ($this->loggerManager !== null) {
            $mapped = ($this->mapper)([
                'event' => $key,
                'args' => $args,
                'err' => $err,
                'deferred' => $deferred,
            ]);
            $this->loggerManager->debug('EventManager.fire()', $mapped);
        }

        // Iterate through registered listeners for the event.
        $listeners = $this->listeners[$key] ?? [];
        foreach ($listeners as $fn) {
            try {
                $fn(($this->mapper)($args), $err);
            } catch (\Throwable $ex) {
                $this->loggerManager?->error('EventManager.fire()', $ex);
            }
        }

        // If deferred is true and no deferred record exists yet, store it.
        if ($deferred && !array_key_exists($key, $this->deferred)) {
            $this->deferred[$key] = ['args' => $args, 'err' => $err];
        }
    }

    /**
     * Removes all listeners (and deferred data) for the specified event.
     *
     * @param string $event The event name.
     * @return void
     */
    public function removeListeners(SystemEvents|string $event): void
    {
        $key = $event instanceof \BackedEnum ? $event->value : $event;
        if (array_key_exists($key, $this->listeners)) {
            unset($this->listeners[$key]);
        }
        if (array_key_exists($key, $this->deferred)) {
            unset($this->deferred[$key]);
        }
    }
}
