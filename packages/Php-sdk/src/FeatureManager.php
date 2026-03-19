<?php

declare(strict_types=1);

namespace ConvertSdk;

use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use OpenAPI\Client\Model\ConfigFeature;
use OpenAPI\Client\BucketingAttributes;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\VariationChangeType;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Utils\TypeUtils;

/**
 * Manages features within the Convert SDK, handling bucketing and feature status.
 */
final class FeatureManager implements FeatureManagerInterface
{
    /**
     * @param DataManagerInterface $dataManager Data manager instance
     * @param LogManagerInterface|null $logManager Optional logger instance
     */
    public function __construct(
        private readonly DataManagerInterface $dataManager,
        private readonly ?LogManagerInterface $logManager = null,
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
     * @return array<string, mixed> Returns a single bucketed feature array or array of feature arrays
     */
    public function runFeature(
        string $visitorId,
        string $featureKey,
        BucketingAttributes $attributes,
        ?array $experienceKeys = null
    ): array {
        $this->logManager?->debug('FeatureManager.runFeature()', [
            'visitorId' => $visitorId,
            'featureKey' => $featureKey,
        ]);

        $declaredFeature = $this->dataManager->getEntity($featureKey, 'features');
        if ($declaredFeature) {
            $features = $this->runFeatures($visitorId, $attributes, [
                'features' => [$featureKey],
                'experiences' => $experienceKeys
            ]);

            // Filter out RuleError items (runFeatures may return them)
            $validFeatures = array_filter($features, fn($f) => is_array($f));

            if (!empty($validFeatures)) {
                $result = count($validFeatures) === 1
                    ? reset($validFeatures)
                    : array_values($validFeatures);

                $this->logManager?->debug('FeatureManager.runFeature()', [
                    'featureKey' => $featureKey,
                    'status' => FeatureStatus::Enabled->value,
                ]);

                return $result;
            }

            $this->logManager?->debug('FeatureManager.runFeature()', [
                'featureKey' => $featureKey,
                'status' => FeatureStatus::Disabled->value,
            ]);

            return [
                'id' => $declaredFeature['id'],
                'name' => $declaredFeature['name'],
                'key' => $featureKey,
                'status' => FeatureStatus::Disabled->value
            ];
        } else {
            if ($this->logManager) {
                $availableKeys = array_map(
                    fn($f) => $f['key'] ?? 'unknown',
                    $this->dataManager->getEntitiesList('features')
                );
                $this->logManager->debug('FeatureManager.runFeature()', [
                    'featureKey' => $featureKey,
                    'reason' => Messages::NULL_RETURN_FEATURE_NOT_FOUND,
                    'availableKeys' => $availableKeys,
                ]);
            }

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
        $this->logManager?->debug('FeatureManager.isFeatureEnabled()', [
            'visitorId' => $visitorId,
            'featureKey' => $featureKey,
        ]);

        $declaredFeature = $this->dataManager->getEntity($featureKey, 'features');

        if ($declaredFeature) {
            $features = $this->runFeatures($visitorId, $attributes, [
                'features' => [$featureKey],
                'experiences' => $experienceKeys
            ]);
            $validFeatures = array_filter($features, fn($f) => is_array($f));
            $enabled = !empty($validFeatures);

            $this->logManager?->debug('FeatureManager.isFeatureEnabled()', [
                'featureKey' => $featureKey,
                'enabled' => $enabled,
            ]);

            return $enabled;
        }

        if ($this->logManager) {
            $availableKeys = array_map(
                fn($f) => $f['key'] ?? 'unknown',
                $this->dataManager->getEntitiesList('features')
            );
            $this->logManager->debug('FeatureManager.isFeatureEnabled()', [
                'featureKey' => $featureKey,
                'enabled' => false,
                'reason' => Messages::NULL_RETURN_FEATURE_NOT_FOUND,
                'availableKeys' => $availableKeys,
            ]);
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
     * @return array<string, mixed> Returns a single bucketed feature array or array of feature arrays
     */
    public function runFeatureById(
        string $visitorId,
        string $featureId,
        BucketingAttributes $attributes,
        ?array $experienceIds = null
    ): array {
        $this->logManager?->debug('FeatureManager.runFeatureById()', [
            'visitorId' => $visitorId,
            'featureId' => $featureId,
        ]);

        $declaredFeature = $this->dataManager->getEntityById($featureId, 'features');

        if ($declaredFeature) {
            $experienceKeys = $experienceIds ? array_map(function ($exp) {
                return $exp['key'];
            }, $this->dataManager->getEntitiesByIds($experienceIds, 'experiences')) : null;

            $features = $this->runFeatures($visitorId, $attributes, [
                'features' => [$declaredFeature['key']],
                'experiences' => $experienceKeys
            ]);

            // Filter out RuleError items
            $validFeatures = array_filter($features, fn($f) => is_array($f));

            if (!empty($validFeatures)) {
                $this->logManager?->debug('FeatureManager.runFeatureById()', [
                    'featureId' => $featureId,
                    'status' => FeatureStatus::Enabled->value,
                ]);

                if (count($validFeatures) === 1) {
                    return reset($validFeatures);
                } else {
                    return array_values($validFeatures);
                }
            }

            $this->logManager?->debug('FeatureManager.runFeatureById()', [
                'featureId' => $featureId,
                'status' => FeatureStatus::Disabled->value,
            ]);

            return [
                'id' => $featureId,
                'name' => $declaredFeature['name'],
                'key' => $declaredFeature['key'],
                'status' => FeatureStatus::Disabled->value
            ];
        } else {
            if ($this->logManager) {
                $availableIds = array_map(
                    fn($f) => $f['id'] ?? 'unknown',
                    $this->dataManager->getEntitiesList('features')
                );
                $this->logManager->debug('FeatureManager.runFeatureById()', [
                    'featureId' => $featureId,
                    'reason' => Messages::NULL_RETURN_FEATURE_NOT_FOUND,
                    'availableIds' => $availableIds,
                ]);
            }

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
        if ($this->logManager) {
            $this->logManager->debug('FeatureManager.runFeatures()', [
                'visitorId' => $visitorId,
                'filter' => $filter,
            ]);
        }

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
                $changes = $bucketedVariation['changes'] ?? [];
            }
            foreach ($changes as $change) {
                if (($change['type'] ?? null) !== VariationChangeType::FullstackFeature->value) {
                    $this->logManager?->warn(
                        'FeatureManager.runFeatures()',
                        Messages::VARIATION_CHANGE_NOT_SUPPORTED
                    );
                    continue;
                }
                $featureId = $change['data']['feature_id'] ?? null;
                if (!$featureId) {
                    $this->logManager?->warn(
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
                        $this->logManager?->warn(
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
                                $this->logManager?->warn(
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

        if ($this->logManager) {
            $enabledCount = count(array_filter($bucketedFeatures, fn($f) => ($f['status'] ?? '') === FeatureStatus::Enabled->value));
            $disabledCount = count($bucketedFeatures) - $enabledCount;
            $this->logManager->debug('FeatureManager.runFeatures()', [
                'visitorId' => $visitorId,
                'totalFeatures' => count($bucketedFeatures),
                'enabled' => $enabledCount,
                'disabled' => $disabledCount,
            ]);
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
