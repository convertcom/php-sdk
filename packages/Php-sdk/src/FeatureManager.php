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

/**
 * Manages features within the Convert SDK, handling bucketing and feature status.
 */
final class FeatureManager implements FeatureManagerInterface
{
    /**
     * @param Config $config SDK configuration
     * @param DataManagerInterface $dataManager Data manager instance
     * @param LogManagerInterface|null $loggerManager Optional logger instance
     */
    public function __construct(
        private readonly Config $config,
        private readonly DataManagerInterface $dataManager,
        private readonly ?LogManagerInterface $loggerManager = null,
    ) {}

    /**
     * Get a list of all features.
     *
     * @return array<int, ConfigFeature> List of features
     */
    public function getList(): array
    {
        return $this->dataManager->getEntitiesList('features');
    }

    /**
     * Get a list of all features as object grouped by identity field.
     *
     * @param string $field A field to group entities, defaults to 'id'
     * @return array<string, ConfigFeature> Features grouped by field
     */
    public function getListAsObject(string $field): array
    {
        return $this->dataManager->getEntitiesListObject('features', $field);
    }

    /**
     * Get a feature entity by key.
     *
     * @param string $key Feature key
     * @return ConfigFeature The feature
     */
    public function getFeature(string $key): ConfigFeature
    {
        return new ConfigFeature($this->dataManager->getEntity($key, 'features'));
    }

    /**
     * Get a feature entity by ID.
     *
     * @param string $id Feature ID
     * @return ConfigFeature The feature
     */
    public function getFeatureById(string $id): ConfigFeature
    {
        return new ConfigFeature($this->dataManager->getEntityById($id, 'features'));
    }

    /**
     * Get specific features by array of keys.
     *
     * @param array<int, string> $keys Feature keys
     * @return array<int, ConfigFeature> Matching features
     */
    public function getFeatures(array $keys): array
    {
        return $this->dataManager->getItemsByKeys($keys, 'features');
    }

    /**
     * Get a specific variable type defined in a specific feature.
     *
     * @param string $key A feature's key
     * @param string $variableName Variable name
     * @return string|null The variable type or null
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
     * Get a specific variable type defined in a specific feature by ID.
     *
     * @param string $id A feature's ID
     * @param string $variableName Variable name
     * @return string|null The variable type or null
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
     * Check that feature is declared.
     *
     * @param string $key Feature key
     * @return bool True if feature exists
     */
    public function isFeatureDeclared(string $key): bool
    {
        $declaredFeature = $this->dataManager->getEntity($key, 'features');
        return $declaredFeature !== null;
    }

    /**
     * Get feature and its status.
     *
     * @param string $visitorId Visitor identifier
     * @param string $featureKey Feature key
     * @param BucketingAttributes $attributes Bucketing attributes
     * @param array<int, string>|null $experienceKeys Optional array of experience keys
     * @return mixed Returns a single bucketed feature, rule error, or array of features/errors
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
                    return $features[0];
                } else {
                    return $features;
                }
            }

            return [
                'id' => $declaredFeature['id'],
                'name' => $declaredFeature['name'],
                'key' => $featureKey,
                'status' => FeatureStatus::Disabled->value
            ];
        } else {
            return [
                'key' => $featureKey,
                'status' => FeatureStatus::Disabled->value
            ];
        }
    }

    /**
     * Check if feature is enabled.
     *
     * @param string $visitorId Visitor identifier
     * @param string $featureKey Feature key
     * @param BucketingAttributes $attributes Bucketing attributes
     * @param array<int, string>|null $experienceKeys Optional array of experience keys
     * @return bool True if feature is enabled
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
     * Get feature and its status by ID.
     *
     * @param string $visitorId Visitor identifier
     * @param string $featureId Feature ID
     * @param BucketingAttributes $attributes Bucketing attributes
     * @param array<int, string>|null $experienceIds Optional array of experience IDs
     * @return mixed Returns a single bucketed feature, rule error, or array of features/errors
     */
    public function runFeatureById(
        string $visitorId,
        string $featureId,
        BucketingAttributes $attributes,
        ?array $experienceIds = null
    ) {
        $declaredFeature = $this->dataManager->getEntityById($featureId, 'features');

        if ($declaredFeature) {
            $experienceKeys = $experienceIds ? array_map(function ($exp) {
                return $exp['key'];
            }, $this->dataManager->getEntitiesByIds($experienceIds, 'experiences')) : null;

            $features = $this->runFeatures($visitorId, $attributes, [
                'features' => [$declaredFeature['key']],
                'experiences' => $experienceKeys
            ]);

            if (!empty($features)) {
                if (count($features) === 1) {
                    return $features[0];
                } else {
                    $matchedErrors = array_filter($features, function ($match) {
                        return $match instanceof RuleError;
                    });
                    if (!empty($matchedErrors)) {
                        return $matchedErrors;
                    }
                    return $features;
                }
            }

            return [
                'id' => $featureId,
                'name' => $declaredFeature['name'],
                'key' => $declaredFeature['key'],
                'status' => FeatureStatus::Disabled->value
            ];
        } else {
            return [
                'id' => $featureId,
                'status' => FeatureStatus::Disabled->value
            ];
        }
    }

    /**
     * Get features and their statuses.
     *
     * @param string $visitorId The unique identifier for the visitor
     * @param BucketingAttributes $attributes The bucketing attributes object
     * @param array<string, mixed>|null $filter Filter records by experiences and/or features keys
     * @return array<int, mixed> Array of bucketed features or rule errors
     */
    public function runFeatures(string $visitorId, BucketingAttributes $attributes, ?array $filter = null): array
    {
        $typeCasting = $attributes->getTypeCasting() ?? true;

        $declaredFeatures = $this->getListAsObject('id');

        $bucketedFeatures = [];
        $experiences = (!empty($filter['experiences']))
            ? $this->dataManager->getEntities($filter['experiences'], 'experiences')
            : $this->dataManager->getEntitiesList('experiences');

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

        $matchedErrors = array_filter($bucketedVariations, function ($match) {
            return $match instanceof RuleError;
        });
        if (!empty($matchedErrors)) {
            return $matchedErrors;
        }

        foreach ($bucketedVariations as $bucketedVariation) {
            $changes = [];
            if (is_array($bucketedVariation)) {
                $changes = end($bucketedVariation)["changes"];
            }
            foreach ($changes as $change) {
                if (($change['type'] ?? null) !== VariationChangeType::FullstackFeature->value) {
                    $this->loggerManager?->warn(
                        'FeatureManager.runFeatures()',
                        Messages::VARIATION_CHANGE_NOT_SUPPORTED
                    );
                    continue;
                }
                $featureId = $change['data']['feature_id'] ?? null;
                if (!$featureId) {
                    $this->loggerManager?->warn(
                        'FeatureManager.runFeatures()',
                        Messages::FEATURE_NOT_FOUND
                    );
                    continue;
                }

                if (
                    !isset($filter['features']) ||
                    (isset($filter['features']) && in_array($declaredFeatures[$featureId]['key'] ?? null, $filter['features']))
                ) {
                    $variables = $change['data']['variables_data'] ?? null;

                    if ($variables === null) {
                        $this->loggerManager?->warn(
                            'FeatureManager.runFeatures()',
                            Messages::FEATURE_VARIABLES_NOT_FOUND
                        );
                    }

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
                                $this->loggerManager?->warn(
                                    'FeatureManager.runFeatures()',
                                    Messages::FEATURE_VARIABLES_TYPE_NOT_FOUND
                                );
                            }
                        }
                    }

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
     * Convert value's type.
     *
     * @param mixed $value The value to cast
     * @param string $type The target type
     * @return mixed The casted value
     */
    public function castType(mixed $value, string $type): mixed
    {
        return TypeUtils::castType($value, $type);
    }
}
