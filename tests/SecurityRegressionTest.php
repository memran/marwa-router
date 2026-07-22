<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Marwa\Router\Exceptions\UntrustedHostException;
use Marwa\Router\Http\HttpRequest;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Http\UploadedFiles;
use Marwa\Router\Middleware\AuthTokenMiddleware;
use Marwa\Router\Middleware\ContentTypeMiddleware;
use Marwa\Router\Middleware\CorsMiddleware;
use Marwa\Router\Middleware\RequestGuardMiddleware;
use Marwa\Router\Middleware\RequestIdMiddleware;
use Marwa\Router\Response;
use Marwa\Router\RouterFactory;
use Marwa\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Regression tests for the security/bug audit. Each test maps to a
 * confirmed issue and guards the fix.
 */
final class SecurityRegressionTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestFactory::clearTrustedProxies();
        RequestFactory::clearTrustedHosts();
    }

    // -----------------------------------------------------------------
    // AuthTokenMiddleware: numeric tokens
    // -----------------------------------------------------------------

    public function testNumericTokenInListFormDoesNotCrash(): void
    {
        $middleware = new AuthTokenMiddleware(['123456789']);

        $response = $middleware->process(
            $this->bearerRequest('123456789'),
            $this->okHandler(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNumericTokenInAssocFormAuthenticatesWithIdentity(): void
    {
        $middleware = new AuthTokenMiddleware(['987654321' => 'admin']);
        $handler = $this->captureHandler(static function (ServerRequestInterface $request): void {
            self::assertSame('admin', $request->getAttribute('auth_token_identity'));
        });

        $response = $middleware->process($this->bearerRequest('987654321'), $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testIdentityStringIsNotAcceptedAsCredential(): void
    {
        $middleware = new AuthTokenMiddleware(['987654321' => 'admin']);

        $response = $middleware->process(
            $this->bearerRequest('admin'),
            $this->okHandler(),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testWrongTokenStillRejected(): void
    {
        $middleware = new AuthTokenMiddleware(['123456789']);

        $response = $middleware->process(
            $this->bearerRequest('000000000'),
            $this->okHandler(),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // UrlGenerator: signed URL integrity
    // -----------------------------------------------------------------

    public function testSignedUrlWithArrayParamFailsVerification(): void
    {
        $generator = new UrlGenerator([
            ['path' => '/download/{file}', 'name' => 'download'],
        ]);
        $key = 'secret-key';

        $url = $generator->signed('download', ['file' => 'report.pdf'], 3600, $key);
        self::assertTrue($generator->verify($url, $key));

        $tampered = $url . '&role[admin]=1';
        self::assertFalse($generator->verify($tampered, $key));
    }

    public function testSignedUrlWithScalarParamStillFails(): void
    {
        $generator = new UrlGenerator([
            ['path' => '/download/{file}', 'name' => 'download'],
        ]);
        $key = 'secret-key';

        $url = $generator->signed('download', ['file' => 'report.pdf'], 3600, $key);

        self::assertFalse($generator->verify($url . '&role=admin', $key));
    }

    public function testSignedUrlRoundTripUnaffected(): void
    {
        $generator = new UrlGenerator([
            ['path' => '/invite/{id}', 'name' => 'invite'],
        ]);
        $key = 'secret-key';

        $url = $generator->signed('invite', ['id' => 42, 'locale' => 'en_US'], 60, $key);

        self::assertTrue($generator->verify($url, $key));
    }

    // -----------------------------------------------------------------
    // RequestFactory: X-Forwarded-For resolution
    // -----------------------------------------------------------------

    public function testForwardedForSkipsSpoofedLeftmostValue(): void
    {
        RequestFactory::trustProxies(['10.0.0.1']);

        $request = RequestFactory::fromArrays([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8',
        ]);

        self::assertSame('5.6.7.8', $request->getServerParams()['REMOTE_ADDR'] ?? null);
    }

    public function testForwardedForWalksTrustedProxyChainFromRight(): void
    {
        RequestFactory::trustProxies(['10.0.0.1', '10.0.0.2']);

        $request = RequestFactory::fromArrays([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'REMOTE_ADDR' => '10.0.0.1',
            // client 5.6.7.8 -> proxy 10.0.0.2 -> proxy 10.0.0.1
            'HTTP_X_FORWARDED_FOR' => '5.6.7.8, 10.0.0.2',
        ]);

        self::assertSame('5.6.7.8', $request->getServerParams()['REMOTE_ADDR'] ?? null);
    }

    public function testForwardedForAllTrustedFallsBackToLeftmost(): void
    {
        RequestFactory::trustProxies(['10.0.0.1', '10.0.0.2']);

        $request = RequestFactory::fromArrays([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.2',
        ]);

        self::assertSame('10.0.0.2', $request->getServerParams()['REMOTE_ADDR'] ?? null);
    }

    public function testForwardedForIgnoredWhenProxyNotTrusted(): void
    {
        $request = RequestFactory::fromArrays([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
            'REMOTE_ADDR' => '9.9.9.9',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        self::assertSame('9.9.9.9', $request->getServerParams()['REMOTE_ADDR'] ?? null);
    }

    // -----------------------------------------------------------------
    // RequestFactory: trusted host with port
    // -----------------------------------------------------------------

    public function testTrustedHostMatchesWhenHostHeaderIncludesPort(): void
    {
        RequestFactory::trustHosts(['example.com']);

        $request = RequestFactory::fromArrays([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com:8080',
        ]);

        self::assertSame('example.com', $request->getUri()->getHost());
    }

    public function testUntrustedHostStillRejectedWithPort(): void
    {
        RequestFactory::trustHosts(['example.com']);

        $this->expectException(UntrustedHostException::class);

        RequestFactory::fromArrays([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'evil.com:8080',
        ]);
    }

    public function testTrustedWildcardHostMatchesSubdomainWithPort(): void
    {
        RequestFactory::trustHosts(['*.example.com']);

        $request = RequestFactory::fromArrays([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'api.example.com:8080',
        ]);

        self::assertSame('api.example.com', $request->getUri()->getHost());
    }

    // -----------------------------------------------------------------
    // UploadedFiles: temp dir validation
    // -----------------------------------------------------------------

    public function testSiblingDirectoryOfTempDirIsRejected(): void
    {
        $tempDir = realpath(sys_get_temp_dir());
        self::assertNotFalse($tempDir);

        $sibling = $tempDir . '_evil_marwa_test';
        @mkdir($sibling, 0777, true);
        $file = $sibling . '/secret.txt';
        file_put_contents($file, 'sensitive');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('outside the temp directory');

            UploadedFiles::normalize([
                'upload' => [
                    'tmp_name' => $file,
                    'size' => 9,
                    'error' => UPLOAD_ERR_OK,
                    'name' => 'x.txt',
                    'type' => 'text/plain',
                ],
            ]);
        } finally {
            @unlink($file);
            @rmdir($sibling);
        }
    }

    public function testFileInsideTempDirIsAccepted(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'marwa_upload_test_');
        self::assertNotFalse($file);
        file_put_contents($file, 'data');

        try {
            $result = UploadedFiles::normalize([
                'upload' => [
                    'tmp_name' => $file,
                    'size' => 4,
                    'error' => UPLOAD_ERR_OK,
                    'name' => 'y.txt',
                    'type' => 'text/plain',
                ],
            ]);

            self::assertArrayHasKey('upload', $result);
        } finally {
            @unlink($file);
        }
    }

    // -----------------------------------------------------------------
    // RouterFactory: conflict detection across placeholder names
    // -----------------------------------------------------------------

    public function testConflictDetectedForRenamedPlaceholders(): void
    {
        $factory = new RouterFactory();
        $factory->fluent()->get('/users/{id}', static fn () => 'a');

        $this->expectException(\Marwa\Router\Exceptions\RouteConflictException::class);
        $factory->fluent()->get('/users/{name}', static fn () => 'b');
    }

    public function testNoConflictForDifferentConstraints(): void
    {
        $factory = new RouterFactory();
        $factory->fluent()->get('/users/{id}', static fn () => 'a')->where('id', '\d+');
        $factory->fluent()->get('/users/{name}', static fn () => 'b')->where('name', '[a-z]+');

        self::assertCount(2, $factory->routes());
    }

    // -----------------------------------------------------------------
    // Fluent groups: nested where precedence
    // -----------------------------------------------------------------

    public function testNestedGroupWhereOverridesParent(): void
    {
        $factory = new RouterFactory();
        $factory->fluent()->group(
            ['prefix' => '/api', 'where' => ['id' => '\d+']],
            function ($reg): void {
                $reg->group(
                    ['prefix' => '/special', 'where' => ['id' => '[a-z]+']],
                    function ($reg2): void {
                        $reg2->get('/item/{id}', static fn () => 'x')->name('special.item');
                    },
                );
            },
        );

        $routes = $factory->routes();
        self::assertSame('/api/special/item/{id:[a-z]+}', $routes[0]['path']);
    }

    // -----------------------------------------------------------------
    // RequestGuardMiddleware: nested query params
    // -----------------------------------------------------------------

    public function testNestedQueryParamsDoNotEmitWarnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;

            return true;
        });

        try {
            $middleware = new RequestGuardMiddleware();
            $request = (new ServerRequest([], [], new Uri('http://example.com/'), 'GET'))
                ->withQueryParams(['a' => ['b' => '1']]);

            $response = $middleware->process($request, $this->okHandler());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testControlCharsInNestedQueryValuesRejected(): void
    {
        $middleware = new RequestGuardMiddleware();
        $request = (new ServerRequest([], [], new Uri('http://example.com/'), 'GET'))
            ->withQueryParams(['a' => ['b' => "evil\x01value"]]);

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(400, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // ContentTypeMiddleware: media type matching
    // -----------------------------------------------------------------

    public function testBogusContentTypeContainingJsonSubstringRejected(): void
    {
        $middleware = new ContentTypeMiddleware();
        $request = new ServerRequest([], [], null, 'POST', 'php://memory', [
            'Content-Type' => 'text/plain; x=application/json',
        ]);

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(415, $response->getStatusCode());
    }

    public function testJsonContentTypeWithCharsetAccepted(): void
    {
        $middleware = new ContentTypeMiddleware();
        $request = new ServerRequest([], [], null, 'POST', 'php://memory', [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testVendorJsonSuffixAccepted(): void
    {
        $middleware = new ContentTypeMiddleware();
        $request = new ServerRequest([], [], null, 'POST', 'php://memory', [
            'Content-Type' => 'application/problem+json',
        ]);

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // HttpRequest::routeParams
    // -----------------------------------------------------------------

    public function testRouteParamsExcludesMiddlewareAttributes(): void
    {
        $request = (new ServerRequest([], [], null, 'GET', 'php://memory'))
            ->withAttribute('csrf_token', 'secret')
            ->withAttribute('auth_token', 'secret')
            ->withAttribute('request_id', 'secret')
            ->withAttribute('id', '42');

        $http = new HttpRequest($request);

        self::assertSame(['id' => '42'], $http->routeParams());
    }

    // -----------------------------------------------------------------
    // CorsMiddleware: Vary header
    // -----------------------------------------------------------------

    public function testCorsDoesNotClobberExistingVaryHeader(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new TextResponse('ok'))->withHeader('Vary', 'Accept-Encoding');
            }
        };

        $request = (new ServerRequest([], [], new Uri('http://example.com/'), 'GET'))
            ->withHeader('Origin', 'https://example.com');

        $response = $middleware->process($request, $handler);

        $vary = $response->getHeader('Vary');
        self::assertContains('Accept-Encoding', $vary);
        self::assertContains('Origin', $vary);
    }

    // -----------------------------------------------------------------
    // RequestIdMiddleware: client-supplied ID validation
    // -----------------------------------------------------------------

    public function testValidClientRequestIdIsKept(): void
    {
        $middleware = new RequestIdMiddleware();
        $request = (new ServerRequest([], [], new Uri('http://example.com/'), 'GET'))
            ->withHeader('X-Request-Id', 'abc-123_OK.1');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame('abc-123_OK.1', $response->getHeaderLine('X-Request-Id'));
    }

    public function testMaliciousClientRequestIdIsReplaced(): void
    {
        $middleware = new RequestIdMiddleware();
        // Legal in an HTTP header value, but not a safe request ID shape.
        $request = (new ServerRequest([], [], new Uri('http://example.com/'), 'GET'))
            ->withHeader('X-Request-Id', 'forged id; <script>');

        $response = $middleware->process($request, $this->okHandler());

        $id = $response->getHeaderLine('X-Request-Id');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function testOversizedClientRequestIdIsReplaced(): void
    {
        $middleware = new RequestIdMiddleware();
        $request = (new ServerRequest([], [], new Uri('http://example.com/'), 'GET'))
            ->withHeader('X-Request-Id', str_repeat('a', 500));

        $response = $middleware->process($request, $this->okHandler());

        $id = $response->getHeaderLine('X-Request-Id');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    // -----------------------------------------------------------------
    // Response::cookie attribute injection
    // -----------------------------------------------------------------

    public function testCookieRejectsInjectedDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Response())->cookie('n', 'v', domain: 'example.com; HttpOnly');
    }

    public function testCookieRejectsInjectedPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Response())->cookie('n', 'v', path: '/; Secure');
    }

    public function testCookieAcceptsNormalPathAndDomain(): void
    {
        $response = (new Response())->cookie('n', 'v', path: '/app', domain: 'sub.example.com');

        $header = $response->getResponse()->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('Path=/app', $header);
        self::assertStringContainsString('Domain=sub.example.com', $header);
    }

    // -----------------------------------------------------------------
    // CsrfMiddleware: configurable HttpOnly
    // -----------------------------------------------------------------

    public function testCsrfCookieIsHttpOnlyByDefault(): void
    {
        $middleware = new \Marwa\Router\Middleware\CsrfMiddleware();
        $request = new ServerRequest([], [], new Uri('http://example.com/'), 'GET');

        $response = $middleware->process($request, $this->okHandler());

        self::assertStringContainsString('HttpOnly', $response->getHeaderLine('Set-Cookie'));
    }

    public function testCsrfCookieHttpOnlyCanBeDisabled(): void
    {
        $middleware = new \Marwa\Router\Middleware\CsrfMiddleware(httpOnly: false);
        $request = new ServerRequest([], [], new Uri('http://example.com/'), 'GET');

        $response = $middleware->process($request, $this->okHandler());

        self::assertStringNotContainsString('HttpOnly', $response->getHeaderLine('Set-Cookie'));
    }

    // -----------------------------------------------------------------
    // ThrottleMiddleware: PSR-16 key safety and constructor validation
    // -----------------------------------------------------------------

    public function testThrottleHandlesReservedCacheKeyCharsInHeader(): void
    {
        $cache = new class () implements \Psr\SimpleCache\CacheInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            private function checkKey(mixed $key): void
            {
                if (!is_string($key) || $key === '' || strpbrk($key, '{}()/\@:') !== false) {
                    throw new \InvalidArgumentException('Invalid key');
                }
            }

            public function get($key, $default = null): mixed
            {
                $this->checkKey($key);

                return $this->data[$key] ?? $default;
            }

            public function set($key, $value, $ttl = null): bool
            {
                $this->checkKey($key);
                $this->data[$key] = $value;

                return true;
            }

            public function delete($key): bool
            {
                $this->checkKey($key);
                unset($this->data[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->data = [];

                return true;
            }

            public function getMultiple($keys, $default = null): iterable
            {
                $out = [];
                foreach ($keys as $key) {
                    $out[$key] = $this->get($key, $default);
                }

                return $out;
            }

            public function setMultiple($values, $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple($keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }

                return true;
            }

            public function has($key): bool
            {
                $this->checkKey($key);

                return isset($this->data[$key]);
            }
        };

        $middleware = new \Marwa\Router\Middleware\ThrottleMiddleware($cache, 10, 60, 'X-API-Key');
        $request = (new ServerRequest([], [], new Uri('http://example.com/'), 'GET'))
            ->withHeader('X-API-Key', 'abc{def}/ghi@jkl');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testThrottleRejectsZeroLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \Marwa\Router\Middleware\ThrottleMiddleware(
            new \Marwa\Router\Tests\Fixtures\InMemoryCache(),
            0,
            60,
        );
    }

    public function testThrottleRejectsZeroPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \Marwa\Router\Middleware\ThrottleMiddleware(
            new \Marwa\Router\Tests\Fixtures\InMemoryCache(),
            10,
            0,
        );
    }

    // -----------------------------------------------------------------
    // ExceptionToResponseMiddleware: status map validation
    // -----------------------------------------------------------------

    public function testExceptionStatusMapRejectsInvalidCodes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \Marwa\Router\Middleware\ExceptionToResponseMiddleware([
            \RuntimeException::class => 999,
        ]);
    }

    public function testExceptionStatusMapAcceptsValidCodes(): void
    {
        $middleware = new \Marwa\Router\Middleware\ExceptionToResponseMiddleware([
            \RuntimeException::class => 503,
        ]);

        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };

        $response = $middleware->process(
            new ServerRequest([], [], new Uri('http://example.com/'), 'GET'),
            $handler,
        );

        self::assertSame(503, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Response::download sanity
    // -----------------------------------------------------------------

    public function testDownloadIncludesContentLengthForRegularFiles(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'marwa_download_test_');
        self::assertNotFalse($file);
        file_put_contents($file, 'hello');

        try {
            $response = Response::download($file, 'hello.txt');

            self::assertSame('5', $response->getHeaderLine('Content-Length'));
            self::assertStringContainsString('hello.txt', $response->getHeaderLine('Content-Disposition'));
        } finally {
            @unlink($file);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function bearerRequest(string $token): ServerRequestInterface
    {
        return (new ServerRequest([], [], new Uri('http://example.com/'), 'GET'))
            ->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['ok' => true]);
            }
        };
    }

    private function captureHandler(\Closure $assertions): RequestHandlerInterface
    {
        return new class ($assertions) implements RequestHandlerInterface {
            public function __construct(
                private readonly \Closure $assertions,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->assertions)($request);

                return new JsonResponse(['ok' => true]);
            }
        };
    }
}
