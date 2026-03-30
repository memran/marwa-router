<?php

declare(strict_types=1);

namespace Examples\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startedAt = microtime(true);
        $response = $handler->handle($request);
        $milliseconds = (int) ((microtime(true) - $startedAt) * 1000);

        return $response->withHeader('X-Response-Time', $milliseconds . 'ms');
    }
}
