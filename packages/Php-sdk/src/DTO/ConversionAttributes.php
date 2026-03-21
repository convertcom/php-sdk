<?php

declare(strict_types=1);

namespace ConvertSdk\DTO;

/**
 * Conversion tracking attributes for trackConversion() calls.
 */
readonly class ConversionAttributes
{
    /**
     * @param array<string, mixed>|null $ruleData Key-value pairs for goal rule matching
     * @param array<GoalData|array{key: string, value: mixed}>|null $conversionData Goal data entries (amount, transactionId, etc.)
     * @param array<string, mixed>|null $conversionSetting Tracking behavior overrides (e.g., forceMultipleTransactions)
     */
    public function __construct(
        public ?array $ruleData = null,
        public ?array $conversionData = null,
        public ?array $conversionSetting = null,
    ) {}
}
