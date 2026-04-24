<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\RuleManager;
use OpenAPI\Client\Model\RuleElement;
use OpenAPI\Client\Model\RuleObject;
use PHPUnit\Framework\TestCase;

class RuleManagerTest extends TestCase
{
    protected RuleManager $ruleManager;

    protected function setUp(): void
    {
        $this->ruleManager = new RuleManager();
    }

    // ----- Class structure tests -----

    public function testShouldExposeRuleManager(): void
    {
        $this->assertTrue(class_exists(RuleManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfRuleManagerInstance(): void
    {
        $rm = new RuleManager();
        $reflection = new \ReflectionClass($rm);
        $this->assertEquals('RuleManager', $reflection->getShortName());
    }

    public function testRuleManagerIsFinal(): void
    {
        $reflection = new \ReflectionClass(RuleManager::class);
        $this->assertTrue($reflection->isFinal(), 'RuleManager must be a final class');
    }

    // ----- Tests for RuleManager with custom comparison processor -----

    public function testRuleManagerWithCustomComparisonProcessor_InstanceCreation(): void
    {
        $customComparisonProcessor = [
            'isTypeOf' => function ($value, $testAgainst, $negation = false) {
                $actualType = gettype($value);
                if ($actualType === 'integer' || $actualType === 'double') {
                    $actualType = 'number';
                }
                if ($negation) {
                    return $actualType !== $testAgainst;
                }
                return $actualType === $testAgainst;
            },
        ];

        $rm = new RuleManager(
            comparisonProcessor: $customComparisonProcessor,
            keysCaseSensitive: false,
        );
        $reflection = new \ReflectionClass($rm);
        $this->assertEquals('RuleManager', $reflection->getShortName());
    }

    public function testCustomComparisonProcessorIsUsed(): void
    {
        $this->assertIsArray($this->ruleManager->getComparisonProcessor());
    }

    public function testGetComparisonProcessorMethodsWithCustomProcessor(): void
    {
        $customComparisonProcessor = [
            'isTypeOf' => function ($value, $testAgainst, $negation = false) {
                $actualType = gettype($value);
                if ($actualType === 'integer' || $actualType === 'double') {
                    $actualType = 'number';
                }
                if ($negation) {
                    return $actualType !== $testAgainst;
                }
                return $actualType === $testAgainst;
            },
        ];

        $rm = new RuleManager(
            comparisonProcessor: $customComparisonProcessor,
            keysCaseSensitive: false,
        );

        $methods = $rm->getComparisonProcessorMethods();
        $expected = array_filter(array_keys($customComparisonProcessor), function ($name) use ($customComparisonProcessor) {
            return is_callable($customComparisonProcessor[$name]);
        });
        sort($methods);
        sort($expected);
        $this->assertEquals($expected, $methods);
    }

    public function testIsRuleMatchedWithCustomComparisonProcessor(): void
    {
        $customComparisonProcessor = [
            'isTypeOf' => function ($value, $testAgainst, $negation = false) {
                $actualType = gettype($value);
                if ($actualType === 'integer' || $actualType === 'double') {
                    $actualType = 'number';
                }
                if ($negation) {
                    return $actualType !== $testAgainst;
                }
                return $actualType === $testAgainst;
            },
        ];

        $rm = new RuleManager(
            comparisonProcessor: $customComparisonProcessor,
            keysCaseSensitive: false,
        );

        $testRuleSet1 = [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => 'sum',
                                    'matching' => [
                                        'match_type' => 'isTypeOf',
                                        'negated' => false,
                                    ],
                                    'value' => 'number',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $testRuleSet2 = [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => 'sum',
                                    'matching' => [
                                        'match_type' => 'isTypeOf',
                                        'negated' => true,
                                    ],
                                    'value' => 'number',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $testRuleSet3 = [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => 'SUM',
                                    'matching' => [
                                        'match_type' => 'isTypeOf',
                                        'negated' => false,
                                    ],
                                    'value' => 'number',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $data1 = ['sum' => 'not a number'];
        $data2 = ['sum' => 44];

        $this->assertFalse($rm->isRuleMatched($data1, new RuleObject($testRuleSet1)), 'Expected false for data1 against testRuleSet1');
        $this->assertTrue($rm->isRuleMatched($data2, new RuleObject($testRuleSet1)), 'Expected true for data2 against testRuleSet1');
        $this->assertTrue($rm->isRuleMatched($data1, new RuleObject($testRuleSet2)), 'Expected true for data1 against testRuleSet2 with negation');
        $this->assertFalse($rm->isRuleMatched($data2, new RuleObject($testRuleSet2)), 'Expected false for data2 against testRuleSet2 with negation');
        $this->assertTrue($rm->isRuleMatched($data2, new RuleObject($testRuleSet3)), 'Expected true for data2 against testRuleSet3 with case-insensitive keys');
    }

    // ----- Tests for RuleManager with default comparison processor -----

    public function testRuleManagerWithDefaultComparisonProcessor(): void
    {
        $rm = new RuleManager();
        $reflection = new \ReflectionClass($rm);
        $this->assertEquals('RuleManager', $reflection->getShortName());
    }

    public function testIsValidRule(): void
    {
        $validRule = [
            'key' => 'device',
            'matching' => [
                'match_type' => 'contains',
                'negated' => false,
            ],
            'value' => 'phone',
        ];
        $this->assertTrue($this->ruleManager->isValidRule(new RuleElement($validRule)));

        $badStructure = [
            'matching' => 'contains',
            'data' => 'phone',
        ];
        $this->assertFalse($this->ruleManager->isValidRule(new RuleElement($badStructure)));

        $missingMatching = [
            'key' => 'device',
            'value' => 'phone',
        ];
        $this->assertFalse($this->ruleManager->isValidRule(new RuleElement($missingMatching)));

        $missingValue = [
            'key' => 'device',
            'matching' => [
                'match_type' => 'contains',
                'negated' => false,
            ],
        ];
        $this->assertFalse($this->ruleManager->isValidRule(new RuleElement($missingValue)));
    }

    public function testRuleManagerWithDefaultComparisonProcessorIsRuleMatched(): void
    {
        $rm = new RuleManager();

        $testRuleSet1 = [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => 'device',
                                    'matching' => [
                                        'match_type' => 'equals',
                                        'negated' => false,
                                    ],
                                    'value' => 'pc',
                                ],
                                [
                                    'key' => 'price',
                                    'matching' => [
                                        'match_type' => 'less',
                                        'negated' => false,
                                    ],
                                    'value' => 100,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $testRuleSet2 = [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => 'device',
                                    'matching' => [
                                        'match_type' => 'equals',
                                        'negated' => true,
                                    ],
                                    'value' => 'pc',
                                ],
                                [
                                    'key' => 'device',
                                    'matching' => [
                                        'match_type' => 'isIn',
                                        'negated' => false,
                                    ],
                                    'value' => 'phone|tablet',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $testRuleSet3 = [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => 'device',
                                    'matching' => [
                                        'match_type' => 'isIn',
                                        'negated' => false,
                                    ],
                                    'value' => 'phone|tablet',
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => 'age',
                                    'matching' => [
                                        'match_type' => 'less',
                                        'negated' => true,
                                    ],
                                    'value' => 30,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $data1 = ['device' => 'pc', 'browser' => 'Mozilla', 'price' => 3];
        $data12 = ['device' => 'tablet', 'browser' => 'Mozilla', 'price' => 3];
        $data13 = ['DEVICE' => 'tablet', 'BROWSER' => 'Mozilla', 'PRICE' => 3];
        $data2 = ['browser' => 'Chrome'];
        $data21 = 'phone';
        $data22 = ['device' => 'phone'];
        $data31 = ['device' => 'tablet', 'browser' => 'Mozilla', 'age' => 10];
        $data32 = ['device' => 'pc', 'browser' => 'Chrome', 'age' => 31];
        $this->assertTrue($rm->isRuleMatched($data1, new RuleObject($testRuleSet1)));
        $this->assertFalse($rm->isRuleMatched($data13, new RuleObject($testRuleSet1))); // case sensitive
        $this->assertFalse($rm->isRuleMatched($data1, new RuleObject($testRuleSet2)));
        $this->assertTrue($rm->isRuleMatched($data22, new RuleObject($testRuleSet2)));
        $this->assertFalse($rm->isRuleMatched($data2, new RuleObject([['device' => 'pc']])));
        $this->assertFalse($rm->isRuleMatched($data2, new RuleObject([[['device' => 'pc']]])));
        $this->assertFalse($rm->isRuleMatched($data2, new RuleObject(['OR' => [[['device' => 'pc']]]])));
        $this->assertFalse($rm->isRuleMatched([], new RuleObject([])));
        $this->assertTrue($rm->isRuleMatched($data31, new RuleObject($testRuleSet3)));
        $this->assertTrue($rm->isRuleMatched($data32, new RuleObject($testRuleSet3)));
    }

    public function testAllowChangeComparisonProcessorOnFly(): void
    {
        $customComparisonProcessor = [
            'isTypeOf' => function ($value, $testAgainst, $negation = false) {
                if ($negation) {
                    return gettype($value) !== $testAgainst;
                }
                return gettype($value) === $testAgainst;
            },
        ];
        $this->ruleManager->setComparisonProcessor($customComparisonProcessor);
        $methods = $this->ruleManager->getComparisonProcessorMethods();
        $expected = array_filter(array_keys($customComparisonProcessor), function ($name) use ($customComparisonProcessor) {
            return is_callable($customComparisonProcessor[$name]);
        });
        sort($methods);
        sort($expected);
        $this->assertEquals($expected, $methods);
    }

    // ----- New operator-specific tests (Task 5) -----

    public function testEqualsOperator(): void
    {
        $rm = new RuleManager();
        $ruleSet = $this->buildSimpleRuleSet('country', 'equals', false, 'US');

        // String match
        $this->assertTrue($rm->isRuleMatched(['country' => 'US'], new RuleObject($ruleSet)));
        // Case-insensitive
        $this->assertTrue($rm->isRuleMatched(['country' => 'us'], new RuleObject($ruleSet)));
        // No match
        $this->assertFalse($rm->isRuleMatched(['country' => 'GB'], new RuleObject($ruleSet)));
    }

    public function testRegexMatchesOperator(): void
    {
        $rm = new RuleManager();
        $ruleSet = $this->buildSimpleRuleSet('username', 'regexMatches', false, '^user-[0-9]+$');

        $this->assertTrue($rm->isRuleMatched(['username' => 'user-42'], new RuleObject($ruleSet)));
        $this->assertTrue($rm->isRuleMatched(['username' => 'USER-42'], new RuleObject($ruleSet))); // case-insensitive
        $this->assertFalse($rm->isRuleMatched(['username' => 'admin-42'], new RuleObject($ruleSet)));
    }

    public function testAndGroupRequiresAll(): void
    {
        $rm = new RuleManager();

        // 3 AND conditions: country=US AND browser=chrome AND device=desktop
        $ruleSet = [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [['key' => 'country', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'US']],
                        ],
                        [
                            'OR_WHEN' => [['key' => 'browser', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'chrome']],
                        ],
                        [
                            'OR_WHEN' => [['key' => 'device', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'desktop']],
                        ],
                    ],
                ],
            ],
        ];

        // Only 2 of 3 match
        $this->assertFalse($rm->isRuleMatched(
            ['country' => 'US', 'browser' => 'chrome', 'device' => 'mobile'],
            new RuleObject($ruleSet)
        ));

        // All 3 match
        $this->assertTrue($rm->isRuleMatched(
            ['country' => 'US', 'browser' => 'chrome', 'device' => 'desktop'],
            new RuleObject($ruleSet)
        ));
    }

    public function testOrGroupRequiresAny(): void
    {
        $rm = new RuleManager();

        // 3 OR blocks at top level
        $ruleSet = [
            'OR' => [
                ['AND' => [['OR_WHEN' => [['key' => 'country', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'GB']]]]],
                ['AND' => [['OR_WHEN' => [['key' => 'country', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'US']]]]],
                ['AND' => [['OR_WHEN' => [['key' => 'country', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'DE']]]]],
            ],
        ];

        // Only second matches
        $this->assertTrue($rm->isRuleMatched(['country' => 'US'], new RuleObject($ruleSet)));

        // None match
        $this->assertFalse($rm->isRuleMatched(['country' => 'FR'], new RuleObject($ruleSet)));
    }

    public function testOrWhenGroupReturnsFirstMatch(): void
    {
        $rm = new RuleManager();

        // OR_WHEN with 3 items: phone, tablet, desktop
        $ruleSet = [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                ['key' => 'device', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'phone'],
                                ['key' => 'device', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'tablet'],
                                ['key' => 'device', 'matching' => ['match_type' => 'equals', 'negated' => false], 'value' => 'desktop'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // First item matches
        $this->assertTrue($rm->isRuleMatched(['device' => 'phone'], new RuleObject($ruleSet)));
        // Second item matches
        $this->assertTrue($rm->isRuleMatched(['device' => 'tablet'], new RuleObject($ruleSet)));
        // Third item matches
        $this->assertTrue($rm->isRuleMatched(['device' => 'desktop'], new RuleObject($ruleSet)));
        // None match
        $this->assertFalse($rm->isRuleMatched(['device' => 'watch'], new RuleObject($ruleSet)));
    }

    public function testNegationInvertsAllOperators(): void
    {
        $rm = new RuleManager();

        // Negated equals
        $ruleSet = $this->buildSimpleRuleSet('country', 'equals', true, 'US');
        $this->assertTrue($rm->isRuleMatched(['country' => 'GB'], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['country' => 'US'], new RuleObject($ruleSet)));

        // Negated contains
        $ruleSet = $this->buildSimpleRuleSet('url', 'contains', true, 'test');
        $this->assertTrue($rm->isRuleMatched(['url' => 'production.com'], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['url' => 'test.com'], new RuleObject($ruleSet)));

        // Negated regexMatches
        $ruleSet = $this->buildSimpleRuleSet('code', 'regexMatches', true, '\\d+');
        $this->assertTrue($rm->isRuleMatched(['code' => 'abc'], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['code' => '123'], new RuleObject($ruleSet)));

        // Negated isIn
        $ruleSet = $this->buildSimpleRuleSet('device', 'isIn', true, 'phone|tablet');
        $this->assertTrue($rm->isRuleMatched(['device' => 'desktop'], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['device' => 'phone'], new RuleObject($ruleSet)));
    }

    public function testMissingKeyReturnsFalse(): void
    {
        $rm = new RuleManager();
        $ruleSet = $this->buildSimpleRuleSet('country', 'equals', false, 'US');

        // Key 'country' not in data — should return false (no match found)
        $result = $rm->isRuleMatched(['browser' => 'chrome'], new RuleObject($ruleSet));
        $this->assertFalse($result);
    }

    public function testStartsWithOperator(): void
    {
        $rm = new RuleManager();
        $ruleSet = $this->buildSimpleRuleSet('url', 'startsWith', false, 'https://');

        $this->assertTrue($rm->isRuleMatched(['url' => 'https://convert.com'], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['url' => 'http://convert.com'], new RuleObject($ruleSet)));
        // Case-insensitive
        $this->assertTrue($rm->isRuleMatched(['url' => 'HTTPS://convert.com'], new RuleObject($ruleSet)));
    }

    public function testEndsWithOperator(): void
    {
        $rm = new RuleManager();
        $ruleSet = $this->buildSimpleRuleSet('email', 'endsWith', false, '@convert.com');

        $this->assertTrue($rm->isRuleMatched(['email' => 'user@convert.com'], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['email' => 'user@other.com'], new RuleObject($ruleSet)));
        // Case-insensitive
        $this->assertTrue($rm->isRuleMatched(['email' => 'user@CONVERT.COM'], new RuleObject($ruleSet)));
    }

    public function testLessAndLessEqualWithTypeMismatch(): void
    {
        $rm = new RuleManager();

        // less with type mismatch (string vs int) — returns false
        $ruleSet = $this->buildSimpleRuleSet('age', 'less', false, 30);
        $this->assertFalse($rm->isRuleMatched(['age' => 'young'], new RuleObject($ruleSet)));

        // lessEqual with type mismatch
        $ruleSet = $this->buildSimpleRuleSet('age', 'lessEqual', false, 30);
        $this->assertFalse($rm->isRuleMatched(['age' => 'young'], new RuleObject($ruleSet)));

        // Same types work
        $ruleSet = $this->buildSimpleRuleSet('age', 'less', false, 30);
        $this->assertTrue($rm->isRuleMatched(['age' => 25], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['age' => 30], new RuleObject($ruleSet)));

        $ruleSet = $this->buildSimpleRuleSet('age', 'lessEqual', false, 30);
        $this->assertTrue($rm->isRuleMatched(['age' => 30], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['age' => 31], new RuleObject($ruleSet)));
    }

    public function testContainsWithEmptyString(): void
    {
        $rm = new RuleManager();

        // Empty testAgainst always matches (JS parity)
        $ruleSet = $this->buildSimpleRuleSet('url', 'contains', false, '');
        $this->assertTrue($rm->isRuleMatched(['url' => 'anything'], new RuleObject($ruleSet)));
        $this->assertTrue($rm->isRuleMatched(['url' => ''], new RuleObject($ruleSet)));
    }

    public function testIsInWithPipeDelimitedValues(): void
    {
        $rm = new RuleManager();

        // Single value in pipe-delimited set
        $ruleSet = $this->buildSimpleRuleSet('device', 'isIn', false, 'phone|tablet');
        $this->assertTrue($rm->isRuleMatched(['device' => 'phone'], new RuleObject($ruleSet)));
        $this->assertTrue($rm->isRuleMatched(['device' => 'tablet'], new RuleObject($ruleSet)));
        $this->assertFalse($rm->isRuleMatched(['device' => 'desktop'], new RuleObject($ruleSet)));

        // Case-insensitive
        $this->assertTrue($rm->isRuleMatched(['device' => 'PHONE'], new RuleObject($ruleSet)));
    }

    // ----- Helper methods -----

    /**
     * Build a simple rule set with a single OR → AND → OR_WHEN → RuleElement structure.
     */
    private function buildSimpleRuleSet(string $key, string $matchType, bool $negated, mixed $value): array
    {
        return [
            'OR' => [
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => $key,
                                    'matching' => [
                                        'match_type' => $matchType,
                                        'negated' => $negated,
                                    ],
                                    'value' => $value,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
