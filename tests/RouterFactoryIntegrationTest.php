<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Attributes\Domain;
use Marwa\Router\Attributes\Prefix;
use Marwa\Router\Attributes\Route;
use Marwa\Router\Exceptions\InvalidNotFoundHandlerResponseException;
use Marwa\Router\Exceptions\RouteNotFoundException;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Response;
use Marwa\Router\RouterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

final class RouterFactoryIntegrationTest extends TestCase
{
    public function testHandleReturnsConfiguredNotFoundResponse(): void
    {
        $router = new RouterFactory();
        $router->setNotFoundHandler(static fn (): array => ['message' => 'missing']);

        $response = $router->handle(RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/missing', 'REQUEST_METHOD' => 'GET'],
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('"message":"missing"', (string) $response->getBody());
    }

    public function testHandleThrowsRouteNotFoundExceptionWithoutHandler(): void
    {
        $router = new RouterFactory();

        $this->expectException(RouteNotFoundException::class);

        $router->handle(RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/missing', 'REQUEST_METHOD' => 'GET'],
        ));
    }

    public function testHandleThrowsForInvalidNotFoundHandlerResponse(): void
    {
        $router = new RouterFactory();
        $router->setNotFoundHandler(static fn (): int => 123);

        $this->expectException(InvalidNotFoundHandlerResponseException::class);

        $router->handle(RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/missing', 'REQUEST_METHOD' => 'GET'],
        ));
    }

    public function testFluentDomainRouteDispatchesForMatchingHost(): void
    {
        $router = new RouterFactory();
        $router->map(
            'GET',
            '/status',
            static fn (): ResponseInterface => Response::text('ok'),
            name: 'status.show',
            domain: 'api.example.com',
        );

        $response = $router->handle(RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/status',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'api.example.com',
            ],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
    }

    public function testAttributeRoutesDispatchWithSameDomainRules(): void
    {
        $router = new RouterFactory();
        $router->registerFromClasses([AttributeDomainController::class]);

        $response = $router->handle(RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/admin/dashboard',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'admin.example.com',
            ],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('dashboard', (string) $response->getBody());
    }

    public function testCompiledRouteCacheCanRebuildDispatchRules(): void
    {
        $router = new RouterFactory();
        $router->registerFromClasses([AttributeDomainController::class]);

        $cacheFile = tempnam(sys_get_temp_dir(), 'compiled-routes');
        self::assertNotFalse($cacheFile);

        try {
            $router->compileRoutesTo($cacheFile);

            $cachedRouter = new RouterFactory();
            $cachedRouter->loadCompiledRoutesFrom($cacheFile);

            $response = $cachedRouter->handle(RequestFactory::fromArrays(
                server: [
                    'REQUEST_URI' => '/admin/dashboard',
                    'REQUEST_METHOD' => 'GET',
                    'HTTP_HOST' => 'admin.example.com',
                ],
            ));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('dashboard', (string) $response->getBody());
        } finally {
            @unlink($cacheFile);
        }
    }

    public function testMetadataRouteCacheCanRestoreRouteRegistry(): void
    {
        $router = new RouterFactory();
        $router->registerFromClasses([AttributeDomainController::class]);

        $cacheFile = tempnam(sys_get_temp_dir(), 'route-metadata');
        self::assertNotFalse($cacheFile);

        try {
            $router->cacheRoutesTo($cacheFile);

            $cachedRouter = new RouterFactory();
            $cachedRouter->loadRoutesFrom($cacheFile);

            self::assertSame($router->routes(), $cachedRouter->routes());
        } finally {
            @unlink($cacheFile);
        }
    }

    public function testLoggerReceivesNotFoundEvents(): void
    {
        $logger = new IntegrationLogger();
        $router = new RouterFactory();
        $router->setLogger($logger);

        try {
            $router->handle(RequestFactory::fromArrays(
                server: ['REQUEST_URI' => '/missing', 'REQUEST_METHOD' => 'GET'],
            ));
            self::fail('Expected RouteNotFoundException was not thrown.');
        } catch (RouteNotFoundException) {
            self::assertSame('Route not found.', $logger->records[0]['message'] ?? null);
        }
    }

    public function testFluentRouteDefinitionRegistersWithoutExplicitRegisterCall(): void
    {
        $router = new RouterFactory();

        $router->fluent()
            ->get('/ping', static fn (): ResponseInterface => Response::text('pong'))
            ->name('ping');

        gc_collect_cycles();

        $response = $router->handle(RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/ping', 'REQUEST_METHOD' => 'GET'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pong', (string) $response->getBody());
        self::assertSame('ping', $router->routes()[0]['name'] ?? null);
    }

    public function testFluentRouteDefinitionRegisterRemainsIdempotent(): void
    {
        $router = new RouterFactory();

        $router->fluent()
            ->get('/health', static fn (): ResponseInterface => Response::text('ok'))
            ->name('health')
            ->register();

        gc_collect_cycles();

        self::assertCount(1, $router->routes());
        self::assertSame('/health', $router->routes()[0]['path']);
    }
}

#[Prefix('/admin', name: 'admin.')]
#[Domain('admin.example.com')]
final class AttributeDomainController
{
    #[Route('GET', '/dashboard', name: 'dashboard')]
    public function dashboard(): ResponseInterface
    {
        return Response::text('dashboard');
    }
}

final class IntegrationLogger extends AbstractLogger
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
