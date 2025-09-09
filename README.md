# Marwa Router

Attribute-driven routing and a fluent, Laravel-style API on top of league/route — with zero League exposure in your app code.

- ✅ PHP 8 Attributes (native) — `#[Route]`, `#[Prefix]`, `#[UseMiddleware]`, `#[GroupMiddleware]`, `#[Where]`, `#[Domain]`, `#[Throttle]`
- ✅ Fluent manual routes (`$app->fluent()->get(...)->name(...)->middleware(...)->register()`)
- ✅ Optional trailing slash matching (`/foo` and `/foo/`)
- ✅ PSR-15 middlewares (class & method level)
- ✅ PSR-16 throttle middleware (Redis/Filesystem/Array cache)
- ✅ Domain binding and param constraints
- ✅ Custom Not Found handler
- ✅ Route registry & `bin/routes-dump`

## Install

```bash
composer require memran/marwa-router
```

# Usage

## Basic Setup

```bash
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Marwa\Router\Router;
use Laminas\Diactoros\ServerRequestFactory;

// Create request
$request = ServerRequestFactory::fromGlobals();

// Register routes from annotations
$annotationRouter = Router::createAnnotationRouter(
    'App\Controllers',
    __DIR__ . '/src/Controllers'
);

$annotationRouter->registerRoutesFromAnnotations();

// Dispatch
$response = Router::dispatch($request);
```

## Controller Example

```bash

namespace App\Controllers;

use Marwa\Router\Attributes\Route;
use Marwa\Router\Attributes\RoutePrefix;
use Psr\Http\Message\ResponseInterface;
use Laminas\Diactoros\Response\JsonResponse;

#[RoutePrefix('/api/users')]
class UserController
{
    #[Route('GET', '', 'users.index')]
    public function index(): ResponseInterface
    {
        return new JsonResponse(['users' => []]);
    }

    #[Route('GET', '/{id:\d+}', 'users.show')]
    public function show(array $args): ResponseInterface
    {
        return new JsonResponse(['user' => ['id' => $args['id']]]);
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

## Print router

Make it executable: chmod +x bin/routes-dump.php
Run: php bin/routes-dump.php

## URL Generator

```bash
$urls = new \Marwa\Router\UrlGenerator($factory->routes());
$show = $urls->for('users.show', ['id' => 42]); // -> /api/users/42
```
