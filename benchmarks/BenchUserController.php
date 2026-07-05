<?php

declare(strict_types=1);

namespace Marwa\Router\Benchmarks;

use Marwa\Router\Attributes\Route;
use Marwa\Router\Response;

final class BenchUserController
{
    #[Route('GET', '/users/{id}', name: 'users.show')]
    public function show(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }

    #[Route('GET', '/users', name: 'users.index')]
    public function index(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }

    #[Route('POST', '/users', name: 'users.create')]
    public function create(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }
}
