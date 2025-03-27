<?php
namespace ConvertSdk\Utils;

/**
 * Mimics object-utils.ts
 */
class ObjectUtils
{
    public static function objectDeepValue(array $object, string $path, $defaultValue = null, bool $truthy = false)
    {
        try {
            if (!empty($object)) {
                $parts = explode('.', $path);
                $val = $object;
                foreach ($parts as $part) {
                    if (is_array($val) && array_key_exists($part, $val)) {
                        $val = $val[$part];
                    } else {
                        return $defaultValue;
                    }
                }
                if ($val || ($truthy && ($val === false || $val === 0))) {
                    return $val;
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
        return $defaultValue ?? null;
    }

    public static function objectDeepMerge(...$objects): array
    {
        $isAssoc = function ($arr) {
            return array_keys($arr) !== range(0, count($arr) - 1);
        };
    
        $result = array_shift($objects);
        foreach ($objects as $obj) {
            foreach ($obj as $key => $oVal) {
                $pVal = $result[$key] ?? null;
    
                if (is_array($pVal) && is_array($oVal)) {
                    // Check if both are associative arrays
                    if ($isAssoc($pVal) && $isAssoc($oVal)) {
                        $result[$key] = self::objectDeepMerge($pVal, $oVal);
                    } else {
                        // Preserve numeric arrays without converting to associative ones
                        $result[$key] = array_merge($pVal, $oVal);
                    }
                } elseif (is_array($oVal)) {
                    $result[$key] = $oVal;
                } else {
                    $result[$key] = $oVal;
                }
            }
        }
        return $result;
    }
    

    public static function objectNotEmpty($object): bool
    {
        if (is_array($object)) {
            return !empty($object);
        } elseif (is_object($object)) {
            return count(get_object_vars($object)) > 0;
        }
        return false;
    }

    public static function objectDeepEqual($a, $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (!is_array($a) || !is_array($b) || $a === null || $b === null) {
            return false;
        }
        $keysA = array_keys($a);
        $keysB = array_keys($b);
        if (count($keysA) !== count($keysB)) {
            return false;
        }
        foreach ($keysA as $key) {
            if (!in_array($key, $keysB, true)) {
                return false;
            }
            if (is_array($a[$key]) || is_array($b[$key])) {
                if (!self::objectDeepEqual($a[$key], $b[$key])) {
                    return false;
                }
            } elseif (is_callable($a[$key]) || is_callable($b[$key])) {
                if ((string)$a[$key] !== (string)$b[$key]) {
                    return false;
                }
            } else {
                if ($a[$key] !== $b[$key]) {
                    return false;
                }
            }
        }
        return true;
    }
}
