<?php

declare(strict_types=1);

namespace Marwa\Router\Tests\Fixtures;

use Psr\SimpleCache\CacheInterface;

/**
 * Minimal in-memory PSR-16 cache for tests.
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get($key, $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->data[$key] = $value;

        return true;
    }

    public function delete($key): bool
    {
        unset($this->data[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->data = [];

        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key): bool
    {
        return isset($this->data[$key]);
    }
}
