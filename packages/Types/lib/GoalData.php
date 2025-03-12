<?php
/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace OpenApi\Client;

use ConvertSdk\Enums\GoalDataKey;

/**
 * Represents goal data with an optional key and value.
 */
class GoalData
{
    /**
     * @var string|null The goal key, restricted to GoalDataKey values
     */
    protected $key;

    /**
     * @var float|string|null The goal value, either a number or string
     */
    protected $value;

    /**
     * Valid GoalDataKey values.
     */
    private const VALID_KEYS = [
        GoalDataKey::AMOUNT,
        GoalDataKey::PRODUCTS_COUNT,
        GoalDataKey::TRANSACTION_ID
    ];

    /**
     * Constructor to initialize the object with data.
     *
     * @param array $data Associative array of property values
     * @throws \InvalidArgumentException If key is not a valid GoalDataKey
     */
    public function __construct(array $data = [])
    {
        $key = $data['key'] ?? null;
        if ($key !== null && !in_array($key, self::VALID_KEYS, true)) {
            throw new \InvalidArgumentException("Invalid GoalData key: '$key'. Must be one of: " . implode(', ', self::VALID_KEYS));
        }
        $this->key = $key;

        $this->value = $data['value'] ?? null;
    }

    /**
     * Get the goal key.
     *
     * @return string|null
     */
    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * Set the goal key.
     *
     * @param string|null $key
     * @return self
     * @throws \InvalidArgumentException If key is not a valid GoalDataKey
     */
    public function setKey(?string $key): self
    {
        if ($key !== null && !in_array($key, self::VALID_KEYS, true)) {
            throw new \InvalidArgumentException("Invalid GoalData key: '$key'. Must be one of: " . implode(', ', self::VALID_KEYS));
        }
        $this->key = $key;
        return $this;
    }

    /**
     * Get the goal value.
     *
     * @return float|string|null
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set the goal value.
     *
     * @param float|string|null $value
     * @return self
     */
    public function setValue($value): self
    {
        $this->value = $value;
        return $this;
    }
}