<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\ExperienceManager;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use OpenAPI\Client\BucketingAttributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test suite for ExperienceManager.
 *
 * Tests the thin orchestrator layer that delegates to DataManager.
 * All DataManager interactions are mocked to isolate ExperienceManager logic.
 */
class ExperienceManagerUnitTest extends TestCase
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

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(ExperienceManager::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testConstructorUsesTypedParameters(): void
    {
        $reflection = new \ReflectionClass(ExperienceManager::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertSame('dataManager', $params[0]->getName());
        $this->assertSame('logManager', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
    }

    public function testSelectVariationReturnsResult(): void
    {
        $expectedResult = [
            'experienceId' => '100218245',
            'experienceName' => 'Test Experience',
            'experienceKey' => 'pricing-test',
            'bucketingAllocation' => 5000,
            'id' => '100299456',
            'key' => '100299456-variation-1',
            'name' => 'Variation 1',
            'changes' => [],
            'traffic_allocation' => 50,
            'status' => 'active',
        ];

        $this->dataManager
            ->expects($this->once())
            ->method('getBucketing')
            ->with('visitor-456', 'pricing-test', $this->isInstanceOf(BucketingAttributes::class))
            ->willReturn($expectedResult);

        $result = $this->experienceManager->selectVariation(
            'visitor-456',
            'pricing-test',
            new BucketingAttributes([])
        );

        $this->assertIsArray($result);
        $this->assertSame('pricing-test', $result['experienceKey']);
        $this->assertSame('100299456', $result['id']);
    }

    public function testSelectVariationDelegatesToDataManager(): void
    {
        $attributes = new BucketingAttributes([
            'visitorProperties' => ['plan' => 'premium'],
        ]);

        $this->dataManager
            ->expects($this->once())
            ->method('getBucketing')
            ->with('visitor-789', 'my-experiment', $attributes)
            ->willReturn(null);

        $result = $this->experienceManager->selectVariation('visitor-789', 'my-experiment', $attributes);
        $this->assertNull($result);
    }

    public function testSelectVariationReturnsRuleErrorFromDataManager(): void
    {
        $this->dataManager
            ->expects($this->once())
            ->method('getBucketing')
            ->willReturn(RuleError::NoDataFound);

        $result = $this->experienceManager->selectVariation(
            'visitor-1',
            'test-exp',
            new BucketingAttributes([])
        );

        $this->assertSame(RuleError::NoDataFound, $result);
    }

    public function testSelectVariationReturnsBucketingErrorFromDataManager(): void
    {
        $this->dataManager
            ->expects($this->once())
            ->method('getBucketing')
            ->willReturn(BucketingError::VariationNotDecided);

        $result = $this->experienceManager->selectVariation(
            'visitor-1',
            'test-exp',
            new BucketingAttributes([])
        );

        $this->assertSame(BucketingError::VariationNotDecided, $result);
    }

    public function testSelectVariationsFiltersErrors(): void
    {
        $validVariation = [
            'experienceId' => '100',
            'experienceKey' => 'exp-1',
            'id' => '200',
            'key' => 'var-1',
            'changes' => [],
        ];

        $dataManager = $this->createMock(DataManagerInterface::class);
        $dataManager
            ->method('getEntitiesList')
            ->with('experiences')
            ->willReturn([
                ['key' => 'exp-1'],
                ['key' => 'exp-2'],
                ['key' => 'exp-3'],
            ]);

        $dataManager
            ->method('getBucketing')
            ->willReturnCallback(function (string $visitorId, string $key) use ($validVariation): array|RuleError|BucketingError|null {
                return match ($key) {
                    'exp-1' => $validVariation,
                    'exp-2' => RuleError::NoDataFound,
                    'exp-3' => BucketingError::VariationNotDecided,
                    default => null,
                };
            });

        $em = new ExperienceManager(dataManager: $dataManager);
        $results = $em->selectVariations('visitor-1', new BucketingAttributes([]));

        $this->assertCount(1, $results);
        $this->assertSame('exp-1', $results[0]['experienceKey']);
    }

    public function testSelectVariationsReturnsEmptyArrayWhenNoExperiences(): void
    {
        $this->dataManager
            ->method('getEntitiesList')
            ->with('experiences')
            ->willReturn([]);

        $results = $this->experienceManager->selectVariations('visitor-1', new BucketingAttributes([]));

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    public function testGetExperienceDelegatesToDataManager(): void
    {
        $entityData = ['id' => '100', 'key' => 'my-exp', 'name' => 'My Experiment'];

        $this->dataManager
            ->expects($this->once())
            ->method('getEntity')
            ->with('my-exp', 'experiences')
            ->willReturn($entityData);

        $result = $this->experienceManager->getExperience('my-exp');
        $this->assertNotNull($result);
        $this->assertSame('100', $result['id']);
    }

    public function testGetExperienceReturnsNullWhenNotFound(): void
    {
        $this->dataManager
            ->expects($this->once())
            ->method('getEntity')
            ->with('nonexistent', 'experiences')
            ->willReturn(null);

        $result = $this->experienceManager->getExperience('nonexistent');
        $this->assertNull($result);
    }

    public function testSelectVariationsReindexesArray(): void
    {
        $variation1 = [
            'experienceId' => '100',
            'experienceKey' => 'exp-1',
            'id' => '200',
            'key' => 'var-1',
            'changes' => [],
        ];
        $variation2 = [
            'experienceId' => '101',
            'experienceKey' => 'exp-3',
            'id' => '201',
            'key' => 'var-2',
            'changes' => [],
        ];

        $this->dataManager = $this->createMock(DataManagerInterface::class);
        $this->dataManager
            ->method('getEntitiesList')
            ->willReturn([
                ['key' => 'exp-1'],
                ['key' => 'exp-2'],
                ['key' => 'exp-3'],
            ]);

        $this->dataManager
            ->method('getBucketing')
            ->willReturnCallback(fn (string $v, string $key) => match ($key) {
                'exp-1' => $variation1,
                'exp-2' => null,
                'exp-3' => $variation2,
                default => null,
            });

        $this->experienceManager = new ExperienceManager(dataManager: $this->dataManager);

        $results = $this->experienceManager->selectVariations('visitor-1', new BucketingAttributes([]));

        $this->assertCount(2, $results);
        // Verify array is 0-indexed (re-indexed after filtering)
        $this->assertArrayHasKey(0, $results);
        $this->assertArrayHasKey(1, $results);
        $this->assertSame('exp-1', $results[0]['experienceKey']);
        $this->assertSame('exp-3', $results[1]['experienceKey']);
    }

    public function testCanBeConstructedWithoutLogManager(): void
    {
        $em = new ExperienceManager(dataManager: $this->dataManager);
        $this->assertInstanceOf(ExperienceManager::class, $em);
    }

    public function testSelectVariationLogsDebugEntryAndResult(): void
    {
        $this->dataManager
            ->method('getBucketing')
            ->willReturn(['id' => '200', 'key' => 'var-1', 'experienceKey' => 'exp-1', 'changes' => []]);

        $this->logManager->expects($this->atLeast(2))
            ->method('debug')
            ->with(
                $this->equalTo('ExperienceManager.selectVariation()'),
                $this->isType('array')
            );

        $this->experienceManager->selectVariation('visitor-1', 'exp-1', new BucketingAttributes([]));
    }

    public function testSelectVariationsLogsDebugWithCounts(): void
    {
        $this->dataManager
            ->method('getEntitiesList')
            ->with('experiences')
            ->willReturn([['key' => 'exp-1']]);

        $this->dataManager
            ->method('getBucketing')
            ->willReturn(['id' => '200', 'key' => 'var-1', 'experienceKey' => 'exp-1', 'changes' => []]);

        $this->logManager->expects($this->atLeastOnce())
            ->method('debug');

        $results = $this->experienceManager->selectVariations('visitor-1', new BucketingAttributes([]));

        $this->assertCount(1, $results);
    }

    public function testSelectVariationWithNullLogManagerNoException(): void
    {
        $em = new ExperienceManager(dataManager: $this->dataManager);

        $this->dataManager
            ->method('getBucketing')
            ->willReturn(null);

        $result = $em->selectVariation('visitor-1', 'exp-1', new BucketingAttributes([]));
        $this->assertNull($result);
    }
}
