<?php

namespace ConvertSdk\Interfaces;

use OpenAPI\Client\Model\VisitorSegments;

/**
 * Interface for managing visitor segments
 * @category Modules
 */
interface SegmentsManagerInterface
{
    /**
     * Get segments in DataStore
     *
     * @param string $visitorId
     * @return VisitorSegments
     */
    public function getSegments(string $visitorId): VisitorSegments;

    /**
     * Update segments in DataStore
     *
     * @param string $visitorId
     * @param VisitorSegments $segments
     * @return void
     */
    public function putSegments(string $visitorId, ?array $segments): void;

    /**
     * Update custom segments for specific visitor by segment keys
     *
     * @param string $visitorId
     * @param array<string> $segmentKeys A list of segment keys
     * @param array|null $segmentRule An object of key-value pairs for segments matching
     * @return mixed VisitorSegments or RuleError
     */
    public function selectCustomSegments(
        string $visitorId,
        array $segmentKeys,
        ?array $segmentRule = null
    );

    /**
     * Update custom segments for specific visitor by segment IDs
     *
     * @param string $visitorId
     * @param array<string> $segmentIds A list of segment IDs
     * @param array|null $segmentRule An object of key-value pairs for segments matching
     * @return mixed VisitorSegments or RuleError
     */
    public function selectCustomSegmentsByIds(
        string $visitorId,
        array $segmentIds,
        ?array $segmentRule = null
    );
}