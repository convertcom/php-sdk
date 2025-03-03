<?php
/**
 * Convert PHP SDK
 * Logger Module Unit Tests
 */

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\LogManager;
use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Enums\LogMethod;
use ConvertSdk\Interfaces\LogMethodMapInterface;
use Monolog\Logger as MonologLogger;
use Monolog\Level as MonologLoggerLevel;
use Monolog\Handler\TestHandler;

// A client that only implements the "log" method to force fallback behavior.
class MissingMethodClient {
    public function log(...$args) {
        echo implode(' ', $args) . "\n";
    }
}

// A client with a custom method (named "send") used for method mapping.
class CustomMappingClient {
    public function send(...$args) {
        echo implode(' ', $args) . "\n";
    }
}

// A simple anonymous class implementing the LogMethodMapInterface for custom mapping.
class CustomLogMethodMap implements LogMethodMapInterface {
    private $map = [];
    public function offsetExists($offset): bool {
        return isset($this->map[$offset]);
    }
    public function offsetGet($offset) {
        return $this->map[$offset] ?? null;
    }
    public function offsetSet($offset, $value): void {
        $this->map[$offset] = $value;
    }
    public function offsetUnset($offset): void {
        unset($this->map[$offset]);
    }
    public function __construct() {
        // For custom mapping, map the TRACE log method to the 'send' method.
        $this->map[LogMethod::TRACE] = 'send';
    }
}

class LogManagerTest extends TestCase
{
    /**
     * @var LogManager
     */
    protected $logger;

    /**
     * @var TestHandler
     */
    protected $testHandler;

    /**
     * @var MonologLogger
     */
    protected $monolog;

    protected function setUp(): void
    {
        // Create a Monolog instance with a TestHandler.
        $this->testHandler = new TestHandler();
        $this->monolog = new MonologLogger('test');
        $this->monolog->pushHandler($this->testHandler);

        // Initialize LogManager with the Monolog instance.
        $this->logger = new LogManager($this->monolog, LogLevel::TRACE);
    }

    protected function tearDown(): void
    {
        $this->logger = null;
        $this->monolog = null;
        $this->testHandler = null;
    }

    public function testShouldExposeLogManager()
    {
        $this->assertTrue(class_exists(LogManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfLogManagerInstance()
    {
        $logger = new LogManager($this->monolog, LogLevel::TRACE);
        $this->assertInstanceOf(LogManager::class, $logger);
        $this->assertEquals('ConvertSdk\\LogManager', get_class($logger));
    }

    public function testShouldLogToConsoleByDefault()
    {
        $output = 'testing trace message';
        $this->logger->log(LogLevel::TRACE, $output);
        // Monolog mapping for LOG is set in LogManager as 'info'
        $this->assertTrue($this->testHandler->hasRecord($output, MonologLoggerLevel::Info));
    }

    public function testShouldSupportLogMethodWithMultipleArguments()
    {
        $output = 'testing log method';
        $argument = 'with multiple arguments';
        $this->logger->log(LogLevel::TRACE, $output, $argument);
        $expectedMessage = $output . " " . $argument;
        $this->assertTrue($this->testHandler->hasRecord($expectedMessage, MonologLoggerLevel::Info));
    }

    public function testShouldSupportTraceMethodWithMultipleArguments()
    {
        $output = 'testing trace method';
        $argument = 'with multiple arguments';
        $this->logger->trace($output, $argument);
        // LogManager maps TRACE to Monolog's debug level
        $expectedMessage = $output . " " . $argument;
        $this->assertTrue($this->testHandler->hasRecord($expectedMessage, MonologLoggerLevel::Debug));
    }

    public function testShouldSupportDebugMethodWithMultipleArguments()
    {
        $output = 'testing debug method';
        $argument = 'with multiple arguments';
        $this->logger->debug($output, $argument);
        $expectedMessage = $output . " " . $argument;
        $this->assertTrue($this->testHandler->hasRecord($expectedMessage, MonologLoggerLevel::Debug));
    }

    public function testShouldSupportInfoMethodWithMultipleArguments()
    {
        $output = 'testing info method';
        $argument = 'with multiple arguments';
        $this->logger->info($output, $argument);
        $expectedMessage = $output . " " . $argument;
        $this->assertTrue($this->testHandler->hasRecord($expectedMessage, MonologLoggerLevel::Info));
    }

    public function testShouldSupportWarnMethodWithMultipleArguments()
    {
        $output = 'testing warn method';
        $argument = 'with multiple arguments';
        $this->logger->warn($output, $argument);
        $expectedMessage = $output . " " . $argument;
        $this->assertTrue($this->testHandler->hasRecord($expectedMessage, MonologLoggerLevel::Warning));
    }

    public function testShouldSupportErrorMethodWithMultipleArguments()
    {
        $output = 'testing error method';
        $argument = 'with multiple arguments';
        $this->logger->error($output, $argument);
        $expectedMessage = $output . " " . $argument;
        $this->assertTrue($this->testHandler->hasRecord($expectedMessage, MonologLoggerLevel::Error));
    }

    public function testShouldNotLogAnythingWhenUsingSilentLogLevel()
    {
        $output = 'testing silent log level';
        $this->logger->log(LogLevel::SILENT, $output);
        $records = $this->testHandler->getRecords();
        $this->assertEmpty($records);
    }

    public function testShouldReturnErrorWhenUsingInvalidLogLevel()
    {
        // Invalid log level should not add any log record via the monolog client.
        $this->logger->log(6, 'testing invalid log level');
        $records = $this->testHandler->getRecords();
        $this->assertEmpty($records);
    }

    public function testShouldReturnErrorWhenAddingNewClientWithInvalidSDK()
    {
        // We add an invalid client (null) and then verify that the client list doesn't grow.
        $initialClients = $this->getPrivateProperty($this->logger, '_clients');
        $this->logger->addClient(null);
        $afterClients = $this->getPrivateProperty($this->logger, '_clients');
        $this->assertCount(count($initialClients), $afterClients);
    }

    public function testShouldReturnErrorWhenAddingNewClientWithInvalidLogLevel()
    {
        $initialClients = $this->getPrivateProperty($this->logger, '_clients');
        $this->logger->addClient($this->monolog, 6);
        $afterClients = $this->getPrivateProperty($this->logger, '_clients');
        $this->assertCount(count($initialClients), $afterClients);
    }

    public function testShouldLogToConsoleAndToThirdPartyWhenAddingNewClient()
    {
        // Create a second Monolog logger with its own TestHandler.
        $testHandler2 = new TestHandler();
        $monolog2 = new MonologLogger('test2');
        $monolog2->pushHandler($testHandler2);
        $this->logger->addClient($monolog2);
        $output = 'testing third-party logger';
        $this->logger->trace($output);
        $this->assertTrue($this->testHandler->hasRecord($output, MonologLoggerLevel::Debug));
        $this->assertTrue($testHandler2->hasRecord($output, MonologLoggerLevel::Debug));
    }

    public function testShouldMapCustomLogMethodWhenAddingNewClient()
    {
        // Clear default clients so only the custom mapping client is used.
        $this->logger->clearClients();
        // Add a client with custom mapping (using CustomMappingClient and mapping TRACE to 'send').
        $this->logger->addClient(new CustomMappingClient(), LogLevel::TRACE, new CustomLogMethodMap());
        $output = 'testing third-party method mapping';
        $this->logger->trace($output);
        // Since CustomMappingClient is not PSR-3, its output is not captured by the TestHandler.
        // Therefore, we expect that the TestHandler does NOT have a record with $output.
        $this->assertFalse($this->testHandler->hasRecord($output, MonologLoggerLevel::Debug));
    }

    public function testShouldFallbackToConsoleUsingMissingMethodByNewClient()
    {
        // Clear default clients so only the missing method client is used.
        $this->logger->clearClients();
        // Add a client that only implements "log" to force fallback.
        $this->logger->addClient(new MissingMethodClient(), LogLevel::INFO);
        $output = 'testing third-party missing info method';
        $this->logger->info($output);
        // Since MissingMethodClient does not have an "info" method, fallback will trigger.
        // Fallback output (from error_log or echo) is not captured by the Monolog TestHandler.
        // So we expect that TestHandler does NOT have a record with $output.
        $this->assertFalse($this->testHandler->hasRecord($output, MonologLoggerLevel::Info));
    }

    public function testShouldLogOnlyMatchingLevelsWhenUsingNewClient()
    {
        // Create a second Monolog logger with its own TestHandler and log level ERROR.
        $testHandler2 = new TestHandler();
        $monolog2 = new MonologLogger('test2');
        $monolog2->pushHandler($testHandler2);
        $this->logger->addClient($monolog2, LogLevel::ERROR);
        $output = 'testing third-party matching log level';
        $this->logger->warn($output);
        $this->assertTrue($this->testHandler->hasRecord($output, MonologLoggerLevel::Warning));
        $this->assertFalse($testHandler2->hasRecord($output, MonologLoggerLevel::Warning));
    }

    public function testShouldLogEmptyMessage()
    {
        $this->logger->log(LogLevel::INFO, '');
        $records = $this->testHandler->getRecords();
        $foundEmpty = false;
        foreach ($records as $record) {
            if ($record['message'] === '') {
                $foundEmpty = true;
                break;
            }
        }
        $this->assertTrue($foundEmpty);
    }

    public function testShouldHandleLargeNumberOfArguments()
    {
        $output = 'testing with many arguments';
        $args = array_fill(0, 1000, 'arg');
        $this->logger->log(LogLevel::INFO, $output, ...$args);
        $records = $this->testHandler->getRecords();
        $this->assertStringContainsString($output, $records[0]['message']);
    }

    /**
     * Helper method to access protected properties for testing.
     */
    protected function getPrivateProperty($object, $property)
    {
        $reflection = new \ReflectionClass(get_class($object));
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }
}
