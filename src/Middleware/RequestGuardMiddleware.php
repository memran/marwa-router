<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Hard checks on method, path, headers, and payload size.
 */
final class RequestGuardMiddleware implements MiddlewareInterface
{
    private const CTL_PATTERN = '/[\x00-\x08\x0B-\x1F\x7F]/';
    private const HOST_PATTERN = '/^([a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\:\d{1,5})?$/';

    /** @param string[] $allowedMethods */
    public function __construct(
        private readonly array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly int $maxContentLength = 2_000_000, // 2 MB
        private readonly bool $rejectAmbiguousHosts = true,
        private readonly bool $rejectControlChars = true,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Method allowlist
        $method = strtoupper($request->getMethod());
        if (!in_array($method, $this->allowedMethods, true)) {
            return new JsonResponse(['message' => 'Method Not Allowed'], 405);
        }

        // Host header sanity (avoid header attacks / SSRF to internal hostnames via proxies)
        if ($this->rejectAmbiguousHosts) {
            $host = $request->getHeaderLine('Host');
            if (!$host || !preg_match(self::HOST_PATTERN, $host)) {
                return new JsonResponse(['message' => 'Bad Host header'], 400);
            }
        }

        // Content-Length limit (if present)
        $len = $request->getHeaderLine('Content-Length');
        if ($len !== '' && (int)$len > $this->maxContentLength) {
            return new JsonResponse(['message' => 'Payload Too Large'], 413);
        }

        // Path & query control characters
        if ($this->rejectControlChars) {
            $path = $request->getUri()->getPath();
            if ($this->hasCtl($path)) {
                return new JsonResponse(['message' => 'Bad Request'], 400);
            }
            foreach ($request->getQueryParams() as $k => $v) {
                if ($this->hasCtl((string)$k) || $this->hasCtl((string)$v)) {
                    return new JsonResponse(['message' => 'Bad Request'], 400);
                }
            }
        }

        // Normalize path (collapse //, resolve ./ ../ safely)
        $normalized = $this->normalizePath($request->getUri()->getPath());
        if ($normalized !== $request->getUri()->getPath()) {
            $uri = $request->getUri()->withPath($normalized);
            $request = $request->withUri($uri);
        }

        return $handler->handle($request);
    }

    private function hasCtl(string $s): bool
    {
        return (bool)preg_match(self::CTL_PATTERN, $s);
    }

    private function normalizePath(string $p): string
    {
        $parts = array_values(array_filter(explode('/', $p), fn ($x) => $x !== '' && $x !== '.'));
        $stack = [];
        foreach ($parts as $seg) {
            if ($seg === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $seg;
        }
        return '/' . implode('/', $stack);
    }
}
