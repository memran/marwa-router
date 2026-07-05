<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16-based throttle.
 * Windowed counters using cache TTL; not strictly atomic across nodes,
 * but good for most APIs. For strict global limits, use a Redis-backed CacheInterface.
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $limit,
        private readonly int $perSeconds = 60,
        private readonly string $key = 'ip', // 'ip' or header name (e.g. 'X-API-Key')
        private readonly ?LoggerInterface $logger = null,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $bucketKey = $this->bucketKey($request);
        $ttl       = $this->ttlRemaining();

        $count = (int) ($this->cache->get($bucketKey, 0));

        if ($count >= $this->limit) {
            $this->logger?->warning('Throttle limit exceeded.', [
                'bucket' => $bucketKey,
                'limit' => $this->limit,
                'per_seconds' => $this->perSeconds,
                'key' => $this->key,
                'path' => $request->getUri()->getPath(),
                'method' => $request->getMethod(),
            ]);

            return new JsonResponse(['error' => 'Too Many Requests'], 429);
        }

        // best-effort increment
        $this->cache->set($bucketKey, $count + 1, $ttl);

        return $handler->handle($request);
    }

    private function ttlRemaining(): int
    {
        $now = time();

        return (int) floor(($now + $this->perSeconds) / $this->perSeconds) * $this->perSeconds - $now;
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
