<?php

declare(strict_types=1);

namespace Marwa\Router\Benchmarks;

use Marwa\Router\Attributes\Route;
use Marwa\Router\Response;

final class BenchPostController
{
    #[Route('GET', '/posts/{id}', name: 'posts.show')]
    public function show(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }

    #[Route('GET', '/posts', name: 'posts.index')]
    public function index(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }

    #[Route('POST', '/posts', name: 'posts.create')]
    public function create(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }

    #[Route('GET', '/comments/{id}', name: 'comments.show')]
    public function commentsShow(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }

    #[Route('GET', '/comments', name: 'comments.index')]
    public function commentsIndex(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }

    #[Route('GET', '/tags/{id}', name: 'tags.show')]
    public function tagsShow(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }

    #[Route('GET', '/tags', name: 'tags.index')]
    public function tagsIndex(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }
}
