<?php

namespace ConvertSdk;

use GuzzleHttp\Promise\PromiseInterface;
use ConvertSdk\Api\ApiManager;
use ConvertSdk\Data\DataManager;
use ConvertSdk\Bucketing\BucketingManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Logger\LogManager;
use ConvertSdk\Config\Config;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Experience\ExperienceManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class ConvertSDK extends Core {
    public $dataManager;
    public $apiManager;
    public $loggerManager;
    public $experienceManager; // Declare ExperienceManager

    public function __construct(array $config = []) {
        if (empty($config['sdkKey']) && empty($config['data'])) {
            error_log(ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED);
        }

        // Load configuration
        $configuration = Config::create($config);
        if (!isset($configuration['network']['source'])) {
            $configuration['network']['source'] = getenv('VERSION') ?: 'php-sdk';
        }

        // Create a Monolog logger instance.
        $monolog = new Logger('convert');
        // Configure Monolog to log to STDOUT at DEBUG level.
        $monolog->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

        // Initialize LogManager with the Monolog logger and provided log level.
        $this->loggerManager = new LogManager($monolog, $configuration['logger']['logLevel']);

        // Iterate over custom loggers (if any) and add them to LogManager.
        if (isset($configuration['logger']['customLoggers']) && is_array($configuration['logger']['customLoggers'])) {
            foreach ($configuration['logger']['customLoggers'] as $customLogger) {
                if (isset($customLogger['logger']) && isset($customLogger['logLevel'])) {
                    $this->loggerManager->addClient(
                        $customLogger['logger'],
                        $customLogger['logLevel'],
                        $customLogger['methodsMap'] ?? []
                    );
                } else {
                    $this->loggerManager->addClient(
                        $customLogger,
                        $configuration['logger']['logLevel']
                    );
                }
            }
        }

        // Initialize EventManager
        $this->eventManager = new EventManager($configuration, ['loggerManager' => $this->loggerManager]);

        // Initialize ApiManager
        $this->apiManager = new ApiManager($configuration, [
            'eventManager' => $this->eventManager,
            'loggerManager' => $this->loggerManager
        ]);

        // Initialize BucketingManager
        $this->bucketingManager = new BucketingManager($configuration, [
            'loggerManager' => $this->loggerManager
        ]);

        // Initialize DataManager
        $this->dataManager = new DataManager($configuration, [
            'apiManager' => $this->apiManager,
            'loggerManager' => $this->loggerManager
        ]);

        // Initialize ExperienceManager
        $this->experienceManager = new ExperienceManager($configuration, [
            'dataManager' => $this->dataManager,
            'loggerManager' => $this->loggerManager
        ]);

        // Call parent constructor
        parent::__construct($configuration, [
            'dataManager'       => $this->dataManager,
            'eventManager'      => $this->eventManager,
            'apiManager'        => $this->apiManager,
            'loggerManager'     => $this->loggerManager,
            'experienceManager' => $this->experienceManager // Add ExperienceManager to parent
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
