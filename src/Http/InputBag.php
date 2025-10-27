<?php

declare(strict_types=1);

namespace Marwa\Router\Http;

/**
 * Immutable typed accessors with sane defaults.
 */
final class InputBag
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function string(string $key, string $default = ''): string
    {
        if (!$this->has($key)) {
            return $default;
        }
        return (string)$this->data[$key];
    }

    public function int(string $key, int $default = 0): int
    {
        if (!$this->has($key)) {
            return $default;
        }
        return (int)$this->data[$key];
    }

    public function float(string $key, float $default = 0.0): float
    {
        if (!$this->has($key)) {
            return $default;
        }
        return (float)$this->data[$key];
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        $val = $this->data[$key];
        if (is_bool($val)) {
            return $val;
        }
        $str = strtolower((string)$val);
        return in_array($str, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function array(string $key, array $default = []): array
    {
        if (!$this->has($key)) {
            return $default;
        }
        $val = $this->data[$key];
        return is_array($val) ? $val : $default;
    }
}
