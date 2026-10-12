<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use League\Route\Router as LeagueRouter;
use Marwa\Router\Exceptions\RouteNotFoundException;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Response;
use Marwa\Router\RouterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One RouterFactory instance serving many requests, as in a long-running
 * worker (RoadRunner, Swoole, FrankenPHP).
 */
final class DomainDispatchTest extends TestCase
{
    public function testEachDomainIsServedRegardlessOfTheFirstRequestHost(): void
    {
        $router = $this->router();

        self::assertSame('a', $this->body($router, '/home', 'a.example.com'));
        self::assertSame('b', $this->body($router, '/home', 'b.example.com'));
        self::assertSame('a', $this->body($router, '/home', 'a.example.com'));
        self::assertSame('shared', $this->body($router, '/shared', 'b.example.com'));
        self::assertSame('shared', $this->body($router, '/shared', 'other.test'));
    }

    public function testDomainRouteIsNotServedOnOtherHosts(): void
    {
        $router = $this->router();
        self::assertSame('a', $this->body($router, '/home', 'a.example.com'));

        $this->expectException(RouteNotFoundException::class);
        $router->handle($this->request('/home', 'other.test'));
    }

    public function testAttributeDomainRoutesWorkAfterAnotherHostWasServed(): void
    {
        $router = new RouterFactory();
        $router->registerFromClasses([AttributeDomainController::class]);
        $router->map('GET', '/public', static fn (): ResponseInterface => Response::text('public'));

        self::assertSame('public', $this->body($router, '/public', 'www.example.com'));
        self::assertSame('dashboard', $this->body($router, '/admin/dashboard', 'admin.example.com'));
    }

    public function testRoutesAddedAfterTheFirstDispatchAreMatched(): void
    {
        $router = $this->router();
        self::assertSame('shared', $this->body($router, '/shared', 'a.example.com'));

        $router->map('GET', '/late', static fn (): ResponseInterface => Response::text('late'));
        $router->map('GET', '/late-b', static fn (): ResponseInterface => Response::text('late-b'), domain: 'b.example.com');

        self::assertSame('late', $this->body($router, '/late', 'a.example.com'));
        self::assertSame('late-b', $this->body($router, '/late-b', 'b.example.com'));
    }

    public function testInjectedRouterKeepsItsOwnRoutes(): void
    {
        $league = new LeagueRouter();
        $league->map('GET', '/native', static fn (): ResponseInterface => Response::text('native'));

        $router = new RouterFactory(router: $league);
        $router->map('GET', '/mapped', static fn (): ResponseInterface => Response::text('mapped'));

        self::assertSame('native', $this->body($router, '/native', 'localhost'));
        self::assertSame('mapped', $this->body($router, '/mapped', 'localhost'));
    }

    private function router(): RouterFactory
    {
        $router = new RouterFactory();
        $router->map('GET', '/home', static fn (): ResponseInterface => Response::text('a'), domain: 'a.example.com');
        $router->map('GET', '/home', static fn (): ResponseInterface => Response::text('b'), domain: 'b.example.com');
        $router->map('GET', '/shared', static fn (): ResponseInterface => Response::text('shared'));

        return $router;
    }

    private function body(RouterFactory $router, string $path, string $host): string
    {
        return (string) $router->handle($this->request($path, $host))->getBody();
    }

    private function request(string $path, string $host): ServerRequestInterface
    {
        return RequestFactory::fromArrays(
            server: ['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $host],
        );
    }
}
