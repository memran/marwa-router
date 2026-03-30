# Marwa Router

[![CI](https://img.shields.io/github/actions/workflow/status/memran/marwa-router/ci.yml?branch=main&label=CI)](https://github.com/memran/marwa-router/actions/workflows/ci.yml)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-11.x-0E9F6E)](#development)
[![PHPStan](https://img.shields.io/badge/PHPStan-2.x-6B46C1)](#development)
[![Packagist Version](https://img.shields.io/packagist/v/memran/marwa-router)](https://packagist.org/packages/memran/marwa-router)
[![PHP Version](https://img.shields.io/packagist/php-v/memran/marwa-router)](https://packagist.org/packages/memran/marwa-router)
[![Packagist Downloads](https://img.shields.io/packagist/dt/memran/marwa-router)](https://packagist.org/packages/memran/marwa-router)
[![License](https://img.shields.io/packagist/l/memran/marwa-router)](LICENSE)

Marwa Router is a framework-agnostic routing library for PHP 8.2+ built on top of `league/route`. It combines PHP 8 attributes, a fluent route builder, PSR-7 request handling, PSR-15 middleware integration, and small convenience helpers for responses, input access, route inspection, and signed URLs.

## Why Marwa Router

- Keep route definitions close to controller code with native PHP attributes
- Register routes fluently when you want explicit bootstrap logic
- Stay compatible with PSR-7, PSR-15, PSR-16, and PSR-11 components
- Attach middleware, host constraints, parameter rules, and throttling per route or controller
- Use small utilities for JSON/HTML responses, request input, URL generation, and route inspection

## Stability

This package follows semantic versioning for its documented public API under `src/`. Backward-compatible additions may appear in minor releases. Behavioral breaks, constructor signature changes, or renamed public methods belong in major releases and should be called out in [`CHANGELOG.md`](CHANGELOG.md).

## Features

- Attribute routing with `#[Route]`, `#[Prefix]`, `#[Where]`, `#[Domain]`, `#[UseMiddleware]`, `#[GroupMiddleware]`, and `#[Throttle]`
- Fluent route registration with grouping, naming, middleware, domain, constraints, and throttling
- Optional trailing-slash matching
- Direct route mapping with `map()` and a fluent registrar for grouped definitions
- PSR-11 container integration for controller and middleware resolution
- PSR-16-backed throttling middleware
- Optional PSR-3 logging hooks for dispatch failures and throttling events
- Trusted proxy and trusted host handling in `RequestFactory`
- Metadata cache and compiled route bootstrap cache for faster startup
- Response helpers for JSON, HTML, text, redirects, cookies, and downloads
- Input helpers for query params, parsed body, route params, headers, cookies, and files
- Route registry inspection with `bin/routes-dump.php`
- Signed URL generation and verification

## Requirements

- PHP 8.2 or newer
- Composer

## Installation

```bash
composer require memran/marwa-router
```

## Quick Start

```php
<?php

declare(strict_types=1);

use Marwa\Router\Response;
use Marwa\Router\RouterFactory;

require __DIR__ . '/vendor/autoload.php';

$router = new RouterFactory();

$router->fluent()
    ->get('/', fn () => Response::json(['ok' => true]))
    ->name('home')
    ->register();

$router->setNotFoundHandler(fn () => Response::text('Route Not Found', 404));
$router->run();
```

Start a local server:

```bash
php -S 127.0.0.1:8000 -t examples
```

## Complete Tutorial

### 1. Bootstrap the Router

```php
<?php

declare(strict_types=1);

use Marwa\Router\Response;
use Marwa\Router\RouterFactory;

require __DIR__ . '/../vendor/autoload.php';

$router = new RouterFactory();
$router->setTrailingSlashOptional(true);
$router->setNotFoundHandler(fn () => Response::json(['message' => 'Not Found'], 404));
```

Use `setContainer()` when controllers or middleware should be resolved from a PSR-11 container. Use `setCache()` when throttling is enabled.

If your app runs behind a reverse proxy or load balancer, configure trust explicitly:

```php
use Marwa\Router\Http\RequestFactory;

RequestFactory::trustProxies(['127.0.0.1', '10.0.0.0/8']);
RequestFactory::trustHosts(['example.com', '*.example.com']);
```

### 2. Register Attribute-Based Controllers

```php
$router->registerFromDirectories([__DIR__ . '/../src/Controller'], strict: true);
```

`strict: true` is recommended in production so missing controller directories fail fast.

Example controller:

```php
<?php

namespace App\Controller;

use Marwa\Router\Attributes\Prefix;
use Marwa\Router\Attributes\Route;
use Marwa\Router\Attributes\UseMiddleware;
use Marwa\Router\Attributes\Where;
use Marwa\Router\Response;
use Psr\Http\Message\ResponseInterface;

#[Prefix('/users', name: 'users.')]
#[Where('id', '\d+')]
final class UserController
{
    #[Route('GET', '/', name: 'index')]
    public function index(): ResponseInterface
    {
        return Response::json(['users' => []]);
    }

    #[Route('GET', '/{id}', name: 'show')]
    #[UseMiddleware(\App\Middleware\AuditMiddleware::class)]
    public function show(): ResponseInterface
    {
        return Response::json(['user' => 'example']);
    }
}
```

### 3. Add Fluent Routes

Use fluent routes for closures, bootstrap-only endpoints, or when you prefer explicit configuration.

```php
$router->fluent()->group(['prefix' => '/api', 'name' => 'api.'], function ($routes): void {
    $routes->get('/ping', fn () => Response::text('pong'))
        ->name('ping')
        ->register();

    $routes->get('/posts/{slug}', [\App\Controller\PostController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('posts.show')
        ->register();
});
```

Direct `map()` is also available when you want to register a route without the fluent builder:

```php
$router->map(
    ['GET', 'HEAD'],
    '/health',
    static fn () => Response::json(['status' => 'ok']),
    name: 'health',
);
```

### 4. Work with Middleware and Throttling

Per-route middleware can be attached with `#[UseMiddleware]`, `#[GroupMiddleware]`, or `->middleware(...)`.

If you use throttling, provide a PSR-16 cache:

```php
$router = new RouterFactory(cache: $cache);
```

Attribute example:

```php
use Marwa\Router\Attributes\Throttle;

#[Throttle(100, 60, 'ip')]
final class ApiController
{
    #[Route('GET', '/stats', name: 'stats')]
    public function stats(): ResponseInterface
    {
        return Response::json(['ok' => true]);
    }
}
```

Fluent example:

```php
$router->fluent()
    ->post('/api/login', [AuthController::class, 'login'])
    ->throttle(10, 60, 'ip')
    ->name('api.login')
    ->register();
```

Included middleware classes live in `src/Middleware/`:

- `BodyParsingMiddleware`
- `ContentTypeMiddleware`
- `RequestGuardMiddleware`
- `SecurityHeadersMiddleware`
- `ThrottleMiddleware`

### 5. Read Input Data

`Marwa\Router\Http\Input` and `Marwa\Router\Http\HttpRequest` provide ergonomic access to PSR-7 request data.

```php
use Marwa\Router\Http\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

public function search(ServerRequestInterface $request): ResponseInterface
{
    Input::setRequest($request);

    return Response::json([
        'q' => Input::query('q'),
        'page' => Input::query('page', 1),
        'filters' => Input::only(['category', 'status']),
    ]);
}
```

Available helpers include `get()`, `post()`, `query()`, `route()`, `header()`, `cookie()`, `file()`, `only()`, `except()`, `has()`, and `merge()`.

### 6. Generate URLs

The router keeps a route registry that can be passed to `UrlGenerator`.

```php
$urls = new \Marwa\Router\UrlGenerator($router->routes());

$show = $urls->for('users.show', ['id' => 42]);
$signed = $urls->signed('users.show', ['id' => 42], 300, 'app-secret');
$valid = $urls->verify($signed, 'app-secret');
```

### 6.1 Attach a Logger

Provide any PSR-3 logger if you want visibility into missing routes or throttle violations.

```php
$router->setLogger($logger);
```

### 7. Run the Application

```php
$router->run();
```

If you need a response object without emitting it immediately, use `handle()`:

```php
$request = \Marwa\Router\Http\RequestFactory::fromGlobals();
$response = $router->handle($request);
```

For local experimentation, the repository already includes a runnable example:

```bash
php -S 127.0.0.1:8000 -t examples
```

### 8. Inspect Registered Routes

Print the route table discovered from controllers:

```bash
php bin/routes-dump.php --dir=/absolute/path/to/src/Controller
```

Or point the CLI to a bootstrap file that returns a configured `RouterFactory` instance:

```bash
php bin/routes-dump.php --bootstrap=/absolute/path/to/bootstrap.php
```

### 9. Export the Route Registry

```bash
php bin/routes-build-cache.php
```

This writes:

- `var/cache/routes.php` with route metadata
- `var/cache/routes.compiled.php` with a bootstrap callable that re-registers cacheable routes without rescanning controller files

Compiled route cache supports string handlers, `[class-string, method]` handlers, and middleware defined as class strings. It does not support closures or object middleware.

## More Examples

### Attribute Examples

Controller-level host binding and middleware:

```php
use Marwa\Router\Attributes\Domain;
use Marwa\Router\Attributes\GroupMiddleware;
use Marwa\Router\Attributes\Prefix;
use Marwa\Router\Attributes\Route;

#[Prefix('/admin', name: 'admin.')]
#[Domain('admin.example.com')]
#[GroupMiddleware(\App\Middleware\AdminAuthMiddleware::class)]
final class AdminController
{
    #[Route('GET', '/dashboard', name: 'dashboard')]
    public function dashboard(): \Psr\Http\Message\ResponseInterface
    {
        return \Marwa\Router\Response::html('<h1>Admin</h1>');
    }
}
```

Single method responding to multiple HTTP verbs:

```php
#[Route(['GET', 'POST'], '/contact', name: 'contact.submit')]
public function contact(): \Psr\Http\Message\ResponseInterface
{
    return \Marwa\Router\Response::text('Handled');
}
```

### Fluent Route Examples

Named route with middleware and domain:

```php
$router->fluent()
    ->get('/reports/{year}', [ReportController::class, 'show'])
    ->where('year', '\d{4}')
    ->domain('reports.example.com')
    ->middleware(\App\Middleware\AuditMiddleware::class)
    ->name('reports.show')
    ->register();
```

Route group with shared prefix, name prefix, and throttling:

```php
$router->fluent()->group([
    'prefix' => '/api/v1',
    'name' => 'api.v1.',
    'throttle' => ['limit' => 60, 'per' => 60, 'key' => 'ip'],
], function ($routes): void {
    $routes->get('/users', [UserController::class, 'index'])
        ->name('users.index')
        ->register();
});
```

Direct `map()` with middleware, constraints, and a name:

```php
$router->map(
    'GET',
    '/reports/{year}',
    [ReportController::class, 'show'],
    name: 'reports.show',
    middlewares: [\App\Middleware\AuditMiddleware::class],
    where: ['year' => '\d{4}'],
);
```

### Request Access Examples

Using `HttpRequest` directly:

```php
use Marwa\Router\Http\HttpRequest;
use Psr\Http\Message\ServerRequestInterface;

public function store(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
{
    $input = new HttpRequest($request);

    return \Marwa\Router\Response::json([
        'method' => $input->method(),
        'url' => $input->url(),
        'host' => $input->host(),
        'subdomain' => $input->subdomainFor('example.com'),
        'all' => $input->all(),
        'only' => $input->only(['name', 'email']),
        'except' => $input->except(['password']),
        'route' => $input->routeParams(),
        'agent' => $input->header('User-Agent'),
    ]);
}
```

Using the static `Input` facade:

```php
use Marwa\Router\Http\Input;

Input::setRequest($request);

$email = Input::post('email');
$search = Input::query('q');
$token = Input::header('X-Token');
$avatar = Input::file('avatar');
$host = Input::host();
$tenant = Input::subdomainFor('example.com');
$hasFilters = Input::has('filters.status');

Input::merge(['normalized' => true]);
```

Use `subdomainFor()` when your application knows its base domain. It is deterministic for hosts like `tenant.example.com` and `admin.eu.example.co.uk`.

Resetting the static facade in tests:

```php
Input::reset();
```

### Typed Bag and Form Request Examples

Using `InputBag` accessors:

```php
$body = new \Marwa\Router\Http\InputBag([
    'page' => '2',
    'active' => 'true',
    'filters' => ['role' => 'editor'],
]);

$page = $body->int('page');
$active = $body->bool('active');
$filters = $body->array('filters');
```

Minimal `FormRequest` subclass:

```php
use Marwa\Router\Http\FormRequest;

final class CreateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required'],
            'email' => ['required'],
        ];
    }
}
```

Accessing data:

```php
$form = new CreateUserRequest($request, $validator);

if (!$form->authorize()) {
    throw new RuntimeException('Forbidden');
}

$query = $form->query()->string('q');
$name = $form->body()->string('name');
$validated = $form->validate();
```

### File Upload Example

```php
use Laminas\Diactoros\UploadedFile;
use Marwa\Router\Http\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

public function uploadAvatar(ServerRequestInterface $request): ResponseInterface
{
    Input::setRequest($request);

    /** @var UploadedFile|null $avatar */
    $avatar = Input::file('avatar');
    if ($avatar === null || $avatar->getError() !== UPLOAD_ERR_OK) {
        return \Marwa\Router\Response::error('Upload failed', 400);
    }

    $target = __DIR__ . '/../storage/' . $avatar->getClientFilename();
    $avatar->moveTo($target);

    return \Marwa\Router\Response::success(['path' => $target], 'Uploaded');
}
```

### Custom Middleware Example

```php
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $header = 'X-API-Key',
        private string $expected = 'secret',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine($this->header) !== $this->expected) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return $handler->handle($request);
    }
}
```

Use it with attributes or fluent routes:

```php
#[UseMiddleware(ApiKeyMiddleware::class)]
#[Route('POST', '/internal/rebuild', name: 'internal.rebuild')]
public function rebuild(): ResponseInterface
{
    return \Marwa\Router\Response::text('ok');
}
```

Built-in middleware can be attached the same way:

```php
$router->map(
    'POST',
    '/api/users',
    [UserController::class, 'store'],
    middlewares: [
        \Marwa\Router\Middleware\BodyParsingMiddleware::class,
        \Marwa\Router\Middleware\SecurityHeadersMiddleware::class,
        \Marwa\Router\Middleware\RequestGuardMiddleware::class,
    ],
);
```

### Container Integration Example

Any PSR-11 container works. One option is `league/container`:

```php
use League\Container\Container;
use Marwa\Router\RouterFactory;

$container = new Container();
$container->add(\App\Service\UserService::class);
$container->add(\App\Controller\UserController::class)
    ->addArgument(\App\Service\UserService::class);
$container->add(\App\Middleware\ApiKeyMiddleware::class);

$router = new RouterFactory();
$router->setContainer($container);
$router->registerFromDirectories([__DIR__ . '/../src/Controller'], strict: true);
```

Once a container is attached, controller classes and middleware class names are resolved through it before falling back to direct instantiation.

### Signed Download Example

```php
$urls = new \Marwa\Router\UrlGenerator($router->routes());
$downloadUrl = $urls->signed('reports.download', ['id' => 25], 300, $_ENV['APP_KEY']);
```

Controller:

```php
use Marwa\Router\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

public function download(ServerRequestInterface $request, UrlGenerator $urls): ResponseInterface
{
    $url = (string) $request->getUri();

    if (!$urls->verify($url, $_ENV['APP_KEY'])) {
        return \Marwa\Router\Response::forbidden('Invalid or expired signature');
    }

    return \Marwa\Router\Response::download(__DIR__ . '/../reports/report-25.csv');
}
```

### Mini CRUD Example

```php
$router->fluent()->group(['prefix' => '/api/users', 'name' => 'users.'], function ($routes): void {
    $routes->get('/', [UserController::class, 'index'])
        ->name('index')
        ->register();

    $routes->post('/', [UserController::class, 'store'])
        ->middleware(\App\Middleware\ApiKeyMiddleware::class)
        ->name('store')
        ->register();

    $routes->get('/{id}', [UserController::class, 'show'])
        ->where('id', '\d+')
        ->name('show')
        ->register();

    $routes->patch('/{id}', [UserController::class, 'update'])
        ->where('id', '\d+')
        ->name('update')
        ->register();

    $routes->delete('/{id}', [UserController::class, 'delete'])
        ->where('id', '\d+')
        ->name('delete')
        ->register();
});
```

Possible controller methods:

```php
final class UserController
{
    public function index(): \Psr\Http\Message\ResponseInterface
    {
        return \Marwa\Router\Response::json(['data' => []]);
    }

    public function store(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        \Marwa\Router\Http\Input::setRequest($request);

        return \Marwa\Router\Response::created([
            'name' => \Marwa\Router\Http\Input::post('name'),
            'email' => \Marwa\Router\Http\Input::post('email'),
        ]);
    }

    public function show(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $input = new \Marwa\Router\Http\HttpRequest($request);

        return \Marwa\Router\Response::json(['id' => $input->route('id')]);
    }
}
```

### Pagination Example

```php
use Marwa\Router\Http\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

public function index(ServerRequestInterface $request): ResponseInterface
{
    Input::setRequest($request);

    $page = max(1, (int) Input::query('page', 1));
    $perPage = min(100, max(1, (int) Input::query('per_page', 20)));

    return \Marwa\Router\Response::json([
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
        ],
        'data' => [],
    ]);
}
```

### JSON API Error Example

```php
return \Marwa\Router\Response::error(
    'Validation failed',
    422,
    [
        'email' => ['The email field is required.'],
        'password' => ['The password must be at least 12 characters.'],
    ],
);
```

Or with a custom structure:

```php
return \Marwa\Router\Response::json([
    'errors' => [
        ['status' => '422', 'detail' => 'The email field is required.'],
        ['status' => '422', 'detail' => 'The password must be at least 12 characters.'],
    ],
], 422);
```

### Subdomain Routing Example

Attribute-based host binding:

```php
use Marwa\Router\Attributes\Domain;
use Marwa\Router\Attributes\Route;

#[Domain('api.example.com')]
final class ApiStatusController
{
    #[Route('GET', '/status', name: 'api.status')]
    public function status(): \Psr\Http\Message\ResponseInterface
    {
        return \Marwa\Router\Response::json(['ok' => true]);
    }
}
```

Fluent host binding:

```php
$router->fluent()
    ->get('/status', [StatusController::class, 'show'])
    ->domain('status.example.com')
    ->name('status.show')
    ->register();
```

Reading the current host in middleware:

```php
final class TenantMiddleware implements \Psr\Http\Server\MiddlewareInterface
{
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler,
    ): \Psr\Http\Message\ResponseInterface {
        $input = new \Marwa\Router\Http\HttpRequest($request);

        return $handler->handle(
            $request->withAttribute('tenant', $input->subdomainFor('example.com'))
        );
    }
}
```

Reading it later in a controller:

```php
public function dashboard(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
{
    return \Marwa\Router\Response::json([
        'host' => $request->getUri()->getHost(),
        'tenant' => $request->getAttribute('tenant'),
    ]);
}
```

If your app runs on more than one root domain, inject that base domain into middleware from configuration and call `subdomainFor($configuredBaseDomain)`.

### Webhook Verification Example

```php
use Marwa\Router\Http\HttpRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

public function handleWebhook(ServerRequestInterface $request): ResponseInterface
{
    $input = new HttpRequest($request);
    $payload = (string) $request->getBody();
    $signature = (string) $input->header('X-Signature', '');
    $expected = hash_hmac('sha256', $payload, $_ENV['WEBHOOK_SECRET']);

    if (!hash_equals($expected, $signature)) {
        return \Marwa\Router\Response::forbidden('Invalid webhook signature');
    }

    return \Marwa\Router\Response::noContent();
}
```

### Authenticated Admin Area Example

```php
$router->fluent()->group([
    'prefix' => '/admin',
    'name' => 'admin.',
    'middleware' => [\App\Middleware\AdminAuthMiddleware::class],
], function ($routes): void {
    $routes->get('/dashboard', [AdminController::class, 'dashboard'])
        ->name('dashboard')
        ->register();

    $routes->get('/users', [AdminUserController::class, 'index'])
        ->name('users.index')
        ->register();
});
```

Controller-level version:

```php
use Marwa\Router\Attributes\GroupMiddleware;
use Marwa\Router\Attributes\Prefix;

#[Prefix('/admin', name: 'admin.')]
#[GroupMiddleware(\App\Middleware\AdminAuthMiddleware::class)]
final class AdminController
{
    #[Route('GET', '/dashboard', name: 'dashboard')]
    public function dashboard(): \Psr\Http\Message\ResponseInterface
    {
        return \Marwa\Router\Response::html('<h1>Dashboard</h1>');
    }
}
```

### PHPUnit Usage Example

```php
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;

final class UrlGeneratorTest extends TestCase
{
    public function testSignedUrlCanBeVerified(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'users.show', 'path' => '/users/{id}'],
        ]);

        $signed = $generator->signed('users.show', ['id' => 42], 300, 'secret');

        self::assertTrue($generator->verify($signed, 'secret'));
    }

    public function testRequestFactoryBuildsQueryParams(): void
    {
        $request = RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/users?page=2', 'REQUEST_METHOD' => 'GET'],
            query: ['page' => 2],
        );

        self::assertSame(2, $request->getQueryParams()['page']);
    }
}
```

## Response Helpers

`Marwa\Router\Response` exposes small factory helpers:

- `Response::json(...)`
- `Response::html(...)`
- `Response::text(...)`
- `Response::redirect(...)`
- `Response::download(...)`
- `Response::success(...)`
- `Response::error(...)`
- `Response::notFound(...)`
- `Response::serverError(...)`
- `Response::unauthorized(...)`
- `Response::forbidden(...)`
- `Response::created(...)`
- `Response::noContent()`
- `Response::fromArray(...)`

Example:

```php
return Response::success(['id' => 42], 'Created', 201);
```

Additional examples:

```php
return Response::json(['status' => 'ok']);
return Response::html('<p>Hello</p>');
return Response::text('Accepted', 202);
return Response::redirect('/login');
return Response::download(__DIR__ . '/report.csv');
return Response::error('Validation failed', 422, ['email' => ['Required']]);
return Response::notFound();
return Response::unauthorized();
return Response::forbidden();
return Response::serverError();
return Response::noContent();
```

Building a response instance manually:

```php
$response = (new Response())
    ->status(201)
    ->header('X-Request-Id', 'abc123')
    ->cookie('session', 'token', time() + 3600, '/', '', true, true, 'Lax')
    ->body('Created')
    ->getResponse();
```

Creating a response from an array payload:

```php
return Response::fromArray(['html' => '<p>Hello</p>'], 200, ['Content-Type' => 'text/html']);
```

## URL Generator Examples

```php
$generator = new \Marwa\Router\UrlGenerator($router->routes());

$plain = $generator->for('reports.show', ['year' => 2026]);
$withQuery = $generator->for('reports.show', ['year' => 2026, 'format' => 'csv']);
$signed = $generator->signed('reports.show', ['year' => 2026], 600, 'secret-key');
$isValid = $generator->verify($signed, 'secret-key');
```

## Additional Patterns

### RequestFactory Examples

Build a request from PHP globals:

```php
use Marwa\Router\Http\RequestFactory;

$request = RequestFactory::fromGlobals();
$response = $router->dispatch($request);
```

Build a synthetic request for tests or custom runtimes:

```php
use Marwa\Router\Http\RequestFactory;

$request = RequestFactory::fromArrays(
    server: [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/users?page=2',
        'HTTP_HOST' => 'example.test',
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ],
    query: ['page' => 2],
    parsedBody: ['name' => 'Marwa', 'email' => 'marwa@example.com'],
    cookies: ['session' => 'abc123'],
);
```

Trust reverse proxies explicitly before honoring forwarded headers:

```php
use Marwa\Router\Http\RequestFactory;

RequestFactory::trustProxies([
    '127.0.0.1',
    '10.0.0.0/8',
]);
```

With trusted proxies configured, `X-Forwarded-Host`, `X-Forwarded-Proto`, and `X-Forwarded-For` are used to build the effective request URI and client IP. Without trusted proxies, those headers are ignored.

Trusted hosts can be restricted separately:

```php
RequestFactory::trustHosts([
    'example.com',
    '*.example.com',
]);
```

Requests for other hosts will raise `Marwa\Router\Exceptions\UntrustedHostException`.

Clear trust configuration in tests or workers:

```php
RequestFactory::clearTrustedProxies();
RequestFactory::clearTrustedHosts();
```

### Redirect and Cookie Examples

Simple redirect:

```php
return \Marwa\Router\Response::redirect('/login');
```

Manual response with headers and cookies:

```php
$response = (new \Marwa\Router\Response())
    ->status(200)
    ->header('X-Frame-Options', 'DENY')
    ->addHeader('Cache-Control', 'no-store')
    ->cookie('session', 'token', time() + 3600, '/', '', true, true, 'Lax')
    ->body('Authenticated')
    ->getResponse();

return $response;
```

### Custom Not Found Handler

HTML fallback:

```php
$router->setNotFoundHandler(static function (): \Psr\Http\Message\ResponseInterface {
    return \Marwa\Router\Response::html('<h1>404</h1><p>Page not found.</p>', 404);
});
```

JSON fallback that sees the request:

```php
use Psr\Http\Message\ServerRequestInterface;

$router->setNotFoundHandler(static function (ServerRequestInterface $request): array {
    return [
        'message' => 'Route not found',
        'path' => $request->getUri()->getPath(),
    ];
});
```

### Controller Discovery Examples

Register a specific list of controllers:

```php
$router->registerFromClasses([
    \App\Controller\HomeController::class,
    \App\Controller\UserController::class,
]);
```

Scan more than one directory:

```php
$router->registerFromDirectories([
    __DIR__ . '/../src/Controller',
    __DIR__ . '/../modules/Billing/Controller',
], strict: true);
```

### Route Registry Cache Example

Write the discovered route registry to disk:

```php
$router->registerFromDirectories([__DIR__ . '/../src/Controller'], strict: true);
$router->cacheRoutesTo(__DIR__ . '/../var/cache/routes.php');
```

Load the exported registry in another process:

```php
$router = new \Marwa\Router\RouterFactory();
$router->loadRoutesFrom(__DIR__ . '/../var/cache/routes.php');
```

This only restores the route metadata returned by `routes()`. It does not rebuild runtime dispatch rules by itself, so keep normal route registration in your application bootstrap.

Load the compiled bootstrap cache instead when you want faster startup:

```php
$router = new \Marwa\Router\RouterFactory();
$router->loadCompiledRoutesFrom(__DIR__ . '/../var/cache/routes.compiled.php');
```

### Logger Example

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('router');
$logger->pushHandler(new StreamHandler('php://stderr'));

$router->setLogger($logger);
```

The router logs missing routes and invalid not-found handler responses. `ThrottleMiddleware` logs rate-limit violations when a logger is attached to the router.

### Conflict Detection Example

Conflict detection is enabled by default. You can disable it when you intentionally layer multiple route sources and want last-write behavior:

```php
$router->enableConflictDetection(false);
```

## Development

Install dependencies:

```bash
composer install
```

Useful commands:

- `composer test` runs PHPUnit
- `composer test:coverage` prints a text coverage report
- `composer analyse` runs PHPStan
- `composer lint` runs PHP-CS-Fixer in dry-run mode
- `composer fix` applies coding-style fixes
- `composer validate:composer` validates package metadata
- `composer ci` runs the local validation gate

## Project Layout

- `src/` core library code
- `tests/` PHPUnit tests and fixtures
- `examples/` runnable demo application
- `bin/` CLI helpers
- `.github/workflows/` CI configuration

## Production Notes

- Keep `strict_types=1` enabled in application code
- Use `strict: true` for controller discovery in deployment builds
- Provide a real shared PSR-16 cache backend when throttling matters
- Prefer PSR-11 container resolution for non-trivial controllers and middleware
- Return `ResponseInterface`, `string`, or `array` from `setNotFoundHandler()`
- Call `RequestFactory::trustProxies(...)` only for proxy IPs you actually control
- Call `RequestFactory::trustHosts(...)` to reject unexpected host headers early
- Prefer `subdomainFor('example.com')` over naive host splitting in multi-tenant apps
- Use `handle()` in tests, worker runtimes, and custom HTTP emitters
- Attach a PSR-3 logger with `setLogger()` if you want visibility into missing routes and throttling events

## Errors

- Missing routes raise `Marwa\Router\Exceptions\RouteNotFoundException` when no custom not-found handler is configured
- Invalid not-found handler return values raise `Marwa\Router\Exceptions\InvalidNotFoundHandlerResponseException`
- Untrusted hosts raise `Marwa\Router\Exceptions\UntrustedHostException`
- Route definition conflicts raise `Marwa\Router\Exceptions\RouteConflictException`
- Invalid throttle or attribute definitions raise `Marwa\Router\Exceptions\InvalidRouteDefinitionException`

## Contributing

See [AGENTS.md](AGENTS.md) for repository-specific contributor guidance.
