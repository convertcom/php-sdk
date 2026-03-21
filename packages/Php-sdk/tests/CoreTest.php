<?php

declare(strict_types=1);

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Cache\ArrayCache;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Context;
use ConvertSdk\Core;
use ConvertSdk\DataManager;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Event\EventManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\TestCase;

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
    private $accountId;
    private $projectId;

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $this->configuration = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, [
            'api' => [
                'endpoint' => [
                    'config' => 'http://127.0.0.1:9501',
                    'track' => 'http://127.0.0.1:9501',
                ],
            ],
            'events' => [
                'batch_size' => 5,
                'release_interval' => 1000,
            ],
        ]);
        $this->configuration['data'] = new ConfigResponseData($this->configuration['data']);
        if (isset($this->configuration['sdkKey'])) {
            unset($this->configuration['sdkKey']);
        }
        $this->config = new Config($this->configuration);

        $this->loggerManager = new LogManager();
        $bucketingConfig = $this->config->getBucketing();
        $this->bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $this->ruleManager = new RuleManager();
        $this->eventManager = new EventManager();
        $this->apiManager = new ApiManager($this->config, $this->eventManager, $this->loggerManager);
        $this->dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager
        );
        $this->experienceManager = new ExperienceManager(dataManager: $this->dataManager);
        $this->featureManager = new FeatureManager(dataManager: $this->dataManager, logManager: $this->loggerManager);
        $this->segmentsManager = new SegmentsManager($this->config, $this->dataManager, $this->ruleManager);

        $this->core = new Core(
            $this->config,
            $this->dataManager,
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->segmentsManager,
            $this->apiManager,
            new ArrayCache(),
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $this->loggerManager,
        );

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
    public function shouldReturnTrueFromIsReady()
    {
        $this->assertTrue($this->core->isReady());
    }

    /** @test */
    public function isReadyAndOnReadyShouldReturnSameValue()
    {
        $this->assertEquals($this->core->isReady(), $this->core->onReady());
    }

    /** @test */
    public function coreShouldBeFinalClass()
    {
        $reflection = new \ReflectionClass(Core::class);
        $this->assertTrue($reflection->isFinal());
    }

    /** @test */
    public function flushMethodExistsAndIsPublic(): void
    {
        $reflection = new \ReflectionClass(Core::class);
        $this->assertTrue($reflection->hasMethod('flush'));
        $this->assertTrue($reflection->getMethod('flush')->isPublic());
    }

    /** @test */
    public function flushIsNoOpWhenQueueIsEmpty(): void
    {
        // flush() should not throw when there are no queued events
        $this->core->flush();
        $this->assertTrue(true); // No exception means success
    }

    /** @test */
    public function flushDelegatesToApiManagerReleaseQueue(): void
    {
        $apiManagerMock = $this->createMock(\ConvertSdk\Interfaces\ApiManagerInterface::class);
        $apiManagerMock->expects($this->once())
            ->method('releaseQueue')
            ->with('flush');

        $core = new Core(
            $this->config,
            $this->dataManager,
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->segmentsManager,
            $apiManagerMock,
            new ArrayCache(),
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $this->loggerManager,
        );

        $core->flush();
    }

    /** @test */
    public function isReadyShouldReturnFalseWhenConfigDataThrows(): void
    {
        $dataManagerMock = $this->createMock(\ConvertSdk\Interfaces\DataManagerInterface::class);
        $dataManagerMock->method('getConfigData')
            ->willThrowException(new \RuntimeException('No config'));

        $core = new Core(
            $this->config,
            $dataManagerMock,
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->segmentsManager,
            $this->apiManager,
            new ArrayCache(),
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $this->loggerManager,
        );

        $this->assertFalse($core->isReady());
    }

    /** @test */
    public function createContextShouldThrowWhenVisitorIdEmpty(): void
    {
        $this->expectException(\ConvertSdk\Exception\InvalidArgumentException::class);
        $this->core->createContext('');
    }

}
