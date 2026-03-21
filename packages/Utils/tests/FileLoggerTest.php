<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\Utils\FileLogger;

class FileLoggerTest extends TestCase
{
    private $testFile = '/tmp/test.log';
    private $originalErrorHandler;

    protected function setUp(): void
    {
        // Capture PHP warnings/errors by converting them to exceptions.
        $this->originalErrorHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });

        // Ensure the test file does not exist before each test.
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    protected function tearDown(): void
    {
        // Restore the original error handler.
        restore_error_handler();

        // Remove the test file if it exists.
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testShouldReturnAnErrorWithInvalidFile(): void
    {
        $logger = new FileLogger('', new \stdClass());
        try {
            $logger->log('testing invalid file');
            $this->fail('Expected ValueError was not thrown.');
        } catch (\ValueError $e) {
            $this->assertStringContainsString('Path cannot be empty', $e->getMessage());
        }
    }

    public function testShouldReturnAnErrorWithReadOnlyFile()
    {
        // Create an empty file and set it to read-only.
        file_put_contents($this->testFile, '');
        chmod($this->testFile, 0444); // read-only
        $logger = new FileLogger($this->testFile, null);
        try {
            $logger->log('testing read-only log file');
            $this->fail('Expected error was not thrown.');
        } catch (\ErrorException $e) {
            $this->assertStringContainsString("Failed to open stream", $e->getMessage());
        }
    }

    public function testShouldLogToFile()
    {
        $logger = new FileLogger($this->testFile, null);
        $output = 'testing log file';
        $logger->log($output);

        // Read the contents of the file.
        $logContent = file_get_contents($this->testFile);

        // Our logger prefixes the log with an ISO timestamp and [LOG].
        // We'll check that the log content contains [LOG] and the JSON-encoded message.
        $this->assertStringContainsString('[LOG]', $logContent);
        $this->assertStringContainsString(json_encode($output), $logContent);
    }
}
