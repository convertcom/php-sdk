<?php

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\ApiManager;
use ConvertSdk\EventManager;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\LogLevel;
use GuzzleHttp\Client;

// Include DefaultConfig

class ApiManagerTest extends TestCase
{
    private $host = 'http://localhost';
    private $port = 8090;
    private $releaseTimeout = 1000; // in milliseconds
    private $testTimeout = 2000;    // in milliseconds (releaseTimeout + 1000)
    private $batchSize = 5;

    /** @var ApiManager */
    private $apiManager;

    /** @var EventManager */
    private $eventManager;

    private $configuration;
    private $serverPid;

    protected function setUp(): void
    {
        // Load the test-config.json file
        $configPath = __DIR__ . '/test-config.json';
        if (!file_exists($configPath)) {
            $this->fail('Configuration file not found');
        }

        $testConfig = json_decode(file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('Invalid JSON in test-config.json: ' . json_last_error_msg());
        }

        // Merge testConfig with defaultConfig using ObjectUtils::objectDeepMerge
        $this->configuration = ObjectUtils::objectDeepMerge($testConfig, self::getDefault(), [
            'api' => [
                'endpoint' => [
                    'config' => $this->host . ':' . $this->port,
                    'track'  => $this->host . ':' . $this->port
                ]
            ],
            'events' => [
                'batch_size' => $this->batchSize,
                'release_interval' => $this->releaseTimeout
            ]
        ]);

        // Initialize EventManager and ApiManager without creating a new Config instance
        $this->eventManager = new EventManager($this->configuration);
        $this->apiManager = new ApiManager($this->configuration, ['eventManager' => $this->eventManager]);

    }

    public static function getDefault(): array
    {
        return [
            'api' => [
                'endpoint' => [
                    'config' => 'https://cdn-4.convertexperiments.com/api/v1/',
                    'track'  => 'https://100415443.metrics.convertexperiments.com/v1/'
                ]
            ],
            'environment' => 'staging',
            'bucketing' => [
                'max_traffic' => 10000,
                'hash_seed'   => 9999
            ],
            'data' => [],
            'dataStore' => null, // Allows 3rd party data store to be passed.
            'dataRefreshInterval' => 300000, // in milliseconds (5 minutes)
            'events' => [
                'batch_size' => 10,
                'release_interval' => 1000
            ],
            'logger' => [
                'logLevel' => LogLevel::DEBUG,
                'customLoggers' => [] // Allows 3rd party loggers to be passed.
            ],
            'rules' => [
                'keys_case_sensitive' => true,
                'comparisonProcessor' => null // Allows 3rd party comparison processor.
            ],
            'network' => [
                'tracking' => true,
                'cacheLevel' => 'default' // Can be set to 'low' for short-lived cache.
            ],
            'sdkKey' => '',
            'sdkKeySecret' => ''
        ];
    }

    public function testShouldExposeApiManager(): void
    {
        $this->assertTrue(class_exists(ApiManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfApiManagerInstance(): void
    {
        $reflection = new \ReflectionClass($this->apiManager);
        $this->assertEquals('ApiManager', $reflection->getShortName());
    }

    public function testShouldSuccessfullyCreateNewApiManagerInstanceWithDefaultConfig(): void
    {
        $apiManager = new ApiManager($this->configuration);
        $reflection = new \ReflectionClass($apiManager);
        $this->assertEquals('ApiManager', $reflection->getShortName());
    }

    public function testShouldCreateNewApiManagerInstanceWithVisitorProvidedConfigurationAndEventManagerDependency(): void
    {
        $apiManager = new ApiManager($this->configuration, ['eventManager' => $this->eventManager]);
        $reflection = new \ReflectionClass($apiManager);
        $this->assertEquals('ApiManager', $reflection->getShortName());
    }

}
