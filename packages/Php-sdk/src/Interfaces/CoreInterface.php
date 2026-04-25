<?php

declare(strict_types=1);

namespace ConvertSdk\Interfaces;

/**
 * Core SDK interface defining the public API for SDK consumers.
 */
interface CoreInterface
{
    /**
     * Create a visitor context.
     *
     * @param string $visitorId A unique visitor identifier
     * @param array<string, mixed>|null $visitorAttributes Optional associative array for audience/segments targeting
     * @return ContextInterface|null The visitor context, or null if SDK is not initialized
     * @throws \ConvertSdk\Exception\InvalidArgumentException If visitorId is empty
     */
    public function createContext(string $visitorId, ?array $visitorAttributes = null): ?ContextInterface;

    /**
     * Attach an event handler to a system event.
     *
     * @param string $event Event name (SystemEvents value)
     * @param callable $fn Callback function which will be fired
     * @return void
     */
    public function on(string $event, callable $fn): void;

    /**
     * Check if the SDK is fully initialized and ready to use.
     *
     * @return bool True if the SDK is initialized with valid config data
     */
    public function isReady(): bool;

    /**
     * Check if the system is ready.
     *
     * @deprecated Use isReady() instead
     * @return bool
     */
    public function onReady(): bool;

    /**
     * Flush all queued tracking events immediately.
     *
     * @return void
     */
    public function flush(): void;
}
