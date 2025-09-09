<?php

declare(strict_types=1);

namespace Marwa\Router\Attributes;

use Attribute;

/**
 * Bind route(s) to a specific host.
 * Can be placed on class (group-level) or method.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Domain
{
    public function __construct(public string $host) {}
}
