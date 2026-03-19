<?php

declare(strict_types=1);

/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright (c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Interfaces\CoreInterface;
use ConvertSdk\Interfaces\ContextInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\ExperienceManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Config\ConfigValidator;
use ConvertSdk\Exception\ConfigFetchException;
use ConvertSdk\Exception\ConfigValidationException;
use ConvertSdk\Exception\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * Core SDK class that manages configuration, initialization, and visitor context creation.
 *
 * @implements CoreInterface
 */
final class Core implements CoreInterface
{
    /** Default data refresh interval in seconds (5 minutes) */
    public const DEFAULT_DATA_REFRESH_INTERVAL = 300;

    /** @var string|null */
    private ?string $environment;

    /** @var bool */
    private bool $initialized = false;

    /** @var ConfigValidator */
    private ConfigValidator $configValidator;

    /**
     * @param Config $config Configuration object
     * @param DataManagerInterface $dataManager Data manager instance
     * @param EventManagerInterface $eventManager Event manager instance
     * @param ExperienceManagerInterface $experienceManager Experience manager instance
     * @param FeatureManagerInterface $featureManager Feature manager instance
     * @param SegmentsManagerInterface $segmentsManager Segments manager instance
     * @param ApiManagerInterface $apiManager API manager instance
     * @param CacheInterface $cache PSR-16 cache implementation
     * @param int $dataRefreshInterval Cache TTL in seconds
     * @param LogManagerInterface|null $loggerManager Optional logger manager instance
     */
    public function __construct(
        private readonly Config $config,
        private readonly DataManagerInterface $dataManager,
        private readonly EventManagerInterface $eventManager,
        private readonly ExperienceManagerInterface $experienceManager,
        private readonly FeatureManagerInterface $featureManager,
        private readonly SegmentsManagerInterface $segmentsManager,
        private readonly ApiManagerInterface $apiManager,
        private readonly CacheInterface $cache,
        private readonly int $dataRefreshInterval = self::DEFAULT_DATA_REFRESH_INTERVAL,
        private readonly ?LogManagerInterface $loggerManager = null,
    ) {
        $this->environment = $config->getEnvironment() ?? null;
        $this->configValidator = new ConfigValidator();
        $this->initialize();
    }

    /**
     * Build a PSR-16 compliant cache key for the given SDK key.
     *
     * @param string $sdkKey The SDK key to hash
     * @return string A cache-safe key
     */
    private function buildCacheKey(string $sdkKey): string
    {
        if (preg_match('/^[A-Za-z0-9_.]+$/', $sdkKey) && strlen($sdkKey) <= 48) {
            return 'convert_sdk.config.' . $sdkKey;
        }

        return 'convert_sdk.config.' . substr(hash('sha256', $sdkKey), 0, 16);
    }

    /**
     * Initialize credentials, configData etc.
     *
     * @return void
     */
    private function initialize(): void
    {
        if ($this->config->getSdkKey() && strlen($this->config->getSdkKey()) > 0) {
            try {
                $this->fetchConfig();
                $this->eventManager->fire(SystemEvents::Ready, [], null, true);
                $this->loggerManager?->trace('Core.initialize()', Messages::CORE_INITIALIZED);
                $this->initialized = true;
            } catch (\Exception $e) {
                $this->loggerManager?->error('Core.initialize()', ['error' => $e->getMessage()]);
                $this->eventManager->fire(
                    SystemEvents::Ready,
                    [],
                    $e,
                    true
                );
            }
        } elseif ($this->config->getData()) {
            try {
                $this->configValidator->validate($this->config->getData());
            } catch (ConfigValidationException $e) {
                $this->loggerManager?->error('Core.initialize()', ['error' => $e->getMessage()]);
                $this->eventManager->fire(
                    SystemEvents::Ready,
                    [],
                    $e,
                    true
                );
                return;
            }

            $this->dataManager->setConfigData($this->config->getData());
            $this->eventManager->fire(SystemEvents::Ready, [], null, true);
            $this->loggerManager?->trace('Core.initialize()', Messages::CORE_INITIALIZED);
            $this->initialized = true;
        } else {
            $this->loggerManager?->error('Core.initialize()', ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED);
            $this->eventManager->fire(
                SystemEvents::Ready,
                [],
                new \Exception(ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED),
                true
            );
        }
    }

    /**
     * Create a visitor context.
     *
     * @param string $visitorId A unique visitor identifier
     * @param array<string, mixed>|null $visitorAttributes Key-value pairs for audience/segments targeting
     * @return ContextInterface|null The visitor context, or null if SDK is not initialized
     * @throws InvalidArgumentException If visitorId is empty
     */
    public function createContext(string $visitorId, ?array $visitorAttributes = null): ?ContextInterface
    {
        if ($visitorId === '') {
            throw new InvalidArgumentException('Visitor ID must not be empty');
        }
        if (!$this->initialized) {
            return null;
        }
        return new Context(
            $this->config,
            $visitorId,
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
            $this->loggerManager,
            $visitorAttributes
        );
    }

    /**
     * Attach an event handler to a system event.
     *
     * @param string $event Event name (SystemEvents)
     * @param callable $fn A callback function which will be fired
     * @return void
     */
    public function on(string $event, callable $fn): void
    {
        $this->eventManager->on($event, $fn);
    }

    /**
     * Check if the SDK is fully initialized and ready to use.
     *
     * @return bool True if the SDK is initialized with valid config data
     */
    public function isReady(): bool
    {
        try {
            $configData = $this->dataManager->getConfigData();
            if ($this->initialized && $configData->getAccountId() && $configData->getProject()) {
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if the system is ready.
     *
     * @deprecated Use isReady() instead
     * @return bool
     */
    public function onReady(): bool
    {
        return $this->isReady();
    }

    /**
     * Fetch remote config data, using cache when available.
     *
     * @return void
     * @throws ConfigFetchException If the remote config fetch fails
     * @throws ConfigValidationException If the fetched config is invalid
     */
    private function fetchConfig(): void
    {
        $sdkKey = $this->config->getSdkKey();
        $cacheKey = $this->buildCacheKey($sdkKey);

        $configEndpoint = $this->config->getApi() && isset($this->config->getApi()['endpoint']['config'])
            ? $this->config->getApi()['endpoint']['config']
            : '';

        // Check cache first
        $cachedData = $this->cache->get($cacheKey);

        if ($cachedData instanceof ConfigResponseData) {
            $this->loggerManager?->trace('Core.fetchConfig()', 'Using cached config');

            try {
                $this->configValidator->validate($cachedData);
            } catch (ConfigValidationException $e) {
                $this->loggerManager?->error('Core.fetchConfig()', ['error' => 'Cached config invalid, fetching fresh: ' . $e->getMessage()]);
                $this->cache->delete($cacheKey);
                $cachedData = null;
            }
        } else {
            $cachedData = null;
        }

        if ($cachedData !== null) {
            $data = $cachedData;
        } else {
            // Cache miss — fetch via HTTP
            try {
                $data = $this->apiManager->getConfig();
            } catch (\RuntimeException $error) {
                $this->loggerManager?->error('Core.fetchConfig()', ['error' => $error->getMessage()]);
                throw new ConfigFetchException(
                    $error->getMessage(),
                    (int) $error->getCode(),
                    $configEndpoint,
                    $error
                );
            }

            // Validate fresh config
            $this->configValidator->validate($data);

            // Store in cache
            $this->cache->set($cacheKey, $data, $this->dataRefreshInterval);
            $this->loggerManager?->trace('Core.fetchConfig()', 'Config cached with TTL ' . $this->dataRefreshInterval . 's');
        }

        $this->dataManager->setConfigData($data);
        $this->loggerManager?->trace('Core.fetchConfig()', ['data' => $data]);

        // Only fire ConfigUpdated on subsequent refreshes, not initial load
        if ($this->initialized) {
            $this->eventManager->fire(SystemEvents::ConfigUpdated, [], null, true);
        }

        $this->apiManager->setData($data);
        $this->loggerManager?->trace('Core.fetchConfig()', Messages::CONFIG_DATA_UPDATED);
    }
}
