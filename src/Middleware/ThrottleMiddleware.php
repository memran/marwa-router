<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lightweight, best-effort throttling.
 * - Uses APCu if available, otherwise temp files with flock().
 * - Key: client IP or provided header (e.g., X-API-Key).
 * Not bulletproof for heavy clusters, but fine for small/medium setups.
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int $limit,
        private int $perSeconds = 60,
        private string $key = 'ip'
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->resolveKey($request);
        $now = time();

        if (function_exists('apcu_fetch')) {
            $bucketKey = "throttle:{$key}:{$this->perBucket($now)}";
            $count = apcu_fetch($bucketKey);
            if ($count === false) {
                apcu_store($bucketKey, 1, $this->perSeconds);
            } else {
                if ($count >= $this->limit) {
                    return $this->tooMany();
                }
                apcu_inc($bucketKey);
            }
            return $handler->handle($request);
        }

        // File-based fallback
        $dir = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'marwa-throttle';
        @mkdir($dir, 0777, true);
        $file = $dir . DIRECTORY_SEPARATOR . md5($key . ':' . $this->perBucket($now)) . '.cnt';

        $fp = fopen($file, 'c+');
        if (!$fp) {
            // If unable to throttle, let it pass
            return $handler->handle($request);
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return $handler->handle($request);
            }
            $count = 0;
            $data = stream_get_contents($fp);
            if ($data !== false && $data !== '') {
                $count = (int)$data;
            }
            if ($count >= $this->limit) {
                return $this->tooMany();
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)($count + 1));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $handler->handle($request);
    }

    private function tooMany(): ResponseInterface
    {
        return new JsonResponse(['error' => 'Too Many Requests'], 429);
    }

    private function perBucket(int $now): int
    {
        return (int)floor($now / max(1, $this->perSeconds));
    }

    private function resolveKey(ServerRequestInterface $request): string
    {
        if ($this->key === 'ip') {
            return $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        // treat as header name
        $value = $request->getHeaderLine($this->key);
        if ($value === '') {
            // fallback to ip if header not present
            return $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        return $value;
    }
}
