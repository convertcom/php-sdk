<?php

namespace OpenApi\Client;

/**
 * Class representing a bucketing hash configuration.
 *
 * @package ConvertSdk
 */
class BucketingHash
{
    /** @var ?int Optional redistribution value */
    private ?int $redistribute = null;

    /** @var ?int Optional seed value for hashing */
    private ?int $seed = null;

    /** @var ?string Optional experience ID */
    private ?string $experienceId = null;

    /**
     * BucketingHash constructor.
     *
     * @param array $options Configuration options
     */
    public function __construct(array $options = [])
    {
        $this->redistribute = isset($options['redistribute']) && is_numeric($options['redistribute'])
            ? (int)$options['redistribute']
            : null;
        $this->seed = isset($options['seed']) && is_numeric($options['seed'])
            ? (int)$options['seed']
            : null;
        $this->experienceId = isset($options['experienceId']) && is_string($options['experienceId'])
            ? $options['experienceId']
            : null;
    }

    // Getters
    public function getRedistribute(): ?int
    {
        return $this->redistribute;
    }

    public function getSeed(): ?int
    {
        return $this->seed;
    }

    public function getExperienceId(): ?string
    {
        return $this->experienceId;
    }

    // Setters (optional, for flexibility)
    public function setRedistribute(?int $redistribute): void
    {
        $this->redistribute = $redistribute;
    }

    public function setSeed(?int $seed): void
    {
        $this->seed = $seed;
    }

    public function setExperienceId(?string $experienceId): void
    {
        $this->experienceId = $experienceId;
    }
}