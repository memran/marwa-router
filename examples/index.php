<?php

declare(strict_types=1);

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use League\Route\Strategy\ApplicationStrategy;
use Marwa\Router\RouterFactory;

require __DIR__ . '/../vendor/autoload.php';

$factory = new RouterFactory();

// point to your controllers folder (adjust if needed)
$factory->registerFromDirectories([__DIR__ . '\Controllers']);

$router = $factory->getRouter();

// ✅ set a strategy with a PSR-17 response factory
$strategy = (new ApplicationStrategy(new ResponseFactory()));

$router->setStrategy($strategy);

// Build request and dispatch
$request  = ServerRequestFactory::fromGlobals();
$response = $router->dispatch($request);

// Emit response
(new SapiEmitter())->emit($response);


/*examples/index.php (prod fast path)
$factory = new RouterFactory();
$cache = __DIR__ . '/../var/cache/routes.php';
if (is_file($cache)) {
    $factory->registerFromRegistry(require $cache);
} else {
    $factory->registerFromDirectories([__DIR__ . '/controllers']);
}
*/