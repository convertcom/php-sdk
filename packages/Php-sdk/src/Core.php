<?php
namespace ConvertSdk;

use GuzzleHttp\Promise\PromiseInterface;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Interfaces\CoreInterface;

class Core implements CoreInterface
{
    protected $dataManager;       // DataManagerInterface
    protected $eventManager;      // EventManagerInterface
    protected $loggerManager;     // ?LogManagerInterface
    protected $apiManager;        // ApiManagerInterface
    protected $config;            // Array holding configuration settings
    protected $promise;           // PromiseInterface resolving with config response data
    protected $fetchConfigTimerID; // Timer identifier (if using an event loop)
    protected $environment;       // string
    protected $initialized;       // bool

    const DEFAULT_DATA_REFRESH_INTERVAL = 300000; // 5 minutes in milliseconds

    /**
     * Core constructor.
     *
     * @param array $config Configuration array.
     * @param array $dependencies {
     *      @type DataManagerInterface       $dataManager
     *      @type EventManagerInterface      $eventManager
     *      @type ApiManagerInterface        $apiManager
     *      @type LogManagerInterface|null   $loggerManager
     * }
     */
    public function __construct(array $config, array $dependencies)

    {
        $this->initialized   = false;
        $this->environment   = $config['environment'] ?? '';
        $this->dataManager   = $dependencies['dataManager'];
        $this->eventManager  = $dependencies['eventManager'];
        $this->apiManager        = $dependencies['apiManager'];
        $this->loggerManager     = $dependencies['loggerManager'] ?? null;

        // Log constructor call if a logger is available.
        if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
            $this->loggerManager->trace('Core()', Messages::CORE_CONSTRUCTOR, $this);
        }
        $this->initialize($config);
    }

    /**
     * Initialize credentials, configuration data, etc.
     *
     * @param array $config
     */
    protected function initialize(array $config): void
    {
        if (empty($config)) {
            return;
        }
        $this->config = $config;

        if (isset($config['sdkKey']) && !empty($config['sdkKey'])) {

            // If an SDK key is provided, fetch remote configuration.
            $this->fetchConfig();
        } elseif (isset($config['data'])) {
            // If static configuration data is provided, use it directly.
            $this->dataManager->setData($config['data']);
            if (isset($config['data']['error'])) {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                    $this->loggerManager->error('Core.initialize()', ['error' => $config['data']['error']]);
                }
            } else {
                $this->eventManager->fire(SystemEvents::READY, null, null, true);
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
                    $this->loggerManager->trace('Core.initialize()', Messages::CORE_INITIALIZED);
                }
                $this->initialized = true;
            }
        } else {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                $this->loggerManager->error('Core.initialize()', ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED);
            }
            $this->eventManager->fire(SystemEvents::READY, [], new \Exception(ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED), true);
        }
    }

    /**
     * Create visitor context.
     *
     * @param string $visitorId A visitor ID.
     * @param array|null $visitorAttributes Optional attributes for audience/segments targeting.
     * @return Context|null Returns a new Context or null if not initialized.
     */
    public function createContext(string $visitorId, ?array $visitorAttributes = null)
    {
        if (!$this->initialized) {
            return null;
        }
        return new Context(
            $this->config,
            $visitorId,
            [
                'eventManager'      => $this->eventManager,
                // 'experienceManager' => $this->experienceManager,
                // 'featureManager'    => $this->featureManager,
                // 'segmentsManager'   => $this->segmentsManager,
                'apiManager'        => $this->apiManager,
                'dataManager'       => $this->dataManager,
                'loggerManager'     => $this->loggerManager
            ],
            $visitorAttributes
        );
    }

    /**
     * Attach an event handler.
     *
     * @param string $event Event name.
     * @param callable $fn Callback function to fire.
     */
    public function on($event, callable $fn): void
    {
        $this->eventManager->on($event, $fn);
    }

    /**
     * Promisified ready event.
     *
     * Returns a promise that resolves when configuration data is available.
     *
     * @return PromiseInterface
     */
    public function onReady(): PromiseInterface
    {
        return $this->promise->then(function ($data) {
            if (ObjectUtils::objectNotEmpty($this->dataManager->getData())) {
                return null; // resolved successfully.
            } else {
                throw new \Exception(ErrorMessages::DATA_OBJECT_MISSING);
            }
        });
    }

    /**
     * Fetch remote configuration data.
     *
     * This method sets $this->promise to the promise returned by the API manager's getConfig().
     * Once the promise resolves, it processes the data, fires events, and updates internal state.
     *
     * Note: Periodic refresh is simulated here. In production, use an event loop or scheduler.
     */
    protected function fetchConfig(): void
    {
        // Assume that getConfig() returns a PromiseInterface.
        $this->promise = $this->apiManager->getConfig();
        $this->promise->then(function ($data) {
            if (isset($data['error'])) {
                $this->dataManager->setData($data);
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                    $this->loggerManager->error('Core.fetchConfig()', ['error' => $data['error']]);
                }
            } else {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
                    $this->loggerManager->trace('Core.fetchConfig()', ['data' => $data]);
                }
                $event = ObjectUtils::objectNotEmpty($this->dataManager->getData())
                    ? SystemEvents::CONFIG_UPDATED
                    : SystemEvents::READY;
                $this->eventManager->fire($event, null, null, true);
                if (ObjectUtils::objectNotEmpty($this->dataManager->getData())) {
                    if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
                        $this->loggerManager->trace('Core.fetchConfig()', Messages::CONFIG_DATA_UPDATED);
                    }
                } else {
                    if ($this->loggerManager !== null && method_exists($this->loggerManager, 'trace')) {
                        $this->loggerManager->trace('Core.fetchConfig()', Messages::CORE_INITIALIZED);
                    }
                    $this->initialized = true;
                }
                $this->dataManager->setData($data);
                $this->apiManager->setData($data);
            }

            // Clear previous timer if set.
            if ($this->fetchConfigTimerID) {
                // In PHP, you’d cancel the timer via your event loop or scheduler.
                // For this example, we simply reset the timer ID.
                $this->fetchConfigTimerID = null;
            }

            // Schedule the next fetch.
            // Note: Blocking sleep is used here for demonstration only.
            $interval = $this->config['dataRefreshInterval'] ?? self::DEFAULT_DATA_REFRESH_INTERVAL;
            // In a non-blocking environment, integrate with an event loop here.
            // For example:
            // sleep($interval / 1000);
            // $this->fetchConfig();

        })->otherwise(function ($error) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                $this->loggerManager->error('Core.fetchConfig()', ['error' => $error->getMessage()]);
            }
        });
    }
}
