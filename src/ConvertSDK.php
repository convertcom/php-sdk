<?php
namespace ConvertSdk;

use ConvertSdk\Api\ApiManager;
use ConvertSdk\Bucketing\BucketingManager;
use ConvertSdk\Data\DataManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Experience\ExperienceManager;
use ConvertSdk\Feature\FeatureManager;
use ConvertSdk\Rules\RuleManager;
use ConvertSdk\Segments\SegmentsManager;
use ConvertSdk\Logger\LogManager;
use ConvertSdk\Config\Config;
use ConvertSdk\Exceptions\SdkException;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\Messages;

class ConvertSDK extends Core {
    public $dataManager;
    public $eventManager;
    public $experienceManager;
    public $featureManager;
    public $segmentsManager;
    public $apiManager;
    public $loggerManager;

    public function __construct(array $config = []) {
        if (empty($config['sdkKey']) && empty($config['data'])) {
            error_log(ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED);
            throw new SdkException(ErrorMessages::SDK_OR_DATA_OBJECT_REQUIRED);
        }
        
        $configuration = Config::create($config);
        // Set default network source if not provided
        if (!isset($configuration['network']['source'])) {
            $configuration['network']['source'] = getenv('VERSION') ?: 'php-sdk';
        }

        // Initialize LoggerManager and additional dependencies...
        $this->loggerManager = new LogManager($configuration['logger']['logLevel'], $configuration['logger']['customLoggers'] ?? []);
        $this->eventManager = new EventManager($configuration, ['loggerManager' => $this->loggerManager]);
        $this->apiManager = new ApiManager($configuration, [
            'eventManager' => $this->eventManager,
            'loggerManager' => $this->loggerManager
        ]);
        $bucketingManager = new BucketingManager($configuration, ['loggerManager' => $this->loggerManager]);
        $ruleManager = new RuleManager($configuration, ['loggerManager' => $this->loggerManager]);
        $this->dataManager = new DataManager($configuration, [
            'bucketingManager' => $bucketingManager,
            'ruleManager' => $ruleManager,
            'eventManager' => $this->eventManager,
            'apiManager' => $this->apiManager,
            'loggerManager' => $this->loggerManager
        ]);
        $this->experienceManager = new ExperienceManager($configuration, [
            'dataManager' => $this->dataManager
        ]);
        $this->featureManager = new FeatureManager($configuration, [
            'dataManager' => $this->dataManager,
            'loggerManager' => $this->loggerManager
        ]);
        $this->segmentsManager = new SegmentsManager($configuration, [
            'dataManager' => $this->dataManager,
            'ruleManager' => $ruleManager,
            'loggerManager' => $this->loggerManager
        ]);

        // Pass all managers to the parent Core constructor
        parent::__construct($configuration, [
            'dataManager'       => $this->dataManager,
            'eventManager'      => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager'    => $this->featureManager,
            'segmentsManager'   => $this->segmentsManager,
            'apiManager'        => $this->apiManager,
            'loggerManager'     => $this->loggerManager
        ]);
    }

    public function onReady(): void {
        parent::onReady();
    }
}
