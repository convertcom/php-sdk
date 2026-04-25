<?php
/**
 * Convert JS SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace OpenApi\Client;

class IdentityField
{
    public const ID = 'id';
    public const KEY = 'key';

    /**
     * Returns all valid IdentityField values.
     *
     * @return string[]
     */
    public static function getValues(): array
    {
        return [self::ID, self::KEY];
    }

    /**
     * Validates if a value is a valid IdentityField.
     *
     * @param string $value
     * @return bool
     */
    public static function isValid($value): bool
    {
        return in_array($value, self::getValues(), true);
    }
}