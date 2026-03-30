#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Marwa\Router\RouterFactory;

$factory = new RouterFactory();
$factory->registerFromDirectories([__DIR__ . '/../examples/Controllers'], strict: true);

$metadataFile = __DIR__ . '/../var/cache/routes.php';
$compiledFile = __DIR__ . '/../var/cache/routes.compiled.php';
$factory->cacheRoutesTo($metadataFile);
$factory->compileRoutesTo($compiledFile);

echo "Wrote {$metadataFile}\n";
echo "Wrote {$compiledFile}\n";
