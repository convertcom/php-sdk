<?php
/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace OpenAPI\Client;

/**
 * Represents bucketing attributes for configuring experience bucketing.
 */
class BucketingAttributes
{
    /**
     * @var string|null The environment name
     */
    protected $environment;

    /**
     * @var array|null Associative array of location properties
     */
    protected $locationProperties;

    /**
     * @var array|null Associative array of visitor properties
     */
    protected $visitorProperties;

    /**
     * @var bool|null Whether to enable type casting
     */
    protected $typeCasting;

    /**
     * @var string[]|null List of experience keys
     */
    protected $experienceKeys;

    /**
     * @var bool|null Whether to update visitor properties
     */
    protected $updateVisitorProperties;

    /**
     * @var string|null Forced variation ID
     */
    protected $forceVariationId;

    /**
     * @var bool|null Whether to enable tracking
     */
    protected $enableTracking;

    /**
     * @var bool|null Whether to ignore location properties
     */
    protected $ignoreLocationProperties;

    /**
     * Constructor to initialize the object with data.
     *
     * @param array $data Associative array of property values
     */
    public function __construct(array $data = [])
    {
        $this->environment = $data['environment'] ?? null;
        $this->locationProperties = $data['locationProperties'] ?? null;
        $this->visitorProperties = $data['visitorProperties'] ?? null;
        $this->typeCasting = $data['typeCasting'] ?? null;
        $this->experienceKeys = $data['experienceKeys'] ?? null;
        $this->updateVisitorProperties = $data['updateVisitorProperties'] ?? null;
        $this->forceVariationId = $data['forceVariationId'] ?? null;
        $this->enableTracking = $data['enableTracking'] ?? null;
        $this->ignoreLocationProperties = $data['ignoreLocationProperties'] ?? null;
    }

    /**
     * Get the environment.
     *
     * @return string|null
     */
    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    /**
     * Set the environment.
     *
     * @param string|null $environment
     * @return self
     */
    public function setEnvironment(?string $environment): self
    {
        $this->environment = $environment;
        return $this;
    }

    /**
     * Get the location properties.
     *
     * @return array|null
     */
    public function getLocationProperties(): ?array
    {
        return $this->locationProperties;
    }

    /**
     * Set the location properties.
     *
     * @param array|null $locationProperties
     * @return self
     */
    public function setLocationProperties(?array $locationProperties): self
    {
        $this->locationProperties = $locationProperties;
        return $this;
    }

    /**
     * Get the visitor properties.
     *
     * @return array|null
     */
    public function getVisitorProperties(): ?array
    {
        return $this->visitorProperties;
    }

    /**
     * Set the visitor properties.
     *
     * @param array|null $visitorProperties
     * @return self
     */
    public function setVisitorProperties(?array $visitorProperties): self
    {
        $this->visitorProperties = $visitorProperties;
        return $this;
    }

    /**
     * Get whether type casting is enabled.
     *
     * @return bool|null
     */
    public function getTypeCasting(): ?bool
    {
        return $this->typeCasting;
    }

    /**
     * Set whether type casting is enabled.
     *
     * @param bool|null $typeCasting
     * @return self
     */
    public function setTypeCasting(?bool $typeCasting): self
    {
        $this->typeCasting = $typeCasting;
        return $this;
    }

    /**
     * Get the experience keys.
     *
     * @return string[]|null
     */
    public function getExperienceKeys(): ?array
    {
        return $this->experienceKeys;
    }

    /**
     * Set the experience keys.
     *
     * @param string[]|null $experienceKeys
     * @return self
     */
    public function setExperienceKeys(?array $experienceKeys): self
    {
        $this->experienceKeys = $experienceKeys;
        return $this;
    }

    /**
     * Get whether to update visitor properties.
     *
     * @return bool|null
     */
    public function getUpdateVisitorProperties(): ?bool
    {
        return $this->updateVisitorProperties;
    }

    /**
     * Set whether to update visitor properties.
     *
     * @param bool|null $updateVisitorProperties
     * @return self
     */
    public function setUpdateVisitorProperties(?bool $updateVisitorProperties): self
    {
        $this->updateVisitorProperties = $updateVisitorProperties;
        return $this;
    }

    /**
     * Get the forced variation ID.
     *
     * @return string|null
     */
    public function getForceVariationId(): ?string
    {
        return $this->forceVariationId;
    }

    /**
     * Set the forced variation ID.
     *
     * @param string|null $forceVariationId
     * @return self
     */
    public function setForceVariationId(?string $forceVariationId): self
    {
        $this->forceVariationId = $forceVariationId;
        return $this;
    }

    /**
     * Get whether tracking is enabled.
     *
     * @return bool|null
     */
    public function getEnableTracking(): ?bool
    {
        return $this->enableTracking;
    }

    /**
     * Set whether tracking is enabled.
     *
     * @param bool|null $enableTracking
     * @return self
     */
    public function setEnableTracking(?bool $enableTracking): self
    {
        $this->enableTracking = $enableTracking;
        return $this;
    }

    /**
     * Get whether to ignore location properties.
     *
     * @return bool|null
     */
    public function getIgnoreLocationProperties(): ?bool
    {
        return $this->ignoreLocationProperties;
    }

    /**
     * Set whether to ignore location properties.
     *
     * @param bool|null $ignoreLocationProperties
     * @return self
     */
    public function setIgnoreLocationProperties(?bool $ignoreLocationProperties): self
    {
        $this->ignoreLocationProperties = $ignoreLocationProperties;
        return $this;
    }
}