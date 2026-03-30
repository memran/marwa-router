# Marwa Router

Marwa Router is a lightweight PHP 8.2+ routing library built on top of `league/route`. It supports PHP 8 attributes, a fluent route builder, PSR-7 requests, PSR-15 middleware, and PSR-16-backed throttling.

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
    ->get('/', fn() => Response::json(['ok' => true]))
    ->name('home')
    ->register();

$router->run();
```

Run the example app locally:

```bash
php -S 127.0.0.1:8000 -t examples
```

## Attribute Routing

```php
<?php

namespace App\Controller;

use Marwa\Router\Attributes\Prefix;
use Marwa\Router\Attributes\Route;
use Marwa\Router\Response;

#[Prefix('/users', name: 'users.')]
final class UserController
{
    #[Route('GET', '/{id}', name: 'show')]
    public function show(): \Psr\Http\Message\ResponseInterface
    {
        return Response::json(['user' => 'example']);
    }
}
```

Register controllers from one or more directories:

```php
$router->registerFromDirectories([__DIR__ . '/src/Controller'], strict: true);
```

## Fluent Routing

```php
$router->fluent()->group(['prefix' => '/api', 'name' => 'api.'], function ($routes): void {
    $routes->get('/ping', fn() => Response::text('pong'))
        ->name('ping')
        ->register();
});
```

## Throttling and Middleware

Provide a PSR-16 cache implementation if you use `#[Throttle]` or `->throttle()`:

```php
$router = new RouterFactory(cache: $cache);
```

Security-focused middleware included in `src/Middleware/`:

- `RequestGuardMiddleware`
- `ContentTypeMiddleware`
- `BodyParsingMiddleware`
- `SecurityHeadersMiddleware`
- `ThrottleMiddleware`

## CLI Utilities

Print registered routes:

```bash
php bin/routes-dump.php --dir=/absolute/path/to/src/Controller
```

Build a cache file for discovered routes:

```bash
php bin/routes-build-cache.php
```

## Development

Install dependencies:

```bash
composer install
```

Common commands:

- `composer test` runs the PHPUnit suite.
- `composer test:coverage` prints a text coverage report.
- `composer analyse` runs PHPStan level 8.
- `composer lint` checks coding style with PHP-CS-Fixer.
- `composer fix` applies coding-style fixes.
- `composer validate:composer` validates package metadata.
- `composer ci` runs validation, analysis, tests, and style checks.

## Project Layout

- `src/` core library code
- `tests/` PHPUnit tests and fixtures
- `examples/` runnable example application
- `bin/` CLI helpers

## Production Notes

- Use `strict: true` when scanning controller directories so missing paths fail fast.
- Throttling requires a real shared cache in production.
- `setNotFoundHandler()` should return a PSR-7 response, string, or array.
- Prefer controller injection through a PSR-11 container for non-trivial applications.

## Contributing

See [AGENTS.md](AGENTS.md) for repository-specific contribution guidelines.
