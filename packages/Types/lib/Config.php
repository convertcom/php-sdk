<?php

namespace OpenApi\Client;

use OpenAPI\Client\Model\ConfigResponseData;
use InvalidArgumentException;

/**
 * Configuration class representing the SDK configuration.
 * Can be initialized with either an SDK key or configuration data, but not both.
 *
 * @package ConvertSdk
 */
class Config
{
    /** @var string Required environment setting */
    private string $environment;

    /** @var ?array Optional API endpoint configuration */
    private ?array $api = null;

    /** @var ?array Optional bucketing configuration */
    private ?array $bucketing = null;

    /** @var ?object Optional data store object */
    private ?object $dataStore = null;

    /** @var ?int Optional data refresh interval in seconds */
    private ?int $dataRefreshInterval = null;

    /** @var ?array Optional events configuration */
    private ?array $events = null;

    /** @var ?array Optional rules configuration */
    private ?array $rules = null;

    /** @var ?array Optional logger configuration */
    private ?array $logger = null;

    /** @var ?array Optional network configuration */
    private ?array $network = null;

    /** @var callable|null Optional mapper function */
    private $mapper = null; // Changed from ?callable to untyped with runtime check

    /** @var ?string SDK key for authentication */
    private ?string $sdkKey = null;

    /** @var ?string Optional SDK key secret */
    private ?string $sdkKeySecret = null;

    /** @var ?ConfigResponseData Configuration data from API */
    private ?ConfigResponseData $data = null;

    /**
     * Config constructor.
     *
     * @param array $options Configuration options
     * @throws InvalidArgumentException If validation fails
     */
    public function __construct(array $options)
    {
        // Ensure environment is provided (required in ConfigBase)
        if (!isset($options['environment']) || !is_string($options['environment'])) {
            throw new InvalidArgumentException("Environment is required and must be a string");
        }
        $this->environment = $options['environment'];

        // Validate sdkKey vs data exclusivity
        $hasSdkKey = isset($options['sdkKey']);
        $hasData = isset($options['data']);

        if ($hasSdkKey && $hasData) {
            throw new InvalidArgumentException("Cannot provide both sdkKey and data");
        }

        if (!$hasSdkKey && !$hasData) {
            throw new InvalidArgumentException("Must provide either sdkKey or data");
        }

        if ($hasSdkKey) {
            if (!is_string($options['sdkKey'])) {
                throw new InvalidArgumentException("sdkKey must be a string");
            }
            $this->sdkKey = $options['sdkKey'];
            $this->sdkKeySecret = isset($options['sdkKeySecret']) && is_string($options['sdkKeySecret'])
                ? $options['sdkKeySecret']
                : null;
        } elseif ($hasData) {
            if (!$options['data'] instanceof ConfigResponseData) {
                throw new InvalidArgumentException("data must be an instance of ConfigResponseData");
            }
            $this->data = $options['data'];
        }

        // Set optional properties from ConfigBase
        $this->api = isset($options['api']) && is_array($options['api']) ? $options['api'] : null;
        $this->bucketing = isset($options['bucketing']) && is_array($options['bucketing']) ? $options['bucketing'] : null;
        $this->dataStore = isset($options['dataStore']) && is_object($options['dataStore']) ? $options['dataStore'] : null;
        $this->dataRefreshInterval = isset($options['dataRefreshInterval']) && is_int($options['dataRefreshInterval'])
            ? $options['dataRefreshInterval']
            : null;
        $this->events = isset($options['events']) && is_array($options['events']) ? $options['events'] : null;
        $this->rules = isset($options['rules']) && is_array($options['rules']) ? $options['rules'] : null;
        $this->logger = isset($options['logger']) && is_array($options['logger']) ? $options['logger'] : null;
        $this->network = isset($options['network']) && is_array($options['network']) ? $options['network'] : null;
        $this->mapper = isset($options['mapper']) && is_callable($options['mapper']) ? $options['mapper'] : null;
    }

    // Getters
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getApi(): ?array
    {
        return $this->api;
    }

    public function getBucketing(): ?array
    {
        return $this->bucketing;
    }

    public function getDataStore(): ?object
    {
        return $this->dataStore;
    }

    public function getDataRefreshInterval(): ?int
    {
        return $this->dataRefreshInterval;
    }

    public function getEvents(): ?array
    {
        return $this->events;
    }

    public function getRules(): ?array
    {
        return $this->rules;
    }

    public function getLogger(): ?array
    {
        return $this->logger;
    }

    public function getNetwork(): ?array
    {
        return $this->network;
    }

    public function getMapper() // No return type hint due to PHP 7.4 limitation
    {
        return $this->mapper;
    }

    public function getSdkKey(): ?string
    {
        return $this->sdkKey;
    }

    public function getSdkKeySecret(): ?string
    {
        return $this->sdkKeySecret;
    }

    public function getData(): ?ConfigResponseData
    {
        return $this->data;
    }
}