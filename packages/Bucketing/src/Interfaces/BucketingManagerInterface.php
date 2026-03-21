<?php

declare(strict_types=1);

namespace ConvertSdk\Interfaces;

interface BucketingManagerInterface
{
    /**
     * Select a bucket based on the given value and redistribute parameter.
     *
     * @param array $buckets An associative array of buckets with their respective weights.
     * @param float $value The value used for bucket selection.
     * @param float|null $redistribute Optionally, the amount to redistribute.
     *
     * @return string|null The selected bucket or null if no selection could be made.
     */
    public function selectBucket(array $buckets, $value, $redistribute = null);

    /**
     * Get the bucket value for a visitor based on the visitor's ID and any options provided.
     *
     * @param string $visitorId The visitor ID.
     * @param mixed $options Additional options for bucketing (optional).
     *
     * @return float The value associated with the visitor.
     */
    public function getValueVisitorBased($visitorId, $options = null);

    /**
     * Get the bucket assigned to a visitor based on the given buckets and visitor ID.
     *
     * @param array $buckets An associative array of buckets with their respective weights.
     * @param string $visitorId The visitor ID.
     * @param mixed $options Additional options for bucketing (optional).
     *
     * @return mixed The allocation for the visitor or null if no bucket is assigned.
     */
    public function getBucketForVisitor(array $buckets, $visitorId, $options = null);
}
