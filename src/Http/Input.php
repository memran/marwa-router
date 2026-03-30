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
 *   Input::has('email');
 *   Input::merge(['foo' => 'bar']);
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

    /** @return array<string, mixed> */
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

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public static function only(array $keys): array
    {
        return self::http()->only($keys);
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public static function except(array $keys): array
    {
        return self::http()->except($keys);
    }

    // -----------------------------------------------------------------
    // 💡 NEW METHODS: has(), exists(), merge()
    // -----------------------------------------------------------------

    /**
     * Check if an input key exists and is NOT empty.
     */
    public static function has(string $key): bool
    {
        return self::hasKey(self::all(), $key);
    }

    /**
     * Check if an input key exists (even if empty).
     */
    public static function exists(string $key): bool
    {
        return self::hasKey(self::all(), $key);
    }

    /**
     * Merge new input data into current request (immutably).
     *
     * Example:
     *   Input::merge(['debug' => true]);
     *
     * Returns the new merged HttpRequest instance.
     *
     * @param array<string, mixed> $data
     */
    public static function merge(array $data): HttpRequest
    {
        $req = self::http()->psr();

        $body = $req->getParsedBody();
        $bodyArray = is_array($body) ? $body : [];

        // Merge and clone immutably
        $merged = array_merge($bodyArray, $data);
        $newReq = $req->withParsedBody($merged);

        // Rebind to the Input singleton
        self::setRequest($newReq);

        \assert(self::$instance instanceof HttpRequest);

        return self::$instance;
    }

    /**
     * Support dot-notation access without depending on external helpers.
     *
     * @param array<string, mixed> $data
     */
    private static function hasKey(array $data, string $key): bool
    {
        if ($key === '') {
            return false;
        }

        if (array_key_exists($key, $data)) {
            return true;
        }

        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }
}
