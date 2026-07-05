<?php

declare(strict_types=1);

namespace Marwa\Router\Benchmarks;

use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Middleware\{
    AuthTokenMiddleware,
    CorsMiddleware,
    CsrfMiddleware,
    ContentTypeMiddleware,
    RequestGuardMiddleware,
    RequestIdMiddleware,
    SecurityHeadersMiddleware
};
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @BeforeMethods({"setUp"})
 * @Revs(100)
 * @Iterations(10)
 * @Groups({"middleware"})
 */
final class MiddlewarePipelineBench
{
    /** @var list<MiddlewareInterface> */
    private array $pipeline;

    private ServerRequest $validRequest;
    private ServerRequest $authRequest;
    private ServerRequest $preflightRequest;

    public function setUp(): void
    {
        $this->pipeline = [
            new RequestIdMiddleware(),
            new SecurityHeadersMiddleware(),
            new ContentTypeMiddleware(),
            new RequestGuardMiddleware(),
            new AuthTokenMiddleware(['valid-token']),
            new CorsMiddleware(['allowed.example.com']),
            new CsrfMiddleware(),
        ];

        $this->validRequest = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/api/users',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'example.com',
                'HTTP_AUTHORIZATION' => 'Bearer valid-token',
                'HTTP_ORIGIN' => 'https://allowed.example.com',
            ],
        );

        $this->authRequest = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/api/users',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'example.com',
                'HTTP_AUTHORIZATION' => 'Bearer valid-token',
            ],
        );

        $this->preflightRequest = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/api/users',
                'REQUEST_METHOD' => 'OPTIONS',
                'HTTP_HOST' => 'example.com',
                'HTTP_ORIGIN' => 'https://allowed.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );
    }

    /**
     * Full middleware pipeline — 7 middleware on a valid request.
     */
    public function benchFullPipeline(): void
    {
        $handler = new OkHandler();
        $request = $this->validRequest;

        foreach ($this->pipeline as $middleware) {
            $result = $middleware->process($request, $handler);
            if ($result instanceof ResponseInterface) {
                return;
            }
            $request = $result;
        }
    }

    /**
     * Single RequestGuardMiddleware — the most common path check.
     */
    public function benchRequestGuardOnly(): void
    {
        $handler = new OkHandler();
        (new RequestGuardMiddleware())->process($this->validRequest, $handler);
    }

    /**
     * Single AuthTokenMiddleware — token validation.
     */
    public function benchAuthTokenOnly(): void
    {
        $handler = new OkHandler();
        (new AuthTokenMiddleware(['valid-token']))->process($this->authRequest, $handler);
    }

    /**
     * CORS preflight handling — short-circuits early.
     */
    public function benchCorsPreflight(): void
    {
        $handler = new OkHandler();
        (new CorsMiddleware(['allowed.example.com']))->process($this->preflightRequest, $handler);
    }

    /**
     * RequestIdMiddleware — generates unique ID per request.
     */
    public function benchRequestId(): void
    {
        $handler = new OkHandler();
        (new RequestIdMiddleware())->process($this->validRequest, $handler);
    }

    /**
     * SecurityHeadersMiddleware — adds standard headers.
     */
    public function benchSecurityHeaders(): void
    {
        $handler = new OkHandler();
        (new SecurityHeadersMiddleware())->process($this->validRequest, $handler);
    }

    /**
     * ContentTypeMiddleware — content type check.
     */
    public function benchContentType(): void
    {
        $handler = new OkHandler();
        (new ContentTypeMiddleware())->process($this->validRequest, $handler);
    }
}

final class OkHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new TextResponse('ok');
    }
}
