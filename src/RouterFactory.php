<?php

declare(strict_types=1);

namespace Marwa\Router;

use Laminas\Diactoros\ResponseFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use League\Route\Http\Exception\NotFoundException;
use League\Route\Route as LeagueRoute;
use League\Route\Router as LeagueRouter;
use League\Route\Strategy\ApplicationStrategy;
use League\Route\Strategy\OptionsHandlerInterface;
use Marwa\Router\Attributes\{
    Domain as DomainAttr,
    GroupMiddleware,
    Prefix,
    Route as RouteAttr,
    Throttle as ThrottleAttr,
    UseMiddleware,
    Where as WhereAttr
};
use Marwa\Router\Exceptions\InvalidNotFoundHandlerResponseException;
use Marwa\Router\Exceptions\InvalidRouteDefinitionException;
use Marwa\Router\Exceptions\RouteConflictException;
use Marwa\Router\Exceptions\RouteNotFoundException;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Middleware\ThrottleMiddleware;
use Marwa\Router\Strategy\SmartApplicationStrategy;
use Marwa\Router\Support\ClassLocator;
use Marwa\Router\Support\CompiledRouteTable;
use Marwa\Router\Support\LazyMiddleware;
use Marwa\Router\Support\RouteCache;
use Marwa\Router\Support\RouteDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * @phpstan-import-type CompiledRouteEntry from CompiledRouteTable
 */
final class RouterFactory
{
    /** @var array<int, array{methods:array<int,string>,path:string,name:?string,controller:?string,action:?string,domain:?string}> */
    private array $registry = [];

    private LeagueRouter $router;
    private ?SmartApplicationStrategy $strategy = null;
    private ?ContainerInterface $container;
    private ?CacheInterface $cache;
    private ?LoggerInterface $logger = null;
    /** @var array<int, array{methods:array<int,string>,path:string,handler:callable|class-string|array{0: object|class-string, 1: non-empty-string},name:?string,middlewares:array<int,class-string|object>,domain:?string,where:?array<string,string>,throttle:?array{limit:int,per:int,key:string},controller:?string,action:?string}> */
    private array $compiledDefinitions = [];
    private RouteCache $routeCache;

    /** Routes loaded from a compiled cache; dispatched without registering them in league/route. */
    private ?CompiledRouteTable $compiledTable = null;

    /** @var array<int, LeagueRoute> compiled route id => route built on first match */
    private array $compiledRoutes = [];

    /** Whether the league router may hold routes (mapped here or before injection). */
    private bool $hasDynamicRoutes;

    /** An injected router may hold routes or groups this factory does not know about. */
    private bool $routerInjected;

    /** @var array<int, LeagueRoute> routes mapped by this factory, by dispatch id */
    private array $registeredRoutes = [];

    /** @var array<int, string> dispatch id => domain, for routes restricted to a domain */
    private array $registeredRouteDomains = [];

    /** @var array<string, true> domains used by registered routes */
    private array $registeredDomains = [];

    /** @var array<string, array<mixed>> host ("*" for any other host) => dispatch data */
    private array $registeredDispatchData = [];

    /** Whether compiled routes still need to be added to the conflict-detection index. */
    private bool $compiledConflictKeysPending = false;

    /** @var array<class-string, LazyMiddleware> one shared proxy per middleware class */
    private array $lazyMiddlewares = [];

    /** @var array<string, true> */
    private array $seenRouteKeys = [];

    /** @var array<string, true> */
    private array $seenNames = [];

    private bool $detectConflicts = true;
    private bool $trailingSlashOptional = true;

    /** @var null|callable */
    private $notFoundHandler = null;

    public function __construct(
        ?ContainerInterface $container = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?CacheInterface $cache = null,
        ?LeagueRouter $router = null,
        ?RouteCache $routeCache = null,
    ) {
        $responseFactory ??= new ResponseFactory();
        $this->cache = $cache;
        $this->router = $router ?? new LeagueRouter();
        $this->hasDynamicRoutes = $router !== null;
        $this->routerInjected = $router !== null;
        $this->container = $container;
        $this->routeCache = $routeCache ?? new RouteCache();

        if ($container !== null) {
            $this->setContainer($container);
        }
    }

    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        $this->strategy = new SmartApplicationStrategy();
        $this->strategy->setContainer($container);
        $this->router->setStrategy($this->strategy);

        return $this;
    }

    /**
     * When enabled (the default), "/users/" matches the "/users" route.
     * Call this before registering routes.
     */
    public function setTrailingSlashOptional(bool $on): self
    {
        $this->trailingSlashOptional = $on;

        return $this;
    }

    public function setCache(CacheInterface $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function cacheRoutesTo(string $file): void
    {
        $this->routeCache->writeMetadata($file, $this->routes());
    }

    public function loadRoutesFrom(string $file): bool
    {
        $this->registry = $this->routeCache->loadMetadata($file);

        return true;
    }

    public function compileRoutesTo(string $file): void
    {
        $definitions = $this->compiledTable === null
            ? $this->compiledDefinitions
            : array_merge($this->compiledTable->entries(), $this->compiledDefinitions);

        $this->routeCache->writeCompiled(
            $file,
            $definitions,
            fn (string $path): string => $this->toFastRoutePattern($this->toMappable($path)),
            $this->trailingSlashOptional,
        );
    }

    /**
     * Load routes written by compileRoutesTo().
     *
     * The compiled table already contains FastRoute dispatch data, so routes
     * are not re-registered: only the route that matches a request is built.
     */
    public function loadCompiledRoutesFrom(string $file): bool
    {
        $payload = $this->routeCache->readCompiled($file);

        // Cache files written by older versions return a loader closure.
        if (is_callable($payload)) {
            $payload($this);

            return true;
        }

        $table = CompiledRouteTable::fromArray($payload);

        // OPTIONS routes are generated by league/route while preparing
        // registered routes, and a second table cannot be merged into the
        // first; both cases need regular registration.
        if ($this->compiledTable !== null || $this->router->getStrategy() instanceof OptionsHandlerInterface) {
            foreach ($table->entries() as $entry) {
                $this->map(
                    $entry['methods'],
                    $entry['path'],
                    $entry['handler'],
                    $entry['name'],
                    $entry['middlewares'],
                    $entry['domain'],
                    $entry['where'],
                    $entry['throttle'],
                );
            }

            return true;
        }

        $this->compiledTable = $table;
        $this->compiledRoutes = [];
        $this->compiledConflictKeysPending = true;
        foreach ($table->entries() as $entry) {
            $this->registry[] = [
                'methods' => $entry['methods'],
                'path' => $entry['path'],
                'name' => $entry['name'],
                'controller' => $entry['controller'],
                'action' => $entry['action'],
                'domain' => $entry['domain'],
            ];
        }

        return true;
    }

    public function enableConflictDetection(bool $on = true): self
    {
        $this->detectConflicts = $on;

        return $this;
    }

    public function setNotFoundHandler(callable $handler): self
    {
        $this->notFoundHandler = $handler;

        return $this;
    }

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->compiledTable !== null && $this->shouldDispatchCompiled($request, $this->compiledTable)) {
            return $this->dispatchCompiled($request, $this->compiledTable);
        }

        return $this->dispatchRegistered($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->dispatch($request);
        } catch (NotFoundException $e) {
            if ($this->notFoundHandler === null) {
                $this->logger?->notice('Route not found.', [
                    'path' => $request->getUri()->getPath(),
                    'method' => $request->getMethod(),
                    'host' => $request->getUri()->getHost(),
                ]);
                throw new RouteNotFoundException('Route not found.', previous: $e);
            }

            try {
                return $this->normalizeHandlerResponse(($this->notFoundHandler)($request));
            } catch (InvalidNotFoundHandlerResponseException $handlerException) {
                $this->logger?->error('Invalid not-found handler response.', [
                    'path' => $request->getUri()->getPath(),
                    'method' => $request->getMethod(),
                    'host' => $request->getUri()->getHost(),
                ]);

                throw $handlerException;
            }
        }
    }

    public function run(): void
    {
        $request = RequestFactory::fromGlobals();
        $response = $this->handle($request);

        (new SapiEmitter())->emit($response);
    }

    /**
     * @return array<int, array{methods:array<int,string>,path:string,name:?string,controller:?string,action:?string,domain:?string}>
     */
    public function routes(): array
    {
        return $this->registry;
    }

    /**
     * @param string[] $controllerDirs
     */
    public function registerFromDirectories(array $controllerDirs, bool $strict = false): self
    {
        $classes = ClassLocator::loadAndCollectClasses(
            fn (): array => ClassLocator::requirePhpFiles($controllerDirs, $strict),
            $controllerDirs,
        );

        if ($classes === []) {
            return $this;
        }

        return $this->registerFromClasses($classes);
    }

    /**
     * @param array<class-string> $classNames
     */
    public function registerFromClasses(array $classNames): self
    {
        foreach ($classNames as $class) {
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || $ref->isInterface()) {
                continue;
            }

            /** @var Prefix|null $prefixAttr */
            $prefixAttr = $this->firstAttr($ref, Prefix::class)?->newInstance();
            $prefixPath = $prefixAttr !== null ? $this->normalizePrefix($prefixAttr->path) : '';
            $namePrefix = $prefixAttr !== null ? $prefixAttr->name : null;
            $controllerMiddlewares = array_merge(
                $this->collectGroupMiddlewares($ref),
                $this->collectUseMiddlewares($ref),
            );
            $where = $this->collectWhere($ref);
            $domain = $this->firstAttr($ref, DomainAttr::class)?->newInstance()->host ?? null;
            /** @var ThrottleAttr|null $throttle */
            $throttle = $this->firstAttr($ref, ThrottleAttr::class)?->newInstance();

            $this->registerControllerMethods(
                $this->router,
                $ref,
                $controllerMiddlewares,
                $namePrefix,
                $prefixPath,
                $where,
                $domain,
                $throttle,
            );
        }

        return $this;
    }

    /**
     * @param ReflectionClass<object> $controller
     * @param array<class-string> $classMiddlewares
     * @param array<string, string> $classWhere
     */
    private function registerControllerMethods(
        LeagueRouter $target,
        ReflectionClass $controller,
        array $classMiddlewares,
        ?string $namePrefix,
        string $groupPrefix = '',
        array $classWhere = [],
        ?string $classDomain = null,
        ?ThrottleAttr $classThrottle = null,
    ): void {
        foreach ($controller->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(RouteAttr::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($routeAttrs === []) {
                continue;
            }

            $methodWhere = $this->collectWhere($method);
            $effectiveWhere = array_merge($classWhere, $methodWhere);
            $methodDomain = $this->firstAttr($method, DomainAttr::class)?->newInstance()->host ?? null;
            $effectiveDomain = $methodDomain ?? $classDomain;
            /** @var ThrottleAttr|null $methodThrottle */
            $methodThrottle = $this->firstAttr($method, ThrottleAttr::class)?->newInstance();
            $effectiveThrottle = $methodThrottle ?? $classThrottle;
            $handler = [$this->assertControllerExists($controller->getName()), $method->getName()];
            $methodMiddlewares = $this->collectUseMiddlewares($method);

            foreach ($routeAttrs as $attr) {
                /** @var RouteAttr $routeMeta */
                $routeMeta = $attr->newInstance();
                $methods = [];
                foreach ($routeMeta->methods as $item) {
                    $m = strtoupper(trim($item));
                    if ($m !== '') {
                        $methods[] = $m;
                    }
                }

                if ($methods === []) {
                    throw new InvalidRouteDefinitionException(sprintf(
                        'Invalid Route attribute (no methods) on %s::%s',
                        $controller->getName(),
                        $method->getName(),
                    ));
                }

                $routeName = $routeMeta->name !== null ? ($namePrefix ?? '') . $routeMeta->name : null;
                $prettyPath = $this->joinPath(
                    $groupPrefix,
                    $this->applyWhere(ltrim((string) $routeMeta->path, '/'), $effectiveWhere),
                );
                $mappedPath = $this->toMappable($prettyPath);

                $this->assertUniqueRoute($methods, $prettyPath, $effectiveDomain);
                $this->assertUniqueName($routeName);

                $middlewares = array_merge(
                    $classMiddlewares,
                    $methodMiddlewares,
                    $routeMeta->middlewares,
                );

                $this->configureMappedRoutes(
                    $this->mapRoutes($target, $methods, $mappedPath, $handler, $effectiveDomain),
                    $routeName,
                    $middlewares,
                    $effectiveDomain,
                    $effectiveThrottle,
                    sprintf('%s::%s', $controller->getName(), $method->getName()),
                );

                $this->registry[] = [
                    'methods' => $methods,
                    'path' => $prettyPath,
                    'name' => $routeName,
                    'controller' => $controller->getName(),
                    'action' => $method->getName(),
                    'domain' => $effectiveDomain,
                ];
                $this->compiledDefinitions[] = [
                    'methods' => $methods,
                    'path' => $prettyPath,
                    'handler' => [$controller->getName(), $method->getName()],
                    'name' => $routeName,
                    'middlewares' => $middlewares,
                    'domain' => $effectiveDomain,
                    'where' => null,
                    'throttle' => $effectiveThrottle === null
                        ? null
                        : [
                            'limit' => $effectiveThrottle->limit,
                            'per' => $effectiveThrottle->perSeconds,
                            'key' => $effectiveThrottle->key,
                        ],
                    'controller' => $controller->getName(),
                    'action' => $method->getName(),
                ];
            }
        }
    }

    /**
     * @param bool $autoRegister when false, definitions created by the
     *                           registrar are only registered via an
     *                           explicit RouteDefinition::register() call
     *                           instead of automatically on destruction
     */
    public function fluent(bool $autoRegister = true): Fluent\RouteRegistrar
    {
        return (new Fluent\RouteRegistrar($this))->setAutoRegister($autoRegister);
    }

    /**
     * @param array<int, string>|string $methods
     * @param callable|class-string|array{0: object|class-string, 1: non-empty-string} $handler
     * @param array<class-string|object> $middlewares
     * @param array<string, string>|null $where
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
        ?array $throttle = null,
    ): self {
        $finalMethods = [];
        foreach ((array) $methods as $item) {
            $m = strtoupper(trim($item));
            if ($m !== '') {
                $finalMethods[] = $m;
            }
        }

        if ($finalMethods === []) {
            throw new \InvalidArgumentException('map(): at least one HTTP method is required.');
        }

        $child = ltrim(trim($path), '/');
        $prettyPath = $this->joinPath('', $this->applyWhere($child, $where ?? []));
        $mappedPath = $this->toMappable($prettyPath);

        $controllerName = null;
        $actionName = null;
        $callable = $handler;
        if (is_array($handler) && is_string($handler[0])) {
            $controllerName = $handler[0];
            $actionName = $handler[1];
            $callable = [$this->assertControllerExists($handler[0]), $handler[1]];
        }

        $this->assertUniqueRoute($finalMethods, $prettyPath, $domain);
        $this->assertUniqueName($name);

        $throttleConfig = null;
        if ($throttle !== null) {
            $this->assertValidThrottle((int) $throttle['limit'], (int) $throttle['per'], 'fluent route');
            $throttleConfig = $throttle;
        }

        $this->configureMappedRoutes(
            $this->mapRoutes($this->router, $finalMethods, $mappedPath, $callable, $domain),
            $name,
            $middlewares,
            $domain,
            $throttleConfig === null
                ? null
                : new ThrottleAttr((int) $throttleConfig['limit'], (int) $throttleConfig['per'], (string) $throttleConfig['key']),
            'fluent route',
        );

        $this->registry[] = [
            'methods' => $finalMethods,
            'path' => $prettyPath,
            'name' => $name,
            'controller' => $controllerName,
            'action' => $actionName,
            'domain' => $domain,
        ];
        $this->compiledDefinitions[] = [
            'methods' => $finalMethods,
            'path' => $prettyPath,
            'handler' => $this->normalizeCompiledHandler($handler),
            'name' => $name,
            'middlewares' => $middlewares,
            'domain' => $domain,
            'where' => null,
            'throttle' => $throttleConfig,
            'controller' => $controllerName,
            'action' => $actionName,
        ];

        return $this;
    }

    /**
     * Controllers are resolved by the strategy when a route is dispatched,
     * so registration only validates that the class exists.
     *
     * @return class-string
     */
    private function assertControllerExists(string $class): string
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Controller class does not exist: %s', $class));
        }

        return $class;
    }

    /**
     * @param class-string|object $middleware
     */
    private function resolveMiddleware(string|object $middleware): object
    {
        if (is_object($middleware)) {
            return $middleware;
        }

        if (!class_exists($middleware)) {
            throw new \InvalidArgumentException(sprintf('Middleware class does not exist: %s', $middleware));
        }

        return $this->lazyMiddlewares[$middleware] ??= new LazyMiddleware(
            $middleware,
            fn (): object => $this->container !== null && $this->container->has($middleware)
                ? $this->container->get($middleware)
                : new $middleware(),
        );
    }

    private function shouldDispatchCompiled(ServerRequestInterface $request, CompiledRouteTable $table): bool
    {
        if (!$this->hasDynamicRoutes) {
            return true;
        }

        // Routes were also mapped at runtime: the compiled table only
        // handles requests it can match, the rest use the registered routes.
        return $table->matches(
            $request->getUri()->getHost(),
            $request->getMethod(),
            $request->getUri()->getPath(),
        );
    }

    private function dispatchCompiled(ServerRequestInterface $request, CompiledRouteTable $table): ResponseInterface
    {
        return $this->dispatchWith(new RouteDispatcher(
            $table->dispatchDataFor($request->getUri()->getHost()),
            $table->trailingSlashOptional(),
            fn (int $id): LeagueRoute => $this->compiledRoutes[$id] ??= $this->buildCompiledRoute($table->entry($id)),
        ), $request);
    }

    /**
     * Dispatch routes registered on the league router.
     *
     * league/route prepares its dispatch data once, keeping only the routes
     * whose domain matches the first request, and ignores routes added
     * afterwards. Dispatch data is built here per host instead and rebuilt
     * when routes are added, so every domain works in long-running workers.
     */
    private function dispatchRegistered(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->routerInjected || $this->router->getStrategy() instanceof OptionsHandlerInterface) {
            return $this->dispatchPreparedByLeague($request);
        }

        $host = $request->getUri()->getHost();
        $key = isset($this->registeredDomains[$host]) ? $host : '*';
        $data = $this->registeredDispatchData[$key] ??= $this->collectRegisteredDispatchData($key === '*' ? null : $host);

        return $this->dispatchWith(new RouteDispatcher(
            $data,
            $this->trailingSlashOptional,
            fn (int $id): LeagueRoute => $this->registeredRoutes[$id],
        ), $request);
    }

    /**
     * @return array<mixed>
     */
    private function collectRegisteredDispatchData(?string $host): array
    {
        $routes = [];
        foreach ($this->registeredRoutes as $id => $route) {
            $routes[$id] = [
                'methods' => $route->getMethod(),
                'pattern' => $this->toFastRoutePattern($route->getPath()),
                'domain' => $this->registeredRouteDomains[$id] ?? null,
            ];
        }

        return CompiledRouteTable::collectDispatchData($routes, $host);
    }

    /**
     * Same steps as League\Route\Router::dispatch(), for routers holding
     * routes this factory did not map (injected routers) and for strategies
     * that need league/route's generated OPTIONS routes.
     */
    private function dispatchPreparedByLeague(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<int, mixed> $data */
        $data = (function (ServerRequestInterface $request): array {
            if (!$this->routesPrepared) {
                $this->prepareRoutes($request);
            }

            return $this->routesData;
        })->call($this->router, $request);

        return $this->dispatchWith(new RouteDispatcher($data, $this->trailingSlashOptional), $request);
    }

    private function dispatchWith(RouteDispatcher $dispatcher, ServerRequestInterface $request): ResponseInterface
    {
        $strategy = $this->router->getStrategy();
        if ($strategy === null) {
            $strategy = new ApplicationStrategy();
            $this->router->setStrategy($strategy);
        }

        $dispatcher->setStrategy($strategy);

        foreach ($this->router->getMiddlewareStack() as $middleware) {
            if (is_string($middleware)) {
                $dispatcher->lazyMiddleware($middleware);
                continue;
            }

            $dispatcher->middleware($middleware);
        }

        return $dispatcher->dispatchRequest($request);
    }

    /**
     * @param CompiledRouteEntry $entry
     */
    private function buildCompiledRoute(array $entry): LeagueRoute
    {
        $route = new LeagueRoute($entry['methods'], $entry['path'], $entry['handler']);
        $this->configureMappedRoutes(
            [$route],
            $entry['name'],
            $entry['middlewares'],
            $entry['domain'],
            $entry['throttle'] === null
                ? null
                : new ThrottleAttr($entry['throttle']['limit'], $entry['throttle']['per'], $entry['throttle']['key']),
            'compiled route',
        );

        return $route;
    }

    /**
     * Apply league/route's placeholder aliases ({id:number}, {slug:slug}, ...)
     * the same way it does when preparing registered routes.
     */
    private function toFastRoutePattern(string $path): string
    {
        /** @var array<string, string> $matchers */
        $matchers = (fn (): array => $this->patternMatchers)->call($this->router);

        return preg_replace(array_keys($matchers), array_values($matchers), $path) ?? $path;
    }

    /**
     * Add compiled routes to the conflict-detection index the first time a
     * route is registered on top of a loaded compiled table.
     */
    private function seedCompiledConflictKeys(): void
    {
        if (!$this->compiledConflictKeysPending || $this->compiledTable === null) {
            return;
        }

        $this->compiledConflictKeysPending = false;
        foreach ($this->compiledTable->entries() as $entry) {
            $this->assertUniqueRoute($entry['methods'], $entry['path'], $entry['domain']);
            $this->assertUniqueName($entry['name']);
        }
    }

    private function joinPath(string $base, string $child): string
    {
        $base = '/' . ltrim(trim($base), '/');
        $base = rtrim($base, '/');
        $child = ltrim(trim($child), '/');

        if ($child === '') {
            return $base === '' ? '/' : $base;
        }

        return $base === '' ? '/' . $child : $base . '/' . $child;
    }

    private function toMappable(string $pretty): string
    {
        if ($pretty === '' || $pretty === '/') {
            return '/';
        }

        $pretty = '/' . ltrim($pretty, '/');

        // The optional trailing slash is handled when matching (see
        // RouteDispatcher), so routes are registered without it.
        return $this->trailingSlashOptional ? rtrim($pretty, '/') : $pretty;
    }

    /**
     * @param array<string, string> $constraints
     */
    private function applyWhere(string $childPath, array $constraints): string
    {
        if ($childPath === '' || $constraints === []) {
            return $childPath;
        }

        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
            static function (array $matches) use ($constraints): string {
                $name = $matches[1];
                if (str_contains($matches[0], ':') || !isset($constraints[$name])) {
                    return $matches[0];
                }

                return '{' . $name . ':' . $constraints[$name] . '}';
            },
            $childPath,
        ) ?? $childPath;
    }

    /**
     * @template T of object
     * @param ReflectionClass<object>|ReflectionMethod $ref
     * @param class-string<T> $attribute
     * @return ReflectionAttribute<T>|null
     */
    private function firstAttr(ReflectionClass|ReflectionMethod $ref, string $attribute): ?ReflectionAttribute
    {
        $attrs = $ref->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF);

        return $attrs[0] ?? null;
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $ref
     * @return array<class-string>
     */
    private function collectUseMiddlewares(ReflectionClass|ReflectionMethod $ref): array
    {
        $middlewares = [];
        foreach ($ref->getAttributes(UseMiddleware::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $definition = $attr->newInstance();
            foreach ($definition->middlewares as $class) {
                $middlewares[] = $class;
            }
        }

        return $middlewares;
    }

    /**
     * @param ReflectionClass<object> $ref
     * @return array<class-string>
     */
    private function collectGroupMiddlewares(ReflectionClass $ref): array
    {
        $middlewares = [];
        foreach ($ref->getAttributes(GroupMiddleware::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $definition = $attr->newInstance();
            foreach ($definition->middlewares as $class) {
                $middlewares[] = $class;
            }
        }

        return $middlewares;
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $ref
     * @return array<string, string>
     */
    private function collectWhere(ReflectionClass|ReflectionMethod $ref): array
    {
        $constraints = [];
        foreach ($ref->getAttributes(WhereAttr::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $where = $attr->newInstance();
            $constraints[$where->param] = $where->pattern;
        }

        return $constraints;
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = '/' . ltrim(trim($prefix), '/');

        return rtrim($prefix, '/');
    }

    /**
     * @param array<int, string> $methods
     */
    private function assertUniqueRoute(array $methods, string $prettyPath, ?string $domain): void
    {
        if (!$this->detectConflicts) {
            return;
        }

        $this->seedCompiledConflictKeys();
        $path = $this->canonicalPath($prettyPath);
        $host = $this->canonicalDomain($domain);

        foreach ($methods as $methodName) {
            $method = strtoupper($methodName);
            $key = sprintf('%s|%s|%s', $method, $host, $path);

            if (isset($this->seenRouteKeys[$key])) {
                throw new RouteConflictException(sprintf('Duplicate route: %s %s (domain: %s)', $method, $path, $host));
            }

            $this->seenRouteKeys[$key] = true;
        }
    }

    private function assertUniqueName(?string $name): void
    {
        if (!$this->detectConflicts || $name === null || $name === '') {
            return;
        }

        $this->seedCompiledConflictKeys();

        if (isset($this->seenNames[$name])) {
            throw new RouteConflictException(sprintf('Duplicate route name: %s', $name));
        }

        $this->seenNames[$name] = true;
    }

    private function canonicalPath(string $pretty): string
    {
        $path = '/' . ltrim($pretty, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        // Normalize placeholders so "/users/{id}" and "/users/{name}" are
        // recognized as the same route (otherwise the later one would be
        // silently shadowed at runtime). Constraints are kept, since
        // "/users/{id:\d+}" and "/users/{id:[a-z]+}" are distinct routes.
        return preg_replace_callback(
            '/\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^}]+)?\}/',
            static function (array $matches): string {
                $inner = substr($matches[0], 1, -1);
                $colon = strpos($inner, ':');

                return $colon === false ? '{}' : '{:' . substr($inner, $colon + 1) . '}';
            },
            $path,
        ) ?? $path;
    }

    private function canonicalDomain(?string $domain): string
    {
        return $domain === null || $domain === '' ? '*' : strtolower($domain);
    }

    /**
     * @param array<int, string> $methods
     * @param callable|class-string|array{0: object|class-string, 1: non-empty-string} $handler
     * @return array<int, \League\Route\Route>
     */
    private function mapRoutes(
        LeagueRouter $target,
        array $methods,
        string $path,
        callable|array|string $handler,
        ?string $domain,
    ): array {
        $this->hasDynamicRoutes = true;
        $domain = $domain === '' ? null : $domain;
        if ($domain !== null) {
            $this->registeredDomains[$domain] = true;
        }

        $routes = [];
        foreach ($methods as $method) {
            $route = $target->map($method, $path, $handler);
            $this->registeredRoutes[] = $route;
            if ($domain !== null) {
                $this->registeredRouteDomains[array_key_last($this->registeredRoutes)] = $domain;
            }
            $routes[] = $route;
        }

        // Rebuilt on the next dispatch so routes added later are matched too.
        $this->registeredDispatchData = [];

        return $routes;
    }

    /**
     * @param array<int, mixed> $routes
     * @param array<class-string|object> $middlewares
     */
    private function configureMappedRoutes(
        array $routes,
        ?string $name,
        array $middlewares,
        ?string $domain,
        ?ThrottleAttr $throttle,
        string $context,
    ): void {
        $throttleMiddleware = null;

        foreach ($routes as $index => $route) {
            $this->callRouteMethodIfAvailable($route, 'setName', $name !== null && $name !== '' && $index === 0, $name);
            $this->callRouteMethodIfAvailable($route, 'setHost', $domain !== null && $domain !== '', $domain);

            foreach ($middlewares as $middleware) {
                $this->callRouteMethod($route, 'middleware', $this->resolveMiddleware($middleware));
            }

            if ($index === 0 && $throttle !== null) {
                $this->assertValidThrottle($throttle->limit, $throttle->perSeconds, $context);

                if ($this->cache === null) {
                    throw new \RuntimeException('Throttle support requires a CacheInterface.');
                }

                $throttleMiddleware = new ThrottleMiddleware(
                    $this->cache,
                    $throttle->limit,
                    $throttle->perSeconds,
                    $throttle->key,
                    $this->logger,
                );
            }

            if ($throttleMiddleware !== null) {
                $this->callRouteMethod($route, 'middleware', $throttleMiddleware);
            }
        }
    }

    private function assertValidThrottle(int $limit, int $perSeconds, string $context): void
    {
        if ($limit < 1 || $perSeconds < 1) {
            throw new InvalidRouteDefinitionException(sprintf(
                'Invalid throttle configuration for %s. Limit and period must be positive integers.',
                $context,
            ));
        }
    }

    private function normalizeHandlerResponse(mixed $response): ResponseInterface
    {
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        if (is_array($response)) {
            return Response::fromArray($response, 404);
        }

        if (is_string($response)) {
            return Response::html($response, 404);
        }

        throw new InvalidNotFoundHandlerResponseException(
            'Not-found handler must return a ResponseInterface, string, or array.',
        );
    }

    private function callRouteMethodIfAvailable(mixed $route, string $method, bool $condition, mixed ...$arguments): void
    {
        if (!$condition || !is_object($route) || !method_exists($route, $method)) {
            return;
        }

        $route->{$method}(...$arguments);
    }

    private function callRouteMethod(mixed $route, string $method, mixed ...$arguments): void
    {
        if (!is_object($route) || !method_exists($route, $method)) {
            throw new \UnexpectedValueException(sprintf('Mapped route is missing required method: %s', $method));
        }

        $route->{$method}(...$arguments);
    }

    /**
     * @param callable|class-string|array{0: object|class-string, 1: non-empty-string}|array<mixed> $handler
     * @return callable|class-string|array{0: object|class-string, 1: non-empty-string}
     */
    private function normalizeCompiledHandler(callable|array|string $handler): callable|array|string
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && (is_object($handler[0]) || is_string($handler[0])) && is_string($handler[1]) && $handler[1] !== '') {
            /** @var array{0: object|class-string, 1: non-empty-string} $handler */
            return $handler;
        }

        if (is_array($handler)) {
            throw new \InvalidArgumentException(
                'Route handlers defined as arrays must use the [class-string|object, method] format.',
            );
        }

        return $handler;
    }
}
