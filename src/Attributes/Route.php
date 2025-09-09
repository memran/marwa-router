<?php

declare(strict_types=1);

namespace Marwa\Router\Attributes;

use Attribute;

/**
 * Declare a route on a controller method.
 *
 * Examples:
 *   #[Route('GET', '/users', name: 'users.index')]
 *   #[Route(['GET','POST'], '/users/{id:\d+}', name: 'users.show')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /** @var string[] */
    public array $methods;
    public string $path;
    public ?string $name;
    /** @var array<class-string> */
    public array $middlewares;

    /**
     * @param string|string[] $methods
     * @param string $path
     * @param string|null $name
     * @param array<class-string> $middlewares
     */
    public function __construct(
        string|array $methods,
        string $path,
        ?string $name = null,
        array $middlewares = []
    ) {
        $this->methods     = is_array($methods) ? $methods : [$methods];
        $this->path        = $path;
        $this->name        = $name;
        $this->middlewares = $middlewares;
    }
}
