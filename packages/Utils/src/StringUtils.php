<?php
namespace ConvertSdk\Utils;

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
        // Convert "Some text" -> "someText"
        return preg_replace_callback('/(?:^\w|[A-Z]|\b\w)/', function($matches) use (&$input) {
            static $index = 0;
            $char = $matches[0];
            return $index++ === 0 ? strtolower($char) : strtoupper($char);
        }, $input ?? '');
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
        return Murmurhash::hash($value, $seed);
    }
}
