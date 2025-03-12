<?php
/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace OpenApi\Client;

use OpenAPI\Client\Model\VisitorSegments;

/**
 * Represents store data with optional bucketing, locations, segments, and goals.
 */
class StoreData
{
    /**
     * @var array<string, string>|null Key-value pairs for bucketing
     */
    protected $bucketing;

    /**
     * @var string[]|null List of location identifiers
     */
    protected $locations;

    /**
     * @var VisitorSegments|null Visitor segments data
     */
    protected $segments;

    /**
     * @var array<string, bool>|null Key-value pairs for goals
     */
    protected $goals;

    /**
     * Constructor to initialize the object with data.
     *
     * @param array $data Associative array of property values
     */
    public function __construct(array $data = [])
    {
        $this->bucketing = $data['bucketing'] ?? null;
        $this->locations = $data['locations'] ?? null;
        $this->segments = $data['segments'] ?? null;
        $this->goals = $data['goals'] ?? null;
    }

    /**
     * Get the bucketing data.
     *
     * @return array<string, string>|null
     */
    public function getBucketing(): ?array
    {
        return $this->bucketing;
    }

    /**
     * Set the bucketing data.
     *
     * @param array<string, string>|null $bucketing
     * @return self
     */
    public function setBucketing(?array $bucketing): self
    {
        $this->bucketing = $bucketing;
        return $this;
    }

    /**
     * Get the locations.
     *
     * @return string[]|null
     */
    public function getLocations(): ?array
    {
        return $this->locations;
    }

    /**
     * Set the locations.
     *
     * @param string[]|null $locations
     * @return self
     */
    public function setLocations(?array $locations): self
    {
        $this->locations = $locations;
        return $this;
    }

    /**
     * Get the segments.
     *
     * @return VisitorSegments|null
     */
    public function getSegments(): ?VisitorSegments
    {
        return $this->segments;
    }

    /**
     * Set the segments.
     *
     * @param VisitorSegments|null $segments
     * @return self
     */
    public function setSegments(?VisitorSegments $segments): self
    {
        $this->segments = $segments;
        return $this;
    }

    /**
     * Get the goals.
     *
     * @return array<string, bool>|null
     */
    public function getGoals(): ?array
    {
        return $this->goals;
    }

    /**
     * Set the goals.
     *
     * @param array<string, bool>|null $goals
     * @return self
     */
    public function setGoals(?array $goals): self
    {
        $this->goals = $goals;
        return $this;
    }
}