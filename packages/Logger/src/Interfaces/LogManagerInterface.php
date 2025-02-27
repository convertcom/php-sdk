<?php
/**
 * Convert Php SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Interfaces\LogMethodMapInterface;

interface LogManagerInterface {
    /**
     * Log a message with a specified level.
     *
     * @param LogLevel $level
     * @param mixed ...$args
     * @return void
     */
    public function log(int $level, ...$args): void;

    /**
     * Log a trace message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function trace(...$args): void;

    /**
     * Log a debug message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function debug(...$args): void;

    /**
     * Log an info message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function info(...$args): void;

    /**
     * Log a warning message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function warn(...$args): void;

    /**
     * Log an error message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function error(...$args): void;

    /**
     * Add a client to the logger.
     *
     * @param mixed|null $client
     * @param LogLevel|null $level
     * @param LogMethodMapInterface|null $methodMap
     * @return void
     */
    public function addClient($client = null, ?int $level = null, ?LogMethodMapInterface $methodMap = null): void;

    /**
     * Set the log level for a given client.
     *
     * @param LogLevel $level
     * @param mixed|null $client
     * @return void
     */
    public function setClientLevel(int $level, $client = null): void;
}
