<?php
namespace ConvertSdk;

use GuzzleHttp\Promise\PromiseInterface;
use ConvertSdk\Api\ApiManager;
use ConvertSdk\Data\DataManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Logger\LogManager;
use ConvertSdk\Config\Config;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\Messages;

class ConvertSDK extends Core {
    public $dataManager;
    public $apiManager;
    public $loggerManager;

    public function __construct(array $config = []) {
        if (empty($config['sdkKey']) && empty($config['data'])) {
            error_log(ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED);
        }
        
        $configuration = Config::create($config);
        if (!isset($configuration['network']['source'])) {
            $configuration['network']['source'] = getenv('VERSION') ?: 'php-sdk';
        }

        $this->loggerManager = new LogManager(null, $configuration['logger']['logLevel'], $configuration['logger']['customLoggers'] ?? []);        
        $this->eventManager = new EventManager($configuration, ['loggerManager' => $this->loggerManager]);
        $this->apiManager = new ApiManager($configuration, [
            'eventManager' => $this->eventManager,
            'loggerManager' => $this->loggerManager
        ]);
        $this->dataManager = new DataManager($configuration, [
            'apiManager' => $this->apiManager,
            'loggerManager' => $this->loggerManager
        ]);

        parent::__construct($configuration, [
            'dataManager'   => $this->dataManager,
            'eventManager'  => $this->eventManager,
            'apiManager'    => $this->apiManager,
            'loggerManager' => $this->loggerManager
        ]);
    }

    /**
     * Promisified ready event using Guzzle promises.
     *
     * @return PromiseInterface
     */
    public function onReady(): PromiseInterface {
        return parent::onReady();
    }
}
