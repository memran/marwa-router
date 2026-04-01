<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MaintenanceModeMiddleware implements MiddlewareInterface
{
    /** @var bool|callable(ServerRequestInterface): bool */
    private mixed $enabled;

    /** @var array<int, callable(ServerRequestInterface): bool> */
    private array $except;

    /**
     * @param bool|callable(ServerRequestInterface): bool $enabled
     * @param array<int, callable(ServerRequestInterface): bool> $except
     */
    public function __construct(
        bool|callable $enabled = true,
        array $except = [],
        private ?int $retryAfter = null,
        private string $message = 'Service temporarily unavailable',
    ) {
        $this->enabled = $enabled;
        $this->except = $except;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isEnabled($request) || $this->isExcepted($request)) {
            return $handler->handle($request);
        }

        $response = new JsonResponse(['message' => $this->message], 503);

        if ($this->retryAfter !== null && $this->retryAfter >= 0) {
            $response = $response->withHeader('Retry-After', (string) $this->retryAfter);
        }

        return $response;
    }

    private function isEnabled(ServerRequestInterface $request): bool
    {
        return is_callable($this->enabled)
            ? (bool) ($this->enabled)($request)
            : $this->enabled;
    }

    private function isExcepted(ServerRequestInterface $request): bool
    {
        foreach ($this->except as $predicate) {
            if ($predicate($request)) {
                return true;
            }
        }

        return false;
    }
}
