<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use League\Route\Http\Exception\MethodNotAllowedException;
use Marwa\Router\Attributes\Prefix;
use Marwa\Router\Attributes\Route;
use Marwa\Router\Attributes\UseMiddleware;
use Marwa\Router\Exceptions\RouteConflictException;
use Marwa\Router\Exceptions\RouteNotFoundException;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Response;
use Marwa\Router\RouterFactory;
use Marwa\Router\Tests\Fixtures\InMemoryCache;
use Marwa\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CompiledRouteCacheTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/marwa-compiled-' . bin2hex(random_bytes(6)) . '.php';
        CountingController::$instances = 0;
        CountingMiddleware::$instances = 0;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testRegistrationDoesNotInstantiateControllersOrMiddleware(): void
    {
        $router = new RouterFactory();
        for ($i = 0; $i < 50; $i++) {
            $router->map(['GET', 'POST'], "/items{$i}/{id}", [CountingController::class, 'show'], middlewares: [CountingMiddleware::class]);
        }
        $router->registerFromClasses([CountingAttributeController::class]);

        self::assertSame(0, CountingController::$instances);
        self::assertSame(0, CountingMiddleware::$instances);

        $response = $router->handle($this->request('/items7/42'));
        $router->handle($this->request('/items8/42', 'POST'));
        $router->handle($this->request('/attr/ping'));

        self::assertSame('show:42', (string) $response->getBody());
        self::assertSame('1', $response->getHeaderLine('X-Counting'));
        self::assertSame(3, CountingController::$instances);
        self::assertSame(1, CountingMiddleware::$instances, 'one middleware instance is shared by all routes');
    }

    public function testMissingMiddlewareClassStillFailsAtRegistration(): void
    {
        $router = new RouterFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Middleware class does not exist');

        $router->map('GET', '/x', [CountingController::class, 'show'], middlewares: ['Missing\\Middleware']);
    }

    public function testLoadedTableDispatchesWithoutInstantiatingAnything(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/static', [CountingController::class, 'show'], name: 'static');
        $router->map('GET', '/users/{id}', [CountingController::class, 'show'], name: 'users.show', where: ['id' => '\d+']);
        $router->map('GET', '/posts/{id:number}', [CountingController::class, 'show']);
        $router->map(['GET', 'PUT'], '/mw/{id}', [CountingController::class, 'show'], middlewares: [CountingMiddleware::class]);
        $router->compileRoutesTo($this->file);

        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);
        self::assertSame(0, CountingController::$instances);
        self::assertSame(0, CountingMiddleware::$instances);

        self::assertSame(200, $cached->handle($this->request('/static'))->getStatusCode());
        self::assertSame(200, $cached->handle($this->request('/static/'))->getStatusCode(), 'trailing slash stays optional');
        self::assertSame('show:5', (string) $cached->handle($this->request('/users/5'))->getBody());
        self::assertSame('show:9', (string) $cached->handle($this->request('/posts/9'))->getBody());

        $mw = $cached->handle($this->request('/mw/3', 'PUT'));
        self::assertSame('show:3', (string) $mw->getBody());
        self::assertSame('1', $mw->getHeaderLine('X-Counting'));

        self::assertSame($router->routes(), $cached->routes());
        self::assertSame('/users/12', (new UrlGenerator($cached->routes()))->for('users.show', ['id' => 12]));
    }

    public function testLoadedTableKeepsNotFoundAndMethodNotAllowedBehavior(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/users/{id}', [CountingController::class, 'show'], where: ['id' => '\d+']);
        $router->compileRoutesTo($this->file);

        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);

        try {
            $cached->handle($this->request('/users/abc'));
            self::fail('Expected RouteNotFoundException');
        } catch (RouteNotFoundException) {
        }

        $cached->setNotFoundHandler(static fn (): string => 'missing');
        self::assertSame(404, $cached->handle($this->request('/nope'))->getStatusCode());

        $this->expectException(MethodNotAllowedException::class);
        $cached->handle($this->request('/users/1', 'DELETE'));
    }

    public function testLoadedTableMatchesRoutesPerDomain(): void
    {
        $source = new RouterFactory();
        $source->map('GET', '/home', [DomainController::class, 'a'], domain: 'a.example.com');
        $source->map('GET', '/home', [DomainController::class, 'b'], domain: 'b.example.com');
        $source->map('GET', '/shared', [DomainController::class, 'shared']);
        $source->compileRoutesTo($this->file);

        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);

        // The same instance must serve every host, as in a long-running worker.
        self::assertSame('a', (string) $cached->handle($this->request('/home', host: 'a.example.com'))->getBody());
        self::assertSame('b', (string) $cached->handle($this->request('/home', host: 'b.example.com'))->getBody());
        self::assertSame('shared', (string) $cached->handle($this->request('/shared', host: 'a.example.com'))->getBody());
        self::assertSame('shared', (string) $cached->handle($this->request('/shared', host: 'other.test'))->getBody());

        $this->expectException(RouteNotFoundException::class);
        $cached->handle($this->request('/home', host: 'other.test'));
    }

    public function testRuntimeRoutesWorkAlongsideLoadedTable(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/cached', [CountingController::class, 'show'], name: 'cached');
        $router->compileRoutesTo($this->file);

        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);
        $cached->map('GET', '/runtime', static fn (): ResponseInterface => Response::text('runtime'), name: 'runtime');

        self::assertSame('show:', (string) $cached->handle($this->request('/cached'))->getBody());
        self::assertSame('runtime', (string) $cached->handle($this->request('/runtime'))->getBody());
        self::assertCount(2, $cached->routes());
    }

    public function testConflictDetectionCoversLoadedRoutes(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/users/{id}', [CountingController::class, 'show'], name: 'users.show');
        $router->compileRoutesTo($this->file);

        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);

        try {
            $cached->map('GET', '/users/{name}', [CountingController::class, 'show']);
            self::fail('Expected duplicate route conflict');
        } catch (RouteConflictException $e) {
            self::assertStringContainsString('Duplicate route', $e->getMessage());
        }

        $this->expectException(RouteConflictException::class);
        $this->expectExceptionMessage('Duplicate route name: users.show');
        $cached->map('GET', '/other', [CountingController::class, 'show'], name: 'users.show');
    }

    public function testRecompilingAfterLoadKeepsLoadedAndRuntimeRoutes(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/one', [CountingController::class, 'show']);
        $router->compileRoutesTo($this->file);

        $cached = new RouterFactory();
        $cached->loadCompiledRoutesFrom($this->file);
        $cached->map('GET', '/two', [CountingController::class, 'show']);
        $cached->compileRoutesTo($this->file);

        $reloaded = new RouterFactory();
        $reloaded->loadCompiledRoutesFrom($this->file);

        self::assertSame(200, $reloaded->handle($this->request('/one'))->getStatusCode());
        self::assertSame(200, $reloaded->handle($this->request('/two'))->getStatusCode());
    }

    public function testThrottleAppliesToLoadedRoutes(): void
    {
        $router = new RouterFactory(cache: new InMemoryCache());
        $router->map('GET', '/limited', [CountingController::class, 'show'], throttle: ['limit' => 1, 'per' => 60, 'key' => 'ip']);
        $router->compileRoutesTo($this->file);

        $cached = new RouterFactory(cache: new InMemoryCache());
        $cached->loadCompiledRoutesFrom($this->file);

        self::assertSame(200, $cached->handle($this->request('/limited'))->getStatusCode());
        self::assertSame(429, $cached->handle($this->request('/limited'))->getStatusCode());
    }

    public function testContainerResolvesControllersAndMiddlewareAtDispatch(): void
    {
        $controller = new CountingController('from-container');
        $container = new class ($controller) implements ContainerInterface {
            public int $gets = 0;

            public function __construct(private readonly CountingController $controller) {}

            public function get(string $id): mixed
            {
                $this->gets++;

                return $id === CountingController::class ? $this->controller : new CountingMiddleware();
            }

            public function has(string $id): bool
            {
                return $id === CountingController::class || $id === CountingMiddleware::class;
            }
        };

        $router = new RouterFactory();
        $router->map('GET', '/c/{id}', [CountingController::class, 'show'], middlewares: [CountingMiddleware::class]);
        $router->compileRoutesTo($this->file);

        $cached = new RouterFactory($container);
        $cached->loadCompiledRoutesFrom($this->file);
        self::assertSame(0, $container->gets);

        $response = $cached->handle($this->request('/c/1'));

        self::assertSame('from-container:1', (string) $response->getBody());
        self::assertSame('1', $response->getHeaderLine('X-Counting'));
    }

    public function testLegacyCallableCacheFilesStillLoad(): void
    {
        file_put_contents($this->file, <<<'PHP'
            <?php

            declare(strict_types=1);

            return static function (\Marwa\Router\RouterFactory $router): void {
                $router->map(['GET'], '/legacy', [\Marwa\Router\Tests\CountingController::class, 'show']);
            };
            PHP);

        $router = new RouterFactory();
        $router->loadCompiledRoutesFrom($this->file);

        self::assertSame(200, $router->handle($this->request('/legacy'))->getStatusCode());
    }

    private function request(string $path, string $method = 'GET', string $host = 'localhost'): ServerRequestInterface
    {
        return RequestFactory::fromArrays(
            server: ['REQUEST_URI' => $path, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => $host],
        );
    }
}

final class CountingController
{
    public static int $instances = 0;

    public function __construct(private readonly string $label = 'show')
    {
        self::$instances++;
    }

    /** @param array<string, string> $vars */
    public function show(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return Response::text($this->label . ':' . ($vars['id'] ?? ''));
    }
}

#[Prefix('/attr')]
#[UseMiddleware(CountingMiddleware::class)]
final class CountingAttributeController
{
    public function __construct()
    {
        CountingController::$instances++;
    }

    #[Route('GET', '/ping')]
    public function ping(): ResponseInterface
    {
        return Response::text('pong');
    }
}

final class CountingMiddleware implements MiddlewareInterface
{
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Counting', '1');
    }
}

final class DomainController
{
    public function a(): ResponseInterface
    {
        return Response::text('a');
    }

    public function b(): ResponseInterface
    {
        return Response::text('b');
    }

    public function shared(): ResponseInterface
    {
        return Response::text('shared');
    }
}
