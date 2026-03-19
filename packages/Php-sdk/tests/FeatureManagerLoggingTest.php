<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ConvertSdk\FeatureManager;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Enums\FeatureStatus;
use OpenAPI\Client\BucketingAttributes;

/**
 * Unit tests for FeatureManager debug logging behavior (Story 4.1).
 */
class FeatureManagerLoggingTest extends TestCase
{
    private DataManagerInterface&MockObject $dataManager;
    private LogManagerInterface&MockObject $logManager;
    private FeatureManager $featureManager;

    protected function setUp(): void
    {
        $this->dataManager = $this->createMock(DataManagerInterface::class);
        $this->logManager = $this->createMock(LogManagerInterface::class);
        $this->featureManager = new FeatureManager(
            dataManager: $this->dataManager,
            logManager: $this->logManager,
        );
    }

    public function testRunFeatureLogsDebugWithFeatureKeyAndStatus(): void
    {
        $this->dataManager
            ->method('getEntity')
            ->with('dark-mode', 'features')
            ->willReturn(['id' => '100', 'name' => 'Dark Mode', 'key' => 'dark-mode']);

        $this->dataManager
            ->method('getEntitiesListObject')
            ->willReturn([]);

        $this->dataManager
            ->method('getEntitiesList')
            ->willReturn([]);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $this->featureManager->runFeature(
            'visitor-456',
            'dark-mode',
            new BucketingAttributes([])
        );

        $runFeatureCalls = array_filter($debugCalls, fn($c) => $c['method'] === 'FeatureManager.runFeature()');
        $this->assertNotEmpty($runFeatureCalls, 'Expected at least one debug call with FeatureManager.runFeature()');

        // Verify entry log has visitorId and featureKey
        $entryCall = reset($runFeatureCalls);
        $this->assertArrayHasKey('visitorId', $entryCall['data']);
        $this->assertArrayHasKey('featureKey', $entryCall['data']);
    }

    public function testRunFeaturesLogsDebugWithSummaryCounts(): void
    {
        $this->dataManager
            ->method('getEntitiesListObject')
            ->willReturn([]);

        $this->dataManager
            ->method('getEntitiesList')
            ->willReturn([]);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $this->featureManager->runFeatures(
            'visitor-456',
            new BucketingAttributes([])
        );

        $runFeaturesCalls = array_filter($debugCalls, fn($c) => $c['method'] === 'FeatureManager.runFeatures()');
        $this->assertNotEmpty($runFeaturesCalls, 'Expected at least one debug call with FeatureManager.runFeatures()');

        // Verify summary log has counts
        $summaryCalls = array_filter($runFeaturesCalls, fn($c) => array_key_exists('totalFeatures', $c['data']));
        $this->assertNotEmpty($summaryCalls, 'Expected summary log with totalFeatures count');
    }

    public function testNoExceptionWhenLogManagerIsNull(): void
    {
        $featureManager = new FeatureManager(
            dataManager: $this->dataManager,
            logManager: null,
        );

        $this->dataManager
            ->method('getEntity')
            ->willReturn(null);

        $result = $featureManager->runFeature(
            'visitor-123',
            'nonexistent-feature',
            new BucketingAttributes([])
        );

        $this->assertIsArray($result);
        $this->assertEquals(FeatureStatus::Disabled->value, $result['status']);
    }
}
