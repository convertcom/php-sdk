<?php
namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\RuleManager;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Utils\Comparisons;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\ErrorMessages;

class RuleManagerTest extends TestCase
{
    protected $ruleManager;
    protected $testConfig;

    protected function setUp(): void
    {
        // Load test config from JSON file
        $jsonConfig = file_get_contents(__DIR__ . '/test-config.json');
        $this->testConfig = json_decode($jsonConfig, true);
        // By default, instantiate without custom configuration.
        $this->ruleManager = new RuleManager();
    }

    public function testShouldExposeRuleManager()
    {
        $this->assertTrue(class_exists(RuleManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfRuleManagerInstance()
    {
        $rm = new RuleManager();
        $reflection = new \ReflectionClass($rm);
        $this->assertEquals('RuleManager', $reflection->getShortName());
    }

    // ----- Tests for RuleManager with custom comparison processor -----
    public function testRuleManagerWithCustomComparisonProcessor_InstanceCreation()
    {
        $customComparisonProcessor = [
            'isTypeOf' => function ($value, $testAgainst, $negation = false) {
                // Map PHP types to TS "number"
                $actualType = gettype($value);
                if ($actualType === 'integer' || $actualType === 'double') {
                    $actualType = 'number';
                }
                if ($negation) {
                    return $actualType !== $testAgainst;
                }
                return $actualType === $testAgainst;
            }
        ];

        // Merge test config with default config and override the rules section.
        $configuration = ObjectUtils::objectDeepMerge(
            $this->testConfig,
            DefaultConfig::getDefault(),
            [
                'rules' => [
                    'comparisonProcessor' => $customComparisonProcessor,
                    'keys_case_sensitive' => false
                ]
            ]
        );
        $rm = new RuleManager($configuration);
        $reflection = new \ReflectionClass($rm);
        $this->assertEquals('RuleManager', $reflection->getShortName());
        // Save instance for further tests in this block.
        $this->ruleManager = $rm;
    }

    public function testCustomComparisonProcessorIsUsed()
    {
        // Using the custom processor instance from previous test.
        $this->assertIsArray($this->ruleManager->getComparisonProcessor());
    }

    public function testGetComparisonProcessorMethodsWithCustomProcessor()
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
            }
        ];
        $configuration = ObjectUtils::objectDeepMerge(
            $this->testConfig,
            DefaultConfig::getDefault(),
            [
                'rules' => [
                    'comparisonProcessor' => $customComparisonProcessor,
                    'keys_case_sensitive' => false
                ]
            ]
        );
        $rm = new RuleManager($configuration);
        $this->ruleManager = $rm;
        $methods = $rm->getComparisonProcessorMethods();
        $expected = array_filter(array_keys($customComparisonProcessor), function ($name) use ($customComparisonProcessor) {
            return is_callable($customComparisonProcessor[$name]);
        });
        sort($methods);
        sort($expected);
        $this->assertEquals($expected, $methods);
    }

    public function testIsRuleMatchedWithCustomComparisonProcessor()
    {
        // Custom comparison processor already set in configuration.
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
            }
        ];
        $configuration = ObjectUtils::objectDeepMerge(
            $this->testConfig,
            DefaultConfig::getDefault(),
            [
                'rules' => [
                    'comparisonProcessor' => $customComparisonProcessor,
                    'keys_case_sensitive' => false
                ]
            ]
        );
        $rm = new RuleManager($configuration);
        $this->ruleManager = $rm;

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
                                        'negated' => false
                                    ],
                                    'value' => 'number'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
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
                                        'negated' => true
                                    ],
                                    'value' => 'number'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
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
                                        'negated' => false
                                    ],
                                    'value' => 'number'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        $data1 = ['sum' => 'not a number'];
        $data2 = ['sum' => 44];

        // For testRuleSet1: expecting false for data1, true for data2.
        $this->assertFalse($rm->isRuleMatched($data1, $testRuleSet1), 'Expected false for data1 against testRuleSet1');
        $this->assertTrue($rm->isRuleMatched($data2, $testRuleSet1), 'Expected true for data2 against testRuleSet1');

        // For testRuleSet2 (negation true): expect the reverse.
        $this->assertTrue($rm->isRuleMatched($data1, $testRuleSet2), 'Expected true for data1 against testRuleSet2 with negation');
        $this->assertFalse($rm->isRuleMatched($data2, $testRuleSet2), 'Expected false for data2 against testRuleSet2 with negation');

        // For testRuleSet3: keys should be compared case-insensitively.
        // Since configuration sets keys_case_sensitive false, data with lower-case 'sum' should match a rule with 'SUM'.
        $this->assertTrue($rm->isRuleMatched($data2, $testRuleSet3), 'Expected true for data2 against testRuleSet3 with case-insensitive keys');
    }

    // ----- Tests for RuleManager with default comparison processor -----
    public function testRuleManagerWithDefaultComparisonProcessor()
    {
        $configuration = ObjectUtils::objectDeepMerge($this->testConfig, DefaultConfig::getDefault());
        $rm = new RuleManager($configuration);
        $this->ruleManager = $rm;
        $reflection = new \ReflectionClass($rm);
        $this->assertEquals('RuleManager', $reflection->getShortName());
    }

    public function testIsValidRule()
    {
        $validRule = [
            'key' => 'device',
            'matching' => [
                'match_type' => 'contains',
                'negated' => false
            ],
            'value' => 'phone'
        ];
        $this->assertTrue($this->ruleManager->isValidRule($validRule));

        $badStructure = [
            'matching' => 'contains',
            'data' => 'phone'
        ];
        $this->assertFalse($this->ruleManager->isValidRule($badStructure));

        $missingMatching = [
            'key' => 'device',
            'value' => 'phone'
        ];
        $this->assertFalse($this->ruleManager->isValidRule($missingMatching));

        $missingValue = [
            'key' => 'device',
            'matching' => [
                'match_type' => 'contains',
                'negated' => false
            ]
        ];
        $this->assertFalse($this->ruleManager->isValidRule($missingValue));
    }

    public function testRuleManagerWithDefaultComparisonProcessorIsRuleMatched()
    {
        $configuration = ObjectUtils::objectDeepMerge($this->testConfig, DefaultConfig::getDefault());
        $rm = new RuleManager($configuration);
        $this->ruleManager = $rm;

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
                                        'negated' => false
                                    ],
                                    'value' => 'pc'
                                ],
                                [
                                    'key' => 'price',
                                    'matching' => [
                                        'match_type' => 'less',
                                        'negated' => false
                                    ],
                                    'value' => 100
                                ]
                            ]
                        ]
                    ]
                ]
            ]
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
                                        'negated' => true
                                    ],
                                    'value' => 'pc'
                                ],
                                [
                                    'key' => 'device',
                                    'matching' => [
                                        'match_type' => 'isIn',
                                        'negated' => false
                                    ],
                                    'value' => 'phone|tablet'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
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
                                        'negated' => false
                                    ],
                                    'value' => 'phone|tablet'
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'AND' => [
                        [
                            'OR_WHEN' => [
                                [
                                    'key' => 'age',
                                    'matching' => [
                                        'match_type' => 'less',
                                        'negated' => true
                                    ],
                                    'value' => 30
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $data1 = ['device' => 'pc', 'browser' => 'Mozilla', 'price' => 3];
        $data12 = ['device' => 'tablet', 'browser' => 'Mozilla', 'price' => 3];
        $data13 = ['DEVICE' => 'tablet', 'BROWSER' => 'Mozilla', 'PRICE' => 3];
        $data2 = ['browser' => 'Chrome'];
        $data21 = 'phone';
        $data22 = ['device' => 'phone'];
        $data31 = ['device' => 'tablet', 'browser' => 'Mozilla', 'age' => 10];
        $data32 = ['device' => 'pc', 'browser' => 'Chrome', 'age' => 31];
        $this->assertTrue($rm->isRuleMatched($data1, $testRuleSet1));
        $this->assertTrue($rm->isRuleMatched($data12, $testRuleSet1));
        $this->assertFalse($rm->isRuleMatched($data13, $testRuleSet1)); // case sensitive
        $this->assertFalse($rm->isRuleMatched($data1, $testRuleSet2));
        $this->assertTrue($rm->isRuleMatched($data22, $testRuleSet2));
        $this->assertFalse($rm->isRuleMatched($data21, $testRuleSet2));
        $this->assertFalse($rm->isRuleMatched($data2, [['device' => 'pc']]));
        $this->assertFalse($rm->isRuleMatched($data2, $testRuleSetWrong = [[['device' => 'pc']]]));
        $this->assertFalse($rm->isRuleMatched($data2, $testRuleSetWrong2 = ['OR' => [[['device' => 'pc']]]]));
        $this->assertFalse($rm->isRuleMatched([], []));
        $this->assertFalse($rm->isRuleMatched('a string', 1234567));
        $this->assertFalse($rm->isRuleMatched([], [[['a string']]]));
        $this->assertTrue($rm->isRuleMatched($data31, $testRuleSet3));
        $this->assertTrue($rm->isRuleMatched($data32, $testRuleSet3));
    }

    public function testAllowChangeComparisonProcessorOnFly()
    {
        $customComparisonProcessor = [
            'isTypeOf' => function ($value, $testAgainst, $negation = false) {
                // For custom processor, we use PHP gettype (no mapping)
                if ($negation) {
                    return gettype($value) !== $testAgainst;
                }
                return gettype($value) === $testAgainst;
            }
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
}
