<?php

declare(strict_types=1);

namespace ConvertSdk\Utils;

use DateTime;

class FileLogger
{
    private $file;
    private $fs;
    private $appendMethod;

    /**
     * @param string $file
     * @param mixed $fs A filesystem handler (or null to use PHP built‑in functions)
     * @param string $appendMethod Defaults to 'append'
     */
    public function __construct(string $file, $fs, string $appendMethod = 'append')
    {
        $this->file = $file;
        $this->fs = $fs;
        $this->appendMethod = $appendMethod;
    }

    /**
     * Writes output to the file. (For testing, errors are allowed to propagate.)
     *
     * @param string $method
     * @param mixed ...$args
     * @return void
     */
    private function _write(string $method, ...$args): void
    {
        $prefix = sprintf("%s [%s]", (new DateTime())->format(DateTime::ATOM), strtoupper($method));
        $output = $prefix . ' ' . implode("\n" . $prefix . ' ', array_map('json_encode', $args)) . "\n";
        if ($this->appendMethod === 'append') {
            // This will throw an error if, for example, the file is invalid or not writable.
            file_put_contents($this->file, $output, FILE_APPEND);
        } else {
            if (is_callable([$this->fs, $this->appendMethod])) {
                call_user_func([$this->fs, $this->appendMethod], $this->file, $output);
            } else {
                throw new \Exception("Append method not callable");
            }
        }
    }

    public function log(...$args): void
    {
        $this->_write('log', ...$args);
    }

    public function info(...$args): void
    {
        $this->_write('info', ...$args);
    }

    public function debug(...$args): void
    {
        $this->_write('debug', ...$args);
    }

    public function warn(...$args): void
    {
        $this->_write('warn', ...$args);
    }

    public function error(...$args): void
    {
        $this->_write('error', ...$args);
    }
}
