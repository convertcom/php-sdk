<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Cache;

use ConvertSdk\Cache\ArrayCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class ArrayCacheTest extends TestCase
{
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    #[Test]
    public function implementsCacheInterface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cache);
    }

    #[Test]
    public function setAndGetRoundTrip(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertSame('value1', $this->cache->get('key1'));
    }

    #[Test]
    public function getReturnsDefaultWhenKeyMissing(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
        $this->assertSame('fallback', $this->cache->get('nonexistent', 'fallback'));
    }

    #[Test]
    public function ttlExpiryRemovesValue(): void
    {
        $this->cache->set('expiring', 'data', 1);
        $this->assertSame('data', $this->cache->get('expiring'));

        sleep(2);

        $this->assertNull($this->cache->get('expiring'));
    }

    #[Test]
    public function hasReturnsFalseForExpiredKeys(): void
    {
        $this->cache->set('temp', 'val', 1);
        $this->assertTrue($this->cache->has('temp'));

        sleep(2);

        $this->assertFalse($this->cache->has('temp'));
    }

    #[Test]
    public function deleteRemovesKey(): void
    {
        $this->cache->set('toDelete', 'val');
        $this->assertTrue($this->cache->has('toDelete'));

        $this->cache->delete('toDelete');
        $this->assertFalse($this->cache->has('toDelete'));
    }

    #[Test]
    public function clearRemovesAllKeys(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->clear();

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    #[Test]
    public function getMultipleReturnsMultipleValues(): void
    {
        $this->cache->set('x', 10);
        $this->cache->set('y', 20);

        $result = $this->cache->getMultiple(['x', 'y', 'z'], 'default');

        $this->assertSame(10, $result['x']);
        $this->assertSame(20, $result['y']);
        $this->assertSame('default', $result['z']);
    }

    #[Test]
    public function setMultipleSetsMultipleValues(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);

        $this->assertSame(1, $this->cache->get('a'));
        $this->assertSame(2, $this->cache->get('b'));
    }

    #[Test]
    public function deleteMultipleRemovesMultipleKeys(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->set('c', 3);

        $this->cache->deleteMultiple(['a', 'b']);

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
        $this->assertTrue($this->cache->has('c'));
    }

    #[Test]
    public function nullTtlStoresWithoutExpiry(): void
    {
        $this->cache->set('forever', 'value', null);
        $this->assertSame('value', $this->cache->get('forever'));

        // Should still be there (no expiry)
        $this->assertTrue($this->cache->has('forever'));
    }

    #[Test]
    public function zeroOrNegativeTtlDeletesImmediately(): void
    {
        $this->cache->set('existing', 'data');
        $this->cache->set('existing', 'newdata', 0);
        $this->assertFalse($this->cache->has('existing'));

        $this->cache->set('existing2', 'data');
        $this->cache->set('existing2', 'newdata', -1);
        $this->assertFalse($this->cache->has('existing2'));
    }

    #[Test]
    public function dateIntervalTtlWorks(): void
    {
        $interval = new \DateInterval('PT10S'); // 10 seconds
        $this->cache->set('interval_key', 'interval_value', $interval);
        $this->assertSame('interval_value', $this->cache->get('interval_key'));
    }

    #[Test]
    public function storesVariousTypes(): void
    {
        $this->cache->set('int', 42);
        $this->cache->set('float', 3.14);
        $this->cache->set('bool', true);
        $this->cache->set('array', ['a' => 1]);
        $this->cache->set('null_val', null);
        $object = new \stdClass();
        $object->foo = 'bar';
        $this->cache->set('object', $object);

        $this->assertSame(42, $this->cache->get('int'));
        $this->assertSame(3.14, $this->cache->get('float'));
        $this->assertSame(true, $this->cache->get('bool'));
        $this->assertSame(['a' => 1], $this->cache->get('array'));
        $this->assertNull($this->cache->get('null_val'));
        $this->assertSame($object, $this->cache->get('object'));
    }
}
