<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use Marwa\Router\Exceptions\FileNotFoundException;
use Marwa\Router\Exceptions\UncacheableRouteException;
use Marwa\Router\RouterFactory;

/**
 * Internal helper for route metadata and compiled cache persistence.
 *
 * @phpstan-type RouteRegistryEntry array{
 *   methods:array<int,string>,
 *   path:string,
 *   name:?string,
 *   controller:?string,
 *   action:?string,
 *   domain:?string
 * }
 * @phpstan-type CacheableRouteHandler string|array{0:class-string,1:non-empty-string}
 * @phpstan-type RouteHandler callable|string|array{0: object|class-string, 1: non-empty-string}
 * @phpstan-type CompiledRouteDefinition array{
 *   methods:array<int,string>,
 *   path:string,
 *   handler:RouteHandler,
 *   name:?string,
 *   middlewares:array<int,class-string|object>,
 *   domain:?string,
 *   where:?array<string,string>,
 *   throttle:?array{limit:int,per:int,key:string}
 * }
 * @phpstan-type ExportedCompiledRouteDefinition array{
 *   methods:array<int,string>,
 *   path:string,
 *   handler:CacheableRouteHandler,
 *   name:?string,
 *   middlewares:array<int,class-string>,
 *   domain:?string,
 *   where:?array<string,string>,
 *   throttle:?array{limit:int,per:int,key:string}
 * }
 */
final class RouteCache
{
    /**
     * @param array<int, RouteRegistryEntry> $routes
     */
    public function writeMetadata(string $file, array $routes): void
    {
        $this->ensureDirectory(dirname($file), 'route cache');

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($routes, true) . ";\n";
        if (file_put_contents($file, $payload, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write route cache file: %s', $file));
        }
    }

    /**
     * @return array<int, RouteRegistryEntry>
     */
    public function loadMetadata(string $file): array
    {
        if (!is_file($file)) {
            throw new FileNotFoundException($file);
        }

        $routes = require $file;
        if (!is_array($routes)) {
            throw new \UnexpectedValueException(sprintf('Route cache file must return an array: %s', $file));
        }

        return $routes;
    }

    /**
     * @param array<int, CompiledRouteDefinition> $definitions
     */
    public function writeCompiled(string $file, array $definitions): void
    {
        $this->ensureDirectory(dirname($file), 'compiled route cache');

        $compiled = [];
        foreach ($definitions as $definition) {
            $compiled[] = $this->exportCompiledDefinition($definition);
        }

        $payload = <<<PHP
<?php

declare(strict_types=1);

return static function (\Marwa\Router\RouterFactory \$router): void {
    \$routes = %s;

    foreach (\$routes as \$route) {
        \$router->map(
            \$route['methods'],
            \$route['path'],
            \$route['handler'],
            \$route['name'],
            \$route['middlewares'],
            \$route['domain'],
            \$route['where'],
            \$route['throttle'],
        );
    }
};
PHP;

        $rendered = sprintf($payload, var_export($compiled, true));
        if (file_put_contents($file, $rendered, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write compiled route cache file: %s', $file));
        }
    }

    public function loadCompiled(string $file, RouterFactory $router): bool
    {
        if (!is_file($file)) {
            throw new FileNotFoundException($file);
        }

        $loader = require $file;
        if (!is_callable($loader)) {
            throw new \UnexpectedValueException(sprintf(
                'Compiled route cache file must return a callable: %s',
                $file,
            ));
        }

        $loader($router);

        return true;
    }

    private function ensureDirectory(string $directory, string $label): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create %s directory: %s', $label, $directory));
        }
    }

    /**
     * @param CompiledRouteDefinition $definition
     * @return ExportedCompiledRouteDefinition
     */
    private function exportCompiledDefinition(array $definition): array
    {
        $handler = $this->exportCacheableHandler($definition['handler']);
        /** @var array<int, class-string> $middlewares */
        $middlewares = array_map(
            fn (string|object $middleware): string => $this->exportCacheableMiddleware($middleware),
            $definition['middlewares'],
        );

        return [
            'methods' => $definition['methods'],
            'path' => $definition['path'],
            'handler' => $handler,
            'name' => $definition['name'],
            'middlewares' => $middlewares,
            'domain' => $definition['domain'],
            'where' => $definition['where'],
            'throttle' => $definition['throttle'],
        ];
    }

    /**
     * @param RouteHandler $handler
     * @return CacheableRouteHandler
     */
    private function exportCacheableHandler(callable|array|string $handler): string|array
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null) && $handler[1] !== '') {
            /** @var class-string $class */
            $class = $handler[0];

            return [$class, $handler[1]];
        }

        throw new UncacheableRouteException(
            'Compiled route cache only supports string handlers and [class-string, method] handlers.',
        );
    }

    private function exportCacheableMiddleware(string|object $middleware): string
    {
        if (is_string($middleware)) {
            return $middleware;
        }

        throw new UncacheableRouteException(
            'Compiled route cache only supports middleware defined as class strings.',
        );
    }
}
