<?php

declare(strict_types=1);

namespace ConvertSdk\Interfaces;

/**
 * Contract for visitor bucketing managers.
 *
 * Implementations must provide deterministic visitor-to-variation assignment
 * using MurmurHash3 with cross-SDK parity guarantees.
 */
interface BucketingManagerInterface
{
    /**
     * Select a variation based on cumulative percentage boundaries.
     *
     * @param array<string, float|int> $buckets Variation IDs as keys, percentages as values
     * @param float $value A normalized bucket value in [0, maxTraffic)
     * @param float $redistribute Amount to redistribute per bucket (default: 0.0)
     * @return string|null The selected variation ID, or null if no match
     */
    public function selectBucket(array $buckets, float $value, float $redistribute = 0.0): ?string;

    /**
     * Compute a deterministic bucket value for a visitor.
     *
     * @param string $visitorId The visitor's unique identifier
     * @param array{seed?: int, experienceId?: string}|null $options Optional overrides
     * @return int Normalized bucket value in [0, maxTraffic)
     */
    public function getValueVisitorBased(string $visitorId, ?array $options = null): int;

    /**
     * Get the bucket assignment for a visitor.
     *
     * @param array<string, float|int> $buckets Variation IDs as keys, percentages as values
     * @param string $visitorId The visitor's unique identifier
     * @param array{redistribute?: float, seed?: int, experienceId?: string}|null $options Optional overrides
     * @return array{variationId: string, bucketingAllocation: int}|null Assignment result or null
     */
    public function getBucketForVisitor(array $buckets, string $visitorId, ?array $options = null): ?array;
}
