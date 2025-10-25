<?php

declare(strict_types=1);

namespace Examples\Controllers\Auth;

use Examples\Middleware\TimingMiddleware;
use Marwa\Router\Attributes\{Prefix, Route, UseMiddleware};
use Marwa\Router\Response;

#[Prefix('/auth', name: 'auth.')]

final class AuthController
{

    #[Route('GET', '/', name: 'index')]
    #[UseMiddleware(TimingMiddleware::class)]
    public function index()
    {
        return Response::html("Authorize");
    }

    #[Route('POST', '/validate', name: 'validation')]
    public function show($req, array $args)
    {
        return Response::text('User is Validated');
    }
}
