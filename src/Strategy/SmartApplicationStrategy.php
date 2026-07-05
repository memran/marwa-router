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
    /** @var array<string, int> cached parameter counts keyed by callable identity */
    private static array $paramCountCache = [];

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
        $key = $this->cacheKey($controller);

        if (isset(self::$paramCountCache[$key])) {
            return self::$paramCountCache[$key];
        }

        if (is_array($controller)) {
            $count = (new ReflectionMethod($controller[0], $controller[1]))->getNumberOfParameters();
        } elseif ($controller instanceof \Closure) {
            $count = (new ReflectionFunction($controller))->getNumberOfParameters();
        } elseif (is_string($controller)) {
            $count = (new ReflectionFunction($controller))->getNumberOfParameters();
        } else {
            assert(is_object($controller));
            $count = (new ReflectionMethod($controller, '__invoke'))->getNumberOfParameters();
        }

        self::$paramCountCache[$key] = $count;

        return $count;
    }

    private function cacheKey(mixed $controller): string
    {
        if (is_array($controller)) {
            $class = is_object($controller[0]) ? get_class($controller[0]) : $controller[0];

            return $class . '::' . $controller[1];
        }
        if ($controller instanceof \Closure) {
            return 'closure#' . spl_object_id($controller);
        }
        if (is_object($controller)) {
            return get_class($controller);
        }

        return (string) $controller;
    }
}
