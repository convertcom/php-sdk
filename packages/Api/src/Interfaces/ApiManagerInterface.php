<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\VisitorTrackingEvents;

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
     * @return array Response array with 'data', 'status', 'statusText', 'headers' keys
     */
    public function request(
        string $method,
        array $path,
        array $data = [],
        array $headers = []
    ): array;

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
     * @return void
     */
    public function releaseQueue(?string $reason = null): void;

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
     * @return ConfigResponseData
     */
    public function getConfig(): ConfigResponseData;

    /**
     * Get configuration data scoped to a single experience via `exp=` (qs-02
     * capability B preview input — AC4). Forces `_conv_low_cache=1` regardless
     * of `network.cacheLevel`, plus `debug_token=` when configured.
     *
     * @param string $experienceId The experience id to inject via `exp=`
     * @return ConfigResponseData
     */
    public function getConfigForExperience(string $experienceId): ConfigResponseData;
}
