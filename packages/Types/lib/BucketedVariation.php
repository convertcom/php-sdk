<?php
/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace OpenApi\Client;


use OpenAPI\Client\Model\ExperienceVariationConfig;

/**
 * Represents a bucketed variation, extending ExperienceVariationConfig with additional optional properties.
 */
class BucketedVariation extends ExperienceVariationConfig
{
    /**
     * @var string|null The ID of the experience
     */
    protected $experienceId;

    /**
     * @var string|null The key of the experience
     */
    protected $experienceKey;

    /**
     * @var string|null The name of the experience
     */
    protected $experienceName;

    /**
     * @var int|null The allocation percentage for bucketing
     */
    protected $bucketingAllocation;

    /**
     * Constructor to initialize the object with data.
     *
     * @param array $data Associative array of property values
     */
    public function __construct(array $data = [])
    {
        // Initialize parent class properties
        parent::__construct($data);

        // Set additional properties, defaulting to null if not provided
        $this->experienceId = $data['experienceId'] ?? null;
        $this->experienceKey = $data['experienceKey'] ?? null;
        $this->experienceName = $data['experienceName'] ?? null;
        $this->bucketingAllocation = $data['bucketingAllocation'] ?? null;
    }

    /**
     * Get the experience ID.
     *
     * @return string|null
     */
    public function getExperienceId(): ?string
    {
        return $this->experienceId;
    }

    /**
     * Set the experience ID.
     *
     * @param string|null $experienceId
     * @return self
     */
    public function setExperienceId(?string $experienceId): self
    {
        $this->experienceId = $experienceId;
        return $this;
    }

    /**
     * Get the experience key.
     *
     * @return string|null
     */
    public function getExperienceKey(): ?string
    {
        return $this->experienceKey;
    }

    /**
     * Set the experience key.
     *
     * @param string|null $experienceKey
     * @return self
     */
    public function setExperienceKey(?string $experienceKey): self
    {
        $this->experienceKey = $experienceKey;
        return $this;
    }

    /**
     * Get the experience name.
     *
     * @return string|null
     */
    public function getExperienceName(): ?string
    {
        return $this->experienceName;
    }

    /**
     * Set the experience name.
     *
     * @param string|null $experienceName
     * @return self
     */
    public function setExperienceName(?string $experienceName): self
    {
        $this->experienceName = $experienceName;
        return $this;
    }

    /**
     * Get the bucketing allocation.
     *
     * @return int|null
     */
    public function getBucketingAllocation(): ?int
    {
        return $this->bucketingAllocation;
    }

    /**
     * Set the bucketing allocation.
     *
     * @param int|null $bucketingAllocation
     * @return self
     */
    public function setBucketingAllocation(?int $bucketingAllocation): self
    {
        $this->bucketingAllocation = $bucketingAllocation;
        return $this;
    }
}