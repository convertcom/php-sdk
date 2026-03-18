<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Interfaces\EventManagerInterface;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Logger\Interfaces\LogManagerInterface;
use OpenAPI\Client\Config;

class EventManager implements EventManagerInterface
{
    /**
     * @var array Listeners indexed by event name.
     */
    private $_listeners = [];

    /**
     * @var array Deferred events indexed by event name.
     */
    private $_deferred = [];

    /**
     * @var LogManagerInterface|null Optional logger manager.
     */
    private $_loggerManager;

    /**
     * @var callable Mapper function.
     */
    private $_mapper;

    /**
     * Constructor.
     *
     * @param Config|null $config Optional configuration object.
     * @param array $dependencies Optional dependencies array. Expected key: 'loggerManager'.
     */
    public function __construct(?Config $config = null, array $dependencies = [])
    {
        $this->_listeners = [];
        $this->_deferred = [];
        $this->_loggerManager = isset($dependencies['loggerManager']) ? $dependencies['loggerManager'] : null;

        // Use config mapper if provided; otherwise, use identity function.
        $this->_mapper = ($config !== null) ? $config->getMapper() : null;
        if (!is_callable($this->_mapper)) {
            $this->_mapper = function ($value) {
                return $value;
            };
        }
    }

    /**
     * Registers a callback function for the given event.
     *
     * @param SystemEvents|string $event The event name or constant.
     * @param callable $fn Callback function receiving ($args, $err).
     * @return void
     */
    public function on($event, callable $fn): void
    {
        $key = $event instanceof \BackedEnum ? $event->value : $event;
        if (!isset($this->_listeners[$key])) {
            $this->_listeners[$key] = [];
        }
        $this->_listeners[$key][] = $fn;

        // Log the registration if a logger is available.
        if ($this->_loggerManager !== null && method_exists($this->_loggerManager, 'trace')) {
            $this->_loggerManager->trace('EventManager.on()', ['event' => $key]);
        }

        // If there is a deferred event for this event, fire it now.
        if (array_key_exists($key, $this->_deferred)) {
            $deferredData = $this->_deferred[$key];
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
    public function fire($event, $args = null, $err = null, bool $deferred = false): void
    {
        $key = $event instanceof \BackedEnum ? $event->value : $event;
        if ($this->_loggerManager !== null && method_exists($this->_loggerManager, 'debug')) {
            $mapped = call_user_func($this->_mapper, [
                'event' => $key,
                'args' => $args,
                'err' => $err,
                'deferred' => $deferred
            ]);
            $this->_loggerManager->debug('EventManager.fire()', $mapped);
        }

        // Iterate through registered listeners for the event.
        $listeners = $this->_listeners[$key] ?? [];
        foreach ($listeners as $fn) {
            if (is_callable($fn)) {
                try {
                    call_user_func($fn, call_user_func($this->_mapper, $args), $err);
                } catch (\Throwable $ex) {
                    if ($this->_loggerManager !== null && method_exists($this->_loggerManager, 'error')) {
                        $this->_loggerManager->error('EventManager.fire()', $ex);
                    }
                }
            }
        }

        // If deferred is true and no deferred record exists yet, store it.
        if ($deferred && !array_key_exists($key, $this->_deferred)) {
            $this->_deferred[$key] = ['args' => $args, 'err' => $err];
        }
    }

    /**
     * Removes all listeners (and deferred data) for the specified event.
     *
     * @param string $event The event name.
     * @return void
     */
    public function removeListeners($event): void
    {
        $key = $event instanceof \BackedEnum ? $event->value : $event;
        if (array_key_exists($key, $this->_listeners)) {
            unset($this->_listeners[$key]);
        }
        if (array_key_exists($key, $this->_deferred)) {
            unset($this->_deferred[$key]);
        }
    }
}