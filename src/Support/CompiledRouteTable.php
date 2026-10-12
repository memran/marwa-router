<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedGenerator;
use FastRoute\Dispatcher as FastRoute;
use FastRoute\RouteParser\Std;

/**
 * Precompiled route definitions plus the FastRoute dispatch data built from
 * them, so a cached router can match requests without re-registering routes.
 *
 * Dispatch data is built once per host: "*" holds routes without a domain,
 * and each domain gets its own table containing its routes plus the
 * domain-less ones (mirroring league/route's host filtering).
 *
 * @internal
 *
 * @phpstan-type CompiledRouteEntry array{
 *   methods:array<int,string>,
 *   path:string,
 *   handler:callable-string|class-string|array{0:class-string,1:non-empty-string},
 *   name:?string,
 *   middlewares:array<int,class-string>,
 *   domain:?string,
 *   where:?array<string,string>,
 *   throttle:?array{limit:int,per:int,key:string},
 *   controller:?string,
 *   action:?string
 * }
 */
final class CompiledRouteTable
{
    public const VERSION = 2;

    private const ANY_HOST = '*';

    /**
     * @param array<int, CompiledRouteEntry> $entries
     * @param array<string, array<mixed>> $dispatchData
     */
    private function __construct(
        private readonly array $entries,
        private readonly array $dispatchData,
        private readonly bool $trailingSlashOptional,
    ) {}

    /**
     * @param array<int, CompiledRouteEntry> $entries
     * @param callable(string): string $pathMapper converts a route path into the FastRoute pattern
     */
    public static function build(array $entries, callable $pathMapper, bool $trailingSlashOptional): self
    {
        $entries = array_values($entries);
        $routes = [];
        $hosts = [];
        foreach ($entries as $id => $entry) {
            $routes[$id] = ['methods' => $entry['methods'], 'pattern' => $pathMapper($entry['path']), 'domain' => $entry['domain']];
            if ($entry['domain'] !== null && $entry['domain'] !== '') {
                $hosts[$entry['domain']] = true;
            }
        }

        $dispatchData = [self::ANY_HOST => self::collectDispatchData($routes, null)];
        foreach (array_keys($hosts) as $host) {
            $dispatchData[$host] = self::collectDispatchData($routes, $host);
        }

        return new self($entries, $dispatchData, $trailingSlashOptional);
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if (($payload['version'] ?? null) !== self::VERSION
            || !is_array($payload['routes'] ?? null)
            || !is_array($payload['dispatch'] ?? null)
            || !isset($payload['dispatch'][self::ANY_HOST])
        ) {
            throw new \UnexpectedValueException('Unsupported compiled route cache format.');
        }

        /** @var array<int, CompiledRouteEntry> $entries */
        $entries = $payload['routes'];
        /** @var array<string, array<mixed>> $dispatchData */
        $dispatchData = $payload['dispatch'];

        // Tables written before this flag existed registered both slash
        // variants, so they need no normalization.
        return new self($entries, $dispatchData, ($payload['trailing_slash_optional'] ?? false) === true);
    }

    /**
     * @return array{version:int, trailing_slash_optional:bool, routes:array<int, CompiledRouteEntry>, dispatch:array<string, array<mixed>>}
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'trailing_slash_optional' => $this->trailingSlashOptional,
            'routes' => $this->entries,
            'dispatch' => $this->dispatchData,
        ];
    }

    /**
     * @return array<int, CompiledRouteEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return CompiledRouteEntry
     */
    public function entry(int $id): array
    {
        return $this->entries[$id] ?? throw new \OutOfBoundsException(sprintf('Unknown compiled route id: %d', $id));
    }

    /**
     * @return array<mixed>
     */
    public function dispatchDataFor(string $host): array
    {
        return $this->dispatchData[$host] ?? $this->dispatchData[self::ANY_HOST];
    }

    public function trailingSlashOptional(): bool
    {
        return $this->trailingSlashOptional;
    }

    public function matches(string $host, string $method, string $path): bool
    {
        $result = (new RouteDispatcher($this->dispatchDataFor($host), $this->trailingSlashOptional))->dispatch($method, $path);

        return $result[0] !== FastRoute::NOT_FOUND;
    }

    /**
     * Build dispatch data for one host, with route ids as handlers.
     *
     * Routes without a domain are included for every host; routes with a
     * domain only for that host (null builds the table for unknown hosts).
     *
     * Variable routes are grouped by their first static path segment, so a
     * request only runs the regexes of routes that can match its first
     * segment. Routes starting with a placeholder ("fallback" routes) are
     * part of every group, keeping registration order between them.
     *
     * When several routes match the same method and path shape, a route
     * restricted to this host wins over a domain-less one, and otherwise the
     * last registered route wins.
     *
     * @param array<int, array{methods:array<int,string>|string, pattern:string, domain:?string}> $routes
     * @return array{static:array<string, array<string, int>>, fallback:array<string, mixed>, segments:array<string, array<string, mixed>>}
     */
    public static function collectDispatchData(array $routes, ?string $host): array
    {
        $parser = new Std();

        /** @var array<string, array{method:string, data:array<int, mixed>, id:int, specific:bool}> $variants */
        $variants = [];
        foreach ($routes as $id => $route) {
            $domain = $route['domain'] ?? '';
            if ($domain !== '' && $domain !== $host) {
                continue;
            }

            $specific = $domain !== '';
            foreach ($parser->parse($route['pattern']) as $routeData) {
                foreach ((array) $route['methods'] as $method) {
                    $key = $method . "\0" . self::shapeKey($routeData);
                    if (($variants[$key]['specific'] ?? false) && !$specific) {
                        continue;
                    }

                    // Re-insert so the winning route keeps its own registration position.
                    unset($variants[$key]);
                    $variants[$key] = ['method' => $method, 'data' => $routeData, 'id' => $id, 'specific' => $specific];
                }
            }
        }

        $static = [];
        $fallback = [];
        $segments = [];
        $position = 0;
        foreach ($variants as $variant) {
            $routeData = $variant['data'];
            if (count($routeData) === 1 && is_string($routeData[0])) {
                $static[$variant['method']][$routeData[0]] = $variant['id'];
                continue;
            }

            $item = [$position++, $variant['method'], $routeData, $variant['id']];
            $segment = self::routeSegment($routeData);
            if ($segment === null) {
                $fallback[] = $item;
            } else {
                $segments[$segment][] = $item;
            }
        }

        $segmentData = [];
        foreach ($segments as $segment => $items) {
            $merged = array_merge($items, $fallback);
            usort($merged, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $segmentData[(string) $segment] = self::variableRouteData($merged);
        }

        return [
            'static' => $static,
            'fallback' => self::variableRouteData($fallback),
            'segments' => $segmentData,
        ];
    }

    /**
     * First path segment of a request path ("users" for "/users/5").
     */
    public static function requestSegment(string $path): string
    {
        $slash = strpos($path, '/', 1);

        return $slash === false ? substr($path, 1) : substr($path, 1, $slash - 1);
    }

    /**
     * Static first segment of a parsed route, or null when the first
     * segment contains a placeholder.
     *
     * @param array<int, mixed> $routeData
     */
    private static function routeSegment(array $routeData): ?string
    {
        $head = $routeData[0] ?? null;
        if (!is_string($head)) {
            return null;
        }

        $slash = strpos($head, '/', 1);
        if ($slash === false || $slash === 1) {
            return null;
        }

        return substr($head, 1, $slash - 1);
    }

    /**
     * Key identifying routes FastRoute would treat as the same route:
     * placeholder names are ignored, their patterns are not.
     *
     * @param array<int, mixed> $routeData
     */
    private static function shapeKey(array $routeData): string
    {
        $key = '';
        foreach ($routeData as $part) {
            $key .= is_string($part) ? "s\0{$part}\0" : "v\0" . (string) $part[1] . "\0";
        }

        return $key;
    }

    /**
     * @param array<int, array{0:int, 1:string, 2:array<int, mixed>, 3:int}> $items
     * @return array<string, mixed>
     */
    private static function variableRouteData(array $items): array
    {
        $generator = new GroupCountBasedGenerator();
        foreach ($items as [, $method, $routeData, $id]) {
            $generator->addRoute($method, $routeData, $id);
        }

        return $generator->getData()[1];
    }
}
