<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Examples\Middleware\TimingMiddleware;
use Marwa\Router\Attributes\{Prefix, Route, UseMiddleware, Where};
use Marwa\Router\Response;
use Psr\Http\Message\ResponseInterface;

#[Prefix('/api/users', name: 'users.')]
#[Where('id', '\d+')]
final class UserController
{
    #[Route('GET', '/', name: 'index')]
    #[UseMiddleware(TimingMiddleware::class)]
    public function index(): ResponseInterface
    {
        return Response::html('Blazing Fast!!!');
    }

    #[Route('GET', '/{id}', name: 'show')]
    public function show(mixed $req, array $args): ResponseInterface
    {
        return Response::text('User ' . $args['id']);
    }
}
