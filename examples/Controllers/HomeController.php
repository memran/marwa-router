<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Laminas\Diactoros\Response\JsonResponse;
use Marwa\Router\Attributes\Prefix;
use Marwa\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    #[Route('GET', '/', name: 'home')]
    public function home(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'message' => 'Marwa Router is alive']);
    }
}
