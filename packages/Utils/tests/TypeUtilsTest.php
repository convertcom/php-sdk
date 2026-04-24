<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Utils\TypeUtils;
use PHPUnit\Framework\TestCase;

class TypeUtilsTest extends TestCase
{
    // Boolean casting
    public function testCastTypeBooleanWithTrueString(): void
    {
        $this->assertTrue(TypeUtils::castType('true', 'boolean'));
    }

    public function testCastTypeBooleanWithFalseString(): void
    {
        $this->assertFalse(TypeUtils::castType('false', 'boolean'));
    }

    public function testCastTypeBooleanWithNonBooleanString(): void
    {
        $this->assertTrue(TypeUtils::castType('yes', 'boolean'));
        $this->assertFalse(TypeUtils::castType('', 'boolean'));
        $this->assertTrue(TypeUtils::castType(1, 'boolean'));
        $this->assertFalse(TypeUtils::castType(0, 'boolean'));
    }

    // Float casting
    public function testCastTypeFloatWithTrueValue(): void
    {
        $this->assertSame(1.0, TypeUtils::castType(true, 'float'));
    }

    public function testCastTypeFloatWithFalseValue(): void
    {
        $this->assertSame(0.0, TypeUtils::castType(false, 'float'));
    }

    public function testCastTypeFloatWithNumericString(): void
    {
        $this->assertSame(3.14, TypeUtils::castType('3.14', 'float'));
    }

    // Integer casting
    public function testCastTypeIntegerWithTrueValue(): void
    {
        $this->assertSame(1, TypeUtils::castType(true, 'integer'));
    }

    public function testCastTypeIntegerWithFalseValue(): void
    {
        $this->assertSame(0, TypeUtils::castType(false, 'integer'));
    }

    public function testCastTypeIntegerWithNumericString(): void
    {
        $this->assertSame(42, TypeUtils::castType('42', 'integer'));
    }

    // JSON casting
    public function testCastTypeJsonWithValidJsonString(): void
    {
        $result = TypeUtils::castType('{"key":"value"}', 'json');
        $this->assertSame(['key' => 'value'], $result);
    }

    public function testCastTypeJsonWithInvalidJsonString(): void
    {
        $result = TypeUtils::castType('{invalid json}', 'json');
        $this->assertSame('{invalid json}', $result);
    }

    public function testCastTypeJsonWithArrayValue(): void
    {
        $input = ['key' => 'value'];
        $result = TypeUtils::castType($input, 'json');
        $this->assertSame($input, $result);
    }

    // String casting
    public function testCastTypeString(): void
    {
        $this->assertSame('123', TypeUtils::castType(123, 'string'));
    }

    // Default (unknown type)
    public function testCastTypeUnknownTypeReturnsValueUnchanged(): void
    {
        $this->assertSame(42, TypeUtils::castType(42, 'unknown'));
        $this->assertSame('hello', TypeUtils::castType('hello', 'nonexistent'));
    }
}
