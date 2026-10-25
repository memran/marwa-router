<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Marwa\Router\Response;
use Marwa\Router\RouterFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that takes a value, written as a string the cache can hold.
 *
 * A middleware configured per route -- a permission to require, an API scope
 * to admit -- could only be given as an instance, and an instance is not data,
 * so the route could not be compiled: `writeCompiled()` refuses with "only
 * supports middleware defined as class strings". One application reached 1,066
 * such instances across 85 route files, which made the compiled cache
 * unavailable to exactly the application large enough to need it.
 *
 * `Class:arg1,arg2` is the string form. The class is everything before the
 * first colon -- a fully qualified name has backslashes and no colon, so the
 * boundary is unambiguous while the arguments stay free to contain colons of
 * their own, as an API scope like `organization:read` does.
 */
final class ParameterizedMiddlewareTest extends TestCase
{
    public function testArgumentsReachTheMiddlewareConstructor(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/a', static fn (): ResponseInterface => Response::json(['ok' => true]), middlewares: [
            ParamStampMiddleware::class . ':alpha',
        ]);

        $response = $router->dispatch($this->request('/a'));

        self::assertSame('alpha', $response->getHeaderLine('X-Stamp'));
    }

    /**
     * The bug this guards is not hypothetical.
     *
     * One proxy per middleware *class* is what 1.3.4 introduced, and it is
     * right until the class takes an argument: two routes requiring different
     * permissions would then share the proxy built for whichever was
     * registered first, and every route would enforce that one. Silently --
     * the wrong permission still answers.
     */
    public function testTwoArgumentsOfTheSameClassDoNotShareOneInstance(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/a', static fn (): ResponseInterface => Response::json([]), middlewares: [
            ParamStampMiddleware::class . ':alpha',
        ]);
        $router->map('GET', '/b', static fn (): ResponseInterface => Response::json([]), middlewares: [
            ParamStampMiddleware::class . ':bravo',
        ]);

        self::assertSame('alpha', $router->dispatch($this->request('/a'))->getHeaderLine('X-Stamp'));
        self::assertSame('bravo', $router->dispatch($this->request('/b'))->getHeaderLine('X-Stamp'));
    }

    /** Several arguments, split on commas, in order. */
    public function testSeveralArgumentsArePassedInOrder(): void
    {
        $router = new RouterFactory();
        $router->map('GET', '/a', static fn (): ResponseInterface => Response::json([]), middlewares: [
            ParamPairMiddleware::class . ':organization:read,organization.view',
        ]);

        // The first argument keeps its own colon: only the boundary between
        // the class and its arguments is a split point.
        self::assertSame(
            'organization:read|organization.view',
            $router->dispatch($this->request('/a'))->getHeaderLine('X-Pair'),
        );
    }

    /** Nothing is built until a request actually runs the middleware. */
    public function testTheMiddlewareIsNotBuiltWhileRegisteringRoutes(): void
    {
        ParamCountingMiddleware::$built = 0;
        $router = new RouterFactory();

        for ($i = 0; $i < 50; $i++) {
            $router->map('GET', '/r' . $i, static fn (): ResponseInterface => Response::json([]), middlewares: [
                ParamCountingMiddleware::class . ':x' . $i,
            ]);
        }

        self::assertSame(0, ParamCountingMiddleware::$built, 'registering built the middleware');

        $router->dispatch($this->request('/r7'));

        self::assertSame(1, ParamCountingMiddleware::$built, 'dispatching built more than the one it needed');
    }

    /**
     * A missing class still fails with its name, not with the arguments.
     *
     * At registration, not at dispatch: the class is checked when the route is
     * mapped and only the instance is deferred, so a typo is found while
     * booting rather than by the first visitor to that URL.
     */
    public function testAnUnknownClassIsRefusedByNameWhenTheRouteIsMapped(): void
    {
        $router = new RouterFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Middleware class does not exist: Nope\\Missing\\Middleware');

        $router->map('GET', '/a', static fn (): ResponseInterface => Response::json([]), middlewares: [
            'Nope\\Missing\\Middleware:alpha',
        ]);
    }

    /**
     * And the route is cacheable, which is the whole reason for the form.
     */
    public function testAParameterizedRouteCompilesAndDispatchesFromTheCache(): void
    {
        $file = sys_get_temp_dir() . '/marwa-param-mw-' . bin2hex(random_bytes(6)) . '.php';

        $router = new RouterFactory();
        $router->map('GET', '/a', [ParamStampController::class, 'show'], middlewares: [
            ParamStampMiddleware::class . ':alpha',
        ]);
        $router->compileRoutesTo($file);

        self::assertFileExists($file);

        $fresh = new RouterFactory();
        $fresh->loadCompiledRoutesFrom($file);
        $response = $fresh->dispatch($this->request('/a'));

        self::assertSame('alpha', $response->getHeaderLine('X-Stamp'));

        @unlink($file);
    }

    private function request(string $path): ServerRequest
    {
        return new ServerRequest([], [], new Uri('http://localhost' . $path), 'GET');
    }
}

final class ParamStampController
{
    public function show(): ResponseInterface
    {
        return Response::json(['ok' => true]);
    }
}

final class ParamStampMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $stamp) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Stamp', $this->stamp);
    }
}

final class ParamPairMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $scope,
        private readonly string $permission,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Pair', $this->scope . '|' . $this->permission);
    }
}

final class ParamCountingMiddleware implements MiddlewareInterface
{
    public static int $built = 0;

    public function __construct(private readonly string $tag)
    {
        self::$built++;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Tag', $this->tag);
    }
}
