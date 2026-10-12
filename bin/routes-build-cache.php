#!/usr/bin/env php
<?php

declare(strict_types=1);

// Usage:
//   php bin/routes-build-cache.php [--dir=path/to/Controllers ...] [--out=path/to/cache]
//
// Defaults: --dir=examples/Controllers --out=var/cache
// Writes <out>/routes.php (route metadata) and <out>/routes.compiled.php (compiled routes).

require __DIR__ . '/../vendor/autoload.php';

use Marwa\Router\RouterFactory;

$dirs = [];
$outDir = __DIR__ . '/../var/cache';
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dirs[] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}
$dirs = $dirs !== [] ? $dirs : [__DIR__ . '/../examples/Controllers'];

$factory = new RouterFactory();
$factory->registerFromDirectories($dirs, strict: true);

$metadataFile = rtrim($outDir, '/\\') . '/routes.php';
$compiledFile = rtrim($outDir, '/\\') . '/routes.compiled.php';
$factory->cacheRoutesTo($metadataFile);
$factory->compileRoutesTo($compiledFile);

printf("Wrote %s\n", $metadataFile);
printf("Wrote %s (%d routes)\n", $compiledFile, count($factory->routes()));
