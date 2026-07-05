<?php

declare(strict_types=1);

namespace Marwa\Router\Benchmarks;

use Laminas\Diactoros\Response\TextResponse;
use Marwa\Router\RouterFactory;
use Marwa\Router\Http\RequestFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * @BeforeMethods({"setUp"})
 * @Revs(100)
 * @Iterations(10)
 * @Groups({"router", "dispatch"})
 */
final class RouterDispatchBench
{
    private RouterFactory $routerWithClosures;
    private RouterFactory $routerWithClasses;
    private RouterFactory $routerWithNotFound;
    private $closureRequest;
    private $classRequest;

    public function setUp(): void
    {
        $this->closureRequest = RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/users/42', 'REQUEST_METHOD' => 'GET'],
        );

        $this->classRequest = RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/users/1', 'REQUEST_METHOD' => 'GET'],
        );

        // Fluent registration with closures
        $ok = static fn () => new TextResponse('ok');
        $this->routerWithClosures = new RouterFactory();
        $this->routerWithClosures->map('GET', '/users/{id}', $ok, name: 'users.show');
        $this->routerWithClosures->map('GET', '/users', $ok, name: 'users.index');
        $this->routerWithClosures->map('POST', '/users', $ok, name: 'users.create');
        $this->routerWithClosures->map('GET', '/posts/{id}', $ok, name: 'posts.show');
        $this->routerWithClosures->map('GET', '/posts', $ok, name: 'posts.index');
        $this->routerWithClosures->map('POST', '/posts', $ok, name: 'posts.create');
        $this->routerWithClosures->map('GET', '/comments/{id}', $ok, name: 'comments.show');
        $this->routerWithClosures->map('GET', '/comments', $ok, name: 'comments.index');
        $this->routerWithClosures->map('GET', '/tags/{id}', $ok, name: 'tags.show');
        $this->routerWithClosures->map('GET', '/tags', $ok, name: 'tags.index');

        // Attribute-based registration
        $this->routerWithClasses = new RouterFactory();
        $this->routerWithClasses->registerFromClasses([
            BenchUserController::class,
            BenchPostController::class,
        ]);

        // Router with not-found handler (no exception on miss)
        $this->routerWithNotFound = new RouterFactory();
        $this->routerWithNotFound->setNotFoundHandler(
            static fn () => new TextResponse('not found', 404),
        );
    }

    /**
     * Dispatch a request through a router with 10 closure-based routes.
     */
    public function benchDispatchClosureRoutes(): void
    {
        $this->routerWithClosures->handle($this->closureRequest);
    }

    /**
     * Dispatch a request through a router with attribute-based controller routes.
     */
    public function benchDispatchAttributeRoutes(): void
    {
        $this->routerWithClasses->handle($this->classRequest);
    }

    /**
     * Route registration via fluent map() — 10 routes.
     */
    public function benchRegisterFluentRoutes(): void
    {
        $ok = static fn () => new TextResponse('ok');
        $router = new RouterFactory();
        $router->map('GET', '/users/{id}', $ok, name: 'users.show');
        $router->map('GET', '/users', $ok, name: 'users.index');
        $router->map('POST', '/users', $ok, name: 'users.create');
        $router->map('GET', '/posts/{id}', $ok, name: 'posts.show');
        $router->map('GET', '/posts', $ok, name: 'posts.index');
        $router->map('POST', '/posts', $ok, name: 'posts.create');
        $router->map('GET', '/comments/{id}', $ok, name: 'comments.show');
        $router->map('GET', '/comments', $ok, name: 'comments.index');
        $router->map('GET', '/tags/{id}', $ok, name: 'tags.show');
        $router->map('GET', '/tags', $ok, name: 'tags.index');
    }

    /**
     * Route registration via attribute-based class scanning — 2 controllers.
     */
    public function benchRegisterAttributeRoutes(): void
    {
        $router = new RouterFactory();
        $router->registerFromClasses([
            BenchUserController::class,
            BenchPostController::class,
        ]);
    }

    /**
     * Dispatch a 404 with not-found handler — no exception overhead.
     */
    public function benchDispatchNotFound(): void
    {
        $request = RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/nonexistent', 'REQUEST_METHOD' => 'GET'],
        );

        $this->routerWithNotFound->handle($request);
    }
}
