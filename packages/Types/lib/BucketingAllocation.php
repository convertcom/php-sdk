<?php

namespace OpenApi\Client;

/**
 * Class representing a bucketing allocation.
 *
 * @package ConvertSdk
 */
class BucketingAllocation
{
    /** @var ?string Optional variation ID */
    private ?string $variationId = null;

    /** @var ?float Optional bucketing allocation percentage (stored as float for precision) */
    private ?float $bucketingAllocation = null;

    /**
     * BucketingAllocation constructor.
     *
     * @param array $options Configuration options
     */
    public function __construct(array $options = [])
    {
        $this->variationId = isset($options['variationId']) && is_string($options['variationId'])
            ? $options['variationId']
            : null;
        $this->bucketingAllocation = isset($options['bucketingAllocation']) && is_numeric($options['bucketingAllocation'])
            ? (float)$options['bucketingAllocation']
            : null;
    }

    // Getters
    public function getVariationId(): ?string
    {
        return $this->variationId;
    }

    public function getBucketingAllocation(): ?float
    {
        return $this->bucketingAllocation;
    }

    // Setters (optional, for flexibility)
    public function setVariationId(?string $variationId): void
    {
        $this->variationId = $variationId;
    }

    public function setBucketingAllocation(?float $bucketingAllocation): void
    {
        $this->bucketingAllocation = $bucketingAllocation;
    }
}