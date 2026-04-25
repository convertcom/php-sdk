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
}
