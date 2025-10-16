<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Adds conservative security headers (tweak CSP for your app).
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $csp = "default-src 'self'; frame-ancestors 'none'; base-uri 'self'"
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $res = $handler->handle($request);
        return $res
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=()')
            ->withHeader('Content-Security-Policy', $this->csp);
    }
}
