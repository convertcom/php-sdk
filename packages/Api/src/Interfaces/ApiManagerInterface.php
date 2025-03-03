<?php
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use GuzzleHttp\Promise\PromiseInterface;

/**
 * Interface ApiManagerInterface
 *
 * Defines the API Manager contract.
 */
interface ApiManagerInterface
{
    /**
     * Sends an API request.
     *
     * @param string $method HTTP method (e.g. "GET", "POST", etc.)
     * @param mixed $path The API path (could be a string or a Path object)
     * @param array $data An associative array of request data.
     * @param array $headers An associative array of headers.
     * @return PromiseInterface Promise that resolves to the response.
     */
    public function request(string $method, $path, array $data, array $headers): PromiseInterface;

    /**
     * Enqueues an event request for the given visitor.
     *
     * @param string $visitorId
     * @param mixed $eventRequest (VisitorTrackingEvents)
     * @param mixed|null $segments (optional VisitorSegments)
     * @return void
     */
    public function enqueue(string $visitorId, $eventRequest, $segments = null): void;

    /**
     * Releases the API request queue.
     *
     * @param string|null $reason Optional reason for releasing the queue.
     * @return PromiseInterface Promise that resolves to the result of releasing the queue.
     */
    public function releaseQueue(string $reason = null): PromiseInterface;

    /**
     * Stops the API request queue.
     *
     * @return void
     */
    public function stopQueue(): void;

    /**
     * Starts the API request queue.
     *
     * @return void
     */
    public function startQueue(): void;

    /**
     * Enables tracking.
     *
     * @return void
     */
    public function enableTracking(): void;

    /**
     * Disables tracking.
     *
     * @return void
     */
    public function disableTracking(): void;

    /**
     * Sets the configuration data.
     *
     * @param mixed $data (ConfigResponseData)
     * @return void
     */
    public function setData($data): void;

    /**
     * Retrieves the current configuration.
     *
     * @return PromiseInterface Promise that resolves to the configuration (ConfigResponseData).
     */
    public function getConfig(): PromiseInterface;
}
