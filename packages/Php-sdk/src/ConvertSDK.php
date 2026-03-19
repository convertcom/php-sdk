<?php

declare(strict_types=1);

namespace ConvertSdk;

use ConvertSdk\Config\Config;
use ConvertSdk\Cache\ArrayCache;
use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Exception\InvalidArgumentException;
use OpenAPI\Client\Config as OpenApiConfig;
use OpenAPI\Client\Model\ConfigResponseData;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * SDK entry point and factory.
 *
 * ConvertSDK is a static factory class that creates and returns a fully
 * initialized {@see Core} instance. It is not directly instantiable.
 *
 * Usage:
 *   $sdk = ConvertSDK::create(['sdkKey' => 'your-sdk-key']);
 *   $context = $sdk->createContext('visitor-123', ['country' => 'US']);
 */
final class ConvertSDK
{
    /**
     * Prevent direct instantiation — use {@see create()} instead.
     */
    private function __construct() {}

    /**
     * Create and initialize the SDK.
     *
     * Resolves all dependencies, creates managers in the correct order,
     * and returns a fully initialized Core instance.
     *
     * @param array{
     *     sdkKey?: string,
     *     data?: array<string, mixed>|ConfigResponseData,
     *     logger?: LoggerInterface,
     *     cache?: CacheInterface,
     *     dataRefreshInterval?: int,
     *     environment?: string,
     *     network?: array<string, mixed>,
     *     api?: array<string, mixed>,
     * } $config SDK configuration options
     *
     * @return Core A fully initialized Core instance
     *
     * @throws InvalidArgumentException If both sdkKey and data are missing
     */
    public static function create(array $config = []): Core
    {
        // 1. Validate: at least one of sdkKey or data must be provided
        if (empty($config['sdkKey']) && empty($config['data'])) {
            throw new InvalidArgumentException('Either sdkKey or data must be provided');
        }

        // 2. Merge defaults
        $configuration = Config::create($config);
        if (!isset($configuration['network']['source'])) {
            $configuration['network']['source'] = getenv('VERSION') ?: 'php-sdk';
        }

        // Remove empty sdkKey so OpenAPI\Client\Config processes 'data' correctly
        // (its constructor uses isset() and elseif, so an empty sdkKey blocks data)
        if (isset($configuration['sdkKey']) && $configuration['sdkKey'] === '') {
            unset($configuration['sdkKey']);
        }

        // 3. Resolve PSR-3 logger (accepts a single PSR-3 LoggerInterface instance)
        $logger = (isset($config['logger']) && $config['logger'] instanceof LoggerInterface)
            ? $config['logger']
            : new NullLogger();

        $logManager = new LogManager($logger, LogLevel::Warn);

        // 4. Resolve PSR-16 cache
        $cache = (isset($configuration['cache']) && $configuration['cache'] instanceof CacheInterface)
            ? $configuration['cache']
            : new ArrayCache();

        // Resolve dataRefreshInterval: DefaultConfig stores milliseconds, PSR-16 cache uses seconds
        $dataRefreshIntervalMs = (int) ($configuration['dataRefreshInterval'] ?? 300000);
        $dataRefreshInterval = max(1, (int) ($dataRefreshIntervalMs / 1000));

        // 5. Wrap data in ConfigResponseData if raw array provided
        if (!empty($configuration['data']) && is_array($configuration['data'])) {
            $configuration['data'] = new ConfigResponseData($configuration['data']);
        }

        // 6. Create OpenApiConfig wrapper
        $openApiConfig = new OpenApiConfig($configuration);

        // 7. Instantiate managers in dependency order
        $eventManager = new EventManager($openApiConfig, ['loggerManager' => $logManager]);

        try {
            $apiManager = new ApiManager($openApiConfig, $eventManager, $logManager);
        } catch (\Http\Discovery\Exception\NotFoundException $e) {
            throw new \RuntimeException(
                'No PSR-18 HTTP client found. Install one (e.g., guzzlehttp/guzzle ^7) or pass an explicit httpClient.',
                0,
                $e
            );
        }

        $bucketingManager = new BucketingManager($openApiConfig, ['loggerManager' => $logManager]);
        $ruleManager = new RuleManager($openApiConfig, ['loggerManager' => $logManager]);

        $dataManager = new DataManager(
            $openApiConfig,
            $bucketingManager,
            $ruleManager,
            $eventManager,
            $apiManager,
            $logManager
        );

        $experienceManager = new ExperienceManager($openApiConfig, [
            'dataManager' => $dataManager,
            'loggerManager' => $logManager,
        ]);

        $featureManager = new FeatureManager($openApiConfig, $dataManager, $logManager);
        $segmentsManager = new SegmentsManager($openApiConfig, $dataManager, $ruleManager, $logManager);

        // 8. Construct and return Core
        return new Core(
            $openApiConfig,
            $dataManager,
            $eventManager,
            $experienceManager,
            $featureManager,
            $segmentsManager,
            $apiManager,
            $cache,
            $dataRefreshInterval,
            $logManager
        );
    }
}
