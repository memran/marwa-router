<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\Uri;
use Marwa\Router\Middleware\AuthTokenMiddleware;
use Marwa\Router\Middleware\BodyParsingMiddleware;
use Marwa\Router\Middleware\ContentTypeMiddleware;
use Marwa\Router\Middleware\CorsMiddleware;
use Marwa\Router\Middleware\CsrfMiddleware;
use Marwa\Router\Middleware\ExceptionToResponseMiddleware;
use Marwa\Router\Middleware\MaintenanceModeMiddleware;
use Marwa\Router\Middleware\RequestGuardMiddleware;
use Marwa\Router\Middleware\RequestIdMiddleware;
use Marwa\Router\Middleware\SecurityHeadersMiddleware;
use Marwa\Router\Response;
use Marwa\Router\RouterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\SimpleCache\CacheInterface;

final class MiddlewareIntegrationTest extends TestCase
{
    public function testBodyParsingRunsBeforeHandlerAndThrottleBlocksSecondRequest(): void
    {
        $logger = new TestLogger();
        $router = new RouterFactory(cache: new ArrayCache());
        $router->setLogger($logger);

        $router->map(
            'POST',
            '/api/users',
            static function (ServerRequest $request): ResponseInterface {
                $payload = $request->getParsedBody();

                return \Marwa\Router\Response::json([
                    'name' => is_array($payload) ? ($payload['name'] ?? null) : null,
                ]);
            },
            middlewares: [new BodyParsingMiddleware()],
            throttle: ['limit' => 1, 'per' => 60, 'key' => 'ip'],
        );

        $first = $router->handle($this->jsonRequest('/api/users', ['name' => 'Marwa']));
        self::assertSame(200, $first->getStatusCode());
        self::assertStringContainsString('"name":"Marwa"', (string) $first->getBody());

        $second = $router->handle($this->jsonRequest('/api/users', ['name' => 'Marwa']));
        self::assertSame(429, $second->getStatusCode());
        self::assertSame('Throttle limit exceeded.', $logger->records[0]['message'] ?? null);
    }

    public function testContentTypeMiddlewareRejectsUnsupportedMediaTypeAndInvalidJson(): void
    {
        $router = new RouterFactory();
        $router->map(
            'POST',
            '/api/posts',
            static fn (ServerRequest $request): ResponseInterface => Response::json([
                'payload' => $request->getParsedBody(),
            ]),
            middlewares: [new ContentTypeMiddleware()],
        );

        $unsupported = $router->handle($this->requestWithBody(
            '/api/posts',
            'POST',
            '{"title":"Example"}',
            [
                'Content-Type' => ['text/plain'],
                'Host' => ['example.com'],
            ],
        ));

        self::assertSame(415, $unsupported->getStatusCode());
        self::assertStringContainsString('Unsupported Media Type', (string) $unsupported->getBody());

        $invalidJson = $router->handle($this->requestWithBody(
            '/api/posts',
            'POST',
            '{"title":',
            [
                'Content-Type' => ['application/json'],
                'Host' => ['example.com'],
            ],
        ));

        self::assertSame(400, $invalidJson->getStatusCode());
        self::assertStringContainsString('Invalid JSON', (string) $invalidJson->getBody());
    }

    public function testRequestGuardMiddlewareRejectsBadHostsAndOversizedPayloads(): void
    {
        $router = new RouterFactory();
        $router->map(
            'POST',
            '/admin/users',
            static fn (): ResponseInterface => Response::text('guarded'),
            middlewares: [new RequestGuardMiddleware()],
        );

        $badHost = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/admin/users'),
            'POST',
            'php://memory',
            ['Host' => ['bad host']],
        ));

        self::assertSame(400, $badHost->getStatusCode());
        self::assertStringContainsString('Bad Host header', (string) $badHost->getBody());

        $tooLarge = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/admin/users'),
            'POST',
            'php://memory',
            [
                'Host' => ['example.com'],
                'Content-Length' => ['2000001'],
            ],
        ));

        self::assertSame(413, $tooLarge->getStatusCode());
        self::assertStringContainsString('Payload Too Large', (string) $tooLarge->getBody());
    }

    public function testSecurityHeadersMiddlewareAddsConservativeHeaders(): void
    {
        $router = new RouterFactory();
        $router->map(
            'GET',
            '/secure',
            static fn (): ResponseInterface => Response::text('ok'),
            middlewares: [new SecurityHeadersMiddleware()],
        );

        $response = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/secure'),
            'GET',
            'php://memory',
            ['Host' => ['example.com']],
        ));

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('geolocation=(), microphone=()', $response->getHeaderLine('Permissions-Policy'));
        self::assertSame(
            "default-src 'self'; frame-ancestors 'none'; base-uri 'self'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testCorsMiddlewareHandlesPreflightAndDecoratesSimpleRequests(): void
    {
        $router = new RouterFactory();
        $router->map(
            ['GET', 'OPTIONS'],
            '/api/profile',
            static fn (): ResponseInterface => Response::text('ok'),
            middlewares: [
                new CorsMiddleware(
                    allowedOrigins: ['https://app.example.com'],
                    allowedMethods: ['GET', 'OPTIONS'],
                    allowedHeaders: ['Content-Type', 'X-Trace-Id'],
                    exposedHeaders: ['X-Trace-Id'],
                    allowCredentials: true,
                    maxAge: 600,
                ),
            ],
        );

        $preflight = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://api.example.com/api/profile'),
            'OPTIONS',
            'php://memory',
            [
                'Host' => ['api.example.com'],
                'Origin' => ['https://app.example.com'],
                'Access-Control-Request-Method' => ['GET'],
                'Access-Control-Request-Headers' => ['Content-Type, X-Trace-Id'],
            ],
        ));

        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('https://app.example.com', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $preflight->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('GET, OPTIONS', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, X-Trace-Id', $preflight->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $preflight->getHeaderLine('Access-Control-Max-Age'));

        $simple = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://api.example.com/api/profile'),
            'GET',
            'php://memory',
            [
                'Host' => ['api.example.com'],
                'Origin' => ['https://app.example.com'],
            ],
        ));

        self::assertSame(200, $simple->getStatusCode());
        self::assertSame('https://app.example.com', $simple->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $simple->getHeaderLine('Vary'));
        self::assertSame('X-Trace-Id', $simple->getHeaderLine('Access-Control-Expose-Headers'));
    }

    public function testRequestIdMiddlewarePreservesIncomingIdAndGeneratesMissingIds(): void
    {
        $router = new RouterFactory();
        $router->map(
            'GET',
            '/trace',
            static fn (ServerRequest $request): ResponseInterface => Response::json([
                'request_id' => $request->getAttribute('request_id'),
            ]),
            middlewares: [new RequestIdMiddleware()],
        );

        $provided = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/trace'),
            'GET',
            'php://memory',
            [
                'Host' => ['example.com'],
                'X-Request-Id' => ['incoming-123'],
            ],
        ));

        self::assertSame('incoming-123', $provided->getHeaderLine('X-Request-Id'));
        self::assertStringContainsString('"request_id":"incoming-123"', (string) $provided->getBody());

        $generated = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/trace'),
            'GET',
            'php://memory',
            ['Host' => ['example.com']],
        ));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $generated->getHeaderLine('X-Request-Id'));
    }

    public function testExceptionToResponseMiddlewareConvertsThrowablesIntoJsonResponses(): void
    {
        $router = new RouterFactory();
        $router->map(
            'GET',
            '/boom',
            static function (): ResponseInterface {
                throw new \RuntimeException('Database offline');
            },
            middlewares: [new ExceptionToResponseMiddleware()],
        );

        $response = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/boom'),
            'GET',
            'php://memory',
            ['Host' => ['example.com']],
        ));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('"success":false', (string) $response->getBody());
        self::assertStringContainsString('"message":"Internal server error"', (string) $response->getBody());
    }

    public function testCsrfMiddlewareIssuesTokenCookieAndRejectsMismatches(): void
    {
        $router = new RouterFactory();
        $router->map(
            ['GET', 'POST'],
            '/form',
            static fn (): ResponseInterface => Response::text('submitted'),
            middlewares: [new CsrfMiddleware()],
        );

        $bootstrap = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/form'),
            'GET',
            'php://memory',
            ['Host' => ['example.com']],
        ));

        $cookie = $bootstrap->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('csrf_token=', $cookie);

        preg_match('/csrf_token=([^;]+)/', $cookie, $matches);
        $token = $matches[1] ?? null;
        self::assertNotNull($token);

        $valid = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/form'),
            'POST',
            'php://memory',
            [
                'Host' => ['example.com'],
                'Cookie' => ['csrf_token=' . $token],
                'X-CSRF-Token' => [$token],
            ],
            [],
            [],
            ['_csrf' => $token],
        ));

        self::assertSame(200, $valid->getStatusCode());

        $invalid = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/form'),
            'POST',
            'php://memory',
            [
                'Host' => ['example.com'],
                'Cookie' => ['csrf_token=' . $token],
                'X-CSRF-Token' => ['wrong-token'],
            ],
        ));

        self::assertSame(419, $invalid->getStatusCode());
        self::assertStringContainsString('CSRF token mismatch', (string) $invalid->getBody());
    }

    public function testAuthTokenMiddlewareAcceptsBearerTokenAndRejectsMissingCredentials(): void
    {
        $router = new RouterFactory();
        $router->map(
            'GET',
            '/private',
            static fn (ServerRequest $request): ResponseInterface => Response::json([
                'actor' => $request->getAttribute('auth_token_identity'),
            ]),
            middlewares: [new AuthTokenMiddleware(['secret-token' => 'service-a'])],
        );

        $authorized = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/private'),
            'GET',
            'php://memory',
            [
                'Host' => ['example.com'],
                'Authorization' => ['Bearer secret-token'],
            ],
        ));

        self::assertSame(200, $authorized->getStatusCode());
        self::assertStringContainsString('"actor":"service-a"', (string) $authorized->getBody());

        $unauthorized = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/private'),
            'GET',
            'php://memory',
            ['Host' => ['example.com']],
        ));

        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertSame('Bearer', $unauthorized->getHeaderLine('WWW-Authenticate'));
    }

    public function testMaintenanceModeMiddlewareBlocksRequestsUnlessBypassed(): void
    {
        $router = new RouterFactory();
        $router->map(
            'GET',
            '/status',
            static fn (): ResponseInterface => Response::text('ok'),
            middlewares: [
                new MaintenanceModeMiddleware(
                    enabled: true,
                    except: [
                        static fn (ServerRequest $request): bool => $request->getHeaderLine('X-Maintenance-Bypass') === '1',
                    ],
                    retryAfter: 120,
                ),
            ],
        );

        $blocked = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/status'),
            'GET',
            'php://memory',
            ['Host' => ['example.com']],
        ));

        self::assertSame(503, $blocked->getStatusCode());
        self::assertSame('120', $blocked->getHeaderLine('Retry-After'));

        $bypassed = $router->handle(new ServerRequest(
            [],
            [],
            new Uri('https://example.com/status'),
            'GET',
            'php://memory',
            [
                'Host' => ['example.com'],
                'X-Maintenance-Bypass' => ['1'],
            ],
        ));

        self::assertSame(200, $bypassed->getStatusCode());
    }

    private function jsonRequest(string $path, array $payload): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return new ServerRequest(
            ['REMOTE_ADDR' => '203.0.113.10'],
            [],
            new Uri('https://example.com' . $path),
            'POST',
            $stream,
            ['Content-Type' => ['application/json']],
        );
    }

    /**
     * @param array<string, array<string>> $headers
     */
    private function requestWithBody(string $path, string $method, string $body, array $headers): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return new ServerRequest(
            [],
            [],
            new Uri('https://example.com' . $path),
            $method,
            $stream,
            $headers,
        );
    }
}

final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get((string) $key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }
}

final class TestLogger extends AbstractLogger
{
    /** @var list<array{level:string, message:string, context:array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
