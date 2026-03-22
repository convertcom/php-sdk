<?php

declare(strict_types=1);

use ConvertSdk\Utils\ObjectUtils;
use PHPUnit\Framework\TestCase;

class ObjectUtilsTest extends TestCase
{
    // Test objectDeepValue method
    public function testObjectDeepValueShouldReturnMatchingValueWithProvidedPath()
    {
        $obj = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
            ],
        ];

        $res = ObjectUtils::objectDeepValue($obj, 'api.endpoint');
        $this->assertEquals($obj['api']['endpoint'], $res);
    }

    public function testObjectDeepValueShouldReturnDefaultWhenPathNotFound()
    {
        $obj = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
            ],
        ];

        $defaultValue = 'default value';
        $res = ObjectUtils::objectDeepValue($obj, 'api.notFound', $defaultValue);
        $this->assertEquals($defaultValue, $res);
    }

    public function testObjectDeepValueShouldConsiderZeroAsNormalValue()
    {
        $obj = [
            'api' => [
                'maxResults' => 0,
            ],
        ];

        $res = ObjectUtils::objectDeepValue($obj, 'api.maxResults', 1, true);
        $this->assertEquals($obj['api']['maxResults'], $res);
    }

    public function testObjectDeepValueShouldConsiderFalseAsNormalValue()
    {
        $obj = [
            'api' => [
                'hasLimit' => false,
            ],
        ];

        $res = ObjectUtils::objectDeepValue($obj, 'api.hasLimit', 0, true);
        $this->assertEquals($obj['api']['hasLimit'], $res);
    }

    // Test objectDeepMerge method
    public function testObjectDeepMergeShouldMergeObjectsAndTheirKeys()
    {
        $obj1 = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        $obj2 = [
            'api' => [
                'maxResults' => 3,
            ],
            'test' => true,
        ];

        $expected = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
                'maxResults' => 3,
            ],
            'test' => true,
        ];

        $res = ObjectUtils::objectDeepMerge($obj1, $obj2);
        $this->assertEquals($expected, $res);
    }

    // Test objectNotEmpty method
    public function testObjectNotEmptyShouldReturnTrueForNonEmptyArray()
    {
        $obj = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
            ],
        ];

        $res = ObjectUtils::objectNotEmpty($obj);
        $this->assertTrue($res);
    }

    public function testObjectNotEmptyShouldReturnFalseForEmptyArray()
    {
        $obj = [];

        $res = ObjectUtils::objectNotEmpty($obj);
        $this->assertFalse($res);
    }

    // Test objectDeepEqual method
    public function testObjectDeepEqualShouldReturnTrueForEqualObjects()
    {
        $obj1 = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        $obj2 = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
            ],
        ];

        $res = ObjectUtils::objectDeepEqual($obj1, $obj2);
        $this->assertTrue($res);
    }

    public function testObjectDeepEqualShouldReturnFalseForDifferentObjects()
    {
        $obj1 = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        $obj2 = [
            'api' => [
                'endpoint' => 'Different value',
            ],
        ];

        $res = ObjectUtils::objectDeepEqual($obj1, $obj2);
        $this->assertFalse($res);
    }

    public function testObjectDeepEqualShouldReturnFalseForDifferentKeys()
    {
        $obj1 = [
            'api' => [
                'endpoint' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        $obj2 = [
            'api' => [
                'otherKey' => 'Different value',
            ],
        ];

        $res = ObjectUtils::objectDeepEqual($obj1, $obj2);
        $this->assertFalse($res);
    }

    public function testObjectDeepEqualShouldReturnFalseWhenOneIsNull(): void
    {
        $this->assertFalse(ObjectUtils::objectDeepEqual(null, ['a' => 1]));
        $this->assertFalse(ObjectUtils::objectDeepEqual(['a' => 1], null));
    }

    public function testObjectDeepEqualShouldReturnTrueForIdenticalScalars(): void
    {
        $this->assertTrue(ObjectUtils::objectDeepEqual(42, 42));
        $this->assertTrue(ObjectUtils::objectDeepEqual('hello', 'hello'));
    }

    public function testObjectDeepEqualShouldReturnFalseForDifferentSizedArrays(): void
    {
        $this->assertFalse(ObjectUtils::objectDeepEqual(['a' => 1], ['a' => 1, 'b' => 2]));
    }

    public function testObjectDeepMergeShouldHandleNumericArrays(): void
    {
        $obj1 = ['items' => [1, 2, 3]];
        $obj2 = ['items' => [4, 5]];

        // Mirrors JS SDK: [...new Set([...oVal, ...pVal])] — new values first, deduplicated
        $result = ObjectUtils::objectDeepMerge($obj1, $obj2);
        $this->assertSame([4, 5, 1, 2, 3], $result['items']);
    }

    public function testObjectDeepMergeShouldDeduplicateNumericArrays(): void
    {
        $obj1 = ['locations' => ['pricing', 'events']];
        $obj2 = ['locations' => ['pricing', 'stats']];

        $result = ObjectUtils::objectDeepMerge($obj1, $obj2);
        $this->assertSame(['pricing', 'stats', 'events'], $result['locations']);
    }

    public function testObjectDeepMergeShouldNotGrowExponentially(): void
    {
        $data = ['locations' => ['loc-a']];

        // Simulate repeated merges like putData() does on each runExperience call
        for ($i = 0; $i < 20; $i++) {
            $data = ObjectUtils::objectDeepMerge($data, ['locations' => ['loc-a']]);
        }

        // Without deduplication this would be 2^20 = 1,048,576 entries
        $this->assertCount(1, $data['locations']);
        $this->assertSame(['loc-a'], $data['locations']);
    }

    public function testObjectDeepMergeShouldHandleOverwritingScalarWithArray(): void
    {
        $obj1 = ['key' => 'scalar'];
        $obj2 = ['key' => ['nested' => 'value']];

        $result = ObjectUtils::objectDeepMerge($obj1, $obj2);
        $this->assertSame(['nested' => 'value'], $result['key']);
    }

    public function testObjectDeepValueShouldReturnDefaultForEmptyArray(): void
    {
        $res = ObjectUtils::objectDeepValue([], 'any.path', 'default');
        $this->assertSame('default', $res);
    }

    public function testObjectDeepValueShouldReturnDefaultForFalsyValueWithoutTruthy(): void
    {
        $obj = ['val' => 0];
        $res = ObjectUtils::objectDeepValue($obj, 'val', 'default', false);
        $this->assertSame('default', $res);
    }

    public function testObjectNotEmptyShouldReturnTrueForNonEmptyObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $this->assertTrue(ObjectUtils::objectNotEmpty($obj));
    }

    public function testObjectNotEmptyShouldReturnFalseForEmptyObject(): void
    {
        $obj = new \stdClass();
        $this->assertFalse(ObjectUtils::objectNotEmpty($obj));
    }

    public function testObjectNotEmptyShouldReturnFalseForNonArrayNonObject(): void
    {
        $this->assertFalse(ObjectUtils::objectNotEmpty('string'));
        $this->assertFalse(ObjectUtils::objectNotEmpty(42));
        $this->assertFalse(ObjectUtils::objectNotEmpty(null));
    }
}
