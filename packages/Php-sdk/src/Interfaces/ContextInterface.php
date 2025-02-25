<?php
namespace ConvertSdk\Interfaces;

use GuzzleHttp\Promise\PromiseInterface;

interface ContextInterface
{
    /**
     * Run a single experience.
     *
     * @param string $experienceKey
     * @param ?\ConvertSdk\Types\BucketingAttributes $attributes
     * @return mixed  Returns BucketedVariation, RuleError, or BucketingError.
     */
    public function runExperience(string $experienceKey, ?\ConvertSdk\Types\BucketingAttributes $attributes = null);

    /**
     * Run multiple experiences.
     *
     * @param ?\ConvertSdk\Types\BucketingAttributes $attributes
     * @return array  Array of BucketedVariation, RuleError, or BucketingError.
     */
    public function runExperiences(?\ConvertSdk\Types\BucketingAttributes $attributes = null): array;

    /**
     * Run a feature.
     *
     * @param string $key
     * @param ?\ConvertSdk\Types\BucketingAttributes $attributes
     * @return mixed  Returns BucketedFeature, RuleError, or an array of BucketedFeature|RuleError.
     */
    public function runFeature(string $key, ?\ConvertSdk\Types\BucketingAttributes $attributes = null);

    /**
     * Run multiple features.
     *
     * @param ?\ConvertSdk\Types\BucketingAttributes $attributes
     * @return array  Array of BucketedFeature or RuleError.
     */
    public function runFeatures(?\ConvertSdk\Types\BucketingAttributes $attributes = null): array;

    /**
     * Track a conversion.
     *
     * @param string $goalKey
     * @param ?\ConvertSdk\Types\ConversionAttributes $attributes
     * @return \ConvertSdk\Enums\RuleError
     */
    public function trackConversion(string $goalKey, ?\ConvertSdk\Types\ConversionAttributes $attributes = null);

    /**
     * Set default segments.
     *
     * @param \ConvertSdk\Types\VisitorSegments $segments
     * @return void
     */
    public function setDefaultSegments(\ConvertSdk\Types\VisitorSegments $segments): void;

    /**
     * Run custom segments.
     *
     * @param array $segmentKeys
     * @param ?\ConvertSdk\Types\SegmentsAttributes $attributes
     * @return \ConvertSdk\Enums\RuleError
     */
    public function runCustomSegments(array $segmentKeys, ?\ConvertSdk\Types\SegmentsAttributes $attributes = null);

    /**
     * Update visitor properties.
     *
     * @param string $visitorId
     * @param array $visitorProperties
     * @return void
     */
    public function updateVisitorProperties(string $visitorId, array $visitorProperties): void;

    /**
     * Get a configuration entity by key.
     *
     * @param string $key
     * @param \ConvertSdk\Enums\EntityType $entityType
     * @return \ConvertSdk\Types\Entity
     */
    public function getConfigEntity(string $key, \ConvertSdk\Enums\EntityType $entityType);

    /**
     * Get a configuration entity by id.
     *
     * @param string $id
     * @param \ConvertSdk\Enums\EntityType $entityType
     * @return \ConvertSdk\Types\Entity
     */
    public function getConfigEntityById(string $id, \ConvertSdk\Enums\EntityType $entityType);

    /**
     * Get visitor data.
     *
     * @return \ConvertSdk\Types\StoreData
     */
    public function getVisitorData(): \ConvertSdk\Types\StoreData;

    /**
     * Release queues.
     *
     * @param ?string $reason
     * @return PromiseInterface
     */
    public function releaseQueues(?string $reason = null): PromiseInterface;
}
