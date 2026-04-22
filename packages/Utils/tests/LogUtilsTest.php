<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ArrayIterator;
use ConvertSdk\Utils\LogUtils;
use DateTime;
use DateTimeImmutable;
use JsonSerializable;
use OpenAPI\Client\Model\RuleElement;
use PHPUnit\Framework\TestCase;

class LogUtilsTest extends TestCase
{
    public function testNullPassesThrough(): void
    {
        $this->assertNull(LogUtils::toLoggable(null));
    }

    public function testIntPassesThrough(): void
    {
        $this->assertSame(42, LogUtils::toLoggable(42));
    }

    public function testFloatPassesThrough(): void
    {
        $this->assertSame(3.14, LogUtils::toLoggable(3.14));
    }

    public function testStringPassesThrough(): void
    {
        $this->assertSame('hello', LogUtils::toLoggable('hello'));
    }

    public function testBoolPassesThrough(): void
    {
        $this->assertTrue(LogUtils::toLoggable(true));
        $this->assertFalse(LogUtils::toLoggable(false));
    }

    public function testPlainNestedArrayPassesThrough(): void
    {
        $input = ['a' => 1, 'b' => ['c' => 'x', 'd' => [2, 3, 4]]];
        $this->assertSame($input, LogUtils::toLoggable($input));
    }

    public function testDateTimeImmutableFormatsAsAtom(): void
    {
        $dt = new DateTimeImmutable('2026-04-22T10:30:00+00:00');
        $this->assertSame('2026-04-22T10:30:00+00:00', LogUtils::toLoggable($dt));
    }

    public function testDateTimeFormatsAsAtom(): void
    {
        $dt = new DateTime('2026-04-22T10:30:00+00:00');
        $this->assertSame('2026-04-22T10:30:00+00:00', LogUtils::toLoggable($dt));
    }

    public function testOpenApiModelWithNarrowEnumMismatchDoesNotThrow(): void
    {
        // This is the entire point of LogUtils: if the OpenAPI ObjectSerializer path were used,
        // it would throw InvalidArgumentException for rule_type != 'js_condition'. toLoggable()
        // must bypass that via attributeMap() + getters() and surface the real value.
        $rule = $this->makeRuleElementWithRuleType('generic_text_key_value');

        $result = LogUtils::toLoggable($rule);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('rule_type', $result);
        $this->assertSame('generic_text_key_value', $result['rule_type']);
    }

    public function testOpenApiModelValuePreservedAcrossAllProperties(): void
    {
        $rule = $this->makeRuleElementWithRuleType('generic_text_key_value');
        $rule->offsetSet('value', 'events');
        $rule->offsetSet('key', 'location');
        $rule->offsetSet('matching', ['match_type' => 'matches', 'negated' => false]);

        $result = LogUtils::toLoggable($rule);

        $this->assertSame('generic_text_key_value', $result['rule_type']);
        $this->assertSame('events', $result['value']);
        $this->assertSame('location', $result['key']);
        $this->assertSame(['match_type' => 'matches', 'negated' => false], $result['matching']);
    }

    public function testArrayContainingOpenApiModelRecurses(): void
    {
        $rule = $this->makeRuleElementWithRuleType('generic_text_key_value');

        $result = LogUtils::toLoggable(['rules' => [$rule]]);

        $this->assertIsArray($result);
        $this->assertIsArray($result['rules']);
        $this->assertIsArray($result['rules'][0]);
        $this->assertSame('generic_text_key_value', $result['rules'][0]['rule_type']);
    }

    public function testTraversableIsIterated(): void
    {
        $iter = new ArrayIterator(['a' => 1, 'b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 2], LogUtils::toLoggable($iter));
    }

    public function testNonModelJsonSerializableRecursesViaJsonSerialize(): void
    {
        $obj = new class () implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['kind' => 'custom', 'values' => [1, 2, 3]];
            }
        };

        $this->assertSame(['kind' => 'custom', 'values' => [1, 2, 3]], LogUtils::toLoggable($obj));
    }

    public function testPlainObjectConvertsToArray(): void
    {
        $obj = (object)['alpha' => 'a', 'beta' => 'b'];
        $this->assertSame(['alpha' => 'a', 'beta' => 'b'], LogUtils::toLoggable($obj));
    }

    public function testEmptyArrayReturnsEmptyArray(): void
    {
        $this->assertSame([], LogUtils::toLoggable([]));
    }

    public function testJsonEncodeOnToLoggableResultDoesNotTriggerEnumValidation(): void
    {
        // Regression test mirroring the real bug: json_encode on the untouched model throws;
        // json_encode on toLoggable()'s result must succeed.
        $rule = $this->makeRuleElementWithRuleType('generic_text_key_value');

        $encoded = json_encode(LogUtils::toLoggable($rule));

        $this->assertNotFalse($encoded);
        $this->assertStringContainsString('"rule_type":"generic_text_key_value"', (string)$encoded);
    }

    /**
     * Build a real RuleElement and override its rule_type via offsetSet — same path the
     * ObjectSerializer uses when deserialising config responses from the API.
     */
    private function makeRuleElementWithRuleType(string $ruleType): RuleElement
    {
        $rule = new RuleElement();
        $rule->offsetSet('rule_type', $ruleType);
        return $rule;
    }
}
