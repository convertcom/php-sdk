<?php

use ConvertSdk\Utils\StringUtils;
use PHPUnit\Framework\TestCase;

class StringUtilsTest extends TestCase
{
    public function testStringFormatWithNoArguments()
    {
        $template = 'Lorem ipsum dolor sit amet';
        $result = StringUtils::stringFormat($template);
        $this->assertEquals($template, $result);
    }

    public function testStringFormatWithStringArgument()
    {
        $template = 'Lorem %s dolor sit amet';
        $result = StringUtils::stringFormat($template, 'ipsum');
        $this->assertEquals('Lorem ipsum dolor sit amet', $result);
    }

    public function testStringFormatWithFunctionArgument()
    {
        $template = 'Lorem %s dolor sit amet';
        $result = StringUtils::stringFormat($template, function() {
            return 'ipsum';
        });
        $this->assertEquals('Lorem ipsum dolor sit amet', $result);
    }

    public function testStringFormatWithMultipleArguments()
    {
        $template = 'Lorem %s dolor %s amet';
        $result = StringUtils::stringFormat($template, 'ipsum', 'sit');
        $this->assertEquals('Lorem ipsum dolor sit amet', $result);
    }

    public function testStringFormatWithJsonArgument()
    {
        $template = '%j';
        $result = StringUtils::stringFormat($template, 'Lorem ipsum dolor sit amet');
        $this->assertEquals('"Lorem ipsum dolor sit amet"', $result);
    }

    public function testStringFormatWithArrayArgument()
    {
        $template = 'This is array: %j';
        $result = StringUtils::stringFormat($template, [1, 2, 3]);
        $this->assertEquals('This is array: [1,2,3]', $result);
    }

    public function testStringFormatWithEscapedPercentSign()
    {
        $template = 'Lorem %s dolor %%s';
        $result = StringUtils::stringFormat($template, 'ipsum');
        $this->assertEquals('Lorem ipsum dolor %s', $result);
    }

    public function testCamelCaseConversion()
    {
        $input = 'Some text';
        $result = StringUtils::camelCase($input);
        $this->assertEquals('someText', $result);
    }

    public function testGenerateHashWithDefaultSeed()
    {
        $value = 'testString';
        $hash = StringUtils::generateHash($value);
        
        // Assert the hash is an integer (or check with an expected value if needed)
        $this->assertIsInt($hash);
    }

    public function testGenerateHashWithCustomSeed()
    {
        $value = 'testValue';
        $seed = 1234;
        $result = StringUtils::generateHash($value, $seed);
        $this->assertIsInt($result);
    }
}
