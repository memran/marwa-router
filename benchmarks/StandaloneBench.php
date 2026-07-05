<?php

declare(strict_types=1);

namespace Marwa\Router\Benchmarks;

use Laminas\Diactoros\Response\TextResponse;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\UrlGenerator;

/**
 * Standalone benchmarks that work on both old and new code.
 * Run with: vendor/bin/phpbench run benchmarks/StandaloneBench.php --report=default
 *
 * @BeforeMethods({"setUp"})
 * @Revs(100)
 * @Iterations(10)
 * @Groups({"standalone"})
 */
final class StandaloneBench
{
    private UrlGenerator $generatorLarge;
    private \Marwa\Router\RouterFactory $routerFluent;

    public function setUp(): void
    {
        // UrlGenerator: 100 routes — tests name index O(1) vs linear scan
        $routes = [];
        foreach (range(1, 100) as $i) {
            $routes[] = ['name' => "route{$i}", 'path' => "/resource{$i}/{id}"];
        }
        $this->generatorLarge = new UrlGenerator($routes);

        // Router: 10 fluent closure routes
        $ok = static fn () => new TextResponse('ok');
        $this->routerFluent = new \Marwa\Router\RouterFactory();
        $this->routerFluent->map('GET', '/users/{id}', $ok, name: 'users.show');
        $this->routerFluent->map('GET', '/users', $ok, name: 'users.index');
        $this->routerFluent->map('POST', '/users', $ok, name: 'users.create');
        $this->routerFluent->map('GET', '/posts/{id}', $ok, name: 'posts.show');
        $this->routerFluent->map('GET', '/posts', $ok, name: 'posts.index');
        $this->routerFluent->map('POST', '/posts', $ok, name: 'posts.create');
        $this->routerFluent->map('GET', '/comments/{id}', $ok, name: 'comments.show');
        $this->routerFluent->map('GET', '/comments', $ok, name: 'comments.index');
        $this->routerFluent->map('GET', '/tags/{id}', $ok, name: 'tags.show');
        $this->routerFluent->map('GET', '/tags', $ok, name: 'tags.index');
    }

    /**
     * UrlGenerator: look up last route in 100-route set (O(1) index).
     */
    public function benchUrlGenLookupLast(): void
    {
        $this->generatorLarge->for('route100', ['id' => 1]);
    }

    /**
     * UrlGenerator: look up first route in 100-route set.
     */
    public function benchUrlGenLookupFirst(): void
    {
        $this->generatorLarge->for('route1', ['id' => 1]);
    }

    /**
     * UrlGenerator: look up middle route in 100-route set.
     */
    public function benchUrlGenLookupMiddle(): void
    {
        $this->generatorLarge->for('route50', ['id' => 1]);
    }

    /**
     * Router dispatch: closure routes.
     */
    public function benchRouterDispatch(): void
    {
        $request = RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/users/42', 'REQUEST_METHOD' => 'GET'],
        );
        $this->routerFluent->handle($request);
    }

    /**
     * Router: register 10 fluent routes.
     */
    public function benchRouterRegisterFluent(): void
    {
        $ok = static fn () => new TextResponse('ok');
        $router = new \Marwa\Router\RouterFactory();
        $router->map('GET', '/a/{id}', $ok);
        $router->map('GET', '/a', $ok);
        $router->map('POST', '/a', $ok);
        $router->map('GET', '/b/{id}', $ok);
        $router->map('GET', '/b', $ok);
        $router->map('POST', '/b', $ok);
        $router->map('GET', '/c/{id}', $ok);
        $router->map('GET', '/c', $ok);
        $router->map('GET', '/d/{id}', $ok);
        $router->map('GET', '/d', $ok);
    }

    /**
     * Signed URL: generate + verify round-trip.
     */
    public function benchSignedUrlRoundTrip(): void
    {
        $url = $this->generatorLarge->signed('route50', ['id' => 42], 300, 'secret');
        $this->generatorLarge->verify($url, 'secret');
    }
}
