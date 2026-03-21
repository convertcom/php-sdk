<?php

declare(strict_types=1);

namespace ConvertSdk\DTO;

use ConvertSdk\Enums\FeatureStatus;

/**
 * Readonly consumer DTO for feature flag results.
 *
 * Represents the resolved state of a feature flag for a specific visitor.
 */
readonly class BucketedFeature
{
    /**
     * @param string $featureId The feature ID
     * @param string $featureKey The feature key
     * @param FeatureStatus $status The feature status (enabled/disabled)
     * @param array<string, mixed> $variables The feature variables with their resolved values
     */
    public function __construct(
        public string $featureId,
        public string $featureKey,
        public FeatureStatus $status,
        public array $variables,
    ) {
    }
}
