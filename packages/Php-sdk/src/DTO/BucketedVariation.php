<?php

declare(strict_types=1);

namespace ConvertSdk\DTO;

/**
 * Readonly consumer DTO for experience bucketing results.
 *
 * Represents the variation a visitor was bucketed into for a specific experience.
 */
readonly class BucketedVariation
{
    /**
     * @param string $experienceId The experience ID
     * @param string $experienceKey The experience key
     * @param string $variationId The variation ID
     * @param string $variationKey The variation key
     * @param array<int, array<string, mixed>> $changes The variation changes
     */
    public function __construct(
        public string $experienceId,
        public string $experienceKey,
        public string $variationId,
        public string $variationKey,
        public array $changes,
    ) {}
}
