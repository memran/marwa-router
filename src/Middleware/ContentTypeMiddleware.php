<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Enforce content type for write methods; optional JSON parse with depth/size caps.
 */
final class ContentTypeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private bool $requireJsonForWrites = true,
        private int $maxJsonBytes = 1_000_000,
        private int $maxJsonDepth = 32
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if ($this->requireJsonForWrites && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $ct = $request->getHeaderLine('Content-Type');
            if ($ct && stripos($ct, 'application/json') === false) {
                return new JsonResponse(['message' => 'Unsupported Media Type'], 415);
            }

            // Safe JSON parsing if body is readable stream
            $body = (string)$request->getBody();
            if (strlen($body) > $this->maxJsonBytes) {
                return new JsonResponse(['message' => 'Payload Too Large'], 413);
            }
            if ($body !== '') {
                $data = json_decode($body, true, $this->maxJsonDepth, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    return new JsonResponse(['message' => 'Invalid JSON'], 400);
                }
                $request = $request->withParsedBody($data);
            }
        }
        return $handler->handle($request);
    }
}
