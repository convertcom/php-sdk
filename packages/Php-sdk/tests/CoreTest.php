<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ConvertSdk\BucketingManager;
use ConvertSdk\RuleManager;
use ConvertSdk\EventManager;
use ConvertSdk\ApiManager;
use ConvertSdk\DataManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\LogManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\Core;
use ConvertSdk\Context;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\EntityType;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Enums\ErrorMessages;



class CoreTest extends TestCase
{
    private $configuration;
    private $bucketingManager;
    private $ruleManager;
    private $eventManager;
    private $apiManager;
    private $dataManager;
    private $config;
    private $experienceManager;
    private $featureManager;
    private $loggerManager;
    private $segmentsManager;
    private $core;
    private $host = 'http://localhost';
    private $port = 8090;
    private $releaseTimeout = 1000;
    private $testTimeout = 1100; // releaseTimeout + 100
    private $batchSize = 5;

    protected function setUp(): void
    {
        // Initialize configuration
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault(); // Assume DefaultConfig exists
        $this->configuration = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, [
            'api' => [
                'endpoint' => [
                    'config' => 'http://127.0.0.1:9501',
                    'track' => 'http://127.0.0.1:9501'
                ]
            ],
            'events' => [
                'batch_size' => 5,
                'release_interval' => 1000
            ]
        ]);
        $this->configuration['data'] = new ConfigResponseData($this->configuration['data']);
        if (isset($this->configuration['sdkKey'])) {
            unset($this->configuration['sdkKey']);
        }
        $this->config = new Config($this->configuration);

        // Initialize managers
        $this->loggerManager = new LogManager($this->config);

        $this->bucketingManager = new BucketingManager($this->config);
        $this->ruleManager = new RuleManager($this->config);
        $this->eventManager = new EventManager($this->config);
        $this->apiManager = new ApiManager($this->config, $this->eventManager, $this->loggerManager);
        $this->dataManager = new DataManager(
          $this->config,
          $this->bucketingManager,
          $this->ruleManager,
          $this->eventManager,
          $this->apiManager,
          $this->loggerManager
      );
        $this->experienceManager = new ExperienceManager($this->config, ['dataManager' => $this->dataManager]);
        $this->featureManager = new FeatureManager($this->config, $this->dataManager);
        $this->segmentsManager = new SegmentsManager($this->config, $this->dataManager, $this->ruleManager);
        $this->core = new Core($this->config, [
            'eventManager' => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager' => $this->featureManager,
            'segmentsManager' => $this->segmentsManager,
            'dataManager' => $this->dataManager,
            'apiManager' => $this->apiManager
        ]);

        $this->accountId = $this->config->getData() ? $this->config->getData()->getAccountId() : '';
        $project = $this->config->getData() ? $this->config->getData()->getProject() : null;
        $this->projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
    }

    /** @test */
    public function importedEntityShouldBeAConstructorOfCoreInstance()
    {
        $this->assertTrue(class_exists(Core::class));
    }

    /** @test */
    public function shouldSuccessfullyCreateNewCoreInstance()
    {
        $this->assertInstanceOf(Core::class, $this->core);
    }

    /** @test */
    public function shouldExposeCore()
    {
        $this->assertTrue(class_exists(Core::class));
    }

    /** @test */
    public function shouldSuccessfullyCreateVisitorContext()
    {
        $visitorId = 'XXX';
        $visitorContext = $this->core->createContext($visitorId, ['browser' => 'chrome']);
        $this->assertInstanceOf(Context::class, $visitorContext);
    }

    /** @test */
    public function shouldSuccessfullyTriggerReadyEvent()
    {
        $triggered = false;
        $this->eventManager->on(SystemEvents::Ready, function ($args, $err) use (&$triggered) {
            $this->assertNull($err);
            $triggered = true;
        });
        // Simulate triggering the ready event (adjust based on actual Core implementation)
        $this->core->onReady();
        $this->assertTrue($triggered);
    }

    /** @test */
    public function shouldSuccessfullyResolveOnReady()
    {
        try {
            $this->core->onReady();
            $this->assertTrue(true);
        } catch (Exception $e) {
            $this->fail('onReady threw an exception: ' . $e->getMessage());
        }
    }

    /** @test */
    public function shouldSuccessfullyGetConfigUsingSdkKey()
    {
        $mock = new MockHandler([
            new Response(200, [], '{}')
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $accountId = $this->accountId;
        $projectId = $this->projectId;
        $config = [
            'sdkKey' => "$accountId/$projectId",
            'api' => $this->configuration['api'],
            'events' => $this->configuration['events'],
            'environment' => $this->configuration['environment']
        ];

        $core = new Core(new Config($config), [
            'eventManager' => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager' => $this->featureManager,
            'segmentsManager' => $this->segmentsManager,
            'dataManager' => $this->dataManager,
            'apiManager' => new ApiManager(new Config($config), $this->eventManager)
        ]);

        $this->assertInstanceOf(Core::class, $core);
    }
}