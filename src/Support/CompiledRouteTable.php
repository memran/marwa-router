<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedGenerator;
use FastRoute\Dispatcher as FastRoute;
use FastRoute\RouteCollector;
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
     * @param array<string, array<int, mixed>> $dispatchData
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
        $patterns = [];
        $hosts = [];
        foreach ($entries as $id => $entry) {
            $patterns[$id] = $pathMapper($entry['path']);
            if ($entry['domain'] !== null && $entry['domain'] !== '') {
                $hosts[$entry['domain']] = true;
            }
        }

        $dispatchData = [self::ANY_HOST => self::collect($entries, $patterns, null)];
        foreach (array_keys($hosts) as $host) {
            $dispatchData[$host] = self::collect($entries, $patterns, $host);
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
        /** @var array<string, array<int, mixed>> $dispatchData */
        $dispatchData = $payload['dispatch'];

        // Tables written before this flag existed registered both slash
        // variants, so they need no normalization.
        return new self($entries, $dispatchData, ($payload['trailing_slash_optional'] ?? false) === true);
    }

    /**
     * @return array{version:int, trailing_slash_optional:bool, routes:array<int, CompiledRouteEntry>, dispatch:array<string, array<int, mixed>>}
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
     * @return array<int, mixed>
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
     * @param array<int, CompiledRouteEntry> $entries
     * @param array<int, string> $patterns
     * @return array<int, mixed>
     */
    private static function collect(array $entries, array $patterns, ?string $host): array
    {
        $collector = new RouteCollector(new Std(), new GroupCountBasedGenerator());

        foreach ($entries as $id => $entry) {
            $domain = $entry['domain'] ?? '';
            if ($domain !== '' && $domain !== $host) {
                continue;
            }

            $collector->addRoute($entry['methods'], $patterns[$id], $id);
        }

        return $collector->getData();
    }
}
