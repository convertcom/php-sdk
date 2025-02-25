<?php
namespace ConvertSdk\Utils;

use ConvertSdk\Utils\ObjectUtils;

/**
 * Mimics comparisons.ts
 */
class Comparisons
{
    public static function equals($value, $testAgainst, bool $negation = false): bool
    {
        // If $value is an array
        if (is_array($value)) {
            $result = in_array($testAgainst, $value, true);
            return self::_returnNegationCheck($result, $negation);
        }
        // If $value is a non-empty object/associative array
        if (is_array($value) && ObjectUtils::objectNotEmpty($value)) {
            $keys = array_keys($value);
            $result = in_array((string)$testAgainst, $keys, true);
            return self::_returnNegationCheck($result, $negation);
        }
        // Convert to lowercase strings
        $valueStr = strtolower((string)$value);
        $testStr = strtolower((string)$testAgainst);
        return self::_returnNegationCheck($valueStr === $testStr, $negation);
    }

    public static function equalsNumber($value, $testAgainst, bool $negation = false): bool
    {
        return self::equals($value, $testAgainst, $negation);
    }

    public static function matches($value, $testAgainst, bool $negation = false): bool
    {
        return self::equals($value, $testAgainst, $negation);
    }

    public static function less($value, $testAgainst, bool $negation = false): bool
    {
        if (gettype($value) !== gettype($testAgainst)) {
            return false;
        }
        $result = $value < $testAgainst;
        return self::_returnNegationCheck($result, $negation);
    }

    public static function lessEqual($value, $testAgainst, bool $negation = false): bool
    {
        if (gettype($value) !== gettype($testAgainst)) {
            return false;
        }
        $result = $value <= $testAgainst;
        return self::_returnNegationCheck($result, $negation);
    }

    public static function contains($value, $testAgainst, bool $negation = false): bool
    {
        $valueStr = strtolower((string)$value);
        $testStr = strtolower((string)$testAgainst);
        // If testAgainst is just whitespace, treat as matched
        if (trim($testStr) === '') {
            return self::_returnNegationCheck(true, $negation);
        }
        $found = (strpos($valueStr, $testStr) !== false);
        return self::_returnNegationCheck($found, $negation);
    }

    public static function isIn($values, $testAgainst, bool $negation = false, string $splitter = '|'): bool
    {
        // Convert $values to an array of strings
        $matchedValuesArray = explode($splitter, (string)$values);
        // Convert $testAgainst to array if it's a string
        if (is_string($testAgainst)) {
            $testAgainst = explode($splitter, $testAgainst);
        }
        if (!is_array($testAgainst)) {
            $testAgainst = [];
        }
        // Lowercase everything
        $testAgainst = array_map('strtolower', $testAgainst);
        foreach ($matchedValuesArray as $item) {
            if (in_array(strtolower($item), $testAgainst, true)) {
                return self::_returnNegationCheck(true, $negation);
            }
        }
        return self::_returnNegationCheck(false, $negation);
    }

    public static function startsWith($value, $testAgainst, bool $negation = false): bool
    {
        $valueStr = strtolower((string)$value);
        $testStr = strtolower((string)$testAgainst);
        $found = (strpos($valueStr, $testStr) === 0);
        return self::_returnNegationCheck($found, $negation);
    }

    public static function endsWith($value, $testAgainst, bool $negation = false): bool
    {
        $valueStr = strtolower((string)$value);
        $testStr = strtolower((string)$testAgainst);
        $pos = strrpos($valueStr, $testStr);
        $found = ($pos !== false && $pos === (strlen($valueStr) - strlen($testStr)));
        return self::_returnNegationCheck($found, $negation);
    }

    public static function regexMatches($value, $testAgainst, bool $negation = false): bool
    {
        $valueStr = strtolower((string)$value);
        $pattern = '/' . $testAgainst . '/i';
        $matched = (preg_match($pattern, $valueStr) === 1);
        return self::_returnNegationCheck($matched, $negation);
    }

    private static function _returnNegationCheck(bool $value, bool $negation = false): bool
    {
        return $negation ? !$value : $value;
    }
}
