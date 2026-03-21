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
     * @param array      $data      The data set to be compared.
     * @param RuleObject $ruleSet   The set of rules.
     * @param string|null $logEntry Optional log entry.
     * @return bool|RuleError
     */
    public function isRuleMatched(array $data, RuleObject $ruleSet, ?string $logEntry = null);

    /**
     * Check if the provided rule is valid.
     *
     * @param RuleElement $rule The rule to validate.
     * @return bool True if the rule is valid, false otherwise.
     */
    public function isValidRule(RuleElement $rule): bool;
}
