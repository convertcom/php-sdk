<?php
namespace ConvertSdk\Interfaces;

use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Interfaces\ContextInterface;
use GuzzleHttp\Promise\PromiseInterface;

interface CoreInterface
{
    /**
     * Create a visitor context.
     *
     * @param string $visitorId
     * @param array|null $visitorAttributes Optional associative array.
     * @return ContextInterface
     */
    public function createContext(string $visitorId, ?array $visitorAttributes = null);

    /**
     * Attach an event handler to a system event.
     *
     * @param SystemEvents $event
     * @param callable $fn Callback function with optional arguments.
     * @return void
     */
    public function on($event, callable $fn): void;

    /**
     * Returns a promise that resolves when the core is ready.
     *
     * @return PromiseInterface
     */
    public function onReady(): PromiseInterface;
}
