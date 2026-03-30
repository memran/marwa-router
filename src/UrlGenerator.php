<?php

declare(strict_types=1);

namespace Marwa\Router;

final class UrlGenerator
{
    /** @param array<int, array{path:string,name:?string}> $routes */
    public function __construct(private array $routes) {}

    /**
     * @param array<string, scalar|null> $params
     */
    public function for(string $name, array $params = []): string
    {
        foreach ($this->routes as $route) {
            if ($route['name'] !== $name) {
                continue;
            }

            $path = $route['path'] ?: '/';
            $url = preg_replace_callback(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
                static function (array $match) use (&$params): string {
                    $key = $match[1];
                    if (!array_key_exists($key, $params)) {
                        throw new \InvalidArgumentException("Missing route param: {$key}");
                    }

                    $value = (string) $params[$key];
                    unset($params[$key]);

                    return rawurlencode($value);
                },
                $path,
            );

            if ($url === null) {
                throw new \RuntimeException('Failed to compile route URL.');
            }

            if (!empty($params)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            }

            return $url;
        }

        throw new \RuntimeException("Route not found by name: {$name}");
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function signed(string $name, array $params, int $ttl, string $key): string
    {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('Signed URL TTL must be greater than zero.');
        }

        $url = $this->for($name, $params);
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \RuntimeException('Unable to parse generated URL.');
        }

        $exp = time() + $ttl;
        $query = $this->parseQuery($parts['query'] ?? null);
        $query['exp'] = (string) $exp;
        $signature = hash_hmac('sha256', $this->signaturePayload($parts['path'] ?? '/', $query), $key);
        $query['sig'] = $signature;

        return $this->buildUrl($parts, $query);
    }

    public function verify(string $url, string $key): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $query = $this->parseQuery($parts['query'] ?? null);
        $userSig = $query['sig'] ?? null;
        $exp = $query['exp'] ?? null;
        unset($query['sig']);

        if (!is_string($userSig) || !is_string($exp) || !ctype_digit($exp)) {
            return false;
        }

        if (time() > (int) $exp) {
            return false;
        }

        $generatedSig = hash_hmac('sha256', $this->signaturePayload($parts['path'] ?? '/', $query), $key);

        return hash_equals($generatedSig, $userSig);
    }

    /**
     * @return array<string, string>
     */
    private function parseQuery(?string $queryString): array
    {
        if ($queryString === null || $queryString === '') {
            return [];
        }

        parse_str($queryString, $query);

        $result = [];
        foreach ($query as $key => $value) {
            if (is_scalar($value)) {
                $result[(string) $key] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $query
     */
    private function signaturePayload(string $path, array $query): string
    {
        ksort($query);

        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, int|string> $parts
     * @param array<string, string> $query
     */
    private function buildUrl(array $parts, array $query): string
    {
        $path = (string) ($parts['path'] ?? '/');
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $queryString === '' ? $path : $path . '?' . $queryString;
    }
}
