<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ConvertSdk\DataManager;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\BucketingManagerInterface;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\RuleManagerInterface;
use ConvertSdk\Enums\Messages;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;

/**
 * Unit tests for DataManager entity lookup failure logging (Story 4.2).
 */
class DataManagerLoggingTest extends TestCase
{
    private LogManagerInterface&MockObject $logManager;
    private DataManager $dataManager;

    /** @var list<list<mixed>> Captured debug() call arguments */
    private array $debugCalls = [];

    protected function setUp(): void
    {
        $this->debugCalls = [];
        $this->logManager = $this->createMock(LogManagerInterface::class);

        // Capture all debug calls with their arguments
        $this->logManager
            ->method('debug')
            ->willReturnCallback(function () {
                $this->debugCalls[] = func_get_args();
            });

        $configData = new ConfigResponseData([
            'account_id' => 'test-account',
            'project' => ['id' => 'test-project'],
            'experiences' => [
                ['id' => '100', 'key' => 'exp-alpha', 'name' => 'Alpha Test', 'variations' => []],
                ['id' => '200', 'key' => 'exp-beta', 'name' => 'Beta Test', 'variations' => []],
            ],
            'features' => [
                ['id' => '300', 'key' => 'feat-dark-mode', 'name' => 'Dark Mode'],
            ],
            'goals' => [],
            'audiences' => [],
            'locations' => [],
            'segments' => [],
            'archived_experiences' => [],
        ]);

        $config = new Config([
            'environment' => 'staging',
            'data' => $configData,
        ]);

        $this->dataManager = new DataManager(
            $config,
            $this->createMock(BucketingManagerInterface::class),
            $this->createMock(RuleManagerInterface::class),
            $this->createMock(EventManagerInterface::class),
            $this->createMock(ApiManagerInterface::class),
            $this->logManager,
            false
        );
    }

    private function findDebugCallsForMethod(string $methodName, string $message): array
    {
        return array_values(array_filter($this->debugCalls, function (array $call) use ($methodName, $message) {
            return ($call[0] ?? null) === $methodName && ($call[1] ?? null) === $message;
        }));
    }

    public function testGetEntityLogsDebugWhenEntityNotFound(): void
    {
        $result = $this->dataManager->getEntity('nonexistent-key', 'experiences');

        $this->assertNull($result);

        $calls = $this->findDebugCallsForMethod('DataManager._getEntityByField()', Messages::ENTITY_LOOKUP_FAILED);
        $this->assertNotEmpty($calls, 'Expected debug log for entity not found');

        // Third argument is the mapper output (identity function returns array as-is)
        $context = $calls[0][2];
        $this->assertIsArray($context);
        $this->assertSame('nonexistent-key', $context['searchedFor']);
        $this->assertSame('experiences', $context['entityType']);
        $this->assertSame('key', $context['identityField']);
        $this->assertIsArray($context['availableKeys']);
        $this->assertCount(2, $context['availableKeys']);
        $this->assertContains('exp-alpha', $context['availableKeys']);
        $this->assertContains('exp-beta', $context['availableKeys']);
    }

    public function testGetEntityByIdLogsDebugWhenEntityNotFound(): void
    {
        $result = $this->dataManager->getEntityById('999', 'experiences');

        $this->assertNull($result);

        $calls = $this->findDebugCallsForMethod('DataManager._getEntityByField()', Messages::ENTITY_LOOKUP_FAILED);
        $this->assertNotEmpty($calls, 'Expected debug log for entity not found by ID');

        $context = $calls[0][2];
        $this->assertSame('999', $context['searchedFor']);
        $this->assertSame('id', $context['identityField']);
        // When searching by ID, availableKeys contains IDs
        $this->assertContains('100', $context['availableKeys']);
        $this->assertContains('200', $context['availableKeys']);
    }

    public function testGetSubItemLogsDebugWhenSubEntityNotFound(): void
    {
        // Experience 'exp-alpha' exists but variation 'nonexistent-var' does not
        $result = $this->dataManager->getSubItem(
            'experiences',
            'exp-alpha',
            'variations',
            'nonexistent-var',
            'key',
            'key'
        );

        $this->assertNull($result);

        $calls = $this->findDebugCallsForMethod('DataManager.getSubItem()', Messages::ENTITY_LOOKUP_FAILED);
        $this->assertNotEmpty($calls, 'Expected debug log for sub-item not found');

        $context = $calls[0][2];
        $this->assertIsArray($context);
        $this->assertSame('experiences', $context['entityType']);
        $this->assertSame('exp-alpha', $context['entityIdentity']);
        $this->assertSame('variations', $context['subEntityType']);
        $this->assertSame('nonexistent-var', $context['subEntityIdentity']);
        $this->assertTrue($context['parentFound']);
    }

    public function testGetSubItemDoesNotDoubleLogWhenParentMissing(): void
    {
        $result = $this->dataManager->getSubItem(
            'experiences',
            'nonexistent-exp',
            'variations',
            'var-1',
            'key',
            'key'
        );

        $this->assertNull($result);

        // getSubItem should NOT log when parent is missing — _getEntityByField already logged it
        $subItemCalls = $this->findDebugCallsForMethod('DataManager.getSubItem()', Messages::ENTITY_LOOKUP_FAILED);
        $this->assertEmpty($subItemCalls, 'getSubItem should NOT double-log when parent not found');

        // But _getEntityByField SHOULD have logged
        $entityCalls = $this->findDebugCallsForMethod('DataManager._getEntityByField()', Messages::ENTITY_LOOKUP_FAILED);
        $this->assertNotEmpty($entityCalls, '_getEntityByField should log parent not found');
    }

    public function testGetEntityDoesNotLogEntityNotFoundWhenEntityExists(): void
    {
        $result = $this->dataManager->getEntity('exp-alpha', 'experiences');

        $this->assertNotNull($result);

        $calls = $this->findDebugCallsForMethod('DataManager._getEntityByField()', Messages::ENTITY_LOOKUP_FAILED);
        $this->assertEmpty($calls, 'Should NOT log entity not found when entity exists');
    }

    public function testEntityNotFoundLogIncludesCorrectEntityType(): void
    {
        $result = $this->dataManager->getEntity('nonexistent', 'features');

        $this->assertNull($result);

        $calls = $this->findDebugCallsForMethod('DataManager._getEntityByField()', Messages::ENTITY_LOOKUP_FAILED);
        $this->assertNotEmpty($calls);

        $context = $calls[0][2];
        $this->assertSame('features', $context['entityType']);
        $this->assertContains('feat-dark-mode', $context['availableKeys']);
    }
}
