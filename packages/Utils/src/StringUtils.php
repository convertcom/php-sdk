<?php

declare(strict_types=1);

namespace ConvertSdk\Utils;
use lastguest\Murmur;

class StringUtils
{
    public static function stringFormat(string $template, ...$args): string
    {
        if (count($args) === 0) {
            return $template;
        }
        $i = 0;
        $result = preg_replace_callback('/(%?)(%([sj]))/', function($matches) use (&$i, $args) {
            if ($matches[1] !== '') {
                // Escaped
                return $matches[0];
            }
            $arg = $args[$i++] ?? null;
            $val = is_callable($arg) ? $arg() : $arg;
            switch ($matches[3]) {
                case 's':
                    return (string)$val;
                case 'j':
                    return json_encode($val);
                default:
                    return $matches[0];
            }
        }, $template);
        return str_replace('%%', '%', $result);
    }

    public static function camelCase(string $input): string
    {
        $words = preg_split('/\s+/', strtolower($input));
    
        $words = array_map(function ($word, $index) {
            if ($index === 0) {
                return $word; // Keep the first word in lowercase
            }
            return ucfirst($word); // Capitalize the first letter of subsequent words
        }, $words, array_keys($words));
    
        return implode('', $words); // Join the words back together
    }
    

     /**
     * Generate numeric hash based on seed using lastguest/murmurhash.
     *
     * @param string $value
     * @param int $seed
     * @return int
     */
    public static function generateHash(string $value, int $seed = 9999): int
    {
        return Murmur::hash3_int($value, $seed);
    }
}
