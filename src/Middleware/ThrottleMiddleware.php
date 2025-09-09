<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Psr\SimpleCache\CacheInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Laminas\Diactoros\Response\JsonResponse;

/**
 * PSR-16-based throttle.
 * Windowed counters using cache TTL; not strictly atomic across nodes,
 * but good for most APIs. For strict global limits, use a Redis-backed CacheInterface.
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CacheInterface $cache,
        private int $limit,
        private int $perSeconds = 60,
        private string $key = 'ip' // 'ip' or header name (e.g. 'X-API-Key')
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $bucketKey = $this->bucketKey($request);
        $ttl       = $this->perSeconds;

        $count = (int) ($this->cache->get($bucketKey, 0));

        if ($count >= $this->limit) {
            return new JsonResponse(['error' => 'Too Many Requests'], 429);
        }

        // best-effort increment
        $this->cache->set($bucketKey, $count + 1, $ttl);

        return $handler->handle($request);
    }

    private function bucketKey(ServerRequestInterface $req): string
    {
        $id = $this->key === 'ip'
            ? ($req->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0')
            : ($req->getHeaderLine($this->key) ?: ($req->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0'));

        $bucket = (int) floor(time() / max(1, $this->perSeconds));
        return 'throttle:' . $this->key . ':' . $id . ':' . $bucket . ':' . $this->perSeconds;
    }
}
