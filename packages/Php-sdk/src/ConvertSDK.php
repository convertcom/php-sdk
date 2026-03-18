<?php

declare(strict_types=1);

namespace ConvertSdk;

use GuzzleHttp\Promise\PromiseInterface;
use ConvertSdk\ApiManager;
use ConvertSdk\DataManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\EventManager;
use ConvertSdk\LogManager;
use ConvertSdk\Config\Config;
use OpenAPI\Client\Config as OpenApiConfig;
use OpenAPI\Client\Model\ConfigResponseData;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Enums\Messages;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\RuleManager;
use ConvertSdk\SegmentsManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class ConvertSDK extends Core {
    public $dataManager;
    public $apiManager;
    public $loggerManager;
    public $experienceManager;
    public $featureManager;
    public $segmentsManager;

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
        $this->loggerManager = new LogManager($monolog, LogLevel::Warn);

        // Iterate over custom loggers (if any) and add them to LogManager.
        if (isset($configuration['logger']['customLoggers']) && is_array($configuration['logger']['customLoggers'])) {
            foreach ($configuration['logger']['customLoggers'] as $customLogger) {
                if (isset($customLogger['logger']) && isset($customLogger['logLevel'])) {
                    $level = $customLogger['logLevel'];
                    $this->loggerManager->addClient(
                        $customLogger['logger'],
                        $level instanceof LogLevel ? $level : LogLevel::from((int)$level),
                        $customLogger['methodsMap'] ?? []
                    );
                } else {
                    $configLevel = $configuration['logger']['logLevel'];
                    $this->loggerManager->addClient(
                        $customLogger,
                        $configLevel instanceof LogLevel ? $configLevel : LogLevel::from((int)$configLevel)
                    );
                }
            }
        }
        $configuration['data'] = new ConfigResponseData($configuration['data']);
        // Initialize EventManager
        $this->eventManager = new EventManager(new OpenApiConfig($configuration), ['loggerManager' => $this->loggerManager]);

        // Initialize ApiManager
        $this->apiManager = new ApiManager(new OpenApiConfig($configuration), $this->eventManager, $this->loggerManager);
        // Initialize BucketingManager
        $this->bucketingManager = new BucketingManager(new OpenApiConfig($configuration), [
            'loggerManager' => $this->loggerManager
        ]);

        $this->ruleManager = new RuleManager(new OpenApiConfig($configuration), [
            'loggerManager' => $this->loggerManager
        ]);
        // Initialize DataManager
        $this->dataManager = new DataManager(new OpenApiConfig($configuration),
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager
        );

        // Initialize ExperienceManager
        $this->experienceManager = new ExperienceManager(new OpenApiConfig($configuration), [
            'dataManager' => $this->dataManager,
            'loggerManager' => $this->loggerManager
        ]);
        $this->featureManager = new FeatureManager(new OpenApiConfig($configuration), $this->dataManager, $this->loggerManager);
        $this->segmentsManager = new SegmentsManager(new OpenApiConfig($configuration), $this->dataManager, $this->ruleManager, $this->loggerManager);

        // Call parent constructor
        parent::__construct(new OpenApiConfig($configuration), [
            'dataManager'       => $this->dataManager,
            'eventManager'      => $this->eventManager,
            'apiManager'        => $this->apiManager,
            'loggerManager'     => $this->loggerManager,
            'experienceManager' => $this->experienceManager,
            'featureManager'    => $this->featureManager,
            'segmentsManager'   => $this->segmentsManager,
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
