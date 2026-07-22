<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    /** @var \Closure():string|null */
    private readonly ?\Closure $generator;

    public function __construct(
        private readonly string $headerName = 'X-Request-Id',
        private readonly string $attributeName = 'request_id',
        ?callable $generator = null,
    ) {
        $this->generator = $generator !== null ? \Closure::fromCallable($generator) : null;
    }

    /**
     * Allowed shape for client-supplied request IDs (bounded length, safe
     * characters) to prevent log forging and oversized header reflection.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = trim($request->getHeaderLine($this->headerName));
        if ($requestId === '' || preg_match(self::ID_PATTERN, $requestId) !== 1) {
            $requestId = $this->generateRequestId();
        }

        $request = $request->withAttribute($this->attributeName, $requestId);
        $response = $handler->handle($request);

        return $response->withHeader($this->headerName, $requestId);
    }

    private function generateRequestId(): string
    {
        if ($this->generator !== null) {
            return (string) ($this->generator)();
        }

        return bin2hex(random_bytes(16));
    }
}
