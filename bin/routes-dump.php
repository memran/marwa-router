#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Laminas\Diactoros\ResponseFactory;
use League\Route\Strategy\ApplicationStrategy;
use Marwa\Router\RouterFactory;

$factory = new RouterFactory();
$factory->registerFromDirectories([__DIR__ . '/../examples/controllers']); // adjust as needed

$router = $factory->getRouter();
$router->setStrategy(new ApplicationStrategy(new ResponseFactory()));

$rows = $factory->routes();
printf("%-10s %-30s %-30s %s\n", 'METHODS', 'PATH', 'NAME', 'HANDLER');
foreach ($rows as $r) {
    printf(
        "%-10s %-30s %-30s %s::%s\n",
        implode('|', $r['methods']),
        $r['path'] ?: '/',
        $r['name'] ?? '',
        $r['controller'],
        $r['action']
    );
}
