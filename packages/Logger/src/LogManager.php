<?php
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Enums\LogMethod;
use ConvertSdk\Interfaces\LogClientInterface;
use ConvertSdk\Interfaces\LogMethodMapInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use Psr\Log\LoggerInterface;

class LogManager implements LogManagerInterface
{
    /**
     * @var array Array of log clients.
     */
    protected $_clients = [];

    /**
     * @var array Default mapping for log methods.
     */
    protected $_defaultMapper = [
        LogMethod::LOG   => LogMethod::LOG,
        LogMethod::DEBUG => LogMethod::DEBUG,
        LogMethod::INFO  => LogMethod::INFO,
        LogMethod::WARN  => LogMethod::WARN,
        LogMethod::ERROR => LogMethod::ERROR,
        LogMethod::TRACE => LogMethod::TRACE,
    ];

    /**
     * Default mapping for PSR-3 loggers (e.g. Monolog).
     */
    protected $_monologMapping = [
        LogMethod::LOG   => 'info',
        LogMethod::DEBUG => 'debug',
        LogMethod::INFO  => 'info',
        LogMethod::WARN  => 'warning',
        LogMethod::ERROR => 'error',
        LogMethod::TRACE => 'debug'  // Use 'debug' for TRACE since Monolog doesn't have trace.
    ];

    /**
     * Default log level.
     */
    protected const DEFAULT_LOG_LEVEL = LogLevel::TRACE;

    /**
     * Constructor.
     *
     * @param mixed $client A logging client (for example, an object with logging methods or a PSR-3 logger).
     * @param int $level The log level.
     * @param LogMethodMapInterface|null $mapper An optional custom method mapping.
     */
    public function __construct($client = null, int $level = self::DEFAULT_LOG_LEVEL, ?LogMethodMapInterface $mapper = null)
    {
        $this->_clients = [];
        if ($client === null) {
            error_log('Invalid Client SDK' . "\n");
            return;
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
     * Checks if the provided level is valid.
     *
     * @param mixed $level
     * @return bool
     */
    private function _isValidLevel($level): bool
    {
        $reflection = new \ReflectionClass(LogLevel::class);
        $values = array_values($reflection->getConstants());
        return in_array($level, $values, true);
    }

    /**
     * Checks if the provided method is valid.
     *
     * @param string $method
     * @return bool
     */
    private function _isValidMethod(string $method): bool
    {
        $reflection = new \ReflectionClass(LogMethod::class);
        $values = array_values($reflection->getConstants());
        return in_array($method, $values, true);
    }

    /**
     * Internal logging function.
     *
     * @param string $method The log method key.
     * @param int $level The log level.
     * @param mixed ...$args The log message arguments.
     * @return void
     */
    private function _log(string $method, int $level, ...$args): void
    {
        foreach ($this->_clients as $client) {
            if ($level >= $client['level'] && $level !== LogLevel::SILENT) {
                $mappedMethod = isset($client['mapper'][$method]) ? $client['mapper'][$method] : null;
                if ($mappedMethod && method_exists($client['sdk'], $mappedMethod)) {
                    if ($client['sdk'] instanceof \Psr\Log\LoggerInterface) {
                        // Concatenate all arguments into one message
                        $message = implode(' ', array_map('strval', $args));
                        // Call the PSR-3 method with an empty context array
                        $client['sdk']->$mappedMethod($message, []);
                    } else {
                        call_user_func_array([$client['sdk'], $mappedMethod], $args);
                    }
                } else {
                    error_log("Info: Unable to find method \"{$method}()\" in client sdk: " .
                        (is_object($client['sdk']) ? $this->classBasename($client['sdk']) : gettype($client['sdk'])) . "\n");
                    error_log(implode(' ', array_map('strval', $args)) . "\n");
                }
            }
        }
    }

    /**
     * Logs a message with a specified level.
     *
     * @param int $level
     * @param mixed ...$args
     * @return void
     */
    public function log(int $level, ...$args): void
    {
        if (!$this->_isValidLevel($level)) {
            error_log('Invalid Log Level' . "\n");
            return;
        }
        $this->_log(LogMethod::LOG, $level, ...$args);
    }

    /**
     * Logs a trace message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function trace(...$args): void
    {
        $this->_log(LogMethod::TRACE, LogLevel::TRACE, ...$args);
    }

    /**
     * Logs a debug message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function debug(...$args): void
    {
        $this->_log(LogMethod::DEBUG, LogLevel::DEBUG, ...$args);
    }

    /**
     * Logs an info message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function info(...$args): void
    {
        $this->_log(LogMethod::INFO, LogLevel::INFO, ...$args);
    }

    /**
     * Logs a warning message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function warn(...$args): void
    {
        $this->_log(LogMethod::WARN, LogLevel::WARN, ...$args);
    }

    /**
     * Logs an error message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function error(...$args): void
    {
        $this->_log(LogMethod::ERROR, LogLevel::ERROR, ...$args);
    }

    /**
     * Helper method to get only the base name of a class.
     *
     * @param object|string $objectOrClass
     * @return string
     */
    protected function classBasename($objectOrClass): string
    {
        $class = is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass;
        return substr(strrchr($class, "\\"), 1) ?: $class;
    }

    /**
     * Adds a client to the logger.
     *
     * @param mixed $client A logging client.
     * @param int|null $level The log level.
     * @param LogMethodMapInterface|null $methodMap Optional custom method mapping.
     * @return void
     */
    public function addClient($client = null, ?int $level = null, ?LogMethodMapInterface $methodMap = null): void
    {
        if (!$client) {
            error_log('Invalid Client SDK' . "\n");
            return;
        }
        $level = $level ?? self::DEFAULT_LOG_LEVEL;
        if (!$this->_isValidLevel($level)) {
            error_log('Invalid Log Level' . "\n");
            return;
        }
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
     * @param int $level The new log level.
     * @param mixed|null $client The specific client to update.
     * @return void
     */
    public function setClientLevel(int $level, $client = null): void
    {
        if (!$this->_isValidLevel($level)) {
            error_log('Invalid Log Level' . "\n");
            return;
        }
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
