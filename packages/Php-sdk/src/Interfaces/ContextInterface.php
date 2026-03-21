<?php

declare(strict_types=1);

/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use OpenAPI\Client\BucketingAttributes;
use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\BucketingError;

/**
 * Visitor context interface for running experiences, features, and tracking conversions.
 */
interface ContextInterface
{
    /**
     * Run a single experience for a given key with optional bucketing attributes.
     *
     * @param string $experienceKey The key identifying the experience
     * @param BucketingAttributes|null $attributes Optional attributes for bucketing
     * @return BucketedVariation|null The bucketed variation DTO, or null for all non-success paths
     */
    public function runExperience(string $experienceKey, ?BucketingAttributes $attributes = null): ?BucketedVariation;

    /**
     * Run multiple experiences with optional bucketing attributes.
     *
     * @param BucketingAttributes|null $attributes Optional attributes for bucketing
     * @return BucketedVariation[] Array of bucketed variation DTOs
     */
    public function runExperiences(?BucketingAttributes $attributes = null): array;

    /**
     * Run a single feature for a given key with optional bucketing attributes.
     *
     * @param string $key The key identifying the feature
     * @param BucketingAttributes|null $attributes Optional attributes for bucketing
     * @return BucketedFeature|null The bucketed feature DTO, or null for not-found/error paths
     */
    public function runFeature(string $key, ?BucketingAttributes $attributes = null): ?BucketedFeature;

    /**
     * Run multiple features with optional bucketing attributes.
     *
     * @param BucketingAttributes|null $attributes Optional attributes for bucketing
     * @return BucketedFeature[] Array of bucketed feature DTOs
     */
    public function runFeatures(?BucketingAttributes $attributes = null): array;

    /**
     * Track a conversion for a given goal key.
     *
     * @param string $goalKey The key identifying the goal
     * @param ConversionAttributes|null $attributes Conversion attributes
     * @return RuleError|bool|null RuleError on rule mismatch, false if goal not found, null on success
     */
    public function trackConversion(string $goalKey, ?ConversionAttributes $attributes = null): RuleError|bool|null;

    /**
     * Set default segments for the visitor.
     *
     * @param array<string, mixed> $segments The segments to set
     * @return void
     */
    public function setDefaultSegments(array $segments): void;

    /**
     * Run custom segments for given segment keys.
     *
     * @param array<int, string> $segmentKeys Array of segment keys
     * @param array<string, mixed>|null $attributes Optional segment attributes
     * @return array<int, mixed>|null Custom segments or null
     */
    public function runCustomSegments(array $segmentKeys, ?array $attributes = null): ?array;

    /**
     * Set custom segments (deprecated alias for runCustomSegments).
     *
     * @deprecated Use runCustomSegments() instead
     * @param array<int, string> $segmentKeys Array of segment keys
     * @param array<string, mixed>|null $attributes Optional segment attributes
     * @return array<int, mixed>|null Custom segments or null
     */
    public function setCustomSegments(array $segmentKeys, ?array $attributes = null): ?array;

    /**
     * Update properties for a specific visitor.
     *
     * @param string $visitorId The ID of the visitor
     * @param array<string, mixed> $visitorProperties Key-value pairs of visitor properties
     * @return void
     */
    public function updateVisitorProperties(string $visitorId, array $visitorProperties): void;

    /**
     * Retrieve a configuration entity by key and type.
     *
     * @param string $key The key of the entity
     * @param string $entityType The type of the entity (EntityType value)
     * @return array<string, mixed> The entity data
     */
    public function getConfigEntity(string $key, string $entityType): array;

    /**
     * Retrieve a configuration entity by ID and type.
     *
     * @param string $id The ID of the entity
     * @param string $entityType The type of the entity (EntityType value)
     * @return array<string, mixed> The entity data
     */
    public function getConfigEntityById(string $id, string $entityType): array;

    /**
     * Retrieve the visitor's stored data.
     *
     * @return array<string, mixed> The visitor's data
     */
    public function getVisitorData(): array;

    /**
     * Release any queued operations.
     *
     * @param string|null $reason Optional reason for releasing queues
     * @return void
     */
    public function releaseQueues(?string $reason = null): void;

    /**
     * Set a single visitor attribute.
     *
     * @param string $key The attribute key
     * @param mixed $value The attribute value
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void;

    /**
     * Set multiple visitor attributes at once (merges with existing).
     *
     * @param array<string, mixed> $attributes Key-value pairs of attributes
     * @return void
     */
    public function setAttributes(array $attributes): void;

    /**
     * Get all current visitor attributes.
     *
     * @return array<string, mixed> The current visitor attributes
     */
    public function getAttributes(): array;

    /**
     * Get the visitor ID for this context.
     *
     * @return string The visitor ID
     */
    public function getVisitorId(): string;
}
