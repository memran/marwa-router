<?php

declare(strict_types=1);

namespace Marwa\Router\Strategy;

use League\Route\Route;
use League\Route\Strategy\ApplicationStrategy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use ReflectionMethod;

final class SmartApplicationStrategy extends ApplicationStrategy
{
    /**
     * Cached parameter counts for object callables (closures, invokables).
     * A WeakMap prevents stale entries when object IDs are reused after
     * garbage collection and avoids unbounded growth in long-running workers.
     *
     * @var \WeakMap<object, int>|null
     */
    private static ?\WeakMap $objectParamCountCache = null;

    /** @var array<string, int> cached parameter counts for named callables (class::method, functions) */
    private static array $namedParamCountCache = [];

    #[\Override]
    public function invokeRouteCallable(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        $controller = $route->getCallable($this->getContainer());
        $vars = $route->getVars();

        $paramCount = $this->countCallableParameters($controller);

        $response = match ($paramCount) {
            0 => $controller(),
            1 => $controller($request),
            default => $controller($request, $vars),
        };

        return $this->decorateResponse($response);
    }

    private function countCallableParameters(callable $controller): int
    {
        if (is_array($controller)) {
            $key = (is_object($controller[0]) ? get_class($controller[0]) : $controller[0]) . '::' . $controller[1];
            if (isset(self::$namedParamCountCache[$key])) {
                return self::$namedParamCountCache[$key];
            }

            return self::$namedParamCountCache[$key] =
                (new ReflectionMethod($controller[0], $controller[1]))->getNumberOfParameters();
        }

        if (is_string($controller)) {
            if (isset(self::$namedParamCountCache[$controller])) {
                return self::$namedParamCountCache[$controller];
            }

            return self::$namedParamCountCache[$controller] =
                (new ReflectionFunction($controller))->getNumberOfParameters();
        }

        // Closures and invokable objects: keyed by object identity in a WeakMap.
        assert(is_object($controller));
        self::$objectParamCountCache ??= new \WeakMap();

        if (isset(self::$objectParamCountCache[$controller])) {
            return self::$objectParamCountCache[$controller];
        }

        $count = $controller instanceof \Closure
            ? (new ReflectionFunction($controller))->getNumberOfParameters()
            : (new ReflectionMethod($controller, '__invoke'))->getNumberOfParameters();

        return self::$objectParamCountCache[$controller] = $count;
    }
}
