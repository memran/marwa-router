<?php

declare(strict_types=1);

namespace Marwa\Router\Fluent;
use \Marwa\Router\RouterFactory;
use Closure;
final class RouteRegistrar
{
    private string $groupPrefix = '';
    private ?string $groupNamePrefix = null;
    /** @var array<class-string|object> */
    private array $groupMiddlewares = [];
    /** @var array<string,string> */
    private array $groupWhere = [];
    private ?string $groupDomain = null;
    /** @var array{limit:int,per:int,key:string}|null */
    private ?array $groupThrottle = null;

    public function __construct(private  RouterFactory $factory) {}

    /**
     * @param array{
     *   prefix?:string,
     *   name?:string,
     *   domain?:string,
     *   where?:array<string,string>,
     *   middleware?:array<int, class-string|object>,
     *   throttle?:array{limit:int,per:int,key:string}
     * } $opts
     */
    public function group(array $opts, Closure $routes): void
    {
        $child = new self($this->factory);

        $child->groupPrefix      = $this->join($this->groupPrefix, (string)($opts['prefix'] ?? ''));
        $child->groupNamePrefix  = $this->joinName($this->groupNamePrefix, $opts['name'] ?? null);
        $child->groupDomain      = $opts['domain'] ?? $this->groupDomain;
        $child->groupWhere       = ($this->groupWhere ?? []) + (array)($opts['where'] ?? []);
        $child->groupMiddlewares = array_merge($this->groupMiddlewares, (array)($opts['middleware'] ?? []));
        $child->groupThrottle    = $opts['throttle'] ?? $this->groupThrottle;

        $routes($child);
    }

    public function get(string $path, callable|array|string $handler): RouteDefinition
    {
        return $this->def(['GET'], $path, $handler);
    }
    public function post(string $path, callable|array|string $handler): RouteDefinition
    {
        return $this->def(['POST'], $path, $handler);
    }
    public function put(string $path, callable|array|string $handler): RouteDefinition
    {
        return $this->def(['PUT'], $path, $handler);
    }
    public function patch(string $path, callable|array|string $handler): RouteDefinition
    {
        return $this->def(['PATCH'], $path, $handler);
    }
    public function delete(string $path, callable|array|string $handler): RouteDefinition
    {
        return $this->def(['DELETE'], $path, $handler);
    }
    public function any(string $path, callable|array|string $handler): RouteDefinition
    {
        return $this->def(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $path, $handler);
    }

    private function def(array $methods, string $path, callable|array|string $handler): RouteDefinition
    {
        $fullPath = $this->join($this->groupPrefix, $path);
        $rd = new RouteDefinition($this->factory, $methods, $fullPath, $handler);

        // hydrate group defaults
        $rd->setNamePrefix($this->groupNamePrefix);
        foreach ($this->groupMiddlewares as $mw) {
            $rd->middleware($mw);
        }
        foreach ($this->groupWhere as $k => $v) {
            $rd->where($k, $v);
        }
        if ($this->groupDomain) {
            $rd->domain($this->groupDomain);
        }
        if ($this->groupThrottle) {
            $rd->throttle($this->groupThrottle['limit'], $this->groupThrottle['per'], $this->groupThrottle['key']);
        }

        return $rd;
    }

    private function join(string $base, string $child): string
    {
        $base  = '/' . ltrim(trim($base), '/');
        $child = ltrim(trim($child), '/');
        if ($base === '/' && $child === '') return '/';
        if ($child === '') return $base;
        return rtrim($base, '/') . '/' . $child;
    }

    private function joinName(?string $a, ?string $b): ?string
    {
        if (!$a && !$b) return null;
        return (string)($a ?? '') . (string)($b ?? '');
    }
}
