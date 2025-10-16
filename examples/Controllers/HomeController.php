<?php

declare(strict_types=1);

namespace Examples\Controllers;
use Marwa\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Marwa\Router\Response;
use Psr\Http\Message\ResponseInterface;

final class HomeController
{
    #[Route('GET', '/', name: 'home')]
    public function home(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json (['ok' => true, 'message' => 'Marwa Router is alive','request'=>$request]);
    }
}
