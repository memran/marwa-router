<?php

declare(strict_types=1);

namespace Marwa\Router\Exceptions;

final class FileNotFoundException extends \RuntimeException
{
    public function __construct(string $path)
    {
        parent::__construct("File or directory not found: {$path}");
    }
}
