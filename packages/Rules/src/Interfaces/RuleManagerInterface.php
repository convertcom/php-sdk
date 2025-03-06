<?php
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 0 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use ConvertSdk\Enums\RuleError;

interface RuleManagerInterface
{
    /**
     * Get the comparison processor methods.
     *
     * @return array
     */
    public function getComparisonProcessorMethods(): array;

    /**
     * Check if the rule is matched.
     *
     * @param array $data The data to be checked.
     * @param array $ruleSet The set of rules.
     * @param string|null $logEntry Optional log entry.
     * @return bool|RuleError
     */
    public function isRuleMatched($data, array $ruleSet, ?string $logEntry = null);

    /**
     * Check if the rule is valid.
     *
     * @param array $rule The rule to be checked.
     * @return bool
     */
    public function isValidRule(array $rule): bool;
}
