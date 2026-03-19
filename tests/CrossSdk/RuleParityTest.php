<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\CrossSdk;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ConvertSdk\RuleManager;
use ConvertSdk\Utils\Comparisons;
use OpenAPI\Client\Model\RuleObject;

/**
 * Cross-SDK rule evaluation parity tests.
 *
 * Validates that the PHP RuleManager and Comparisons produce
 * identical output to the JS SDK for all test vectors.
 */
class RuleParityTest extends TestCase
{
    private static array $vectors = [];

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/rule-test-vectors.json';
        self::$vectors = json_decode(file_get_contents($path), true);
    }

    // ---- Comparison operator parity tests ----

    public static function comparisonVectorProvider(): iterable
    {
        $path = __DIR__ . '/rule-test-vectors.json';
        $vectors = json_decode(file_get_contents($path), true);

        foreach ($vectors['comparison_operators'] as $group) {
            foreach ($group['cases'] as $i => $case) {
                $label = sprintf(
                    '%s: %s (#%d)',
                    $group['method'],
                    $case['note'],
                    $i
                );
                yield $label => [
                    $group['method'],
                    $case['value'],
                    $case['testAgainst'],
                    $case['negation'],
                    $case['expected'],
                ];
            }
        }
    }

    #[DataProvider('comparisonVectorProvider')]
    public function testComparisonOperatorParity(
        string $method,
        mixed $value,
        mixed $testAgainst,
        bool $negation,
        bool $expected
    ): void {
        $result = Comparisons::$method($value, $testAgainst, $negation);
        $this->assertSame(
            $expected,
            $result,
            sprintf(
                'Comparisons::%s(%s, %s, %s) returned %s, expected %s',
                $method,
                var_export($value, true),
                var_export($testAgainst, true),
                $negation ? 'true' : 'false',
                var_export($result, true),
                var_export($expected, true)
            )
        );
    }

    // ---- Rule evaluation parity tests ----

    public static function ruleVectorProvider(): iterable
    {
        $path = __DIR__ . '/rule-test-vectors.json';
        $vectors = json_decode(file_get_contents($path), true);

        foreach ($vectors['rule_evaluation'] as $i => $vector) {
            $label = sprintf('%s: %s', $vector['category'], $vector['description']);
            yield $label => [
                $vector['data'],
                $vector['ruleSet'],
                $vector['expected'],
                $vector['keysCaseSensitive'] ?? true,
            ];
        }
    }

    #[DataProvider('ruleVectorProvider')]
    public function testRuleEvaluationMatchesExpected(
        array $data,
        array $ruleSet,
        bool $expected,
        bool $keysCaseSensitive = true
    ): void {
        $ruleManager = new RuleManager(keysCaseSensitive: $keysCaseSensitive);
        $result = $ruleManager->isRuleMatched($data, new RuleObject($ruleSet));
        $this->assertSame(
            $expected,
            $result,
            sprintf(
                'Rule evaluation for "%s" data returned %s, expected %s',
                json_encode($data),
                var_export($result, true),
                var_export($expected, true)
            )
        );
    }

    // ---- Structural tests ----

    public function testAllComparisonCategoriesPresent(): void
    {
        $categories = array_column(self::$vectors['comparison_operators'], 'category');
        $required = ['equals', 'equalsNumber', 'matches', 'less', 'lessEqual', 'contains', 'isIn', 'startsWith', 'endsWith', 'regexMatches'];

        foreach ($required as $category) {
            $this->assertContains($category, $categories, "Missing comparison category: $category");
        }
    }

    public function testAllRuleEvaluationCategoriesPresent(): void
    {
        $categories = array_column(self::$vectors['rule_evaluation'], 'category');
        $required = ['equals_operator', 'regex_operator', 'and_group_partial', 'or_group_single_match', 'negation_operator', 'missing_key'];

        foreach ($required as $category) {
            $this->assertContains($category, $categories, "Missing rule evaluation category: $category");
        }
    }

    public function testComparisonVectorMinimumCount(): void
    {
        $totalCases = 0;
        foreach (self::$vectors['comparison_operators'] as $group) {
            $totalCases += count($group['cases']);
        }
        // At least 3 cases per operator * 10 operators = 30 minimum
        $this->assertGreaterThanOrEqual(30, $totalCases);
    }

    public function testRuleEvaluationVectorMinimumCount(): void
    {
        $this->assertGreaterThanOrEqual(10, count(self::$vectors['rule_evaluation']));
    }
}
