<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Examples\Middleware\TimingMiddleware;
use Marwa\Router\Attributes\{Prefix, Route, UseMiddleware,GroupMiddleware, Where,Domain};
use Marwa\Router\Response;

#[Prefix('/api/users', name: 'users.')]
#[Where('id', '\d+')]
//#[GroupMiddleware(\Examples\Middleware\ApiKeyMiddleware::class)]
//#[Domain("localhost")]
final class UserController
{
   
    #[Route('GET', '/', name: 'index')]
    #[UseMiddleware(TimingMiddleware::class)]
    public function index()
    {
        return Response::html("Blazing Fast!!!");
    }

    #[Route('GET', '/{id}', name: 'show')]
    public function show($req, array $args)
    {
        return Response::text('User ' . $args['id']);
    }
}
