<?php

declare(strict_types=1);

namespace Examples\Controllers;

use Laminas\Diactoros\ServerRequest;
use Marwa\Router\Attributes\Route;
use Marwa\Router\Http\HttpRequest;
use Marwa\Router\Http\Input;
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
    #[Route('GET', '/about', name: 'about')]
    public function about(ServerRequestInterface $request): ResponseInterface
    {
        Input::setRequest($request);
        if (Input::has('q')) {
            $query = Input::get('q');
            return Response::json(['ok' => true, 'message' => "You searched for: {$query}"]);
        }
        return Response::json(['ok' => true, 'message' => 'This is the about page']);
    }
}
