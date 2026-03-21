<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Context;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\ExperienceManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Context null-return contract.
 *
 * Verifies that Context::runExperience() returns null for all non-success
 * paths (RuleError, BucketingError, null from ExperienceManager).
 * Uses mocked ExperienceManager to isolate Context behavior.
 */
class ContextNullReturnTest extends TestCase
{
    private Config $config;
    private EventManagerInterface&MockObject $eventManager;
    private ExperienceManagerInterface&MockObject $experienceManager;
    private FeatureManagerInterface&MockObject $featureManager;
    private DataManagerInterface&MockObject $dataManager;
    private SegmentsManagerInterface&MockObject $segmentsManager;
    private ApiManagerInterface&MockObject $apiManager;

    protected function setUp(): void
    {
        $testConfig = json_decode(file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $configuration = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig);
        $configuration['data'] = new ConfigResponseData($configuration['data']);
        if (isset($configuration['sdkKey'])) {
            unset($configuration['sdkKey']);
        }

        $this->config = new Config($configuration);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->experienceManager = $this->createMock(ExperienceManagerInterface::class);
        $this->featureManager = $this->createMock(FeatureManagerInterface::class);
        $this->dataManager = $this->createMock(DataManagerInterface::class);
        $this->segmentsManager = $this->createMock(SegmentsManagerInterface::class);
        $this->apiManager = $this->createMock(ApiManagerInterface::class);

        // DataManager::getData() returns null by default (no stored visitor data)
        $this->dataManager->method('getData')->willReturn(null);
    }

    private function createContext(string $visitorId = 'visitor-123'): Context
    {
        return new Context(
            $this->config,
            $visitorId,
            $this->eventManager,
            $this->experienceManager,
            $this->featureManager,
            $this->dataManager,
            $this->segmentsManager,
            $this->apiManager,
        );
    }

    public function testRunExperienceReturnsNullForRuleError(): void
    {
        $this->experienceManager
            ->method('selectVariation')
            ->willReturn(RuleError::NoDataFound);

        $this->eventManager
            ->expects($this->never())
            ->method('fire');

        $context = $this->createContext();
        $result = $context->runExperience('some-experience');

        $this->assertNull($result, 'Context must return null when ExperienceManager returns RuleError');
    }

    public function testRunExperienceReturnsNullForNeedMoreDataRuleError(): void
    {
        $this->experienceManager
            ->method('selectVariation')
            ->willReturn(RuleError::NeedMoreData);

        $this->eventManager
            ->expects($this->never())
            ->method('fire');

        $context = $this->createContext();
        $result = $context->runExperience('some-experience');

        $this->assertNull($result, 'Context must return null for RuleError::NeedMoreData');
    }

    public function testRunExperienceReturnsNullForBucketingError(): void
    {
        $this->experienceManager
            ->method('selectVariation')
            ->willReturn(BucketingError::VariationNotDecided);

        $this->eventManager
            ->expects($this->never())
            ->method('fire');

        $context = $this->createContext();
        $result = $context->runExperience('some-experience');

        $this->assertNull($result, 'Context must return null when ExperienceManager returns BucketingError');
    }

    public function testRunExperienceReturnsNullWhenExperienceManagerReturnsNull(): void
    {
        $this->experienceManager
            ->method('selectVariation')
            ->willReturn(null);

        $this->eventManager
            ->expects($this->never())
            ->method('fire');

        $context = $this->createContext();
        $result = $context->runExperience('nonexistent-key');

        $this->assertNull($result);
    }

    public function testRunExperienceReturnsDtoForSuccessfulBucketing(): void
    {
        $this->experienceManager
            ->method('selectVariation')
            ->willReturn([
                'experienceId' => '100',
                'experienceKey' => 'test-exp',
                'experienceName' => 'Test',
                'bucketingAllocation' => 5000,
                'id' => '200',
                'key' => 'var-1',
                'name' => 'Variation 1',
                'changes' => [],
                'traffic_allocation' => 50,
                'status' => 'active',
            ]);

        $this->eventManager
            ->expects($this->once())
            ->method('fire');

        $context = $this->createContext();
        $result = $context->runExperience('test-exp');

        $this->assertInstanceOf(BucketedVariation::class, $result);
        $this->assertSame('100', $result->experienceId);
        $this->assertSame('test-exp', $result->experienceKey);
        $this->assertSame('200', $result->variationId);
        $this->assertSame('var-1', $result->variationKey);
        $this->assertIsArray($result->changes);
    }

    public function testRunExperienceDoesNotFireEventOnRuleError(): void
    {
        $this->experienceManager
            ->method('selectVariation')
            ->willReturn(RuleError::NoDataFound);

        $this->eventManager
            ->expects($this->never())
            ->method('fire');

        $context = $this->createContext();
        $context->runExperience('test-exp');
    }

    public function testRunExperienceFiresEventOnSuccess(): void
    {
        $this->experienceManager
            ->method('selectVariation')
            ->willReturn([
                'experienceId' => '100',
                'experienceKey' => 'test-exp',
                'id' => '200',
                'key' => 'var-1',
                'changes' => [],
            ]);

        $this->eventManager
            ->expects($this->once())
            ->method('fire');

        $context = $this->createContext();
        $context->runExperience('test-exp');
    }
}
