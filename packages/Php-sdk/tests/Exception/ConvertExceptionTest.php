<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Exception;

use ConvertSdk\Exception\BucketingException;
use ConvertSdk\Exception\ConfigFetchException;
use ConvertSdk\Exception\ConfigValidationException;
use ConvertSdk\Exception\ConvertException;
use ConvertSdk\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ConvertExceptionTest extends TestCase
{
    public function testConvertExceptionExtendsRuntimeException(): void
    {
        $exception = new ConvertException('test');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testConfigFetchExceptionExtendsConvertException(): void
    {
        $exception = new ConfigFetchException('fetch failed', 403, 'https://api.example.com/config');
        $this->assertInstanceOf(ConvertException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testConfigFetchExceptionStoresStatusCodeAndUrl(): void
    {
        $exception = new ConfigFetchException('HTTP 403', 403, 'https://api.example.com/config/key');

        $this->assertSame(403, $exception->getStatusCode());
        $this->assertSame('https://api.example.com/config/key', $exception->getUrl());
        $this->assertSame('HTTP 403', $exception->getMessage());
        $this->assertSame(403, $exception->getCode());
    }

    public function testConfigFetchExceptionPreservesPreviousException(): void
    {
        $previous = new \RuntimeException('original error');
        $exception = new ConfigFetchException('wrapped', 500, 'https://api.example.com', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testConfigValidationExceptionExtendsConvertException(): void
    {
        $exception = new ConfigValidationException('invalid config');
        $this->assertInstanceOf(ConvertException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testInvalidArgumentExceptionExtendsPhpInvalidArgumentException(): void
    {
        $exception = new InvalidArgumentException('bad argument');
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        // Should NOT extend ConvertException
        $this->assertNotInstanceOf(ConvertException::class, $exception);
    }

    public function testBucketingExceptionExtendsConvertException(): void
    {
        $exception = new BucketingException('hash failed');
        $this->assertInstanceOf(ConvertException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testAllConvertExceptionSubclassesAreCatchableViaConvertException(): void
    {
        $exceptions = [
            new ConfigFetchException('test', 500, 'https://example.com'),
            new ConfigValidationException('test'),
            new BucketingException('test'),
        ];

        foreach ($exceptions as $exception) {
            $caught = false;
            try {
                throw $exception;
            } catch (ConvertException $e) {
                $caught = true;
            }
            $this->assertTrue($caught, get_class($exception) . ' should be catchable via ConvertException');
        }
    }

    public function testInvalidArgumentExceptionIsNotCatchableViaConvertException(): void
    {
        $caught = false;
        try {
            throw new InvalidArgumentException('test');
        } catch (ConvertException $e) {
            $caught = true;
        } catch (\InvalidArgumentException $e) {
            // Expected path
        }
        $this->assertFalse($caught, 'InvalidArgumentException should NOT be catchable via ConvertException');
    }
}
