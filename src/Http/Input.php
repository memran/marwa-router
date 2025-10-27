<?php

declare(strict_types=1);

namespace Marwa\Router\Http;

use Laminas\Diactoros\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Static Laravel-like Input facade for PSR-7 requests.
 * Uses Laminas Diactoros under the hood.
 *
 * Example:
 *   Input::get('q')
 *   Input::post('name')
 *   Input::file('avatar')
 *   Input::all()
 */
final class Input
{
    private static ?ServerRequestInterface $request = null;

    /**
     * Initialize Input from a PSR-7 request.
     * Typically done at bootstrap or in middleware.
     */
    public static function setRequest(ServerRequestInterface $request): void
    {
        self::$request = $request;
    }

    /**
     * Ensure we have a PSR-7 request.
     */
    private static function getRequest(): ServerRequestInterface
    {
        if (self::$request === null) {
            // Default fallback from globals
            self::$request = ServerRequestFactory::fromGlobals();
        }
        return self::$request;
    }

    /**
     * Get merged query + body input data.
     *
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        $req = self::getRequest();
        $body = $req->getParsedBody();
        $bodyArray = is_array($body) ? $body : [];
        return array_merge($req->getQueryParams(), $bodyArray);
    }

    /**
     * Get input value from query/body.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::all();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * Get query-only parameter.
     */
    public static function query(string $key, mixed $default = null): mixed
    {
        $params = self::getRequest()->getQueryParams();
        return $params[$key] ?? $default;
    }

    /**
     * Get body-only parameter (POST, JSON, etc.)
     */
    public static function post(string $key, mixed $default = null): mixed
    {
        $body = self::getRequest()->getParsedBody();
        if (!is_array($body)) {
            return $default;
        }
        return $body[$key] ?? $default;
    }

    /**
     * Get uploaded file(s).
     */
    public static function file(?string $key = null): mixed
    {
        $files = self::getRequest()->getUploadedFiles();
        return $key === null ? $files : ($files[$key] ?? null);
    }

    /**
     * Get route parameter set by the router.
     */
    public static function route(string $key, mixed $default = null): mixed
    {
        return self::getRequest()->getAttribute($key) ?? $default;
    }

    /**
     * Get a cookie value.
     */
    public static function cookie(string $key, mixed $default = null): mixed
    {
        $cookies = self::getRequest()->getCookieParams();
        return $cookies[$key] ?? $default;
    }

    /**
     * Get header value (first match).
     */
    public static function header(string $key, mixed $default = null): mixed
    {
        $values = self::getRequest()->getHeader($key);
        return $values[0] ?? $default;
    }

    /**
     * HTTP method (GET, POST, etc.)
     */
    public static function method(): string
    {
        return strtoupper(self::getRequest()->getMethod());
    }

    /**
     * Full request URL.
     */
    public static function url(): string
    {
        return (string) self::getRequest()->getUri();
    }

    /**
     * Reset for testing.
     */
    public static function reset(): void
    {
        self::$request = null;
    }
}
