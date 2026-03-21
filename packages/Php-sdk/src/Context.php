<?php

declare(strict_types=1);

/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright (c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Interfaces\ContextInterface;
use ConvertSdk\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\ExperienceManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\Exception\InvalidArgumentException;
use OpenAPI\Client\Config;
use OpenAPI\Client\BucketingAttributes;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\EntityType;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Utils\ObjectUtils;

/**
 * Provides visitor context for running experiences, features, and tracking conversions.
 */
final class Context implements ContextInterface
{
    /** @var ?string */
    private ?string $environment;

    /** @var ?array<string, mixed> */
    private ?array $visitorProperties = null;

    /**
     * @param Config $config SDK configuration
     * @param string $visitorId Unique visitor identifier
     * @param EventManagerInterface $eventManager Event manager instance
     * @param ExperienceManagerInterface $experienceManager Experience manager instance
     * @param FeatureManagerInterface $featureManager Feature manager instance
     * @param DataManagerInterface $dataManager Data manager instance
     * @param SegmentsManagerInterface $segmentsManager Segments manager instance
     * @param ApiManagerInterface $apiManager API manager instance
     * @param LogManagerInterface|null $loggerManager Optional logger manager instance
     * @param array<string, mixed>|null $visitorAttributes Initial visitor attributes for targeting
     *
     * @throws InvalidArgumentException If visitorId is empty
     */
    public function __construct(
        private readonly Config $config,
        private readonly string $visitorId,
        private readonly EventManagerInterface $eventManager,
        private readonly ExperienceManagerInterface $experienceManager,
        private readonly FeatureManagerInterface $featureManager,
        private readonly DataManagerInterface $dataManager,
        private readonly SegmentsManagerInterface $segmentsManager,
        private readonly ApiManagerInterface $apiManager,
        private readonly ?LogManagerInterface $loggerManager = null,
        ?array $visitorAttributes = null,
    ) {
        if ($visitorId === '') {
            throw new InvalidArgumentException('Visitor ID must not be empty');
        }

        $this->environment = $config->getEnvironment() ?? null;

        if (!empty($visitorAttributes)) {
            $filtered = $this->dataManager->filterReportSegments($visitorAttributes);
            if (isset($filtered['properties'])) {
                $this->visitorProperties = $filtered['properties'];
            }
            $this->segmentsManager->putSegments($visitorId, $visitorAttributes);
        }
    }

    /**
     * Get variation from specific experience.
     *
     * @param string $experienceKey An experience's key that should be activated
     * @param BucketingAttributes|null $attributes Attributes for the visitor
     * @return BucketedVariation|null The bucketed variation DTO, or null for all non-success paths
     */
    public function runExperience(string $experienceKey, ?BucketingAttributes $attributes = null): ?BucketedVariation
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runExperience()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }

        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());
        $result = $this->experienceManager->selectVariation(
            $this->visitorId,
            $experienceKey,
            new BucketingAttributes([
                'visitorProperties' => $visitorProperties,
                'locationProperties' => $attributes?->getLocationProperties(),
                'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(),
                'environment' => $attributes?->getEnvironment() ?? $this->environment
            ])
        );

        if ($result === null
            || $result instanceof RuleError
            || $result === BucketingError::VariationNotDecided
        ) {
            return null;
        }

        $this->eventManager->fire(
            SystemEvents::Bucketing,
            [
                'visitorId' => $this->visitorId,
                'experienceKey' => $experienceKey,
                'variationKey' => $result['key'] ?? null
            ],
            null,
            true
        );

        return $this->mapToBucketedVariationDto($result);
    }

    /**
     * Get variations across all experiences.
     *
     * @param BucketingAttributes|null $attributes Attributes for the visitor
     * @return BucketedVariation[] Array of bucketed variation DTOs
     */
    public function runExperiences(?BucketingAttributes $attributes = null): array
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runExperiences()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return [];
        }

        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        $bucketedVariations = $this->experienceManager->selectVariations(
            $this->visitorId,
            new BucketingAttributes([
                'visitorProperties' => $visitorProperties,
                'locationProperties' => $attributes?->getLocationProperties(),
                'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(),
                'environment' => $attributes?->getEnvironment() ?? $this->environment
            ])
        );

        $dtos = [];
        foreach ($bucketedVariations as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            $this->eventManager->fire(
                SystemEvents::Bucketing,
                [
                    'visitorId' => $this->visitorId,
                    'experienceKey' => $variation['experienceKey'] ?? null,
                    'variationKey' => $variation['key'] ?? null
                ],
                null,
                true
            );
            $dtos[] = $this->mapToBucketedVariationDto($variation);
        }

        return $dtos;
    }

    /**
     * Get feature and its status.
     *
     * @param string $key A feature key
     * @param BucketingAttributes|null $attributes Attributes for the visitor
     * @return BucketedFeature|null The bucketed feature DTO, or null for not-found/error paths
     */
    public function runFeature(string $key, ?BucketingAttributes $attributes = null): ?BucketedFeature
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runFeature()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }

        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        $result = $this->featureManager->runFeature(
            $this->visitorId,
            $key,
            new BucketingAttributes([
                'visitorProperties' => $visitorProperties,
                'locationProperties' => $attributes?->getLocationProperties(),
                'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(),
                'typeCasting' => $attributes !== null && method_exists($attributes, 'getTypeCasting')
                    ? $attributes->getTypeCasting()
                    : true,
                'environment' => $attributes?->getEnvironment() ?? $this->environment
            ]),
            $attributes?->getExperienceKeys()
        );

        // Determine if result is a single feature array or array of feature arrays
        // Single feature: has 'status' key directly; multi: indexed array of feature arrays
        if (isset($result['status'])) {
            // Feature not declared (no 'id') → return null per consumer contract
            if (!isset($result['id'])) {
                return null;
            }

            $dto = $this->mapToBucketedFeatureDto($result);

            // Fire event only for enabled features
            if ($dto->status === FeatureStatus::Enabled) {
                $this->eventManager->fire(
                    SystemEvents::Bucketing,
                    [
                        'visitorId' => $this->visitorId,
                        'experienceKey' => $result['experienceKey'] ?? null,
                        'featureKey' => $key,
                        'status' => $result['status'] ?? null
                    ],
                    null,
                    true
                );
            }

            return $dto;
        }

        // Array of feature arrays (multi-experience) — return first enabled one
        foreach ($result as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $dto = $this->mapToBucketedFeatureDto($feature);
            if ($dto->status === FeatureStatus::Enabled) {
                $this->eventManager->fire(
                    SystemEvents::Bucketing,
                    [
                        'visitorId' => $this->visitorId,
                        'experienceKey' => $feature['experienceKey'] ?? null,
                        'featureKey' => $key,
                        'status' => $feature['status'] ?? null
                    ],
                    null,
                    true
                );
                return $dto;
            }
        }

        // No enabled features found — return first feature as disabled DTO
        $firstFeature = $result[0] ?? null;
        if (is_array($firstFeature)) {
            return $this->mapToBucketedFeatureDto($firstFeature);
        }

        return null;
    }

    /**
     * Get features and their statuses.
     *
     * @param BucketingAttributes|null $attributes Attributes for the visitor
     * @return BucketedFeature[] Array of bucketed feature DTOs
     */
    public function runFeatures(?BucketingAttributes $attributes = null): array
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runFeatures()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return [];
        }

        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        $bucketedFeatures = $this->featureManager->runFeatures($this->visitorId, new BucketingAttributes([
            'visitorProperties' => $visitorProperties,
            'locationProperties' => $attributes?->getLocationProperties(),
            'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(),
            'typeCasting' => $attributes !== null && method_exists($attributes, 'getTypeCasting')
                ? $attributes->getTypeCasting()
                : true,
            'environment' => $attributes?->getEnvironment() ?? $this->environment
        ]));

        // Filter out RuleError results
        $matchedErrors = array_filter($bucketedFeatures, function ($match) {
            return $match instanceof RuleError;
        });
        if (!empty($matchedErrors)) {
            return [];
        }

        $dtos = [];
        foreach ($bucketedFeatures as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $dto = $this->mapToBucketedFeatureDto($feature);

            // Fire event only for enabled features
            if ($dto->status === FeatureStatus::Enabled) {
                $this->eventManager->fire(
                    SystemEvents::Bucketing,
                    [
                        'visitorId' => $this->visitorId,
                        'experienceKey' => $feature['experienceKey'] ?? null,
                        'featureKey' => $feature['key'] ?? null,
                        'status' => $feature['status'] ?? null
                    ],
                    null,
                    true
                );
            }

            $dtos[] = $dto;
        }

        return $dtos;
    }

    /**
     * Trigger conversion tracking.
     *
     * @param string $goalKey A goal key
     * @param array<string, mixed>|null $attributes Conversion attributes
     * @return RuleError|null Rule error if conversion tracking fails
     */
    public function trackConversion(string $goalKey, ?array $attributes): ?RuleError
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.trackConversion()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }
        $goalRule = $attributes['ruleData'] ?? [];
        $goalData = $attributes['conversionData'] ?? [];
        if ($goalData !== null && !is_array($goalData)) {
            $this->loggerManager?->error(
                'Context.trackConversion()',
                ErrorMessages::GOAL_DATA_NOT_VALID
            );
            return null;
        }

        $segments = $this->segmentsManager->getSegments($this->visitorId);
        $triggered = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            $goalRule,
            $goalData,
            $segments,
            $attributes['conversionData'] ?? []
        );

        if ($triggered instanceof RuleError) {
            return $triggered;
        }
        if ($triggered) {
            $this->eventManager->fire(
                SystemEvents::Conversion,
                [
                    'visitorId' => $this->visitorId,
                    'goalKey' => $goalKey
                ],
                null,
                true
            );
        }

        return null;
    }

    /**
     * Set default segments for reports.
     *
     * @param array<string, mixed> $segments Segment data
     * @return void
     */
    public function setDefaultSegments(array $segments): void
    {
        $this->segmentsManager->putSegments($this->visitorId, $segments);
    }

    /**
     * To be deprecated.
     *
     * @param array<int, string> $segmentKeys A list of segment keys
     * @param array<string, mixed>|null $attributes Segment attributes
     * @return array<int, mixed>|null
     */
    public function setCustomSegments(array $segmentKeys, ?array $attributes = null): ?array
    {
        return $this->runCustomSegments($segmentKeys, $attributes);
    }

    /**
     * Match custom segments.
     *
     * @param array<int, string> $segmentKeys A list of segment keys
     * @param array<string, mixed>|null $attributes Segment attributes
     * @return array<int, mixed>|null
     */
    public function runCustomSegments(array $segmentKeys, ?array $attributes = null): ?array
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runCustomSegments()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }
        $segmentsRule = $this->getVisitorProperties($attributes["ruleData"] ?? null);
        $error = $this->segmentsManager->selectCustomSegments(
            $this->visitorId,
            $segmentKeys,
            $segmentsRule
        );
        return $error->getCustomSegments() ? $error->getCustomSegments() : null;
    }

    /**
     * Update visitor properties in memory.
     *
     * @param string $visitorId The visitor ID
     * @param array<string, mixed> $visitorProperties Key-value pairs of visitor properties
     * @return void
     */
    public function updateVisitorProperties(string $visitorId, array $visitorProperties): void
    {
        $this->dataManager->putData($visitorId, ['segments' => $visitorProperties]);
    }

    /**
     * Set a single visitor attribute.
     *
     * @param string $key The attribute key
     * @param mixed $value The attribute value
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->visitorProperties = $this->visitorProperties ?? [];
        $this->visitorProperties[$key] = $value;
    }

    /**
     * Set multiple visitor attributes at once (merges with existing).
     *
     * @param array<string, mixed> $attributes Key-value pairs of attributes
     * @return void
     */
    public function setAttributes(array $attributes): void
    {
        $this->visitorProperties = array_merge($this->visitorProperties ?? [], $attributes);
    }

    /**
     * Get all current visitor attributes.
     *
     * @return array<string, mixed> The current visitor attributes
     */
    public function getAttributes(): array
    {
        return $this->visitorProperties ?? [];
    }

    /**
     * Get the visitor ID for this context.
     *
     * @return string The visitor ID
     */
    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    /**
     * Get config entity by key.
     *
     * @param string $key Entity key
     * @param string $entityType Entity type (EntityType value)
     * @return array<string, mixed> The entity data
     */
    public function getConfigEntity(string $key, string $entityType): array
    {
        if ($entityType === EntityType::Variation->value) {
            $experiences = $this->dataManager->getEntitiesList(EntityType::Experience->value);
            foreach ($experiences as $experience) {
                $variation = $this->dataManager->getSubItem(
                    'experiences',
                    $experience['key'],
                    'variations',
                    $key,
                    'key',
                    'key'
                );
                if ($variation) {
                    return $variation;
                }
            }
        }
        return $this->dataManager->getEntity($key, $entityType);
    }

    /**
     * Get config entity by ID.
     *
     * @param string $id Entity ID
     * @param string $entityType Entity type (EntityType value)
     * @return array<string, mixed> The entity data
     */
    public function getConfigEntityById(string $id, string $entityType): array
    {
        if ($entityType === EntityType::Variation->value) {
            $experiences = $this->dataManager->getEntitiesList(EntityType::Experience->value);
            foreach ($experiences as $experience) {
                $variation = $this->dataManager->getSubItem(
                    'experiences',
                    $experience['id'],
                    'variations',
                    $id,
                    'id',
                    'id'
                );
                if ($variation) {
                    return $variation;
                }
            }
        }
        return $this->dataManager->getEntityById($id, $entityType);
    }

    /**
     * Get visitor data.
     *
     * @return array<string, mixed> The visitor's stored data
     */
    public function getVisitorData(): array
    {
        return $this->dataManager->getData($this->visitorId) ?? [];
    }

    /**
     * Send pending API/DataStore queues to server.
     *
     * @param string|null $reason Optional reason for releasing queues
     * @return void
     */
    public function releaseQueues(?string $reason = null): void
    {
        if ($this->dataManager->getDataStoreManager()) {
            $this->dataManager->getDataStoreManager()->releaseQueue($reason);
        }
        $this->apiManager->releaseQueue($reason);
    }

    /**
     * Get visitor properties merged with stored segments.
     *
     * @param array<string, mixed>|null $attributes Visitor attributes to merge
     * @return array<string, mixed> Merged visitor properties
     */
    private function getVisitorProperties(?array $attributes = null): array
    {
        $data = $this->dataManager->getData($this->visitorId);
        $segments = $data && $data["segments"] ? $data["segments"] : [];
        $segments = $segments ? $segments : [];
        $visitorProperties = $attributes
            ? ObjectUtils::objectDeepMerge($this->visitorProperties ?? [], $attributes)
            : $this->visitorProperties;
        return ObjectUtils::objectDeepMerge($segments, $visitorProperties ?? []);
    }

    /**
     * Map internal bucketed feature array to consumer-facing readonly DTO.
     *
     * @param array<string, mixed> $feature The internal bucketed feature array from FeatureManager
     * @return BucketedFeature The readonly consumer DTO
     */
    private function mapToBucketedFeatureDto(array $feature): BucketedFeature
    {
        return new BucketedFeature(
            featureId: (string) ($feature['id'] ?? ''),
            featureKey: (string) ($feature['key'] ?? ''),
            status: FeatureStatus::tryFrom($feature['status'] ?? 'disabled') ?? FeatureStatus::Disabled,
            variables: (array) ($feature['variables'] ?? []),
        );
    }

    /**
     * Map internal bucketed variation array to consumer-facing readonly DTO.
     *
     * @param array<string, mixed> $variation The internal bucketed variation array from DataManager
     * @return BucketedVariation The readonly consumer DTO
     */
    private function mapToBucketedVariationDto(array $variation): BucketedVariation
    {
        return new BucketedVariation(
            experienceId: (string) ($variation['experienceId'] ?? ''),
            experienceKey: (string) ($variation['experienceKey'] ?? ''),
            variationId: (string) ($variation['id'] ?? ''),
            variationKey: (string) ($variation['key'] ?? ''),
            changes: (array) ($variation['changes'] ?? []),
        );
    }
}
