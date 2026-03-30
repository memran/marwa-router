<?php

declare(strict_types=1);

namespace Marwa\Router\Attributes;

use Attribute;

/**
 * Apply PSR-15 middlewares to the entire Prefix group (class-level only).
 * Middleware order: GroupMiddleware -> UseMiddleware(class) -> UseMiddleware(method) -> Route::$middlewares
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class GroupMiddleware
{
    /** @var array<int, class-string> */
    public array $middlewares;

    /** @param class-string ...$middlewares */
    public function __construct(string ...$middlewares)
    {
        $this->middlewares = array_values($middlewares);
    }
}
