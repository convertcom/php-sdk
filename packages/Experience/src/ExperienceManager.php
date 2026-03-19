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
        $this->logManager?->debug('ExperienceManager.selectVariation()', [
            'visitorId' => $visitorId,
            'experienceKey' => $experienceKey,
        ]);

        $result = $this->dataManager->getBucketing($visitorId, $experienceKey, $attributes);

        if ($this->logManager) {
            $logData = [
                'visitorId' => $visitorId,
                'experienceKey' => $experienceKey,
            ];

            if (is_array($result)) {
                $logData['resultType'] = 'bucketed';
                $logData['variationId'] = $result['id'] ?? $result['key'] ?? 'unknown';
            } elseif ($result instanceof RuleError) {
                $logData['resultType'] = 'RuleError';
                $logData['ruleError'] = $result->value;
            } elseif ($result instanceof BucketingError) {
                $logData['resultType'] = 'BucketingError';
                $logData['bucketingError'] = $result->value;
                $logData['reason'] = Messages::NULL_RETURN_TRAFFIC_ALLOCATION;
            } elseif ($result === null) {
                // Determine specific null-return reason for consumer-facing log
                $logData['resultType'] = 'null';
                $experience = $this->dataManager->getEntity($experienceKey, 'experiences');
                if ($experience === null) {
                    $logData['reason'] = Messages::NULL_RETURN_EXPERIENCE_NOT_FOUND;
                    $logData['availableKeys'] = array_map(
                        fn($e) => $e['key'] ?? 'unknown',
                        $this->dataManager->getEntitiesList('experiences')
                    );
                } else {
                    // Experience exists but visitor not qualified — DataManager already
                    // logged the specific reason (audience mismatch, location mismatch,
                    // experience archived, environment mismatch)
                    $logData['reason'] = Messages::NULL_RETURN_VISITOR_NOT_QUALIFIED;
                }
            }

            $this->logManager->debug('ExperienceManager.selectVariation()', $logData);
        }

        return $result;
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
        $this->logManager?->debug('ExperienceManager.selectVariationById()', [
            'visitorId' => $visitorId,
            'experienceId' => $experienceId,
        ]);

        $result = $this->dataManager->getBucketingById($visitorId, $experienceId, $attributes);

        if ($this->logManager) {
            $logData = [
                'visitorId' => $visitorId,
                'experienceId' => $experienceId,
            ];

            if (is_array($result)) {
                $logData['resultType'] = 'bucketed';
                $logData['variationId'] = $result['id'] ?? $result['key'] ?? 'unknown';
            } elseif ($result instanceof RuleError) {
                $logData['resultType'] = 'RuleError';
                $logData['ruleError'] = $result->value;
            } elseif ($result instanceof BucketingError) {
                $logData['resultType'] = 'BucketingError';
                $logData['bucketingError'] = $result->value;
                $logData['reason'] = Messages::NULL_RETURN_TRAFFIC_ALLOCATION;
            } elseif ($result === null) {
                $logData['resultType'] = 'null';
                $experience = $this->dataManager->getEntityById($experienceId, 'experiences');
                if ($experience === null) {
                    $logData['reason'] = Messages::NULL_RETURN_EXPERIENCE_NOT_FOUND;
                    $logData['availableIds'] = array_map(
                        fn($e) => $e['id'] ?? 'unknown',
                        $this->dataManager->getEntitiesList('experiences')
                    );
                } else {
                    $logData['reason'] = Messages::NULL_RETURN_VISITOR_NOT_QUALIFIED;
                }
            }

            $this->logManager->debug('ExperienceManager.selectVariationById()', $logData);
        }

        return $result;
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

        $experienceCount = count($experiences);

        $this->logManager?->debug('ExperienceManager.selectVariations()', [
            'visitorId' => $visitorId,
            'experienceCount' => $experienceCount,
        ]);

        $variations = array_map(function ($experience) use ($visitorId, $attributes) {
            return $this->selectVariation($visitorId, $experience["key"], $attributes);
        }, $experiences);
        $filteredVariations = array_filter($variations, function ($variation) {
            return $variation !== null &&
            !($variation instanceof RuleError) &&
                   $variation !== BucketingError::VariationNotDecided;
        });
        $result = array_values($filteredVariations); // Re-index array after filtering

        $this->logManager?->debug('ExperienceManager.selectVariations()', [
            'visitorId' => $visitorId,
            'experienceCount' => $experienceCount,
            'bucketedVariations' => count($result),
        ]);

        return $result;
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