<?php

declare(strict_types=1);

namespace Examples\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final class ApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(private string $header = 'X-API-Key', private string $expected = 'secret') {}
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine($this->header) !== $this->expected) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }
        return $handler->handle($request);
    }
}
