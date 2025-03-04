<?php
namespace ConvertSdk\Utils;

class ArrayUtils
{
    /**
     * Validates variable is an array and not empty.
     *
     * @param mixed $value
     * @return bool
     */
    public static function arrayNotEmpty($value): bool
    {
        return is_array($value) && count($value) > 0;
    }
}