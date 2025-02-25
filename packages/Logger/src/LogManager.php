<?php
namespace ConvertSdk\Logger;

use ConvertSdk\Enums\LogLevel;   
use ConvertSdk\Enums\LogMethod;

/**
 * Class LogManager
 *
 * Provides logging logic.
 */
class LogManager
{
    /**
     * Default log level.
     */
    private const DEFAULT_LOG_LEVEL = LogLevel::TRACE;

    /**
     * @var array List of log clients.
     * Each client is an associative array with keys: 'sdk', 'level', 'mapper'.
     */
    private $clients = [];

    /**
     * @var array Default method map.
     */
    private $defaultMapper = [
        LogMethod::LOG   => LogMethod::LOG,
        LogMethod::DEBUG => LogMethod::DEBUG,
        LogMethod::INFO  => LogMethod::INFO,
        LogMethod::WARN  => LogMethod::WARN,
        LogMethod::ERROR => LogMethod::ERROR,
        LogMethod::TRACE => LogMethod::TRACE,
    ];

    /**
     * LogManager constructor.
     *
     * @param mixed $client A logging client (defaults to a simple console object).
     * @param int $level Log level (default is DEFAULT_LOG_LEVEL).
     * @param array|null $mapper Optional method map to override defaults.
     */
    public function __construct($client = null, int $level = self::DEFAULT_LOG_LEVEL, ?array $mapper = null)
    {
        $this->clients = [];
        // If no client is provided, you might want to create a default client.
        // For this example, if $client is null, we create a simple stub that uses PHP's error_log.
        if ($client === null) {
            $client = new class {
                public function log(...$args) {
                    error_log(implode(" ", $args));
                }
                public function debug(...$args) {
                    error_log(implode(" ", $args));
                }
                public function info(...$args) {
                    error_log(implode(" ", $args));
                }
                public function warn(...$args) {
                    error_log(implode(" ", $args));
                }
                public function error(...$args) {
                    error_log(implode(" ", $args));
                }
                public function trace(...$args) {
                    error_log(implode(" ", $args));
                }
            };
        }
        $this->addClient($client, $level, $mapper);
    }

    /**
     * Check if provided log level is valid.
     *
     * @param mixed $level
     * @return bool
     */
    private function isValidLevel($level): bool
    {
        $values = (new \ReflectionClass(\ConvertSdk\Enums\LogLevel::class))->getConstants();
        return in_array($level, $values, true);
    }
    /**
     * Check if provided method is valid.
     *
     * @param string $method
     * @return bool
     */
    private function isValidMethod(string $method): bool
    {
        // Assume LogMethod::getValues() returns an array of valid methods.
        return in_array($method, LogMethod::getValues(), true);
    }

    /**
     * Internal method to log a message.
     *
     * @param string $method The log method name.
     * @param int $level The log level.
     * @param mixed ...$args The arguments to log.
     * @return void
     */
    private function _log(string $method, int $level, ...$args): void
    {
        foreach ($this->clients as $client) {
            if ($level >= $client['level'] && $level !== LogLevel::SILENT) {
                $mappedMethod = $client['mapper'][$method] ?? null;
                if ($mappedMethod && method_exists($client['sdk'], $mappedMethod)) {
                    call_user_func_array([$client['sdk'], $mappedMethod], $args);
                } else {
                    // Fallback: log a message stating the method is missing.
                    error_log("Info: Unable to find method \"{$method}()\" in client sdk: " . (is_object($client['sdk']) ? get_class($client['sdk']) : gettype($client['sdk'])));
                    // Attempt to call the method directly if it exists.
                    if (method_exists($client['sdk'], $method)) {
                        call_user_func_array([$client['sdk'], $method], $args);
                    } else {
                        // As a last resort, just print to error_log.
                        error_log(implode(" ", $args));
                    }
                }
            }
        }
    }

    /**
     * Log a message at the specified level.
     *
     * @param int $level
     * @param mixed ...$args
     * @return void
     */
    public function log(int $level, ...$args): void
    {
        if (!$this->isValidLevel($level)) {
            error_log('Invalid Log Level');
            return;
        }
        $this->_log(LogMethod::LOG, $level, ...$args);
    }

    /**
     * Log a trace message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function trace(...$args): void
    {
        $this->_log(LogMethod::TRACE, LogLevel::TRACE, ...$args);
    }

    /**
     * Log a debug message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function debug(...$args): void
    {
        $this->_log(LogMethod::DEBUG, LogLevel::DEBUG, ...$args);
    }

    /**
     * Log an info message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function info(...$args): void
    {
        $this->_log(LogMethod::INFO, LogLevel::INFO, ...$args);
    }

    /**
     * Log a warning message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function warn(...$args): void
    {
        $this->_log(LogMethod::WARN, LogLevel::WARN, ...$args);
    }

    /**
     * Log an error message.
     *
     * @param mixed ...$args
     * @return void
     */
    public function error(...$args): void
    {
        $this->_log(LogMethod::ERROR, LogLevel::ERROR, ...$args);
    }

    /**
     * Add a logging client.
     *
     * @param mixed $client A logging client.
     * @param int $level Log level.
     * @param array|null $methodMap Optional method map.
     * @return void
     */
    public function addClient($client, int $level = self::DEFAULT_LOG_LEVEL, ?array $methodMap = null): void
    {
        if (!$client) {
            error_log('Invalid Client SDK');
            return;
        }
        if (!$this->isValidLevel($level)) {
            error_log('Invalid Log Level');
            return;
        }
        $mapper = $this->defaultMapper;
        if ($methodMap && is_array($methodMap)) {
            foreach (array_keys($methodMap) as $method) {
                if ($this->isValidMethod($method)) {
                    $mapper[$method] = $methodMap[$method];
                }
            }
        }
        $this->clients[] = [
            'sdk'    => $client,
            'level'  => $level,
            'mapper' => $mapper
        ];
    }

    /**
     * Set the log level for a specific client or all clients.
     *
     * @param int $level
     * @param mixed|null $client Optional specific client.
     * @return void
     */
    public function setClientLevel(int $level, $client = null): void
    {
        if (!$this->isValidLevel($level)) {
            error_log('Invalid Log Level');
            return;
        }
        if ($client !== null) {
            $found = false;
            foreach ($this->clients as &$cl) {
                if ($cl['sdk'] === $client) {
                    $cl['level'] = $level;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                error_log('Client SDK not found');
            }
        } else {
            foreach ($this->clients as &$cl) {
                $cl['level'] = $level;
            }
        }
    }
}
