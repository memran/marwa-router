<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use Closure;
use League\Route\Dispatcher;
use League\Route\Route;

/**
 * league/route dispatcher with three additions:
 *
 * - optional trailing slash: "/users/" is matched as "/users", so each route
 *   is registered once instead of once per slash variant;
 * - segment groups: with data from CompiledRouteTable::collectDispatchData(),
 *   only the variable routes for the request's first path segment are tried;
 * - route ids: handlers in precompiled data are route ids, and only the
 *   route that matched is built.
 *
 * @internal
 */
final class RouteDispatcher extends Dispatcher
{
    /** @var array<string, array<string, mixed>> first path segment => variable route data */
    private array $segmentRouteData = [];

    /** @var array<string, mixed> variable route data for segments without their own group */
    private array $fallbackRouteData = [];

    /**
     * @param array<mixed> $data segment-grouped data, or plain FastRoute data [static, variable]
     * @param null|Closure(int): Route $routeResolver
     */
    public function __construct(
        array $data,
        private readonly bool $trailingSlashOptional = false,
        private readonly ?Closure $routeResolver = null,
    ) {
        if (array_key_exists('segments', $data)) {
            /** @var array{static:array<string, array<string, int>>, fallback:array<string, mixed>, segments:array<string, array<string, mixed>>} $data */
            $this->segmentRouteData = $data['segments'];
            $this->fallbackRouteData = $data['fallback'];
            $data = [$data['static'], $data['fallback']];
        }

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
        if ($this->trailingSlashOptional) {
            $uri = self::normalizePath($uri);
        }

        if ($this->segmentRouteData !== []) {
            $this->variableRouteData = $this->segmentRouteData[CompiledRouteTable::requestSegment($uri)] ?? $this->fallbackRouteData;
        }

        return parent::dispatch($httpMethod, $uri);
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
