<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\DataManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\TestCase;

class FeatureManagerTest extends TestCase
{
    private $featureManager;
    private $dataManager;
    private $eventManager;
    private $apiManager;
    private $config;
    private $accountId;
    private $projectId;

    private const HOST = '127.0.0.1';
    private const PORT = 8090;
    private const RELEASE_TIMEOUT = 1000; // in milliseconds
    private const TEST_TIMEOUT = self::RELEASE_TIMEOUT + 1000;
    private const BATCH_SIZE = 5;
    private const VISITOR_ID = 'XXX';

    protected function setUp(): void
    {
        // Load test configuration
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $configuration = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, [
            'api' => [
                'endpoint' => [
                    'config' => 'http://' . self::HOST . ':' . self::PORT,
                    'track' => 'http://' . self::HOST . ':' . self::PORT,
                ],
            ],
            'events' => [
                'batch_size' => self::BATCH_SIZE,
                'release_interval' => self::RELEASE_TIMEOUT,
            ],
        ]);

        $configuration['data'] = new ConfigResponseData($configuration['data']);
        if (isset($configuration['sdkKey'])) {
            unset($configuration['sdkKey']);
        }
        // Create Config object
        $this->config = new Config($configuration);

        $bucketingConfig = $this->config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $loggerManager = new LogManager();
        $this->eventManager = new EventManager();
        $this->apiManager = new ApiManager($this->config, $this->eventManager);
        $this->dataManager = new DataManager(
            $this->config,
            $bucketingManager,
            $ruleManager,
            $this->eventManager,
            $this->apiManager,
            $loggerManager
        );
        $this->featureManager = new FeatureManager(dataManager: $this->dataManager);

        $this->accountId = $this->config->getData() ? $this->config->getData()->getAccountId() : '';
        $project = $this->config->getData() ? $this->config->getData()->getProject() : null;
        $this->projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
    }

    public function testExposeFeatureManager(): void
    {
        $this->assertTrue(class_exists(FeatureManager::class));
    }

    public function testImportedEntityIsConstructor(): void
    {
        $reflection = new \ReflectionClass(FeatureManager::class);
        $this->assertTrue($reflection->isInstantiable());
        $this->assertEquals('ConvertSdk\FeatureManager', $reflection->getName());
    }

    public function testCreateFeatureManagerInstance(): void
    {
        $this->assertIsObject($this->featureManager);
        $reflection = new \ReflectionClass($this->featureManager);
        $this->assertEquals('ConvertSdk\FeatureManager', $reflection->getName());
    }

    public function testGetListOfEntities(): void
    {
        $entities = $this->featureManager->getList();
        $this->assertIsArray($entities);
        $this->assertCount(3, $entities);
        $this->assertEquals($this->config->getData()->getFeatures(), $entities);
    }

    public function testGetListAsObject(): void
    {
        $field = 'id';
        $entities = $this->featureManager->getListAsObject($field);
        $featuresList = array_column($this->config->getData()->getFeatures(), null, $field);
        $this->assertEquals($featuresList, $entities);
    }

    public function testGetFeatureByKey(): void
    {
        $featureKey = 'feature-1';
        $featureId = '10024';
        $entity = $this->featureManager->getFeature($featureKey);
        $this->assertIsObject($entity);
        $this->assertEquals($featureId, $entity->getId());
    }

    public function testGetFeatureById(): void
    {
        $featureKey = 'feature-1';
        $featureId = '10024';
        $entity = $this->featureManager->getFeatureById($featureId);
        $this->assertIsObject($entity);
        $this->assertEquals($featureKey, $entity->getKey());
    }

    public function testGetFeaturesByKeys(): void
    {
        $featureKeys = ['feature-1', 'feature-2', 'not-attached-feature-3'];
        $entities = $this->featureManager->getFeatures($featureKeys);
        $this->assertIsArray($entities);
        $this->assertEquals($this->config->getData()->getFeatures(), $entities);
    }

    public function testGetFeatureVariableType(): void
    {
        $featureKey = 'feature-1';
        $variableName = 'enabled';
        $variableType = 'boolean';
        $type = $this->featureManager->getFeatureVariableType($featureKey, $variableName);
        $this->assertEquals($variableType, $type);
    }

    public function testGetFeatureVariableTypeById(): void
    {
        $featureId = '10024';
        $variableName = 'enabled';
        $variableType = 'boolean';
        $type = $this->featureManager->getFeatureVariableTypeById($featureId, $variableName);
        $this->assertEquals($variableType, $type);
    }

    public function testIsFeatureDeclared(): void
    {
        $featureKey = 'feature-1';
        $check = $this->featureManager->isFeatureDeclared($featureKey);
        $this->assertTrue($check);
    }

    public function testRunFeature(): void
    {
        $featureKey = 'feature-1';
        $featureIds = ['10024', '10025'];
        $features = $this->featureManager->runFeature(self::VISITOR_ID, $featureKey, new BucketingAttributes([
            'visitorProperties' => ['varName3' => 'something'],
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]));
        $this->assertIsArray($features);
        $this->assertCount(2, $features);
        $selectedFeatures = array_column($features, 'id');

        $this->assertContains($selectedFeatures[0], $featureIds);
        $this->assertContains($selectedFeatures[1], $featureIds);
    }

    public function testIsFeatureEnabled(): void
    {
        $featureKey = 'feature-1';
        $enabled = $this->featureManager->isFeatureEnabled(self::VISITOR_ID, $featureKey, new BucketingAttributes([
            'visitorProperties' => ['varName3' => 'something'],
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]));

        $this->assertTrue($enabled);
    }

    public function testRunFeatureById(): void
    {
        $featureId = '10024';
        $featureIds = ['10024', '10025'];
        $features = $this->featureManager->runFeatureById(self::VISITOR_ID, $featureId, new BucketingAttributes([
            'visitorProperties' => ['varName3' => 'something'],
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]));

        $this->assertIsArray($features);
        $this->assertCount(2, $features);
        $selectedFeatures = array_column($features, 'id');
        $this->assertContains($selectedFeatures[0], $featureIds);
        $this->assertContains($selectedFeatures[1], $featureIds);
    }

    public function testRunFeatures(): void
    {
        $filterByFeatures = ['feature-1', 'feature-2', 'not-attached-feature-3'];
        $filterByExperiences = ['test-experience-ab-fullstack-2', 'test-experience-ab-fullstack-3'];
        $featureIds = ['10024', '10025', '10026'];
        $features = $this->featureManager->runFeatures(self::VISITOR_ID, new BucketingAttributes([
            'visitorProperties' => ['varName3' => 'something'],
            'locationProperties' => ['url' => 'https://convert.com/'],
            'updateVisitorProperties' => false,
            'typeCasting' => true,
        ]), [
            'features' => $filterByFeatures,
            'experiences' => $filterByExperiences,
        ]);
        $this->assertIsArray($features);
        $this->assertCount(3, $features);
        $selectedFeatures = array_column($features, 'id');
        $this->assertContains($selectedFeatures[0], $featureIds);
        $this->assertContains($selectedFeatures[1], $featureIds);
        $this->assertContains($selectedFeatures[2], $featureIds);
    }

    public function testCastType(): void
    {
        $value = $this->featureManager->castType('123', 'integer');
        $this->assertIsInt($value);
        $this->assertEquals(123, $value);

        $value = $this->featureManager->castType(123, 'string');
        $this->assertIsString($value);
        $this->assertEquals('123', $value);

        $value = $this->featureManager->castType('1.23', 'float');
        $this->assertIsFloat($value);
        $this->assertEquals(1.23, $value);

        $value = $this->featureManager->castType('false', 'boolean');
        $this->assertIsBool($value);
        $this->assertFalse($value);
    }
}
