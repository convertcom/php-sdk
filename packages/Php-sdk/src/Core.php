<?php
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
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Promise;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Context;

/**
 * Core
 * @category Main
 * @implements CoreInterface
 */
class Core implements CoreInterface
{
    /** Default data refresh interval in milliseconds (5 minutes) */
    const DEFAULT_DATA_REFRESH_INTERVAL = 300000;

    /** @var DataManagerInterface */
    private $_dataManager;

    /** @var EventManagerInterface */
    private $_eventManager;

    /** @var ExperienceManagerInterface */
    private $_experienceManager;

    /** @var FeatureManagerInterface */
    private $_featureManager;

    /** @var SegmentsManagerInterface */
    private $_segmentsManager;

    /** @var ApiManagerInterface */
    private $_apiManager;

    /** @var LogManagerInterface|null */
    private $_loggerManager;

    /** @var Config */
    private $_config;

    /** @var string|null */
    private $_environment;

    /** @var bool */
    private $_initialized = false;

    /**
     * Constructor
     *
     * @param Config $config Configuration object
     * @param array $dependencies Dependencies array
     * @param DataManagerInterface $dependencies['dataManager'] Data manager instance
     * @param EventManagerInterface $dependencies['eventManager'] Event manager instance
     * @param ExperienceManagerInterface $dependencies['experienceManager'] Experience manager instance
     * @param FeatureManagerInterface $dependencies['featureManager'] Feature manager instance
     * @param SegmentsManagerInterface $dependencies['segmentsManager'] Segments manager instance
     * @param ApiManagerInterface $dependencies['apiManager'] API manager instance
     * @param LogManagerInterface|null $dependencies['loggerManager'] Optional logger manager instance
     */
    public function __construct(Config $config, array $dependencies)
    {
        $this->_dataManager = $dependencies['dataManager'];
        $this->_eventManager = $dependencies['eventManager'];
        $this->_experienceManager = $dependencies['experienceManager'];
        $this->_featureManager = $dependencies['featureManager'];
        $this->_segmentsManager = $dependencies['segmentsManager'];
        $this->_apiManager = $dependencies['apiManager'];
        $this->_loggerManager = $dependencies['loggerManager'] ?? null;
        $this->_environment = $config->getEnvironment() ?? null;
        $this->initialize($config);
    }

    /**
     * Initialize credentials, configData etc..
     *
     * @param Config $config Configuration object
     * @return void
     */
    private function initialize(Config $config): void
    {
        if (!$config) {
            return;
        }
        $this->_config = $config;
        if ($config->getSdkKey() && strlen($config->getSdkKey()) > 0) {
            $this->fetchConfig()->then(
                function () {
                    $this->_eventManager->fire(SystemEvents::READY, null, null, true);
                    $this->_loggerManager?->trace('Core.initialize()', Messages::CORE_INITIALIZED);
                    $this->_initialized = true;
                },
                function (\Exception $e) {
                    $this->_loggerManager?->error('Core.initialize()', ['error' => $e->getMessage()]);
                    $this->_eventManager->fire(
                        SystemEvents::READY,
                        [],
                        $e,
                        true
                    );
                }
            );
        } elseif ($config->getData()) {
            $this->_dataManager->setConfigData($config->getData());
            $configData = $this->_dataManager->getConfigData();
            if (!$configData->getAccountId() || !$configData->getProject()) {
                $this->_loggerManager?->error('Core.initialize()', ['error' => 'Invalid configuration data: missing account_id or project']);
                $this->_eventManager->fire(
                    SystemEvents::READY,
                    [],
                    new \Exception('Invalid configuration data: missing account_id or project'),
                    true
                );
            } else {
                $this->_eventManager->fire(SystemEvents::READY, null, null, true);
                $this->_loggerManager?->trace('Core.initialize()', Messages::CORE_INITIALIZED);
                $this->_initialized = true;
            }
        } else {
            $this->_loggerManager?->error('Core.initialize()', ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED);
            $this->_eventManager->fire(
                SystemEvents::READY,
                [],
                new \Exception(ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED),
                true
            );
        }
    }
    /**
     * Create visitor context
     *
     * @param string $visitorId A visitor ID
     * @param array|null $visitorAttributes An object of key-value pairs for audience/segments targeting
     * @return ContextInterface|null
     */
    public function createContext(string $visitorId, ?array $visitorAttributes = null): ?ContextInterface
    {
        if (!$this->_initialized) {
            return null;
        }
        return new Context(
            $this->_config,
            $visitorId,
            [
                'eventManager' => $this->_eventManager,
                'experienceManager' => $this->_experienceManager,
                'featureManager' => $this->_featureManager,
                'segmentsManager' => $this->_segmentsManager,
                'apiManager' => $this->_apiManager,
                'dataManager' => $this->_dataManager,
                'loggerManager' => $this->_loggerManager
            ],
            $visitorAttributes
        );
    }

    /**
     * Add event handler to event
     *
     * @param string $event Event name (SystemEvents)
     * @param callable $fn A callback function which will be fired
     * @return void
     */
    public function on(string $event, callable $fn): void
    {
        $this->_eventManager->on($event, $fn);
    }

    /**
     * Check if the system is ready
     *
     * @return PromiseInterface
     */
    public function onReady(): PromiseInterface
    {
        $promise = new Promise(function ($resolve, $reject) {
            $configData = $this->_dataManager->getConfigData();
            if ($this->_initialized && $configData->getAccountId() && $configData->getProject()) {
                $resolve();
            } else {
                $reject(new \Exception(ErrorMessages::DATA_OBJECT_MISSING));
            }
        });

        return $promise;
    }

   /**
     * Fetch remote config data
     *
     * @return PromiseInterface
     */
    public function fetchConfig(): PromiseInterface
    {
        return $this->_apiManager->getConfig()->then(
            function (ConfigResponseData $data) {
                $this->_dataManager->setConfigData($data);
                $configData = $this->_dataManager->getConfigData();

                if (!$configData->getAccountId() || !$configData->getProject()) {
                    $this->_loggerManager?->error('Core.fetchConfig()', ['error' => 'Invalid configuration data: missing account_id or project']);
                    throw new \Exception('Invalid configuration data: missing account_id or project');
                }

                $this->_loggerManager?->trace('Core.fetchConfig()', ['data' => $data]);
                $event = ($configData->getAccountId() && $configData->getProject()) ? SystemEvents::CONFIG_UPDATED : SystemEvents::READY;
                $this->_eventManager->fire($event, null, null, true);
                $this->_apiManager->setData($data);

                if ($configData->getAccountId() && $configData->getProject()) {
                    $this->_loggerManager?->trace('Core.fetchConfig()', Messages::CONFIG_DATA_UPDATED);
                } else {
                    $this->_loggerManager?->trace('Core.fetchConfig()', Messages::CORE_INITIALIZED);
                    $this->_initialized = true;
                }
                // Note: Periodic refresh omitted, as PHP doesn't support long-running timers.
                // Consider implementing via cron job or external scheduler if needed.
            },
            function ($error) {
                $this->_loggerManager?->error('Core.fetchConfig()', ['error' => $error->getMessage()]);
                throw $error;
            }
        );
    }
}