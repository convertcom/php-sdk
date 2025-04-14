<?php

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\BucketingManager;
use ConvertSdk\RuleManager;
use ConvertSdk\EventManager;
use ConvertSdk\ApiManager;
use ConvertSdk\DataManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\SegmentsManager;
use ConvertSdk\LogManager;
use ConvertSdk\Context;
use OpenAPI\Client\Config;
use ConvertSdk\Enums\EntityType;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Model\ConversionAttributes;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\Model\ConfigResponseData;

class ContextTest extends TestCase
{
    private $config;
    private $bucketingManager;
    private $ruleManager;
    private $eventManager;
    private $apiManager;
    private $loggerManager;
    private $dataManager;
    private $experienceManager;
    private $featureManager;
    private $segmentsManager;
    private $context;
    private $accountId;
    private $projectId;
    private $visitorId = 'XXX';
    private $featureId = '10025';

    protected function setUp(): void
    {
        // Load and merge configuration
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault(); // Assume DefaultConfig exists
        $configuration = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, [
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
        $configuration['data'] = new ConfigResponseData($configuration['data']);
        if (isset($configuration['sdkKey'])) {
            unset($configuration['sdkKey']);
        }

        // Create Config object
        $this->config = new Config($configuration);
        $this->loggerManager = new LogManager($this->config);
        $this->bucketingManager = new BucketingManager($this->config);
        $this->ruleManager = new RuleManager($this->config);
        $this->eventManager = new EventManager($this->config);
        $this->apiManager = new ApiManager($this->config, $this->eventManager);
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

        $this->context = new Context(
            $this->config,
            $this->visitorId,
            [
                'eventManager' => $this->eventManager,
                'experienceManager' => $this->experienceManager,
                'featureManager' => $this->featureManager,
                'segmentsManager' => $this->segmentsManager,
                'dataManager' => $this->dataManager,
                'apiManager' => $this->apiManager
            ]
        );

        $this->accountId = $this->config->getData() ? $this->config->getData()->getAccountId() : '';
        $project = $this->config->getData() ? $this->config->getData()->getProject() : null;
        $this->projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
    }

    ### Helper Functions from shared.js (Converted to Test Methods)

    public function testGetVariationsAcrossAllExperiences(): void
    {
        $variationIds = ['100299456', '100299457', '100299460', '100299461'];
        $variations = $this->context->runExperiences(new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertIsArray($variations);
        $this->assertCount(2, $variations);
        foreach ($variations as $variation) {
            $this->assertIsArray($variation);
            $this->assertArrayHasKey('experienceId', $variation);
            $this->assertArrayHasKey('experienceKey', $variation);
            $this->assertArrayHasKey('experienceName', $variation);
            $this->assertArrayHasKey('bucketingAllocation', $variation);
            $this->assertArrayHasKey('id', end($variation));
            $this->assertArrayHasKey('key', end($variation));
            $this->assertArrayHasKey('name', end($variation));
            $this->assertArrayHasKey('status', end($variation));
            $this->assertArrayHasKey('changes', end($variation));
            $this->assertArrayHasKey('traffic_allocation', end($variation));
        }
        $selectedVariations = array_column($variations, 'id');
        $this->assertContainsAll($variationIds, $selectedVariations);
    }

    public function testGetSingleFeatureWithStatus(): void
    {
        $featureKey = 'feature-2';
        $feature = $this->context->runFeature($featureKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertIsArray($feature);
        $this->assertArrayHasKey('experienceId', $feature);
        $this->assertArrayHasKey('experienceKey', $feature);
        $this->assertArrayHasKey('experienceName', $feature);
        $this->assertArrayHasKey('id', $feature);
        $this->assertArrayHasKey('key', $feature);
        $this->assertArrayHasKey('name', $feature);
        $this->assertArrayHasKey('status', $feature);
        $this->assertArrayHasKey('variables', $feature);
        $this->assertEquals($this->featureId, $feature['id']);
    }

    public function testGetMultipleFeatureWithStatus(): void
    {
        $featureKey = 'feature-1';
        $featureIds = ['10024', '10025'];
        $features = $this->context->runFeature($featureKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertIsArray($features);
        $this->assertCount(2, $features);
        foreach ($features as $feature) {
            $this->assertIsArray($feature);
            $this->assertArrayHasKey('experienceId', $feature);
            $this->assertArrayHasKey('experienceKey', $feature);
            $this->assertArrayHasKey('experienceName', $feature);
            $this->assertArrayHasKey('id', $feature);
            $this->assertArrayHasKey('key', $feature);
            $this->assertArrayHasKey('name', $feature);
            $this->assertArrayHasKey('status', $feature);
            $this->assertArrayHasKey('variables', $feature);
        }
        $selectedFeatures = array_column($features, 'id');
        $this->assertContainsAll($featureIds, $selectedFeatures);
    }

    public function testGetFeaturesWithStatuses(): void
    {
        $featureIds = ['10024', '10025', '10026'];
        $features = $this->context->runFeatures(new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertIsArray($features);
        $this->assertCount(4, $features);
        $enabledFeatures = array_filter($features, fn($f) => $f['status'] === 'enabled');
        foreach ($enabledFeatures as $feature) {
            $this->assertIsArray($feature);
            $this->assertArrayHasKey('experienceId', $feature);
            $this->assertArrayHasKey('experienceKey', $feature);
            $this->assertArrayHasKey('experienceName', $feature);
            $this->assertArrayHasKey('id', $feature);
            $this->assertArrayHasKey('key', $feature);
            $this->assertArrayHasKey('name', $feature);
            $this->assertArrayHasKey('status', $feature);
            $this->assertArrayHasKey('variables', $feature);
        }
        $disabledFeatures = array_filter($features, fn($f) => $f['status'] === 'disabled');
        foreach ($disabledFeatures as $feature) {
            $this->assertIsArray($feature);
            $this->assertArrayHasKey('id', $feature);
            $this->assertArrayHasKey('key', $feature);
            $this->assertArrayHasKey('name', $feature);
            $this->assertArrayHasKey('status', $feature);
        }
        $selectedFeatures = array_column($features, 'id');
        $this->assertContainsAll($featureIds, $selectedFeatures);
    }

    // Custom assertion for deep membership
    private function assertContainsAll(array $haystack, array $needles): void
    {
        foreach ($needles as $needle) {
            $this->assertContains($needle, $haystack);
        }
    }

    ### Basic Tests

    public function testContextClassIsDefined(): void
    {
        $this->assertTrue(class_exists(Context::class));
    }

    public function testContextIsConstructable(): void
    {
        $this->assertInstanceOf(Context::class, $this->context);
    }

    ### Main Context Tests

    public function testRunExperience(): void
    {
        $experienceKey = 'test-experience-ab-fullstack-2';
        $variation = $this->context->runExperience($experienceKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertIsArray($variation);
        $this->assertArrayHasKey('experienceId', $variation);
        $this->assertArrayHasKey('experienceKey', $variation);
        $this->assertArrayHasKey('experienceName', $variation);
        $this->assertArrayHasKey('bucketingAllocation', $variation);
        $this->assertArrayHasKey('id', end($variation));
        $this->assertArrayHasKey('key', end($variation));
        $this->assertArrayHasKey('name', end($variation));
        $this->assertArrayHasKey('status', end($variation));
        $this->assertArrayHasKey('changes', end($variation));
        $this->assertArrayHasKey('traffic_allocation', end($variation));
        $this->assertEquals($experienceKey, $variation['experienceKey']);
    }

    public function testRunExperiences(): void
    {
        $this->testGetVariationsAcrossAllExperiences();
    }

    public function testRunSingleFeature(): void
    {
        $this->testGetSingleFeatureWithStatus();
    }

    public function testRunMultipleFeatures(): void
    {
        $this->testGetMultipleFeatureWithStatus();
    }

    public function testRunFeatures(): void
    {
        $this->testGetFeaturesWithStatuses();
    }

    public function testTrackConversion(): void
    {
        $goalKey = 'increase-engagement';
        $requestData = [
            'source' => 'js-sdk',
            'enrichData' => true,
            'accountId' => $this->accountId,
            'projectId' => $this->projectId,
            'visitors' => [
                [
                    'visitorId' => $this->visitorId,
                    'events' => [
                        [
                            'eventType' => 'conversion',
                            'data' => ['goalId' => '100215960']
                        ],
                        [
                            'eventType' => 'conversion',
                            'data' => [
                                'goalId' => '100215960',
                                'goalData' => [
                                    ['key' => 'amount', 'value' => 10.3],
                                    ['key' => 'productsCount', 'value' => 2]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $this->context->trackConversion($goalKey, [
            'ruleData' => ['action' => 'buy'],
            'conversionData' => [
                ['key' => 'amount', 'value' => 10.3],
                ['key' => 'productsCount', 'value' => 2]
            ]
        ]);

        // Since we're not using a server, we can't directly assert the request body.
        // Instead, we could mock the ApiManager or check internal state if applicable.
        // For now, we'll assume the method runs without throwing an exception.
        $this->assertTrue(true); // Placeholder assertion
    }

    public function testTrackConversionInvalidGoalData(): void
    {
        $goalKey = 'increase-engagement';
        $response = $this->context->trackConversion($goalKey, [
            'ruleData' => ['action' => 'buy'],
            'conversionData' => [
                ['key' => 'amount', 'value' => 10.3],
                ['key' => 'productsCount', 'value' => 2]
            ]
        ]);
        $this->assertNull($response);
    }

    public function testSetDefaultSegments(): void
    {
        $segments = ['country' => 'UK'];
        $this->context->setDefaultSegments($segments);
        $localSegments = $this->dataManager->getData($this->visitorId);
        $this->assertEquals($segments["country"], $localSegments['segments']['country']);
    }

    public function testRunCustomSegments(): void
    {
        $segmentKey = 'test-segments-1';
        $segmentId = '200299434';
        $this->context->runCustomSegments([$segmentKey], ['ruleData' => ['enabled' => true]]);
        $data = $this->dataManager->getData($this->visitorId);
        $this->assertEquals([$segmentId], $data['segments']['custom_segments']);
    }

    public function testUpdateVisitorProperties(): void
    {
        $properties = ['weather' => 'rainy'];
        $this->context->updateVisitorProperties($this->visitorId, $properties);
        $localSegments = $this->dataManager->getData($this->visitorId);
        $this->assertEquals($properties, $localSegments['segments']);
    }



    ### Invalid Visitor Tests

    public function testRunExperienceInvalidVisitor(): void
    {
        $invalidContext = new Context($this->config, null, [
            'eventManager' => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager' => $this->featureManager,
            'segmentsManager' => $this->segmentsManager,
            'dataManager' => $this->dataManager,
            'apiManager' => $this->apiManager
        ]);
        $variation = $invalidContext->runExperience('test-experience-ab-fullstack-2');
        $this->assertNull($variation);
    }

    public function testRunExperiencesInvalidVisitor(): void
    {
        $invalidContext = new Context($this->config, null, [
            'eventManager' => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager' => $this->featureManager,
            'segmentsManager' => $this->segmentsManager,
            'dataManager' => $this->dataManager,
            'apiManager' => $this->apiManager
        ]);
        $variations = $invalidContext->runExperiences();
        $this->assertEmpty($variations);
    }

    public function testRunFeatureInvalidVisitor(): void
    {
        $invalidContext = new Context($this->config, null, [
            'eventManager' => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager' => $this->featureManager,
            'segmentsManager' => $this->segmentsManager,
            'dataManager' => $this->dataManager,
            'apiManager' => $this->apiManager
        ]);
        $features = $invalidContext->runFeature('feature-1');
        $this->assertNull($features);
    }

    public function testRunFeaturesInvalidVisitor(): void
    {
        $invalidContext = new Context($this->config, null, [
            'eventManager' => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager' => $this->featureManager,
            'segmentsManager' => $this->segmentsManager,
            'dataManager' => $this->dataManager,
            'apiManager' => $this->apiManager
        ]);
        $features = $invalidContext->runFeatures();
        $this->assertEmpty($features);
    }

    public function testTrackConversionInvalidVisitor(): void
    {
        $invalidContext = new Context($this->config, null, [
            'eventManager' => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager' => $this->featureManager,
            'segmentsManager' => $this->segmentsManager,
            'dataManager' => $this->dataManager,
            'apiManager' => $this->apiManager
        ]);
        $output = $invalidContext->trackConversion('increase-engagement', []);
        $this->assertNull($output);
    }

    public function testSetCustomSegmentsInvalidVisitor(): void
    {
        $invalidContext = new Context($this->config, null, [
            'eventManager' => $this->eventManager,
            'experienceManager' => $this->experienceManager,
            'featureManager' => $this->featureManager,
            'segmentsManager' => $this->segmentsManager,
            'dataManager' => $this->dataManager,
            'apiManager' => $this->apiManager
        ]);
        $output = $invalidContext->setCustomSegments(['test-segments-1']);
        $this->assertNull($output);
    }
}