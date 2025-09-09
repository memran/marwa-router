<?php

declare(strict_types=1);

namespace Marwa\Router\Attributes;

use Attribute;

/**
 * Attach one or more PSR-15 middlewares at class or method level.
 * Executed (class-level first, then method-level).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class UseMiddleware
{
    /** @param class-string ...$middlewares */
    public array $middlewares;
    public function __construct(string ...$middlewares)
    {
        // Assign the variadic parameter to the class property.
        $this->middlewares = $middlewares;
    }
}
