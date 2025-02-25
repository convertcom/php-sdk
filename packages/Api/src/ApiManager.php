<?php
namespace ConvertSdk\Api;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\promise_for;
use ConvertSdk\Utils\HttpClient;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Utils\ObjectUtils;

class ApiManager
{
    // Constants
    private const DEFAULT_HEADERS = ['Content-Type' => 'application/json'];
    private const DEFAULT_BATCH_SIZE = 10;
    private const DEFAULT_RELEASE_INTERVAL = 10000; // in milliseconds
    // Default endpoints are taken from environment variables if not provided in config.
    private const DEFAULT_CONFIG_ENDPOINT = null; // e.g. getenv('CONFIG_ENDPOINT')
    private const DEFAULT_TRACK_ENDPOINT  = null; // e.g. getenv('TRACK_ENDPOINT')

    // Properties
    protected $requestsQueue;
    protected $requestsQueueTimerID;

    protected $configEndpoint;
    protected $trackEndpoint;

    protected $defaultHeaders;
    protected $data;
    protected $enrichData;
    protected $environment;
    protected $loggerManager;
    protected $eventManager;
    protected $sdkKey;
    protected $accountId;
    protected $projectId;
    protected $trackingEvent;
    protected $trackingEnabled;
    protected $trackingSource;
    protected $cacheLevel;
    protected $mapper;
    

    public $batchSize;
    public $releaseInterval;

    /**
     * ApiManager constructor.
     *
     * @param array|null $config
     * @param array $dependencies {
     *     @type EventManagerInterface|null $eventManager
     *     @type LogManagerInterface|null   $loggerManager
     * }
     */
    public function __construct(?array $config = null, array $dependencies = [])
    {
        $this->loggerManager = $dependencies['loggerManager'] ?? null;
        $this->eventManager  = $dependencies['eventManager'] ?? null;
        // Endpoints: prefer config values, fallback to environment variables.
        $this->configEndpoint = $config['api']['endpoint']['config'] ?? getenv('DEFAULT_CONFIG_ENDPOINT');;
        $this->trackEndpoint  = $config['api']['endpoint']['track']  ?? getenv('TRACK_ENDPOINT');

        $this->defaultHeaders = self::DEFAULT_HEADERS;

        // Retrieve deep value for data using our helper.
        $this->data = ObjectUtils::objectDeepValue($config, 'data');

        $this->enrichData = !ObjectUtils::objectDeepValue($config, 'dataStore');
        $this->environment = $config['environment'] ?? null;
        $this->mapper = $config['mapper'] ?? function ($value) {
            return $value;
        };

        $this->batchSize = isset($config['events']['batch_size']) ? (int)$config['events']['batch_size'] : self::DEFAULT_BATCH_SIZE;
        $this->releaseInterval = isset($config['events']['release_interval']) ? (int)$config['events']['release_interval'] : self::DEFAULT_RELEASE_INTERVAL;

        $this->accountId = $this->data['account_id'] ?? null;
        $this->projectId = $this->data['project']['id'] ?? null;
        $this->sdkKey = $config['sdkKey'] ?? ($this->accountId . '/' . $this->projectId);
        if (isset($config['sdkKeySecret'])) {
            // $this->defaultHeaders['Authorization'] = 'Bearer ' . $config['sdkKeySecret'];
        }

        // Initialize tracking event object.
        $this->trackingEvent = [
            'enrichData' => $this->enrichData,
            'accountId' => $this->accountId,
            'projectId' => $this->projectId,
            'visitors' => []
        ];

        $this->trackingEnabled = $config['network']['tracking'] ?? false;
        $this->trackingSource = $config['network']['source'] ?? 'js-sdk';
        $this->cacheLevel = $config['network']['cacheLevel'] ?? null;

        // Initialize requests queue as an associative array.
        $this->requestsQueue = [
            'length' => 0,
            'items' => []
        ];
    }

    /**
     * Send a request to the API server.
     *
     * @param string $method
     * @param array  $path    Array with keys 'base' and 'route'
     * @param array  $data
     * @param array  $headers
     * @return PromiseInterface
     */
    public function request(string $method, array $path, array $data = [], array $headers = []): PromiseInterface
    {
        $requestHeaders = array_merge($this->defaultHeaders, $headers);
        $requestConfig = [
            'method' => $method,
            'path' => $path['route'],
            'baseURL' => $path['base'],
            'headers' => $requestHeaders,
            'data' => $data,
            'responseType' => 'json'
        ];
        return HttpClient::request($requestConfig);
    }

    /**
     * Enqueue a tracking event request.
     *
     * @param string $visitorId
     * @param array  $eventRequest
     * @param array|null $segments
     */
    public function enqueue(string $visitorId, array $eventRequest, ?array $segments = null): void
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('ApiManager.enqueue()', call_user_func($this->mapper, ['eventRequest' => $eventRequest]));
        }
        $this->pushQueue($visitorId, $eventRequest, $segments);
        if ($this->trackingEnabled) {
            if ($this->getQueueLength() === $this->batchSize) {
                $this->releaseQueue('size');
            } else {
                if ($this->getQueueLength() === 1) {
                    $this->startQueue();
                }
            }
        }
    }

    /**
     * Push a new event into the queue.
     *
     * @param string $visitorId
     * @param array $eventRequest
     * @param array|null $segments
     */
    protected function pushQueue(string $visitorId, array $eventRequest, ?array $segments = null): void
    {
        $found = false;
        foreach ($this->requestsQueue['items'] as &$item) {
            if ($item['visitorId'] === $visitorId) {
                $item['events'][] = $eventRequest;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $visitor = [
                'visitorId' => $visitorId,
                'events' => [$eventRequest]
            ];
            if ($segments !== null) {
                $visitor['segments'] = $segments;
            }
            $this->requestsQueue['items'][] = $visitor;
        }
        $this->requestsQueue['length']++;
    }

    /**
     * Reset the requests queue.
     */
    private function resetQueue(): void
    {
        $this->requestsQueue['items'] = [];
        $this->requestsQueue['length'] = 0;
    }

    /**
     * Get the current length of the queue.
     *
     * @return int
     */
    protected function getQueueLength(): int
    {
        return $this->requestsQueue['length'];
    }

    /**
     * Release the queue to the server.
     *
     * @param string|null $reason
     * @return PromiseInterface|null
     */
    public function releaseQueue(?string $reason = null): ?PromiseInterface
    {
        if ($this->getQueueLength() === 0) {
            return promise_for(null);
        }
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
            $this->loggerManager->info('ApiManager.releaseQueue()', Messages::RELEASING_QUEUE);
        }
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('ApiManager.releaseQueue()', ['reason' => $reason ?? '']);
        }
        $this->stopQueue();

        $payload = $this->trackingEvent;
        // Copy queue items to payload's visitors
        $payload['visitors'] = $this->requestsQueue['items'];
        $payload['source'] = $this->trackingSource;

        // Build the tracking path.
        $trackEndpoint = str_replace('[project_id]', (string)$this->projectId, $this->trackEndpoint);
        $path = [
            'base' => $trackEndpoint,
            'route' => '/track/' . $this->sdkKey
        ];

        return $this->request('post', $path, call_user_func($this->mapper, $payload))
            ->then(function ($result) use ($reason, $payload) {
                $this->resetQueue();
                if ($this->eventManager !== null && method_exists($this->eventManager, 'fire')) {
                    $this->eventManager->fire(SystemEvents::API_QUEUE_RELEASED, [
                        'reason' => $reason,
                        'result' => $result,
                        'visitors' => $payload['visitors']
                    ]);
                }
            })->otherwise(function ($error) use ($reason) {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                    $this->loggerManager->error('ApiManager.releaseQueue()', ['error' => $error->getMessage()]);
                }
                $this->startQueue();
                if ($this->eventManager !== null && method_exists($this->eventManager, 'fire')) {
                    $this->eventManager->fire(SystemEvents::API_QUEUE_RELEASED, ['reason' => $reason], $error);
                }
            });
    }

    /**
     * Stop the queue timer.
     */
    public function stopQueue(): void
    {
        if ($this->requestsQueueTimerID) {
            // Cancel timer logic – depends on your event loop or timer library.
            $this->requestsQueueTimerID = null;
        }
    }

    /**
     * Start the queue timer.
     *
     * In a production environment, integrate with a proper event loop.
     * Here, we simulate a delay using sleep.
     */
    public function startQueue(): void
    {
        $this->requestsQueueTimerID = null;
        // Blocking sleep simulation (convert milliseconds to seconds)
        sleep($this->releaseInterval / 1000);
        $this->releaseQueue('timeout');
    }

    /**
     * Enable tracking.
     */
    public function enableTracking(): void
    {
        $this->trackingEnabled = true;
        $this->releaseQueue('trackingEnabled');
    }

    /**
     * Disable tracking.
     */
    public function disableTracking(): void
    {
        $this->trackingEnabled = false;
    }

    /**
     * Set configuration data.
     *
     * @param array $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
        $this->accountId = $data['account_id'] ?? null;
        $this->projectId = $data['project']['id'] ?? null;
        $this->trackingEvent['accountId'] = $this->accountId;
        $this->trackingEvent['projectId'] = $this->projectId;
    }

    /**
     * Get configuration data from the server.
     *
     * @return PromiseInterface
     */
    public function getConfig(): PromiseInterface
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('ApiManager.getConfig()');
        }
        $query = '';
        // if ($this->cacheLevel === 'low' || $this->environment) {
        //     $query = '?';
        // }
        // if ($this->environment) {
        //     $query .= 'environment=' . $this->environment;
        // }
        if ($this->cacheLevel === 'low') {
            $query .= '_conv_low_cache=1';
        }
        $path = [
            'base' => $this->configEndpoint,
            'route' => '/config/' . $this->sdkKey . $query
        ];
        
        return $this->request('get', $path)
            ->then(function ($response) {
                return $response['data'];
            });
    }
}
