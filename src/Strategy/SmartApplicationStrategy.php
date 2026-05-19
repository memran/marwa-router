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
        if (is_array($controller) && is_string($controller[1])) {
            $ref = new ReflectionMethod($controller[0], $controller[1]);
            return $ref->getNumberOfParameters();
        }

        if ($controller instanceof \Closure) {
            $ref = new ReflectionFunction($controller);
            return $ref->getNumberOfParameters();
        }

        if (is_string($controller)) {
            $ref = new ReflectionFunction($controller);
            return $ref->getNumberOfParameters();
        }

        if (is_object($controller) && !($controller instanceof \Closure)) {
            $ref = new ReflectionMethod($controller, '__invoke');
            return $ref->getNumberOfParameters();
        }

        return 2;
    }
}
