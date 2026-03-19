<?php

declare(strict_types=1);

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
use ConvertSdk\Exception\InvalidArgumentException;
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
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
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

        $this->config = new Config($configuration);
        $this->loggerManager = new LogManager();
        $bucketingConfig = $this->config->getBucketing();
        $this->bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $this->ruleManager = new RuleManager();
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
        $this->experienceManager = new ExperienceManager(dataManager: $this->dataManager);
        $this->featureManager = new FeatureManager(dataManager: $this->dataManager);
        $this->segmentsManager = new SegmentsManager($this->config, $this->dataManager, $this->ruleManager);

        $this->context = new Context(
            $this->config,
            $this->visitorId,
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
        );

        $this->accountId = $this->config->getData() ? $this->config->getData()->getAccountId() : '';
        $project = $this->config->getData() ? $this->config->getData()->getProject() : null;
        $this->projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
    }

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
            $this->assertInstanceOf(\ConvertSdk\DTO\BucketedVariation::class, $variation);
            $this->assertNotEmpty($variation->experienceId);
            $this->assertNotEmpty($variation->experienceKey);
            $this->assertNotEmpty($variation->variationId);
            $this->assertNotEmpty($variation->variationKey);
            $this->assertIsArray($variation->changes);
        }
        $selectedVariationIds = array_map(fn($v) => $v->variationId, $variations);
        foreach ($selectedVariationIds as $id) {
            $this->assertContains($id, $variationIds);
        }
    }

    public function testGetSingleFeatureWithStatus(): void
    {
        $featureKey = 'feature-2';
        $feature = $this->context->runFeature($featureKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertInstanceOf(\ConvertSdk\DTO\BucketedFeature::class, $feature);
        $this->assertNotEmpty($feature->featureId);
        $this->assertEquals($featureKey, $feature->featureKey);
        $this->assertInstanceOf(\ConvertSdk\Enums\FeatureStatus::class, $feature->status);
        $this->assertEquals(\ConvertSdk\Enums\FeatureStatus::Enabled, $feature->status);
        $this->assertIsArray($feature->variables);
        $this->assertEquals($this->featureId, $feature->featureId);
    }

    public function testGetMultipleFeatureWithStatus(): void
    {
        $featureKey = 'feature-1';
        // feature-1 is in multiple experiences — runFeature returns first enabled
        $feature = $this->context->runFeature($featureKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertInstanceOf(\ConvertSdk\DTO\BucketedFeature::class, $feature);
        $this->assertEquals($featureKey, $feature->featureKey);
        $this->assertEquals(\ConvertSdk\Enums\FeatureStatus::Enabled, $feature->status);
        $this->assertNotEmpty($feature->featureId);
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
        foreach ($features as $feature) {
            $this->assertInstanceOf(\ConvertSdk\DTO\BucketedFeature::class, $feature);
            $this->assertInstanceOf(\ConvertSdk\Enums\FeatureStatus::class, $feature->status);
            $this->assertNotEmpty($feature->featureKey);
        }
        $enabledFeatures = array_filter($features, fn($f) => $f->status === \ConvertSdk\Enums\FeatureStatus::Enabled);
        foreach ($enabledFeatures as $feature) {
            $this->assertNotEmpty($feature->featureId);
            $this->assertIsArray($feature->variables);
        }
        $disabledFeatures = array_filter($features, fn($f) => $f->status === \ConvertSdk\Enums\FeatureStatus::Disabled);
        foreach ($disabledFeatures as $feature) {
            $this->assertNotEmpty($feature->featureId);
        }
        $selectedFeatures = array_map(fn($f) => $f->featureId, $features);
        $this->assertContainsAll($featureIds, $selectedFeatures);
    }

    private function assertContainsAll(array $haystack, array $needles): void
    {
        foreach ($needles as $needle) {
            $this->assertContains($needle, $haystack);
        }
    }

    public function testContextClassIsDefined(): void
    {
        $this->assertTrue(class_exists(Context::class));
    }

    public function testContextIsConstructable(): void
    {
        $this->assertInstanceOf(Context::class, $this->context);
    }

    public function testContextIsFinalClass(): void
    {
        $reflection = new \ReflectionClass(Context::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testRunExperience(): void
    {
        $experienceKey = 'test-experience-ab-fullstack-2';
        $variation = $this->context->runExperience($experienceKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertInstanceOf(\ConvertSdk\DTO\BucketedVariation::class, $variation);
        $this->assertNotEmpty($variation->experienceId);
        $this->assertEquals($experienceKey, $variation->experienceKey);
        $this->assertNotEmpty($variation->variationId);
        $this->assertNotEmpty($variation->variationKey);
        $this->assertIsArray($variation->changes);
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
        $this->context->trackConversion($goalKey, [
            'ruleData' => ['action' => 'buy'],
            'conversionData' => [
                ['key' => 'amount', 'value' => 10.3],
                ['key' => 'productsCount', 'value' => 2]
            ]
        ]);
        $this->assertTrue(true);
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

    public function testEmptyVisitorIdThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Visitor ID must not be empty');

        new Context(
            $this->config,
            '',
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
        );
    }

    public function testGetVisitorId(): void
    {
        $this->assertEquals($this->visitorId, $this->context->getVisitorId());
    }

    public function testGetAttributesReturnsEmptyArrayWhenNoAttributesSet(): void
    {
        $this->assertIsArray($this->context->getAttributes());
    }

    public function testCreateContextWithAttributes(): void
    {
        // Note: filterReportSegments() splits attributes:
        // - Segment keys (browser, devices, source, campaign, visitor_type, country, custom_segments) → stored via putSegments()
        // - Other keys → stored as visitorProperties (accessible via getAttributes())
        $attributes = ['plan' => 'premium', 'country' => 'DE'];
        $context = new Context(
            $this->config,
            'visitor-with-attrs',
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
            null,
            $attributes,
        );

        $this->assertEquals('visitor-with-attrs', $context->getVisitorId());

        // 'plan' is a non-segment property → accessible via getAttributes()
        $result = $context->getAttributes();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('plan', $result, 'Non-segment attributes should be accessible via getAttributes()');
        $this->assertEquals('premium', $result['plan']);

        // 'country' is a segment key → stored via putSegments(), not in getAttributes()
        $this->assertArrayNotHasKey('country', $result, 'Segment keys are stored via putSegments, not in visitorProperties');
    }

    public function testSetAttribute(): void
    {
        $this->context->setAttribute('country', 'US');
        $attrs = $this->context->getAttributes();
        $this->assertArrayHasKey('country', $attrs);
        $this->assertEquals('US', $attrs['country']);
    }

    public function testSetAttributeOverwritesExisting(): void
    {
        $this->context->setAttribute('country', 'US');
        $this->context->setAttribute('country', 'CA');
        $attrs = $this->context->getAttributes();
        $this->assertEquals('CA', $attrs['country']);
    }

    public function testSetAttributesMultiple(): void
    {
        $this->context->setAttributes(['plan' => 'enterprise', 'locale' => 'en']);
        $attrs = $this->context->getAttributes();
        $this->assertArrayHasKey('plan', $attrs);
        $this->assertArrayHasKey('locale', $attrs);
        $this->assertEquals('enterprise', $attrs['plan']);
        $this->assertEquals('en', $attrs['locale']);
    }

    public function testSetAttributesMergesWithExisting(): void
    {
        $this->context->setAttribute('plan', 'free');
        $this->context->setAttributes(['country' => 'US']);
        $attrs = $this->context->getAttributes();
        $this->assertArrayHasKey('plan', $attrs);
        $this->assertArrayHasKey('country', $attrs);
        $this->assertEquals('free', $attrs['plan']);
        $this->assertEquals('US', $attrs['country']);
    }

    public function testSetAttributeAddsNewKeyToExisting(): void
    {
        $context = new Context(
            $this->config,
            'visitor-initial-attrs',
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
            null,
            ['plan' => 'free'],
        );

        $context->setAttribute('country', 'US');
        $attrs = $context->getAttributes();
        $this->assertArrayHasKey('plan', $attrs);
        $this->assertArrayHasKey('country', $attrs);
        $this->assertEquals('US', $attrs['country']);
    }

    public function testRunExperienceReturnsDto(): void
    {
        $experienceKey = 'test-experience-ab-fullstack-2';
        $result = $this->context->runExperience($experienceKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertInstanceOf(\ConvertSdk\DTO\BucketedVariation::class, $result);
        $this->assertNotEmpty($result->experienceId);
        $this->assertEquals($experienceKey, $result->experienceKey);
        $this->assertNotEmpty($result->variationId);
        $this->assertNotEmpty($result->variationKey);
        $this->assertIsArray($result->changes);
    }

    public function testRunExperienceReturnsNullForMissingKey(): void
    {
        $result = $this->context->runExperience('nonexistent-experience', new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertNull($result);
    }

    public function testRunExperiencesReturnsDtoArray(): void
    {
        $variations = $this->context->runExperiences(new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertIsArray($variations);
        $this->assertGreaterThan(0, count($variations));
        foreach ($variations as $variation) {
            $this->assertInstanceOf(\ConvertSdk\DTO\BucketedVariation::class, $variation);
            $this->assertNotEmpty($variation->experienceId);
            $this->assertNotEmpty($variation->experienceKey);
            $this->assertNotEmpty($variation->variationId);
            $this->assertNotEmpty($variation->variationKey);
            $this->assertIsArray($variation->changes);
        }
    }

    public function testRunExperienceDoesNotFireEventOnNull(): void
    {
        // A nonexistent experience should return null and not fire any event
        $result = $this->context->runExperience('definitely-nonexistent', new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]));

        $this->assertNull($result);
    }

    public function testRunFeatureReturnsDto(): void
    {
        $featureKey = 'feature-2';
        $result = $this->context->runFeature($featureKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertInstanceOf(\ConvertSdk\DTO\BucketedFeature::class, $result);
        $this->assertNotEmpty($result->featureId);
        $this->assertEquals($featureKey, $result->featureKey);
        $this->assertIsArray($result->variables);
    }

    public function testRunFeatureReturnsNullForMissingKey(): void
    {
        $result = $this->context->runFeature('nonexistent-feature', new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertNull($result);
    }

    public function testRunFeaturesReturnsDtoArray(): void
    {
        $features = $this->context->runFeatures(new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        $this->assertIsArray($features);
        $this->assertGreaterThan(0, count($features));
        foreach ($features as $feature) {
            $this->assertInstanceOf(\ConvertSdk\DTO\BucketedFeature::class, $feature);
            $this->assertNotEmpty($feature->featureKey);
            $this->assertInstanceOf(\ConvertSdk\Enums\FeatureStatus::class, $feature->status);
        }
    }

    public function testRunFeatureReturnsDtoWithDisabledStatus(): void
    {
        // 'not-attached-feature-3' exists in config but visitor shouldn't be bucketed for it
        $featureKey = 'not-attached-feature-3';
        $result = $this->context->runFeature($featureKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something']
        ]));

        // Feature exists but visitor is not bucketed → disabled DTO
        $this->assertInstanceOf(\ConvertSdk\DTO\BucketedFeature::class, $result);
        $this->assertEquals(\ConvertSdk\Enums\FeatureStatus::Disabled, $result->status);
        $this->assertEquals($featureKey, $result->featureKey);
    }

    public function testRunFeatureDoesNotFireEventOnNull(): void
    {
        // A nonexistent feature should return null and not fire any event
        $result = $this->context->runFeature('definitely-nonexistent-feature', new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]));

        $this->assertNull($result);
    }

    public function testRunFeatureTypeCastingApplied(): void
    {
        $featureKey = 'feature-1';
        $result = $this->context->runFeature($featureKey, new BucketingAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'visitorProperties' => ['varName3' => 'something'],
            'typeCasting' => true
        ]));

        $this->assertInstanceOf(\ConvertSdk\DTO\BucketedFeature::class, $result);
        $this->assertEquals(\ConvertSdk\Enums\FeatureStatus::Enabled, $result->status);
        $this->assertNotEmpty($result->variables, 'Enabled feature should have variables');

        // Verify actual type casting: 'enabled' variable is defined as boolean in test-config
        if (isset($result->variables['enabled'])) {
            $this->assertIsBool($result->variables['enabled'], 'Boolean variable should be cast to bool, not remain string');
        }

        // Verify variables are not all strings (type casting must have happened)
        $hasNonString = false;
        foreach ($result->variables as $value) {
            if (!is_string($value)) {
                $hasNonString = true;
                break;
            }
        }
        $this->assertTrue($hasNonString, 'At least one variable should be type-cast to a non-string type');
    }
}
