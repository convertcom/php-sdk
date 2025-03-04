<?php

namespace ConvertSdk\Tests;

use PHPUnit\Framework\TestCase;
use ConvertSdk\Utils\Comparisons;

class ComparisonsTest extends TestCase
{
    // equals() tests
    public function testEqualsReturnsTrueForEqualNumbers()
    {
        $result = Comparisons::equals(123, 123);
        $this->assertTrue($result);
    }

    public function testEqualsReturnsFalseForEqualNumbersWithNegation()
    {
        $result = Comparisons::equals(123, 123, true);
        $this->assertFalse($result);
    }

    public function testEqualsReturnsFalseForDifferentNumbers()
    {
        $result = Comparisons::equals(321, 123);
        $this->assertFalse($result);
    }

    public function testEqualsReturnsTrueForEqualStrings()
    {
        $result = Comparisons::equals('value', 'value');
        $this->assertTrue($result);
    }

    public function testEqualsReturnsFalseForStringAndNumberMismatch()
    {
        $result = Comparisons::equals('value', 123);
        $this->assertFalse($result);
    }

    public function testEqualsReturnsTrueForStringAndNumberMismatchWithNegation()
    {
        $result = Comparisons::equals('value', 123, true);
        $this->assertTrue($result);
    }

    // less() tests
    public function testLessReturnsTrueForLessNumbers()
    {
        $result = Comparisons::less(-111, 123);
        $this->assertTrue($result);
    }

    public function testLessReturnsFalseForLessNumbersWithNegation()
    {
        $result = Comparisons::less(-111, 123, true);
        $this->assertFalse($result);
    }

    public function testLessReturnsTrueForEqualNumbersWithNegation()
    {
        $result = Comparisons::less(123, 123, true);
        $this->assertTrue($result);
    }

    public function testLessReturnsFalseForInvalidComparison()
    {
        $result = Comparisons::less(321, -123);
        $this->assertFalse($result);
    }

    public function testLessReturnsTrueForInvalidComparisonWithNegation()
    {
        $result = Comparisons::less(321, -123, true);
        $this->assertTrue($result);
    }

    public function testLessReturnsTrueForStringComparison()
    {
        $result = Comparisons::less('abcde', 'axyz');
        $this->assertTrue($result);
    }

    public function testLessReturnsFalseForReversedStringComparison()
    {
        $result = Comparisons::less('axyz', 'abcde');
        $this->assertFalse($result);
    }

    public function testLessReturnsFalseForMismatchedTypes1()
    {
        $result = Comparisons::less(4, 'orange');
        $this->assertFalse($result);
    }

    public function testLessReturnsFalseForMismatchedTypes2()
    {
        $result = Comparisons::less('orange', 4);
        $this->assertFalse($result);
    }

    public function testLessReturnsFalseForMismatchedTypesWithNegation()
    {
        $result = Comparisons::less('orange', 4, true);
        $this->assertFalse($result);
    }

    public function testLessReturnsFalseForEqualNumbers()
    {
        $result = Comparisons::less(4, 4);
        $this->assertFalse($result);
    }

    // lessEqual() tests
    public function testLessEqualReturnsTrueForEqualNumbers()
    {
        $result = Comparisons::lessEqual(4, 4);
        $this->assertTrue($result);
    }

    public function testLessEqualReturnsFalseForMismatchedTypes1()
    {
        $result = Comparisons::lessEqual(4, 'orange');
        $this->assertFalse($result);
    }

    public function testLessEqualReturnsFalseForMismatchedTypesWithNegation()
    {
        $result = Comparisons::lessEqual(4, 'orange', true);
        $this->assertFalse($result);
    }

    public function testLessEqualReturnsTrueForValidComparison()
    {
        $result = Comparisons::lessEqual(4, 123);
        $this->assertTrue($result);
    }

    public function testLessEqualReturnsFalseForInvalidComparison()
    {
        $result = Comparisons::lessEqual(123, 4);
        $this->assertFalse($result);
    }

    public function testLessEqualReturnsTrueForInvalidComparisonWithNegation()
    {
        $result = Comparisons::lessEqual(123, 4, true);
        $this->assertTrue($result);
    }

    public function testLessEqualReturnsFalseForStringComparison()
    {
        $result = Comparisons::lessEqual('axyz', 'abcde');
        $this->assertFalse($result);
    }

    public function testLessEqualReturnsFalseForEqualNumbersWithNegation()
    {
        $result = Comparisons::lessEqual(1234, 1234, true);
        $this->assertFalse($result);
    }

    public function testLessEqualReturnsTrueForValidStringComparison()
    {
        $result = Comparisons::lessEqual('abcde', 'axyz');
        $this->assertTrue($result);
    }

    // contains() tests
    public function testContainsReturnsTrueForSubstring()
    {
        $result = Comparisons::contains('abcde', 'a');
        $this->assertTrue($result);
    }

    public function testContainsReturnsTrueForNumberSubstring()
    {
        $result = Comparisons::contains(12345, 23);
        $this->assertTrue($result);
    }

    public function testContainsReturnsFalseForReverseNumberSubstring()
    {
        $result = Comparisons::contains(23, 12345);
        $this->assertFalse($result);
    }

    public function testContainsReturnsTrueForReverseNumberSubstringWithNegation()
    {
        $result = Comparisons::contains(23, 12345, true);
        $this->assertTrue($result);
    }

    public function testContainsReturnsTrueForEmptyTestAgainst()
    {
        $result = Comparisons::contains('abcde', '');
        $this->assertTrue($result);
    }

    // isIn() tests
    public function testIsInReturnsTrueForSameNumber()
    {
        $result = Comparisons::isIn(23, 23);
        $this->assertTrue($result);
    }

    public function testIsInReturnsTrueForDelimitedString()
    {
        $result = Comparisons::isIn('a', 'a|b|c|d|e');
        $this->assertTrue($result);
    }

    public function testIsInReturnsFalseForDelimitedStringWithNegation()
    {
        $result = Comparisons::isIn('a', 'a|b|c|d|e', true);
        $this->assertFalse($result);
    }

    public function testIsInReturnsTrueForDelimitedStringArray()
    {
        $result = Comparisons::isIn('a|c', 'a|b|c|d|e');
        $this->assertTrue($result);
    }

    public function testIsInReturnsFalseForMismatchedArrayAndString()
    {
        $result = Comparisons::isIn('orange', ['ab', 'cd', 'ef']);
        $this->assertFalse($result);
    }

    public function testIsInReturnsTrueForNegatedMismatchedArrayAndString()
    {
        $result = Comparisons::isIn('orange', ['ab', 'cd', 'ef'], true);
        $this->assertTrue($result);
    }

    public function testIsInReturnsTrueForDelimitedStringAgainstArray()
    {
        $result = Comparisons::isIn('ab|ef', ['ab', 'cd', 'ef']);
        $this->assertTrue($result);
    }

    public function testIsInReturnsFalseForEmptyArrayAgainstEmptyString()
    {
        $result = Comparisons::isIn('', []);
        $this->assertFalse($result);
    }

    public function testIsInReturnsFalseForCommaSplitterMismatch1()
    {
        $result = Comparisons::isIn('ab|ef', ['ab', 'cd', 'ef'], false, ',');
        $this->assertFalse($result);
    }

    public function testIsInReturnsFalseForCommaSplitterMismatch2()
    {
        $result = Comparisons::isIn('a|c', 'a|b|c|d|e', false, ',');
        $this->assertFalse($result);
    }

    public function testIsInReturnsTrueForCommaDelimitedComparisonString()
    {
        $result = Comparisons::isIn('a,c', 'a,b,c,d,e', false, ',');
        $this->assertTrue($result);
    }

    public function testIsInReturnsTrueForCommaDelimitedComparisonAgainstArray()
    {
        $result = Comparisons::isIn('ab,ef', ['ab', 'cd', 'ef'], false, ',');
        $this->assertTrue($result);
    }

    public function testIsInReturnsTrueForNumberInArray()
    {
        $result = Comparisons::isIn(456, [123, 456, 789]);
        $this->assertTrue($result);
    }

    public function testIsInReturnsFalseForNumberAgainstObject()
    {
        // Passing an object should return false.
        $result = Comparisons::isIn(456, (object)['foo' => 'bar']);
        $this->assertFalse($result);
    }

    // startsWith() tests
    public function testStartsWithReturnsTrueForNumberPrefix()
    {
        $result = Comparisons::startsWith(12345678, 12);
        $this->assertTrue($result);
    }

    public function testStartsWithReturnsTrueForStringPrefix()
    {
        $result = Comparisons::startsWith('orange is fruit', 'orange');
        $this->assertTrue($result);
    }

    public function testStartsWithIsCaseInsensitive()
    {
        $result = Comparisons::startsWith('oRaNgE is fruit', 'ORANGE');
        $this->assertTrue($result);
    }

    public function testStartsWithReturnsFalseForNonMatchingPrefix()
    {
        $result = Comparisons::startsWith('orange is fruit', 'is');
        $this->assertFalse($result);
    }

    public function testStartsWithReturnsTrueForNegatedNonMatchingPrefix()
    {
        $result = Comparisons::startsWith('orange is fruit', 'is', true);
        $this->assertTrue($result);
    }

    public function testStartsWithReturnsTrueForEmptyTestAgainst()
    {
        $result = Comparisons::startsWith('orange is fruit', '');
        $this->assertTrue($result);
    }

    // endsWith() tests
    public function testEndsWithReturnsFalseForNonMatchingSuffix()
    {
        $result = Comparisons::endsWith(12345678, 4567);
        $this->assertFalse($result);
    }

    public function testEndsWithReturnsTrueForNegatedNonMatchingSuffix()
    {
        $result = Comparisons::endsWith(12345678, 4567, true);
        $this->assertTrue($result);
    }

    public function testEndsWithReturnsTrueForMatchingSuffix()
    {
        $result = Comparisons::endsWith(12345678, 45678);
        $this->assertTrue($result);
    }

    public function testEndsWithReturnsFalseForNegatedMatchingSuffix()
    {
        $result = Comparisons::endsWith(12345678, 45678, true);
        $this->assertFalse($result);
    }

    public function testEndsWithReturnsTrueForStringSuffixCaseInsensitive()
    {
        $result = Comparisons::endsWith('orange is fruit', 'FRUIT');
        $this->assertTrue($result);
    }

    public function testEndsWithReturnsFalseForNonMatchingStringSuffix()
    {
        $result = Comparisons::endsWith('orange is fruit', 'is');
        $this->assertFalse($result);
    }

    public function testEndsWithReturnsTrueForEmptyTestAgainstSuffix()
    {
        $result = Comparisons::endsWith('orange is fruit', '');
        $this->assertTrue($result);
    }

    // regexMatches() tests
    public function testRegexMatchesReturnsFalseForInvalidRegex()
    {
        $result = Comparisons::regexMatches('/?wwww', 'orange');
        $this->assertFalse($result);
    }

    public function testRegexMatchesReturnsTrueForWordCharacters()
    {
        $result = Comparisons::regexMatches('orange', '\\w+');
        $this->assertTrue($result);
    }

    public function testRegexMatchesReturnsTrueForWordCharactersWithExclamation()
    {
        $result = Comparisons::regexMatches('An APPle!', '\\w+');
        $this->assertTrue($result);
    }

    public function testRegexMatchesReturnsTrueForNumbers()
    {
        $result = Comparisons::regexMatches(111222333, '\\d+');
        $this->assertTrue($result);
    }

    public function testRegexMatchesReturnsFalseForNumbersWithNegation()
    {
        $result = Comparisons::regexMatches(111222333, '\\d+', true);
        $this->assertFalse($result);
    }

    public function testRegexMatchesEmailValidation1()
    {
        $result = Comparisons::regexMatches(
            'test@email.com',
            "^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\\.[a-zA-Z0-9-]+)*$"
        );
        $this->assertTrue($result);
    }

    public function testRegexMatchesEmailValidation2()
    {
        $result = Comparisons::regexMatches(
            'more.complex.e-mail123@subdomain.email.com',
            "^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\\.[a-zA-Z0-9-]+)*$"
        );
        $this->assertTrue($result);
    }

    public function testRegexMatchesInvalidEmail()
    {
        $result = Comparisons::regexMatches(
            'Not an email',
            "^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\\.[a-zA-Z0-9-]+)*$"
        );
        $this->assertFalse($result);
    }

    public function testRegexMatchesWrongEmailFormat()
    {
        $result = Comparisons::regexMatches(
            'wrong()\\Email.co@m',
            "^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\\.[a-zA-Z0-9-]+)*$"
        );
        $this->assertFalse($result);
    }
}
