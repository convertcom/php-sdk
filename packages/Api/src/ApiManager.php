<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use OpenAPI\Client\VisitorsQueue;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Class ApiManager
 *
 * Implements the ApiManagerInterface to handle API interactions for the Convert SDK.
 */
class ApiManager implements ApiManagerInterface
{
    /**
      * Default HTTP headers
      */
    private const DEFAULT_HEADERS = [
        'Content-Type' => 'application/json',
    ];

    /**
     * Default batch size for queue processing
     */
    private const DEFAULT_BATCH_SIZE = 10;

    /**
     * Default release interval in milliseconds
     */
    private const DEFAULT_RELEASE_INTERVAL = 10000;

    /**
     * Default configuration endpoint
     */
    private const DEFAULT_CONFIG_ENDPOINT = '';

    /**
     * Default tracking endpoint
     */
    private const DEFAULT_TRACK_ENDPOINT = '';

    /** @var VisitorsQueue Queue for tracking visitor requests */
    private VisitorsQueue $requestsQueue;

    /** @var ?int Timer ID for the requests queue */
    private ?int $requestsQueueTimerID = null;

    /** @var bool Whether timeout-based release is enabled */
    private bool $timeoutEnabled = true;

    /** @var string Configuration endpoint URL */
    private string $configEndpoint;

    /** @var string Tracking endpoint URL */
    private string $trackEndpoint;

    /** @var array<string, string> Default HTTP headers */
    private array $defaultHeaders = self::DEFAULT_HEADERS;

    /** @var ?ConfigResponseData Configuration response data */
    private ?ConfigResponseData $data = null;

    /** @var bool Whether to enrich data */
    private bool $enrichData;

    /** @var ?string Environment setting */
    private ?string $environment = null;

    /** @var ?LogManagerInterface Logger manager instance */
    private ?LogManagerInterface $loggerManager = null;

    /** @var ?EventManagerInterface Event manager instance */
    private ?EventManagerInterface $eventManager = null;

    /** @var string SDK key */
    private string $sdkKey;

    /** @var string Account ID */
    private string $accountId;

    /** @var string Project ID */
    private string $projectId;

    /** @var array<string, mixed> Tracking event data */
    private array $trackingEvent;

    /** @var bool Whether tracking is enabled */
    private bool $trackingEnabled = false;

    /** @var string Source of tracking */
    private string $trackingSource;

    /** @var string Cache level setting */
    private string $cacheLevel;

    /** @var callable Mapper function for data transformation */
    private mixed $mapper;

    /** @var int Batch size for queue processing */
    private int $batchSize;

    /** @var int Release interval in milliseconds */
    private int $releaseInterval;

    /** @var ClientInterface PSR-18 HTTP client */
    private ClientInterface $httpClient;

    /** @var RequestFactoryInterface PSR-17 request factory */
    private RequestFactoryInterface $requestFactory;

    /** @var StreamFactoryInterface PSR-17 stream factory */
    private StreamFactoryInterface $streamFactory;

    /**
     * ApiManager constructor.
     *
     * @param ?Config $config Configuration object
     * @param ?EventManagerInterface $eventManager Event manager dependency
     * @param ?LogManagerInterface $loggerManager Logger manager dependency
     * @param ?ClientInterface $httpClient PSR-18 HTTP client (auto-discovered if null)
     * @param ?RequestFactoryInterface $requestFactory PSR-17 request factory (auto-discovered if null)
     * @param ?StreamFactoryInterface $streamFactory PSR-17 stream factory (auto-discovered if null)
     */
    public function __construct(
        ?Config $config = null,
        ?EventManagerInterface $eventManager = null,
        ?LogManagerInterface $loggerManager = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->loggerManager = $loggerManager;
        $this->eventManager = $eventManager;

        $this->configEndpoint = $config && $config->getApi() && isset($config->getApi()['endpoint']['config'])
            ? $config->getApi()['endpoint']['config']
            : self::DEFAULT_CONFIG_ENDPOINT;
        $this->trackEndpoint = $config && $config->getApi() && isset($config->getApi()['endpoint']['track'])
            ? $config->getApi()['endpoint']['track']
            : self::DEFAULT_TRACK_ENDPOINT;

        $this->data = $config ? $config->getData() : null;
        $this->enrichData = $config ? ($config->getDataStore() === null) : true;
        $this->environment = $config ? $config->getEnvironment() : null;
        $this->mapper = function ($value) { return $value; };
        $mapperFromConfig = $config ? $config->getMapper() : null;
        if (is_callable($mapperFromConfig)) {
            $this->mapper = $mapperFromConfig;
        }
        $this->batchSize = $config && $config->getEvents() && isset($config->getEvents()['batch_size'])
            ? (int)$config->getEvents()['batch_size']
            : self::DEFAULT_BATCH_SIZE;
        $this->releaseInterval = $config && $config->getEvents() && isset($config->getEvents()['release_interval'])
            ? (int)$config->getEvents()['release_interval']
            : self::DEFAULT_RELEASE_INTERVAL;

        $this->accountId = $this->data ? $this->data->getAccountId() : '';
        $project = $this->data ? $this->data->getProject() : null;
        $this->projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
        $this->sdkKey = $config && $config->getSdkKey() ? $config->getSdkKey() : "{$this->accountId}/{$this->projectId}";
        if ($config && $config->getSdkKeySecret()) {
            $this->defaultHeaders['Authorization'] = "Bearer {$config->getSdkKeySecret()}";
        }
        $this->trackingEvent = [
            'enrichData' => $this->enrichData,
            'accountId' => $this->accountId,
            'projectId' => $this->projectId,
            'visitors' => [],
        ];
        $this->trackingEnabled = $config && $config->getNetwork() && isset($config->getNetwork()['tracking'])
            ? (bool) $config->getNetwork()['tracking']
            : false;
        $this->trackingSource = $config && $config->getNetwork() && isset($config->getNetwork()['source'])
            ? (string) $config->getNetwork()['source']
            : 'js-sdk';
        $this->cacheLevel = $config && $config->getNetwork() && isset($config->getNetwork()['cacheLevel'])
            ? (string) $config->getNetwork()['cacheLevel']
            : '';

        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->requestsQueue = new VisitorsQueue();
    }

    /**
     * Send request to API server.
     *
     * @param string $method HTTP method (e.g., 'GET', 'POST')
     * @param array $path Path with 'base' and 'route' keys
     * @param array $data Request data
     * @param array $headers Request headers
     * @return array Response array with 'data', 'status', 'statusText', 'headers' keys
     */
    public function request(
        string $method,
        array $path,
        array $data = [],
        array $headers = []
    ): array {
        $url = rtrim($path['base'] ?? '', '/') . '/' . ltrim($path['route'] ?? '', '/');
        $request = $this->requestFactory->createRequest($method, $url);

        $requestHeaders = array_merge($this->defaultHeaders, $headers);
        foreach ($requestHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true) && !empty($data)) {
            $body = $this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR));
            $request = $request->withBody($body);
        }

        $response = $this->httpClient->sendRequest($request);

        $rawBody = $response->getBody()->getContents();
        $decoded = json_decode($rawBody, true);

        return [
            'data' => $decoded,
            'status' => $response->getStatusCode(),
            'statusText' => $response->getReasonPhrase(),
            'headers' => $response->getHeaders(),
        ];
    }

    /**
     * Add request to queue for sending to server.
     *
     * @param string $visitorId Visitor ID
     * @param VisitorTrackingEvents $eventRequest Event request data
     * @param ?VisitorSegments $segments Visitor segments (optional)
     * @return void
     */
    public function enqueue(
        string $visitorId,
        VisitorTrackingEvents $eventRequest,
        ?VisitorSegments $segments = null
    ): void {
        if ($this->loggerManager && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace(
                'ApiManager.enqueue()',
                call_user_func($this->mapper, ['eventRequest' => $eventRequest])
            );
        }
        /** @var array<string, mixed> $eventArray */
        $eventArray = json_decode((string) json_encode($eventRequest), true) ?? [];
        /** @var array<string, mixed> $segmentsArray */
        $segmentsArray = $segments !== null
            ? (json_decode((string) json_encode($segments), true) ?? [])
            : [];
        $this->requestsQueue->push($visitorId, $eventArray, $segmentsArray);
        if ($this->trackingEnabled) {
            $this->releaseQueue('size');
            if ($this->requestsQueue->length === $this->getBatchSize()) {
                $this->releaseQueue('size');
            } elseif ($this->requestsQueue->length === 1) {
                $this->startQueue();
            }
        }
    }

    /**
     * Maximum number of retries for tracking POST requests.
     */
    private const MAX_RETRIES = 2;

    /**
     * Retry delays in microseconds: 100ms after first failure, 300ms after second.
     * @var array<int, int>
     */
    private const RETRY_DELAYS_US = [100_000, 300_000];

    /**
     * Send queue to server with retry logic.
     *
     * Retries up to MAX_RETRIES times on HTTP 5xx or network errors.
     * Does NOT retry on HTTP 4xx (client errors).
     * Backoff: 100ms after first failure, 300ms after second (formula: 100ms * attempt^2).
     *
     * @param ?string $reason Reason for releasing the queue (optional)
     * @return void
     */
    public function releaseQueue(?string $reason = null): void
    {
        if ($this->requestsQueue->length === 0) {
            return;
        }

        if ($this->loggerManager && method_exists($this->loggerManager, 'info')) {
            $this->loggerManager->info('ApiManager.releaseQueue()', 'Releasing queue');
        }
        if ($this->loggerManager && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('ApiManager.releaseQueue()', ['reason' => $reason ?? '']);
        }

        $this->stopQueue();

        $payload = $this->trackingEvent;
        $payload['visitors'] = $this->requestsQueue->getItems();
        $payload['source'] = $this->trackingSource;

        $lastError = null;
        $lastStatusCode = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $this->request(
                    'POST',
                    [
                        'base' => str_replace('[project_id]', (string)$this->projectId, $this->trackEndpoint),
                        'route' => "/track/{$this->sdkKey}",
                    ],
                    call_user_func($this->mapper, $payload)
                );

                $statusCode = $result['status'] ?? 0;

                if ($statusCode >= 200 && $statusCode < 300) {
                    // Success — clear queue and fire event
                    $this->requestsQueue->reset();
                    if ($this->eventManager && method_exists($this->eventManager, 'fire')) {
                        $this->eventManager->fire(SystemEvents::ApiQueueReleased, [
                            'reason' => $reason,
                            'result' => $result,
                            'visitors' => $payload['visitors'],
                        ]);
                    }
                    return;
                }

                if ($statusCode >= 400 && $statusCode < 500) {
                    // Client error — do NOT retry, discard batch
                    if ($this->loggerManager && method_exists($this->loggerManager, 'warn')) {
                        $this->loggerManager->warn('ApiManager.releaseQueue()', [
                            'error' => "Tracking POST returned client error HTTP {$statusCode}",
                            'statusCode' => $statusCode,
                            'endpoint' => "/track/{$this->sdkKey}",
                            'reason' => $reason,
                        ]);
                    }
                    $this->requestsQueue->reset();
                    if ($this->eventManager && method_exists($this->eventManager, 'fire')) {
                        $this->eventManager->fire(
                            SystemEvents::ApiQueueReleased,
                            ['reason' => $reason, 'error' => "HTTP {$statusCode}"],
                            new \RuntimeException("Tracking POST returned client error HTTP {$statusCode}")
                        );
                    }
                    return;
                }

                // Server error (5xx) — retry if attempts remain
                $lastStatusCode = $statusCode;
                $lastError = null;
                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAYS_US[$attempt]);
                }
            } catch (ClientExceptionInterface $e) {
                // Network error — retry if attempts remain
                $lastError = $e;
                $lastStatusCode = null;
                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAYS_US[$attempt]);
                }
            }
        }

        // All retries exhausted — discard batch and log warning
        $logContext = [
            'endpoint' => "/track/{$this->sdkKey}",
            'reason' => $reason,
            'attempts' => self::MAX_RETRIES + 1,
        ];
        if ($lastError !== null) {
            $logContext['error'] = $lastError->getMessage();
        }
        if ($lastStatusCode !== null) {
            $logContext['statusCode'] = $lastStatusCode;
        }
        if ($this->loggerManager && method_exists($this->loggerManager, 'warn')) {
            $this->loggerManager->warn('ApiManager.releaseQueue()', $logContext);
        }

        $this->requestsQueue->reset();
        if ($this->eventManager && method_exists($this->eventManager, 'fire')) {
            $this->eventManager->fire(
                SystemEvents::ApiQueueReleased,
                ['reason' => $reason, 'error' => $lastError ? $lastError->getMessage() : "HTTP {$lastStatusCode}"],
                $lastError
            );
        }
    }

    /**
     * Stop queue timer
     */
    public function stopQueue(): void
    {
        $this->requestsQueueTimerID = null;
    }

    /**
     * Start queue timer
     */
    public function startQueue(): void
    {
        if ($this->timeoutEnabled && $this->requestsQueue->length > 0) {
            $this->requestsQueueTimerID = 1;
            $this->releaseQueue('timeout');
        } else {
            $this->requestsQueueTimerID = 1;
        }
    }

    /**
     * Enable tracking
     */
    public function enableTracking(): void
    {
        $this->trackingEnabled = true;
        $this->releaseQueue('trackingEnabled');
    }

    public function setTimeoutEnabled(bool $enabled): void
    {
        $this->timeoutEnabled = $enabled;
    }

    /**
     * Check if a timeout is "scheduled"
     */
    public function hasPendingTimeout(): bool
    {
        return $this->requestsQueueTimerID !== null;
    }

    /**
     * Disable tracking
     */
    public function disableTracking(): void
    {
        $this->trackingEnabled = false;
    }

    /**
     * Set configuration data
     *
     * @param ConfigResponseData $data Configuration data object
     */
    public function setData(ConfigResponseData $data): void
    {
        $this->data = $data;
        $this->accountId = $data->getAccountId() ?? '';
        $project = $data->getProject();
        $this->projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
        $this->trackingEvent['accountId'] = $this->accountId;
        $this->trackingEvent['projectId'] = $this->projectId;
    }

    /**
     * Get the batch size for queue processing.
     *
     * @return int
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Get configuration data
     *
     * @return ConfigResponseData
     */
    public function getConfig(): ConfigResponseData
    {
        if ($this->loggerManager && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('ApiManager.getConfig()');
        }

        $query = '';
        if ($this->cacheLevel === 'low' || $this->environment) {
            $query = '?';
        }
        if ($this->environment) {
            $query .= 'environment=' . urlencode($this->environment);
        }
        if ($this->cacheLevel === 'low') {
            if ($query !== '?') {
                $query .= '&';
            }
            $query .= '_conv_low_cache=1';
        }

        try {
            $response = $this->request(
                'GET',
                [
                    'base' => $this->configEndpoint,
                    'route' => "/config/{$this->sdkKey}{$query}",
                ]
            );

            $statusCode = $response['status'] ?? 0;
            if ($statusCode < 200 || $statusCode >= 300) {
                $url = $this->configEndpoint . "/config/{$this->sdkKey}";
                if ($this->loggerManager) {
                    $this->loggerManager->error('ApiManager.getConfig()', [
                        'endpoint' => $url . $query,
                        'status' => 'error',
                        'httpStatus' => $statusCode,
                        'error' => "HTTP {$statusCode}",
                    ]);
                }
                throw new \RuntimeException(
                    "Config fetch failed: HTTP {$statusCode} from {$url}",
                    $statusCode
                );
            }

            $data = $response['data'] ?? [];
            $configData = new ConfigResponseData($data);
            // Preserve error field if present — ConfigResponseData constructor drops unknown keys
            if (isset($data['error'])) {
                $configData['error'] = $data['error'];
            }

            if ($this->loggerManager) {
                $project = $configData->getProject();
                $this->loggerManager->debug('ApiManager.getConfig()', [
                    'endpoint' => $this->configEndpoint . "/config/{$this->sdkKey}" . $query,
                    'status' => 'success',
                    'httpStatus' => $statusCode,
                    'accountId' => $configData->getAccountId() ?? 'unknown',
                    'projectId' => $project ? (is_array($project) ? ($project['id'] ?? '') : $project->getId()) : 'unknown',
                    'fetchedAt' => date('c'),
                ]);
            }

            return $configData;
        } catch (ClientExceptionInterface $e) {
            if ($this->loggerManager) {
                $this->loggerManager->error('ApiManager.getConfig()', [
                    'endpoint' => $this->configEndpoint . "/config/{$this->sdkKey}" . $query,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'code' => method_exists($e, 'getCode') ? $e->getCode() : null,
                ]);
            }

            throw new \RuntimeException(
                "Failed to fetch config from {$this->configEndpoint}/config/{$this->sdkKey}: HTTP error - {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }
}
