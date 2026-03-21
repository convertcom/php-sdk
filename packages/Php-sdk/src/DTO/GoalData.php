<?php

declare(strict_types=1);

namespace ConvertSdk\DTO;

use ConvertSdk\Enums\GoalDataKey;

/**
 * Revenue and goal data for conversion tracking.
 *
 * Used with trackConversion() to report revenue, transaction IDs,
 * and custom dimensions alongside goal conversions.
 */
readonly class GoalData
{
    /**
     * @param GoalDataKey $key The goal data key (amount, transactionId, etc.)
     * @param int|float|string $value The value for this key
     */
    public function __construct(
        public GoalDataKey $key,
        public int|float|string $value,
    ) {
    }
}
