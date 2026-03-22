<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\DataStoreManager;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\TestCase;

/**
 * A simple test implementation of a DataStore with get and set methods.
 */
class TestDataStore
{
    private $data = [];

    /**
     * Retrieves data by key or all data if no key is provided.
     *
     * @param string|null $key
     * @return mixed
     */
    public function get($key = null)
    {
        if ($key === null) {
            return $this->data;
        }
        return $this->data[$key] ?? null;
    }

    /**
     * Sets data for a given key.
     *
     * @param string $key
     * @param mixed $value
     * @throws \InvalidArgumentException
     */
    public function set($key, $value)
    {
        if ($key === null) {
            throw new \InvalidArgumentException('Invalid DataStore key!');
        }
        $this->data[$key] = $value;
    }
}

/**
 * Test class for DataStoreManager.
 */
class DataStoreManagerTest extends TestCase
{
    /** @var TestDataStore */
    private $dataStore;

    /** @var DataStoreManager */
    private $dataStoreManager;

    /** @var string */
    private $storeKey = 'test-key';

    /** @var array */
    private $storeData = [
        'bucketing' => [
            'exp1' => 'var1',
            'exp2' => 'var2',
        ],
        'goals' => [
            'goal1' => true,
            'goal2' => true,
        ],
        'segments' => [
            'browser' => 'CH',
            'devices' => 'ALLPH',
            'source' => 'test',
            'campaign' => 'test',
            'visitorType' => 'new',
            'country' => 'US',
            'custom_segments' => ['seg1', 'seg2'],
        ],
    ];

    /**
     * Sets up the test environment before each test.
     */
    protected function setUp(): void
    {
        // Load test configuration from JSON file
        $testConfigPath = __DIR__ . '/test-config.json';
        $testConfig = file_exists($testConfigPath)
            ? json_decode(file_get_contents($testConfigPath), true)
            : [];

        // Get default configuration
        $defaultConfig = DefaultConfig::getDefault();
        // Merge configurations with overrides
        $configuration = ObjectUtils::objectDeepMerge(
            $testConfig,
            $defaultConfig
        );

        if (isset($configuration['sdkKey'])) {
            unset($configuration['sdkKey']);
        }
        $configuration['data'] = new ConfigResponseData($configuration['data']);
        // Instantiate Config object
        $config = new Config($configuration);

        // Create dependencies
        $this->dataStore = new TestDataStore();

        // Instantiate DataStoreManager
        $this->dataStoreManager = new DataStoreManager($config, [
            'dataStore' => $this->dataStore,
        ]);
    }

    /**
     * Tests that the DataStoreManager class is exposed.
     */
    public function testShouldExposeDataStoreManager(): void
    {
        $this->assertTrue(class_exists(DataStoreManager::class));
    }

    /**
     * Tests that the instantiated object is an instance of DataStoreManager.
     */
    public function testImportedEntityShouldBeAConstructorOfDataStoreManagerInstance(): void
    {
        $this->assertInstanceOf(DataStoreManager::class, $this->dataStoreManager);
    }

    /**
     * Tests that visitor data can be set and retrieved immediately.
     */
    public function testShouldSuccessfullySetVisitorDataImmediately(): void
    {
        $this->dataStoreManager->set($this->storeKey, $this->storeData);
        $retrieved = $this->dataStoreManager->get($this->storeKey);
        $this->assertEquals($this->storeData, $retrieved);
    }

    /**
     * Tests that the visitor data has the correct structure.
     */
    public function testShouldHaveTheCorrectShapeForVisitorData(): void
    {
        $this->dataStoreManager->set($this->storeKey, $this->storeData);
        $retrieved = $this->dataStoreManager->get($this->storeKey);

        $this->assertIsArray($retrieved);
        $this->assertArrayHasKey('bucketing', $retrieved);
        $this->assertEquals($this->storeData['bucketing'], $retrieved['bucketing']);
        $this->assertArrayHasKey('goals', $retrieved);
        $this->assertEquals($this->storeData['goals'], $retrieved['goals']);
        $this->assertArrayHasKey('segments', $retrieved);
        $this->assertEquals($this->storeData['segments'], $retrieved['segments']);
    }
}
