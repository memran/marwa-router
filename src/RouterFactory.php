<?php declare(strict_types=1);

namespace Marwa\Router;

use League\Route\Router as LeagueRouter;
use League\Route\Strategy\StrategyInterface;
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
use Marwa\Router\Exceptions\RouteConflictException;
use Marwa\Router\Middleware\ThrottleMiddleware;
use Marwa\Router\Support\ClassLocator;
use Marwa\Router\Strategy\HtmlStrategy;
use Marwa\Router\Strategy\JsonStrategy;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Psr\SimpleCache\CacheInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

// PSR-7 implementation & emitter
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;

final class RouterFactory
{
    /** Human-readable route table for dump tool */
    /** @var array<int, array{methods:array<int,string>,path:string,name:?string,controller:?string,action:?string,domain:?string}> */
    private array $registry = [];

    private LeagueRouter $router;
    private ?StrategyInterface $strategy = null;

    private ?ContainerInterface $container;
    private ResponseFactoryInterface $responseFactory;
    private ?CacheInterface $cache;

    /** @var array<string,true> */
    private array $seenRouteKeys = [];
    /** @var array<string,true> */
    private array $seenNames = [];
    /** Enable/disable conflict detection */
    private bool $detectConflicts = true;

    /** If true, every route matches with and without a trailing slash. */
    private bool $trailingSlashOptional = true;

    /**
     * Router Fatory constructor 
     */
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

        // Default strategy: HTML
        $this->useHtmlStrategy();
    }

    // -------------------------------------------------
    // Public API
    // -------------------------------------------------

    /** Toggle optional trailing slash matching globally. */
    public function setTrailingSlashOptional(bool $on): self
    {
        $this->trailingSlashOptional = $on;
        return $this;
    }

    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        // reattach container if strategy supports it
        if ($this->strategy && method_exists($this->strategy, 'setContainer')) {
            $this->strategy->setContainer($container);
        }
        return $this;
    }

    public function setCache(CacheInterface $cache): self
    {
        $this->cache = $cache;
        return $this;
    }

    /** Replace the internal League strategy (Html/Json/Text or custom). */
    public function useStrategy(StrategyInterface $strategy): self
    {
        if (method_exists($strategy, 'setContainer') && $this->container) {
            $strategy->setContainer($this->container);
        }
        $this->strategy = $strategy;
        $this->router->setStrategy($strategy);
        return $this;
    }

    public function useHtmlStrategy(): self
    {
        return $this->useStrategy(new HtmlStrategy($this->responseFactory));
    }

    public function useJsonStrategy(int $jsonFlags = 0): self
    {
        return $this->useStrategy(new JsonStrategy($this->responseFactory, $jsonFlags));
    }

    /**
     *  stores route cache
     */
    public function cacheRoutesTo(string $file): void
    {
        file_put_contents($file, '<?php return ' . var_export($this->routes(), true) . ';');
    }

    /**
     * load route cache
     */
    public function loadRoutesFrom(string $file): bool
    {
        if (!is_file($file)) return false;
        $this->registry = require $file;
        return true;
    }

    public function enableConflictDetection(bool $on = true): self
    {
        $this->detectConflicts = $on;
        return $this;
    }
    /**
     * Allow app code to set a custom 404 renderer on strategies that support it
     * (i.e., have setNotFoundHandler() method via a shared trait).
     *
     * @param callable|\Psr\Http\Server\RequestHandlerInterface $handler
     */
    public function setNotFoundHandler($handler): self
    {
        if ($this->strategy && \method_exists($this->strategy, 'setNotFoundHandler')) {
            $this->strategy->setNotFoundHandler($handler);
        }
        return $this;
    }

    /** Dispatch a PSR-7 request. */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        return $this->router->dispatch($request);
    }

    /** Convenience for SAPI usage (Diactoros ServerRequest + SapiEmitter). */
    public function run(): void
    {
        $request  = ServerRequestFactory::fromGlobals();
        $response = $this->dispatch($request);
        (new SapiEmitter())->emit($response);
    }

    /** Expose the route table for dump/debug. */
    public function routes(): array
    {
        return $this->registry;
    }

    // -------------------------------------------------
    // Attribute scanning (eager registration)
    // -------------------------------------------------

    /** @param string[] $controllerDirs */
    public function registerFromDirectories(array $controllerDirs, bool $strict = false): self
    {
        // Require files, collect classes (Windows-safe path filtering)
        $classes = ClassLocator::loadAndCollectClasses(
            fn() => ClassLocator::requirePhpFiles($controllerDirs, $strict),
            $controllerDirs
        );

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
            $prefixPath    = isset($prefixAttr?->path) ? $this->normalizePrefix($prefixAttr->path) : '';
            $namePrefix    = $prefixAttr?->name ?? null;

            $ctrlMw        = $this->collectUseMiddlewares($ref);   // class @UseMiddleware
            $groupMw       = $this->collectGroupMiddlewares($ref); // class @GroupMiddleware
            $allCtrlMw     = array_merge($groupMw, $ctrlMw);       // apply both per-route (eager)
            $whereCtrl     = $this->collectWhere($ref);            // class @Where
            $domainCtrl    = $this->firstAttr($ref, DomainAttr::class)?->newInstance()->host ?? null;
            $throttleClass = $this->firstAttr($ref, ThrottleAttr::class)?->newInstance();

            // EAGER: map routes directly (do NOT use $router->group() which is lazy)
            $this->registerControllerMethods(
                $this->router,
                $ref,
                $allCtrlMw,
                $namePrefix,
                $prefixPath,
                $whereCtrl,
                $domainCtrl,
                $throttleClass
            );
        }

        return $this;
    }

    /**
     * @param LeagueRouter $target (kept for signature compatibility)
     * @param array<class-string> $classMiddlewares
     * @param array<string,string> $classWhere
     */
    private function registerControllerMethods(
        LeagueRouter $target,
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

            // Method-level overrides
            $methodWhere       = $this->collectWhere($method);
            $effectiveWhere    = array_merge($classWhere, $methodWhere); // method overrides class
            $methodDomainAttr  = $this->firstAttr($method, DomainAttr::class)?->newInstance()->host ?? null;
            $effectiveDomain   = $methodDomainAttr ?? $classDomain;
            $methodThrottle    = $this->firstAttr($method, ThrottleAttr::class)?->newInstance() ?? null;
            $effectiveThrottle = $methodThrottle ?? $classThrottle;

            foreach ($routeAttrs as $attr) {
                /** @var RouteAttr $routeMeta */
                $routeMeta = $attr->newInstance();

                // HTTP methods
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

                // Path building
                $childRaw     = (string) $routeMeta->path; // '' | '/{id}' | '{id}'
                $childWhere   = $this->applyWhere(ltrim($childRaw, '/'), $effectiveWhere);
                $prettyFull   = $this->joinPath($groupPrefix, $childWhere); // "/api/users" or "/api/users/{id:\d+}"
                $mappedPath   = $this->toMappable($prettyFull);             // "/api/users[/]" or "/api/users/{id:\d+}[/]"

                $this->assertUniqueRoute($methods, $prettyFull, $effectiveDomain);
                $this->assertUniqueName($routeMeta->name ? (($namePrefix ?? '') . $routeMeta->name) : null);

                // Handler (container aware)
                $handler = [$this->resolveController($controller->getName()), $method->getName()];
                $route   = $this->router->map($methods, $mappedPath, $handler);

                // Name
                if ($routeMeta->name) {
                    $route->setName(($namePrefix ?? '') . $routeMeta->name);
                }

                // Domain (if supported)
                if ($effectiveDomain && \method_exists($route, 'setHost')) {
                    $route->setHost($effectiveDomain);
                }

                // Middlewares: class/group -> method @UseMiddleware -> route-meta middlewares
                foreach ($classMiddlewares as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }
                foreach ($this->collectUseMiddlewares($method) as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }
                foreach ($routeMeta->middlewares as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }

                // Throttle
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

                // Record pretty route for dump
                $this->registry[] = [
                    'methods'    => $methods,
                    'path'       => $prettyFull,
                    'name'       => $routeMeta->name ? (($namePrefix ?? '') . $routeMeta->name) : null,
                    'controller' => $controller->getName(),
                    'action'     => $method->getName(),
                    'domain'     => $effectiveDomain,
                ];
            }
        }
    }

    // -------------------------------------------------
    // Fluent API
    // -------------------------------------------------

    public function fluent(): Fluent\RouteRegistrar
    {
        return new Fluent\RouteRegistrar($this);
    }

    /**
     * Low-level manual map used by the fluent layer.
     *
     * @param array<int,string>|string    $methods
     * @param callable|array|string       $handler  (callable or ["Class","method"])
     * @param array<class-string|object>  $middlewares
     * @param array<string,string>|null   $where
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

        $child      = ltrim(trim($path), '/');                 // '' ok
        $childWhere = $this->applyWhere($child, $where ?? []);
        $pretty     = $this->joinPath('', $childWhere);        // top-level pretty path
        $mapped     = $this->toMappable($pretty);

        // handler normalization
        $controllerName = null;
        $actionName = null;
        $callable = $handler;
        if (\is_array($handler) && \is_string($handler[0] ?? null) && \is_string($handler[1] ?? null)) {
            $controllerName = $handler[0];
            $actionName = $handler[1];
            $callable = [$this->resolveController($controllerName), $actionName];
        }

        $this->assertUniqueRoute($finalMethods, $pretty === '' ? '/' : $pretty, $domain);
        $this->assertUniqueName($name);

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
            $route->middleware(new ThrottleMiddleware(
                $this->cache,
                (int)$throttle['limit'],
                (int)$throttle['per'],
                (string)$throttle['key']
            ));
        }

        // Record pretty path (no FastRoute tokens)
        $this->registry[] = [
            'methods'    => $finalMethods,
            'path'       => $pretty === '' ? '/' : $pretty,
            'name'       => $name,
            'controller' => $controllerName,
            'action'     => $actionName,
            'domain'     => $domain,
        ];

        return $this;
    }

    // -------------------------------------------------
    // Helpers
    // -------------------------------------------------

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

    /** Pretty join: "/api/users" + "" => "/api/users"; + "{id:\d+}" => "/api/users/{id:\d+}" */
    private function joinPath(string $base, string $child): string
    {
        $base  = '/' . ltrim(trim($base), '/');
        $base  = rtrim($base, '/'); // canonical
        $child = ltrim(trim($child), '/');
        return $child === '' ? ($base === '' ? '/' : $base) : ($base === '' ? '/' . $child : $base . '/' . $child);
    }

    /** Convert pretty path to FastRoute pattern with optional trailing slash if enabled. */
    private function toMappable(string $pretty): string
    {
        if ($pretty === '' || $pretty === '/') {
            return '/';
        }
        $pretty = '/' . ltrim($pretty, '/');
        if ($this->trailingSlashOptional) {
            return rtrim($pretty, '/') . '[/]';
        }
        return $pretty;
    }

    /** Apply {param:regex} constraints unless already present. */
    private function applyWhere(string $childPath, array $constraints): string
    {
        if ($childPath === '' || empty($constraints)) return $childPath;

        return \preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/', function ($m) use ($constraints) {
            $name = $m[1];
            // if already has a regex ( {name:...} ), keep it
            if (\str_contains($m[0], ':') || !isset($constraints[$name])) {
                return $m[0];
            }
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

    private function normalizePrefix(string $p): string
    {
        $p = '/' . ltrim(trim($p), '/');
        return rtrim($p, '/');
    }

    /**
     * Ensure {METHOD, domain, path} is unique.
     * @param array<int,string> $methods
     */
    private function assertUniqueRoute(array $methods, string $prettyPath, ?string $domain): void
    {
        if (!$this->detectConflicts) return;

        $path = $this->canonicalPath($prettyPath);
        $host = $this->canonicalDomain($domain);

        foreach ($methods as $m) {
            $method = strtoupper($m);
            $key = "{$method}|{$host}|{$path}";
            if (isset($this->seenRouteKeys[$key])) {
                throw new RouteConflictException("Duplicate route: {$method} {$path} (domain: {$host})");
            }
            $this->seenRouteKeys[$key] = true;
        }
    }

    /** Ensure route names are unique (if provided). */
    private function assertUniqueName(?string $name): void
    {
        if (!$this->detectConflicts || $name === null || $name === '') return;

        if (isset($this->seenNames[$name])) {
            throw new RouteConflictException("Duplicate route name: {$name}");
        }
        $this->seenNames[$name] = true;
    }
    private function canonicalPath(string $pretty): string
    {
        // ensure single leading slash, strip trailing slash (except root)
        $p = '/' . ltrim($pretty, '/');
        return $p === '/' ? '/' : rtrim($p, '/');
    }

    private function canonicalDomain(?string $domain): string
    {
        return $domain === null || $domain === '' ? '*' : strtolower($domain);
    }
}
