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
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigExperience;
use OpenAPI\Client\Model\ExperienceVariationConfig;
use OpenAPI\Client\BucketedVariation;
use OpenAPI\Client\BucketingAttributes;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\Messages;

/**
 * Provides experiences specific logic
 * @category Modules
 * @implements ExperienceManagerInterface
 */
class ExperienceManager implements ExperienceManagerInterface
{
    /** @var DataManagerInterface */
    private $dataManager;

    /** @var LogManagerInterface|null */
    private $loggerManager;

    /**
     * Constructor for ExperienceManager.
     *
     * @param Config $config Configuration object
     * @param array $dependencies Dependencies array
     * @param DataManagerInterface $dependencies['dataManager'] Data manager instance
     * @param LogManagerInterface|null $dependencies['loggerManager'] Optional logger manager instance
     */
    public function __construct(Config $config, array $dependencies)
    {
        $this->dataManager = $dependencies['dataManager'];
        $this->loggerManager = $dependencies['loggerManager'] ?? null;
        if ($this->loggerManager) {
            $this->loggerManager->trace('ExperienceManager()', Messages::EXPERIENCE_CONSTRUCTOR);
        }
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
     * @return ConfigExperience The experience configuration
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
     * @return ConfigExperience The experience configuration
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
     * @return BucketedVariation|RuleError|BucketingError The selected variation or an error
     */
    public function selectVariation(string $visitorId, string $experienceKey, BucketingAttributes $attributes)
    {
        return $this->dataManager->getBucketing($visitorId, $experienceKey, $attributes);
    }

    /**
     * Select a variation for a visitor based on experience ID.
     *
     * @param string $visitorId The visitor's ID
     * @param string $experienceId The experience ID
     * @param BucketingAttributes $attributes Bucketing attributes for variation selection
     * @return BucketedVariation|RuleError|BucketingError The selected variation or an error
     */
    public function selectVariationById(string $visitorId, string $experienceId, BucketingAttributes $attributes)
    {
        return $this->dataManager->getBucketingById($visitorId, $experienceId, $attributes);
    }

    /**
     * Select variations for a visitor across all experiences.
     *
     * @param string $visitorId The visitor's ID
     * @param BucketingAttributes $attributes Bucketing attributes for variation selection
     * @return array Array of BucketedVariation|RuleError|BucketingError
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