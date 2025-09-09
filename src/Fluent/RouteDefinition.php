<?php

declare(strict_types=1);

namespace Marwa\Router\Fluent;

final class RouteDefinition
{
    /** @var array<int,string> */
    private array $methods;
    private string $path;
    /** @var callable|array|string */
    private $handler;

    private ?string $name = null;
    private ?string $namePrefix = null;

    /** @var array<class-string|object> */
    private array $middlewares = [];
    /** @var array<string,string> */
    private array $where = [];
    private ?string $domain = null;
    /** @var array{limit:int,per:int,key:string}|null */
    private ?array $throttle = null;

    public function __construct(
        private \Marwa\Router\RouterFactory $factory,
        array|string $methods,
        string $path,
        callable|array|string $handler
    ) {
        $this->methods = (array) $methods;
        $this->path    = $path;
        $this->handler = $handler;
    }

    /** Internal: set group name prefix (e.g., "api.") */
    public function setNamePrefix(?string $prefix): self
    {
        $this->namePrefix = $prefix;
        return $this;
    }

    public function name(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /** @param class-string|object ...$mw */
    public function middleware(...$mw): self
    {
        array_push($this->middlewares, ...$mw);
        return $this;
    }

    public function where(string $param, string $pattern): self
    {
        $this->where[$param] = $pattern;
        return $this;
    }

    public function domain(string $host): self
    {
        $this->domain = $host;
        return $this;
    }

    public function throttle(int $limit, int $perSeconds = 60, string $key = 'ip'): self
    {
        $this->throttle = ['limit' => $limit, 'per' => $perSeconds, 'key' => $key];
        return $this;
    }

    public function register(): \Marwa\Router\RouterFactory
    {
        $finalName = $this->name;
        if ($this->namePrefix && $this->name !== null) {
            $finalName = $this->namePrefix . $this->name;
        }

        return $this->factory->map(
            $this->methods,
            $this->path,
            $this->handler,
            $finalName,
            $this->middlewares,
            $this->domain,
            $this->where,
            $this->throttle
        );
    }
}
