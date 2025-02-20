<?php

namespace Convert\PhpSdk\Utils;

class Logger
{
    public static function log($message)
    {
        file_put_contents(__DIR__ . "/../../logs/sdk.log", date("Y-m-d H:i:s") . " - " . $message . PHP_EOL, FILE_APPEND);
    }
}
