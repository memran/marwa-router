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
    /** @param class-string ...$middlewares */
    public array $middlewares;
    public function __construct(string ...$middlewares)
    {
        // Assign the variadic parameter to the class property.
        $this->middlewares = $middlewares;
    }
}
