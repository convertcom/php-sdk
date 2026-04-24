<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Enums\LogMethod;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\LogMethodMapInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LogManager implements LogManagerInterface
{
    /**
     * @var array<int, array{sdk: mixed, level: LogLevel, mapper: array<string, string>}> Array of log clients.
     */
    protected array $_clients = [];

    /**
     * @var array<string, string> Default mapping for log methods.
     */
    protected array $_defaultMapper = [
        'log'   => 'log',
        'debug' => 'debug',
        'info'  => 'info',
        'warn'  => 'warn',
        'error' => 'error',
        'trace' => 'trace',
    ];

    /**
     * Default mapping for PSR-3 loggers (e.g. Monolog).
     *
     * @var array<string, string>
     */
    protected array $_monologMapping = [
        'log'   => 'info',
        'debug' => 'debug',
        'info'  => 'info',
        'warn'  => 'warning',
        'error' => 'error',
        'trace' => 'debug',
    ];

    /**
     * Default log level.
     */
    protected const DEFAULT_LOG_LEVEL = LogLevel::Trace;

    /**
     * Constructor.
     *
     * @param mixed $client A logging client (for example, an object with logging methods or a PSR-3 logger).
     * @param LogLevel $level The log level.
     * @param LogMethodMapInterface|null $mapper An optional custom method mapping.
     */
    public function __construct(mixed $client = null, LogLevel $level = self::DEFAULT_LOG_LEVEL, ?LogMethodMapInterface $mapper = null)
    {
        $this->_clients = [];
        if ($client === null) {
            $client = new NullLogger();
        }
        $this->addClient($client, $level, $mapper);
    }

    /**
     * Clears all registered log clients.
     *
     * @return void
     */
    public function clearClients(): void
    {
        $this->_clients = [];
    }

    /**
     * Checks if the provided method is valid.
     *
     * @param string $method
     * @return bool
     */
    private function _isValidMethod(string $method): bool
    {
        return LogMethod::tryFrom($method) !== null;
    }

    /**
     * Internal logging function.
     *
     * @param LogMethod $method The log method key.
     * @param LogLevel $level The log level.
     * @param mixed ...$args The log message arguments.
     * @return void
     */
    private function _log(LogMethod $method, LogLevel $level, mixed ...$args): void
    {
        foreach ($this->_clients as $client) {
            if ($level->value >= $client['level']->value && $level !== LogLevel::Silent) {
                $mappedMethod = $client['mapper'][$method->value] ?? null;
                if ($mappedMethod && method_exists($client['sdk'], $mappedMethod)) {
                    if ($client['sdk'] instanceof \Psr\Log\LoggerInterface) {
                        // Concatenate all arguments into one message
                        try {
                            $message = implode(' ', array_map(
                                fn ($arg) => is_array($arg) ? (json_encode($arg) ?: '[unserializable]') : (is_object($arg) ? get_class($arg) : strval($arg)),
                                $args
                            ));
                        } catch (\Throwable $e) {
                            $message = '[log serialization error: ' . $e->getMessage() . ']';
                        }
                        // Call the PSR-3 method with an empty context array
                        $client['sdk']->$mappedMethod($message, []);
                    } else {
                        call_user_func_array([$client['sdk'], $mappedMethod], $args);
                    }
                } else {
                    error_log("Info: Unable to find method \"{$method->value}()\" in client sdk: " .
                        (is_object($client['sdk']) ? $this->classBasename($client['sdk']) : gettype($client['sdk'])) . "\n");
                    $formattedArgs = array_map(function ($arg) {
                        return is_array($arg) ? json_encode($arg) : strval($arg);
                    }, $args);
                    error_log(implode(' ', $formattedArgs) . "\n");
                }
            }
        }
    }

    /**
     * Logs a message with a specified level.
     *
     * @param LogLevel $level
     * @param mixed ...$args
     * @return void
     */
    public function log(LogLevel $level, mixed ...$args): void
    {
        $this->_log(LogMethod::Log, $level, ...$args);
    }

    /**
     * Logs a trace message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function trace(mixed ...$args): void
    {
        $this->_log(LogMethod::Trace, LogLevel::Trace, ...$args);
    }

    /**
     * Logs a debug message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function debug(mixed ...$args): void
    {
        $this->_log(LogMethod::Debug, LogLevel::Debug, ...$args);
    }

    /**
     * Logs an info message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function info(mixed ...$args): void
    {
        $this->_log(LogMethod::Info, LogLevel::Info, ...$args);
    }

    /**
     * Logs a warning message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function warn(mixed ...$args): void
    {
        $this->_log(LogMethod::Warn, LogLevel::Warn, ...$args);
    }

    /**
     * Logs an error message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function error(mixed ...$args): void
    {
        $this->_log(LogMethod::Error, LogLevel::Error, ...$args);
    }

    /**
     * Helper method to get only the base name of a class.
     *
     * @param object|string $objectOrClass
     * @return string
     */
    protected function classBasename(object|string $objectOrClass): string
    {
        $class = is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass;
        return substr(strrchr($class, '\\'), 1) ?: $class;
    }

    /**
     * Adds a client to the logger.
     *
     * @param mixed $client A logging client.
     * @param LogLevel|null $level The log level.
     * @param LogMethodMapInterface|null $methodMap Optional custom method mapping.
     * @return void
     */
    public function addClient(mixed $client = null, ?LogLevel $level = null, ?LogMethodMapInterface $methodMap = null): void
    {
        if (!$client) {
            error_log('Invalid Client SDK' . "\n");
            return;
        }
        $level = $level ?? self::DEFAULT_LOG_LEVEL;
        if ($client instanceof LoggerInterface) {
            $mapper = $this->_monologMapping;
        } else {
            $mapper = $this->_defaultMapper;
        }
        if ($methodMap) {
            foreach ($methodMap as $key => $value) {
                if ($this->_isValidMethod($key)) {
                    $mapper[$key] = $value;
                }
            }
        }
        $this->_clients[] = [
            'sdk'    => $client,
            'level'  => $level,
            'mapper' => $mapper,
        ];
    }

    /**
     * Sets the log level for a given client, or for all clients if none is specified.
     *
     * @param LogLevel $level The new log level.
     * @param mixed|null $client The specific client to update.
     * @return void
     */
    public function setClientLevel(LogLevel $level, mixed $client = null): void
    {
        if ($client !== null) {
            $found = false;
            foreach ($this->_clients as $index => $c) {
                if ($c['sdk'] === $client) {
                    $this->_clients[$index]['level'] = $level;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                error_log('Client SDK not found' . "\n");
                return;
            }
        } else {
            foreach ($this->_clients as $index => $c) {
                $this->_clients[$index]['level'] = $level;
            }
        }
    }
}
