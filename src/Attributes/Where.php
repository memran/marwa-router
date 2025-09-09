<?php

declare(strict_types=1);

namespace Marwa\Router\Attributes;

use Attribute;

/**
 * Constrain a path parameter to a regex.
 * Examples:
 *  #[Where('id', '\d+')]
 *  #[Where('slug', '[a-z0-9-]+')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Where
{
    public function __construct(
        public string $param,
        public string $pattern
    ) {}
}
