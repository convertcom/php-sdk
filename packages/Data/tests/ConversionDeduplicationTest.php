<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\DataManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\RuleManager;
use ConvertSdk\Event\EventManager;
use ConvertSdk\LogManager;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Interfaces\ApiManagerInterface;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use ConvertSdk\Config\DefaultConfig;
use PHPUnit\Framework\TestCase;

class ConversionDeduplicationTest extends TestCase
{
    private Config $config;
    private DataManager $dataManager;
    private string $visitorId = 'dedup-test-visitor';
    private string $accountId;
    private string $projectId;

    /** @var ApiManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private ApiManagerInterface $apiManagerMock;

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $overrides = [
            'api' => [
                'endpoint' => [
                    'config' => 'http://localhost:8090',
                    'track' => 'http://localhost:8090'
                ]
            ],
            'events' => [
                'batch_size' => 10,
                'release_interval' => 1000
            ]
        ];
        $mergedConfig = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig, $overrides);
        $mergedConfig['data'] = new ConfigResponseData($mergedConfig['data']);
        if (isset($mergedConfig['sdkKey'])) {
            unset($mergedConfig['sdkKey']);
        }
        $this->config = new Config($mergedConfig);

        $bucketingConfig = $this->config->getBucketing();
        $bucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $ruleManager = new RuleManager();
        $eventManager = new EventManager();
        $loggerManager = new LogManager($this->config);

        // Mock ApiManager to avoid PHP 8.4 end() deprecation on objects
        $this->apiManagerMock = $this->createMock(ApiManagerInterface::class);

        $this->accountId = $this->config->getData()->getAccountId();
        $project = $this->config->getData() ? $this->config->getData()->getProject() : null;
        $this->projectId = $project ? (is_array($project) ? ($project['id'] ?? '') : ($project->getId() ?? '')) : '';

        $this->dataManager = new DataManager(
            $this->config,
            $bucketingManager,
            $ruleManager,
            $eventManager,
            $this->apiManagerMock,
            $loggerManager,
            true
        );
    }

    protected function tearDown(): void
    {
        $this->dataManager->reset();
    }

    /**
     * Test 6.1: First trackConversion sends conversion event
     */
    public function testFirstConversionEnqueuesEvent(): void
    {
        $goalKey = 'goal-without-rule';

        $this->apiManagerMock->expects($this->once())
            ->method('enqueue')
            ->with(
                $this->equalTo($this->visitorId),
                $this->callback(function (VisitorTrackingEvents $event) {
                    return $event->getEventType() === SystemEvents::Conversion->value
                        && $event->getData() !== null;
                }),
                $this->anything()
            );

        $result = $this->dataManager->convert($this->visitorId, $goalKey);
        $this->assertTrue($result);
    }

    /**
     * Test 6.2: Second trackConversion for same visitor+goal is deduplicated
     */
    public function testSecondConversionIsDeduplicated(): void
    {
        $goalKey = 'goal-without-rule';

        // First call — should enqueue
        $this->apiManagerMock->expects($this->once())
            ->method('enqueue');

        $result1 = $this->dataManager->convert($this->visitorId, $goalKey);
        $this->assertTrue($result1);

        // Second call — should be deduplicated (enqueue not called again)
        $result2 = $this->dataManager->convert($this->visitorId, $goalKey);
        $this->assertTrue($result2); // Returns true (deduped, not an error)
    }

    /**
     * Test 6.3: trackConversion with non-existent goal returns false
     */
    public function testNonExistentGoalReturnsFalse(): void
    {
        $this->apiManagerMock->expects($this->never())
            ->method('enqueue');

        $result = $this->dataManager->convert($this->visitorId, 'nonexistent-goal-key');
        $this->assertFalse($result);
    }

    /**
     * Test 6.4: trackConversion with goalData sends BOTH conversion AND transaction events
     */
    public function testConversionWithGoalDataSendsBothEvents(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [['key' => 'amount', 'value' => 10.5]];

        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue');

        $result = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            null,
            $goalData
        );
        $this->assertTrue($result);
    }

    /**
     * Test 6.5: Conversion event includes bucketingData from visitor's active experiments
     */
    public function testConversionIncludesBucketingData(): void
    {
        $goalKey = 'goal-without-rule';
        $bucketingData = ['exp1' => 'var1', 'exp2' => 'var2'];

        // Pre-populate bucketing data for the visitor
        $this->dataManager->putData($this->visitorId, ['bucketing' => $bucketingData]);

        $capturedData = null;
        $this->apiManagerMock->expects($this->once())
            ->method('enqueue')
            ->with(
                $this->equalTo($this->visitorId),
                $this->callback(function (VisitorTrackingEvents $event) use (&$capturedData) {
                    $capturedData = $event->getData();
                    return true;
                }),
                $this->anything()
            );

        $result = $this->dataManager->convert($this->visitorId, $goalKey);
        $this->assertTrue($result);
        $this->assertNotNull($capturedData);
        $this->assertIsArray($capturedData);
        $this->assertArrayHasKey('bucketingData', $capturedData);
        $this->assertEquals($bucketingData, $capturedData['bucketingData']);
    }

    /**
     * Test 6.6: Dedup storage key uses {accountId}-{projectId}-{visitorId} format
     */
    public function testDedupStorageKeyFormat(): void
    {
        $expectedKey = "{$this->accountId}-{$this->projectId}-{$this->visitorId}";
        $actualKey = $this->dataManager->getStoreKey($this->visitorId);
        $this->assertEquals($expectedKey, $actualKey);
    }

    /**
     * Test 6.7: SystemEvents::Conversion event fires on successful tracking
     * (Verified via the event type in the enqueued payload)
     */
    public function testConversionEventType(): void
    {
        $goalKey = 'goal-without-rule';

        $capturedEventType = null;
        $this->apiManagerMock->expects($this->once())
            ->method('enqueue')
            ->with(
                $this->anything(),
                $this->callback(function (VisitorTrackingEvents $event) use (&$capturedEventType) {
                    $capturedEventType = $event->getEventType();
                    return true;
                }),
                $this->anything()
            );

        $this->dataManager->convert($this->visitorId, $goalKey);
        $this->assertNotNull($capturedEventType);
        $this->assertEquals(SystemEvents::Conversion->value, $capturedEventType);
    }

    /**
     * Test 6.8: Goal with rules validates ruleData via RuleManager
     */
    public function testGoalWithRulesValidatesRuleData(): void
    {
        $goalKey = 'increase-engagement';

        // Matching rule — should succeed
        $this->apiManagerMock->expects($this->once())
            ->method('enqueue');

        $result = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            ['action' => 'buy']
        );
        $this->assertTrue($result);
    }

    /**
     * Test: Goal with rules rejects mismatched ruleData
     */
    public function testGoalWithRulesRejectsMismatchedData(): void
    {
        $goalKey = 'increase-engagement';

        $this->apiManagerMock->expects($this->never())
            ->method('enqueue');

        $result = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            ['action' => 'sell']
        );
        $this->assertFalse($result);
    }

    /**
     * Test: putData stores goals correctly and getData retrieves them
     */
    public function testPutDataStoresGoals(): void
    {
        $this->dataManager->putData($this->visitorId, ['goals' => ['goal1' => true]]);
        $data = $this->dataManager->getData($this->visitorId);
        $this->assertNotNull($data);
        $this->assertTrue($data['goals']['goal1']);
    }

    /**
     * Test: Different visitors have independent deduplication
     */
    public function testDifferentVisitorsIndependentDedup(): void
    {
        $goalKey = 'goal-without-rule';
        $visitor2 = 'dedup-test-visitor-2';

        // Both visitors should trigger conversion (2 enqueue calls)
        $this->apiManagerMock->expects($this->exactly(2))
            ->method('enqueue');

        $result1 = $this->dataManager->convert($this->visitorId, $goalKey);
        $result2 = $this->dataManager->convert($visitor2, $goalKey);
        $this->assertTrue($result1);
        $this->assertTrue($result2);
    }

    /**
     * Test: forceMultipleTransactions allows repeat transaction events
     */
    public function testForceMultipleTransactionsAllowsRepeat(): void
    {
        $goalKey = 'goal-without-rule';
        $goalData = [['key' => 'amount', 'value' => 20.0]];
        $conversionSetting = ['forceMultipleTransactions' => true];

        // First call: 1 conversion + 1 transaction = 2 enqueue calls
        // Second call with force: 1 transaction = 1 enqueue call
        // Total: 3 enqueue calls
        $this->apiManagerMock->expects($this->exactly(3))
            ->method('enqueue');

        $result1 = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            null,
            $goalData,
            null,
            $conversionSetting
        );
        $this->assertTrue($result1);

        $result2 = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            null,
            $goalData,
            null,
            $conversionSetting
        );
        $this->assertTrue($result2);
    }

    /**
     * Test: Repeat trigger without force and no goalData sends nothing
     */
    public function testRepeatTriggerWithoutForceSendsNothing(): void
    {
        $goalKey = 'goal-without-rule';

        // First call triggers 1 enqueue
        $this->apiManagerMock->expects($this->once())
            ->method('enqueue');

        $this->dataManager->convert($this->visitorId, $goalKey);

        // Second call — deduplicated, no enqueue
        $result = $this->dataManager->convert($this->visitorId, $goalKey);
        $this->assertTrue($result);
    }
}
