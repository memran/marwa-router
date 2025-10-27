<?php

declare(strict_types=1);

namespace Marwa\Router\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Abstraction for plugging any validator (e.g., Marwa\Entity).
 * Return an array of normalized/validated data or throw on failure.
 */
interface ValidatorInterface
{
    /**
     * @param ServerRequestInterface $request
     * @param array<string, mixed>   $rules
     * @return array<string, mixed> Validated data
     * @throws \InvalidArgumentException on validation error
     */
    public function validate(ServerRequestInterface $request, array $rules): array;
}
