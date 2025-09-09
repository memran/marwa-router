<?php

declare(strict_types=1);

namespace Marwa\Router\Attributes;

use Attribute;

/**
 * Optional controller-level path prefix and common name prefix.
 * Example:
 *   #[Prefix('/api/users', name: 'users.')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Prefix
{
    public function __construct(
        public string $path = '',
        public ?string $name = null
    ) {}
}
