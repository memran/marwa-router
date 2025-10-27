<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Marwa\Router\Attributes\Route;
use Marwa\Router\Http\HttpRequest;
use Psr\Http\Message\ServerRequestInterface;
use Marwa\Router\Response;
use Psr\Http\Message\ResponseInterface;

final class HomeController
{
    #[Route('GET', '/', name: 'home')]
    public function home(ServerRequestInterface $request): ResponseInterface
    {
        $input = new HttpRequest($request);
        return Response::json(['ok' => true, 'message' => 'Marwa Router is alive', 'request' => $input->all()]);
    }
}
