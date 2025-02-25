<?php
namespace ConvertSdk;

use ConvertSdk\Interfaces\ContextInterface;
use ConvertSdk\Enums\ERROR_MESSAGES;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\SystemEvents;

/**
 * Class Context
 *
 * Provides visitor context.
 *
 * @package ConvertSdk
 */
class Context implements ContextInterface
{
    /** @var mixed */
    private $_eventManager;
    /** @var mixed */
    private $_experienceManager;
    /** @var mixed */
    private $_featureManager;
    /** @var mixed */
    private $_dataManager;
    /** @var mixed */
    private $_segmentsManager;
    /** @var mixed */
    private $_apiManager;
    /** @var mixed|null */
    private $_loggerManager;
    /** @var array */
    private $_config;
    /** @var string */
    private $_visitorId;
    /** @var array */
    private $_visitorProperties = [];
    /** @var string */
    private $_environment;

    /**
     * Context constructor.
     *
     * @param array $config
     * @param string $visitorId
     * @param array $dependencies An associative array containing:
     *   - eventManager
     *   - experienceManager
     *   - featureManager
     *   - segmentsManager
     *   - dataManager
     *   - apiManager
     *   - (optional) loggerManager
     * @param array|null $visitorProperties Optional visitor properties.
     */
    public function __construct(array $config, string $visitorId, array $dependencies, ?array $visitorProperties = null)
    {
        $this->_environment = isset($config['environment']) ? $config['environment'] : '';
        $this->_visitorId = $visitorId;
        $this->_config = $config;
        $this->_eventManager = $dependencies['eventManager'];
        $this->_experienceManager = $dependencies['experienceManager'];
        $this->_featureManager = $dependencies['featureManager'];
        $this->_dataManager = $dependencies['dataManager'];
        $this->_segmentsManager = $dependencies['segmentsManager'];
        $this->_apiManager = $dependencies['apiManager'];
        $this->_loggerManager = $dependencies['loggerManager'] ?? null;

        if (objectNotEmpty($visitorProperties)) {
            $data = $this->_dataManager->filterReportSegments($visitorProperties);
            if (isset($data['properties'])) {
                $this->_visitorProperties = $data['properties'];
            }
            $this->_segmentsManager->putSegments($visitorId, $visitorProperties);
        }
    }

    /**
     * Get variation from specific experience.
     *
     * @param string $experienceKey
     * @param array|null $attributes (Optional) BucketingAttributes with keys such as:
     *        - visitorProperties, locationProperties, updateVisitorProperties, environment.
     * @return mixed Returns a BucketedVariation, RuleError, or BucketingError.
     */
    public function runExperience(string $experienceKey, ?array $attributes = null)
    {
        if (!$this->_visitorId) {
            if ($this->_loggerManager && method_exists($this->_loggerManager, 'error')) {
                $this->_loggerManager->error('Context.runExperience()', ERROR_MESSAGES::VISITOR_ID_REQUIRED);
            }
            return null;
        }

        $visitorProperties = $this->getVisitorProperties($attributes['visitorProperties'] ?? null);
        $params = [
            'visitorProperties'       => $visitorProperties,
            'locationProperties'      => $attributes['locationProperties'] ?? null,
            'updateVisitorProperties' => $attributes['updateVisitorProperties'] ?? null,
            'environment'             => $attributes['environment'] ?? $this->_environment,
        ];

        $bucketedVariation = $this->_experienceManager->selectVariation($this->_visitorId, $experienceKey, $params);

        // Check if result is a RuleError or BucketingError.
        if (in_array($bucketedVariation, RuleError::getValues(), true)) {
            return $bucketedVariation;
        }
        if (in_array($bucketedVariation, BucketingError::getValues(), true)) {
            return $bucketedVariation;
        }
        if ($bucketedVariation) {
            $this->_eventManager->fire(
                SystemEvents::BUCKETING,
                [
                    'visitorId'     => $this->_visitorId,
                    'experienceKey' => $experienceKey,
                    'variationKey'  => $bucketedVariation->key
                ],
                null,
                true
            );
        }
        return $bucketedVariation;
    }

    /**
     * Get variations across all experiences.
     *
     * @param array|null $attributes (Optional) BucketingAttributes.
     * @return array Array of BucketedVariation, RuleError, or BucketingError.
     */
    public function runExperiences(?array $attributes = null): array
    {
        if (!$this->_visitorId) {
            if ($this->_loggerManager && method_exists($this->_loggerManager, 'error')) {
                $this->_loggerManager->error('Context.runExperiences()', ERROR_MESSAGES::VISITOR_ID_REQUIRED);
            }
            return [];
        }

        $visitorProperties = $this->getVisitorProperties($attributes['visitorProperties'] ?? null);
        $params = [
            'visitorProperties'       => $visitorProperties,
            'locationProperties'      => $attributes['locationProperties'] ?? null,
            'updateVisitorProperties' => $attributes['updateVisitorProperties'] ?? null,
            'environment'             => $attributes['environment'] ?? $this->_environment,
        ];
        $bucketedVariations = $this->_experienceManager->selectVariations($this->_visitorId, $params);

        // If rule errors are present, return them.
        $matchedRuleErrors = array_filter($bucketedVariations, function ($match) {
            return in_array($match, RuleError::getValues(), true);
        });
        if (!empty($matchedRuleErrors)) {
            return array_values($matchedRuleErrors);
        }

        // If bucketing errors are present, return them.
        $matchedBucketingErrors = array_filter($bucketedVariations, function ($match) {
            return in_array($match, BucketingError::getValues(), true);
        });
        if (!empty($matchedBucketingErrors)) {
            return array_values($matchedBucketingErrors);
        }

        foreach ($bucketedVariations as $variation) {
            $this->_eventManager->fire(
                SystemEvents::BUCKETING,
                [
                    'visitorId'     => $this->_visitorId,
                    'experienceKey' => $variation->experienceKey,
                    'variationKey'  => $variation->key
                ],
                null,
                true
            );
        }
        return $bucketedVariations;
    }

    /**
     * Get feature and its status.
     *
     * @param string $key Feature key.
     * @param array|null $attributes (Optional) BucketingAttributes.
     * @return mixed Returns a BucketedFeature, RuleError, or an array of BucketedFeature|RuleError.
     */
    public function runFeature(string $key, ?array $attributes = null)
    {
        if (!$this->_visitorId) {
            if ($this->_loggerManager && method_exists($this->_loggerManager, 'error')) {
                $this->_loggerManager->error('Context.runFeature()', ERROR_MESSAGES::VISITOR_ID_REQUIRED);
            }
            return null;
        }

        $visitorProperties = $this->getVisitorProperties($attributes['visitorProperties'] ?? null);
        $params = [
            'visitorProperties'       => $visitorProperties,
            'locationProperties'      => $attributes['locationProperties'] ?? null,
            'updateVisitorProperties' => $attributes['updateVisitorProperties'] ?? null,
            'typeCasting'             => array_key_exists('typeCasting', $attributes ?? []) ? $attributes['typeCasting'] : true,
            'environment'             => $attributes['environment'] ?? $this->_environment,
        ];

        $bucketedFeature = $this->_featureManager->runFeature($this->_visitorId, $key, $params, $attributes['experienceKeys'] ?? null);

        if (is_array($bucketedFeature)) {
            $matchedErrors = array_filter($bucketedFeature, function ($match) {
                return in_array($match, RuleError::getValues(), true);
            });
            if (!empty($matchedErrors)) {
                return array_values($matchedErrors);
            }
            foreach ($bucketedFeature as $feature) {
                $this->_eventManager->fire(
                    SystemEvents::BUCKETING,
                    [
                        'visitorId'     => $this->_visitorId,
                        'experienceKey' => $feature->experienceKey,
                        'featureKey'    => $key,
                        'status'        => $feature->status
                    ],
                    null,
                    true
                );
            }
        } else {
            if (in_array($bucketedFeature, RuleError::getValues(), true)) {
                return $bucketedFeature;
            }
            if ($bucketedFeature) {
                $this->_eventManager->fire(
                    SystemEvents::BUCKETING,
                    [
                        'visitorId'     => $this->_visitorId,
                        'experienceKey' => $bucketedFeature->experienceKey,
                        'featureKey'    => $key,
                        'status'        => $bucketedFeature->status
                    ],
                    null,
                    true
                );
            }
        }
        return $bucketedFeature;
    }

    /**
     * Get features and their statuses.
     *
     * @param array|null $attributes (Optional) BucketingAttributes.
     * @return array Array of BucketedFeature or RuleError.
     */
    public function runFeatures(?array $attributes = null): array
    {
        if (!$this->_visitorId) {
            if ($this->_loggerManager && method_exists($this->_loggerManager, 'error')) {
                $this->_loggerManager->error('Context.runFeatures()', ERROR_MESSAGES::VISITOR_ID_REQUIRED);
            }
            return [];
        }
        $visitorProperties = $this->getVisitorProperties($attributes['visitorProperties'] ?? null);
        $params = [
            'visitorProperties'       => $visitorProperties,
            'locationProperties'      => $attributes['locationProperties'] ?? null,
            'updateVisitorProperties' => $attributes['updateVisitorProperties'] ?? null,
            'typeCasting'             => array_key_exists('typeCasting', $attributes ?? []) ? $attributes['typeCasting'] : true,
            'environment'             => $attributes['environment'] ?? $this->_environment,
        ];
        $bucketedFeatures = $this->_featureManager->runFeatures($this->_visitorId, $params);

        $matchedErrors = array_filter($bucketedFeatures, function ($match) {
            return in_array($match, RuleError::getValues(), true);
        });
        if (!empty($matchedErrors)) {
            return array_values($matchedErrors);
        }
        foreach ($bucketedFeatures as $feature) {
            $this->_eventManager->fire(
                SystemEvents::BUCKETING,
                [
                    'visitorId'     => $this->_visitorId,
                    'experienceKey' => $feature->experienceKey,
                    'featureKey'    => $feature->key,
                    'status'        => $feature->status
                ],
                null,
                true
            );
        }
        return $bucketedFeatures;
    }

    /**
     * Trigger Conversion.
     *
     * @param string $goalKey A goal key.
     * @param array|null $attributes (Optional) ConversionAttributes with keys such as ruleData, conversionData, conversionSetting.
     * @return mixed Returns a RuleError.
     */
    public function trackConversion(string $goalKey, ?array $attributes = null)
    {
        if (!$this->_visitorId) {
            if ($this->_loggerManager && method_exists($this->_loggerManager, 'error')) {
                $this->_loggerManager->error('Context.trackConversion()', ERROR_MESSAGES::VISITOR_ID_REQUIRED);
            }
            return null;
        }

        $goalRule = $attributes['ruleData'] ?? null;
        $goalData = $attributes['conversionData'] ?? null;
        if ($goalData && !is_array($goalData)) {
            if ($this->_loggerManager && method_exists($this->_loggerManager, 'error')) {
                $this->_loggerManager->error('Context.trackConversion()', ERROR_MESSAGES::GOAL_DATA_NOT_VALID);
            }
            return null;
        }
        $segments = $this->_segmentsManager->getSegments($this->_visitorId);
        $triggered = $this->_dataManager->convert(
            $this->_visitorId,
            $goalKey,
            $goalRule,
            $goalData,
            $segments,
            $attributes['conversionSetting'] ?? null
        );
        if (in_array($triggered, RuleError::getValues(), true)) {
            return $triggered;
        }
        if ($triggered) {
            $this->_eventManager->fire(
                SystemEvents::CONVERSION,
                [
                    'visitorId' => $this->_visitorId,
                    'goalKey'   => $goalKey
                ],
                null,
                true
            );
        }
        return $triggered;
    }

    /**
     * Set default segments for reports.
     *
     * @param array $segments A VisitorSegments array.
     * @return void
     */
    public function setDefaultSegments(array $segments): void
    {
        $this->_segmentsManager->putSegments($this->_visitorId, $segments);
    }

    /**
     * To be deprecated.
     *
     * @param array $segmentKeys
     * @param array|null $attributes (Optional) SegmentsAttributes.
     * @return mixed Returns a RuleError.
     */
    public function setCustomSegments(array $segmentKeys, ?array $attributes = null)
    {
        return $this->runCustomSegments($segmentKeys, $attributes);
    }

    /**
     * Match Custom segments.
     *
     * @param array $segmentKeys A list of segment keys.
     * @param array|null $attributes (Optional) SegmentsAttributes with a key ruleData.
     * @return mixed Returns a RuleError.
     */
    public function runCustomSegments(array $segmentKeys, ?array $attributes = null)
    {
        if (!$this->_visitorId) {
            if ($this->_loggerManager && method_exists($this->_loggerManager, 'error')) {
                $this->_loggerManager->error('Context.runCustomSegments()', ERROR_MESSAGES::VISITOR_ID_REQUIRED);
            }
            return null;
        }
        $segmentsRule = $this->getVisitorProperties($attributes['ruleData'] ?? null);
        $error = $this->_segmentsManager->selectCustomSegments($this->_visitorId, $segmentKeys, $segmentsRule);
        if ($error) {
            return $error;
        }
        return null;
    }

    /**
     * Update visitor properties in memory.
     *
     * @param string $visitorId
     * @param array $visitorProperties
     * @return void
     */
    public function updateVisitorProperties(string $visitorId, array $visitorProperties): void
    {
        $this->_dataManager->putData($visitorId, ['segments' => $visitorProperties]);
    }

    /**
     * Get configuration entity.
     *
     * @param string $key
     * @param mixed $entityType
     * @return mixed An Entity.
     */
    public function getConfigEntity(string $key, $entityType)
    {
        if ($entityType === \ConvertSdk\Enums\EntityType::VARIATION) {
            $experiences = $this->_dataManager->getEntitiesList(\ConvertSdk\Enums\EntityType::EXPERIENCE);
            if (is_array($experiences)) {
                foreach ($experiences as $experience) {
                    // Assume each $experience has a property 'key'
                    $variation = $this->_dataManager->getSubItem(
                        'experiences',
                        $experience->key,
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
        }
        return $this->_dataManager->getEntity($key, $entityType);
    }

    /**
     * Get configuration entity by ID.
     *
     * @param string $id
     * @param mixed $entityType
     * @return mixed An Entity.
     */
    public function getConfigEntityById(string $id, $entityType)
    {
        if ($entityType === \ConvertSdk\Enums\EntityType::VARIATION) {
            $experiences = $this->_dataManager->getEntitiesList(\ConvertSdk\Enums\EntityType::EXPERIENCE);
            if (is_array($experiences)) {
                foreach ($experiences as $experience) {
                    $variation = $this->_dataManager->getSubItem(
                        'experiences',
                        $experience->id,
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
        }
        return $this->_dataManager->getEntityById($id, $entityType);
    }

    /**
     * Get visitor data.
     *
     * @return array A StoreData array.
     */
    public function getVisitorData(): array
    {
        return $this->_dataManager->getData($this->_visitorId) ?? [];
    }

    /**
     * Send pending API/DataStore queues to server.
     *
     * @param string|null $reason
     * @return mixed A promise-like object.
     */
    public function releaseQueues(?string $reason = null)
    {
        if (isset($this->_dataManager->dataStoreManager)) {
            $this->_dataManager->dataStoreManager->releaseQueue($reason);
        }
        return $this->_apiManager->releaseQueue($reason);
    }

    /**
     * Get visitor properties.
     *
     * @param array|null $attributes Optional key-value pairs used for audience targeting.
     * @return array
     */
    private function getVisitorProperties(?array $attributes = null): array
    {
        $data = $this->_dataManager->getData($this->_visitorId) ?? [];
        $segments = $data['segments'] ?? [];
        $visitorProperties = $attributes ? objectDeepMerge($this->_visitorProperties ?? [], $attributes) : $this->_visitorProperties;
        return objectDeepMerge($segments, $visitorProperties ?? []);
    }
}
