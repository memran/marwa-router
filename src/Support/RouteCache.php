<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use Marwa\Router\Exceptions\FileNotFoundException;
use Marwa\Router\Exceptions\UncacheableRouteException;

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
 * @phpstan-type CacheableRouteHandler callable-string|class-string|array{0:class-string,1:non-empty-string}
 * @phpstan-type RouteHandler callable|class-string|array{0: object|class-string, 1: non-empty-string}
 * @phpstan-type CompiledRouteDefinition array{
 *   methods:array<int,string>,
 *   path:string,
 *   handler:RouteHandler,
 *   name:?string,
 *   middlewares:array<int,class-string|object>,
 *   domain:?string,
 *   where:?array<string,string>,
 *   throttle:?array{limit:int,per:int,key:string},
 *   controller:?string,
 *   action:?string
 * }
 * @phpstan-import-type CompiledRouteEntry from CompiledRouteTable
 */
final class RouteCache
{
    public function __construct(
        private readonly ?string $signingKey = null,
    ) {}

    /**
     * @param array<int, RouteRegistryEntry> $routes
     */
    public function writeMetadata(string $file, array $routes): void
    {
        $this->ensureDirectory(dirname($file), 'route cache');

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($routes, true) . ";\n";
        if ($this->signingKey !== null) {
            $signature = hash_hmac('sha256', $payload, $this->signingKey);
            $payload = "<?php\n\ndeclare(strict_types=1);\n\n/* SIG:{$signature} */\n\nreturn " . var_export($routes, true) . ";\n";
        }
        if (file_put_contents($file, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write route cache file');
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

        if ($this->signingKey !== null) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                throw new \RuntimeException('Unable to read route cache file');
            }
            if (!preg_match('#/\* SIG:([a-f0-9]{64}) \*/#', $raw, $sigMatch)) {
                throw new \RuntimeException('Route cache file is missing HMAC signature');
            }
            $storedSig = $sigMatch[1];
            $contentWithoutSig = preg_replace('#/\* SIG:[a-f0-9]{64} \*/\n*\n*#', '', $raw, 1) ?? $raw;
            if (!hash_equals($storedSig, hash_hmac('sha256', $contentWithoutSig, $this->signingKey))) {
                throw new \RuntimeException('Route cache file HMAC verification failed');
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'route_cache_');
            if ($tmpFile === false) {
                throw new \RuntimeException('Unable to create temporary file for route cache');
            }
            file_put_contents($tmpFile, $contentWithoutSig, LOCK_EX);
            try {
                $routes = require $tmpFile;
            } finally {
                @unlink($tmpFile);
            }
        } else {
            $routes = require $file;
        }

        if (!is_array($routes)) {
            throw new \UnexpectedValueException('Route cache file must return an array');
        }

        return $routes;
    }

    /**
     * Write the compiled route table: route definitions plus prebuilt
     * FastRoute dispatch data, so loading it skips route registration.
     *
     * @param array<int, CompiledRouteDefinition> $definitions
     * @param callable(string): string $pathMapper converts a route path into the FastRoute pattern
     */
    public function writeCompiled(string $file, array $definitions, callable $pathMapper): void
    {
        $this->ensureDirectory(dirname($file), 'compiled route cache');

        $entries = [];
        foreach ($definitions as $definition) {
            $entries[] = $this->exportCompiledDefinition($definition);
        }

        $table = CompiledRouteTable::build($entries, $pathMapper);
        $rendered = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($table->toArray(), true) . ";\n";
        if ($this->signingKey !== null) {
            $signature = hash_hmac('sha256', $rendered, $this->signingKey);
            $rendered = "/* SIG:{$signature} */\n" . $rendered;
        }
        if (file_put_contents($file, $rendered, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write compiled route cache file');
        }
    }

    /**
     * Read a compiled route cache file.
     *
     * Returns the compiled table payload, or a loader callable for files
     * written by older versions (which re-register every route).
     *
     * @return array<mixed>|callable
     */
    public function readCompiled(string $file): array|callable
    {
        if (!is_file($file)) {
            throw new FileNotFoundException($file);
        }

        if ($this->signingKey !== null) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                throw new \RuntimeException('Unable to read compiled route cache file');
            }
            if (!preg_match('#^/\* SIG:([a-f0-9]{64}) \*/\n#', $raw, $sigMatch)) {
                throw new \RuntimeException('Compiled route cache file is missing HMAC signature');
            }
            $storedSig = $sigMatch[1];
            $contentWithoutSig = substr($raw, strlen($sigMatch[0]));
            if (!hash_equals($storedSig, hash_hmac('sha256', $contentWithoutSig, $this->signingKey))) {
                throw new \RuntimeException('Compiled route cache file HMAC verification failed');
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'route_cache_');
            if ($tmpFile === false) {
                throw new \RuntimeException('Unable to create temporary file for route cache');
            }
            file_put_contents($tmpFile, $contentWithoutSig, LOCK_EX);
            try {
                $loaded = require $tmpFile;
            } finally {
                @unlink($tmpFile);
            }
        } else {
            $loaded = require $file;
        }

        if (is_callable($loaded) || is_array($loaded)) {
            return $loaded;
        }

        throw new \UnexpectedValueException('Compiled route cache file must return an array or a callable');
    }

    private function ensureDirectory(string $directory, string $label): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create %s directory: %s', $label, $directory));
        }
    }

    /**
     * @param CompiledRouteDefinition $definition
     * @return CompiledRouteEntry
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
            'controller' => $definition['controller'],
            'action' => $definition['action'],
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

        if (is_array($handler)) {
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
