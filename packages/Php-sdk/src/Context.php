<?php
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
use OpenAPI\Client\Config;
use OpenAPI\Client\BucketedVariation;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Entity;
use OpenAPI\Client\Model\ConfigExperience;
use OpenAPI\Client\Model\ExperienceVariationConfig;
use OpenAPI\Client\StoreData;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\EntityType;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Utils\ObjectUtils;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Promise;

/**
 * Provides visitor context
 * @category Main
 */
class Context implements ContextInterface
{
    private EventManagerInterface $_eventManager;
    private ExperienceManagerInterface $_experienceManager;
    private FeatureManagerInterface $_featureManager;
    private DataManagerInterface $_dataManager;
    private SegmentsManagerInterface $_segmentsManager;
    private ApiManagerInterface $_apiManager;
    private ?LogManagerInterface $_loggerManager;
    private Config $_config;
    private ?string $_visitorId;
    private ?array $_visitorProperties = null;
    private ?string $_environment = null;

    public function __construct(
        Config $config,
        ?string $visitorId,
        array $dependencies,
        ?array $visitorProperties = null
    ) {
        $this->_environment = $config->getEnvironment() ?? null;
        $this->_visitorId = $visitorId;

        $this->_config = $config;
        $this->_eventManager = $dependencies['eventManager'];
        $this->_experienceManager = $dependencies['experienceManager'];
        $this->_featureManager = $dependencies['featureManager'];
        $this->_dataManager = $dependencies['dataManager'];
        $this->_segmentsManager = $dependencies['segmentsManager'];
        $this->_apiManager = $dependencies['apiManager'];
        $this->_loggerManager = $dependencies['loggerManager'] ?? null;

        if (!empty($visitorProperties)) {
            $filtered = $this->_dataManager->filterReportSegments($visitorProperties);
            if (isset($filtered['properties'])) {
                $this->_visitorProperties = $filtered['properties'];
            }
            $this->_segmentsManager->putSegments($visitorId, $visitorProperties);
        }
    }

    /**
     * Get variation from specific experience
     *
     * @param string $experienceKey An experience's key that should be activated
     * @param BucketingAttributes|null $attributes An object that specifies attributes for the visitor
     * @return BucketedVariation|RuleError|BucketingError|null
     */
    public function runExperience(string $experienceKey, ?BucketingAttributes $attributes = null)
    {
        // Check if visitor ID is present
        if (empty($this->_visitorId)) {
            $this->_loggerManager?->error(
                'Context.runExperience()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null; // Early return if visitor ID is missing
        }

        // Get visitor properties, defaulting to null if not provided
        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        // Select variation using experience manager
        $bucketedVariation = $this->_experienceManager->selectVariation(
            $this->_visitorId,
            $experienceKey,
            new BucketingAttributes([
                'visitorProperties' => $visitorProperties, // Represents audiences
                'locationProperties' => $attributes?->getLocationProperties(), // Represents site_area/locations
                'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(), // Optional flag
                'environment' => $attributes?->getEnvironment() ?? $this->_environment // Fallback to default environment
            ])
        );

        // Check if the result is a RuleError
        if (in_array($bucketedVariation, RuleError::getConstants(), true)) {
            return $bucketedVariation;
        }

        // Check if the result is a BucketingError
        if (in_array($bucketedVariation, [BucketingError::VARIATION_NOT_DECIDED], true)) {
            return $bucketedVariation;
        }

        // If a valid variation is returned, fire a bucketing event
        if ($bucketedVariation) {
            $this->_eventManager->fire(
                SystemEvents::BUCKETING,
                [
                    'visitorId' => $this->_visitorId,
                    'experienceKey' => $experienceKey,
                    'variationKey' => $bucketedVariation['key'] ?? null // Safely access variation key
                ],
                null,
                true
            );
        }

        return $bucketedVariation; // Return the bucketed variation
    }

    /**
     * Get variations across all experiences
     *
     * @param BucketingAttributes|null $attributes An object that specifies attributes for the visitor
     * @return array An array of BucketedVariation, RuleError, or BucketingError instances
     */
    public function runExperiences(?BucketingAttributes $attributes = null): array
    {
        // Check if visitor ID is present
        if (empty($this->_visitorId)) {
            $this->_loggerManager?->error(
                'Context.runExperiences()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return []; // Early return with empty array if visitor ID is missing
        }

        // Get visitor properties, defaulting to null if not provided
        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        // Select variations using experience manager
        $bucketedVariations = $this->_experienceManager->selectVariations(
            $this->_visitorId,
            new BucketingAttributes([
                'visitorProperties' => $visitorProperties, // Represents audiences
                'locationProperties' => $attributes?->getLocationProperties(), // Represents site_area/locations
                'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(), // Optional flag
                'environment' => $attributes?->getEnvironment() ?? $this->_environment // Fallback to default environment
            ])
        );

        // Filter for rule errors
        $matchedRuleErrors = array_filter($bucketedVariations, function ($match) {
            return in_array($match, RuleError::getConstants(), true);
        });
        if (!empty($matchedRuleErrors)) {
            return array_values($matchedRuleErrors); // Return rule errors if present
        }

        // Filter for bucketing errors
        $matchedBucketingErrors = array_filter($bucketedVariations, function ($match) {
            return in_array($match, [BucketingError::VARIATION_NOT_DECIDED], true);
        });
        if (!empty($matchedBucketingErrors)) {
            return array_values($matchedBucketingErrors); // Return bucketing errors if present
        }

        // Fire events for each bucketed variation
        foreach ($bucketedVariations as $variation) {
            $this->_eventManager->fire(
                SystemEvents::BUCKETING,
                [
                    'visitorId' => $this->_visitorId,
                    'experienceKey' => $variation['experienceKey'] ?? null, // Safely access experience key
                    'variationKey' => $variation['key'] ?? null // Safely access variation key
                ],
                null,
                true
            );
        }

        return $bucketedVariations; // Return all bucketed variations
    }

    /**
     * Get feature and its status
     * @param string $key A feature key
     * @param BucketingAttributes|null $attributes An object that specifies attributes for the visitor
     * @return BucketedFeature|RuleError|array|null
     */
    public function runFeature(string $key, ?BucketingAttributes $attributes = null)
    {
        // Check if visitor ID is missing
        if (empty($this->_visitorId)) {
            $this->_loggerManager?->error(
                'Context.runFeature()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null; // Early return if no visitor ID
        }

        // Get visitor properties, handling null attributes
        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        // Run the feature through the feature manager
        $bucketedFeature = $this->_featureManager->runFeature(
            $this->_visitorId,
            $key,
            new BucketingAttributes([
                'visitorProperties' => $visitorProperties,
                'locationProperties' => $attributes?->getLocationProperties(),
                'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(),
                'typeCasting' => $attributes !== null && method_exists($attributes, 'getTypeCasting') 
                    ? $attributes->getTypeCasting() 
                    : true, // Default to true if not specified
                'environment' => $attributes?->getEnvironment() ?? $this->_environment
            ]),
            $attributes?->getExperienceKeys()
        );

        // Handle array of bucketed features or errors
        if (is_array($bucketedFeature)) {
            // Filter for rule errors
            $matchedErrors = array_filter($bucketedFeature, function ($match) {
                return in_array($match, RuleError::getConstants(), true);
            });
            if (!empty($matchedErrors)) {
                return array_values($matchedErrors); // Return errors if present
            }

            // Fire events for each bucketed feature
            foreach ($bucketedFeature as $feature) {
                $this->_eventManager->fire(
                    SystemEvents::BUCKETING,
                    [
                        'visitorId' => $this->_visitorId,
                        'experienceKey' => $feature['experienceKey'] ?? null,
                        'featureKey' => $key,
                        'status' => $feature['status'] ?? null
                    ],
                    null,
                    true
                );
            }
        } else {
            // Handle single RuleError or BucketedFeature
            if (in_array($bucketedFeature, RuleError::getConstants(), true)) {
                return $bucketedFeature; // Return RuleError
            }

            if ($bucketedFeature) {
                $this->_eventManager->fire(
                    SystemEvents::BUCKETING,
                    [
                        'visitorId' => $this->_visitorId,
                        'experienceKey' => $bucketedFeature['experienceKey'] ?? null,
                        'featureKey' => $key,
                        'status' => $bucketedFeature['status'] ?? null
                    ],
                    null,
                    true
                );
            }
        }

        return $bucketedFeature; // Return the result
    }

    /**
     * Get features and their statuses
     * @param BucketingAttributes|null $attributes An object that specifies attributes for the visitor
     * @return array An array of BucketedFeature or RuleError instances
     */
    public function runFeatures(?BucketingAttributes $attributes = null): array
    {
        // Check if visitor ID is missing
        if (empty($this->_visitorId)) {
            $this->_loggerManager?->error(
                'Context.runFeatures()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return []; // Early return with empty array
        }

        // Get visitor properties
        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        // Retrieve all bucketed features
        $bucketedFeatures = $this->_featureManager->runFeatures($this->_visitorId, new BucketingAttributes([
            'visitorProperties' => $visitorProperties,
            'locationProperties' => $attributes?->getLocationProperties(),
            'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(),
            'typeCasting' => $attributes !== null && method_exists($attributes, 'getTypeCasting') 
                ? $attributes->getTypeCasting() 
                : true, // Default to true
            'environment' => $attributes?->getEnvironment() ?? $this->_environment
        ]));

        // Filter for rule errors
        $matchedErrors = array_filter($bucketedFeatures, function ($match) {
            return in_array($match, RuleError::getConstants(), true);
        });
        if (!empty($matchedErrors)) {
            return array_values($matchedErrors); // Return errors if present
        }

        // Fire events for each bucketed feature
        foreach ($bucketedFeatures as $feature) {
            $this->_eventManager->fire(
                SystemEvents::BUCKETING,
                [
                    'visitorId' => $this->_visitorId,
                    'experienceKey' => $feature['experienceKey'] ?? null,
                    'featureKey' => $feature['key'] ?? null,
                    'status' => $feature['status'] ?? null
                ],
                null,
                true
            );
        }

        return $bucketedFeatures; // Return all features
    }

    /**
     * Trigger Conversion
     * @param string $goalKey A goal key
     * @param ConversionAttributes|null $attributes An object that specifies attributes for the visitor
     * @return RuleError
     */
    public function trackConversion(string $goalKey, ?array $attributes): ?RuleError
    {
        if (empty($this->_visitorId)) {
            $this->_loggerManager?->error(
                'Context.trackConversion()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }
        $goalRule = $attributes['ruleData'] ?? [];
        $goalData = $attributes['conversionData'] ?? [];
        if ($goalData !== null && !is_array($goalData)) {
            $this->_loggerManager?->error(
                'Context.trackConversion()',
                ErrorMessages::GOAL_DATA_NOT_VALID
            );
            return null;
        }

        $segments = $this->_segmentsManager->getSegments($this->_visitorId);
        $triggered = $this->_dataManager->convert(
            $this->_visitorId,
            $goalKey,
            $goalRule,
            $goalData,
            $segments,
            $attributes['conversionData'] ?? []
        );

        if (in_array($triggered, RuleError::getConstants(), true)) {
            return $triggered;
        }

        if ($triggered) {
            $this->_eventManager->fire(
                SystemEvents::CONVERSION,
                [
                    'visitorId' => $this->_visitorId,
                    'goalKey' => $goalKey
                ],
                null,
                true
            );
        }

        return null;
    }

    /**
    * Set default segments for reports
    * @param VisitorSegments $segments A segment key
    */
    public function setDefaultSegments(array $segments): void
    {
        $this->_segmentsManager->putSegments($this->_visitorId, $segments);
    }

    /**
     * To be deprecated
     * @param array $segmentKeys A list of segment keys
     * @param SegmentsAttributes|null $attributes An object that specifies attributes for the visitor
     * @return RuleError|null
     */
    public function setCustomSegments(array $segmentKeys, ?SegmentsAttributes $attributes = null): ?RuleError
    {
        return $this->runCustomSegments($segmentKeys, $attributes);
    }

    /**
    * Match Custom segments
    * @param array $segmentKeys A list of segment keys
    * @param SegmentsAttributes|null $attributes An object that specifies attributes for the visitor
    * @return RuleError|null
    */
    public function runCustomSegments(array $segmentKeys, array $attributes = null): ?array
    {
        if (empty($this->_visitorId)) {
            $this->_loggerManager?->error(
                'Context.runCustomSegments()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }
        $segmentsRule = $this->getVisitorProperties($attributes["ruleData"]);
        $error = $this->_segmentsManager->selectCustomSegments(
            $this->_visitorId,
            $segmentKeys,
            $segmentsRule
        );
        return $error->getCustomSegments() ? $error->getCustomSegments() : null;
    }

    /**
     * Update visitor properties in memory
     * @param string $visitorId
     * @param array $visitorProperties
     */
    public function updateVisitorProperties(string $visitorId, array $visitorProperties): void
    {
        $this->_dataManager->putData($visitorId, ['segments' => $visitorProperties]);
    }

    /**
     * Get Config Entity
     * @param string $key
     * @param EntityType $entityType
     * @return Entity|null
     */
    public function getConfigEntity(string $key, string $entityType): array
    {
        if ($entityType === EntityType::VARIATION) {
            $experiences = $this->_dataManager->getEntitiesList(EntityType::EXPERIENCE);
            foreach ($experiences as $experience) {
                $variation = $this->_dataManager->getSubItem(
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
        return $this->_dataManager->getEntity($key, $entityType);
    }

    /**
     * Get Config Entity by ID
     * @param string $id
     * @param EntityType $entityType
     * @return Entity|null
     */
    public function getConfigEntityById(string $id, string $entityType): array
    {
        if ($entityType === EntityType::VARIATION) {
            $experiences = $this->_dataManager->getEntitiesList(EntityType::EXPERIENCE);
            foreach ($experiences as $experience) {
                $variation = $this->_dataManager->getSubItem(
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
        return $this->_dataManager->getEntityById($id, $entityType);
    }

    /**
     * Get visitor data
     * @return array
     */
    public function getVisitorData(): StoreData
    {
        return $this->_dataManager->getData($this->_visitorId) ?? [];
    }

    /**
     * Send pending API/DataStore queues to server
     * @param string|null $reason
     * @return PromiseInterface
     */
    public function releaseQueues(?string $reason = null): PromiseInterface
    {
        if ($this->_dataManager->getDataStoreManager()) {
            $this->_dataManager->getDataStoreManager()->releaseQueue($reason);
        }
        return $this->_apiManager->releaseQueue($reason);
    }

    /**
     * Get visitor properties
     * @param array|null $attributes An object of key-value pairs that are used for audience targeting
     * @return array
     */

    private function getVisitorProperties(?array $attributes = null): array
    {
        $data = $this->_dataManager->getData($this->_visitorId);
        $segments = $data && $data->getSegments() ? (array)$data->getSegments() : [];
        $segments = $segments ? end($segments) : [];
        $visitorProperties = $attributes
            ? ObjectUtils::objectDeepMerge($this->_visitorProperties ?? [], $attributes)
            : $this->_visitorProperties;
        return ObjectUtils::objectDeepMerge($segments, $visitorProperties ?? []);
    }
}