<?php

namespace Marwa\Router\Exceptions;

use Exception;

class FileNotFoundException extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("File or directory not found: {$path}");
    }
}
