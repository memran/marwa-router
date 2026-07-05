<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Marwa\Router\Middleware\AuthTokenMiddleware;
use Marwa\Router\Middleware\CorsMiddleware;
use Marwa\Router\Middleware\CsrfMiddleware;
use Marwa\Router\Middleware\RequestGuardMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewareExtendedTest extends TestCase
{
    // --- AuthTokenMiddleware ---

    public function testAuthTokenAcceptsXApiKeyHeader(): void
    {
        $middleware = new AuthTokenMiddleware(['my-api-key']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('X-API-Key', 'my-api-key')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAuthTokenRejectsQueryToken(): void
    {
        $middleware = new AuthTokenMiddleware(['my-token']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withUri(new Uri('https://example.com/test?api_token=my-token'));

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAuthTokenWithIdentityMapping(): void
    {
        $middleware = new AuthTokenMiddleware([
            'token-a' => 'service-a',
            'token-b' => 'service-b',
        ]);
        $handler = $this->captureHandler(function (ServerRequest $request): void {
            self::assertSame('service-b', $request->getAttribute('auth_token_identity'));
        });

        $request = (new ServerRequest())
            ->withHeader('Authorization', 'Bearer token-b')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAuthTokenArrayStyleDoesNotSetIdentity(): void
    {
        $middleware = new AuthTokenMiddleware(['token1', 'token2']);
        $handler = $this->captureHandler(function (ServerRequest $request): void {
            self::assertNull($request->getAttribute('auth_token_identity'));
        });

        $request = (new ServerRequest())
            ->withHeader('Authorization', 'Bearer token1')
            ->withUri(new Uri('https://example.com/test'));

        $middleware->process($request, $handler);
    }

    public function testAuthTokenRejectsMalformedAuthorizationHeader(): void
    {
        $middleware = new AuthTokenMiddleware(['my-token']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('Authorization', 'Basic dXNlcjpwYXNz')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    // --- CorsMiddleware ---

    public function testCorsWildcardOrigin(): void
    {
        $middleware = new CorsMiddleware(['*']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('Origin', 'https://any-domain.com')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $response->getHeaderLine('Vary'));
    }

    public function testCorsRejectsUnknownOrigin(): void
    {
        $middleware = new CorsMiddleware(['https://allowed.com']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('Origin', 'https://evil.com')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        // Non-preflight: passes through to handler without CORS headers
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsPreflightRejectsUnknownOrigin(): void
    {
        $middleware = new CorsMiddleware(['https://allowed.com']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('Origin', 'https://evil.com')
            ->withMethod('OPTIONS')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCorsPreflightRejectsDisallowedMethod(): void
    {
        $middleware = new CorsMiddleware(['*'], allowedMethods: ['GET', 'POST']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('Origin', 'https://any.com')
            ->withMethod('OPTIONS')
            ->withHeader('Access-Control-Request-Method', 'DELETE')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCorsNoOriginHeaderPassesThrough(): void
    {
        $middleware = new CorsMiddleware(['*']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsCredentialsSuppressedWithWildcardOrigin(): void
    {
        $middleware = new CorsMiddleware(['*'], allowCredentials: true);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('Origin', 'https://any.com')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    // --- CsrfMiddleware ---

    public function testCsrfBodyFieldTokenSubmission(): void
    {
        $middleware = new CsrfMiddleware();
        $handler = $this->successHandler();

        // First, get a valid cookie token
        $get = (new ServerRequest())->withUri(new Uri('https://example.com/test'));
        $getResponse = $middleware->process($get, $handler);

        $cookies = $getResponse->getHeader('Set-Cookie');
        self::assertNotEmpty($cookies);
        preg_match('/csrf_token=([a-f0-9]+)/', $cookies[0], $m);
        $token = $m[1];

        // Submit via body field
        $parsedBody = ['_csrf' => $token];
        $post = (new ServerRequest())
            ->withMethod('POST')
            ->withHeader('Cookie', "csrf_token={$token}")
            ->withParsedBody($parsedBody)
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($post, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCsrfRejectsPostWithEmptyCookie(): void
    {
        $middleware = new CsrfMiddleware();
        $handler = $this->successHandler();

        $post = (new ServerRequest())
            ->withMethod('POST')
            ->withHeader('X-CSRF-Token', 'some-token')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($post, $handler);

        self::assertSame(419, $response->getStatusCode());
    }

    // --- RequestGuardMiddleware ---

    public function testRequestGuardRejectsDisallowedMethod(): void
    {
        $middleware = new RequestGuardMiddleware(allowedMethods: ['GET', 'POST']);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withMethod('TRACE')
            ->withHeader('Host', 'example.com')
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame(405, $response->getStatusCode());
    }

    public function testRequestGuardRejectsControlCharsInPath(): void
    {
        $middleware = new RequestGuardMiddleware();
        $handler = $this->successHandler();

        $uri = new class () implements \Psr\Http\Message\UriInterface {
            public string $path = '/test';
            public function __toString(): string
            {
                return '';
            }
            public function getScheme(): string
            {
                return 'https';
            }
            public function getAuthority(): string
            {
                return 'example.com';
            }
            public function getUserInfo(): string
            {
                return '';
            }
            public function getHost(): string
            {
                return 'example.com';
            }
            public function getPort(): ?int
            {
                return null;
            }
            public function getPath(): string
            {
                return $this->path;
            }
            public function getQuery(): string
            {
                return '';
            }
            public function getFragment(): string
            {
                return '';
            }
            public function withScheme(string $scheme): self
            {
                return $this;
            }
            public function withUserInfo(string $user, ?string $password = null): self
            {
                return $this;
            }
            public function withHost(string $host): self
            {
                return $this;
            }
            public function withPort(?int $port): self
            {
                return $this;
            }
            public function withPath(string $path): self
            {
                $this->path = $path;
                return $this;
            }
            public function withQuery(string $query): self
            {
                return $this;
            }
            public function withFragment(string $fragment): self
            {
                return $this;
            }
        };
        $uri->path = "/test\x03injected";

        $request = (new ServerRequest())
            ->withHeader('Host', 'example.com')
            ->withUri($uri);

        $response = $middleware->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testRequestGuardRejectsControlCharsInQuery(): void
    {
        $middleware = new RequestGuardMiddleware();
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('Host', 'example.com')
            ->withQueryParams(['key' => "value\x03injected"])
            ->withUri(new Uri('https://example.com/test'));

        $response = $middleware->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testRequestGuardNormalizesPath(): void
    {
        $handledRequest = null;
        $handler = $this->captureHandler(function (ServerRequest $request) use (&$handledRequest): void {
            $handledRequest = $request;
        });

        $middleware = new RequestGuardMiddleware();
        $request = (new ServerRequest())
            ->withHeader('Host', 'example.com')
            ->withUri(new Uri('https://example.com//foo/../bar/./baz'));

        $middleware->process($request, $handler);

        self::assertNotNull($handledRequest);
        self::assertSame('/bar/baz', $handledRequest->getUri()->getPath());
    }

    public function testRequestGuardAllowsControlCharsWhenDisabled(): void
    {
        $middleware = new RequestGuardMiddleware(rejectControlChars: false);
        $handler = $this->successHandler();

        $request = (new ServerRequest())
            ->withHeader('Host', 'example.com')
            ->withUri(new Uri("https://example.com/test\x00injected"));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    // --- Helpers ---

    private function successHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                return new Response\TextResponse('ok');
            }
        };
    }

    private function captureHandler(\Closure $assertions): RequestHandlerInterface
    {
        return new class ($assertions) implements RequestHandlerInterface {
            public function __construct(
                private readonly \Closure $assertions,
            ) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                ($this->assertions)($request);

                return new Response\TextResponse('ok');
            }
        };
    }
}
