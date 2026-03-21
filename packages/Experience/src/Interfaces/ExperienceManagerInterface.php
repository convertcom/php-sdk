<?php

declare(strict_types=1);

/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use OpenAPI\Client\Model\ConfigExperience;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\BucketedVariation;
use OpenAPI\Client\Model\ExperienceVariationConfig;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\BucketingError;

/**
 * Interface for managing experiences and variations.
 */
interface ExperienceManagerInterface
{
    /**
     * Get a list of all experiences.
     *
     * @return ConfigExperience[]
     */
    public function getList(): array;

    /**
     * Get an experience by its key.
     *
     * @param string $key The experience key
     * @return ConfigExperience
     */
    public function getExperience(string $key): ?ConfigExperience;

    /**
     * Get an experience by its ID.
     *
     * @param string $id The experience ID
     * @return ConfigExperience
     */
    public function getExperienceById(string $id): ?ConfigExperience;

    /**
     * Get multiple experiences by their keys.
     *
     * @param string[] $keys Array of experience keys
     * @return ConfigExperience[]
     */
    public function getExperiences(array $keys): array;

    /**
     * Select a variation for a visitor based on experience key.
     *
     * @param string $visitorId The visitor's ID
     * @param string $experienceKey The experience key
     * @param BucketingAttributes $attributes Bucketing attributes for variation selection
     * @return BucketedVariation|RuleError|BucketingError
     */
    public function selectVariation(string $visitorId, string $experienceKey, BucketingAttributes $attributes);

    /**
     * Select a variation for a visitor based on experience ID.
     *
     * @param string $visitorId The visitor's ID
     * @param string $experienceId The experience ID
     * @param BucketingAttributes $attributes Bucketing attributes for variation selection
     * @return BucketedVariation|RuleError|BucketingError
     */
    public function selectVariationById(string $visitorId, string $experienceId, BucketingAttributes $attributes);

    /**
     * Select variations for a visitor across all experiences.
     *
     * @param string $visitorId The visitor's ID
     * @param BucketingAttributes $attributes Bucketing attributes for variation selection
     * @return array Array of BucketedVariation|RuleError|BucketingError
     */
    public function selectVariations(string $visitorId, BucketingAttributes $attributes): array;

    /**
     * Get a variation by experience key and variation key.
     *
     * @param string $experienceKey The experience key
     * @param string $variationKey The variation key
     * @return ExperienceVariationConfig
     */
    public function getVariation(string $experienceKey, string $variationKey): ExperienceVariationConfig;

    /**
     * Get a variation by experience ID and variation ID.
     *
     * @param string $experienceId The experience ID
     * @param string $variationId The variation ID
     * @return ExperienceVariationConfig
     */
    public function getVariationById(string $experienceId, string $variationId): ExperienceVariationConfig;
}