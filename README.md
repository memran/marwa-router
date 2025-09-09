# Marwa Router

Attribute-driven router on top of **league/route**.  
Scan your controller classes, discover PHP 8 attributes, and auto-register routes.

- 💡 **PHP 8 Attributes** (native, no Doctrine)
- 🧭 **Controller Prefix** + name prefix
- 🧱 **PSR-15 Middlewares** at class & method level
- 🧰 Optional **PSR-11 Container** to resolve controllers/middlewares
- 🗂️ Scan directories or register explicit class lists

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
