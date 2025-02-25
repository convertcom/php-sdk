<?php
namespace ConvertSdk\Data;

use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Enums\ErrorMessages;

class DataStoreManager
{
    /**
     * @var array
     */
    private $requestsQueue = [];

    /**
     * In a PHP environment, timer IDs are not common; we store it for completeness.
     * @var mixed
     */
    private $requestsQueueTimerID;

    /**
     * @var LogManagerInterface|null
     */
    private $loggerManager;

    /**
     * @var EventManagerInterface|null
     */
    private $eventManager;

    /**
     * Batch size for releasing queue.
     * @var int
     */
    public $batchSize;

    /**
     * Release interval in milliseconds.
     * @var int
     */
    public $releaseInterval;

    /**
     * @var mixed
     */
    private $dataStore;

    /**
     * A callable to map values.
     * @var callable
     */
    private $mapper;

    const DEFAULT_BATCH_SIZE = 1;
    const DEFAULT_RELEASE_INTERVAL = 5000; // milliseconds

    /**
     * Constructor.
     *
     * @param array|null $config
     * @param array $dependencies Associative array with optional keys:
     *                            'dataStore', 'eventManager', 'loggerManager'
     */
    public function __construct($config = null, $dependencies = [])
    {
        $this->loggerManager = $dependencies['loggerManager'] ?? null;
        $this->eventManager = $dependencies['eventManager'] ?? null;

        // Set batch size and release interval from config if available, or use defaults.
        $this->batchSize = isset($config['events']['batch_size'])
            ? (int)$config['events']['batch_size']
            : self::DEFAULT_BATCH_SIZE;

        $this->releaseInterval = isset($config['events']['release_interval'])
            ? (int)$config['events']['release_interval']
            : self::DEFAULT_RELEASE_INTERVAL;

        // Use provided dataStore (invokes setDataStore())
        $this->setDataStore($dependencies['dataStore'] ?? null);

        // Mapper callable, defaulting to identity function.
        $this->mapper = $config['mapper'] ?? function ($value) {
            return $value;
        };

        $this->requestsQueue = [];
    }

    /**
     * Stores data in the dataStore.
     *
     * @param string $key
     * @param mixed  $data
     */
    public function set($key, $data)
    {
        try {
            if ($this->dataStore !== null && method_exists($this->dataStore, 'set')) {
                $this->dataStore->set($key, $data);
            }
        } catch (\Exception $error) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                $this->loggerManager->error('DataStoreManager.set()', ['error' => $error->getMessage()]);
            }
        }
    }

    /**
     * Retrieves data from the dataStore.
     *
     * @param string $key
     * @return mixed|null
     */
    public function get($key)
    {
        try {
            if ($this->dataStore !== null && method_exists($this->dataStore, 'get')) {
                return $this->dataStore->get($key);
            }
        } catch (\Exception $error) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                $this->loggerManager->error('DataStoreManager.get()', ['error' => $error->getMessage()]);
            }
        }
        return null;
    }

    /**
     * Enqueues data and releases the queue if batch size is reached,
     * otherwise starts a timer if this is the first item.
     *
     * @param string $key
     * @param mixed  $data
     */
    public function enqueue($key, $data)
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            // Call the mapper to transform data before logging.
            $mapped = call_user_func($this->mapper, ['key' => $key, 'data' => $data]);
            $this->loggerManager->trace('DataStoreManager.enqueue()', $mapped);
        }

        $addData = [$key => $data];
        // Use a deep merge function (assumed available)
        $this->requestsQueue = ObjectUtils::objectDeepMerge($this->requestsQueue, $addData);
        $queueLength = count($this->requestsQueue);

        if ($queueLength >= $this->batchSize) {
            $this->releaseQueue('size');
        } else {
            if ($queueLength === 1) {
                $this->startQueue();
            }
        }
    }

    /**
     * Releases all enqueued data.
     *
     * @param string $reason Optional reason for releasing the queue.
     */
    public function releaseQueue($reason = '')
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
            $this->loggerManager->info('DataStoreManager.releaseQueue()', ['reason' => $reason]);
        }
        $this->stopQueue();
        foreach ($this->requestsQueue as $key => $value) {
            $this->set($key, $value);
        }
        if ($this->eventManager !== null && method_exists($this->eventManager, 'fire')) {
            $this->eventManager->fire(SystemEvents::DATA_STORE_QUEUE_RELEASED, ['reason' => $reason]);
        }
        // Clear the queue after releasing.
        $this->requestsQueue = [];
    }

    /**
     * Stops the current queue timer.
     */
    public function stopQueue()
    {
        // In PHP, there's no direct equivalent to clearTimeout.
        // If using a timer mechanism, implement cancellation logic here.
        $this->requestsQueueTimerID = null;
    }

    /**
     * Starts a timer that will release the queue after the release interval.
     *
     * Note: This implementation uses a blocking sleep() call.
     * In a real asynchronous environment, you might use an event loop.
     */
    public function startQueue()
    {
        // Convert milliseconds to seconds.
        $seconds = $this->releaseInterval / 1000;
        // Blocking sleep (this is a simulation; adjust as needed).
        sleep($seconds);
        $this->releaseQueue('timeout');
    }

    /**
     * Sets the dataStore.
     *
     * @param mixed $dataStore
     */
    public function setDataStore($dataStore)
    {
        if ($dataStore) {
            if ($this->isValidDataStore($dataStore)) {
                $this->dataStore = $dataStore;
            } else {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                    $this->loggerManager->error(
                        'DataStoreManager.dataStore.set()',
                        ErrorMessages::DATA_STORE_NOT_VALID
                    );
                }
            }
        }
    }

    /**
     * Gets the dataStore.
     *
     * @return mixed
     */
    public function getDataStore()
    {
        return $this->dataStore;
    }

    /**
     * Validates that the provided dataStore has both get and set methods.
     *
     * @param mixed $dataStore
     * @return bool
     */
    public function isValidDataStore($dataStore)
    {
        return is_object($dataStore) &&
               method_exists($dataStore, 'get') &&
               method_exists($dataStore, 'set');
    }
}
