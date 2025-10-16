<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Marwa\Router\Attributes\{Prefix, Route, Where};
use Marwa\Router\Response;

#[Prefix('/api/users', name: 'users.')]
#[Where('id', '\d+')]
final class UserController
{
    #[Route('GET', '', name: 'index')]
    public function index()
    {
        return Response::html("ok");
    }

    #[Route('GET', '/{id}', name: 'show')]
    public function show($req, array $args)
    {
        return Response::text('User ' . $args['id']);
    }
}
