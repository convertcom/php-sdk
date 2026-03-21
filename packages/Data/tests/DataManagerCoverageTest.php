<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\DataManager;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Event\EventManager;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use ConvertSdk\Utils\ObjectUtils;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenAPI\Client\Config;
use OpenAPI\Client\LocationAttributes;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\TestCase;

/**
 * Minimal DataStore mock matching the contract validated by DataStoreManager::isValidDataStore()
 * (requires get/set methods). Aligned with DataStoreMock in DataManagerTest.php.
 */
class DataManagerCoverageDataStoreMock
{
    private array $data = [];

    public function get($key)
    {
        return $key ? ($this->data[$key] ?? null) : $this->data;
    }

    public function set($key, $value): void
    {
        if (!$key) {
            throw new \Exception('Invalid DataStore key!');
        }
        $this->data[$key] = $value;
    }

    public function enqueue($key, $value): void
    {
        $this->data[$key] = $value;
    }
}

/**
 * Tests for DataManager methods with zero/low coverage:
 * selectLocations, filterMatchedCustomSegments, setDataStoreManager, getDataStoreManager
 */
class DataManagerCoverageTest extends TestCase
{
    private Config $config;
    private BucketingManager $bucketingManager;
    private RuleManager $ruleManager;
    private EventManager $eventManager;
    private ApiManager $apiManager;
    private LogManager $loggerManager;
    private DataManager $dataManager;
    private string $visitorId = 'test-visitor-coverage';

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $overrides = [
            'api' => [
                'endpoint' => [
                    'config' => 'http://127.0.0.1:9501',
                    'track' => 'http://127.0.0.1:9501',
                ],
            ],
            'events' => [
                'batch_size' => 10,
                'release_interval' => 1000,
            ],
        ];
        $mergedConfig = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, $overrides);
        $mergedConfig['data'] = new ConfigResponseData($mergedConfig['data']);
        if (isset($mergedConfig['sdkKey'])) {
            unset($mergedConfig['sdkKey']);
        }
        $this->config = new Config($mergedConfig);

        $mockHttpClient = new MockHttpClient();
        $psr17Factory = new Psr17Factory();

        $bucketingConfig = $this->config->getBucketing();
        $this->bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $this->ruleManager = new RuleManager();
        $this->eventManager = new EventManager();
        $this->apiManager = new ApiManager(
            $this->config,
            $this->eventManager,
            null,
            $mockHttpClient,
            $psr17Factory,
            $psr17Factory
        );
        $this->loggerManager = new LogManager();

        $this->dataManager = new DataManager(
            $this->config,
            $this->bucketingManager,
            $this->ruleManager,
            $this->eventManager,
            $this->apiManager,
            $this->loggerManager,
            true
        );
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
    }

    // ---- selectLocations tests ----

    public function testSelectLocationsShouldMatchLocationByUrlRule(): void
    {
        $items = [
            [
                'id' => 'loc-1',
                'key' => 'homepage',
                'name' => 'Homepage',
                'rules' => [
                    'OR' => [
                        ['AND' => [
                            ['OR_WHEN' => [
                                [
                                    'rule_type' => 'generic_key_value',
                                    'matching' => ['match_type' => 'matches', 'negated' => false],
                                    'key' => 'url',
                                    'value' => 'https://convert.com/',
                                ],
                            ]],
                        ]],
                    ],
                ],
            ],
        ];

        $attributes = new LocationAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]);

        $result = $this->dataManager->selectLocations($this->visitorId, $items, $attributes);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('homepage', $result[0]['key']);
    }

    public function testSelectLocationsShouldDeactivateLocationOnMismatch(): void
    {
        // First, activate a location
        $items = [
            [
                'id' => 'loc-1',
                'key' => 'homepage',
                'name' => 'Homepage',
                'rules' => [
                    'OR' => [
                        ['AND' => [
                            ['OR_WHEN' => [
                                [
                                    'rule_type' => 'generic_key_value',
                                    'matching' => ['match_type' => 'matches', 'negated' => false],
                                    'key' => 'url',
                                    'value' => 'https://convert.com/',
                                ],
                            ]],
                        ]],
                    ],
                ],
            ],
        ];

        $attributes = new LocationAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]);

        // Activate the location first
        $this->dataManager->selectLocations($this->visitorId, $items, $attributes);

        // Now with non-matching URL, it should deactivate
        $deactivatedFired = false;
        $this->eventManager->on(SystemEvents::LocationDeactivated, function () use (&$deactivatedFired) {
            $deactivatedFired = true;
        });

        $attributes2 = new LocationAttributes([
            'locationProperties' => ['url' => 'https://other.com/'],
        ]);

        $result = $this->dataManager->selectLocations($this->visitorId, $items, $attributes2);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
        $this->assertTrue($deactivatedFired);
    }

    public function testSelectLocationsShouldReturnEmptyForEmptyItems(): void
    {
        $attributes = new LocationAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]);

        $result = $this->dataManager->selectLocations($this->visitorId, [], $attributes);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSelectLocationsShouldSkipItemsWithNoRules(): void
    {
        $items = [
            ['id' => 'loc-1', 'key' => 'no-rules', 'name' => 'No Rules'],
        ];

        $attributes = new LocationAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
        ]);

        $result = $this->dataManager->selectLocations($this->visitorId, $items, $attributes);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSelectLocationsShouldFireActivatedOnForceEvent(): void
    {
        $activatedFired = false;
        $this->eventManager->on(SystemEvents::LocationActivated, function () use (&$activatedFired) {
            $activatedFired = true;
        });

        $items = [
            [
                'id' => 'loc-1',
                'key' => 'homepage',
                'name' => 'Homepage',
                'rules' => [
                    'OR' => [
                        ['AND' => [
                            ['OR_WHEN' => [
                                [
                                    'rule_type' => 'generic_key_value',
                                    'matching' => ['match_type' => 'matches', 'negated' => false],
                                    'key' => 'url',
                                    'value' => 'https://convert.com/',
                                ],
                            ]],
                        ]],
                    ],
                ],
            ],
        ];

        $attributes = new LocationAttributes([
            'locationProperties' => ['url' => 'https://convert.com/'],
            'forceEvent' => true,
        ]);

        $this->dataManager->selectLocations($this->visitorId, $items, $attributes);
        $this->assertTrue($activatedFired);
    }

    // ---- filterMatchedCustomSegments tests ----

    public function testFilterMatchedCustomSegmentsShouldReturnMatchingSegments(): void
    {
        // Store custom segments for the visitor
        $this->dataManager->putData($this->visitorId, [
            'segments' => [
                'custom_segments' => ['seg-100', 'seg-200'],
            ],
        ]);

        $items = [
            ['id' => 'seg-100', 'name' => 'Segment A'],
            ['id' => 'seg-300', 'name' => 'Segment C'],
            ['id' => 'seg-200', 'name' => 'Segment B'],
        ];

        $result = $this->dataManager->filterMatchedCustomSegments($items, $this->visitorId);
        $this->assertCount(2, $result);
        $this->assertSame('seg-100', $result[0]['id']);
        $this->assertSame('seg-200', $result[1]['id']);
    }

    public function testFilterMatchedCustomSegmentsShouldReturnEmptyForNoSegments(): void
    {
        $items = [
            ['id' => 'seg-100', 'name' => 'Segment A'],
        ];

        $result = $this->dataManager->filterMatchedCustomSegments($items, $this->visitorId);
        $this->assertEmpty($result);
    }

    public function testFilterMatchedCustomSegmentsShouldSkipItemsWithNoId(): void
    {
        $this->dataManager->putData($this->visitorId, [
            'segments' => ['custom_segments' => ['seg-100']],
        ]);

        $items = [
            ['name' => 'No ID item'],
            ['id' => 'seg-100', 'name' => 'With ID'],
        ];

        $result = $this->dataManager->filterMatchedCustomSegments($items, $this->visitorId);
        $this->assertCount(1, $result);
    }

    public function testFilterMatchedCustomSegmentsShouldReturnEmptyForEmptyItems(): void
    {
        $result = $this->dataManager->filterMatchedCustomSegments([], $this->visitorId);
        $this->assertEmpty($result);
    }

    // ---- setDataStoreManager / getDataStoreManager tests ----

    public function testSetDataStoreManagerShouldCreateDataStoreManager(): void
    {
        $dataStore = new DataManagerCoverageDataStoreMock();
        $this->dataManager->setDataStoreManager($dataStore);
        $this->assertNotNull($this->dataManager->getDataStoreManager());
    }

    public function testSetDataStoreManagerWithNullShouldClearManager(): void
    {
        $this->dataManager->setDataStoreManager(null);
        $this->assertNull($this->dataManager->getDataStoreManager());
    }

    public function testGetDataStoreManagerShouldReturnNullByDefault(): void
    {
        // DataManager created without dataStore
        $this->assertNull($this->dataManager->getDataStoreManager());
    }
}
