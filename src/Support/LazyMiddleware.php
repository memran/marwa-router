<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Defers middleware construction until the first request that needs it.
 *
 * One proxy is shared by every route using the same middleware identifier,
 * so registering thousands of routes does not instantiate thousands of
 * middleware objects up front. The identifier, not the class: two routes
 * requiring different permissions from the same class need separate proxies.
 *
 * @internal
 */
final class LazyMiddleware implements MiddlewareInterface
{
    private ?MiddlewareInterface $instance = null;

    /**
     * @param string $identifier a class string or `Class:arg1,arg2`, used to name the middleware in errors
     * @param Closure(): object $factory
     */
    public function __construct(
        private readonly string $identifier,
        private readonly Closure $factory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->resolve()->process($request, $handler);
    }

    public function resolve(): MiddlewareInterface
    {
        if ($this->instance !== null) {
            return $this->instance;
        }

        $middleware = ($this->factory)();
        if (!$middleware instanceof MiddlewareInterface) {
            throw new \UnexpectedValueException(sprintf(
                'Middleware %s must implement %s.',
                $this->identifier,
                MiddlewareInterface::class,
            ));
        }

        return $this->instance = $middleware;
    }
}
