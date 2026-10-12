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
    /**
     * Signed cache files start with a PHP header that stops parsing, so the
     * data after it is never executed, even if the file is required directly.
     */
    private const SIGNED_PREFIX = "<?php __halt_compiler();\nMARWA-ROUTE-CACHE:1:";

    public function __construct(
        private readonly ?string $signingKey = null,
    ) {}

    /**
     * @param array<int, RouteRegistryEntry> $routes
     */
    public function writeMetadata(string $file, array $routes): void
    {
        $this->ensureDirectory(dirname($file), 'route cache');
        $this->write($file, $this->render('metadata', $routes), 'Unable to write route cache file');
    }

    /**
     * @return array<int, RouteRegistryEntry>
     */
    public function loadMetadata(string $file): array
    {
        $routes = $this->read($file, 'metadata', 'Route cache file');

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
    public function writeCompiled(string $file, array $definitions, callable $pathMapper, bool $trailingSlashOptional): void
    {
        $this->ensureDirectory(dirname($file), 'compiled route cache');

        $entries = [];
        foreach ($definitions as $definition) {
            $entries[] = $this->exportCompiledDefinition($definition);
        }

        $table = CompiledRouteTable::build($entries, $pathMapper, $trailingSlashOptional);
        $this->write($file, $this->render('compiled', $table->toArray()), 'Unable to write compiled route cache file');
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
        $loaded = $this->read($file, 'compiled', 'Compiled route cache file');

        if (is_callable($loaded) || is_array($loaded)) {
            return $loaded;
        }

        throw new \UnexpectedValueException('Compiled route cache file must return an array or a callable');
    }

    /**
     * Unsigned caches are PHP files, so opcache keeps them in shared memory.
     *
     * Signed caches are data, not code: the signature covers the serialized
     * payload, and the exact bytes that were verified are unserialized. No
     * code from the file is ever executed, so there is no window between
     * verifying and loading the file in which it could be swapped.
     *
     * @param array<mixed> $payload
     */
    private function render(string $kind, array $payload): string
    {
        if ($this->signingKey === null) {
            return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
        }

        $data = serialize($payload);

        return self::SIGNED_PREFIX . $this->sign($kind, $data) . "\n" . $data;
    }

    private function read(string $file, string $kind, string $label): mixed
    {
        if (!is_file($file)) {
            throw new FileNotFoundException($file);
        }

        if ($this->signingKey === null) {
            return require $file;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Unable to read %s', lcfirst($label)));
        }

        if (!str_starts_with($raw, self::SIGNED_PREFIX)) {
            return $this->readLegacySigned($raw, $label);
        }

        $offset = strlen(self::SIGNED_PREFIX);
        $signature = substr($raw, $offset, 64);
        if (strlen($signature) !== 64 || !ctype_xdigit($signature) || ($raw[$offset + 64] ?? '') !== "\n") {
            throw new \RuntimeException($label . ' is missing HMAC signature');
        }

        $data = substr($raw, $offset + 65);
        if (!hash_equals($this->sign($kind, $data), $signature)) {
            throw new \RuntimeException($label . ' HMAC verification failed');
        }

        return unserialize($data, ['allowed_classes' => false]);
    }

    /**
     * The kind is part of the signed message, so a signed metadata cache
     * cannot be passed off as a compiled cache or the other way round.
     */
    private function sign(string $kind, string $data): string
    {
        return hash_hmac('sha256', "marwa-route-cache:{$kind}\n" . $data, (string) $this->signingKey);
    }

    /**
     * Signed PHP files written by older versions: the signature comment is
     * removed and the remaining code is verified, then required from a
     * temporary file. Rewrite the cache to switch to the data format.
     */
    private function readLegacySigned(string $raw, string $label): mixed
    {
        if (!preg_match('#/\* SIG:([a-f0-9]{64}) \*/\n+#', $raw, $sigMatch, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException($label . ' is missing HMAC signature');
        }

        $storedSig = $sigMatch[1][0];
        $content = substr_replace($raw, '', $sigMatch[0][1], strlen($sigMatch[0][0]));
        if (!hash_equals($storedSig, hash_hmac('sha256', $content, (string) $this->signingKey))) {
            throw new \RuntimeException($label . ' HMAC verification failed');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'route_cache_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Unable to create temporary file for route cache');
        }
        file_put_contents($tmpFile, $content, LOCK_EX);
        try {
            return require $tmpFile;
        } finally {
            @unlink($tmpFile);
        }
    }

    private function write(string $file, string $contents, string $error): void
    {
        if (file_put_contents($file, $contents, LOCK_EX) === false) {
            throw new \RuntimeException($error);
        }
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
