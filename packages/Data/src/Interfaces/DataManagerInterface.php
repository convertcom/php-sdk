<?php
/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use ConvertSdk\Types\IdentityField;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\ConversionSettingKey;
use ConvertSdk\Interfaces\DataStoreManagerInterface;
use OpenAPI\Client\ConfigResponseData;
use OpenAPI\Client\StoreData;
use OpenAPI\Client\VisitorSegments;
use OpenAPI\Client\ConfigExperience;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\LocationAttributes;
use OpenAPI\Client\GoalData;
use OpenAPI\Client\BucketedVariation;
use OpenAPI\Client\Entity;


/**
 * @typedef Entity OpenAPI\Client\Entity
 */
interface DataManagerInterface
{
    /**
     * Get the configuration data.
     *
     * @return ConfigResponseData
     */
    public function getData(): ConfigResponseData;

    /**
     * Set the configuration data.
     *
     * @param ConfigResponseData $data
     * @return void
     */
    public function setData(ConfigResponseData $data): void;

    /**
     * Get the data store manager.
     *
     * @return DataStoreManagerInterface
     */
    public function getDataStoreManager(): DataStoreManagerInterface;

    /**
     * Set the data store manager.
     *
     * @param DataStoreManagerInterface $dataStoreManager
     * @return void
     */
    public function setDataStoreManager(DataStoreManagerInterface $dataStoreManager): void;

    /**
     * Reset the data manager state.
     *
     * @return void
     */
    public function reset(): void;

    /**
     * Store data for a given key.
     *
     * @param string $storeKey
     * @param StoreData $storeData
     * @return void
     */
    public function putData(string $storeKey, StoreData $storeData): void;

    /**
     * Retrieve data for a given key.
     *
     * @param string $storeKey
     * @return StoreData
     */
    public function getStoreData(string $storeKey): StoreData;

    /**
     * Generate a store key from a visitor ID.
     *
     * @param string $visitorId
     * @return string
     */
    public function getStoreKey(string $visitorId): string;

    /**
     * Select locations based on attributes.
     *
     * @param string $visitorId
     * @param array $items Array of items with string keys and any values
     * @param LocationAttributes $attributes
     * @return array Array of items (associative arrays) or RuleError instances
     */
    public function selectLocations(string $visitorId, array $items, LocationAttributes $attributes): array;

    /**
     * Match rules by field for bucketing.
     *
     * @param string $visitorId
     * @param string $identity
     * @param string $identityField IdentityField constant (id or key)
     * @param BucketingAttributes $attributes
     * @return ConfigExperience|RuleError
     */
    public function matchRulesByField(string $visitorId, string $identity, string $identityField, BucketingAttributes $attributes): mixed;

    /**
     * Get bucketing information by experience key.
     *
     * @param string $visitorId
     * @param string $experienceKey
     * @param BucketingAttributes $attributes
     * @return BucketedVariation|RuleError|BucketingError
     */
    public function getBucketing(string $visitorId, string $experienceKey, BucketingAttributes $attributes): mixed;

    /**
     * Get bucketing information by experience ID.
     *
     * @param string $visitorId
     * @param string $experienceId
     * @param BucketingAttributes $attributes
     * @return BucketedVariation|RuleError|BucketingError
     */
    public function getBucketingById(string $visitorId, string $experienceId, BucketingAttributes $attributes): mixed;

    /**
     * Record a conversion event.
     *
     * @param string $visitorId
     * @param string $goalId
     * @param array|null $goalRule Associative array of goal rules (optional)
     * @param GoalData[]|null $goalData Array of GoalData objects (optional)
     * @param VisitorSegments|null $segments (optional)
     * @param array|null $conversionSetting Associative array with ConversionSettingKey keys (optional)
     * @return RuleError|bool
     */
    public function convert(string $visitorId, string $goalId, ?array $goalRule = null, ?array $goalData = null, ?VisitorSegments $segments = null, ?array $conversionSetting = null): mixed;

    /**
     * Get a list of entities by type.
     *
     * @param string $entityType
     * @return array Array of Entity objects or strings
     */
    public function getEntitiesList(string $entityType): array;

    /**
     * Get entities as an object keyed by a field.
     *
     * @param string $entityType
     * @param string|null $field IdentityField constant (id or key) (optional)
     * @return array Associative array of Entity objects
     */
    public function getEntitiesListObject(string $entityType, ?string $field = null): array;

    /**
     * Get a single entity by key.
     *
     * @param string $key
     * @param string $entityType
     * @return Entity
     */
    public function getEntity(string $key, string $entityType): Entity;

    /**
     * Get multiple entities by keys.
     *
     * @param string[] $keys
     * @param string $entityType
     * @return array Array of Entity objects
     */
    public function getEntities(array $keys, string $entityType): array;

    /**
     * Get a single entity by ID.
     *
     * @param string $id
     * @param string $entityType
     * @return Entity
     */
    public function getEntityById(string $id, string $entityType): Entity;

    /**
     * Get multiple entities by IDs.
     *
     * @param string[] $ids
     * @param string $entityType
     * @return array Array of Entity objects
     */
    public function getEntitiesByIds(array $ids, string $entityType): array;

    /**
     * Get items by keys from a specific path.
     *
     * @param string[] $keys
     * @param string $path
     * @return array Array of items with string keys and any values
     */
    public function getItemsByKeys(array $keys, string $path): array;

    /**
     * Get items by IDs from a specific path.
     *
     * @param string[] $ids
     * @param string $path
     * @return array Array of items with string keys and any values
     */
    public function getItemsByIds(array $ids, string $path): array;

    /**
     * Get a sub-item from an entity.
     *
     * @param string $entityType
     * @param string $entityIdentity
     * @param string $subEntityType
     * @param string $subEntityIdentity
     * @param string $identityField IdentityField constant (id or key)
     * @param string $subIdentityField IdentityField constant (id or key)
     * @return array Associative array of any key-value pairs
     */
    public function getSubItem(
        string $entityType,
        string $entityIdentity,
        string $subEntityType,
        string $subEntityIdentity,
        string $identityField,
        string $subIdentityField
    ): array;

    /**
     * Filter report segments based on visitor properties.
     *
     * @param array $visitorProperties Associative array of visitor properties
     * @return array Filtered associative array
     */
    public function filterReportSegments(array $visitorProperties): array;

    /**
     * Validate configuration data.
     *
     * @param ConfigResponseData $data
     * @return bool
     */
    public function isValidConfigData(ConfigResponseData $data): bool;

    /**
     * Set the data store.
     *
     * @param mixed $dataStore
     * @return void
     */
    public function setDataStore($dataStore): void;
}