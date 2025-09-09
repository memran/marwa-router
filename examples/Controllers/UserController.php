<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Laminas\Diactoros\Response\JsonResponse;
use Marwa\Router\Attributes\{Prefix, Route, Where};

#[\Marwa\Router\Attributes\Prefix('/api/users', name: 'users.')]
#[\Marwa\Router\Attributes\Where('id', '\d+')]
final class UserController
{
    #[\Marwa\Router\Attributes\Route('GET', '', name: 'index')]
    public function index()
    {
        return 'OK';
    }

    #[\Marwa\Router\Attributes\Route('GET', '/{id}', name: 'show')]
    public function show($req, array $args)
    {
        return 'User ' . $args['id'];
    }
}
