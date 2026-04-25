<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\DTO\GoalData;
use ConvertSdk\Enums\GoalDataKey;
use PHPUnit\Framework\TestCase;

class GoalDataTest extends TestCase
{
    /**
     * Test 7.4: GoalData DTO is readonly and accepts GoalDataKey + int|float|string
     */
    public function testGoalDataIsReadonly(): void
    {
        $reflection = new \ReflectionClass(GoalData::class);
        $this->assertTrue($reflection->isReadonly());
    }

    public function testGoalDataWithFloatValue(): void
    {
        $goalData = new GoalData(GoalDataKey::Amount, 99.99);
        $this->assertSame(GoalDataKey::Amount, $goalData->key);
        $this->assertSame(99.99, $goalData->value);
    }

    public function testGoalDataWithStringValue(): void
    {
        $goalData = new GoalData(GoalDataKey::TransactionId, 'txn-abc-123');
        $this->assertSame(GoalDataKey::TransactionId, $goalData->key);
        $this->assertSame('txn-abc-123', $goalData->value);
    }

    public function testGoalDataWithIntValue(): void
    {
        $goalData = new GoalData(GoalDataKey::ProductsCount, 5);
        $this->assertSame(GoalDataKey::ProductsCount, $goalData->key);
        $this->assertSame(5, $goalData->value);
    }

    public function testGoalDataWithAllCustomDimensions(): void
    {
        $dimensions = [
            GoalDataKey::CustomDimension1,
            GoalDataKey::CustomDimension2,
            GoalDataKey::CustomDimension3,
            GoalDataKey::CustomDimension4,
            GoalDataKey::CustomDimension5,
        ];

        foreach ($dimensions as $i => $key) {
            $goalData = new GoalData($key, "dim-value-{$i}");
            $this->assertSame($key, $goalData->key);
            $this->assertSame("dim-value-{$i}", $goalData->value);
        }
    }

    public function testGoalDataNamespace(): void
    {
        $reflection = new \ReflectionClass(GoalData::class);
        $this->assertEquals('ConvertSdk\DTO', $reflection->getNamespaceName());
    }
}
