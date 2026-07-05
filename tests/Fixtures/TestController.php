<?php

declare(strict_types=1);

namespace Marwa\Router\Tests\Fixtures;

use Marwa\Router\Attributes\Route;
use Marwa\Router\Response;

final class TestController
{
    #[Route('GET', '/test', name: 'test')]
    public function test(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('ok');
    }
}
