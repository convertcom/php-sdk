<?php

declare(strict_types=1);

namespace ConvertSdk\Interfaces;

/*!
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

use OpenAPI\Client\Model\ConfigFeature;
use OpenAPI\Client\BucketingAttributes;

/**
 * Interface FeatureManagerInterface
 *
 * Defines the contract for managing features in the Convert SDK.
 *
 * @package ConvertSdk\Interfaces
 */
interface FeatureManagerInterface
{
    /**
     * Get a list of all configured features.
     *
     * @return ConfigFeature[] Array of feature configurations
     */
    public function getList(): array;

    /**
     * Get a feature by its key.
     *
     * @param string $key Feature key
     * @return ConfigFeature Feature configuration
     */
    public function getFeature(string $key): ConfigFeature;

    /**
     * Get a feature by its ID.
     *
     * @param string $id Feature ID
     * @return ConfigFeature Feature configuration
     */
    public function getFeatureById(string $id): ConfigFeature;

    /**
     * Get multiple features by their keys.
     *
     * @param string[] $keys Array of feature keys
     * @return ConfigFeature[] Array of feature configurations
     */
    public function getFeatures(array $keys): array;

    /**
     * Get all features as an object indexed by a specified field.
     *
     * @param string $field Field to index by (e.g., 'id', 'key')
     * @return array<string, ConfigFeature> Associative array of features
     */
    public function getListAsObject(string $field): array;

    /**
     * Check if a feature is declared by its key.
     *
     * @param string $key Feature key
     * @return bool True if the feature is declared, false otherwise
     */
    public function isFeatureDeclared(string $key): bool;

    /**
     * Get the type of a feature variable by feature key.
     *
     * @param string $key Feature key
     * @param string $variableName Variable name
     * @return string Variable type (e.g., 'string', 'number')
     */
    public function getFeatureVariableType(string $key, string $variableName): ?string;

    /**
     * Get the type of a feature variable by feature ID.
     *
     * @param string $id Feature ID
     * @param string $variableName Variable name
     * @return string Variable type (e.g., 'string', 'number')
     */
    public function getFeatureVariableTypeById(string $id, string $variableName): ?string;

    /**
     * Run a feature for a visitor, returning its bucketed state.
     *
     * @param string $visitorId Visitor ID
     * @param string $featureKey Feature key
     * @param BucketingAttributes $attributes Bucketing attributes
     * @param string[]|null $experienceKeys Optional array of experience keys
     * @return array<string, mixed> Bucketed feature array or array of feature arrays
     */
    public function runFeature(
        string $visitorId,
        string $featureKey,
        BucketingAttributes $attributes,
        ?array $experienceKeys = null
    ): array;

    /**
     * Check if a feature is enabled for a visitor.
     *
     * @param string $visitorId Visitor ID
     * @param string $featureKey Feature key
     * @param BucketingAttributes $attributes Bucketing attributes
     * @param string[]|null $experienceKeys Optional array of experience keys
     * @return bool True if the feature is enabled, false otherwise
     */
    public function isFeatureEnabled(
        string $visitorId,
        string $featureKey,
        BucketingAttributes $attributes,
        ?array $experienceKeys = null
    ): bool;

    /**
     * Run a feature by its ID for a visitor, returning its bucketed state.
     *
     * @param string $visitorId Visitor ID
     * @param string $featureId Feature ID
     * @param BucketingAttributes $attributes Bucketing attributes
     * @param string[]|null $experienceIds Optional array of experience IDs
     * @return array<string, mixed> Bucketed feature array or array of feature arrays
     */
    public function runFeatureById(
        string $visitorId,
        string $featureId,
        BucketingAttributes $attributes,
        ?array $experienceIds = null
    ): array;

    /**
     * Run multiple features for a visitor with optional filtering.
     *
     * @param string $visitorId Visitor ID
     * @param BucketingAttributes $attributes Bucketing attributes
     * @param array<string, string[]>|null $filter Optional filter (e.g., ['experienceKeys' => ['exp1']])
     * @return array<int, array<string, mixed>> Array of bucketed feature arrays
     */
    public function runFeatures(
        string $visitorId,
        BucketingAttributes $attributes,
        ?array $filter = null
    ): array;
}