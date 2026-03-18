<?php

declare(strict_types=1);

namespace ConvertSdk\Utils;

/**
 * Mimics type-utils.ts
 */
class TypeUtils
{
    public static function castType($value, string $type)
    {
        switch ($type) {
            case 'boolean':
                if ($value === 'true') {
                    return true;
                }
                if ($value === 'false') {
                    return false;
                }
                return (bool)$value;
            case 'float':
                if ($value === true) {
                    return 1.0;
                }
                if ($value === false) {
                    return 0.0;
                }
                return floatval($value);
            case 'json':
                if (is_array($value) || is_object($value)) {
                    return $value;
                } else {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                    return (string)$value;
                }
            case 'string':
                return (string)$value;
            case 'integer':
                if ($value === true) {
                    return 1;
                }
                if ($value === false) {
                    return 0;
                }
                return intval($value);
            default:
                return $value;
        }
    }
}
