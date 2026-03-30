#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Marwa\Router\RouterFactory;

$factory = new RouterFactory();
$factory->registerFromDirectories([__DIR__ . '/../examples/Controllers'], strict: true);

$cacheFile = __DIR__ . '/../var/cache/routes.php';
$factory->cacheRoutesTo($cacheFile);

echo "Wrote {$cacheFile}\n";
