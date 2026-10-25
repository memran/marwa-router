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
 *   middlewares:array<int,string>,
 *   domain:?string,
 *   where:?array<string,string>,
 *   throttle:?array{limit:int,per:int,key:string},
 *   controller:?string,
 *   action:?string
 * }
 * @phpstan-type RouteTreeNode array{data:array<string, mixed>|null, children:array<string, mixed>}
 */
final class CompiledRouteTable
{
    public const VERSION = 2;

    private const ANY_HOST = '*';

    /** Route tree nodes with at most this many variable routes are not split further. */
    private const MAX_UNSPLIT_ROUTES = 32;

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
     * Variable routes are placed in a tree keyed by their leading static
     * path segments ("/api/v1/users/{id}" under api > v1 > users). A request
     * walks the tree as deep as its own segments go and only runs the
     * regexes stored at the node it reaches. Each node holds its own routes
     * plus those of its ancestors (routes with a placeholder at a shallower
     * depth, e.g. "/{lang}/about"), in registration order.
     *
     * When several routes match the same method and path shape, a route
     * restricted to this host wins over a domain-less one, and otherwise the
     * last registered route wins.
     *
     * @param array<int, array{methods:array<int,string>|string, pattern:string, domain:?string}> $routes
     * @return array{static:array<string, array<string, int>>, tree:RouteTreeNode}
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
        $root = ['routes' => [], 'children' => []];
        $position = 0;
        foreach ($variants as $variant) {
            $routeData = $variant['data'];
            if (count($routeData) === 1 && is_string($routeData[0])) {
                $static[$variant['method']][$routeData[0]] = $variant['id'];
                continue;
            }

            self::insert($root, self::staticSegments($routeData), [$position++, $variant['method'], $routeData, $variant['id']]);
        }

        return ['static' => $static, 'tree' => self::buildTree($root, [], true)];
    }

    /**
     * @param array{routes:list<array{0:int, 1:string, 2:array<int, mixed>, 3:int}>, children:array<string, mixed>} $node
     * @param list<string> $segments
     * @param array{0:int, 1:string, 2:array<int, mixed>, 3:int} $item
     */
    private static function insert(array &$node, array $segments, array $item): void
    {
        if ($segments === []) {
            $node['routes'][] = $item;

            return;
        }

        $segment = array_shift($segments);
        /** @var array{routes:list<array{0:int, 1:string, 2:array<int, mixed>, 3:int}>, children:array<string, mixed>} $child */
        $child = $node['children'][$segment] ?? ['routes' => [], 'children' => []];
        // Release the parent's copy so modifying the child does not copy it.
        $node['children'][$segment] = null;
        self::insert($child, $segments, $item);
        $node['children'][$segment] = $child;
    }

    /**
     * @param array{routes:list<array{0:int, 1:string, 2:array<int, mixed>, 3:int}>, children:array<string, mixed>} $node
     * @param list<array{0:int, 1:string, 2:array<int, mixed>, 3:int}> $inherited routes of the ancestors, in registration order
     * @return RouteTreeNode
     */
    private static function buildTree(array $node, array $inherited, bool $isRoot): array
    {
        // Small subtrees are not worth splitting: FastRoute already batches
        // about ten routes per regex, and every node adds its own data.
        if ($node['children'] !== [] && self::countRoutes($node) <= self::MAX_UNSPLIT_ROUTES) {
            $node = ['routes' => self::subtreeRoutes($node), 'children' => []];
        }

        $data = null;
        if ($node['routes'] !== [] || $isRoot) {
            $inherited = array_merge($inherited, $node['routes']);
            usort($inherited, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $data = self::variableRouteData($inherited);
        }

        $children = [];
        foreach ($node['children'] as $segment => $child) {
            /** @var array{routes:list<array{0:int, 1:string, 2:array<int, mixed>, 3:int}>, children:array<string, mixed>} $child */
            $children[(string) $segment] = self::buildTree($child, $inherited, false);
        }

        // Nodes without routes of their own reuse the nearest ancestor's data.
        return ['data' => $data, 'children' => $children];
    }

    /**
     * @param array{routes:list<mixed>, children:array<string, mixed>} $node
     */
    private static function countRoutes(array $node): int
    {
        $count = count($node['routes']);
        foreach ($node['children'] as $child) {
            /** @var array{routes:list<mixed>, children:array<string, mixed>} $child */
            $count += self::countRoutes($child);
        }

        return $count;
    }

    /**
     * @param array{routes:list<array{0:int, 1:string, 2:array<int, mixed>, 3:int}>, children:array<string, mixed>} $node
     * @return list<array{0:int, 1:string, 2:array<int, mixed>, 3:int}>
     */
    private static function subtreeRoutes(array $node): array
    {
        $routes = $node['routes'];
        foreach ($node['children'] as $child) {
            /** @var array{routes:list<array{0:int, 1:string, 2:array<int, mixed>, 3:int}>, children:array<string, mixed>} $child */
            array_push($routes, ...self::subtreeRoutes($child));
        }
        usort($routes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $routes;
    }

    /**
     * Variable route data for a request path: the data of the deepest tree
     * node matching the path's leading segments.
     *
     * @param RouteTreeNode $tree
     * @return array<string, mixed>
     */
    public static function variableRouteDataFor(array $tree, string $path): array
    {
        $node = $tree;
        $data = $tree['data'] ?? [];
        foreach (explode('/', substr($path, 1)) as $segment) {
            $node = $node['children'][$segment] ?? null;
            if ($node === null) {
                break;
            }
            $data = $node['data'] ?? $data;
        }

        return $data;
    }

    /**
     * First path segment of a request path ("users" for "/users/5"); used
     * with segment-grouped data from caches written by earlier versions.
     */
    public static function requestSegment(string $path): string
    {
        $slash = strpos($path, '/', 1);

        return $slash === false ? substr($path, 1) : substr($path, 1, $slash - 1);
    }

    /**
     * Complete static segments a parsed route starts with: those followed
     * by a "/" before the first placeholder ("/api/v1/{x}" gives api, v1).
     *
     * @param array<int, mixed> $routeData
     * @return list<string>
     */
    private static function staticSegments(array $routeData): array
    {
        $head = $routeData[0] ?? null;
        if (!is_string($head)) {
            return [];
        }

        $segments = explode('/', substr($head, 1));
        array_pop($segments);

        return $segments;
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
