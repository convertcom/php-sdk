<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\RuleManagerInterface;
use ConvertSdk\Utils\ArrayUtils;
use ConvertSdk\Utils\Comparisons;
use ConvertSdk\Utils\LogUtils;
use ConvertSdk\Utils\ObjectUtils;
use ConvertSdk\Utils\StringUtils;
use OpenAPI\Client\Model\RuleElement;
use OpenAPI\Client\Model\RuleObject;
use OpenAPI\Client\RuleAnd;
use OpenAPI\Client\RuleOrWhen;

/**
 * Provides rule processing calculations with corresponding comparison methods.
 *
 * Evaluates rule sets using a 4-level nesting hierarchy: OR → AND → OR_WHEN → RuleElement.
 * Supports pluggable comparison processors and case-insensitive key matching.
 *
 * @implements RuleManagerInterface
 */
final class RuleManager implements RuleManagerInterface
{
    private const DEFAULT_KEYS_CASE_SENSITIVE = true;
    private const DEFAULT_NEGATION = '!';
    private const DEFAULT_COMPARISON_PROCESSOR = Comparisons::class;

    /**
     * Constructor to initialize RuleManager with required dependencies.
     *
     * @param array<string, callable>|string $comparisonProcessor Comparison processor class name or array of callable methods
     * @param string $negation Negation prefix character (default: '!')
     * @param bool $keysCaseSensitive Whether rule key matching is case-sensitive (default: true)
     * @param LogManagerInterface|null $logManager Optional logger for debug output
     * @param \Closure|null $mapper Optional data mapper for logging transformations
     */
    public function __construct(
        private array|string $comparisonProcessor = self::DEFAULT_COMPARISON_PROCESSOR,
        private readonly string $negation = self::DEFAULT_NEGATION,
        private readonly bool $keysCaseSensitive = self::DEFAULT_KEYS_CASE_SENSITIVE,
        private readonly ?LogManagerInterface $logManager = null,
        private readonly ?\Closure $mapper = null,
    ) {
        $this->logManager?->trace('RuleManager()', Messages::RULE_CONSTRUCTOR, LogUtils::toLoggable($this));
    }

    /**
     * Set the comparison processor.
     *
     * @param array<string, callable> $comparisonProcessor Array of callable comparison methods
     * @return void
     */
    public function setComparisonProcessor(array $comparisonProcessor): void
    {
        $this->comparisonProcessor = $comparisonProcessor;
    }

    /**
     * Get the comparison processor.
     *
     * @return array<string|int, string|callable> Method names (if class) or callable array
     */
    public function getComparisonProcessor(): array
    {
        if (is_string($this->comparisonProcessor)) {
            return get_class_methods($this->comparisonProcessor);
        }
        return $this->comparisonProcessor;
    }

    /**
     * Retrieve comparison method names from the comparison processor.
     *
     * @return array<int, string> List of available comparison method names
     */
    public function getComparisonProcessorMethods(): array
    {
        if (is_string($this->comparisonProcessor)) {
            return get_class_methods($this->comparisonProcessor);
        } elseif (is_array($this->comparisonProcessor)) {
            return array_filter(array_keys($this->comparisonProcessor), function ($name) {
                return is_callable($this->comparisonProcessor[$name]);
            });
        }
        return [];
    }

    /**
     * Check input data matching to rule set.
     *
     * Evaluates a data set against a hierarchical rule set (OR → AND → OR_WHEN → RuleElement).
     * Returns true on first OR-level match, false if none match, or RuleError on data issues.
     *
     * @param array<string, mixed> $data Key-value data set to compare against rules
     * @param RuleObject $ruleSet Hierarchical rule object with OR/AND/OR_WHEN structure
     * @param string|null $logEntry Optional label for log messages
     * @return bool|RuleError True if rules match, false if not, or RuleError on data issues
     */
    public function isRuleMatched(array $data, RuleObject $ruleSet, ?string $logEntry = null): bool|RuleError
    {
        $mapperFn = $this->mapper ?? static fn (mixed $value): mixed => $value;
        $this->logManager?->trace('RuleManager.isRuleMatched()', LogUtils::toLoggable($mapperFn([
            'data' => $data,
            'ruleSet' => $ruleSet,
        ])));
        if ($logEntry) {
            $this->logManager?->info('RuleManager.isRuleMatched()', str_replace('#', $logEntry, Messages::PROCESSING_ENTITY));
        }

        // Top OR level
        $match = false;
        if (isset($ruleSet['OR']) && ArrayUtils::arrayNotEmpty($ruleSet['OR'])) {
            foreach ($ruleSet['OR'] as $i => $rule) {
                $match = $this->processAND($data, new RuleAnd($rule));
                if ($match === true) {
                    $this->logManager?->info(
                        'RuleManager.isRuleMatched()',
                        $logEntry ?? '',
                        str_replace('#', (string)$i, Messages::RULE_MATCH)
                    );
                    return $match;
                }
                if ($match instanceof RuleError) {
                    $this->logManager?->info('RuleManager.isRuleMatched()', $logEntry ?? '', ErrorMessages::RULE_ERROR);
                } else {
                    $this->logManager?->info(
                        'RuleManager.isRuleMatched()',
                        $logEntry ?? '',
                        Messages::RULE_NOT_MATCH
                    );
                }
            }
            // If last match was a RuleError, propagate it (JS SDK parity)
            if ($match !== false) {
                return $match;
            }
        } else {
            $this->logManager?->warn('RuleManager.isRuleMatched()', $logEntry ?? '', ErrorMessages::RULE_NOT_VALID);
        }
        return false;
    }

    /**
     * Check if rule object is valid.
     *
     * Validates that a rule element has the required matching structure
     * (match_type string, negated boolean) and a value field.
     *
     * @param RuleElement $rule The rule element to validate
     * @return bool True if the rule has valid structure
     */
    public function isValidRule(RuleElement $rule): bool
    {
        $mapperFn = $this->mapper ?? static fn (mixed $value): mixed => $value;
        $this->logManager?->trace('RuleManager.isValidRule()', LogUtils::toLoggable($mapperFn(['rule' => $rule])));
        return isset($rule['matching']) && is_array($rule['matching']) &&
            isset($rule['matching']['match_type']) && is_string($rule['matching']['match_type']) &&
            isset($rule['matching']['negated']) && is_bool($rule['matching']['negated']) &&
            isset($rule['value']);
    }

    /**
     * Process AND block of rule set.
     *
     * Requires ALL rules in the AND block to match (return true).
     * Returns the first non-true result (false or RuleError) for short-circuit evaluation.
     *
     * @param array<string, mixed> $data Key-value data set to compare
     * @param RuleAnd $rulesSubset AND rule group containing OR_WHEN sub-rules
     * @return bool|RuleError True if all AND conditions match, false or RuleError otherwise
     */
    private function processAND(array $data, RuleAnd $rulesSubset): bool|RuleError
    {
        // Second AND level
        $match = false;
        if ($rulesSubset instanceof RuleAnd) {
            // Extract the AND items array (getAnd() returns ['AND' => [items...]])
            $rawAnd = $rulesSubset->getAnd();
            $andRules = $rawAnd['AND'] ?? $rawAnd;
            if (ArrayUtils::arrayNotEmpty($andRules)) {
                foreach ($andRules as $orWhenGroup) {
                    $match = $this->processORWHEN($data, new RuleOrWhen($orWhenGroup));
                    // AND requires ALL to return true — return first non-true (JS SDK parity)
                    if ($match !== true) {
                        return $match;
                    }
                }
                $this->logManager?->info('RuleManager.processAND()', Messages::RULE_MATCH_AND);
                return true;
            }
        } else {
            $this->logManager?->warn('RuleManager.processAND()', ErrorMessages::RULE_NOT_VALID);
        }
        return false;
    }

    /**
     * Process OR_WHEN block of rule set.
     *
     * Returns the first true match found. If no true match is found but a RuleError
     * was encountered, propagates the RuleError. Returns false only if all items are false.
     *
     * @param array<string, mixed> $data Key-value data set to compare
     * @param RuleOrWhen $rulesSubset OR_WHEN rule group containing individual rule elements
     * @return bool|RuleError True on first match, false if none match, or RuleError on data issues
     */
    private function processORWHEN(array $data, RuleOrWhen $rulesSubset): bool|RuleError
    {
        // Third OR level. Called OR_WHEN.
        $match = false;
        if ($rulesSubset instanceof RuleOrWhen) {
            // Extract the OR_WHEN items array (getOrWhen() returns ['OR_WHEN' => [items...]])
            $rawOrWhen = $rulesSubset->getOrWhen();
            $orWhenRules = $rawOrWhen['OR_WHEN'] ?? $rawOrWhen;

            if (ArrayUtils::arrayNotEmpty($orWhenRules)) {
                foreach ($orWhenRules as $ruleItem) {
                    if (!is_array($ruleItem)) {
                        continue;
                    }
                    $match = $this->processRuleItem($data, new RuleElement($ruleItem));
                    if ($match === true) {
                        return $match;
                    }
                }
                // Propagate RuleError if last match was not false (JS SDK parity)
                if ($match !== false) {
                    return $match;
                }
            }
        } else {
            $this->logManager?->warn('RuleManager.processORWHEN()', ErrorMessages::RULE_NOT_VALID);
        }
        return false;
    }

    /**
     * Process a single rule item.
     *
     * Extracts the data value for the rule's key, then applies the specified
     * comparison method. Supports both key-value data and custom RuleData interfaces.
     *
     * @param array<string, mixed> $data Key-value data set to compare
     * @param RuleElement $rule A single rule element to evaluate
     * @return bool|RuleError Comparison result, or RuleError from custom interface
     */
    private function processRuleItem(array $data, RuleElement $rule): bool|RuleError
    {
        if ($this->isValidRule($rule)) {
            try {
                $negation = $rule['matching']['negated'] ?? false;
                $matching = $rule['matching']['match_type'];
                if (in_array($matching, $this->getComparisonProcessorMethods(), true)) {
                    if ($this->isUsingCustomInterface($data)) {
                        if (isset($rule['rule_type'])) {
                            $this->logManager?->info(
                                'RuleManager.processRuleItem()',
                                str_replace('#', $rule['rule_type'], Messages::RULE_MATCH_START)
                            );
                            foreach (get_class_methods($data) as $method) {
                                if ($method === '__construct') {
                                    continue;
                                }
                                $ruleMethod = StringUtils::camelCase('get ' . str_replace('_', ' ', $rule['rule_type']));
                                if ($method === $ruleMethod || ($data['mapper'] ?? null) === $ruleMethod) {
                                    $dataValue = $data[$method]($rule);
                                    $ruleErrorEnum = RuleError::tryFrom($dataValue);
                                    if ($ruleErrorEnum !== null) {
                                        return $ruleErrorEnum;
                                    }
                                    if ($rule['rule_type'] === 'js_condition') {
                                        return $dataValue;
                                    }
                                    if (is_string($this->comparisonProcessor)) {
                                        return call_user_func(
                                            [$this->comparisonProcessor, $matching],
                                            $dataValue,
                                            $rule['value'],
                                            $negation
                                        );
                                    } else {
                                        return $this->comparisonProcessor[$matching](
                                            $dataValue,
                                            $rule['value'],
                                            $negation
                                        );
                                    }
                                }
                            }
                        }
                    } elseif (ObjectUtils::objectNotEmpty($data)) {
                        foreach ($data as $key => $value) {
                            $k = $this->keysCaseSensitive ? $key : strtolower($key);
                            $ruleK = $this->keysCaseSensitive ? $rule['key'] : strtolower($rule['key']);
                            if ($k === $ruleK) {
                                if (is_string($this->comparisonProcessor)) {
                                    return call_user_func(
                                        [$this->comparisonProcessor, $matching],
                                        $value,
                                        $rule['value'],
                                        $negation
                                    );
                                } else {
                                    return $this->comparisonProcessor[$matching](
                                        $value,
                                        $rule['value'],
                                        $negation
                                    );
                                }
                            }
                        }
                    } else {
                        $this->logManager?->trace('RuleManager.processRuleItem()', LogUtils::toLoggable([
                            'warn' => ErrorMessages::RULE_DATA_NOT_VALID,
                            'data' => $data,
                        ]));
                    }
                } else {
                    $this->logManager?->warn(
                        'RuleManager.processRuleItem()',
                        str_replace('#', $matching, ErrorMessages::RULE_MATCH_TYPE_NOT_SUPPORTED)
                    );
                }
            } catch (\Throwable $error) {
                $this->logManager?->error('RuleManager.processRuleItem()', [
                    'error' => $error->getMessage(),
                ]);
            }
        } else {
            $this->logManager?->warn('RuleManager.processRuleItem()', ErrorMessages::RULE_NOT_VALID);
        }
        return false;
    }

    /**
     * Check if rule data object uses the custom RuleData interface.
     *
     * @param array<string, mixed> $data Data set to check
     * @return bool True if data implements the custom RuleData interface pattern
     */
    private function isUsingCustomInterface(array $data): bool
    {
        return ObjectUtils::objectNotEmpty($data) &&
            isset($data['name']) &&
            $data['name'] === 'RuleData';
    }
}
