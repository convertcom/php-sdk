<?php
namespace ConvertSdk\Data;

use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Utils\ArrayUtils;
use ConvertSdk\Data\DataStoreManager;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Enums\ConfigAudienceTypes;
use ConvertSdk\Enums\GenericListMatchingOptions;
use ConvertSdk\Enums\VariationStatuses;
use ConvertSdk\Enums\ConversionSettingKey;
use ConvertSdk\Enums\eventType;
use ConvertSdk\Enums\SegmentsKeys;
use ConvertSdk\Enums\DataEntities;


class DataManager
{
    // --- Properties ---
    private $data;               // ConfigResponseData (array)
    private $accountId;          // string
    private $projectId;          // string
    private $config;             // Config array
    private $bucketingManager;   // BucketingManagerInterface
    private $loggerManager;      // LogManagerInterface|null
    private $eventManager;       // EventManagerInterface
    private $dataStoreManager;   // DataStoreManagerInterface|null
    private $apiManager;         // ApiManagerInterface
    private $ruleManager;        // RuleManagerInterface
    private $dataEntities;       // Typically set to DATA_ENTITIES constant
    private $localStoreLimit;    // e.g. 10000
    private $bucketedVisitors;   // Associative array for bucketed visitors
    private $asyncStorage;       // boolean
    private $environment;        // string
    private $mapper;             // callable

    const LOCAL_STORE_LIMIT = 10000;

    // --- Constructor ---
    /**
     * DataManager constructor.
     *
     * @param array $config Configuration array.
     * @param array $dependencies {
     *      @type BucketingManagerInterface $bucketingManager
     *      @type RuleManagerInterface      $ruleManager
     *      @type EventManagerInterface     $eventManager
     *      @type ApiManagerInterface       $apiManager
     *      @type LogManagerInterface|null  $loggerManager
     * }
     * @param array $options Optional options; e.g. ['asyncStorage' => true]
     */
    public function __construct(array $config, array $dependencies, array $options = ['asyncStorage' => true])
    {
        $this->environment = $config['environment'] ?? null;
        $this->apiManager = $dependencies['apiManager'];
        $this->bucketingManager = $dependencies['bucketingManager'];
        $this->ruleManager = $dependencies['ruleManager'];
        $this->loggerManager = $dependencies['loggerManager'] ?? null;
        $this->eventManager = $dependencies['eventManager'];
        $this->config = $config;
        $this->mapper = $config['mapper'] ?? function ($value) {
            return $value;
        };
        $this->asyncStorage = $options['asyncStorage'] ?? true;
        $this->data = ObjectUtils::objectDeepValue($config, 'data');
        $this->accountId = $this->data['account_id'] ?? null;
        $this->projectId = $this->data['project']['id'] ?? null;
        // Use the setter for dataStoreManager
        $this->setDataStoreManager($config['dataStore'] ?? null);
        $this->dataEntities = DataEntities::DATA_ENTITIES;
        $this->localStoreLimit = self::LOCAL_STORE_LIMIT;
        $this->bucketedVisitors = [];
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager()', MESSAGES::DATA_CONSTRUCTOR, $this);
        }
    }

    // --- Data Getter/Setter ---
    public function setData(array $data)
    {
        if ($this->isValidConfigData($data)) {
            $this->data = $data;
            $this->accountId = $data['account_id'] ?? null;
            $this->projectId = $data['project']['id'] ?? null;
        } else {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                $this->loggerManager->error('DataManager.data.set()', ERROR_MESSAGES::CONFIG_DATA_NOT_VALID);
            }
        }
    }

    public function getData(?string $visitorId = null)
    {
        // If a visitorId is provided, we retrieve memory data from bucketedVisitors.
        if ($visitorId !== null) {
            $storeKey = $this->getStoreKey($visitorId);
            $memoryData = $this->bucketedVisitors[$storeKey] ?? null;
            if ($this->dataStoreManager) {
                return ObjectUtils::objectDeepMerge($memoryData ?? [], $this->dataStoreManager->get($storeKey) ?? []);
            }
            return $memoryData;
        }
        // Otherwise, return the global data.
        return $this->data;
    }

    // --- DataStoreManager Setter/Getter ---
    public function setDataStoreManager($dataStore)
    {
        $this->dataStoreManager = null;
        if ($dataStore) {
            $this->dataStoreManager = new DataStoreManager($this->config, [
                'dataStore' => $dataStore,
                'eventManager' => $this->eventManager,
                'loggerManager' => $this->loggerManager
            ]);
        }
    }

    public function getDataStoreManager()
    {
        return $this->dataStoreManager;
    }

    public function setDataStore($dataStore)
    {
        $this->setDataStoreManager($dataStore);
    }

    // --- Rule Matching Methods ---
    public function matchRulesByField(string $visitorId, string $identity, string $identityField = 'key', array $attributes)
    {
        $visitorProperties = $attributes['visitorProperties'] ?? null;
        $locationProperties = $attributes['locationProperties'] ?? null;
        $ignoreLocationProperties = $attributes['ignoreLocationProperties'] ?? false;
        $environment = $attributes['environment'] ?? $this->environment;

        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager.matchRulesByField()', call_user_func($this->mapper, [
                'visitorId' => $visitorId,
                'identity' => $identity,
                'identityField' => $identityField,
                'visitorProperties' => $visitorProperties,
                'locationProperties' => $locationProperties,
                'ignoreLocationProperties' => $ignoreLocationProperties,
                'environment' => $environment
            ]));
        }

        $experience = $this->_getEntityByField($identity, 'experiences', $identityField);
        if (!$experience) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
                $this->loggerManager->debug('DataManager.matchRulesByField()', MESSAGES::EXPERIENCE_NOT_FOUND, call_user_func($this->mapper, [
                    'identity' => $identity,
                    'identityField' => $identityField
                ]));
            }
            return null;
        }

        $archivedExperiences = $this->getEntitiesList('archived_experiences');
        $isArchivedExperience = false;
        if (is_array($archivedExperiences)) {
            foreach ($archivedExperiences as $id) {
                if (strval($experience['id']) === strval($id)) {
                    $isArchivedExperience = true;
                    break;
                }
            }
        }
        if ($isArchivedExperience) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
                $this->loggerManager->debug('DataManager.matchRulesByField()', MESSAGES::EXPERIENCE_ARCHIVED, call_user_func($this->mapper, [
                    'identity' => $identity,
                    'identityField' => $identityField
                ]));
            }
            return null;
        }

        $isEnvironmentMatch = isset($experience['environment']) ? ($experience['environment'] === $environment) : true;
        if (!$isEnvironmentMatch) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
                $this->loggerManager->debug('DataManager.matchRulesByField()', MESSAGES::EXPERIENCE_ENVIRONMENT_NOT_MATCH, call_user_func($this->mapper, [
                    'identity' => $identity,
                    'identityField' => $identityField
                ]));
            }
            return null;
        }

        $matchedErrors = [];
        $dataForVisitor = $this->getData($visitorId) ?? [];
        $bucketing = $dataForVisitor['bucketing'] ?? [];
        $variationId = $bucketing[strval($experience['id'])] ?? null;
        $isBucketed = false;
        if ($variationId && $this->retrieveVariation($experience['id'], strval($variationId))) {
            $isBucketed = true;
        }

        $locationMatched = $ignoreLocationProperties === true;
        if (!$locationMatched && $locationProperties) {
            if (isset($experience['locations']) && is_array($experience['locations']) && count($experience['locations']) > 0) {
                $matchedLocations = [];
                $locations = $this->getItemsByIds($experience['locations'], 'locations');
                if (count($locations) > 0) {
                    $matchedLocations = $this->selectLocations($visitorId, $locations, [
                        'locationProperties' => $locationProperties,
                        'identityField' => $identityField
                    ]);
                    $matchedErrors = array_filter($matchedLocations, function ($match) {
                        return in_array($match, (array)RuleError::getConstants(), true);
                    });
                    if (count($matchedErrors) > 0) {
                        return array_shift($matchedErrors);
                    }
                }
                $locationMatched = count($matchedLocations) > 0;
            } elseif (isset($experience['site_area'])) {
                $locationMatched = $this->ruleManager->isRuleMatched($locationProperties, $experience['site_area'], 'SiteArea');
                if (in_array($locationMatched, (array)RuleError::getConstants(), true)) {
                    return $locationMatched;
                }
            } else {
                $locationMatched = true;
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                    $this->loggerManager->info('DataManager.matchRulesByField()', MESSAGES::LOCATION_NOT_RESTRICTED);
                }
            }
        }
        if (!$locationMatched) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
                $this->loggerManager->debug('DataManager.matchRulesByField()', MESSAGES::LOCATION_NOT_MATCH, call_user_func($this->mapper, [
                    'locationProperties' => $locationProperties,
                    'experiences[].variations[].' => isset($experience['locations']) ? $experience['locations'] : ($experience['site_area'] ?? '')
                ]));
            }
            return null;
        }

        // --- 2nd Part: Audience & Segments Matching ---
        $audiences = [];
        $segments = [];
        $matchedAudiences = [];
        $matchedSegments = [];
        $audiencesToCheck = [];
        $audiencesMatched = false;
        $segmentsMatched = false;
        if ($visitorProperties) {
            if (isset($experience['audiences']) && is_array($experience['audiences']) && count($experience['audiences']) > 0) {
                $audiences = $this->getItemsByIds($experience['audiences'], 'audiences');
                $audiencesToCheck = array_filter($audiences, function ($audience) use ($isBucketed) {
                    if ($isBucketed && isset($audience['type']) && $audience['type'] === ConfigAudienceTypes::PERMANENT) {
                        return false;
                    }
                    return true;
                });
                if (count($audiencesToCheck) > 0) {
                    $matchedAudiences = $this->filterMatchedRecordsWithRule($audiencesToCheck, $visitorProperties, 'audience', $identityField);
                    $matchedErrors = array_filter($matchedAudiences, function ($match) {
                        return in_array($match, (array)RuleError::getConstants(), true);
                    });
                    if (count($matchedErrors) > 0) {
                        return array_shift($matchedErrors);
                    }
                    if (count($matchedAudiences) > 0) {
                        foreach ($matchedAudiences as $item) {
                            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                                $this->loggerManager->info('DataManager.matchRulesByField()', str_replace('#', $item[$identityField] ?? '', MESSAGES::AUDIENCE_MATCH));
                            }
                        }
                    }
                    if ((($experience['settings']['matching_options']['audiences']) ?? null) === GenericListMatchingOptions::ALL) {
                        $audiencesMatched = (count($matchedAudiences) === count($audiencesToCheck));
                    } else {
                        $audiencesMatched = count($matchedAudiences) > 0;
                    }
                } else {
                    $audiencesMatched = true;
                    if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                        $this->loggerManager->info('DataManager.matchRulesByField()', MESSAGES::NON_PERMANENT_AUDIENCE_NOT_RESTRICTED);
                    }
                }
            } else {
                $audiencesMatched = true;
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                    $this->loggerManager->info('DataManager.matchRulesByField()', MESSAGES::AUDIENCE_NOT_RESTRICTED);
                }
            }
        } else {
            $audiencesMatched = true;
        }
        $segments = $this->getItemsByIds($experience['audiences'], 'segments');
        if (count($segments) > 0) {
            $matchedSegments = $this->filterMatchedCustomSegments($segments, $visitorId);
            if (count($matchedSegments) > 0) {
                foreach ($matchedSegments as $item) {
                    if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                        $this->loggerManager->info('DataManager.matchRulesByField()', str_replace('#', $item[$identityField] ?? '', MESSAGES::SEGMENTATION_MATCH));
                    }
                }
            }
            $segmentsMatched = count($matchedSegments) > 0;
        } else {
            $segmentsMatched = true;
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                $this->loggerManager->info('DataManager.matchRulesByField()', MESSAGES::SEGMENTATION_NOT_RESTRICTED);
            }
        }
        if ($audiencesMatched && $segmentsMatched) {
            if (isset($experience['variations']) && is_array($experience['variations']) && count($experience['variations']) > 0) {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                    $this->loggerManager->info('DataManager.matchRulesByField()', MESSAGES::EXPERIENCE_RULES_MATCHED);
                }
                return $experience;
            } else {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
                    $this->loggerManager->debug('DataManager.matchRulesByField()', MESSAGES::VARIATIONS_NOT_FOUND, call_user_func($this->mapper, [
                        'visitorProperties' => $visitorProperties,
                        'audiences' => $audiences
                    ]));
                }
            }
        } else {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
                $this->loggerManager->debug('DataManager.matchRulesByField()', MESSAGES::AUDIENCE_NOT_MATCH, call_user_func($this->mapper, [
                    'visitorProperties' => $visitorProperties,
                    'audiences' => $audiences
                ]));
            }
        }
        return null;
    }

    // --- 3rd Part: Bucketing & Data Storage Methods ---
    private function _retrieveBucketing(string $visitorId, ?array $visitorProperties, bool $updateVisitorProperties, array $experience, ?string $forceVariationId = null, bool $enableTracking = true)
    {
        if (!$visitorId || !$experience || !isset($experience['id'])) {
            return null;
        }
        $variation = null;
        $variationId = null;
        $bucketedVariation = null;
        $bucketingAllocation = null;
        $storeKey = $this->getStoreKey($visitorId);
        if ($forceVariationId && ($variation = $this->retrieveVariation($experience['id'], strval($forceVariationId)))) {
            $variationId = $forceVariationId;
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                $this->loggerManager->info('DataManager._retrieveBucketing()', str_replace('#', "#{$forceVariationId}", MESSAGES::BUCKETED_VISITOR_FORCED));
                $this->loggerManager->debug('DataManager._retrieveBucketing()', call_user_func($this->mapper, [
                    'storeKey' => $storeKey,
                    'visitorId' => $visitorId,
                    'variationId' => $forceVariationId
                ]));
            }
        }
        $dataForVisitor = $this->getData($visitorId) ?? [];
        $bucketing = $dataForVisitor['bucketing'] ?? [];
        $storedVariationId = $bucketing[strval($experience['id'])] ?? null;
        if ($storedVariationId &&
            (!$variationId || strval($variationId) === strval($storedVariationId)) &&
            ($variation = $this->retrieveVariation($experience['id'], strval($storedVariationId)))
        ) {
            $variationId = $storedVariationId;
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                $this->loggerManager->info('DataManager._retrieveBucketing()', str_replace('#', "#{$variationId}", MESSAGES::BUCKETED_VISITOR_FOUND));
                $this->loggerManager->debug('DataManager._retrieveBucketing()', call_user_func($this->mapper, [
                    'storeKey' => $storeKey,
                    'visitorId' => $visitorId,
                    'variationId' => $variationId
                ]));
            }
        } else {
            $buckets = [];
            if (isset($experience['variations']) && is_array($experience['variations'])) {
                foreach ($experience['variations'] as $var) {
                    if (isset($var['status']) && $var['status'] !== VariationStatuses::RUNNING) {
                        continue;
                    }
                    if ((isset($var['traffic_allocation']) && $var['traffic_allocation'] > 0) || (!isset($var['traffic_allocation']) || is_nan($var['traffic_allocation']))) {
                        if (isset($var['id'])) {
                            $buckets[$var['id']] = $var['traffic_allocation'] ?? 100.0;
                        }
                    }
                }
            }
            $bucketingResult = $this->bucketingManager->getBucketForVisitor(
                $buckets,
                $visitorId,
                (isset($this->config['bucketing']['excludeExperienceIdHash']) && $this->config['bucketing']['excludeExperienceIdHash']) ? null : ['experienceId' => strval($experience['id'])]
            );
            $variationId = $variationId ?? ($bucketingResult['variationId'] ?? null);
            $bucketingAllocation = $bucketingResult['bucketingAllocation'] ?? null;
            if (!$variationId) {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
                    $this->loggerManager->debug('DataManager._retrieveBucketing()', ERROR_MESSAGES::UNABLE_TO_SELECT_BUCKET_FOR_VISITOR, call_user_func($this->mapper, [
                        'visitorId' => $visitorId,
                        'experience' => $experience,
                        'buckets' => $buckets,
                        'bucketing' => $bucketingResult
                    ]));
                }
                return \ConvertSdk\Enums\BucketingError::VARIAION_NOT_DECIDED;
            }
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                $this->loggerManager->info('DataManager._retrieveBucketing()', str_replace('#', "#{$variationId}", MESSAGES::BUCKETED_VISITOR));
            }
            if ($updateVisitorProperties) {
                $this->putData($visitorId, array_merge(
                    ['bucketing' => [strval($experience['id']) => $variationId]],
                    $visitorProperties ? ['segments' => $visitorProperties] : []
                ));
            } else {
                $this->putData($visitorId, ['bucketing' => [strval($experience['id']) => $variationId]]);
            }
            if ($enableTracking) {
                $bucketingEvent = [
                    'experienceId' => strval($experience['id']),
                    'variationId' => strval($variationId)
                ];
                $visitorEvent = [
                    'eventType' => eventType::BUCKETING,
                    'data' => $bucketingEvent
                ];
                $this->apiManager->enqueue($visitorId, $visitorEvent, $bucketingResult['segments'] ?? null);
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
                    $this->loggerManager->trace('DataManager._retrieveBucketing()', call_user_func($this->mapper, ['visitorEvent' => $visitorEvent]));
                }
            }
            $variation = $this->retrieveVariation($experience['id'], strval($variationId));
        }
        if ($variation) {
            $bucketedVariation = array_merge(
                [
                    'experienceId' => $experience['id'],
                    'experienceName' => $experience['name'] ?? null,
                    'experienceKey' => $experience['key'] ?? null
                ],
                ['bucketingAllocation' => $bucketingAllocation],
                $variation
            );
        }
        return $bucketedVariation;
    }

    private function retrieveVariation(string $experienceId, string $variationId)
    {
        return $this->getSubItem('experiences', $experienceId, 'variations', $variationId, 'id', 'id');
    }

    public function reset()
    {
        $this->bucketedVisitors = [];
    }

    public function putData(string $visitorId, array $newData = [])
    {
        $storeKey = $this->getStoreKey($visitorId);
        $storeData = $this->getData($visitorId) ?? [];
        $isChanged = !ObjectUtils::objectDeepEqual($storeData, $newData);
        if ($isChanged) {
            $updatedData = ObjectUtils::objectDeepMerge($storeData, $newData);
            $this->bucketedVisitors[$storeKey] = $updatedData;
            if (count($this->bucketedVisitors) > $this->localStoreLimit) {
                foreach ($this->bucketedVisitors as $key => $value) {
                    unset($this->bucketedVisitors[$key]);
                    break;
                }
            }
            if ($this->dataStoreManager) {
                $storedSegments = $storeData['segments'] ?? [];
                $data = $storeData;
                unset($data['segments']);
                $reportSegments = ($this->filterReportSegments($storedSegments)['segments']) ?? [];
                $newSegments = ($this->filterReportSegments($newData['segments'] ?? [])['segments']) ?? [];
                if ($newSegments) {
                    if ($this->asyncStorage) {
                        $this->dataStoreManager->enqueue($storeKey, ObjectUtils::objectDeepMerge($data, ['segments' => array_merge($reportSegments, $newSegments)]));
                    } else {
                        $this->dataStoreManager->set($storeKey, ObjectUtils::objectDeepMerge($data, ['segments' => array_merge($reportSegments, $newSegments)]));
                    }
                } else {
                    if ($this->asyncStorage) {
                        $this->dataStoreManager->enqueue($storeKey, $updatedData);
                    } else {
                        $this->dataStoreManager->set($storeKey, $updatedData);
                    }
                }
            }
        }
    }

    public function getStoreKey(string $visitorId): string
    {
        return $this->accountId . '-' . $this->projectId . '-' . $visitorId;
    }

    public function selectLocations(string $visitorId, array $items, array $attributes): array
    {
        $locationProperties = $attributes['locationProperties'] ?? [];
        $identityField = $attributes['identityField'] ?? 'key';
        $forceEvent = $attributes['forceEvent'] ?? false;
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager.selectLocations()', call_user_func($this->mapper, [
                'items' => $items,
                'locationProperties' => $locationProperties
            ]));
        }
        $stored = $this->getData($visitorId);
        $locations = $stored['locations'] ?? [];
        $matchedRecords = [];
        if (ArrayUtils::arrayNotEmpty($items)) {
            for ($i = 0, $length = count($items); $i < $length; $i++) {
                $item = $items[$i];
                if (!isset($item['rules'])) continue;
                $idVal = isset($item[$identityField]) ? $item[$identityField] : '';
                $match = $this->ruleManager->isRuleMatched($locationProperties, $item['rules'], "ConfigLocation #" . $idVal);
                $identity = isset($item[$identityField]) && method_exists($item[$identityField], '__toString') ? strval($item[$identityField]) : (string)$item[$identityField];
                if ($match === true) {
                    if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                        $this->loggerManager->info('DataManager.selectLocations()', str_replace('#', "#{$identity}", MESSAGES::LOCATION_MATCH));
                    }
                    if (!in_array($identity, $locations) || $forceEvent) {
                        $this->eventManager->fire(SystemEvents::LOCATION_ACTIVATED, [
                            'visitorId' => $visitorId,
                            'location' => [
                                'id' => $item['id'] ?? null,
                                'key' => $item['key'] ?? null,
                                'name' => $item['name'] ?? null
                            ]
                        ], null, true);
                        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                            $this->loggerManager->info('DataManager.selectLocations()', str_replace('#', "#{$identity}", MESSAGES::LOCATION_ACTIVATED));
                        }
                    }
                    if (!in_array($identity, $locations)) {
                        $locations[] = $identity;
                    }
                    $matchedRecords[] = $item;
                } elseif ($match !== false) {
                    $matchedRecords[] = $match;
                } elseif ($match === false && in_array($identity, $locations)) {
                    $this->eventManager->fire(SystemEvents::LOCATION_DEACTIVATED, [
                        'visitorId' => $visitorId,
                        'location' => [
                            'id' => $item['id'] ?? null,
                            'key' => $item['key'] ?? null,
                            'name' => $item['name'] ?? null
                        ]
                    ], null, true);
                    $index = array_search($identity, $locations);
                    if ($index !== false) {
                        unset($locations[$index]);
                    }
                    if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
                        $this->loggerManager->info('DataManager.selectLocations()', str_replace('#', "#{$identity}", MESSAGES::LOCATION_DEACTIVATED));
                    }
                }
            }
        }
        $this->putData($visitorId, ['locations' => $locations]);
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
            $this->loggerManager->debug('DataManager.selectLocations()', call_user_func($this->mapper, ['matchedRecords' => $matchedRecords]));
        }
        return $matchedRecords;
    }

    public function getBucketing(string $visitorId, string $key, array $attributes)
    {
        return $this->_getBucketingByField($visitorId, $key, 'key', $attributes);
    }

    public function getBucketingById(string $visitorId, string $id, array $attributes)
    {
        return $this->_getBucketingByField($visitorId, $id, 'id', $attributes);
    }

    public function convert(string $visitorId, string $goalId, ?array $goalRule = null, ?array $goalData = null, ?array $segments = null, ?array $conversionSetting = null)
    {
        $goal = is_string($goalId) ? $this->getEntity($goalId, 'goals') : $this->getEntityById($goalId, 'goals');
        if (!isset($goal['id']) || !$goal['id']) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                $this->loggerManager->error('DataManager.convert()', MESSAGES::GOAL_NOT_FOUND);
            }
            return null;
        }
        if ($goalRule) {
            if (!isset($goal['rules'])) return null;
            $ruleMatched = $this->ruleManager->isRuleMatched($goalRule, $goal['rules'], "ConfigGoal #{$goalId}");
            if (in_array($ruleMatched, (array)RuleError::getConstants(), true))
                return $ruleMatched;
            if (!$ruleMatched) {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                    $this->loggerManager->error('DataManager.convert()', MESSAGES::GOAL_RULE_NOT_MATCH);
                }
                return null;
            }
        }
        $forceMultipleTransactions = $conversionSetting[$conversionSetting ? ConversionSettingKey::FORCE_MULTIPLE_TRANSACTIONS : null] ?? null;
        $dataForVisitor = $this->getData($visitorId) ?? [];
        $bucketingData = $dataForVisitor['bucketing'] ?? [];
        $goalTriggered = $dataForVisitor['goals'][strval($goalId)] ?? null;
        if ($goalTriggered) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
                $this->loggerManager->debug('DataManager.convert()', str_replace('#', strval($goalId), MESSAGES::GOAL_FOUND), call_user_func($this->mapper, [
                    'visitorId' => $visitorId,
                    'goalId' => $goalId
                ]));
            }
            if (!$forceMultipleTransactions) return null;
        }
        $this->putData($visitorId, ['goals' => [strval($goalId) => true]]);
        if (!$goalTriggered) {
            $this->sendConversion($visitorId, $goal);
        }
        if ($goalData && (!$goalTriggered || $forceMultipleTransactions)) {
            $this->sendTransaction($visitorId, $goal, $goalData);
        }
        return true;
    }

    private function sendConversion(string $visitorId, array $goal)
    {
        $data = ['goalId' => $goal['id']];
        $dataForVisitor = $this->getData($visitorId);
        if (isset($dataForVisitor['bucketing'])) {
            $data['bucketingData'] = $dataForVisitor['bucketing'];
        }
        $event = [
            'eventType' => eventType::CONVERSION,
            'data' => $data
        ];
        $this->apiManager->enqueue($visitorId, $event, null);
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager.convert()', call_user_func($this->mapper, ['event' => $event]));
        }
    }

    private function sendTransaction(string $visitorId, array $goal, array $goalData)
    {
        $data = ['goalId' => $goal['id'], 'goalData' => $goalData];
        $dataForVisitor = $this->getData($visitorId);
        if (isset($dataForVisitor['bucketing'])) {
            $data['bucketingData'] = $dataForVisitor['bucketing'];
        }
        $event = [
            'eventType' => eventType::CONVERSION,
            'data' => $data
        ];
        $this->apiManager->enqueue($visitorId, $event, null);
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager.convert()', call_user_func($this->mapper, ['event' => $event]));
        }
    }

    public function filterMatchedRecordsWithRule(array $items, array $visitorProperties, string $entityType, string $field = 'id'): array
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager.filterMatchedRecordsWithRule()', call_user_func($this->mapper, [
                'items' => $items,
                'visitorProperties' => $visitorProperties
            ]));
        }
        $matchedRecords = [];
        if (ArrayUtils::arrayNotEmpty($items)) {
            foreach ($items as $item) {
                if (!isset($item['rules'])) continue;
                $idValue = isset($item[$field]) ? $item[$field] : '';
                $match = $this->ruleManager->isRuleMatched($visitorProperties, $item['rules'], camelCase($entityType) . " #{$idValue}");
                if ($match === true) {
                    $matchedRecords[] = $item;
                } elseif ($match !== false) {
                    $matchedRecords[] = $match;
                }
            }
        }
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
            $this->loggerManager->debug('DataManager.filterMatchedRecordsWithRule()', call_user_func($this->mapper, ['matchedRecords' => $matchedRecords]));
        }
        return $matchedRecords;
    }

    public function filterMatchedCustomSegments(array $items, string $visitorId): array
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager.filterMatchedCustomSegments()', call_user_func($this->mapper, [
                'items' => $items,
                'visitorId' => $visitorId
            ]));
        }
        $data = $this->getData($visitorId);
        $customSegments = $data['segments'][SegmentsKeys::CUSTOM_SEGMENTS] ?? [];
        $matchedRecords = [];
        if (ArrayUtils::arrayNotEmpty($items)) {
            foreach ($items as $item) {
                if (!isset($item['id'])) continue;
                if (in_array($item['id'], $customSegments)) {
                    $matchedRecords[] = $item;
                }
            }
        }
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'debug')) {
            $this->loggerManager->debug('DataManager.filterMatchedCustomSegments()', call_user_func($this->mapper, ['matchedRecords' => $matchedRecords]));
        }
        return $matchedRecords;
    }

    public function filterReportSegments(array $visitorProperties): array
    {
        $segmentsKeys = array_map(function ($key) {
            return (string)$key;
        }, SegmentsKeys::getConstants());
        $segments = [];
        $properties = [];
        foreach ($visitorProperties as $key => $value) {
            if (in_array($key, $segmentsKeys)) {
                $segments[$key] = $value;
            } else {
                $properties[$key] = $value;
            }
        }
        return [
            'properties' => count($properties) ? $properties : null,
            'segments' => count($segments) ? $segments : null
        ];
    }

    public function getEntitiesList(string $entityType): array
    {
        $mappedEntityType = \ConvertSdk\Enums\DATA_ENTITIES_MAP[$entityType] ?? $entityType;
        $list = [];
        if (in_array($mappedEntityType, $this->dataEntities)) {
            $list = ObjectUtils::objectDeepValue($this->data, $mappedEntityType) ?? [];
        }
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager.getEntitiesList()', call_user_func($this->mapper, [
                'entityType' => $mappedEntityType,
                'list' => $list
            ]));
        }
        return $list;
    }

    public function getEntitiesListObject(string $entityType, string $field = 'id'): array
    {
        $list = $this->getEntitiesList($entityType);
        $result = [];
        foreach ($list as $entity) {
            $result[$entity[$field]] = $entity;
        }
        return $result;
    }

    private function _getEntityByField(string $identity, string $entityType, string $identityField = 'key')
    {
        $mappedEntityType = \ConvertSdk\Enums\DATA_ENTITIES_MAP[$entityType] ?? $entityType;
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager._getEntityByField()', call_user_func($this->mapper, [
                'identity' => $identity,
                'entityType' => $mappedEntityType,
                'identityField' => $identityField
            ]));
        }
        $list = $this->getEntitiesList($mappedEntityType);
        if (ArrayUtils::arrayNotEmpty($list)) {
            foreach ($list as $item) {
                if (isset($item[$identityField]) && strval($item[$identityField]) === strval($identity)) {
                    return $item;
                }
            }
        }
        return null;
    }

    public function getEntity(string $key, string $entityType)
    {
        return $this->_getEntityByField($key, $entityType, 'key');
    }

    public function getEntities(array $keys, string $entityType): array
    {
        return $this->getItemsByKeys($keys, $entityType);
    }

    public function getEntityById(string $id, string $entityType)
    {
        return $this->_getEntityByField($id, $entityType, 'id');
    }

    public function getEntitiesByIds(array $ids, string $entityType): array
    {
        return $this->getItemsByIds($ids, $entityType);
    }

    public function getItemsByKeys(array $keys, string $path): array
    {
        $list = $this->getEntitiesList($path);
        $items = [];
        if (ArrayUtils::arrayNotEmpty($list)) {
            foreach ($list as $item) {
                if (in_array($item['key'], $keys)) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    public function getItemsByIds(array $ids, string $path): array
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('DataManager.getItemsByIds()', call_user_func($this->mapper, [
                'ids' => $ids,
                'path' => $path
            ]));
        }
        $items = [];
        if (ArrayUtils::arrayNotEmpty($ids)) {
            $list = $this->getEntitiesList($path);
            if (ArrayUtils::arrayNotEmpty($list)) {
                foreach ($list as $item) {
                    if (in_array($item['id'], $ids)) {
                        $items[] = $item;
                    }
                }
            }
        }
        return $items;
    }

    public function getSubItem(string $entityType, string $entityIdentity, string $subEntityType, string $subEntityIdentity, string $identityField, string $subIdentityField)
    {
        $entity = $this->_getEntityByField($entityIdentity, $entityType, $identityField);
        if ($entity && isset($entity[$subEntityType]) && is_array($entity[$subEntityType])) {
            foreach ($entity[$subEntityType] as $subEntity) {
                if (isset($subEntity[$subIdentityField]) && $subEntity[$subIdentityField] === $subEntityIdentity) {
                    return $subEntity;
                }
            }
        }
        return null;
    }

    public function isValidConfigData(array $data): bool
    {
        return ObjectUtils::objectNotEmpty($data) &&
            ((isset($data['account_id']) && isset($data['project']['id'])) || isset($data['error']));
    }
}
