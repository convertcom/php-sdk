<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Enums\GoalDataKey;
use PHPUnit\Framework\TestCase;

class GoalDataKeyTest extends TestCase
{
    /**
     * Test 6.10: GoalDataKey enum has all 8 cases (including 5 custom dimensions)
     */
    public function testGoalDataKeyHasEightCases(): void
    {
        $cases = GoalDataKey::cases();
        $this->assertCount(8, $cases);
    }

    public function testOriginalThreeCasesExist(): void
    {
        $this->assertEquals('amount', GoalDataKey::Amount->value);
        $this->assertEquals('productsCount', GoalDataKey::ProductsCount->value);
        $this->assertEquals('transactionId', GoalDataKey::TransactionId->value);
    }

    public function testCustomDimensionCasesExist(): void
    {
        $this->assertEquals('customDimension1', GoalDataKey::CustomDimension1->value);
        $this->assertEquals('customDimension2', GoalDataKey::CustomDimension2->value);
        $this->assertEquals('customDimension3', GoalDataKey::CustomDimension3->value);
        $this->assertEquals('customDimension4', GoalDataKey::CustomDimension4->value);
        $this->assertEquals('customDimension5', GoalDataKey::CustomDimension5->value);
    }

    public function testFromValueWorksForAllCases(): void
    {
        $expected = [
            'amount' => GoalDataKey::Amount,
            'productsCount' => GoalDataKey::ProductsCount,
            'transactionId' => GoalDataKey::TransactionId,
            'customDimension1' => GoalDataKey::CustomDimension1,
            'customDimension2' => GoalDataKey::CustomDimension2,
            'customDimension3' => GoalDataKey::CustomDimension3,
            'customDimension4' => GoalDataKey::CustomDimension4,
            'customDimension5' => GoalDataKey::CustomDimension5,
        ];

        foreach ($expected as $value => $case) {
            $this->assertEquals($case, GoalDataKey::from($value));
        }
    }
}
