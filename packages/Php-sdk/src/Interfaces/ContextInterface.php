<?php

declare(strict_types=1);

/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\BucketedVariation;
use OpenAPI\Client\Entity;
use ConvertSdk\Enums\EntityType;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\BucketingError;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\StoreData;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Promise;

interface ContextInterface
{
    /**
     * Runs a single experience for a given key with optional bucketing attributes.
     *
     * @param string $experienceKey The key identifying the experience
     * @param BucketingAttributes|null $attributes Optional attributes for bucketing
     * @return mixed The result of running the experience (BucketedVariation, RuleError, or BucketingError)
     */
    public function runExperience(string $experienceKey, ?BucketingAttributes $attributes = null);

    /**
     * Runs multiple experiences with optional bucketing attributes.
     *
     * @param BucketingAttributes|null $attributes Optional attributes for bucketing
     * @return array An array of results from running experiences (each can be BucketedVariation, RuleError, or BucketingError)
     */
    public function runExperiences(?BucketingAttributes $attributes = null): array;

    /**
     * Runs a single feature for a given key with optional bucketing attributes.
     *
     * @param string $key The key identifying the feature
     * @param BucketingAttributes|null $attributes Optional attributes for bucketing
     * @return mixed The result of running the feature (, RuleError, or array of |RuleError)
     */
    public function runFeature(string $key, ?BucketingAttributes $attributes = null);

    /**
     * Runs multiple features with optional bucketing attributes.
     *
     * @param BucketingAttributes|null $attributes Optional attributes for bucketing
     * @return array An array of results from running features (each can be  or RuleError)
     */
    public function runFeatures(?BucketingAttributes $attributes = null): array;

    /**
     * Tracks a conversion for a given goal key with optional conversion attributes.
     *
     * @param string $goalKey The key identifying the goal
     * @param ConversionAttributes|null $attributes Optional attributes for conversion tracking
     * @return RuleError The error result of tracking the conversion, if any
     */
    public function trackConversion(string $goalKey, ?array $attributes): ?RuleError;

    /**
     * Sets default segments for the visitor.
     *
     * @param VisitorSegments $segments The segments to set as default
     */
    public function setDefaultSegments(array $segments): void;

    /**
     * Runs custom segments for given segment keys with optional segment attributes.
     *
     * @param string[] $segmentKeys Array of segment keys
     * @param SegmentsAttributes|null $attributes Optional attributes for segment evaluation
     * @return RuleError The error result of running custom segments, if any
     */
    public function runCustomSegments(array $segmentKeys, array $attributes = null): ?array;

    /**
     * Updates properties for a specific visitor.
     *
     * @param string $visitorId The ID of the visitor
     * @param array<string, mixed> $visitorProperties Key-value pairs of visitor properties
     */
    public function updateVisitorProperties(string $visitorId, array $visitorProperties): void;

    /**
     * Retrieves a configuration entity by key and type.
     *
     * @param string $key The key of the entity
     * @param EntityType $entityType The type of the entity (e.g., feature or experience)
     * @return Entity The configuration entity
     */
    public function getConfigEntity(string $key, string $entityType): array;

    /**
     * Retrieves a configuration entity by ID and type.
     *
     * @param string $id The ID of the entity
     * @param EntityType $entityType The type of the entity (e.g., feature or experience)
     * @return Entity The configuration entity
     */
    public function getConfigEntityById(string $id, string $entityType): array;

    /**
     * Retrieves the visitor's stored data.
     *
     * @return StoreData The visitor's data
     */
    public function getVisitorData(): array;

    /**
     * Releases any queued operations with an optional reason.
     *
     * @param string|null $reason Optional reason for releasing queues
     */
    public function releaseQueues(?string $reason = null): PromiseInterface;
}