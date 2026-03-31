<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\Uri;
use Marwa\Router\Middleware\BodyParsingMiddleware;
use Marwa\Router\Middleware\ContentTypeMiddleware;
use Marwa\Router\Middleware\RequestGuardMiddleware;
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
