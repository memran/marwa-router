<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $safeMethods
     */
    public function __construct(
        private readonly string $cookieName = 'csrf_token',
        private readonly string $attributeName = 'csrf_token',
        private readonly string $headerName = 'X-CSRF-Token',
        private readonly string $bodyField = '_csrf',
        private readonly array $safeMethods = ['GET', 'HEAD', 'OPTIONS'],
        private readonly bool $secureCookie = false,
        private readonly string $sameSite = 'Lax',
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookieToken = $this->readCookieToken($request);
        $method = strtoupper($request->getMethod());

        if (in_array($method, $this->safeMethods, true)) {
            $token = $cookieToken !== '' ? $cookieToken : bin2hex(random_bytes(32));
            $request = $request->withAttribute($this->attributeName, $token);
            $response = $handler->handle($request);

            if ($cookieToken === '') {
                $response = $response->withAddedHeader('Set-Cookie', $this->buildCookieHeader($token, $request));
            }

            return $response;
        }

        $submittedToken = $this->resolveSubmittedToken($request);
        if ($cookieToken === '' || $submittedToken === '' || !hash_equals($cookieToken, $submittedToken)) {
            return new JsonResponse(['message' => 'CSRF token mismatch'], 419);
        }

        return $handler->handle($request->withAttribute($this->attributeName, $cookieToken));
    }

    private function resolveSubmittedToken(ServerRequestInterface $request): string
    {
        $headerToken = trim($request->getHeaderLine($this->headerName));
        if ($headerToken !== '') {
            return $headerToken;
        }

        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody) && isset($parsedBody[$this->bodyField]) && is_string($parsedBody[$this->bodyField])) {
            return trim($parsedBody[$this->bodyField]);
        }

        return '';
    }

    private function readCookieToken(ServerRequestInterface $request): string
    {
        $cookieParams = $request->getCookieParams();
        if (isset($cookieParams[$this->cookieName]) && is_string($cookieParams[$this->cookieName])) {
            return trim($cookieParams[$this->cookieName]);
        }

        $cookieHeader = $request->getHeaderLine('Cookie');
        if ($cookieHeader === '') {
            return '';
        }

        foreach (explode(';', $cookieHeader) as $segment) {
            $parts = explode('=', trim($segment), 2);
            if ($parts[0] === $this->cookieName) {
                return urldecode($parts[1] ?? '');
            }
        }

        return '';
    }

    private function buildCookieHeader(string $token, ServerRequestInterface $request): string
    {
        $parts = [
            rawurlencode($this->cookieName) . '=' . rawurlencode($token),
            'Path=/',
            'HttpOnly',
            'SameSite=' . $this->sameSite,
        ];

        $isSecure = $this->secureCookie || $this->isSecureRequest($request);
        if ($isSecure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function isSecureRequest(ServerRequestInterface $request): bool
    {
        $uri = $request->getUri();
        if ($uri->getScheme() === 'https') {
            return true;
        }
        $forwardedProto = $request->getHeaderLine('X-Forwarded-Proto');
        if ($forwardedProto !== '' && strtolower($forwardedProto) === 'https') {
            return true;
        }
        return false;
    }
}
