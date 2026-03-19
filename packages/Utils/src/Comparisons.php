<?php

declare(strict_types=1);

namespace ConvertSdk\Utils;

use ConvertSdk\Utils\ObjectUtils;

/**
 * Comparison processor for rule evaluation.
 *
 * Provides static comparison methods used by the RuleManager to evaluate
 * individual rule conditions. All string comparisons are case-insensitive.
 */
class Comparisons
{
    /**
     * Check equality between value and test target.
     *
     * Supports arrays (indexOf), objects (key lookup), and scalar string comparison.
     * All scalar comparisons are case-insensitive.
     *
     * @param mixed $value The actual value to test
     * @param mixed $testAgainst The expected value to compare against
     * @param bool $negation Whether to invert the result
     * @return bool True if values are equal (or not equal when negated)
     */
    public static function equals(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        if (is_array($value)) {
            $result = in_array($testAgainst, $value, true);
            return self::returnNegationCheck($result, $negation);
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value) && ObjectUtils::objectNotEmpty($value)) {
            $keys = array_keys($value);
            $result = in_array((string)$testAgainst, $keys, true);
            return self::returnNegationCheck($result, $negation);
        }
        $valueStr = strtolower((string)$value);
        $testStr = strtolower((string)$testAgainst);
        return self::returnNegationCheck($valueStr === $testStr, $negation);
    }

    /**
     * Check numeric equality. Delegates to equals().
     *
     * @param mixed $value The actual value to test
     * @param mixed $testAgainst The expected value to compare against
     * @param bool $negation Whether to invert the result
     * @return bool True if values are equal
     */
    public static function equalsNumber(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        return self::equals($value, $testAgainst, $negation);
    }

    /**
     * Check matching equality. Delegates to equals().
     *
     * @param mixed $value The actual value to test
     * @param mixed $testAgainst The expected value to compare against
     * @param bool $negation Whether to invert the result
     * @return bool True if values match
     */
    public static function matches(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        return self::equals($value, $testAgainst, $negation);
    }

    /**
     * Check if value is less than test target.
     *
     * Returns false if types don't match (prevents cross-type comparison).
     *
     * @param mixed $value The actual value to test
     * @param mixed $testAgainst The value to compare against
     * @param bool $negation Whether to invert the result
     * @return bool True if value < testAgainst (same type only)
     */
    public static function less(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        // Normalize numeric types for JS SDK parity (JS typeof returns 'number' for both int and float)
        if (is_numeric($value) && is_numeric($testAgainst)) {
            $value = (float) $value;
            $testAgainst = (float) $testAgainst;
        }
        if (gettype($value) !== gettype($testAgainst)) {
            return false;
        }
        $result = $value < $testAgainst;
        return self::returnNegationCheck($result, $negation);
    }

    /**
     * Check if value is less than or equal to test target.
     *
     * Returns false if types don't match (prevents cross-type comparison).
     * Numeric values are normalized to float for JS SDK parity (JS has single 'number' type).
     *
     * @param mixed $value The actual value to test
     * @param mixed $testAgainst The value to compare against
     * @param bool $negation Whether to invert the result
     * @return bool True if value <= testAgainst (same type only)
     */
    public static function lessEqual(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        // Normalize numeric types for JS SDK parity (JS typeof returns 'number' for both int and float)
        if (is_numeric($value) && is_numeric($testAgainst)) {
            $value = (float) $value;
            $testAgainst = (float) $testAgainst;
        }
        if (gettype($value) !== gettype($testAgainst)) {
            return false;
        }
        $result = $value <= $testAgainst;
        return self::returnNegationCheck($result, $negation);
    }

    /**
     * Check if value contains the test string.
     *
     * Case-insensitive. Empty/whitespace-only testAgainst always returns true.
     *
     * @param mixed $value The value to search within
     * @param mixed $testAgainst The substring to search for
     * @param bool $negation Whether to invert the result
     * @return bool True if value contains testAgainst
     */
    public static function contains(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        $valueStr = strtolower((string)$value);
        $testStr = strtolower((string)$testAgainst);
        if (trim($testStr) === '') {
            return self::returnNegationCheck(true, $negation);
        }
        $found = (strpos($valueStr, $testStr) !== false);
        return self::returnNegationCheck($found, $negation);
    }

    /**
     * Check if value is in a set of pipe-delimited values.
     *
     * Both sides are split by the splitter and compared case-insensitively.
     *
     * @param mixed $values The value(s) to check (pipe-delimited string)
     * @param mixed $testAgainst The set to check against (pipe-delimited string or array)
     * @param bool $negation Whether to invert the result
     * @param string $splitter Delimiter character (default: '|')
     * @return bool True if any value is found in testAgainst
     */
    public static function isIn(mixed $values, mixed $testAgainst, bool $negation = false, string $splitter = '|'): bool
    {
        $matchedValuesArray = explode($splitter, (string)$values);

        if (is_string($testAgainst)) {
            $testAgainst = explode($splitter, $testAgainst);
        } elseif (is_object($testAgainst)) {
            return self::returnNegationCheck(false, $negation);
        } elseif (!is_array($testAgainst)) {
            $testAgainst = [$testAgainst];
        }

        $matchedValuesArray = array_map(function ($item): string {
            return strtolower((string)$item);
        }, $matchedValuesArray);
        $testAgainst = array_map(function ($item): string {
            return strtolower((string)$item);
        }, $testAgainst);

        foreach ($matchedValuesArray as $item) {
            if (in_array($item, $testAgainst, true)) {
                return self::returnNegationCheck(true, $negation);
            }
        }
        return self::returnNegationCheck(false, $negation);
    }

    /**
     * Check if value starts with the test string.
     *
     * Case-insensitive prefix matching.
     *
     * @param mixed $value The value to check
     * @param mixed $testAgainst The expected prefix
     * @param bool $negation Whether to invert the result
     * @return bool True if value starts with testAgainst
     */
    public static function startsWith(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        $valueStr = strtolower((string)$value);
        $testStr = strtolower((string)$testAgainst);
        $found = (strpos($valueStr, $testStr) === 0);
        return self::returnNegationCheck($found, $negation);
    }

    /**
     * Check if value ends with the test string.
     *
     * Case-insensitive suffix matching.
     *
     * @param mixed $value The value to check
     * @param mixed $testAgainst The expected suffix
     * @param bool $negation Whether to invert the result
     * @return bool True if value ends with testAgainst
     */
    public static function endsWith(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        $valueStr = strtolower((string)$value);
        $testStr = strtolower((string)$testAgainst);
        $pos = strrpos($valueStr, $testStr);
        $found = ($pos !== false && $pos === (strlen($valueStr) - strlen($testStr)));
        return self::returnNegationCheck($found, $negation);
    }

    /**
     * Check if value matches a regular expression pattern.
     *
     * Case-insensitive regex matching. Forward slashes in the pattern are escaped.
     *
     * @param mixed $value The value to test
     * @param mixed $testAgainst The regex pattern (without delimiters)
     * @param bool $negation Whether to invert the result
     * @return bool True if value matches the regex pattern
     */
    public static function regexMatches(mixed $value, mixed $testAgainst, bool $negation = false): bool
    {
        $valueStr = strtolower((string)$value);
        $escapedPattern = str_replace('/', '\/', (string)$testAgainst);
        $pattern = '/' . $escapedPattern . '/i';
        $matched = (preg_match($pattern, $valueStr) === 1);
        return self::returnNegationCheck($matched, $negation);
    }

    /**
     * Apply negation check to a boolean result.
     *
     * @param bool $value The comparison result
     * @param bool $negation Whether to invert the result
     * @return bool The final result (inverted if negation is true)
     */
    private static function returnNegationCheck(bool $value, bool $negation = false): bool
    {
        return $negation ? !$value : $value;
    }
}
