<?php

declare(strict_types=1);

namespace Examples\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $t0 = microtime(true);
        $res = $handler->handle($request);
        $ms = (int)((microtime(true) - $t0) * 1000);
        return $res->withHeader('X-Response-Time', "{$ms}ms");
    }
}
