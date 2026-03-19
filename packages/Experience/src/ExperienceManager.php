<?php

declare(strict_types=1);

/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\ExperienceManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use OpenAPI\Client\Model\ConfigExperience;
use OpenAPI\Client\Model\ExperienceVariationConfig;
use OpenAPI\Client\BucketingAttributes;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\Messages;

/**
 * Provides experiences specific logic.
 *
 * ExperienceManager is a thin orchestrator that delegates all bucketing logic
 * to DataManager. It does NOT contain bucketing, rule evaluation, or hashing logic.
 *
 * @implements ExperienceManagerInterface
 */
final class ExperienceManager implements ExperienceManagerInterface
{
    /**
     * @param DataManagerInterface $dataManager Data manager for bucketing and entity lookups
     * @param LogManagerInterface|null $logManager Optional logger manager instance
     */
    public function __construct(
        private readonly DataManagerInterface $dataManager,
        private readonly ?LogManagerInterface $logManager = null,
    ) {
        $this->logManager?->trace('ExperienceManager()', Messages::EXPERIENCE_CONSTRUCTOR);
    }

    /**
     * Get a list of all experiences.
     *
     * @return ConfigExperience[] Array of experience configurations
     */
    public function getList(): array
    {
        return $this->dataManager->getEntitiesList('experiences');
    }

    /**
     * Get an experience by its key.
     *
     * @param string $key The experience key
     * @return ConfigExperience|null The experience configuration, or null if not found
     */
    public function getExperience(string $key): ?ConfigExperience
    {
        $entityData = $this->dataManager->getEntity($key, 'experiences');
        if ($entityData === null) {
            return null;
        }
        return new ConfigExperience($entityData);
    }

    /**
     * Get an experience by its ID.
     *
     * @param string $id The experience ID
     * @return ConfigExperience|null The experience configuration, or null if not found
     */
    public function getExperienceById(string $id): ?ConfigExperience
    {
        $entityData = $this->dataManager->getEntityById($id, 'experiences');
        if ($entityData === null) {
            return null;
        }
        return new ConfigExperience($entityData);
    }

    /**
     * Get multiple experiences by their keys.
     *
     * @param string[] $keys Array of experience keys
     * @return ConfigExperience[] Array of experience configurations
     */
    public function getExperiences(array $keys): array
    {
        return $this->dataManager->getItemsByKeys($keys, 'experiences');
    }

    /**
     * Select a variation for a visitor based on experience key.
     *
     * @param string $visitorId The visitor's ID
     * @param string $experienceKey The experience key
     * @param BucketingAttributes $attributes Bucketing attributes for variation selection
     * @return array|RuleError|BucketingError|null The selected variation array, or an error/null
     */
    public function selectVariation(string $visitorId, string $experienceKey, BucketingAttributes $attributes): array|RuleError|BucketingError|null
    {
        return $this->dataManager->getBucketing($visitorId, $experienceKey, $attributes);
    }

    /**
     * Select a variation for a visitor based on experience ID.
     *
     * @param string $visitorId The visitor's ID
     * @param string $experienceId The experience ID
     * @param BucketingAttributes $attributes Bucketing attributes for variation selection
     * @return array|RuleError|BucketingError|null The selected variation array, or an error/null
     */
    public function selectVariationById(string $visitorId, string $experienceId, BucketingAttributes $attributes): array|RuleError|BucketingError|null
    {
        return $this->dataManager->getBucketingById($visitorId, $experienceId, $attributes);
    }

    /**
     * Select variations for a visitor across all experiences.
     *
     * Filters out null, RuleError, and BucketingError results — only successful
     * bucketed variation arrays are returned.
     *
     * @param string $visitorId The visitor's ID
     * @param BucketingAttributes $attributes Bucketing attributes for variation selection
     * @return array<int, array<string, mixed>> Array of successful bucketed variation arrays
     */
    public function selectVariations(string $visitorId, BucketingAttributes $attributes): array
    {
        $experiences = $this->getList();
        $variations = array_map(function ($experience) use ($visitorId, $attributes) {
            return $this->selectVariation($visitorId, $experience["key"], $attributes);
        }, $experiences);
        $filteredVariations = array_filter($variations, function ($variation) {
            return $variation !== null &&
            !($variation instanceof RuleError) &&
                   $variation !== BucketingError::VariationNotDecided;
        });
        return array_values($filteredVariations); // Re-index array after filtering
    }

    /**
     * Get a variation by experience key and variation key.
     *
     * @param string $experienceKey The experience key
     * @param string $variationKey The variation key
     * @return ExperienceVariationConfig The variation configuration
     */
    public function getVariation(string $experienceKey, string $variationKey): ExperienceVariationConfig
    {
        $variationData = $this->dataManager->getSubItem(
            'experiences',
            $experienceKey,
            'variations',
            $variationKey,
            'key',
            'key'
        );

        return new ExperienceVariationConfig($variationData);
    }

    /**
     * Get a variation by experience ID and variation ID.
     *
     * @param string $experienceId The experience ID
     * @param string $variationId The variation ID
     * @return ExperienceVariationConfig The variation configuration
     */
    public function getVariationById(string $experienceId, string $variationId): ExperienceVariationConfig
    {
        $variationData = $this->dataManager->getSubItem(
            'experiences',
            $experienceId,
            'variations',
            $variationId,
            'id',
            'id'
        );

        return new ExperienceVariationConfig($variationData);
    }
}