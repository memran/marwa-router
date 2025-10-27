# Marwa Router

![License](https://img.shields.io/github/license/memran/marwa-router)
[![Total Downloads](https://img.shields.io/packagist/dt/memran/marwa-router.svg?style=flat-square)](https://packagist.org/packages/memran/marwa-router)
![PHP Version](https://img.shields.io/badge/PHP-8.1+-blue)

Attribute-driven routing and a fluent API on top of league/route.

- ✅ PHP 8 Attributes (native) — `#[Route]`, `#[Prefix]`, `#[UseMiddleware]`, `#[GroupMiddleware]`, `#[Where]`, `#[Domain]`, `#[Throttle]`
- ✅ Fluent manual routes (`$app->fluent()->get(...)->name(...)->middleware(...)->register()`)
- ✅ Optional trailing slash matching (`/foo` and `/foo/`)
- ✅ PSR-15 middlewares (class & method level)
- ✅ PSR-16 throttle middleware (Redis/Filesystem/Array cache)
- ✅ Domain binding and param constraints
- ✅ Custom Not Found handler
- ✅ Route registry & `bin/routes-dump`
- ✅ Framework-agnostic and PSR-7 compliant
- ✅ Works with Laminas Diactoros request objects
- ✅ Supports:
  - Query parameters
  - POST / JSON body
  - Route attributes
  - Uploaded files
  - Cookies and headers
  - Automatically falls back to PHP globals if not set manually

## Install

```bash
composer require memran/marwa-router
```

# Usage

## Basic Setup

```bash
<?php
declare(strict_types=1);

use Marwa\Router\RouterFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\SimpleCache\CacheInterface;

require __DIR__ . '/../vendor/autoload.php';

$app = new RouterFactory();

$app->fluent()->get('/', fn() => 'ok')->name('hello')->register();

// Fluent routes (optional)
$app->fluent()->group(['prefix' => '/api', 'name' => 'api.'], function ($r) {
    $r->get('/hello', fn() => new JsonResponse(['hi' => 'there']))
      ->name('hello')
      ->register();
});

// Attribute scan : optional (point this at your controllers folder)
//$app->registerFromDirectories([__DIR__ . '/controllers'], strict: true);

// Custom 404 (optional; strategy wraps strings/arrays accordingly)
$app->setNotFoundHandler(fn($req) =>
    '<h1>Oops!</h1><p>' . htmlspecialchars($req->getUri()->getPath()) . ' not found.</p>'
);

$app->run();

```

Run a dev server:

```bash
php -S 127.0.0.1:8000 -t examples
```

Visit:

    http://127.0.0.1:8000/api/hello

Any missing route returns your strategy’s 404.

## Controller Example

```bash
<?php
declare(strict_types=1);

namespace Examples\Controllers;

use Marwa\Router\Response;
use Marwa\Router\Attributes\{Prefix, Route, UseMiddleware, GroupMiddleware, Where, Domain, Throttle};
use Psr\Http\Message\ServerRequestInterface;

#[Prefix('/api/users', name: 'users.')]
#[GroupMiddleware(\Examples\Middleware\ApiKeyMiddleware::class)]
#[Where('id', '\d+')]
#[Throttle(100, 60, 'ip')] // 100 requests per 60s per IP
final class UserController
{
    #[Route('GET', '', name: 'index')]
    public function index(): ResponseInterface
    {
        $input = new HttpRequest($request);
        return Response::json(['users' => $input->all()]);
    }

    #[UseMiddleware(\Examples\Middleware\TimingMiddleware::class)]
    #[Route('GET', '/{id}', name: 'show')]
    public function show(ServerRequestInterface $req): ResponseInterface
    {
        $input = Input::setRequest($request);
        return Response::json(['id' => Input::get('id')]);
    }
}

```

# Attributes

## @Route

Define HTTP method and path for a controller method.

```bash
#[Route('GET', '', name: 'index')]
```

## @RoutePrefix

Define a prefix for all routes in a controller.

```bash
#[Prefix('/api/users', name: 'users.')]
```

## @Middleware

Apply middleware to controllers or methods.

```bash
#[Middleware(new AuthMiddleware())]
```

## Custom 404 (HTML strategy shown):

```bash
$app->setNotFoundHandler(function ($req) {
    return '<h1>Not Found</h1><p>' . htmlspecialchars($req->getUri()->getPath()) . '</p>';
});

```

# Tutorial: Fluent Routing

```bash
$app->fluent()
    ->group(['prefix' => '/api', 'name' => 'api.'], function ($r) {
        $r->get('/ping', fn() => 'pong')
          ->name('ping')
          ->middleware(\Examples\Middleware\TimingMiddleware::class)
          ->register();

        $r->get('/users/{id}', [\Examples\Controllers\UserController::class, 'show'])
          ->where('id', '\d+')
          ->name('users.show')
          ->throttle(60, 60, 'ip') // 60/min per IP
          ->register();
    });
```

Notes:

- ->register() finalizes each route in the fluent builder.
- ->where(), ->middleware(), ->throttle(), ->name() are chainable.
- Domain: ->domain('api.example.com').

## Throttling (PSR-16)

Add #[Throttle(limit, seconds, key)] at class or method level, or ->throttle() in fluent routes.
You must provide a PSR-16 cache to RouterFactory.

Examples:

- #[Throttle(100, 60, 'ip')] → 100/min per IP (uses client IP)
- #[Throttle(20, 60, 'X-API-Key')] → 20/min per API key (header)
- Fluent: ->throttle(60, 60, 'ip')

If you use throttle and forget to supply a cache, the router will throw at bootstrap.

## Domain & Param Constraints

### Attributes:

```bash
    #[Domain('api.example.com')]
    #[Where('slug', '[a-z0-9-]+')]
    #[Route('GET', '/posts/{slug}', name: 'posts.show')]
```

### Fluent:

```bash
    $app->fluent()->get('/posts/{slug}', [Controller::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->domain('api.example.com')
    ->name('posts.show')
    ->register();
```

## Route Dump (CLI)

A tiny CLI prints the effective route table.

```bash
php bin/routes-dump.php --dir=/absolute/path/to/examples/controllers
```

Windows:

```bash
php bin\routes-dump.php --dir="F:\projects\memran-marwa-router\examples\Controllers"
```

Alternatively, point it at a bootstrap that returns your configured RouterFactory:

```bash
php bin/routes-dump.php --bootstrap=examples/bootstrap.php
```

## URL Generator

```bash
$urls = new \Marwa\Router\UrlGenerator($factory->routes());
$show = $urls->for('users.show', ['id' => 42]); // -> /api/users/42
```

# Full Example: Minimal App

```bash
<?php

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Marwa\Router\RouterFactory;

$app = new RouterFactory();

// 1) Annotation scan (optional)
$app->registerFromDirectories([__DIR__ . '/Controllers']);

// Run app (reads globals, dispatches, emits)
$app->run();

```

### Get query or body parameters

```bash
Input::setRequest($request);
$name  = Input::get('name');
$email = Input::post('email');
$page  = Input::query('page', 1);
$all   = Input::all();
```

### Get route parameters (from League\Route)

```bash
$id = Input::route('id');
```

### Get uploaded file

```bash
$file = Input::file('avatar');
```

### Get cookie or header

```bash
$session = Input::cookie('session');
$agent = Input::header('User-Agent');

```

### Get HTTP metadata

```bash
$method = Input::method(); // GET, POST, etc.
$url    = Input::url();    // Full URL
```

### Custom Request

You can create a synthetic request easily using your own data:

```bash
use Marwa\Request\Http\RequestFactory;
use Marwa\Request\Http\Input;

$request = RequestFactory::fromArrays(
    server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
    query: ['q' => 'search'],
    parsedBody: ['name' => 'Emran'],
    cookies: ['session' => 'xyz']
);

Input::setRequest($request);

echo Input::get('name'); // Emran
echo Input::query('q');  // search
```

## Troubleshooting

- “No routes registered” in routes-dump
  Use absolute --dir paths or --bootstrap that returns $app. On Windows, path case/separators can differ; the included ClassLocator is Windows-safe.

- #[Prefix] routes missing from dump
  This package registers prefixed routes eagerly (no lazy group closures), so they should show. If not, verify your controller namespace and that the file is inside the scanned dir.

- Trailing slash quirks
  Optional trailing slash is enabled by default (e.g., /foo and /foo/). Disable with $app->setTrailingSlashOptional(false).

- Throttle throws “CacheInterface not provided”
  Supply a PSR-16 cache to RouterFactory or remove the #[Throttle]/->throttle() usage.

## License

MIT

## Credits

Built on the excellent league/route and PSR ecosystem.
