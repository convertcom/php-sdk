<?php

declare(strict_types=1);

namespace ConvertSdk;

use ConvertSdk\Enums\Messages;
use ConvertSdk\Interfaces\BucketingManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Utils\StringUtils;

/**
 * Manages visitor bucketing for A/B test variation assignment.
 *
 * Uses MurmurHash3 to deterministically assign visitors to variations
 * based on their visitor ID and experience configuration. The bucketing
 * formula is identical to the JS SDK for cross-SDK parity.
 */
final class BucketingManager implements BucketingManagerInterface
{
    private const MAX_HASH = 4294967296; // 2^32, unsigned 32-bit max

    /**
     * @param int $maxTraffic Maximum traffic allocation value (default: 10000)
     * @param int $hashSeed MurmurHash3 seed (default: 9999)
     * @param LogManagerInterface|null $logManager Optional logger for debug output
     */
    public function __construct(
        private readonly int $maxTraffic = 10000,
        private readonly int $hashSeed = 9999,
        private readonly ?LogManagerInterface $logManager = null,
    ) {
        if ($this->logManager) {
            $this->logManager->trace('BucketingManager()', Messages::BUCKETING_CONSTRUCTOR, $this);
        }
    }

    /**
     * Select a variation based on cumulative percentage boundaries.
     *
     * Iterates through variation buckets, accumulating their percentage
     * ranges (scaled by 100), and returns the first variation whose
     * cumulative range exceeds the given value.
     *
     * @param array<string, float|int> $buckets Variation IDs as keys, percentages as values
     * @param float $value A normalized bucket value in [0, maxTraffic)
     * @param float $redistribute Amount to redistribute per bucket (default: 0.0)
     * @return string|null The selected variation ID, or null if no match
     */
    public function selectBucket(array $buckets, float $value, float $redistribute = 0.0): ?string
    {
        $variation = null;
        $prev = 0.0;

        foreach ($buckets as $id => $percentage) {
            $prev += ($percentage * 100) + $redistribute;
            if ($value < $prev) {
                $variation = (string) $id;
                break;
            }
        }

        if ($this->logManager) {
            $this->logManager->debug('BucketingManager.selectBucket()', [
                'buckets' => $buckets,
                'value' => $value,
                'redistribute' => $redistribute,
            ], ['variation' => $variation]);
        }

        return $variation;
    }

    /**
     * Compute a deterministic bucket value for a visitor.
     *
     * Formula (identical to JS SDK):
     *   hash = generateHash(experienceId + visitorId, seed)
     *   value = intval((hash / 4294967296) * maxTraffic)
     *
     * @param string $visitorId The visitor's unique identifier
     * @param array{seed?: int, experienceId?: string}|null $options Optional overrides
     * @return int Normalized bucket value in [0, maxTraffic)
     */
    public function getValueVisitorBased(string $visitorId, ?array $options = null): int
    {
        $seed = $options['seed'] ?? $this->hashSeed;
        $experienceId = $options['experienceId'] ?? '';
        $hash = StringUtils::generateHash($experienceId . strval($visitorId), $seed);
        $val = ($hash / self::MAX_HASH) * $this->maxTraffic;
        $result = intval($val);

        if ($this->logManager) {
            $this->logManager->debug('BucketingManager.getValueVisitorBased()', [
                'visitorId' => $visitorId,
                'seed' => $seed,
                'experienceId' => $experienceId,
                'val' => $val,
                'result' => $result,
            ]);
        }

        return $result;
    }

    /**
     * Get the bucket assignment for a visitor.
     *
     * Combines hash-based value computation with bucket selection to
     * deterministically assign a visitor to a variation.
     *
     * @param array<string, float|int> $buckets Variation IDs as keys, percentages as values
     * @param string $visitorId The visitor's unique identifier
     * @param array{redistribute?: float, seed?: int, experienceId?: string}|null $options Optional overrides
     * @return array{variationId: string, bucketingAllocation: int}|null Assignment result or null
     */
    public function getBucketForVisitor(array $buckets, string $visitorId, ?array $options = null): ?array
    {
        $value = $this->getValueVisitorBased($visitorId, $options);
        $selectedBucket = $this->selectBucket($buckets, $value, $options['redistribute'] ?? 0);

        if ($this->logManager) {
            $this->logManager->debug('BucketingManager.getBucketForVisitor()', [
                'visitorId' => $visitorId,
                'experienceId' => $options['experienceId'] ?? '',
                'bucketValue' => $value,
                'selectedVariationId' => $selectedBucket,
            ]);
        }

        if (!$selectedBucket) {
            return null;
        }

        return [
            'variationId' => $selectedBucket,
            'bucketingAllocation' => $value,
        ];
    }

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
    public function getBucketRanges(array $allocations): array
    {
        $totalWeight = array_reduce(
            $allocations,
            fn (float $sum, array $allocation) => $sum + $allocation['allocation'],
            0.0
        );

        $ranges = [];

        if ($totalWeight <= 0) {
            if ($this->logManager) {
                $this->logManager->debug('BucketingManager.getBucketRanges()', [
                    'allocations' => $allocations,
                    'totalWeight' => $totalWeight,
                ]);
            }

            return $ranges;
        }

        $cumWeight = 0.0;
        foreach ($allocations as $allocation) {
            $anchor = ($cumWeight / $totalWeight) * $this->maxTraffic;
            $width = $allocation['active'] ? $allocation['allocation'] * 100 : 0.0;
            $ranges[] = [
                'id' => $allocation['id'],
                'anchor' => $anchor,
                'width' => $width,
            ];
            $cumWeight += $allocation['allocation'];
        }

        if ($this->logManager) {
            $this->logManager->debug('BucketingManager.getBucketRanges()', [
                'allocations' => $allocations,
                'totalWeight' => $totalWeight,
            ], ['ranges' => $ranges]);
        }

        return $ranges;
    }

    /**
     * Select the variation whose anchored range contains the provided value.
     *
     * @param array<int, array{id: string, anchor: float, width: float}> $ranges Anchored bucket ranges (see getBucketRanges())
     * @param float $value A normalized bucket value in [0, maxTraffic)
     * @return string|null The selected variation ID, or null if no match
     */
    public function selectBucketAnchored(array $ranges, float $value): ?string
    {
        $variation = null;

        foreach ($ranges as $range) {
            if ($value >= $range['anchor'] && $value < $range['anchor'] + $range['width']) {
                $variation = $range['id'];
                break;
            }
        }

        if ($this->logManager) {
            $this->logManager->debug('BucketingManager.selectBucketAnchored()', [
                'ranges' => $ranges,
                'value' => $value,
            ], ['variation' => $variation]);
        }

        return $variation;
    }

    /**
     * Get an anchored bucket for the visitor (qs-01). Reuses the existing
     * visitor-based hash value unchanged, then resolves it through the anchored layout.
     *
     * @param array<int, array{id: string, allocation: float, active: bool}> $allocations Variation allocations in config order
     * @param string $visitorId The visitor's unique identifier
     * @param array{seed?: int, experienceId?: string}|null $options Optional overrides
     * @return array{variationId: string, bucketingAllocation: int}|null Assignment result or null
     */
    public function getBucketForVisitorAnchored(array $allocations, string $visitorId, ?array $options = null): ?array
    {
        $value = $this->getValueVisitorBased($visitorId, $options);
        $selectedBucket = $this->selectBucketAnchored($this->getBucketRanges($allocations), (float)$value);

        if ($this->logManager) {
            $this->logManager->debug('BucketingManager.getBucketForVisitorAnchored()', [
                'visitorId' => $visitorId,
                'experienceId' => $options['experienceId'] ?? '',
                'bucketValue' => $value,
                'selectedVariationId' => $selectedBucket,
            ]);
        }

        if (!$selectedBucket) {
            return null;
        }

        return [
            'variationId' => $selectedBucket,
            'bucketingAllocation' => $value,
        ];
    }
}
