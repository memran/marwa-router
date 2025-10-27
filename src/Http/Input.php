<?php

declare(strict_types=1);

namespace Marwa\Router\Http;

use Laminas\Diactoros\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Static Laravel-style Input facade built on top of HttpRequest.
 *
 * Example:
 *   Input::get('q');
 *   Input::post('name');
 *   Input::all();
 *   Input::route('id');
 *   Input::header('User-Agent');
 */
final class Input
{
    private static ?HttpRequest $instance = null;

    /**
     * Bind a PSR-7 request to Input.
     */
    public static function setRequest(ServerRequestInterface $request): void
    {
        self::$instance = new HttpRequest($request);
    }

    /**
     * Get the internal HttpRequest instance.
     */
    private static function http(): HttpRequest
    {
        if (self::$instance === null) {
            $req = ServerRequestFactory::fromGlobals();
            self::$instance = new HttpRequest($req);
        }
        return self::$instance;
    }

    /**
     * Reset for testing or rebind.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // --- Proxy methods to HttpRequest ---

    public static function all(): array
    {
        return self::http()->all();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::http()->input($key, $default);
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        // Body-only access
        $body = self::http()->psr()->getParsedBody();
        if (!is_array($body)) {
            return $default;
        }
        return $body[$key] ?? $default;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return self::http()->query($key, $default);
    }

    public static function route(string $key, mixed $default = null): mixed
    {
        return self::http()->route($key, $default);
    }

    public static function file(?string $key = null): mixed
    {
        return self::http()->file($key);
    }

    public static function cookie(string $key, mixed $default = null): mixed
    {
        return self::http()->cookie($key, $default);
    }

    public static function header(string $key, mixed $default = null): mixed
    {
        return self::http()->header($key, $default);
    }

    public static function method(): string
    {
        return self::http()->method();
    }

    public static function url(): string
    {
        return self::http()->url();
    }

    public static function only(array $keys): array
    {
        return self::http()->only($keys);
    }

    public static function except(array $keys): array
    {
        return self::http()->except($keys);
    }
}
