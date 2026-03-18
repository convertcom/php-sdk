<?php

declare(strict_types=1);

namespace ConvertSdk;

use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigFeature;
use OpenAPI\Client\Model\ConfigExperience;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\BucketedVariation;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\VariationChangeType;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Utils\TypeUtils;
use ConvertSdk\Utils\ArrayUtils;
use ConvertSdk\Utils\ObjectUtils;

/*!
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

/**
 * Class FeatureManager
 *
 * Manages features within the Convert SDK, handling bucketing and feature status.
 *
 * @package ConvertSdk
 */
class FeatureManager implements FeatureManagerInterface
{
    /**
     * @var Config Configuration instance
     */
    private $config;

    /**
     * @var DataManagerInterface Data manager for storing and retrieving bucketed data
     */
    private $dataManager;

    /**
     * @var LogManagerInterface|null Logger for warnings and errors
     */
    private $loggerManager;

    /**
     * FeatureManager constructor.
     *
     * @param Config $config SDK configuration
     * @param DataManagerInterface $dataManager Data manager instance
     * @param LogManagerInterface|null $loggerManager Optional logger instance
     */
    public function __construct(
        Config $config,
        DataManagerInterface $dataManager,
        ?LogManagerInterface $loggerManager = null
    ) {
        $this->config = $config;
        $this->dataManager = $dataManager;
        $this->loggerManager = $loggerManager;
    }

    /**
     * Get a list of all entities
     * @return ConfigFeature[]
     */
    public function getList(): array
    {
        return $this->dataManager->getEntitiesList('features');
    }
    
    /**
     * Get a list of all entities as object of entities grouped by identity field
     * @param string $field A field to group entities defaults to 'id'
     * @return array<string, ConfigFeature>
     */
    public function getListAsObject(string $field): array
    {
        return $this->dataManager->getEntitiesListObject('features', $field);
    }
    
    /**
     * Get the entity by key
     * @param string $key
     * @return ConfigFeature
     */
    public function getFeature(string $key): ConfigFeature
    {
        return new ConfigFeature($this->dataManager->getEntity($key, 'features'));
    }
    
    /**
     * Get the entity by id
     * @param string $id
     * @return ConfigFeature
     */
    public function getFeatureById(string $id): ConfigFeature
    {
        return new ConfigFeature($this->dataManager->getEntityById($id, 'features'));
    }
    
    /**
     * Get specific entities by array of keys
     * @param string[] $keys
     * @return ConfigFeature[]
     */
    public function getFeatures(array $keys): array
    {
        return $this->dataManager->getItemsByKeys($keys, 'features');
    }
    
    /**
     * Get a specific variable type defined in a specific feature
     * @param string $key A feature's key
     * @param string $variableName
     * @return string|null
     */
    public function getFeatureVariableType(string $key, string $variableName): ?string
    {
        $feature = $this->getFeature($key);
        if (isset($feature['variables'])) {
            foreach ($feature['variables'] as $variable) {
                if ($variable['key'] === $variableName) {
                    return $variable['type'] ?? null;
                }
            }
        }
        return null;
    }
    
    /**
     * Get a specific variable type defined in a specific feature by id
     * @param string $id A feature's id
     * @param string $variableName
     * @return string|null
     */
    public function getFeatureVariableTypeById(string $id, string $variableName): ?string
    {
        $feature = $this->getFeatureById($id);
        if (isset($feature['variables'])) {
            foreach ($feature['variables'] as $variable) {
                if ($variable['key'] === $variableName) {
                    return $variable['type'] ?? null;
                }
            }
        }
        return null;
    }
    
    /**
     * Check that feature is declared
     * @param string $key ConfigFeature key
     * @return bool
     */
    public function isFeatureDeclared(string $key): bool
    {
        $declaredFeature = $this->dataManager->getEntity($key, 'features');
        return $declaredFeature !== null;
    }

    /**
     * Get feature and its status
     *
     * @param string $visitorId
     * @param string $featureKey
     * @param BucketingAttributes $attributes
     * @param array|null $experienceKeys Optional array of experience keys
     * @return BucketedFeature|RuleError|array Returns a single bucketed feature, rule error, or array of features/errors
     */
    public function runFeature(
        string $visitorId,
        string $featureKey,
        BucketingAttributes $attributes,
        ?array $experienceKeys = null
    ) {
        $declaredFeature = $this->dataManager->getEntity($featureKey, 'features');
        if ($declaredFeature) {
            $features = $this->runFeatures($visitorId, $attributes, [
                'features' => [$featureKey],
                'experiences' => $experienceKeys
            ]);

            if (!empty($features)) {
                if (count($features) === 1) {
                    // Return the single bucketed feature
                    return $features[0];
                } else {
                    // Return an array of bucketed features (feature used in multiple experiences)
                    return $features;
                }
            }

            // Return disabled feature if visitor was not bucketed
            return [
                'id' => $declaredFeature['id'],
                'name' => $declaredFeature['name'],
                'key' => $featureKey,
                'status' => FeatureStatus::Disabled->value
            ];
        } else {
            // Feature is not declared
            return [
                'key' => $featureKey,
                'status' => FeatureStatus::Disabled->value
            ];
        }
    }

    /**
     * Check if feature is enabled
     *
     * @param string $visitorId
     * @param string $featureKey
     * @param BucketingAttributes $attributes
     * @param array|null $experienceKeys Optional array of experience keys
     * @return bool True if feature is enabled, false otherwise
     */
    public function isFeatureEnabled(
        string $visitorId,
        string $featureKey,
        BucketingAttributes $attributes,
        ?array $experienceKeys = null
    ): bool {
        $declaredFeature = $this->dataManager->getEntity($featureKey, 'features');

        if ($declaredFeature) {
            $features = $this->runFeatures($visitorId, $attributes, [
                'features' => [$featureKey],
                'experiences' => $experienceKeys
            ]);
            return !empty($features);
        }

        return false;
    }

    /**
     * Get feature and its status by ID
     *
     * @param string $visitorId
     * @param string $featureId
     * @param BucketingAttributes $attributes
     * @param array|null $experienceIds Optional array of experience IDs
     * @return BucketedFeature|RuleError|array Returns a single bucketed feature, rule error, or array of features/errors
     */
    public function runFeatureById(
        string $visitorId,
        string $featureId,
        BucketingAttributes $attributes,
        ?array $experienceIds = null
    ) {
        $declaredFeature = $this->dataManager->getEntityById($featureId, 'features');

        if ($declaredFeature) {
            // Convert experience IDs to keys
            $experienceKeys = $experienceIds ? array_map(function ($exp) {
                return $exp['key'];
            }, $this->dataManager->getEntitiesByIds($experienceIds, 'experiences')) : null;

            $features = $this->runFeatures($visitorId, $attributes, [
                'features' => [$declaredFeature['key']],
                'experiences' => $experienceKeys
            ]);

            if (!empty($features)) {
                if (count($features) === 1) {
                    // Return the single bucketed feature
                    return $features[0];
                } else {
                    // Check for rule errors
                    $matchedErrors = array_filter($features, function ($match) {
                        return $match instanceof RuleError;
                    });
                    if (!empty($matchedErrors)) {
                        return $matchedErrors;
                    }
                    // Return array of bucketed features (feature used in multiple experiences)
                    return $features;
                }
            }

            // Return disabled feature if visitor was not bucketed
            return [
                'id' => $featureId,
                'name' => $declaredFeature['name'],
                'key' => $declaredFeature['key'],
                'status' => FeatureStatus::Disabled->value
            ];
        } else {
            // Feature is not declared
            return [
                'id' => $featureId,
                'status' => FeatureStatus::Disabled->value
            ];
        }
    }

    /**
     * Get features and their statuses
     *
     * @param string $visitorId The unique identifier for the visitor
     * @param BucketingAttributes $attributes The bucketing attributes object
     * @param array|null $filter Filter records by experiences and/or features keys
     * @return array Array of bucketed features or rule errors
     */
    public function runFeatures(string $visitorId, BucketingAttributes $attributes, ?array $filter = null): array
    {
        // Extract typeCasting with a default value of true
        $typeCasting = $attributes->getTypeCasting() ?? true;

        // Get list of declared features grouped by id
        $declaredFeatures = $this->getListAsObject('id');

        // Initialize array for bucketed features
        $bucketedFeatures = [];
        // Retrieve all or filtered experiences
        $experiences = (!empty($filter['experiences']))
            ? $this->dataManager->getEntities($filter['experiences'], 'experiences')
            : $this->dataManager->getEntitiesList('experiences');
        // Retrieve bucketed variations across the experiences
        $bucketedVariations = array_filter(array_map(function ($experience) use ($visitorId, $attributes) {
            $variation = $this->dataManager->getBucketing(
                $visitorId,
                $experience['key'] ?? null,
                $attributes
            );
            if ($variation instanceof RuleError) {
                return $variation;
            }
            return $variation;
        }, $experiences));

        // Return rule errors if present
        $matchedErrors = array_filter($bucketedVariations, function ($match) {
            return $match instanceof RuleError;
        });
        if (!empty($matchedErrors)) {
            return $matchedErrors;
        }

        // Collect features from bucketed variations
        foreach ($bucketedVariations as $bucketedVariation) {
            $changes = [];
            if (is_array($bucketedVariation)){
                $changes = end($bucketedVariation)["changes"];
            }
            foreach ($changes as $change) {
                if (($change['type'] ?? null) !== VariationChangeType::FullstackFeature->value) {
                    $this->_loggerManager?->warn(
                        'FeatureManager.runFeatures()',
                        Messages::VARIATION_CHANGE_NOT_SUPPORTED
                    );
                    continue;
                }
                $featureId = $change['data']['feature_id'] ?? null;
                if (!$featureId) {
                    $this->_loggerManager?->warn(
                        'FeatureManager.runFeatures()',
                        Messages::FEATURE_NOT_FOUND
                    );
                    continue;
                }

                // Take the features filter into account
                if (
                    !isset($filter['features']) ||
                    (isset($filter['features']) && in_array($declaredFeatures[$featureId]['key'] ?? null, $filter['features']))
                ) {
                    $variables = $change['data']['variables_data'] ?? null;

                    if ($variables === null) {
                        $this->_loggerManager?->warn(
                            'FeatureManager.runFeatures()',
                            Messages::FEATURE_VARIABLES_NOT_FOUND
                        );
                    }

                    // Convert variables values types if typeCasting is enabled
                    if ($typeCasting && !empty($variables)) {
                        foreach ($variables as $variableName => $value) {
                            $variableDefinition = null;
                            foreach ($declaredFeatures[$featureId]['variables'] ?? [] as $obj) {
                                if ($obj['key'] === $variableName) {
                                    $variableDefinition = $obj;
                                    break;
                                }
                            }
                            if ($variableDefinition && isset($variableDefinition['type'])) {
                                $variables[$variableName] = $this->castType($value, $variableDefinition['type']);
                            } else {
                                $this->_loggerManager?->warn(
                                    'FeatureManager.runFeatures()',
                                    Messages::FEATURE_VARIABLES_TYPE_NOT_FOUND
                                );
                            }
                        }
                    }

                    // Build the bucketed feature object
                    $bucketedFeature = array_merge(
                        [
                            'experienceId' => $bucketedVariation['experienceId'] ?? null,
                            'experienceName' => $bucketedVariation['experienceName'] ?? null,
                            'experienceKey' => $bucketedVariation['experienceKey'] ?? null
                        ],
                        [
                            'key' => $declaredFeatures[$featureId]['key'] ?? null,
                            'name' => $declaredFeatures[$featureId]['name'] ?? null,
                            'id' => $featureId,
                            'status' => FeatureStatus::Enabled->value,
                            'variables' => $variables
                        ]
                    );
                    $bucketedFeatures[] = $bucketedFeature;
                }
            }
        }

        // Extend the list with not enabled features only if no features filter is provided
        if (!isset($filter['features'])) {
            $bucketedFeaturesIds = array_column($bucketedFeatures, 'id');
            foreach ($declaredFeatures as $declaredFeature) {
                if (!in_array($declaredFeature['id'], $bucketedFeaturesIds)) {
                    $bucketedFeatures[] = [
                        'id' => $declaredFeature['id'],
                        'name' => $declaredFeature['name'] ?? null,
                        'key' => $declaredFeature['key'] ?? null,
                        'status' => FeatureStatus::Disabled->value
                    ];
                }
            }
        }

        return $bucketedFeatures;
    }

    /**
     * Convert value's type
     *
     * @param mixed $value The value to cast
     * @param string $type The target type
     * @return mixed The casted value
     */
    public function castType($value, string $type)
    {
        // Assuming a utility function exists for type casting
        return TypeUtils::castType($value, $type);
    }
}