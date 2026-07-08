<?php

declare(strict_types=1);
/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\ConversionSettingKey;
use ConvertSdk\Enums\DataEntities;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\RuleType;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\BucketingManagerInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\DataStoreManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\RuleManagerInterface;
use ConvertSdk\Utils\ArrayUtils;
use ConvertSdk\Utils\LogUtils;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Utils\StringUtils;
use OpenAPI\Client\BucketedVariation;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Entity;
use OpenAPI\Client\GoalData;
use OpenAPI\Client\IdentityField;
use OpenAPI\Client\LocationAttributes;
use OpenAPI\Client\Model\ConfigAudienceTypes;
use OpenAPI\Client\Model\ConfigExperience;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\ExperienceVariationConfig;
use OpenAPI\Client\Model\GenericListMatchingOptions;
use OpenAPI\Client\Model\RuleObject;
use OpenAPI\Client\Model\VariationStatuses;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use OpenAPI\Client\StoreData;

/**
 * Class DataManager
 * Implements the DataManagerInterface for managing data operations in the Convert JS SDK.
 */
final class DataManager implements DataManagerInterface
{
    /**
     * Maximum number of items allowed in local store.
     */
    private const LOCAL_STORE_LIMIT = 10000;
    /**
       * Configuration response data.
       */
    private ConfigResponseData $_data;

    /**
     * Account identifier.
     */
    private ?string $_accountId;

    /**
     * Project identifier.
     */
    private ?string $_projectId;

    /**
     * Configuration object.
     */
    private Config $_config;

    /**
     * Bucketing manager instance.
     */
    private BucketingManagerInterface $_bucketingManager;

    /**
     * Logger manager instance.
     */
    private LogManagerInterface $_loggerManager;

    /**
     * Event manager instance.
     */
    private EventManagerInterface $_eventManager;

    /**
     * Data store manager instance.
     */
    private ?DataStoreManagerInterface $_dataStoreManager;

    /**
     * API manager instance.
     */
    private ApiManagerInterface $_apiManager;

    /**
     * Rule manager instance.
     */
    private RuleManagerInterface $_ruleManager;

    /**
     * Data entities enum.
     */
    private array $_dataEntities;

    /**
     * Local store limit.
     */
    private int $_localStoreLimit = self::LOCAL_STORE_LIMIT;

    /**
     * Map of bucketed visitors (visitor ID => data).
     */
    private array $_bucketedVisitors = [];

    /**
     * Flag indicating if storage is asynchronous.
     */

    /**
     * Environment string.
     */
    private string $_environment;

    /**
     * Mapper function for transforming data.
     */
    private \Closure $_mapper;

    /**
     * DataManager constructor.
     *
     * @param Config $config
     * @param BucketingManagerInterface $bucketingManager
     * @param RuleManagerInterface $ruleManager
     * @param EventManagerInterface $eventManager
     * @param ApiManagerInterface $apiManager
     * @param LogManagerInterface|null $loggerManager
     */
    public function __construct(
        Config $config,
        BucketingManagerInterface $bucketingManager,
        RuleManagerInterface $ruleManager,
        EventManagerInterface $eventManager,
        ApiManagerInterface $apiManager,
        ?LogManagerInterface $loggerManager = null,
    ) {
        $this->_environment = $config->getEnvironment();
        $this->_apiManager = $apiManager;
        $this->_bucketingManager = $bucketingManager;
        $this->_ruleManager = $ruleManager;
        $this->_loggerManager = $loggerManager;
        $this->_eventManager = $eventManager;
        $this->_config = $config;
        $mapper = $config->getMapper();
        $this->_mapper = $mapper instanceof \Closure ? $mapper : ($mapper !== null ? \Closure::fromCallable($mapper) : fn ($value) => $value);
        $this->_data = $config->getData() ?? new ConfigResponseData();
        $this->_accountId = $this->_data ? $this->_data->getAccountId() : '';
        $project = $this->_data ? $this->_data->getProject() : null;
        $this->_projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
        $this->_dataStoreManager = null;
        $rawDataStore = $config->getDataStore();
        if ($rawDataStore !== null) {
            $this->setDataStore($rawDataStore);
        }
        $this->_dataEntities = DataEntities::DATA_ENTITIES;
        $this->_loggerManager?->trace(
            'DataManager()',
            Messages::DATA_CONSTRUCTOR,
            null
        );
    }

    /**
     * Get the configuration data.
     *
     * @return ConfigResponseData
     */
    public function getConfigData(): ConfigResponseData
    {
        return $this->_data;
    }

    /**
     * Set the configuration data.
     *
     * @param ConfigResponseData $data
     * @return void
     */
    public function setConfigData(ConfigResponseData $data): void
    {
        if ($this->isValidConfigData($data)) {
            $this->_data = $data;
            $this->_accountId = $data->account_id ?? null;
            $this->_projectId = $data->project->id ?? null;
        } else {
            $this->_loggerManager?->error(
                'DataManager.setConfigData()',
                ERROR_MESSAGES::CONFIG_DATA_NOT_VALID
            );
        }
    }

    /**
     * Set the data store manager.
     *
     * @param mixed $dataStore Optional data store object
     * @return void
     */
    public function setDataStoreManager(mixed $dataStore): void
    {
        $this->_dataStoreManager = null;
        if ($dataStore) {
            $this->_dataStoreManager = new DataStoreManager(
                $this->_config,
                [
                    'dataStore' => $dataStore,
                    'eventManager' => $this->_eventManager,
                    'loggerManager' => $this->_loggerManager,
                ]
            );
        }
    }

    /**
     * Get the data store manager.
     *
     * @return DataStoreManagerInterface
     */
    public function getDataStoreManager(): ?DataStoreManagerInterface
    {
        return $this->_dataStoreManager;
    }

    /**
     * Set dataStoreManager at run-time.
     *
     * @param mixed $dataStore Optional data store object
     * @return void
     */
    public function setDataStore(mixed $dataStore): void
    {
        $this->_dataStoreManager = null;
        if ($dataStore) {
            $this->_dataStoreManager = new DataStoreManager(
                $this->_config,
                [
                    'dataStore' => $dataStore,
                    'eventManager' => $this->_eventManager,
                    'loggerManager' => $this->_loggerManager,
                ]
            );
        }
    }

    /**
     * Validate locationProperties against locations rules and visitorProperties against audiences rules
     *
     * @param string $visitorId
     * @param string $identity Value of the field which name is provided in identityField
     * @param string $identityField Defaults to 'key'
     * @param BucketingAttributes $attributes
     * @return mixed ConfigExperience or RuleError or null
     */
    public function matchRulesByField(
        string $visitorId,
        string $identity,
        string $identityField,
        BucketingAttributes $attributes
    ): array|RuleError|null {
        // Extract attributes properties
        $visitorProperties = $attributes->visitorProperties ?? null;
        $locationProperties = $attributes->locationProperties ?? null;
        $ignoreLocationProperties = $attributes->ignoreLocationProperties ?? false;
        $environment = $attributes->environment ?? $this->_environment;
        // qs-02 capability (B) preview input — per-context suppression signal,
        // forwarded to selectLocations() below so a preview context's "other
        // experiences evaluate normally" location matching never persists.
        $suppressPersistence = $attributes->suppressPersistence ?? false;

        // Log trace information
        $this->_loggerManager?->trace(
            'DataManager.matchRulesByField()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'visitorId' => $visitorId,
                'identity' => $identity,
                'identityField' => $identityField,
                'visitorProperties' => $visitorProperties,
                'locationProperties' => $locationProperties,
                'ignoreLocationProperties' => $ignoreLocationProperties,
                'environment' => $environment,
            ])))
        );

        // Retrieve the experience
        $experience = $this->_getEntityByField($identity, 'experiences', $identityField);
        if (!$experience) {
            $this->_loggerManager?->debug(
                'DataManager.matchRulesByField()',
                Messages::EXPERIENCE_NOT_FOUND,
                LogUtils::toLoggable(($this->_mapper)([
                    'identity' => $identity,
                    'identityField' => $identityField,
                ]))
            );
            return null;
        }

        // Retrieve archived experiences
        $archivedExperiences = $this->getEntitiesList('archived_experiences');
        // Check if the experience is archived
        $isArchivedExperience = in_array((string)$experience['id'], array_map('strval', $archivedExperiences), true);
        if ($isArchivedExperience) {
            $this->_loggerManager?->debug(
                'DataManager.matchRulesByField()',
                Messages::EXPERIENCE_ARCHIVED,
                LogUtils::toLoggable(($this->_mapper)([
                    'identity' => $identity,
                    'identityField' => $identityField,
                ]))
            );
            return null;
        }

        // Check environment match
        $isEnvironmentMatch = isset($experience['environment']) ? $experience['environment'] === $environment : true;
        if (!$isEnvironmentMatch) {
            $this->_loggerManager?->debug(
                'DataManager.matchRulesByField()',
                Messages::EXPERIENCE_ENVIRONMENT_NOT_MATCH,
                LogUtils::toLoggable(($this->_mapper)([
                    'identity' => $identity,
                    'identityField' => $identityField,
                ]))
            );
            return null;
        }

        // Check bucketing
        $visitorData = $this->getData($visitorId) ?? [];
        $bucketingData = $visitorData['bucketing'] ?? [];
        $variationId = $bucketingData[$experience['id']] ?? null;
        $isBucketed = $variationId && $this->retrieveVariation($experience['id'], (string)$variationId);
        // Check location rules
        $locationMatched = $ignoreLocationProperties === true;
        if (!$locationMatched && $locationProperties) {
            if (isset($experience['locations']) && is_array($experience['locations']) && count($experience['locations']) > 0) {
                $locations = $this->getItemsByIds($experience['locations'], 'locations');
                if (count($locations) > 0) {
                    $matchedLocations = $this->selectLocations($visitorId, $locations, new LocationAttributes([
                        'locationProperties' => $locationProperties,
                        'identityField' => $identityField,
                        'suppressPersistence' => $suppressPersistence,
                    ]));
                    $matchedErrors = array_filter($matchedLocations, fn ($match) => $match instanceof RuleError);
                    if (count($matchedErrors) > 0) {
                        return reset($matchedErrors);
                    }
                    $locationMatched = count($matchedLocations) > 0;
                }
            } elseif (isset($experience['site_area'])) {
                $locationMatched = $this->_ruleManager->isRuleMatched(
                    $locationProperties,
                    new RuleObject($experience['site_area']),
                    'SiteArea'
                );
                if ($locationMatched instanceof RuleError) {
                    return $locationMatched;
                }
            } else {
                $locationMatched = true;
                $this->_loggerManager?->info(
                    'DataManager.matchRulesByField()',
                    Messages::LOCATION_NOT_RESTRICTED
                );
            }
        }
        if (!$locationMatched) {
            $this->_loggerManager?->debug(
                'DataManager.matchRulesByField()',
                Messages::LOCATION_NOT_MATCH,
                json_encode(LogUtils::toLoggable(($this->_mapper)([
                    'locationProperties' => $locationProperties,
                    isset($experience['locations']) ? 'experiences[].variations[].locations' : 'experiences[].variations[].site_area' => $experience['locations'] ?? $experience['site_area'] ?? '',
                ])))
            );
            return null;
        }

        // Check audience rules
        $audiences = [];
        $segments = [];
        $matchedAudiences = [];
        $matchedSegments = [];
        $audiencesToCheck = [];
        $audiencesMatched = false;
        $segmentsMatched = false;

        if (isset($experience['audiences']) && is_array($experience['audiences']) && count($experience['audiences']) > 0) {
            // Hoisted above the visitorProperties gate below: getItemsByIds() is a
            // pure config lookup (no side effects), so fetching it unconditionally
            // lets us inspect the fetched audiences' rule trees for a
            // bucketed_into_experience_key rule (qs-03) before deciding whether the
            // empty-visitorProperties gate applies.
            $audiences = $this->getItemsByIds($experience['audiences'], 'audiences');
            // qs-03 (mutual-exclusion audience rule): a bucketed_into_experience_key
            // rule resolves against SDK-stored visitor bucketing state, not
            // caller-supplied visitor properties, so it must still be evaluated when
            // $visitorProperties is empty (AC4 "zero new application inputs").
            $hasBucketingExclusionAudience = $this->_audiencesContainBucketedIntoExperienceKeyRule($audiences);
            // In PHP, an empty array [] is falsy (unlike JS where {} is truthy).
            // This check correctly requires non-empty visitorProperties to evaluate audience rules
            // -- UNLESS the audience carries a bucketed_into_experience_key rule, which reads
            // stored bucketing state instead of visitor properties (qs-03 AC4). Generic-only
            // audiences (no such rule) keep today's exact gate behavior (AC7).
            if ($visitorProperties || $hasBucketingExclusionAudience) {
                $audiencesToCheck = array_filter(
                    $audiences,
                    fn ($audience) => !($isBucketed && $audience['type'] === ConfigAudienceTypes::PERMANENT)
                );
                if (count($audiencesToCheck) > 0) {
                    $matchedAudiences = $this->filterMatchedRecordsWithRule(
                        $audiencesToCheck,
                        $visitorProperties,
                        'audience',
                        $identityField,
                        $visitorId
                    );
                    $matchedErrors = array_filter($matchedAudiences, fn ($match) => $match instanceof RuleError);
                    if (count($matchedErrors) > 0) {
                        return reset($matchedErrors);
                    }
                    if (count($matchedAudiences) > 0) {
                        foreach ($matchedAudiences as $item) {
                            $this->_loggerManager?->info(
                                'DataManager.matchRulesByField()',
                                str_replace('#', $item[$identityField] ?? '', Messages::AUDIENCE_MATCH)
                            );
                        }
                    }
                    $audiencesMatched = $experience['settings']['matching_options']['audiences'] === GenericListMatchingOptions::ALL
                        ? count($matchedAudiences) === count($audiencesToCheck)
                        : count($matchedAudiences) > 0;
                } else {
                    $audiencesMatched = true;
                    $this->_loggerManager?->info(
                        'DataManager.matchRulesByField()',
                        Messages::NON_PERMANENT_AUDIENCE_NOT_RESTRICTED
                    );
                }
            }
            // If visitorProperties is null/empty, no bucketed_into_experience_key
            // audience is present, and the experience has (other) audiences,
            // audiencesMatched stays false — can't evaluate without properties.
        } else {
            // No audiences on experience — all visitors qualify
            $audiencesMatched = true;
            $this->_loggerManager?->info(
                'DataManager.matchRulesByField()',
                Messages::AUDIENCE_NOT_RESTRICTED
            );
        }

        $segments = $this->getItemsByIds($experience['audiences'] ?? [], 'segments');
        if (count($segments) > 0) {
            $matchedSegments = $this->filterMatchedCustomSegments($segments, $visitorId);
            if (count($matchedSegments) > 0) {
                foreach ($matchedSegments as $item) {
                    $this->_loggerManager?->info(
                        'DataManager.matchRulesByField()',
                        str_replace('#', $item[$identityField] ?? '', Messages::SEGMENTATION_MATCH)
                    );
                }
            }
            $segmentsMatched = count($matchedSegments) > 0;
        } else {
            $segmentsMatched = true;
            $this->_loggerManager?->info(
                'DataManager.matchRulesByField()',
                Messages::SEGMENTATION_NOT_RESTRICTED
            );
        }

        // Final check and return
        if ($audiencesMatched && $segmentsMatched) {
            if (isset($experience['variations']) && is_array($experience['variations']) && count($experience['variations']) > 0) {
                $this->_loggerManager?->info(
                    'DataManager.matchRulesByField()',
                    Messages::EXPERIENCE_RULES_MATCHED
                );
                return $experience;
            } else {
                $this->_loggerManager?->debug(
                    'DataManager.matchRulesByField()',
                    Messages::VARIATIONS_NOT_FOUND,
                    LogUtils::toLoggable(($this->_mapper)([
                        'visitorProperties' => $visitorProperties,
                        'audiences' => $audiences,
                    ]))
                );
            }
        } else {
            $this->_loggerManager?->debug(
                'DataManager.matchRulesByField()',
                Messages::AUDIENCE_NOT_MATCH,
                LogUtils::toLoggable(($this->_mapper)([
                    'visitorProperties' => $visitorProperties,
                    'audiences' => $audiences,
                ]))
            );
        }
        return null;
    }


    /**
     * Retrieve variation for visitor
     *
     * @param string $visitorId
     * @param string $identity Value of the field which name is provided in identityField
     * @param string $identityField Defaults to IdentityField::KEY
     * @param BucketingAttributes $attributes
     * @return mixed BucketedVariation|RuleError|BucketingError|null
     * @throws \InvalidArgumentException If identityField is invalid
     * @private
     */
    private function _getBucketingByField(
        string $visitorId,
        string $identity,
        string $identityField,
        BucketingAttributes $attributes
    ): array|RuleError|BucketingError|null {
        // Validate identityField
        if (!IdentityField::isValid($identityField)) {
            throw new \InvalidArgumentException("Invalid identityField: $identityField. Must be 'id' or 'key'.");
        }

        // Extract attributes properties
        $visitorProperties = $attributes->visitorProperties ?? null;
        $locationProperties = $attributes->locationProperties ?? null;
        $updateVisitorProperties = $attributes->updateVisitorProperties ?? null;
        $forceVariationId = $attributes->forceVariationId ?? null;
        $enableTracking = $attributes->enableTracking ?? true;
        $ignoreLocationProperties = $attributes->ignoreLocationProperties ?? false;
        $environment = $attributes->environment ?? $this->_environment;
        // qs-02 capability (B) preview input — per-context suppression signal.
        $suppressPersistence = $attributes->suppressPersistence ?? false;
        // Log trace information
        $this->_loggerManager?->trace(
            'DataManager._getBucketingByField()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
              'visitorId' => $visitorId,
              'identity' => $identity,
              'identityField' => $identityField,
              'visitorProperties' => $visitorProperties,
              'locationProperties' => $locationProperties,
              'forceVariationId' => $forceVariationId,
              'enableTracking' => $enableTracking,
              'ignoreLocationProperties' => $ignoreLocationProperties,
              'environment' => $environment,
        ])))
        );

        // Retrieve the experience
        $experience = $this->matchRulesByField(
            $visitorId,
            $identity,
            $identityField,
            new BucketingAttributes([
                'visitorProperties' => $visitorProperties,
                'locationProperties' => $locationProperties,
                'ignoreLocationProperties' => $ignoreLocationProperties,
                'environment' => $environment,
                'suppressPersistence' => $suppressPersistence,
            ])
        );
        if ($experience) {
            if ($experience instanceof RuleError) {
                return $experience;
            }
            return $this->_retrieveBucketing(
                $visitorId,
                $visitorProperties,
                $updateVisitorProperties,
                new ConfigExperience($experience),
                $forceVariationId,
                $enableTracking,
                $suppressPersistence
            );
        }

        return null;
    }

    /**
     * Shared "running + non-zero-traffic" active predicate used by BOTH the packed
     * (buildPackedBuckets) and anchored (buildVariationAllocations) layout builders,
     * so both layouts agree on activeness.
     *
     * @param array<string, mixed> $variation
     */
    private function isVariationActive(array $variation): bool
    {
        return (isset($variation['status']) ? $variation['status'] === VariationStatuses::RUNNING : true) &&
            (array_key_exists('traffic_allocation', $variation) ?
                ($variation['traffic_allocation'] > 0 || !is_numeric($variation['traffic_allocation'])) :
                true);
    }

    /**
     * Build buckets where key is variation id and value is traffic distribution
     * (existing packed layout, experience version <= 11, missing, or non-numeric;
     * byte-for-byte unchanged). Version 11 is the version stamped on every experience
     * currently served in production (backend CURRENT_EXPERIENCE_VERSION), so this is
     * the active path for all currently-running experiments.
     *
     * @param array<int, array<string, mixed>> $variations
     * @return array<string, float|int>
     * @private
     */
    private function buildPackedBuckets(array $variations): array
    {
        return array_reduce(
            array_filter(
                $variations,
                fn ($variation) => $this->isVariationActive($variation)
            ),
            function ($carry, $variation) {
                if (!empty($variation['id'])) {
                    $carry[$variation['id']] = $variation['traffic_allocation'] ?? 100.0;
                }
                return $carry;
            },
            []
        );
    }

    /**
     * Build variation allocations for the anchored layout (qs-01, contract v12).
     * Activates only once the served experience version is > 11 (i.e. >= 12, once the
     * backend bumps CURRENT_EXPERIENCE_VERSION past its current value of 11).
     * Inactive arms (stopped, or explicit zero traffic_allocation) keep their weight for
     * anchor stability but are marked inactive so BucketingManager::getBucketRanges()
     * gives them zero width. Uses the shared isVariationActive() predicate, also used
     * by buildPackedBuckets(), so both layouts agree on activeness. See
     * qs-01-anchored-bucketing-layout.md "The contract (normative)".
     *
     * @param array<int, array<string, mixed>> $variations
     * @return array<int, array{id: string, allocation: float, active: bool}>
     * @private
     */
    private function buildVariationAllocations(array $variations): array
    {
        $allocations = [];

        foreach ($variations as $variation) {
            if (empty($variation['id'])) {
                continue;
            }

            $trafficAllocation = array_key_exists('traffic_allocation', $variation)
                ? $variation['traffic_allocation']
                : null;

            $allocations[] = [
                'id' => (string)$variation['id'],
                'allocation' => is_numeric($trafficAllocation) ? (float)$trafficAllocation : 100.0,
                'active' => $this->isVariationActive($variation),
            ];
        }

        return $allocations;
    }

    /**
     * Retrieve variation for visitor
     *
     * @param string $visitorId
     * @param ?array $visitorProperties
     * @param bool $updateVisitorProperties
     * @param ConfigExperience $experience
     * @param ?string $forceVariationId
     * @param bool $enableTracking Defaults to true
     * @param bool $suppressPersistence qs-02 capability (B) preview input — when true,
     *     suppresses the stored-decision write AND the bucketing-event enqueue
     *     regardless of $enableTracking. Defaults to false.
     * @return mixed BucketedVariation array or BucketingError or null
     * @private
     */
    private function _retrieveBucketing(
        string $visitorId,
        ?array $visitorProperties,
        ?bool $updateVisitorProperties,
        ConfigExperience $experience,
        ?string $forceVariationId = null,
        bool $enableTracking = true,
        bool $suppressPersistence = false
    ): array|BucketingError|null {
        // Initial validation
        if (empty($visitorId) || $experience === null || empty($experience->getId())) {
            return null;
        }

        // Initialize variables
        $variation = null;
        $variationId = null;
        $bucketedVariation = null;
        $bucketingAllocation = null;
        $storeKey = $this->getStoreKey($visitorId);
        // Handle forced variation
        if (!empty($forceVariationId) && ($variation = $this->retrieveVariation($experience->getId(), (string)$forceVariationId))) {
            $variationId = $forceVariationId;
            $this->_loggerManager?->info(
                'DataManager._retrieveBucketing()',
                str_replace('#', '#' . $forceVariationId, Messages::BUCKETED_VISITOR_FORCED)
            );
            $this->_loggerManager?->debug(
                'DataManager._retrieveBucketing()',
                LogUtils::toLoggable(($this->_mapper)([
                    'storeKey' => $storeKey,
                    'visitorId' => $visitorId,
                    'variationId' => $forceVariationId,
                ]))
            );
        }

        // Check stored bucketing
        $data = $this->getData($visitorId);
        $bucketing = $data['bucketing'] ?? [];
        $segments = $data['segments'] ?? [];
        $storedVariationId = $bucketing[(string)$experience->getId()] ?? null;

        if (
            !empty($storedVariationId) &&
            (empty($variationId) || (string)$variationId === (string)$storedVariationId) &&
            ($variation = $this->retrieveVariation($experience->getId(), (string)$storedVariationId))
        ) {
            $variationId = $storedVariationId;
            $this->_loggerManager?->info(
                'DataManager._retrieveBucketing()',
                str_replace('#', '#' . $variationId, Messages::BUCKETED_VISITOR_FOUND)
            );
            $this->_loggerManager?->debug(
                'DataManager._retrieveBucketing()',
                json_encode(
                    LogUtils::toLoggable(($this->_mapper)([
                    'storeKey' => $storeKey,
                    'visitorId' => $visitorId,
                    'variationId' => $variationId,
              ]))
                )
            );
        } else {
            // qs-01: anchored-vs-packed GATE. `experience.version > 11` runs the anchored
            // layout (contract v12); version <= 11, missing, or non-numeric keeps the
            // existing packed cumulative walk unchanged -- this is every currently-served
            // production experience (backend CURRENT_EXPERIENCE_VERSION = 11). The SDK
            // must never infer the layout from anything but this field. See
            // qs-01-anchored-bucketing-layout.md "The contract (normative)".
            $version = $experience->getVersion();
            $isAnchoredLayout = is_numeric($version) && (float)$version > 11;

            // Determine bucket for visitor
            $bucketingParams = $this->_config->bucketing->excludeExperienceIdHash ?? false
                ? null
                : ['experienceId' => (string)$experience->getId()];

            if ($isAnchoredLayout) {
                $buckets = $this->buildVariationAllocations($experience->getVariations());
                $bucketing = $this->_bucketingManager->getBucketForVisitorAnchored(
                    $buckets,
                    $visitorId,
                    $bucketingParams
                );
            } else {
                $buckets = $this->buildPackedBuckets($experience->getVariations());
                $bucketing = $this->_bucketingManager->getBucketForVisitor(
                    $buckets,
                    $visitorId,
                    $bucketingParams
                );
            }

            $variationId = $variationId ?? $bucketing['variationId'] ?? null;
            $bucketingAllocation = $bucketing['bucketingAllocation'] ?? null;

            // Handle bucketing failure
            if (empty($variationId)) {
                $this->_loggerManager?->debug(
                    'DataManager._retrieveBucketing()',
                    ErrorMessages::UNABLE_TO_SELECT_BUCKET_FOR_VISITOR,
                    json_encode(LogUtils::toLoggable(($this->_mapper)([
                        'visitorId' => $visitorId,
                        'experience' => $experience,
                        'buckets' => $buckets,
                        'bucketing' => $bucketing,
                    ])))
                );
                return BucketingError::VariationNotDecided;
            }

            $this->_loggerManager?->info(
                'DataManager._retrieveBucketing()',
                str_replace('#', '#' . $variationId, Messages::BUCKETED_VISITOR)
            );

            $storeDataObj = [
              'bucketing' => [(string)$experience->getId() => $variationId],
            ];
            if ($updateVisitorProperties && !empty($visitorProperties)) {
                $storeDataObj['segments'] = $visitorProperties;
            }
            // qs-02: suppressed for a preview context — zero-trace, regardless of enableTracking.
            if (!$suppressPersistence) {
                $this->putData($visitorId, $storeDataObj);
            }
            // Track bucketing event if enabled
            if ($enableTracking && !$suppressPersistence) {
                $bucketingEvent = [
                    'experienceId' => (string)$experience->getId(),
                    'variationId' => (string)$variationId,
                ];
                $visitorEvent = [
                    'eventType' => VisitorTrackingEvents::EVENT_TYPE_BUCKETING,
                    'data' => $bucketingEvent,
                ];
                $this->_apiManager->enqueue($visitorId, new VisitorTrackingEvents($visitorEvent), new VisitorSegments($segments));
                $this->_loggerManager?->trace(
                    'DataManager._retrieveBucketing()',
                    json_encode(LogUtils::toLoggable(($this->_mapper)(['visitorEvent' => $visitorEvent])))
                );
            }

            $variation = $this->retrieveVariation($experience->getId(), (string)$variationId);
        }

        // Build and return the bucketed variation
        if ($variation) {
            $bucketedVariation = array_merge(
                [
                    'experienceId' => $experience->getId(),
                    'experienceName' => $experience->getName(),
                    'experienceKey' => $experience->getKey(),
                ],
                ['bucketingAllocation' => $bucketingAllocation],
                [
                    'id' => $variation->getId(),
                    'name' => $variation->getName(),
                    'key' => $variation->getKey(),
                    'traffic_allocation' => $variation->getTrafficAllocation(),
                    'status' => $variation->getStatus(),
                    'changes' => $variation->getChanges(),
                ]
            );
        }

        return $bucketedVariation;
    }

    /**
     * Retrieve a variation for a given experience.
     *
     * @param string $experienceId
     * @param string $variationId
     * @return ExperienceVariationConfig
     * @private
     */
    private function retrieveVariation(
        string $experienceId,
        string $variationId
    ): ?ExperienceVariationConfig {
        $subItem = $this->getSubItem(
            'experiences',
            $experienceId,
            'variations',
            $variationId,
            'id',
            'id'
        );
        return $subItem !== null ? new ExperienceVariationConfig($subItem) : null;
    }

    /**
     * Build a bucketed-variation array for a preview forced decision (qs-02
     * capability B preview input), bypassing every normal gate — audiences,
     * segments, locations, the environment check, experience status, variation
     * status/traffic filters, stored decisions, and the bucketing hash. Pure:
     * never calls putData() and never enqueues a tracking event, so it stays
     * entirely per-context regardless of whether $experienceData came from the
     * current shared config or from a one-off `?exp=` fetch — and never
     * touches the shared entity list either way.
     *
     * @param array<string, mixed> $experienceData The experience data (from the current
     *     config, or from a `?exp=` fetch response — same raw shape either way)
     * @param string $variationId The variation id to force
     * @return array<string, mixed>|null Same shape as a normal bucketed decision (see
     *     _retrieveBucketing()), or null when $variationId does not exist on the given
     *     experience — the caller (Context) treats this as inert bad input.
     */
    public function buildPreviewDecision(array $experienceData, string $variationId): ?array
    {
        $variationData = null;
        foreach ($experienceData['variations'] ?? [] as $candidate) {
            if (is_array($candidate) && (string)($candidate['id'] ?? '') === $variationId) {
                $variationData = $candidate;
                break;
            }
        }

        if ($variationData === null) {
            $this->_loggerManager?->warn(
                'DataManager.buildPreviewDecision()',
                Messages::PREVIEW_VARIATION_NOT_FOUND,
                LogUtils::toLoggable(($this->_mapper)([
                    'experienceId' => $experienceData['id'] ?? null,
                    'variationId' => $variationId,
                ]))
            );
            return null;
        }

        $experience = new ConfigExperience($experienceData);
        $variation = new ExperienceVariationConfig($variationData);

        return array_merge(
            [
                'experienceId' => $experience->getId(),
                'experienceName' => $experience->getName(),
                'experienceKey' => $experience->getKey(),
            ],
            ['bucketingAllocation' => null],
            [
                'id' => $variation->getId(),
                'name' => $variation->getName(),
                'key' => $variation->getKey(),
                'traffic_allocation' => $variation->getTrafficAllocation(),
                'status' => $variation->getStatus(),
                'changes' => $variation->getChanges(),
            ]
        );
    }

    /**
     * Reset the bucketed visitors map.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->_bucketedVisitors = [];
    }

    /**
     * Store data for a visitor.
     *
     * @param string $visitorId
     * @param ?StoreData $newData Defaults to null (empty StoreData)
     * @return void
     * @private
     */
    public function putData(string $visitorId, ?array $newData): void
    {
        // Step 1: Get the store key
        $storeKey = $this->getStoreKey($visitorId);
        // Step 2: Retrieve existing data or use an empty array
        $storeDataObj = $this->getData($visitorId);

        $storeData = $storeDataObj ? [
            'bucketing' => $storeDataObj['bucketing'] ?? [],
            'locations' => $storeDataObj['locations'] ?? [],
            'segments' => $storeDataObj['segments'] ?? [],
            'goals' => $storeDataObj['goals'] ?? [],
        ] : [];
        // Step 3: Handle newData, defaulting to an empty StoreData object
        $newDataObj = $newData ?? [];
        $newDataArray = [
            'bucketing' => $newDataObj['bucketing'] ?? [],
            'locations' => $newDataObj['locations'] ?? [],
            'segments' => $newDataObj['segments'] ?? [],
            'goals' => $newDataObj['goals'] ?? [],
        ];
        // Step 4: Check if data has changed
        $isChanged = !ObjectUtils::objectDeepEqual($storeData, $newDataArray);
        if ($isChanged) {

            // Step 5: Merge data if changed
            $updatedData = ObjectUtils::objectDeepMerge($storeData, $newDataArray);
            $this->_bucketedVisitors[$storeKey] = $updatedData;
            // Step 6: Enforce local store limit
            if (count($this->_bucketedVisitors) > $this->_localStoreLimit) {
                reset($this->_bucketedVisitors);
                $oldestKey = key($this->_bucketedVisitors);
                unset($this->_bucketedVisitors[$oldestKey]);
            }
            // Step 7: Handle data store manager
            if ($this->_dataStoreManager) {
                // Extract segments and remaining data
                $storedSegments = $storeData['segments'] ?? [];
                $dataWithoutSegments = $storeData;
                unset($dataWithoutSegments['segments']);

                // Filter segments
                $reportSegments = $this->filterReportSegments($storedSegments);
                $newSegments = $this->filterReportSegments($newDataArray['segments'] ?? []);
                if (!empty(array_filter($newSegments, fn ($value) => $value !== null))) {
                    // Merge data with filtered segments
                    $mergedData = ObjectUtils::objectDeepMerge($dataWithoutSegments, [
                        'segments' => array_merge($reportSegments, $newSegments),
                    ]);
                    $this->_dataStoreManager->set($storeKey, $mergedData);
                } else {
                    $this->_dataStoreManager->set($storeKey, $updatedData);
                }
            }
        }
    }

    /**
     * Retrieve stored data for a visitor.
     *
     * @param string $visitorId
     * @return StoreData|null Stored data
     * @private
     */
    public function getData(string $visitorId): ?array
    {
        $storeKey = $this->getStoreKey($visitorId);
        $memoryData = $this->_bucketedVisitors[$storeKey] ?? null;

        if ($this->_dataStoreManager) {
            $dataStoreData = $this->_dataStoreManager->get($storeKey) ?? [];

            $mergedData = ObjectUtils::objectDeepMerge(
                $memoryData ?? [],
                $dataStoreData
            );
            return $mergedData;
        }

        if ($memoryData === null) {
            return null;
        }
        return $memoryData;
    }

    /**
     * Generate a store key for a visitor.
     *
     * @param string $visitorId
     * @return string Store key
     * @private
     */
    public function getStoreKey(string $visitorId): string
    {
        return "{$this->_accountId}-{$this->_projectId}-{$visitorId}";
    }

    /**
     * Select locations for a visitor based on rules and attributes.
     *
     * @param string $visitorId
     * @param array $items Array of location items (associative arrays)
     * @param LocationAttributes $attributes Location attributes object
     * @return array Array of matched items or RuleError instances
     */
    public function selectLocations(string $visitorId, array $items, LocationAttributes $attributes): array
    {
        $locationProperties = $attributes->getLocationProperties();
        $identityField = $attributes->getIdentityField() ?? 'key';
        $forceEvent = $attributes->getForceEvent();
        // qs-02 capability (B) preview input — per-context suppression signal.
        $suppressPersistence = $attributes->getSuppressPersistence() ?? false;

        $this->_loggerManager?->trace(
            'DataManager.selectLocations()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'items' => $items,
                'locationProperties' => $locationProperties,
            ])))
        );

        // Get locations from DataStore
        $data = $this->getData($visitorId);
        $locations = $data['locations'] ?? [];

        $matchedRecords = [];
        if (ArrayUtils::arrayNotEmpty($items)) {
            foreach ($items as $item) {
                if (empty($item['rules'])) {
                    continue;
                }

                $match = $this->_ruleManager->isRuleMatched(
                    $locationProperties,
                    new RuleObject($item['rules']),
                    "ConfigLocation #{$item[$identityField]}"
                );
                $identity = (string)($item[$identityField] ?? '');

                if ($match === true) {
                    $this->_loggerManager?->info(
                        'DataManager.selectLocations()',
                        str_replace('#', "#{$identity}", Messages::LOCATION_MATCH)
                    );

                    if (!in_array($identity, $locations, true) || $forceEvent) {
                        $this->_eventManager->fire(
                            SystemEvents::LocationActivated,
                            [
                                'visitorId' => $visitorId,
                                'location' => [
                                    'id' => $item['id'] ?? null,
                                    'key' => $item['key'] ?? null,
                                    'name' => $item['name'] ?? null,
                                ],
                            ],
                            null,
                            true
                        );
                        $this->_loggerManager?->info(
                            'DataManager.selectLocations()',
                            str_replace('#', "#{$identity}", Messages::LOCATION_ACTIVATED)
                        );
                    }

                    if (!in_array($identity, $locations, true)) {
                        $locations[] = $identity;
                    }
                    $matchedRecords[] = $item;
                } elseif ($match !== false) {
                    // Catch rule errors
                    $matchedRecords[] = $match;
                } elseif ($match === false && in_array($identity, $locations, true)) {
                    $this->_eventManager->fire(
                        SystemEvents::LocationDeactivated,
                        [
                            'visitorId' => $visitorId,
                            'location' => [
                                'id' => $item['id'] ?? null,
                                'key' => $item['key'] ?? null,
                                'name' => $item['name'] ?? null,
                            ],
                        ],
                        null,
                        true
                    );
                    $locationIndex = array_search($identity, $locations, true);
                    if ($locationIndex !== false) {
                        array_splice($locations, $locationIndex, 1);
                    }
                    $this->_loggerManager?->info(
                        'DataManager.selectLocations()',
                        str_replace('#', "#{$identity}", Messages::LOCATION_DEACTIVATED)
                    );
                }
            }
        }

        // Store the data (qs-02: suppressed for a preview context — zero-trace)
        if (!$suppressPersistence) {
            $this->putData($visitorId, ['locations' => $locations]);
        }

        $this->_loggerManager?->debug(
            'DataManager.selectLocations()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'matchedRecords' => $matchedRecords,
            ])))
        );

        return $matchedRecords;
    }

    /**
     * Retrieve variation for visitor by key.
     *
     * @param string $visitorId
     * @param string $key
     * @param BucketingAttributes $attributes
     * @return mixed BucketedVariation array, RuleError, or BucketingError
     */
    public function getBucketing(string $visitorId, string $key, BucketingAttributes $attributes): array|RuleError|BucketingError|null
    {
        return $this->_getBucketingByField($visitorId, $key, 'key', $attributes);
    }

    /**
     * Retrieve variation for visitor by ID.
     *
     * @param string $visitorId
     * @param string $id
     * @param BucketingAttributes $attributes
     * @return mixed BucketedVariation array, RuleError, or BucketingError
     */
    public function getBucketingById(string $visitorId, string $id, BucketingAttributes $attributes): array|RuleError|BucketingError|null
    {
        return $this->_getBucketingByField($visitorId, $id, 'id', $attributes);
    }


    /**
     * Process conversion event.
     *
     * @param string $visitorId The unique identifier of the visitor
     * @param string $goalId The identifier of the goal to process
     * @param array|null $goalRule Optional associative array of key-value pairs for goal matching
     * @param array|null $goalData Optional array of associative arrays containing goal data
     * @param VisitorSegments|null $segments Optional visitor segments object
     * @param array|null $conversionSetting Optional associative array of conversion settings
     * @param bool $suppressPersistence qs-02 capability (B) preview input — when true,
     *     suppresses the goal-triggered write AND the conversion/transaction
     *     enqueue. Defaults to false.
     * @return bool|RuleError Returns true on success, or a RuleError instance on failure
     */
    public function convert(
        string $visitorId,
        string $goalId,
        ?array $goalRule = null,
        ?array $goalData = null,
        ?VisitorSegments $segments = null,
        ?array $conversionSetting = null,
        bool $suppressPersistence = false
    ): bool|RuleError {
        // Retrieve the goal based on goalId type
        $goal = is_string($goalId)
            ? $this->getEntity($goalId, 'goals')
            : $this->getEntityById($goalId, 'goals');
        // Check if goal exists and has an ID
        if ($goal === null || !isset($goal['id'])) {
            $this->_loggerManager?->error(
                'DataManager.convert()',
                Messages::GOAL_NOT_FOUND
            );
            return false;
        }

        // Handle goal rule matching if provided
        if ($goalRule !== null) {
            if (empty($goal['rules'])) {
                return false;
            }
            $ruleMatched = $this->_ruleManager->isRuleMatched(
                $goalRule,
                new RuleObject($goal['rules']),
                "ConfigGoal #{$goalId}"
            );
            if ($ruleMatched instanceof RuleError) {
                return $ruleMatched;
            }
            if ($ruleMatched === false) {
                $this->_loggerManager?->error(
                    'DataManager.convert()',
                    Messages::GOAL_RULE_NOT_MATCH
                );
                return false;
            }
        }

        // Check for force multiple transactions setting
        $forceMultipleTransactions = $conversionSetting[ConversionSettingKey::ForceMultipleTransactions->value] ?? null;
        // Retrieve stored data for the visitor
        $data = $this->getData($visitorId) ?? [];
        $bucketingData = $data['bucketing'] ?? [];
        $goals = $data['goals'] ?? [];
        $goalTriggered = $goals[$goalId] ?? false;
        // Log and skip if goal was already triggered and multiple transactions aren't forced
        if ($goalTriggered) {
            $this->_loggerManager?->debug(
                'DataManager.convert()',
                str_replace('#', $goalId, Messages::GOAL_FOUND),
                json_encode(LogUtils::toLoggable(($this->_mapper)([
                    'visitorId' => $visitorId,
                    'goalId' => $goalId,
                ])))
            );
            if (!$forceMultipleTransactions) {
                return true;
            }
        }

        // Store the goal as triggered (qs-02: suppressed for a preview context — zero-trace)
        if (!$suppressPersistence) {
            $this->putData($visitorId, ['goals' => [$goalId => true]]);
        }

        // Send conversion event if goal wasn't previously triggered
        if (!$goalTriggered && !$suppressPersistence) {
            $this->sendConversion($visitorId, $goal['id'], $bucketingData, $segments);
        }
        // Send transaction event if goalData exists and conditions are met
        if ($goalData !== null && (!$goalTriggered || $forceMultipleTransactions) && !$suppressPersistence) {
            $this->sendTransaction($visitorId, $goal['id'], $goalData, $bucketingData, $segments);
        }

        return true;
    }

    /**
    * Send a conversion event to the API.
    *
    * @param string $visitorId The visitor's unique identifier
    * @param string $goalId The goal identifier
    * @param array $bucketingData Bucketing data for the visitor
    * @param VisitorSegments|null $segments Visitor segments
    * @return void
    */
    private function sendConversion(string $visitorId, string $goalId, array $bucketingData, ?VisitorSegments $segments): void
    {
        $data = ['goalId' => $goalId];
        if (!empty($bucketingData)) {
            $data['bucketingData'] = $bucketingData;
        }
        $event = [
            'eventType' => SystemEvents::Conversion->value,
            'data' => $data,
        ];
        $this->_apiManager->enqueue($visitorId, new VisitorTrackingEvents($event), $segments);
        $this->_loggerManager?->trace(
            'DataManager.convert()',
            LogUtils::toLoggable(($this->_mapper)(['event' => $event]))
        );
    }

    /**
    * Send a transaction event to the API.
    *
    * @param string $visitorId The visitor's unique identifier
    * @param string $goalId The goal identifier
    * @param array $goalData Array of goal data
    * @param array $bucketingData Bucketing data for the visitor
    * @param VisitorSegments|null $segments Visitor segments
    * @return void
    */
    private function sendTransaction(string $visitorId, string $goalId, array $goalData, array $bucketingData, ?VisitorSegments $segments): void
    {
        $data = [
            'goalId' => $goalId,
            'goalData' => $goalData,
        ];
        if (!empty($bucketingData)) {
            $data['bucketingData'] = $bucketingData;
        }
        $event = [
            'eventType' => SystemEvents::Conversion->value,
            'data' => $data,
        ];
        $this->_apiManager->enqueue($visitorId, new VisitorTrackingEvents($event), $segments);
        $this->_loggerManager?->trace(
            'DataManager.convert()',
            LogUtils::toLoggable(($this->_mapper)(['event' => $event]))
        );
    }


    /**
     * Get audiences that meet the visitor properties.
     *
     * @param array $items Array of associative arrays representing items with rules
     * @param array $visitorProperties Associative array of visitor properties
     * @param string $entityType Type of entity being filtered (e.g., 'audience')
     * @param string $field Identity field to use, defaults to 'id'
     * @param string|null $visitorId qs-03: required only when an item's rule tree is a
     *     sole bucketed_into_experience_key rule, to resolve against SDK-stored
     *     visitor bucketing state instead of $visitorProperties. Null for callers
     *     that never carry such a rule (RuleManager path is untouched for them).
     * @return array Array of matched items or RuleError instances
     */
    public function filterMatchedRecordsWithRule(
        array $items,
        array $visitorProperties,
        string $entityType,
        string $field = IdentityField::ID,
        ?string $visitorId = null
    ): array {
        $this->_loggerManager?->trace(
            'DataManager.filterMatchedRecordsWithRule()',
            json_encode(
                LogUtils::toLoggable(($this->_mapper)([
                'items' => $items,
                'visitorProperties' => $visitorProperties,
          ]))
            )
        );

        $matchedRecords = [];
        if (ArrayUtils::arrayNotEmpty($items)) {
            foreach ($items as $item) {
                if (empty($item['rules'])) {
                    continue;
                }

                // qs-03 (mutual-exclusion audience rule): a bucketed_into_experience_key
                // rule resolves against SDK-stored visitor bucketing state instead of
                // $visitorProperties. It is resolved read-only here (getEntity() +
                // getData(), no bucketing/writes/tracking) into a raw boolean, then
                // routed through the SAME untouched isRuleMatched() generic key/value
                // dispatch every other rule uses — via a synthetic single-key data/rule
                // pair whose 'equals' comparison reproduces `matching.negated ? !raw :
                // raw` using RuleManager's own (unmodified) negation logic
                // (Comparisons::equals()/returnNegationCheck()). RuleManager itself is
                // never modified (AC7); any item whose rule tree is NOT a sole
                // bucketed_into_experience_key rule (every generic key/value rule shape
                // in production today) is passed through completely unchanged below.
                $exclusionRule = $visitorId !== null
                    ? $this->_findSoleBucketedIntoExperienceKeyRule($item['rules'])
                    : null;

                if ($exclusionRule !== null) {
                    [$ruleData, $ruleObject] = $this->_buildBucketedIntoExperienceKeyRuleMatch($exclusionRule, $visitorId);
                    $match = $this->_ruleManager->isRuleMatched(
                        $ruleData,
                        $ruleObject,
                        StringUtils::camelCase($entityType) . " #{$item[$field]}"
                    );
                } else {
                    $match = $this->_ruleManager->isRuleMatched(
                        $visitorProperties,
                        new RuleObject($item['rules']),
                        StringUtils::camelCase($entityType) . " #{$item[$field]}"
                    );
                }

                if ($match === true) {
                    $matchedRecords[] = $item;
                } elseif ($match !== false) {
                    // Catch rule errors
                    $matchedRecords[] = $match;
                }
            }
        }

        $this->_loggerManager?->debug(
            'DataManager.filterMatchedRecordsWithRule()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'matchedRecords' => $matchedRecords,
            ])))
        );

        return $matchedRecords;
    }

    /**
     * qs-03: whether any of the given (already-fetched) audiences carries a
     * bucketed_into_experience_key rule as its sole rule. Used to widen the
     * DataManager.php empty-visitorProperties audience-evaluation gate SOLELY
     * for experiences whose audience tree needs this rule type (AC4), while
     * leaving the gate untouched for every generic-only audience (AC7).
     *
     * @param array<int, array<string, mixed>> $audiences
     */
    private function _audiencesContainBucketedIntoExperienceKeyRule(array $audiences): bool
    {
        foreach ($audiences as $audience) {
            if (!empty($audience['rules']) && $this->_findSoleBucketedIntoExperienceKeyRule($audience['rules']) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * qs-03: returns the rule element if $rulesTree contains EXACTLY ONE
     * rule element in total and it is a bucketed_into_experience_key rule;
     * otherwise null (including for mixed generic+exclusion trees, which are
     * out of scope per qs-03-mutual-exclusion-rule.md's non-goals — no served
     * config can yet emit one). Returning null routes the item through the
     * untouched, generic isRuleMatched() path unchanged.
     *
     * @param array<string, mixed> $rulesTree The OR/AND/OR_WHEN rule tree
     * @return array{rule_type: string, matching: array{match_type: string, negated: bool}, value: mixed}|null
     */
    private function _findSoleBucketedIntoExperienceKeyRule(array $rulesTree): ?array
    {
        $elements = $this->_collectRuleElements($rulesTree);
        if (count($elements) === 1 && ($elements[0]['rule_type'] ?? null) === RuleType::BucketedIntoExperienceKey->value) {
            return $elements[0];
        }
        return null;
    }

    /**
     * qs-03: recursively collects every leaf rule element (identified by the
     * presence of a `rule_type` key) anywhere within an OR/AND/OR_WHEN rule
     * tree, regardless of nesting depth or shape.
     *
     * @param mixed $node
     * @return array<int, array<string, mixed>>
     */
    private function _collectRuleElements(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        if (isset($node['rule_type'])) {
            return [$node];
        }
        $elements = [];
        foreach ($node as $value) {
            if (is_array($value)) {
                $elements = array_merge($elements, $this->_collectRuleElements($value));
            }
        }
        return $elements;
    }

    /**
     * qs-03: resolves a bucketed_into_experience_key rule element read-only
     * (target lookup via getEntity(), presence check via getData() — never
     * triggers bucketing of the target, never writes, never tracks — AC5),
     * then builds a synthetic single-key $data/RuleObject pair that reproduces
     * the contract's `matching.negated ? !bucketedRaw : bucketedRaw` via the
     * SAME real, unmodified isRuleMatched() -> Comparisons::equals() negation
     * logic every generic 'equals' rule already uses.
     *
     * Unknown target key -> bucketedRaw = false + AC8 warning naming the key.
     *
     * @param array{rule_type: string, matching: array{match_type: string, negated: bool}, value: mixed} $rule
     * @return array{0: array<string, string>, 1: RuleObject}
     */
    private function _buildBucketedIntoExperienceKeyRuleMatch(array $rule, string $visitorId): array
    {
        $targetKey = (string)($rule['value'] ?? '');
        $target = $this->getEntity($targetKey, 'experiences');

        if ($target === null) {
            $this->_loggerManager?->warn(
                'DataManager.filterMatchedRecordsWithRule()',
                str_replace('#', $targetKey, ErrorMessages::BUCKETING_EXCLUSION_TARGET_NOT_FOUND)
            );
            $bucketedRaw = false;
        } else {
            $visitorData = $this->getData($visitorId) ?? [];
            $bucketingData = $visitorData['bucketing'] ?? [];
            $bucketedRaw = array_key_exists((string)$target['id'], $bucketingData);
        }

        $syntheticKey = '__convertSdk_bucketedIntoExperienceKey';
        $negated = (bool)($rule['matching']['negated'] ?? false);
        $syntheticData = [$syntheticKey => $bucketedRaw ? 'true' : 'false'];
        $syntheticRuleObject = new RuleObject([
            'OR' => [
                ['AND' => [
                    ['OR_WHEN' => [[
                        'rule_type' => RuleType::BucketedIntoExperienceKey->value,
                        'key' => $syntheticKey,
                        'matching' => ['match_type' => 'equals', 'negated' => $negated],
                        'value' => 'true',
                    ]]],
                ]],
            ],
        ]);

        return [$syntheticData, $syntheticRuleObject];
    }

    /**
     * Get audiences that meet the custom segments.
     *
     * @param array $items Array of associative arrays representing items with IDs
     * @param string $visitorId The unique identifier of the visitor
     * @return array Array of matched items
     */
    public function filterMatchedCustomSegments(array $items, string $visitorId): array
    {
        $this->_loggerManager?->trace(
            'DataManager.filterMatchedCustomSegments()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'items' => $items,
                'visitorId' => $visitorId,
            ])))
        );

        // Get custom segments ID from DataStore
        $data = $this->getData($visitorId) ?? [];
        $customSegments = $data['segments']['customSegments'] ?? [];

        $matchedRecords = [];
        if (ArrayUtils::arrayNotEmpty($items)) {
            foreach ($items as $item) {
                if (empty($item['id'])) {
                    continue;
                }
                if (in_array($item['id'], $customSegments, true)) {
                    $matchedRecords[] = $item;
                }
            }
        }

        $this->_loggerManager?->debug(
            'DataManager.filterMatchedCustomSegments()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'matchedRecords' => $matchedRecords,
            ])))
        );

        return $matchedRecords;
    }

    /**
     * Extract report segments from other attributes in visitor properties.
     *
     * @param array|null $visitorProperties Optional associative array of visitor properties
     * @return array Associative array with 'properties' and 'segments' keys
     */
    public function filterReportSegments(?array $visitorProperties = []): array
    {
        // Define segment keys based on VisitorSegments properties
        $segmentsKeys = [
            'browser',
            'devices',
            'source',
            'campaign',
            'visitorType',
            'country',
            'customSegments',
        ];

        $segments = [];
        $properties = [];
        // Split visitor properties into segments and other properties
        foreach ($visitorProperties ?? [] as $key => $value) {
            if (in_array($key, $segmentsKeys, true)) {
                $segments[$key] = $value;
            } else {
                $properties[$key] = $value;
            }
        }

        return [
            'properties' => !empty($properties) ? $properties : null,
            'segments' => !empty($segments) ? $segments : null,
        ];
    }

    /**
     * Get list of data entities.
     *
     * @param string $entityType The type of entity to retrieve
     * @return array Array of entities or strings
     */
    public function getEntitiesList(string $entityType): array
    {
        $list = [];
        $mappedEntityType = DataEntities::DATA_ENTITIES_MAP[$entityType] ?? $entityType;
        if (in_array($mappedEntityType, $this->_dataEntities, true)) {
            switch ($mappedEntityType) {
                case 'experiences':
                    $list = $this->_data->getExperiences() ?? [];
                    break;
                case 'audiences':
                    $list = $this->_data->getAudiences() ?? [];
                    break;
                case 'features':
                    $list = $this->_data->getFeatures() ?? [];
                    break;
                case 'segments':
                    $list = $this->_data->getSegments() ?? [];
                    break;
                case 'locations':
                    $list = $this->_data->getLocations() ?? [];
                    break;
                case 'archived_experiences':
                    $list = $this->_data->getArchivedExperiences() ?? [];
                    break;
                case 'goals':
                    $list = $this->_data->getGoals() ?? [];
                    break;
                default:
                    $list = [];
            }
        }

        $this->_loggerManager?->trace(
            'DataManager.getEntitiesList()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'entityType' => $mappedEntityType,
                'list' => $list,
            ])))
        );

        return $list;
    }

    /**
     * Get list of data entities grouped by field.
     *
     * @param string $entityType The type of entity to retrieve
     * @param string $field Identity field to group by, defaults to 'id'
     * @return array Associative array with entities keyed by the specified field
     */
    public function getEntitiesListObject(string $entityType, string $field = IdentityField::ID): array
    {
        $entities = $this->getEntitiesList($entityType);
        $result = array_reduce($entities, function ($target, $entity) use ($field) {
            $target[$entity[$field]] = $entity;
            return $target;
        }, []);
        return $result;
    }

    /**
    * Retrieve an entity by a specific field value.
    *
    * @param string $identity Value of the field to match
    * @param string $entityType The type of entity to search
    * @param string $identityField Field to match against, defaults to 'key'
    * @return array|null Entity as an associative array or null if not found
    * @private
    */
    private function _getEntityByField(string $identity, string $entityType, string $identityField = IdentityField::KEY): ?array
    {
        $mappedEntityType = DataEntities::DATA_ENTITIES_MAP[$entityType] ?? $entityType;

        $this->_loggerManager?->trace(
            'DataManager._getEntityByField()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'identity' => $identity,
                'entityType' => $mappedEntityType,
                'identityField' => $identityField,
            ])))
        );
        $list = $this->getEntitiesList($mappedEntityType);
        if (ArrayUtils::arrayNotEmpty($list)) {
            foreach ($list as $entity) {
                if (!empty($entity) && (string)$entity[$identityField] === (string)$identity) {
                    return $entity;
                }
            }
        }

        if ($this->_loggerManager) {
            $availableKeys = array_map(
                fn ($e) => $e[$identityField] ?? 'unknown',
                $list
            );
            $this->_loggerManager->debug(
                'DataManager._getEntityByField()',
                Messages::ENTITY_LOOKUP_FAILED,
                LogUtils::toLoggable(($this->_mapper)([
                    'searchedFor' => $identity,
                    'entityType' => $mappedEntityType,
                    'identityField' => $identityField,
                    'availableKeys' => $availableKeys,
                ]))
            );
        }

        return null;
    }

    /**
    * Find the entity in list by key.
    *
    * @param string $key The key value to match
    * @param string $entityType The type of entity to search
    * @return array|null Entity as an associative array or null if not found
    */
    public function getEntity(string $key, string $entityType): ?array
    {
        return $this->_getEntityByField($key, $entityType, 'key');
    }

    /**
    * Find entities in list by keys.
    *
    * @param string[] $keys Array of key values to match
    * @param string $entityType The type of entity to search
    * @return array Array of matched entities
    */
    public function getEntities(array $keys, string $entityType): array
    {
        return $this->getItemsByKeys($keys, $entityType);
    }

    /**
    * Find the entity in list by ID.
    *
    * @param string $id The ID value to match
    * @param string $entityType The type of entity to search
    * @return array|null Entity as an associative array or null if not found
    */
    public function getEntityById(string $id, string $entityType): ?array
    {
        return $this->_getEntityByField($id, $entityType, IdentityField::ID);
    }

    /**
    * Find entities in list by IDs.
    *
    * @param string[] $ids Array of ID values to match
    * @param string $entityType The type of entity to search
    * @return array Array of matched entities
    */
    public function getEntitiesByIds(array $ids, string $entityType): array
    {
        return $this->getItemsByIds($ids, $entityType);
    }

    /**
    * Find items in list by keys.
    *
    * @param string[] $keys Array of key values to match
    * @param string $path The entity type or path to search
    * @return array Array of matched items
    */
    public function getItemsByKeys(array $keys, string $path): array
    {
        $list = $this->getEntitiesList($path);
        $items = [];
        if (ArrayUtils::arrayNotEmpty($list)) {
            foreach ($list as $entity) {
                if (in_array($entity['key'] ?? '', $keys, true)) {
                    $items[] = $entity;
                }
            }
        }
        return $items;
    }

    /**
     * Find items in list by IDs.
     *
     * @param string[] $ids Array of ID values to match
     * @param string $path The entity type or path to search
     * @return array Array of matched items
     */
    public function getItemsByIds(array $ids, string $path): array
    {
        $this->_loggerManager?->trace(
            'DataManager.getItemsByIds()',
            json_encode(LogUtils::toLoggable(($this->_mapper)([
                'ids' => $ids,
                'path' => $path,
            ])))
        );

        $items = [];
        if (ArrayUtils::arrayNotEmpty($ids)) {
            $list = $this->getEntitiesList($path);
            if (ArrayUtils::arrayNotEmpty($list)) {
                foreach ($list as $entity) {
                    if (in_array($entity['id'] ?? '', $ids, true)) {
                        $items[] = $entity;
                    }
                }
            }
        }

        return $items;
    }

    /**
    * Find nested item.
    *
    * @param string $entityType The type of parent entity
    * @param string $entityIdentity The identity value of the parent entity
    * @param string $subEntityType The type of sub-entity to search within
    * @param string $subEntityIdentity The identity value of the sub-entity
    * @param string $identityField Field to identify the parent entity
    * @param string $subIdentityField Field to identify the sub-entity
    * @return array|null Sub-entity as an associative array or null if not found
    */
    public function getSubItem(
        string $entityType,
        string $entityIdentity,
        string $subEntityType,
        string $subEntityIdentity,
        string $identityField,
        string $subIdentityField
    ): ?array {
        $entity = $this->_getEntityByField($entityIdentity, $entityType, $identityField);
        if ($entity && isset($entity[$subEntityType]) && is_array($entity[$subEntityType])) {
            foreach ($entity[$subEntityType] as $subEntity) {
                if (($subEntity[$subIdentityField] ?? null) === $subEntityIdentity) {
                    return $subEntity;
                }
            }
        }

        // Only log at getSubItem level when the parent was found but the sub-entity wasn't.
        // When parent is not found, _getEntityByField() already logged the failure.
        if ($this->_loggerManager && $entity !== null) {
            $this->_loggerManager->debug(
                'DataManager.getSubItem()',
                Messages::ENTITY_LOOKUP_FAILED,
                LogUtils::toLoggable(($this->_mapper)([
                    'entityType' => $entityType,
                    'entityIdentity' => $entityIdentity,
                    'subEntityType' => $subEntityType,
                    'subEntityIdentity' => $subEntityIdentity,
                    'parentFound' => true,
                ]))
            );
        }

        return null;
    }

    /**
    * Validates data object.
    *
    * @param array|null $data Configuration data to validate
    * @return bool True if data is valid, false otherwise
    */
    public function isValidConfigData(ConfigResponseData $data): bool
    {
        return (
            (!empty($data->getAccountId()) && !empty($data->getProject()['id'])) ||
            !empty($data['error'])
        );
    }
}
