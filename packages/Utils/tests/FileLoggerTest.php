<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Utils\FileLogger;
use PHPUnit\Framework\TestCase;

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
        try {
            $logger = new FileLogger('', new \stdClass());
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
            $this->assertStringContainsString('Failed to open stream', $e->getMessage());
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

    public function testInfoShouldWriteWithInfoPrefix(): void
    {
        $logger = new FileLogger($this->testFile, null);
        $logger->info('info message');
        $logContent = file_get_contents($this->testFile);
        $this->assertStringContainsString('[INFO]', $logContent);
        $this->assertStringContainsString(json_encode('info message'), $logContent);
    }

    public function testDebugShouldWriteWithDebugPrefix(): void
    {
        $logger = new FileLogger($this->testFile, null);
        $logger->debug('debug message');
        $logContent = file_get_contents($this->testFile);
        $this->assertStringContainsString('[DEBUG]', $logContent);
        $this->assertStringContainsString(json_encode('debug message'), $logContent);
    }

    public function testWarnShouldWriteWithWarnPrefix(): void
    {
        $logger = new FileLogger($this->testFile, null);
        $logger->warn('warn message');
        $logContent = file_get_contents($this->testFile);
        $this->assertStringContainsString('[WARN]', $logContent);
        $this->assertStringContainsString(json_encode('warn message'), $logContent);
    }

    public function testErrorShouldWriteWithErrorPrefix(): void
    {
        $logger = new FileLogger($this->testFile, null);
        $logger->error('error message');
        $logContent = file_get_contents($this->testFile);
        $this->assertStringContainsString('[ERROR]', $logContent);
        $this->assertStringContainsString(json_encode('error message'), $logContent);
    }

    public function testCustomAppendMethodShouldCallFsMethod(): void
    {
        $fs = new class () {
            public string $capturedFile = '';
            public string $capturedContent = '';
            public function customAppend(string $file, string $content): void
            {
                $this->capturedFile = $file;
                $this->capturedContent = $content;
            }
        };

        $logger = new FileLogger($this->testFile, $fs, 'customAppend');
        $logger->log('custom append test');
        $this->assertSame($this->testFile, $fs->capturedFile);
        $this->assertStringContainsString('[LOG]', $fs->capturedContent);
    }

    public function testNonCallableAppendMethodShouldThrowException(): void
    {
        $fs = new \stdClass();
        $logger = new FileLogger($this->testFile, $fs, 'nonExistentMethod');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Append method not callable');
        $logger->log('should fail');
    }
}
