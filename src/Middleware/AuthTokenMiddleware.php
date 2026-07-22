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
        private readonly string $attributeName = 'auth_token',
        private readonly string $identityAttributeName = 'auth_token_identity',
        private readonly string $realm = 'Bearer',
    ) {
        // Note: PHP silently casts numeric-string array keys to int, so
        // ['12345' => 'id'] arrives as [12345 => 'id']. Use array_is_list()
        // to distinguish list form from token => identity form, and always
        // store keys as strings (findToken() casts back when comparing).
        $isList = array_is_list($tokens);

        foreach ($tokens as $key => $value) {
            if ($isList) {
                $this->tokens[(string) $value] = null;
                continue;
            }

            $this->tokens[(string) $key] = $value;
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return (new JsonResponse(['message' => 'Unauthorized'], 401))
                ->withHeader('WWW-Authenticate', $this->realm);
        }

        $matchedToken = $this->findToken($token);
        if ($matchedToken === null) {
            return (new JsonResponse(['message' => 'Unauthorized'], 401))
                ->withHeader('WWW-Authenticate', $this->realm);
        }

        $identity = $this->tokens[$matchedToken];
        $request = $request->withAttribute($this->attributeName, $token);

        if ($identity !== null) {
            $request = $request->withAttribute($this->identityAttributeName, $identity);
        }

        return $handler->handle($request);
    }

    /**
     * Constant-time token lookup to prevent timing attacks.
     *
     * @return string|null the matched token key, or null if not found
     */
    private function findToken(string $token): ?string
    {
        foreach ($this->tokens as $validToken => $identity) {
            // Array keys for purely numeric tokens are ints; cast to string
            // so hash_equals() never receives an int and comparison is exact.
            $validTokenString = (string) $validToken;
            if (hash_equals($validTokenString, $token)) {
                return $validTokenString;
            }
        }

        return null;
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

        return null;
    }
}
