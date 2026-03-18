<?php

declare(strict_types=1);
/**
 * Convert Php SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Interfaces\LogMethodMapInterface;

interface LogClientInterface {
    /**
     * Get the SDK instance.
     *
     * @return mixed
     */
    public function getSdk();

    /**
     * Set the SDK instance.
     *
     * @param mixed $sdk
     * @return void
     */
    public function setSdk($sdk);

    /**
     * Get the current log level.
     *
     * @return LogLevel
     */
    public function getLevel(): LogLevel;

    /**
     * Set the log level.
     *
     * @param LogLevel $level
     * @return void
     */
    public function setLevel(LogLevel $level): void;

    /**
     * Get the log method map.
     *
     * @return LogMethodMapInterface
     */
    public function getMapper(): LogMethodMapInterface;

    /**
     * Set the log method map.
     *
     * @param LogMethodMapInterface $mapper
     * @return void
     */
    public function setMapper(LogMethodMapInterface $mapper): void;
}
