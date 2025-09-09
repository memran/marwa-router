<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Laminas\Diactoros\Response\JsonResponse;
use Marwa\Router\Attributes\Prefix;
use Marwa\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;

#[Prefix('/api/users', name: 'users.')]
final class UserController
{
    #[Route('GET', '/', name: 'index')]
    public function index(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'users' => []]);
    }

    #[Route('GET', '/{id:\d+}', name: 'show')]
    public function show(ServerRequestInterface $request, array $args): JsonResponse
    {
        return new JsonResponse(['id' => (int)($args['id'] ?? 0)]);
    }

    #[Route('POST', '', name: 'create')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        // $data = $request->getParsedBody();
        return new JsonResponse(['created' => true], 201);
    }
}
