<?php

declare(strict_types=1);

namespace ConvertSdk\Utils;
use lastguest\Murmur;

class StringUtils
{
    private static ?bool $hasNativeMurmur = null;

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
     * Generate a MurmurHash3 (32-bit) hash value.
     *
     * Prefers native PHP hash('murmur3a') when available (PHP 8.1+),
     * falls back to lastguest/murmurhash library.
     * Both implementations are validated against JS SDK test vectors.
     *
     * @param string $value The string to hash
     * @param int $seed MurmurHash3 seed (default: 9999)
     * @return int Unsigned 32-bit MurmurHash3 hash value
     */
    public static function generateHash(string $value, int $seed = 9999): int
    {
        self::$hasNativeMurmur ??= in_array('murmur3a', hash_algos(), true);

        if (self::$hasNativeMurmur) {
            return (int) hexdec(hash('murmur3a', $value, false, ['seed' => $seed]));
        }

        return Murmur::hash3_int($value, $seed);
    }
}
