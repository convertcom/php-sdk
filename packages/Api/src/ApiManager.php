<?php
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Promise;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Logger\Interfaces\LogManagerInterface;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Utils\HttpClient;
use ConvertSdk\Utils\ObjectUtils;

class ApiManager implements ApiManagerInterface
{
    /**
     * @var object Requests queue with two properties: length and items.
     */
    private $_requestsQueue;

    /**
     * @var mixed Timer identifier for queue release (simulation).
     */
    private $_requestsQueueTimerID;

    /**
     * @var string Configuration endpoint.
     */
    private $_configEndpoint;

    /**
     * @var string Tracking endpoint.
     */
    private $_trackEndpoint;

    /**
     * @var array Default headers for requests.
     */
    private $_defaultHeaders = [
        'Content-Type' => 'application/json'
    ];

    /**
     * @var array|null Configuration response data.
     */
    private $_data;

    /**
     * @var bool Whether to enrich data.
     */
    private $_enrichData;

    /**
     * @var string|null Environment string.
     */
    private $_environment;

    /**
     * @var LogManagerInterface|null Logger manager.
     */
    private $_loggerManager;

    /**
     * @var EventManagerInterface|null Event manager.
     */
    private $_eventManager;

    /**
     * @var string SDK key.
     */
    private $_sdkKey;

    /**
     * @var string|null Account ID.
     */
    private $_accountId;

    /**
     * @var string|null Project ID.
     */
    private $_projectId;

    /**
     * @var array Tracking event payload.
     */
    private $_trackingEvent;

    /**
     * @var bool Whether tracking is enabled.
     */
    private $_trackingEnabled;

    /**
     * @var string Tracking source.
     */
    private $_trackingSource;

    /**
     * @var string|null Cache level.
     */
    private $_cacheLevel;

    /**
     * @var callable Mapper function.
     */
    private $_mapper;

    /**
     * @var int Batch size for events.
     */
    public $batchSize;

    /**
     * @var int Release interval (milliseconds).
     */
    public $releaseInterval;

    /**
     * Constructor.
     *
     * @param array|null $config Optional configuration array.
     * @param array $dependencies Optional dependencies array.
     *        Expected keys: 'eventManager' (EventManagerInterface), 'loggerManager' (LogManagerInterface)
     */
    public function __construct(?array $config = [], array $dependencies = [])
    {
        // Set dependencies.
        $this->_loggerManager = $dependencies['loggerManager'] ?? null;
        $this->_eventManager  = $dependencies['eventManager'] ?? null;

        // Define defaults (using environment variables if available).
        $defaultConfigEndpoint = getenv('CONFIG_ENDPOINT') ?: '';
        $defaultTrackEndpoint  = getenv('TRACK_ENDPOINT') ?: '';

        $this->_configEndpoint = isset($config['api']['endpoint']['config']) ? $config['api']['endpoint']['config'] : $defaultConfigEndpoint;
        $this->_trackEndpoint  = isset($config['api']['endpoint']['track']) ? $config['api']['endpoint']['track'] : $defaultTrackEndpoint;

        // Load configuration data.
        $this->_data = ObjectUtils::objectDeepValue($config, 'data');
        $this->_enrichData = !ObjectUtils::objectDeepValue($config, 'dataStore');
        $this->_environment = $config['environment'] ?? null;
        $this->_mapper = isset($config['mapper']) && is_callable($config['mapper'])
            ? $config['mapper']
            : function ($value) { return $value; };

        // Batch size and release interval.
        $this->batchSize = isset($config['events']['batch_size']) ? (int)$config['events']['batch_size'] : 10;
        $this->releaseInterval = isset($config['events']['release_interval']) ? (int)$config['events']['release_interval'] : 10000;

        // Set account, project, sdk key.
        $this->_accountId = $this->_data['account_id'] ?? null;
        $this->_projectId = $this->_data['project']['id'] ?? null;
        $this->_sdkKey = $config['sdkKey'] ?? ($this->_accountId . '/' . $this->_projectId);
        if (isset($config['sdkKeySecret'])) {
            $this->_defaultHeaders['Authorization'] = 'Bearer ' . $config['sdkKeySecret'];
        }
        $this->_trackingEvent = [
            'enrichData' => $this->_enrichData,
            'accountId' => $this->_accountId,
            'projectId' => $this->_projectId,
            'visitors' => []
        ];
        $this->_trackingEnabled = $config['network']['tracking'] ?? false;
        $this->_trackingSource = $config['network']['source'] ?? 'js-sdk';
        $this->_cacheLevel = $config['network']['cacheLevel'] ?? null;

        // Initialize requests queue as an object with items and length.
        $this->_requestsQueue = new \stdClass();
        $this->_requestsQueue->length = 0;
        $this->_requestsQueue->items = [];

        // We'll implement a helper method below for pushing and resetting the queue.
    }

    /**
     * Helper: Push a new visitor event to the queue.
     *
     * @param string $visitorId
     * @param mixed $eventRequest
     * @param mixed $segments
     * @return void
     */
    private function queuePush(string $visitorId, $eventRequest, $segments = null): void
    {
        // Check if a visitor with this visitorId already exists.
        if (!isset($this->_requestsQueue->items[$visitorId])) {
            // If not, create a new entry for this visitor.
            $this->_requestsQueue->items[$visitorId] = [
                'visitorId' => $visitorId,
                'events'    => []
            ];
            if ($segments !== null) {
                $this->_requestsQueue->items[$visitorId]['segments'] = $segments;
            }
        }
        // Append the event request to the visitor's events.
        $this->_requestsQueue->items[$visitorId]['events'][] = $eventRequest;
        // Optionally, update length as the count of visitors (or recalc total events if needed)
        $this->_requestsQueue->length = count($this->_requestsQueue->items);
    }

    /**
     * Helper: Reset the requests queue.
     *
     * @return void
     */
    private function queueReset(): void
    {
        $this->_requestsQueue->items = [];
        $this->_requestsQueue->length = 0;
    }

    /**
     * Send request to API server.
     *
     * @param string $method HTTP method (e.g. 'GET', 'POST')
     * @param mixed $path An array with keys 'base' and 'route'
     * @param array $data Request data.
     * @param array $headers Request headers.
     * @return PromiseInterface
     */
    public function request(string $method, $path, array $data = [], array $headers = []): PromiseInterface
    {
        $requestHeaders = array_merge($this->_defaultHeaders, $headers);
        $requestConfig = [
            'method' => $method, // e.g., 'GET'
            'path'   => $path['route'] ?? '',
            'baseURL'=> $path['base'] ?? '',
            'headers'=> $requestHeaders,
            'data'   => $data,
            'responseType' => 'json'
        ];
        return HttpClient::request($requestConfig);
    }

    /**
     * Add a request to the queue.
     *
     * @param string $visitorId
     * @param mixed $eventRequest (VisitorTrackingEvents)
     * @param mixed|null $segments (VisitorSegments)
     * @return void
     */
    public function enqueue(string $visitorId, $eventRequest, $segments = null): void
    {
        if ($this->_loggerManager !== null && method_exists($this->_loggerManager, 'trace')) {
            $this->_loggerManager->trace('ApiManager.enqueue()', call_user_func($this->_mapper, [
                'eventRequest' => $eventRequest
            ]));
        }
        $this->queuePush($visitorId, $eventRequest, $segments);
        if ($this->_trackingEnabled) {
            if ($this->_requestsQueue->length === $this->batchSize) {
                $this->releaseQueue('size');
            } else {
                if ($this->_requestsQueue->length === 1) {
                    $this->startQueue();
                }
            }
        }
    }

    /**
     * Release the queue to the server.
     *
     * @param string|null $reason Optional reason.
     * @return PromiseInterface
     */
    public function releaseQueue(string $reason = null): PromiseInterface
    {
        if (!$this->_requestsQueue->length) {
            return new \GuzzleHttp\Promise\FulfilledPromise(null);
        }
        if ($this->_loggerManager !== null && method_exists($this->_loggerManager, 'info')) {
            $this->_loggerManager->info('ApiManager.releaseQueue()', \ConvertSdk\Enums\Messages::RELEASING_QUEUE);
        }
        if ($this->_loggerManager !== null && method_exists($this->_loggerManager, 'trace')) {
            $this->_loggerManager->trace('ApiManager.releaseQueue()', ['reason' => $reason ?? '']);
        }
        $this->stopQueue();
        $payload = $this->_trackingEvent;
        // Clone visitors from queue.
        $payload['visitors'] = $this->_requestsQueue->items;
        $payload['source'] = $this->_trackingSource;
        // Build URL by replacing [project_id] placeholder.
        $base = str_replace('[project_id]', strval($this->_projectId), $this->_trackEndpoint);
        $path = [
            'base' => $base,
            'route' => "/track/{$this->_sdkKey}"
        ];
        // Return a promise from the request.
        return $this->request('post', $path, call_user_func($this->_mapper, $payload))
            ->then(function ($result) use ($reason) {
                $this->queueReset();
                if ($this->_eventManager !== null && method_exists($this->_eventManager, 'fire')) {
                    $this->_eventManager->fire(SystemEvents::API_QUEUE_RELEASED, [
                        'reason' => $reason,
                        'result' => $result,
                        'visitors' => $this->_trackingEvent['visitors'] ?? []
                    ]);
                }
            })
            ->otherwise(function ($error) use ($reason) {
                if ($this->_loggerManager !== null && method_exists($this->_loggerManager, 'error')) {
                    $this->_loggerManager->error('ApiManager.releaseQueue()', ['error' => $error->getMessage()]);
                }
                $this->startQueue();
                if ($this->_eventManager !== null && method_exists($this->_eventManager, 'fire')) {
                    $this->_eventManager->fire(SystemEvents::API_QUEUE_RELEASED, ['reason' => $reason], $error);
                }
            });
    }

    /**
     * Stop the queue timer.
     *
     * @return void
     */
    public function stopQueue(): void
    {
        // In PHP we don't have clearTimeout; simply set timer id to null.
        $this->_requestsQueueTimerID = null;
    }

    /**
     * Start the queue timer.
     *
     * @return void
     */
    public function startQueue(): void
    {
        // Simulate asynchronous behavior: we create a promise that waits.
        $this->_requestsQueueTimerID = true; // just a flag
        // Using a promise to simulate delay.
        (new Promise(function () use (&$promise) {
            sleep($this->releaseInterval / 1000); // convert ms to seconds
            $this->releaseQueue('timeout')->then(function () use (&$promise) {
                $promise->resolve(null);
            });
        }))->wait();
    }

    /**
     * Enable tracking.
     *
     * @return void
     */
    public function enableTracking(): void
    {
        $this->_trackingEnabled = true;
        $this->releaseQueue('trackingEnabled');
    }

    /**
     * Disable tracking.
     *
     * @return void
     */
    public function disableTracking(): void
    {
        $this->_trackingEnabled = false;
    }

    /**
     * Set data.
     *
     * @param array $data ConfigResponseData.
     * @return void
     */
    public function setData($data): void
    {
        $this->_data = $data;
        $this->_accountId = $data['account_id'] ?? null;
        $this->_projectId = $data['project']['id'] ?? null;
        $this->_trackingEvent['accountId'] = $this->_accountId;
        $this->_trackingEvent['projectId'] = $this->_projectId;
    }

    /**
     * Get configuration data.
     *
     * @return PromiseInterface Promise resolving to ConfigResponseData.
     */
    public function getConfig(): PromiseInterface
    {
        if ($this->_loggerManager !== null && method_exists($this->_loggerManager, 'trace')) {
            $this->_loggerManager->trace('ApiManager.getConfig()');
        }
        $query = ($this->_cacheLevel === 'low' || $this->_environment) ? '?' : '';
        if ($this->_environment) {
            $query .= "environment=" . $this->_environment;
        }
        if ($this->_cacheLevel === 'low') {
            $query .= "_conv_low_cache=1";
        }
        $promise = new Promise(function () use (&$promise, $query) {
            $this->request('get', [
                'base' => $this->_configEndpoint,
                'route' => "/config/{$this->_sdkKey}{$query}"
            ])->then(function ($response) use (&$promise) {
                $promise->resolve($response['data']);
            })->catch(function ($error) use (&$promise) {
                $promise->reject($error);
            });
        });
        return $promise;
    }
}