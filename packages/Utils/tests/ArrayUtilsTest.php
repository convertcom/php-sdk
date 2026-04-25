<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Utils\ArrayUtils;
use PHPUnit\Framework\TestCase;

class ArrayUtilsTest extends TestCase
{
    public function testShouldReturnTrueForNotEmptyArray()
    {
        $array = ['some', 'value', 22];
        $result = ArrayUtils::arrayNotEmpty($array);
        $this->assertTrue($result, 'Expected non-empty array to return true');
    }

    public function testShouldReturnTrueForNotEmptyArrayWithOneItem()
    {
        $array = [0];
        $result = ArrayUtils::arrayNotEmpty($array);
        $this->assertTrue($result, 'Expected array with one item (0) to return true');
    }

    public function testShouldReturnTrueForNotEmptyArrayWithOneBooleanItem()
    {
        $array = [false];
        $result = ArrayUtils::arrayNotEmpty($array);
        $this->assertTrue($result, 'Expected array with one boolean item (false) to return true');
    }

    public function testShouldReturnFalseForEmptyArray()
    {
        $array = [];
        $result = ArrayUtils::arrayNotEmpty($array);
        $this->assertFalse($result, 'Expected empty array to return false');
    }

    public function testShouldReturnFalseForNull()
    {
        $array = null;
        $result = ArrayUtils::arrayNotEmpty($array);
        $this->assertFalse($result, 'Expected null to return false');
    }

    public function testShouldReturnFalseForDeclaredNotDefinedVariable()
    {
        // In PHP, an uninitialized variable would cause an error, so we simulate it as null.
        $array = null;
        $result = ArrayUtils::arrayNotEmpty($array);
        $this->assertFalse($result, 'Expected uninitialized (null) variable to return false');
    }

    public function testShouldReturnFalseForObject()
    {
        // Create an object instead of an array.
        $notArray = (object)['key' => 'value'];
        $result = ArrayUtils::arrayNotEmpty($notArray);
        $this->assertFalse($result, 'Expected object to return false');
    }

    public function testShouldReturnFalseForString()
    {
        $notArray = 'A string';
        $result = ArrayUtils::arrayNotEmpty($notArray);
        $this->assertFalse($result, 'Expected string to return false');
    }

    public function testShouldReturnFalseForInteger()
    {
        $notArray = 0;
        $result = ArrayUtils::arrayNotEmpty($notArray);
        $this->assertFalse($result, 'Expected integer to return false');
    }
}
