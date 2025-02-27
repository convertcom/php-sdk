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

// A mock client simulating a console that writes to standard output.
class MockConsole {
    public function log(...$args) {
        echo implode(' ', $args) . "\n";
    }
    public function debug(...$args) {
        echo implode(' ', $args) . "\n";
    }
    public function info(...$args) {
        echo implode(' ', $args) . "\n";
    }
    public function warn(...$args) {
        echo implode(' ', $args) . "\n";
    }
    public function error(...$args) {
        echo implode(' ', $args) . "\n";
    }
    public function trace(...$args) {
        echo implode(' ', $args) . "\n";
    }
}

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

    protected function setUp(): void
    {
        // Ensure error_log writes to standard output so that we can capture it.
        ini_set('error_log', 'php://output');
        // Start output buffering to capture all printed/logged output.
        ob_start();
        // Create a LogManager instance with a default client (MockConsole).
        $this->logger = new LogManager(new MockConsole());
    }

    protected function tearDown(): void
    {
        // Clear the output buffer.
        ob_end_clean();
        $this->logger = null;
    }

    public function testShouldExposeLogManager()
    {
        $this->assertTrue(class_exists(LogManager::class));
    }

    public function testImportedEntityShouldBeConstructorOfLogManagerInstance()
    {
        $logger = new LogManager(new MockConsole());
        $this->assertInstanceOf(LogManager::class, $logger);
        $this->assertEquals('ConvertSdk\\LogManager', get_class($logger));
    }

    public function testShouldLogToConsoleByDefault()
    {
        $output = 'testing trace message';
        $this->logger->log(LogLevel::TRACE, $output);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals($output . "\n", $captured);
    }

    public function testShouldSupportLogMethodWithMultipleArguments()
    {
        $output = 'testing log method';
        $argument = 'with multiple arguments';
        $this->logger->log(LogLevel::TRACE, $output, $argument);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals($output . " " . $argument . "\n", $captured);
    }

    public function testShouldSupportTraceMethodWithMultipleArguments()
    {
        $output = 'testing trace method';
        $argument = 'with multiple arguments';
        $this->logger->trace($output, $argument);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals($output . " " . $argument . "\n", $captured);
    }

    public function testShouldSupportDebugMethodWithMultipleArguments()
    {
        $output = 'testing debug method';
        $argument = 'with multiple arguments';
        $this->logger->debug($output, $argument);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals($output . " " . $argument . "\n", $captured);
    }

    public function testShouldSupportInfoMethodWithMultipleArguments()
    {
        $output = 'testing info method';
        $argument = 'with multiple arguments';
        $this->logger->info($output, $argument);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals($output . " " . $argument . "\n", $captured);
    }

    public function testShouldSupportWarnMethodWithMultipleArguments()
    {
        $output = 'testing warn method';
        $argument = 'with multiple arguments';
        $this->logger->warn($output, $argument);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals($output . " " . $argument . "\n", $captured);
    }

    public function testShouldSupportErrorMethodWithMultipleArguments()
    {
        $output = 'testing error method';
        $argument = 'with multiple arguments';
        $this->logger->error($output, $argument);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals($output . " " . $argument . "\n", $captured);
    }

    public function testShouldNotLogAnythingWhenUsingSilentLogLevel()
    {
        $output = 'testing silent log level';
        $this->logger->log(LogLevel::SILENT, $output);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals('', $captured);
    }

    public function testShouldReturnErrorWhenUsingInvalidLogLevel()
    {
        $output = 'testing invalid log level';
        $this->logger->log(6, $output); // Pass an invalid log level
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals("Invalid Log Level\n", $captured);
    }

    public function testShouldReturnErrorWhenAddingNewClientWithInvalidSDK()
    {
        $this->logger->addClient(null);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals("Invalid Client SDK\n", $captured);
    }

    public function testShouldReturnErrorWhenAddingNewClientWithInvalidLogLevel()
    {
        $this->logger->addClient(new MockConsole(), 6);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals("Invalid Log Level\n", $captured);
    }

    public function testShouldLogToConsoleAndToThirdPartyWhenAddingNewClient()
    {
        // Add a second client (MockConsole)
        $this->logger->addClient(new MockConsole());
        $output = 'testing third-party logger';
        $this->logger->trace($output);
        $captured = ob_get_contents();
        ob_clean();
        // Expect the output from both clients (default and the newly added client)
        $this->assertEquals($output . "\n" . $output . "\n", $captured);
    }

    public function testShouldMapCustomLogMethodWhenAddingNewClient()
    {
        // Add a client with custom mapping (using CustomMappingClient and mapping TRACE to 'send')
        $this->logger->addClient(new CustomMappingClient(), LogLevel::TRACE, new CustomLogMethodMap());
        $output = 'testing third-party method mapping';
        $this->logger->trace($output);
        $captured = ob_get_contents();
        ob_clean();
        // Expect two outputs (one from the default client and one from the custom mapped client)
        $this->assertEquals($output . "\n" . $output . "\n", $captured);
    }

    public function testShouldFallbackToConsoleUsingMissingMethodByNewClient()
    {
        // Add a client that only has a "log" method to force the fallback.
        $this->logger->addClient(new MissingMethodClient(), LogLevel::INFO);
        $output = 'testing third-party missing info method';
        $this->logger->info($output);
        $captured = ob_get_contents();
        ob_clean();
        // Expect the fallback message then the output.
        $expected = 'Info: Unable to find method "info()" in client sdk: MissingMethodClient' . "\n" . $output . "\n";
        $this->assertEquals($expected, $captured);
    }

    public function testShouldLogOnlyMatchingLevelsWhenUsingNewClient()
    {
        // Add a client with log level ERROR. This client should not log messages below ERROR.
        $this->logger->addClient(new MockConsole(), LogLevel::ERROR);
        $output = 'testing third-party matching log level';
        $this->logger->warn($output);
        $captured = ob_get_contents();
        ob_clean();
        // The default client (with level TRACE) logs warn, but the new client (ERROR) does not.
        $this->assertEquals($output . "\n", $captured);
    }

    public function testShouldLogEmptyMessage()
    {
        $this->logger->log(LogLevel::INFO, '');
        $captured = ob_get_contents();
        ob_clean();
        $this->assertEquals("\n", $captured); // Expecting an empty log entry
    }

    public function testShouldHandleLargeNumberOfArguments()
    {
        $output = 'testing with many arguments';
        $args = array_fill(0, 1000, 'arg'); // 1000 arguments
        $this->logger->log(LogLevel::INFO, $output, ...$args);
        $captured = ob_get_contents();
        ob_clean();
        $this->assertStringContainsString($output, $captured);
    }
}
