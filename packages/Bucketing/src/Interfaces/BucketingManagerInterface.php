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

    /**
     * Build the anchored bucket layout for a set of variation allocations (qs-01).
     *
     * Anchors are computed over the total weight of ALL entries (active and inactive) so
     * that raising an experience's total allocation only ever grows arms (superset
     * property) and never reshuffles an already-bucketed visitor into a different arm.
     * Inactive (or explicit zero-allocation) entries keep their weight for anchor
     * stability but get a zero-width range so they can never be selected.
     *
     * @param array<int, array{id: string, allocation: float, active: bool}> $allocations Variation allocations in config order
     * @return array<int, array{id: string, anchor: float, width: float}>
     */
    public function getBucketRanges(array $allocations): array;

    /**
     * Select the variation whose anchored range contains the provided value.
     *
     * @param array<int, array{id: string, anchor: float, width: float}> $ranges Anchored bucket ranges (see getBucketRanges())
     * @param float $value A normalized bucket value in [0, maxTraffic)
     * @return string|null The selected variation ID, or null if no match
     */
    public function selectBucketAnchored(array $ranges, float $value): ?string;

    /**
     * Get an anchored bucket for the visitor (qs-01). Reuses the existing
     * visitor-based hash value unchanged, then resolves it through the anchored layout.
     *
     * @param array<int, array{id: string, allocation: float, active: bool}> $allocations Variation allocations in config order
     * @param string $visitorId The visitor's unique identifier
     * @param array{seed?: int, experienceId?: string}|null $options Optional overrides
     * @return array{variationId: string, bucketingAllocation: int}|null Assignment result or null
     */
    public function getBucketForVisitorAnchored(array $allocations, string $visitorId, ?array $options = null): ?array;
}
