<?php

declare(strict_types=1);

namespace ConvertSdk\Cache;

use Psr\SimpleCache\CacheInterface;

final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiry: ?int}> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->store[$key]['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $expiry = null;

        if ($ttl instanceof \DateInterval) {
            $expiry = time() + (new \DateTime())->setTimestamp(0)->add($ttl)->getTimestamp();
        } elseif (is_int($ttl)) {
            if ($ttl <= 0) {
                $this->delete($key);
                return true;
            }
            $expiry = time() + $ttl;
        }

        $this->store[$key] = ['value' => $value, 'expiry' => $expiry];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->store)) {
            return false;
        }

        if ($this->store[$key]['expiry'] !== null && $this->store[$key]['expiry'] < time()) {
            unset($this->store[$key]);
            return false;
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }
}
