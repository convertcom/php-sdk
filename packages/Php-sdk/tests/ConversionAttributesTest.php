<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\DTO\ConversionAttributes;
use PHPUnit\Framework\TestCase;

class ConversionAttributesTest extends TestCase
{
    /**
     * Test 6.9: ConversionAttributes DTO is readonly with correct nullable properties
     */
    public function testConversionAttributesIsReadonly(): void
    {
        $reflection = new \ReflectionClass(ConversionAttributes::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testAllPropertiesAreNullable(): void
    {
        $dto = new ConversionAttributes();
        $this->assertNull($dto->ruleData);
        $this->assertNull($dto->conversionData);
        $this->assertNull($dto->conversionSetting);
    }

    public function testConstructorWithAllParameters(): void
    {
        $ruleData = ['action' => 'buy'];
        $conversionData = [['key' => 'amount', 'value' => 10.5]];
        $conversionSetting = ['forceMultipleTransactions' => true];

        $dto = new ConversionAttributes(
            ruleData: $ruleData,
            conversionData: $conversionData,
            conversionSetting: $conversionSetting,
        );

        $this->assertEquals($ruleData, $dto->ruleData);
        $this->assertEquals($conversionData, $dto->conversionData);
        $this->assertEquals($conversionSetting, $dto->conversionSetting);
    }

    public function testConstructorWithPartialParameters(): void
    {
        $dto = new ConversionAttributes(
            ruleData: ['action' => 'signup'],
        );

        $this->assertEquals(['action' => 'signup'], $dto->ruleData);
        $this->assertNull($dto->conversionData);
        $this->assertNull($dto->conversionSetting);
    }

    public function testHasCorrectNamespace(): void
    {
        $reflection = new \ReflectionClass(ConversionAttributes::class);
        $this->assertEquals('ConvertSdk\DTO', $reflection->getNamespaceName());
    }

    public function testPropertyCount(): void
    {
        $reflection = new \ReflectionClass(ConversionAttributes::class);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        $this->assertCount(3, $properties);
    }
}
