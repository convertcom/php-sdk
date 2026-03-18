<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use ConvertSdk\Enums\SystemEvents;

/**
 * Interface EventManagerInterface
 *
 * Defines the methods for an Event Manager.
 */
interface EventManagerInterface
{
    /**
     * Registers a callback function for the given event.
     *
     * @param SystemEvents|string $event The event name or constant.
     * @param callable $fn A callback function that receives two parameters: $args and $err.
     * @return void
     */
    public function on($event, callable $fn): void;

    /**
     * Fires an event, optionally with arguments and an error.
     *
     * @param SystemEvents|string $event The event name or constant.
     * @param array $args Optional associative array of arguments.
     * @param mixed $err Optional error or exception.
     * @param bool $deferred Optional flag indicating if the event should be deferred.
     * @return void
     */
    public function fire($event, array $args = [], $err = null, bool $deferred = false): void;

    /**
     * Removes all listeners associated with the given event.
     *
     * @param string $event The event name.
     * @return void
     */
    public function removeListeners($event): void;
}
