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
    /** @var array<int, class-string> */
    public array $middlewares;

    /** @param class-string ...$middlewares */
    public function __construct(string ...$middlewares)
    {
        $this->middlewares = array_values($middlewares);
    }
}
