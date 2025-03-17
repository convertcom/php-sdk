<?php
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use GuzzleHttp\Promise\PromiseInterface;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use OpenAPI\Client\Model\VisitorSegments;

/**
 * Interface for ApiManager
 */
interface ApiManagerInterface
{
    /**
     * Send request to API server
     *
     * @param string $method HTTP method (e.g., 'GET', 'POST')
     * @param array $path Path with 'base' and 'route' keys
     * @param array $data Request data
     * @param array $headers Request headers
     * @return PromiseInterface Resolves to an HTTP response
     */
    public function request(
        string $method,
        array $path,
        array $data = [],
        array $headers = []
    ): PromiseInterface;

    /**
     * Add request to queue
     *
     * @param string $visitorId Visitor ID
     * @param VisitorTrackingEvents $eventRequest Event request data
     * @param VisitorSegments|null $segments Visitor segments (optional)
     */
    public function enqueue(
        string $visitorId,
        VisitorTrackingEvents $eventRequest,
        ?VisitorSegments $segments = null
    ): void;

    /**
     * Release queue to server
     *
     * @param string|null $reason Optional reason for releasing the queue
     * @return PromiseInterface Resolves to an HTTP response
     */
    public function releaseQueue(?string $reason = null): PromiseInterface;

    /**
     * Stop queue timer
     */
    public function stopQueue(): void;

    /**
     * Start queue timer
     */
    public function startQueue(): void;

    /**
     * Enable tracking
     */
    public function enableTracking(): void;

    /**
     * Disable tracking
     */
    public function disableTracking(): void;

    /**
     * Set configuration data
     *
     * @param ConfigResponseData $data Configuration data
     */
    public function setData(ConfigResponseData $data): void;

    /**
     * Get configuration data
     *
     * @return PromiseInterface Resolves to ConfigResponseData
     */
    public function getConfig(): PromiseInterface;
}