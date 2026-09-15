<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Context;
use ConvertSdk\DataManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use PHPUnit\Framework\TestCase;

/**
 * Counts real enqueue() calls without mocking network I/O — enqueue() only
 * queues internally until batch size or an explicit releaseQueue(), and this
 * suite never crosses either, so delegating to a real ApiManager is safe.
 */
final class CountingApiManagerDecorator implements ApiManagerInterface
{
    public int $enqueueCalls = 0;

    public function __construct(private readonly ApiManagerInterface $inner)
    {
    }

    public function request(string $method, array $path, array $data = [], array $headers = []): array
    {
        return $this->inner->request($method, $path, $data, $headers);
    }

    public function enqueue(string $visitorId, VisitorTrackingEvents $eventRequest, ?VisitorSegments $segments = null): void
    {
        $this->enqueueCalls++;
        $this->inner->enqueue($visitorId, $eventRequest, $segments);
    }

    public function releaseQueue(?string $reason = null): void
    {
        $this->inner->releaseQueue($reason);
    }

    public function enableTracking(): void
    {
        $this->inner->enableTracking();
    }

    public function disableTracking(): void
    {
        $this->inner->disableTracking();
    }

    public function setData(ConfigResponseData $data): void
    {
        $this->inner->setData($data);
    }

    public function getConfig(): ConfigResponseData
    {
        return $this->inner->getConfig();
    }

    public function getConfigForExperience(string $experienceId): ConfigResponseData
    {
        return $this->inner->getConfigForExperience($experienceId);
    }
}

/**
 * Minimal duck-typed visitor dataStore — DataManager only ever calls get()/set()
 * on whatever is installed via setDataStore().
 */
final class RecordingFeatureDataStore
{
    public int $setCalls = 0;

    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $data): void
    {
        $this->setCalls++;
        $this->data[$key] = $data;
    }
}

/**
 * CAP-1 (SPEC-per-call-bucketing-attributes) concern 3 — enableTracking must
 * gate the bucketing-event enqueue without suppressing the sticky-decision
 * write, on both runFeature() and runFeatures().
 */
class ContextFeatureTrackingSuppressionTest extends TestCase
{
    /** @return array{context: Context, apiManager: CountingApiManagerDecorator, dataStore: RecordingFeatureDataStore} */
    private function buildContext(string $visitorId): array
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $configuration = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, [
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
        $configuration['data'] = new ConfigResponseData($configuration['data']);
        if (isset($configuration['sdkKey'])) {
            unset($configuration['sdkKey']);
        }

        $config = new Config($configuration);
        $loggerManager = new LogManager();
        $bucketingConfig = $config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $eventManager = new EventManager();
        $apiManager = new CountingApiManagerDecorator(new ApiManager($config, $eventManager, $loggerManager));
        $dataManager = new DataManager($config, $bucketingManager, $ruleManager, $eventManager, $apiManager, $loggerManager);
        $dataStore = new RecordingFeatureDataStore();
        $dataManager->setDataStore($dataStore);

        $experienceManager = new ExperienceManager(dataManager: $dataManager);
        $featureManager = new FeatureManager(dataManager: $dataManager);
        $segmentsManager = new SegmentsManager($config, $dataManager, $ruleManager);

        $context = new Context(
            $config,
            $visitorId,
            $eventManager,
            $experienceManager,
            $featureManager,
            $dataManager,
            $segmentsManager,
            $apiManager,
        );

        return ['context' => $context, 'apiManager' => $apiManager, 'dataStore' => $dataStore];
    }

    /** @return array<string, mixed> */
    private function attributesFixture(array $overrides = []): array
    {
        return array_merge([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something'],
        ], $overrides);
    }

    public function testDefaultCallEnqueuesAndPersists(): void
    {
        $rig = $this->buildContext('tracking-default-run-feature');
        $rig['context']->runFeature('feature-1', new BucketingAttributes($this->attributesFixture()));

        $this->assertGreaterThan(0, $rig['apiManager']->enqueueCalls);
        $this->assertGreaterThan(0, $rig['dataStore']->setCalls);
    }

    public function testEnableTrackingFalseProducesNoEnqueueButStillPersists(): void
    {
        $rig = $this->buildContext('tracking-disabled-run-feature');
        $rig['context']->runFeature('feature-1', new BucketingAttributes($this->attributesFixture(['enableTracking' => false])));

        $this->assertSame(0, $rig['apiManager']->enqueueCalls);
        $this->assertGreaterThan(0, $rig['dataStore']->setCalls);
    }

    public function testDefaultRunFeaturesCallEnqueuesAndPersists(): void
    {
        $rig = $this->buildContext('tracking-default-run-features');
        $rig['context']->runFeatures(new BucketingAttributes($this->attributesFixture()));

        $this->assertGreaterThan(0, $rig['apiManager']->enqueueCalls);
        $this->assertGreaterThan(0, $rig['dataStore']->setCalls);
    }

    public function testEnableTrackingFalseOnRunFeaturesProducesNoEnqueueButStillPersists(): void
    {
        $rig = $this->buildContext('tracking-disabled-run-features');
        $rig['context']->runFeatures(new BucketingAttributes($this->attributesFixture(['enableTracking' => false])));

        $this->assertSame(0, $rig['apiManager']->enqueueCalls);
        $this->assertGreaterThan(0, $rig['dataStore']->setCalls);
    }
}
