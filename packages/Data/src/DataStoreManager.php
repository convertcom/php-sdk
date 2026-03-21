<?php

declare(strict_types=1);

namespace ConvertSdk;

use ConvertSdk\Interfaces\DataStoreManagerInterface;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use OpenAPI\Client\Config;

final class DataStoreManager implements DataStoreManagerInterface
{
    private array $requestsQueue = [];
    private ?int $requestsQueueTimerID = null;
    private ?LogManagerInterface $loggerManager;
    private ?EventManagerInterface $eventManager;
    public int $batchSize;
    public int $releaseInterval;
    private mixed $dataStore = null;
    private \Closure $mapper;

    const DEFAULT_BATCH_SIZE = 1;
    const DEFAULT_RELEASE_INTERVAL = 5000; // milliseconds

    /**
     * Constructor
     *
     * @param Config|null $config Optional configuration object.
     * @param array $dependencies
     */
    public function __construct(?Config $config = null, array $dependencies = [])
    {
        $this->loggerManager = $dependencies['loggerManager'] ?? null;
        $this->eventManager = $dependencies['eventManager'] ?? null;

        // Set batch size and release interval from config if available, or use defaults.
        $this->batchSize = $config && isset($config->getEvents()['batch_size']) 
            ? (int)$config->getEvents()['batch_size'] 
            : self::DEFAULT_BATCH_SIZE;

        $this->releaseInterval = $config && isset($config->getEvents()['release_interval']) 
            ? (int)$config->getEvents()['release_interval'] 
            : self::DEFAULT_RELEASE_INTERVAL;

        // Use provided dataStore (invokes setDataStore())
        $this->setDataStore($dependencies['dataStore'] ?? $config->getDataStore());

        // Mapper callable, defaulting to identity function.
        $configMapper = ($config && method_exists($config, 'getMapper')) ? $config->getMapper() : null;
        if ($configMapper instanceof \Closure) {
            $this->mapper = $configMapper;
        } elseif ($configMapper !== null) {
            $this->mapper = \Closure::fromCallable($configMapper);
        } else {
            $this->mapper = fn($value) => $value;
        }

        $this->requestsQueue = [];
    }

    /**
     * Stores data in the dataStore.
     *
     * @param string $key
     * @param mixed  $data
     */
    public function set(string $key, mixed $data): void
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
    public function get(string $key): mixed
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
    public function enqueue(string $key, mixed $data): void
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            // Call the mapper to transform data before logging.
            $mapped = call_user_func($this->mapper, ['key' => $key, 'data' => $data]);
            $this->loggerManager->trace('DataStoreManager.enqueue()', $mapped);
        }

        $addData = [$key => $data];
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
    public function releaseQueue(?string $reason = ''): void
    {
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'info')) {
            $this->loggerManager->info('DataStoreManager.releaseQueue()', ['reason' => $reason]);
        }
        $this->stopQueue();
        foreach ($this->requestsQueue as $key => $value) {
            $this->set($key, $value);
        }
        if ($this->eventManager !== null && method_exists($this->eventManager, 'fire')) {
            $this->eventManager->fire(SystemEvents::DataStoreQueueReleased, ['reason' => $reason]);
        }
        // Clear the queue after releasing.
        $this->requestsQueue = [];
    }

    /**
     * Stops the current queue timer.
     */
    public function stopQueue(): void
    {
        $this->requestsQueueTimerID = null;
    }

    /**
     * Starts a timer that will release the queue after the release interval.
     *
     * Note: This implementation uses a blocking sleep() call.
     * In a real asynchronous environment, you might use an event loop.
     */
    public function startQueue(): void
    {
        $seconds = $this->releaseInterval / 1000;
        sleep($seconds);
        $this->releaseQueue('timeout');
    }

    /**
     * Sets the dataStore.
     *
     * @param mixed $dataStore
     */
    public function setDataStore(mixed $dataStore): void
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
    public function getDataStore(): mixed
    {
        return $this->dataStore;
    }

    /**
     * Validates that the provided dataStore has both get and set methods.
     *
     * @param mixed $dataStore
     * @return bool
     */
    public function isValidDataStore(mixed $dataStore): bool
    {
        return is_object($dataStore) &&
               method_exists($dataStore, 'get') &&
               method_exists($dataStore, 'set');
    }
}
