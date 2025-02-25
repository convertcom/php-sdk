<?php
namespace ConvertSdk\Utils;

/**
 * Mimics array-utils.ts
 */
class ArrayUtils
{
    /**
     * Validates variable is array and not empty
     *
     * @param array $array
     * @return bool
     */
    public static function arrayNotEmpty(array $array): bool
    {
        return count($array) > 0;
    }
}
