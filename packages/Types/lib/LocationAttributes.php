<?php
/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace OpenAPI\Client;

use OpenAPI\Client\IdentityField;

/**
 * Represents location attributes for configuring location-based settings.
 */
class LocationAttributes
{
    /**
     * @var array|null Associative array of location properties
     */
    protected $locationProperties;

    /**
     * @var string|null The identity field, restricted to IdentityField values
     */
    protected $identityField;

    /**
     * @var bool|null Whether to force an event
     */
    protected $forceEvent;

    /**
     * Constructor to initialize the object with data.
     *
     * @param array $data Associative array of property values
     * @throws \InvalidArgumentException If identityField is not a valid IdentityField value
     */
    public function __construct(array $data = [])
    {
        $this->locationProperties = $data['locationProperties'] ?? null;

        $identityField = $data['identityField'] ?? null;
        if ($identityField !== null && !IdentityField::isValid($identityField)) {
            throw new \InvalidArgumentException("Invalid identityField: '$identityField'. Must be one of: " . implode(', ', IdentityField::getValues()));
        }
        $this->identityField = $identityField;

        $this->forceEvent = $data['forceEvent'] ?? null;
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
     * Get the identity field.
     *
     * @return string|null
     */
    public function getIdentityField(): ?string
    {
        return $this->identityField;
    }

    /**
     * Set the identity field.
     *
     * @param string|null $identityField
     * @return self
     * @throws \InvalidArgumentException If identityField is not a valid IdentityField value
     */
    public function setIdentityField(?string $identityField): self
    {
        if ($identityField !== null && !IdentityField::isValid($identityField)) {
            throw new \InvalidArgumentException("Invalid identityField: '$identityField'. Must be one of: " . implode(', ', IdentityField::getValues()));
        }
        $this->identityField = $identityField;
        return $this;
    }

    /**
     * Get whether to force an event.
     *
     * @return bool|null
     */
    public function getForceEvent(): ?bool
    {
        return $this->forceEvent;
    }

    /**
     * Set whether to force an event.
     *
     * @param bool|null $forceEvent
     * @return self
     */
    public function setForceEvent(?bool $forceEvent): self
    {
        $this->forceEvent = $forceEvent;
        return $this;
    }
}