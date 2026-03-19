<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ConvertSdk\ExperienceManager;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\Messages;
use OpenAPI\Client\BucketingAttributes;

/**
 * Unit tests for ExperienceManager null-return reason logging (Story 4.2).
 */
class ExperienceManagerNullReturnLoggingTest extends TestCase
{
    private DataManagerInterface&MockObject $dataManager;
    private LogManagerInterface&MockObject $logManager;
    private ExperienceManager $experienceManager;

    protected function setUp(): void
    {
        $this->dataManager = $this->createMock(DataManagerInterface::class);
        $this->logManager = $this->createMock(LogManagerInterface::class);
        $this->experienceManager = new ExperienceManager(
            dataManager: $this->dataManager,
            logManager: $this->logManager,
        );
    }

    public function testSelectVariationLogsExperienceNotFoundWithAvailableKeys(): void
    {
        // getBucketing returns null (experience not found internally)
        $this->dataManager
            ->method('getBucketing')
            ->willReturn(null);

        // Post-bucketing getEntity check confirms experience doesn't exist
        $this->dataManager
            ->method('getEntity')
            ->with('nonexistent-key', 'experiences')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntitiesList')
            ->with('experiences')
            ->willReturn([
                ['key' => 'exp-alpha', 'id' => '100'],
                ['key' => 'exp-beta', 'id' => '200'],
            ]);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $result = $this->experienceManager->selectVariation(
            'visitor-123',
            'nonexistent-key',
            new BucketingAttributes([])
        );

        $this->assertNull($result);

        // Find the "not found" log call
        $notFoundCalls = array_filter($debugCalls, function ($call) {
            return $call['method'] === 'ExperienceManager.selectVariation()'
                && isset($call['data']['reason'])
                && $call['data']['reason'] === Messages::NULL_RETURN_EXPERIENCE_NOT_FOUND;
        });

        $this->assertNotEmpty($notFoundCalls, 'Expected debug log with experience not found reason');

        $logCall = reset($notFoundCalls);
        $this->assertSame('nonexistent-key', $logCall['data']['experienceKey']);
        $this->assertSame('visitor-123', $logCall['data']['visitorId']);
        $this->assertContains('exp-alpha', $logCall['data']['availableKeys']);
        $this->assertContains('exp-beta', $logCall['data']['availableKeys']);
    }

    public function testSelectVariationLogsVisitorNotQualifiedWhenBucketingReturnsNull(): void
    {
        $this->dataManager
            ->method('getBucketing')
            ->willReturn(null);

        // Post-bucketing getEntity check confirms experience exists (so reason is "not qualified")
        $this->dataManager
            ->method('getEntity')
            ->with('geo-test', 'experiences')
            ->willReturn(['id' => '100', 'key' => 'geo-test', 'name' => 'Geo Test']);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $result = $this->experienceManager->selectVariation(
            'visitor-456',
            'geo-test',
            new BucketingAttributes([])
        );

        $this->assertNull($result);

        // Find the null-return reason log
        $nullReturnCalls = array_filter($debugCalls, function ($call) {
            return $call['method'] === 'ExperienceManager.selectVariation()'
                && isset($call['data']['reason'])
                && $call['data']['reason'] === Messages::NULL_RETURN_VISITOR_NOT_QUALIFIED;
        });

        $this->assertNotEmpty($nullReturnCalls, 'Expected debug log with visitor not qualified reason');

        $logCall = reset($nullReturnCalls);
        $this->assertSame('visitor-456', $logCall['data']['visitorId']);
        $this->assertSame('geo-test', $logCall['data']['experienceKey']);
        $this->assertSame('null', $logCall['data']['resultType']);
    }

    public function testSelectVariationLogsTrafficAllocationReasonForBucketingError(): void
    {
        $this->dataManager
            ->method('getBucketing')
            ->willReturn(BucketingError::VariationNotDecided);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $result = $this->experienceManager->selectVariation(
            'visitor-789',
            'limited-test',
            new BucketingAttributes([])
        );

        $this->assertSame(BucketingError::VariationNotDecided, $result);

        $trafficCalls = array_filter($debugCalls, function ($call) {
            return $call['method'] === 'ExperienceManager.selectVariation()'
                && isset($call['data']['reason'])
                && $call['data']['reason'] === Messages::NULL_RETURN_TRAFFIC_ALLOCATION;
        });

        $this->assertNotEmpty($trafficCalls, 'Expected debug log with traffic allocation reason');
    }

    public function testSelectVariationByIdLogsExperienceNotFoundWithAvailableIds(): void
    {
        $this->dataManager
            ->method('getBucketingById')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntityById')
            ->with('999', 'experiences')
            ->willReturn(null);

        $this->dataManager
            ->method('getEntitiesList')
            ->with('experiences')
            ->willReturn([
                ['id' => '100', 'key' => 'exp-alpha'],
                ['id' => '200', 'key' => 'exp-beta'],
            ]);

        $debugCalls = [];
        $this->logManager->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function (string $method, array $data) use (&$debugCalls): void {
                $debugCalls[] = ['method' => $method, 'data' => $data];
            });

        $result = $this->experienceManager->selectVariationById(
            'visitor-123',
            '999',
            new BucketingAttributes([])
        );

        $this->assertNull($result);

        $notFoundCalls = array_filter($debugCalls, function ($call) {
            return $call['method'] === 'ExperienceManager.selectVariationById()'
                && isset($call['data']['reason'])
                && $call['data']['reason'] === Messages::NULL_RETURN_EXPERIENCE_NOT_FOUND;
        });

        $this->assertNotEmpty($notFoundCalls, 'Expected debug log with experience not found reason');

        $logCall = reset($notFoundCalls);
        $this->assertContains('100', $logCall['data']['availableIds']);
        $this->assertContains('200', $logCall['data']['availableIds']);
    }

    public function testSelectVariationWithNullLogManagerNoException(): void
    {
        $em = new ExperienceManager(dataManager: $this->dataManager);

        $this->dataManager
            ->method('getBucketing')
            ->willReturn(null);

        $result = $em->selectVariation('visitor-1', 'nonexistent', new BucketingAttributes([]));
        $this->assertNull($result);
    }

    public function testSelectVariationByIdWithNullLogManagerNoException(): void
    {
        $em = new ExperienceManager(dataManager: $this->dataManager);

        $this->dataManager
            ->method('getBucketingById')
            ->willReturn(null);

        $result = $em->selectVariationById('visitor-1', '999', new BucketingAttributes([]));
        $this->assertNull($result);
    }

    public function testSelectVariationReturnsNullNotExceptionForBusinessLogicMisses(): void
    {
        // AC #5: null-return path returns null, never an exception
        $this->dataManager
            ->method('getBucketing')
            ->willReturn(null);

        $result = $this->experienceManager->selectVariation(
            'visitor-1',
            'nonexistent',
            new BucketingAttributes([])
        );

        $this->assertNull($result);
    }
}
