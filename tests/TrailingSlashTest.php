<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use League\Route\Http\Exception\MethodNotAllowedException;
use Marwa\Router\Exceptions\RouteNotFoundException;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Response;
use Marwa\Router\RouterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class TrailingSlashTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/marwa-slash-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testTrailingSlashIsOptionalByDefault(): void
    {
        $router = $this->router();

        self::assertSame('/', $this->body($router, '/'));
        self::assertSame('users', $this->body($router, '/users'));
        self::assertSame('users', $this->body($router, '/users/'));
        self::assertSame('user:5', $this->body($router, '/users/5'));
        self::assertSame('user:5', $this->body($router, '/users/5/'));
        self::assertSame('archive:', $this->body($router, '/archive/'));
        self::assertSame('archive:2024', $this->body($router, '/archive/2024/'));
    }

    public function testOnlyOneTrailingSlashIsIgnored(): void
    {
        $this->expectException(RouteNotFoundException::class);

        $this->router()->handle($this->request('/users//'));
    }

    public function testHandlerSeesTheOriginalRequestPath(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/path', static fn (ServerRequestInterface $request): ResponseInterface => Response::text($request->getUri()->getPath()));

        self::assertSame('/path/', (string) $router->handle($this->request('/path/'))->getBody());
    }

    public function testMethodNotAllowedIsReportedWithTrailingSlash(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        $this->router()->handle($this->request('/users/', 'DELETE'));
    }

    public function testTrailingSlashCanBeMadeStrict(): void
    {
        $router = new RouterFactory();
        $router->setTrailingSlashOptional(false);
        $router->map('GET', '/users', static fn (): ResponseInterface => Response::text('users'));
        $router->map('GET', '/dir/', static fn (): ResponseInterface => Response::text('dir'));

        self::assertSame('users', (string) $router->handle($this->request('/users'))->getBody());
        self::assertSame('dir', (string) $router->handle($this->request('/dir/'))->getBody());

        $this->expectException(RouteNotFoundException::class);
        $router->handle($this->request('/users/'));
    }

    public function testEachRouteIsRegisteredOnce(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/users', [SlashController::class, 'users']);
        $router->map('GET', '/users/{id}', [SlashController::class, 'user']);
        $router->compileRoutesTo($this->file);

        /** @var array{trailing_slash_optional:bool, dispatch:array<string, array{static:array<string, array<string, int>>, segments:array<string, array<string, list<array{regex:string, routeMap:array<int, mixed>}>>>}>} $payload */
        $payload = require $this->file;
        $data = $payload['dispatch']['*'];

        self::assertTrue($payload['trailing_slash_optional']);
        self::assertSame(['/users'], array_keys($data['static']['GET']));
        self::assertCount(1, $data['segments']['users']['GET'][0]['routeMap']);
    }

    public function testCompiledTableKeepsTheSettingItWasBuiltWith(): void
    {
        $this->router()->compileRoutesTo($this->file);

        $cached = new RouterFactory();
        $cached->setTrailingSlashOptional(false);
        $cached->loadCompiledRoutesFrom($this->file);

        self::assertSame('users', $this->body($cached, '/users/'));
        self::assertSame('user:7', $this->body($cached, '/users/7/'));
        self::assertSame('archive:2024', $this->body($cached, '/archive/2024/'));
    }

    public function testRuntimeRoutesOnTopOfCompiledTableHonorTrailingSlash(): void
    {
        $this->router()->compileRoutesTo($this->file);

        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);
        $cached->map('GET', '/runtime', static fn (): ResponseInterface => Response::text('runtime'));

        self::assertSame('users', $this->body($cached, '/users/'));
        self::assertSame('runtime', $this->body($cached, '/runtime/'));
    }

    private function router(): RouterFactory
    {
        $router = new RouterFactory();
        $router->map('GET', '/', [SlashController::class, 'root']);
        $router->map('GET', '/users', [SlashController::class, 'users']);
        $router->map('GET', '/users/{id:\d+}', [SlashController::class, 'user']);
        $router->map('GET', '/archive[/{year}]', [SlashController::class, 'archive']);

        return $router;
    }

    private function body(RouterFactory $router, string $path): string
    {
        return (string) $router->handle($this->request($path))->getBody();
    }

    private function request(string $path, string $method = 'GET'): ServerRequestInterface
    {
        return RequestFactory::fromArrays(server: ['REQUEST_URI' => $path, 'REQUEST_METHOD' => $method]);
    }
}

final class SlashController
{
    public function root(): ResponseInterface
    {
        return Response::text('/');
    }

    public function users(): ResponseInterface
    {
        return Response::text('users');
    }

    /** @param array<string, string> $vars */
    public function user(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return Response::text('user:' . $vars['id']);
    }

    /** @param array<string, string> $vars */
    public function archive(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return Response::text('archive:' . ($vars['year'] ?? ''));
    }
}
