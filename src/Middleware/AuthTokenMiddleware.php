<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthTokenMiddleware implements MiddlewareInterface
{
    /** @var array<string, string|null> */
    private array $tokens = [];

    /**
     * @param list<string>|array<string, string> $tokens
     */
    public function __construct(
        array $tokens,
        private string $attributeName = 'auth_token',
        private string $identityAttributeName = 'auth_token_identity',
        private string $realm = 'Bearer',
    ) {
        foreach ($tokens as $key => $value) {
            if (is_int($key)) {
                $this->tokens[(string) $value] = null;
                continue;
            }

            $this->tokens[$key] = $value;
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null || !array_key_exists($token, $this->tokens)) {
            return (new JsonResponse(['message' => 'Unauthorized'], 401))
                ->withHeader('WWW-Authenticate', $this->realm);
        }

        $identity = $this->tokens[$token];
        $request = $request->withAttribute($this->attributeName, $token);

        if ($identity !== null) {
            $request = $request->withAttribute($this->identityAttributeName, $identity);
        }

        return $handler->handle($request);
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        $apiKey = trim($request->getHeaderLine('X-API-Key'));
        if ($apiKey !== '') {
            return $apiKey;
        }

        $queryParams = $request->getQueryParams();
        $queryToken = $queryParams['api_token'] ?? null;

        return is_string($queryToken) && $queryToken !== '' ? $queryToken : null;
    }
}
