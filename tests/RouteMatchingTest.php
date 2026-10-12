<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use League\Route\Http\Exception\MethodNotAllowedException;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Response;
use Marwa\Router\RouterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteMatchingTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/marwa-matching-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testRoutesInDifferentSegmentsAndFallbackRoutesMatch(): void
    {
        foreach ($this->routers() as $label => $router) {
            self::assertSame('user:5', $this->body($router, '/users/5'), $label);
            self::assertSame('order:9', $this->body($router, '/orders/9'), $label);
            self::assertSame('file:a/b/c', $this->body($router, '/files/a/b/c'), $label);
            self::assertSame('about:en', $this->body($router, '/en/about'), $label);
            self::assertSame('page:contact', $this->body($router, '/contact'), $label);
        }
    }

    public function testRegistrationOrderDecidesBetweenSegmentAndFallbackRoutes(): void
    {
        $fallbackFirst = new RouterFactory();
        $fallbackFirst->map('GET', '/{lang}/about', [MatchingController::class, 'about']);
        $fallbackFirst->map('GET', '/users/{id}', [MatchingController::class, 'user']);

        $segmentFirst = new RouterFactory();
        $segmentFirst->map('GET', '/users/{id}', [MatchingController::class, 'user']);
        $segmentFirst->map('GET', '/{lang}/about', [MatchingController::class, 'about']);

        self::assertSame('about:users', $this->body($fallbackFirst, '/users/about'));
        self::assertSame('user:about', $this->body($segmentFirst, '/users/about'));

        $fallbackFirst->compileRoutesTo($this->file);
        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);
        self::assertSame('about:users', $this->body($cached, '/users/about'));
    }

    public function testEachSegmentGroupOnlyHoldsItsOwnRoutes(): void
    {
        $this->source()->compileRoutesTo($this->file);

        /** @var array{dispatch:array<string, array{segments:array<string, array<string, list<array{regex:string}>>>, fallback:array<string, list<array{regex:string}>>}>} $payload */
        $payload = require $this->file;
        $data = $payload['dispatch']['*'];

        self::assertSame(['users', 'orders', 'files'], array_keys($data['segments']));
        $usersRegex = implode('', array_column($data['segments']['users']['GET'], 'regex'));
        self::assertStringContainsString('/users/', $usersRegex);
        self::assertStringNotContainsString('/orders/', $usersRegex);
        self::assertStringContainsString('/about', $usersRegex, 'fallback routes are part of every group');
        self::assertCount(2, $data['fallback']['GET'][0]['routeMap'] ?? []);
    }

    public function testMethodNotAllowedAndHeadFallbackStillWork(): void
    {
        foreach ($this->routers() as $label => $router) {
            self::assertSame(200, $router->handle($this->request('/users/5', 'HEAD'))->getStatusCode(), $label);

            try {
                $router->handle($this->request('/users/5', 'DELETE'));
                self::fail('Expected MethodNotAllowedException for ' . $label);
            } catch (MethodNotAllowedException $e) {
                self::assertSame('GET', $e->getHeaders()['Allow'] ?? null, $label);
            }
        }
    }

    public function testStaticRouteAfterMatchingVariableRouteIsUsed(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/users/{id}', [MatchingController::class, 'user']);
        $router->map('GET', '/users/me', static fn (): ResponseInterface => Response::text('me'));

        self::assertSame('me', $this->body($router, '/users/me'));
        self::assertSame('user:7', $this->body($router, '/users/7'));
    }

    public function testLastRouteWinsWhenConflictDetectionIsDisabled(): void
    {
        $router = new RouterFactory();
        $router->enableConflictDetection(false);
        $router->map('GET', '/x', static fn (): ResponseInterface => Response::text('first'));
        $router->map('GET', '/x', static fn (): ResponseInterface => Response::text('second'));
        $router->map('GET', '/users/{id}', static fn (): ResponseInterface => Response::text('first-user'));
        $router->map('GET', '/users/{name}', static fn (): ResponseInterface => Response::text('second-user'));

        self::assertSame('second', $this->body($router, '/x'));
        self::assertSame('second-user', $this->body($router, '/users/1'));
    }

    public function testDomainRouteWinsOverDomainlessRouteOnTheSamePath(): void
    {
        foreach ([true, false] as $domainFirst) {
            $router = new RouterFactory();
            $generic = [MatchingController::class, 'generic'];
            $admin = [MatchingController::class, 'admin'];
            if ($domainFirst) {
                $router->map('GET', '/', $admin, domain: 'admin.test');
                $router->map('GET', '/', $generic);
            } else {
                $router->map('GET', '/', $generic);
                $router->map('GET', '/', $admin, domain: 'admin.test');
            }
            $router->compileRoutesTo($this->file);
            $cached = new RouterFactory();
            $cached->loadCompiledRoutesFrom($this->file);

            foreach (['runtime' => $router, 'compiled' => $cached] as $label => $r) {
                self::assertSame('admin', $this->body($r, '/', 'admin.test'), $label);
                self::assertSame('generic', $this->body($r, '/', 'www.test'), $label);
            }
        }
    }

    /**
     * @return array<string, RouterFactory>
     */
    private function routers(): array
    {
        $runtime = $this->source();
        $runtime->compileRoutesTo($this->file);
        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);

        return ['runtime' => $runtime, 'compiled' => $cached];
    }

    private function source(): RouterFactory
    {
        $router = new RouterFactory();
        $router->map('GET', '/users/{id}', [MatchingController::class, 'user']);
        $router->map('GET', '/orders/{id}', [MatchingController::class, 'order']);
        $router->map('GET', '/files/{path:.+}', [MatchingController::class, 'file']);
        $router->map('GET', '/{lang}/about', [MatchingController::class, 'about']);
        $router->map('GET', '/{page}', [MatchingController::class, 'page']);

        return $router;
    }

    private function body(RouterFactory $router, string $path, string $host = 'localhost'): string
    {
        return (string) $router->handle($this->request($path, 'GET', $host))->getBody();
    }

    private function request(string $path, string $method, string $host = 'localhost'): ServerRequestInterface
    {
        return RequestFactory::fromArrays(server: ['REQUEST_URI' => $path, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => $host]);
    }
}

final class MatchingController
{
    /** @param array<string, string> $vars */
    public function user(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return Response::text('user:' . $vars['id']);
    }

    /** @param array<string, string> $vars */
    public function order(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return Response::text('order:' . $vars['id']);
    }

    /** @param array<string, string> $vars */
    public function file(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return Response::text('file:' . $vars['path']);
    }

    /** @param array<string, string> $vars */
    public function about(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return Response::text('about:' . $vars['lang']);
    }

    /** @param array<string, string> $vars */
    public function page(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return Response::text('page:' . $vars['page']);
    }

    public function generic(): ResponseInterface
    {
        return Response::text('generic');
    }

    public function admin(): ResponseInterface
    {
        return Response::text('admin');
    }
}
