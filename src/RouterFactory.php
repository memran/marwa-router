<?php

declare(strict_types=1);

namespace Marwa\Router;

use League\Route\Router as LeagueRouter;
use League\Route\RouteGroup;
use League\Route\Strategy\ApplicationStrategy;
use Marwa\Router\Attributes\{
    Prefix,
    Route as RouteAttr,
    UseMiddleware,
    GroupMiddleware,
    Where as WhereAttr,
    Domain as DomainAttr,
    Throttle as ThrottleAttr
};
use Marwa\Router\Exceptions\InvalidRouteDefinitionException;
use Marwa\Router\Middleware\ThrottleMiddleware;
use Marwa\Router\Support\ClassLocator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Laminas\Diactoros\ResponseFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Psr\SimpleCache\CacheInterface;

final class RouterFactory
{
    /** @var array<int, array{methods:array<int,string>,path:string,name:?string,controller:?string,action:?string,domain:?string}> */
    private array $registry = [];

    private LeagueRouter $router;
    private ?ContainerInterface $container;
    private ResponseFactoryInterface $responseFactory;
    private ?CacheInterface $cache;

    /** If true, every route matches with and without a trailing slash. */
    private bool $trailingSlashOptional = true;

    public function __construct(
        ?ContainerInterface $container = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?CacheInterface $cache = null,
        ?LeagueRouter $router = null
    ) {
        $this->container       = $container;
        $this->responseFactory = $responseFactory ?? new ResponseFactory();
        $this->cache           = $cache;
        $this->router          = $router ?? new LeagueRouter();

        $strategy = new ApplicationStrategy($this->responseFactory);
        $this->router->setStrategy($strategy);
    }

    /** Optionally disable/enable optional trailing slash matching. */
    public function setTrailingSlashOptional(bool $on): self
    {
        $this->trailingSlashOptional = $on;
        return $this;
    }

    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        return $this;
    }
    public function setCache(CacheInterface $cache): self
    {
        $this->cache = $cache;
        return $this;
    }

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        return $this->router->dispatch($request);
    }

    public function run(): void
    {
        $request  = \Laminas\Diactoros\ServerRequestFactory::fromGlobals();
        $response = $this->dispatch($request);
        (new SapiEmitter())->emit($response);
    }

    public function routes(): array
    {
        return $this->registry;
    }

    // ---------- Attribute scanning ----------

    /** @param string[] $controllerDirs */
    public function registerFromDirectories(array $controllerDirs, bool $strict = false): self
    {
        $classes = ClassLocator::loadAndCollectClasses(fn() => ClassLocator::requirePhpFiles($controllerDirs, $strict));
        if (empty($classes)) {
            $list = implode(', ', array_map(static fn($p) => rtrim($p, "\\/"), $controllerDirs));
            throw new \RuntimeException("No classes discovered under: {$list}");
        }
        return $this->registerFromClasses($classes);
    }

    /** @param array<class-string> $classNames */
    public function registerFromClasses(array $classNames): self
    {
        foreach ($classNames as $class) {
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || $ref->isInterface()) continue;

            $prefixAttr    = $this->firstAttr($ref, Prefix::class)?->newInstance();
            $ctrlMw        = $this->collectUseMiddlewares($ref);
            $groupMw       = $this->collectGroupMiddlewares($ref);
            $whereCtrl     = $this->collectWhere($ref);
            $domainCtrl    = $this->firstAttr($ref, DomainAttr::class)?->newInstance()->host ?? null;
            $throttleClass = $this->firstAttr($ref, ThrottleAttr::class)?->newInstance();

            $prefixPath = $prefixAttr->path ?? '';
            $namePrefix = $prefixAttr->name ?? null;

            if ($prefixPath) {
                $this->router->group($prefixPath, function (RouteGroup $group) use (
                    $ref,
                    $ctrlMw,
                    $groupMw,
                    $namePrefix,
                    $prefixPath,
                    $whereCtrl,
                    $domainCtrl,
                    $throttleClass
                ) {
                    foreach ($groupMw as $mw) {
                        $group->middleware($this->resolveMiddleware($mw));
                    }
                    $this->registerControllerMethods(
                        $group,
                        $ref,
                        $ctrlMw,
                        $namePrefix,
                        $prefixPath,
                        $whereCtrl,
                        $domainCtrl,
                        $throttleClass
                    );
                });
            } else {
                $this->registerControllerMethods(
                    $this->router,
                    $ref,
                    $ctrlMw,
                    $namePrefix,
                    '',
                    $whereCtrl,
                    $domainCtrl,
                    $throttleClass
                );
            }
        }
        return $this;
    }

    /**
     * @param LeagueRouter|RouteGroup $target
     * @param array<class-string> $classMiddlewares
     * @param array<string,string> $classWhere
     */
    private function registerControllerMethods(
        $target,
        ReflectionClass $controller,
        array $classMiddlewares,
        ?string $namePrefix,
        string $groupPrefix = '',
        array $classWhere = [],
        ?string $classDomain = null,
        ?ThrottleAttr $classThrottle = null
    ): void {
        foreach ($controller->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(RouteAttr::class, ReflectionAttribute::IS_INSTANCEOF);
            if (!$routeAttrs) continue;

            $methodWhere      = $this->collectWhere($method);
            $effectiveWhere   = $classWhere + $methodWhere;
            $methodDomainAttr = $this->firstAttr($method, DomainAttr::class)?->newInstance()->host ?? null;
            $effectiveDomain  = $methodDomainAttr ?? $classDomain;
            $methodThrottle   = $this->firstAttr($method, ThrottleAttr::class)?->newInstance() ?? null;
            $effectiveThrottle = $methodThrottle ?? $classThrottle;

            foreach ($routeAttrs as $attr) {
                /** @var RouteAttr $routeMeta */
                $routeMeta = $attr->newInstance();

                $methods = array_values(array_filter(array_map(
                    static fn(string $m) => strtoupper(trim($m)),
                    is_array($routeMeta->methods) ? $routeMeta->methods : [$routeMeta->methods]
                ), static fn(string $m) => $m !== ''));
                if (!$methods) {
                    throw new InvalidRouteDefinitionException(sprintf(
                        'Invalid Route attribute (no methods) on %s::%s',
                        $controller->getName(),
                        $method->getName()
                    ));
                }

                $child  = ltrim(trim((string)$routeMeta->path), '/'); // '' OK
                $childC = $this->applyWhere($child, $effectiveWhere);

                // Map with optional trailing slash
                $mappedPath = $this->normalizeChild($childC, $target instanceof RouteGroup);

                $handler = [$this->resolveController($controller->getName()), $method->getName()];
                $route   = $target->map($methods, $mappedPath, $handler);

                if ($routeMeta->name) {
                    $route->setName(($namePrefix ?? '') . $routeMeta->name);
                }
                if ($effectiveDomain && \method_exists($route, 'setHost')) {
                    $route->setHost($effectiveDomain);
                }

                foreach ($classMiddlewares as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }
                foreach ($this->collectUseMiddlewares($method) as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }
                foreach ($routeMeta->middlewares as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }

                if ($effectiveThrottle) {
                    if (!$this->cache) {
                        throw new \RuntimeException('Throttle attribute used but no CacheInterface provided to RouterFactory.');
                    }
                    $route->middleware(new ThrottleMiddleware(
                        $this->cache,
                        $effectiveThrottle->limit,
                        $effectiveThrottle->perSeconds,
                        $effectiveThrottle->key
                    ));
                }

                $effectivePath = ($target instanceof RouteGroup)
                    ? $this->joinPath($groupPrefix, $childC)
                    : ($childC === '' ? '/' : '/' . $childC);

                $this->registry[] = [
                    'methods'    => $methods,
                    'path'       => $effectivePath,
                    'name'       => $routeMeta->name ? (($namePrefix ?? '') . $routeMeta->name) : null,
                    'controller' => $controller->getName(),
                    'action'     => $method->getName(),
                    'domain'     => $effectiveDomain,
                ];
            }
        }
    }

    // ---------- Fluent API ----------

    public function fluent(): Fluent\RouteRegistrar
    {
        return new Fluent\RouteRegistrar($this);
    }

    /**
     * Low-level manual map used by the fluent layer.
     *
     * @param array<int,string>|string $methods
     * @param callable|array|string    $handler
     * @param array<class-string|object> $middlewares
     * @param array<string,string>|null $where
     * @param array{limit:int,per:int,key:string}|null $throttle
     */
    public function map(
        array|string $methods,
        string $path,
        callable|array|string $handler,
        ?string $name = null,
        array $middlewares = [],
        ?string $domain = null,
        ?array $where = null,
        ?array $throttle = null
    ): self {
        $finalMethods = array_values(array_filter(
            array_map(static fn(string $m) => strtoupper(trim($m)), (array)$methods),
            static fn(string $m) => $m !== ''
        ));
        if (!$finalMethods) {
            throw new \InvalidArgumentException('map(): at least one HTTP method is required.');
        }

        $child     = ltrim(trim($path), '/');              // '' ok
        $childC    = $this->applyWhere($child, $where ?? []);
        $mapped    = $this->normalizeChild($childC, false); // optional trailing slash

        // handler normalization
        $controllerName = null;
        $actionName = null;
        $callable = $handler;
        if (\is_array($handler) && \is_string($handler[0] ?? null) && \is_string($handler[1] ?? null)) {
            $controllerName = $handler[0];
            $actionName = $handler[1];
            $callable = [$this->resolveController($controllerName), $actionName];
        }

        $route = $this->router->map($finalMethods, $mapped, $callable);
        if ($name) {
            $route->setName($name);
        }
        if ($domain && \method_exists($route, 'setHost')) {
            $route->setHost($domain);
        }
        foreach ($middlewares as $mw) {
            $route->middleware($this->resolveMiddleware($mw));
        }

        if ($throttle) {
            if (!$this->cache) {
                throw new \RuntimeException('map(): throttle provided but CacheInterface not set.');
            }
            $route->middleware(new \Marwa\Router\Middleware\ThrottleMiddleware(
                $this->cache,
                (int)$throttle['limit'],
                (int)$throttle['per'],
                (string)$throttle['key']
            ));
        }

        $this->registry[] = [
            'methods'    => $finalMethods,
            'path'       => ($childC === '' ? '/' : '/' . $childC),
            'name'       => $name,
            'controller' => $controllerName,
            'action'     => $actionName,
            'domain'     => $domain,
        ];

        return $this;
    }

    // ---------- Helpers ----------

    private function resolveController(string $class): object
    {
        if ($this->container && $this->container->has($class)) return $this->container->get($class);
        return new $class();
    }

    /** @param class-string|object $mw */
    private function resolveMiddleware(string|object $mw): object
    {
        if (\is_object($mw)) return $mw;
        if ($this->container && $this->container->has($mw)) return $this->container->get($mw);
        return new $mw();
    }

    private function joinPath(string $base, string $child): string
    {
        $base  = '/' . ltrim(trim($base), '/');
        $child = ltrim(trim($child), '/');
        if ($child === '') return $base === '//' ? '/' : $base;
        return rtrim($base, '/') . '/' . $child;
    }

    /**
     * Turn a child path into a mappable pattern.
     * If $this->trailingSlashOptional is true:
     *   '/foo' becomes '/foo[/]' and group-root '' becomes '[/]' (so '/group' and '/group/' work).
     */
    private function normalizeChild(string $raw, bool $inGroup): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            // Root route
            return $inGroup
                ? ($this->trailingSlashOptional ? '[/]' : '')   // inside group: '' or '[/]'
                : '/';                                          // top-level root: just '/'
        }

        $path = '/' . ltrim($raw, '/');

        if ($this->trailingSlashOptional) {
            $path = rtrim($path, '/');
            return $path . '[/]';
        }
        return $path;
    }

    /** Inject {param:regex} constraints unless already present. */
    private function applyWhere(string $childPath, array $constraints): string
    {
        if ($childPath === '' || empty($constraints)) return $childPath;
        return \preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/', function ($m) use ($constraints) {
            $name = $m[1];
            $has = \str_contains($m[0], ':');
            if ($has || !isset($constraints[$name])) return $m[0];
            return '{' . $name . ':' . $constraints[$name] . '}';
        }, $childPath) ?? $childPath;
    }

    private function firstAttr(ReflectionClass|ReflectionMethod $ref, string $attribute): ?ReflectionAttribute
    {
        $attrs = $ref->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF);
        return $attrs[0] ?? null;
    }

    /** @return class-string[] */
    private function collectUseMiddlewares(ReflectionClass|ReflectionMethod $ref): array
    {
        $mw = [];
        foreach ($ref->getAttributes(UseMiddleware::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $def = $attr->newInstance();
            foreach ($def->middlewares as $class) $mw[] = $class;
        }
        return $mw;
    }

    /** @return class-string[] */
    private function collectGroupMiddlewares(ReflectionClass $ref): array
    {
        $mw = [];
        foreach ($ref->getAttributes(GroupMiddleware::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $def = $attr->newInstance();
            foreach ($def->middlewares as $class) $mw[] = $class;
        }
        return $mw;
    }

    /** @return array<string,string> */
    private function collectWhere(ReflectionClass|ReflectionMethod $ref): array
    {
        $out = [];
        foreach ($ref->getAttributes(WhereAttr::class, ReflectionAttribute::IS_INSTANCEOF) as $a) {
            $w = $a->newInstance();
            $out[$w->param] = $w->pattern;
        }
        return $out;
    }
}
