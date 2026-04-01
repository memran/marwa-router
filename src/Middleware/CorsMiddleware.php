<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposedHeaders
     */
    public function __construct(
        private array $allowedOrigins = ['*'],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Request-Id'],
        private array $exposedHeaders = [],
        private bool $allowCredentials = false,
        private ?int $maxAge = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!$this->isAllowedOrigin($origin)) {
            return $this->isPreflight($request)
                ? new JsonResponse(['message' => 'CORS origin denied'], 403)
                : $handler->handle($request);
        }

        if ($this->isPreflight($request)) {
            $requestedMethod = strtolower(trim($request->getHeaderLine('Access-Control-Request-Method')));
            if ($requestedMethod === '' || !in_array($requestedMethod, $this->normalizeTokens($this->allowedMethods), true)) {
                return new JsonResponse(['message' => 'CORS method denied'], 403);
            }

            $requestedHeaders = $this->parseHeaderList($request->getHeaderLine('Access-Control-Request-Headers'));
            if (!$this->allowsHeaders($requestedHeaders)) {
                return new JsonResponse(['message' => 'CORS headers denied'], 403);
            }

            return $this->decorate($origin, new EmptyResponse(204), $requestedHeaders);
        }

        return $this->decorate($origin, $handler->handle($request));
    }

    /**
     * @param list<string> $requestedHeaders
     */
    private function decorate(string $origin, ResponseInterface $response, array $requestedHeaders = []): ResponseInterface
    {
        $response = $response->withHeader(
            'Access-Control-Allow-Origin',
            $this->allowedOrigins === ['*'] ? '*' : $origin,
        );

        if ($this->allowedOrigins !== ['*']) {
            $response = $response->withHeader('Vary', 'Origin');
        }

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($requestedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $requestedHeaders));
        } elseif ($this->allowedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
        }

        if ($this->allowedMethods !== []) {
            $response = $response->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        }

        if ($this->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        if ($this->maxAge !== null && $this->maxAge >= 0) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
        }

        return $response;
    }

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');
    }

    private function isAllowedOrigin(string $origin): bool
    {
        if ($this->allowedOrigins === ['*']) {
            return true;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * @param list<string> $requestedHeaders
     */
    private function allowsHeaders(array $requestedHeaders): bool
    {
        if ($requestedHeaders === []) {
            return true;
        }

        $allowed = $this->normalizeTokens($this->allowedHeaders);
        foreach ($requestedHeaders as $header) {
            if (!in_array(strtolower($header), $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function parseHeaderList(string $headerValue): array
    {
        if ($headerValue === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $headerValue),
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeTokens(array $values): array
    {
        return array_map(
            static fn (string $value): string => strtolower(trim($value)),
            $values,
        );
    }
}
