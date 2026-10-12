<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use Closure;
use League\Route\Dispatcher;
use League\Route\Route;

/**
 * league/route dispatcher with two additions:
 *
 * - optional trailing slash: "/users/" is matched as "/users", so each route
 *   is registered once instead of once per slash variant;
 * - compiled routes: handlers in precompiled data are route ids, and only
 *   the route that matched is built.
 *
 * @internal
 */
final class RouteDispatcher extends Dispatcher
{
    /**
     * @param array<int, mixed> $data
     * @param null|Closure(int): Route $routeResolver
     */
    public function __construct(
        array $data,
        private readonly bool $trailingSlashOptional = false,
        private readonly ?Closure $routeResolver = null,
    ) {
        parent::__construct($data);
    }

    /**
     * Strip a single trailing slash, keeping the root path intact.
     */
    public static function normalizePath(string $path): string
    {
        return strlen($path) > 1 && str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
    }

    /**
     * @param string $httpMethod
     * @param string $uri
     * @return array<int, mixed>
     */
    #[\Override]
    public function dispatch($httpMethod, $uri): array
    {
        return parent::dispatch($httpMethod, $this->trailingSlashOptional ? self::normalizePath($uri) : $uri);
    }

    /**
     * @param mixed $matchingHandler
     * @param string $httpMethod
     * @param string $uri
     */
    #[\Override]
    protected function ensureHandlerIsRoute($matchingHandler, $httpMethod, $uri): Route
    {
        if ($this->routeResolver !== null && is_int($matchingHandler)) {
            return ($this->routeResolver)($matchingHandler);
        }

        return parent::ensureHandlerIsRoute($matchingHandler, $httpMethod, $uri);
    }
}
