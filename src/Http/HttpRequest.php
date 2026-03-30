<?php

declare(strict_types=1);

namespace Marwa\Router\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * HttpRequest wraps a PSR-7 ServerRequestInterface and exposes
 * Laravel-style convenience methods.
 *
 * Single responsibility: ergonomic data access.
 * It does NOT mutate the underlying PSR-7 request.
 */
final class HttpRequest
{
    private ServerRequestInterface $request;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    /**
     * Get the underlying PSR-7 request.
     */
    public function psr(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * Get all input data (query + parsed body) merged.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $body = $this->bodyArray();
        return array_merge($this->request->getQueryParams(), $body);
    }

    /**
     * Get a single input value from query/body, with default.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $data = $this->all();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * Only some keys.
     *
     * @param array<int,string> $keys
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        $out = [];
        $data = $this->all();
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        return $out;
    }

    /**
     * Everything except some keys.
     *
     * @param array<int,string> $keys
     * @return array<string,mixed>
     */
    public function except(array $keys): array
    {
        $skip = array_flip($keys);
        $out = [];
        foreach ($this->all() as $k => $v) {
            if (!array_key_exists($k, $skip)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Get route param (placeholder in URI like /user/{id}).
     */
    public function route(string $key, mixed $default = null): mixed
    {
        $val = $this->request->getAttribute($key);
        return $val === null ? $default : $val;
    }

    /**
     * Get all route params as array.
     *
     * @return array<string,mixed>
     */
    public function routeParams(): array
    {
        // League\Route puts the whole param bag under 'params' too.
        // We try both for safety.
        $attr = $this->request->getAttributes();
        if (isset($attr['params']) && is_array($attr['params'])) {
            return $attr['params'];
        }

        // Fallback: collect scalar attributes
        $out = [];
        foreach ($attr as $k => $v) {
            if (is_scalar($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Get query param.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        $query = $this->request->getQueryParams();
        return array_key_exists($key, $query) ? $query[$key] : $default;
    }

    /**
     * Get cookie value.
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        $cookies = $this->request->getCookieParams();
        return array_key_exists($key, $cookies) ? $cookies[$key] : $default;
    }

    /**
     * Get uploaded file(s).
     *
     * - $key given => return that file or null
     * - no key     => return all files array
     */
    public function file(?string $key = null): mixed
    {
        $files = $this->request->getUploadedFiles();
        if ($key === null) {
            return $files;
        }
        return $files[$key] ?? null;
    }

    /**
     * HTTP method (GET, POST, PUT...)
     */
    public function method(): string
    {
        return strtoupper($this->request->getMethod());
    }

    /**
     * Full URL string.
     */
    public function url(): string
    {
        return (string) $this->request->getUri();
    }

    /**
     * Host name without port.
     */
    public function host(): string
    {
        return $this->request->getUri()->getHost();
    }

    /**
     * Best-effort subdomain extraction.
     *
     * Returns the leftmost label when the host contains at least three labels,
     * for example "tenant" from "tenant.example.com".
     */
    public function subdomain(): ?string
    {
        $host = $this->host();
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 3) {
            return null;
        }

        $subdomain = trim($parts[0]);

        return $subdomain !== '' ? $subdomain : null;
    }

    /**
     * Resolve the subdomain relative to a known base domain.
     *
     * Examples:
     * - host "tenant.example.com" with base "example.com" => "tenant"
     * - host "admin.eu.example.com" with base "example.com" => "admin.eu"
     * - host "example.com" with base "example.com" => null
     */
    public function subdomainFor(string $baseDomain): ?string
    {
        $host = strtolower(trim($this->host()));
        $baseDomain = strtolower(trim($baseDomain, " \t\n\r\0\x0B."));

        if ($host === '' || $baseDomain === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || filter_var($baseDomain, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if ($host === $baseDomain) {
            return null;
        }

        $suffix = '.' . $baseDomain;
        if (!str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));
        $subdomain = trim($subdomain, '.');

        return $subdomain !== '' ? $subdomain : null;
    }

    /**
     * Header helper.
     *
     * If $key provided → first header value or default.
     * If no $key → all headers as array<string,string[]>
     */
    public function header(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->getHeaders();
        }

        $values = $this->request->getHeader($key);
        if ($values === []) {
            return $default;
        }
        return $values[0];
    }

    /**
     * Internal: normalized body array.
     *
     * @return array<string,mixed>
     */
    private function bodyArray(): array
    {
        $parsed = $this->request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        // if JSON middleware already parsed into [] or {}
        if ($parsed instanceof \ArrayObject) {
            return $parsed->getArrayCopy();
        }
        return [];
    }
}
