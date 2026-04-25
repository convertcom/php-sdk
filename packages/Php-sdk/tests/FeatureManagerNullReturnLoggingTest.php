<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Enums\Messages;
use ConvertSdk\FeatureManager;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use OpenAPI\Client\BucketingAttributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FeatureManager null-return reason logging (Story 4.2).
 */
class FeatureManagerNullReturnLoggingTest extends TestCase
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

    public function testRunFeatureLogsFeatureNotFoundWithAvailableKeys(): void
    {
        $this->dataManager
            ->method('getEntity')
            ->with('nonexistent-feature', 'features')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntitiesList')
            ->with('features')
            ->willReturn([
                ['key' => 'dark-mode', 'id' => '100'],
                ['key' => 'beta-ui', 'id' => '200'],
            ]);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $result = $this->featureManager->runFeature(
            'visitor-123',
            'nonexistent-feature',
            new BucketingAttributes([])
        );

        $this->assertSame(FeatureStatus::Disabled->value, $result['status']);

        // Find the "not found" log call
        $notFoundCalls = array_filter($debugCalls, function ($call) {
            return $call['method'] === 'FeatureManager.runFeature()'
                && isset($call['data']['reason'])
                && $call['data']['reason'] === Messages::NULL_RETURN_FEATURE_NOT_FOUND;
        });

        $this->assertNotEmpty($notFoundCalls, 'Expected debug log with feature not found reason');

        $logCall = reset($notFoundCalls);
        $this->assertSame('nonexistent-feature', $logCall['data']['featureKey']);
        $this->assertContains('dark-mode', $logCall['data']['availableKeys']);
        $this->assertContains('beta-ui', $logCall['data']['availableKeys']);
    }

    public function testRunFeatureByIdLogsFeatureNotFoundWithAvailableIds(): void
    {
        $this->dataManager
            ->method('getEntityById')
            ->with('999', 'features')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntitiesList')
            ->with('features')
            ->willReturn([
                ['id' => '100', 'key' => 'dark-mode'],
                ['id' => '200', 'key' => 'beta-ui'],
            ]);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $result = $this->featureManager->runFeatureById(
            'visitor-123',
            '999',
            new BucketingAttributes([])
        );

        $this->assertSame(FeatureStatus::Disabled->value, $result['status']);

        $notFoundCalls = array_filter($debugCalls, function ($call) {
            return $call['method'] === 'FeatureManager.runFeatureById()'
                && isset($call['data']['reason'])
                && $call['data']['reason'] === Messages::NULL_RETURN_FEATURE_NOT_FOUND;
        });

        $this->assertNotEmpty($notFoundCalls, 'Expected debug log with feature not found reason');

        $logCall = reset($notFoundCalls);
        $this->assertContains('100', $logCall['data']['availableIds']);
        $this->assertContains('200', $logCall['data']['availableIds']);
    }

    public function testIsFeatureEnabledLogsFeatureNotFoundWithAvailableKeys(): void
    {
        $this->dataManager
            ->method('getEntity')
            ->with('nonexistent-feature', 'features')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntitiesList')
            ->with('features')
            ->willReturn([
                ['key' => 'dark-mode', 'id' => '100'],
            ]);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $result = $this->featureManager->isFeatureEnabled(
            'visitor-123',
            'nonexistent-feature',
            new BucketingAttributes([])
        );

        $this->assertFalse($result);

        $notFoundCalls = array_filter($debugCalls, function ($call) {
            return $call['method'] === 'FeatureManager.isFeatureEnabled()'
                && isset($call['data']['reason'])
                && $call['data']['reason'] === Messages::NULL_RETURN_FEATURE_NOT_FOUND;
        });

        $this->assertNotEmpty($notFoundCalls, 'Expected debug log with feature not found reason');
    }

    public function testNoExceptionWhenLogManagerIsNullRunFeature(): void
    {
        $fm = new FeatureManager(
            dataManager: $this->dataManager,
            logManager: null,
        );

        $this->dataManager
            ->method('getEntity')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntitiesList')
            ->willReturn([]);

        $result = $fm->runFeature(
            'visitor-123',
            'nonexistent',
            new BucketingAttributes([])
        );

        $this->assertIsArray($result);
        $this->assertSame(FeatureStatus::Disabled->value, $result['status']);
    }

    public function testNoExceptionWhenLogManagerIsNullIsFeatureEnabled(): void
    {
        $fm = new FeatureManager(
            dataManager: $this->dataManager,
            logManager: null,
        );

        $this->dataManager
            ->method('getEntity')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntitiesList')
            ->willReturn([]);

        $result = $fm->isFeatureEnabled(
            'visitor-123',
            'nonexistent',
            new BucketingAttributes([])
        );

        $this->assertFalse($result);
    }

    public function testRunFeatureReturnsArrayNotExceptionForBusinessLogicMisses(): void
    {
        // AC #5: runFeature never throws for business logic misses
        $this->dataManager
            ->method('getEntity')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntitiesList')
            ->willReturn([]);

        $result = $this->featureManager->runFeature(
            'visitor-1',
            'nonexistent',
            new BucketingAttributes([])
        );

        $this->assertIsArray($result);
        $this->assertSame(FeatureStatus::Disabled->value, $result['status']);
    }
}
