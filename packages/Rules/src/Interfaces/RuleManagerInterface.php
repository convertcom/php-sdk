<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use ConvertSdk\Enums\RuleError;
use OpenAPI\Client\Model\RuleElement;
use OpenAPI\Client\Model\RuleObject;

/**
 * Interface for rule evaluation engine.
 *
 * Defines the contract for evaluating rule sets against data,
 * validating individual rules, and accessing comparison methods.
 */
interface RuleManagerInterface
{
    /**
     * Get the available comparison processor method names.
     *
     * @return array<int, string> List of comparison method names
     */
    public function getComparisonProcessorMethods(): array;

    /**
     * Check if data matches a rule set.
     *
     * @param array<string, mixed> $data The data set to be compared
     * @param RuleObject $ruleSet The hierarchical rule set (OR → AND → OR_WHEN → RuleElement)
     * @param string|null $logEntry Optional label for log messages
     * @return bool|RuleError True if rules match, false if not, or RuleError on data issues
     */
    public function isRuleMatched(array $data, RuleObject $ruleSet, ?string $logEntry = null): bool|RuleError;

    /**
     * Check if a rule element has valid structure.
     *
     * @param RuleElement $rule The rule to validate
     * @return bool True if the rule has valid matching structure and value
     */
    public function isValidRule(RuleElement $rule): bool;
}
