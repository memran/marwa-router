<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use Closure;
use League\Route\Dispatcher;
use League\Route\Route;

/**
 * Dispatcher for precompiled FastRoute data whose handlers are route ids.
 *
 * Route objects are only built for the route that actually matched.
 *
 * @internal
 */
final class CompiledDispatcher extends Dispatcher
{
    /**
     * @param array<int, mixed> $data
     * @param Closure(int): Route $routeResolver
     */
    public function __construct(array $data, private readonly Closure $routeResolver)
    {
        parent::__construct($data);
    }

    /**
     * @param mixed $matchingHandler
     * @param string $httpMethod
     * @param string $uri
     */
    #[\Override]
    protected function ensureHandlerIsRoute($matchingHandler, $httpMethod, $uri): Route
    {
        if (is_int($matchingHandler)) {
            return ($this->routeResolver)($matchingHandler);
        }

        return parent::ensureHandlerIsRoute($matchingHandler, $httpMethod, $uri);
    }
}
