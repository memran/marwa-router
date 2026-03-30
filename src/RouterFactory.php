<?php

declare(strict_types=1);

namespace Marwa\Router;

use Laminas\Diactoros\ResponseFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use League\Route\Http\Exception\NotFoundException;
use League\Route\Router as LeagueRouter;
use League\Route\Strategy\ApplicationStrategy;
use Marwa\Router\Attributes\{
    Domain as DomainAttr,
    GroupMiddleware,
    Prefix,
    Route as RouteAttr,
    Throttle as ThrottleAttr,
    UseMiddleware,
    Where as WhereAttr
};
use Marwa\Router\Exceptions\FileNotFoundException;
use Marwa\Router\Exceptions\InvalidRouteDefinitionException;
use Marwa\Router\Exceptions\RouteConflictException;
use Marwa\Router\Http\RequestFactory;
use Marwa\Router\Middleware\ThrottleMiddleware;
use Marwa\Router\Support\ClassLocator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

final class RouterFactory
{
    /** @var array<int, array{methods:array<int,string>,path:string,name:?string,controller:?string,action:?string,domain:?string}> */
    private array $registry = [];

    private LeagueRouter $router;
    private ?ApplicationStrategy $strategy = null;
    private ?ContainerInterface $container;
    private ?CacheInterface $cache;

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
    ) {
        $responseFactory ??= new ResponseFactory();
        $this->cache = $cache;
        $this->router = $router ?? new LeagueRouter();
        $this->container = $container;

        if ($container !== null) {
            $this->setContainer($container);
        }
    }

    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        $this->strategy = new ApplicationStrategy();
        $this->strategy->setContainer($container);
        $this->router->setStrategy($this->strategy);

        return $this;
    }

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

    public function cacheRoutesTo(string $file): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create route cache directory: %s', $directory));
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($this->routes(), true) . ";\n";
        if (file_put_contents($file, $payload, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write route cache file: %s', $file));
        }
    }

    public function loadRoutesFrom(string $file): bool
    {
        if (!is_file($file)) {
            throw new FileNotFoundException($file);
        }

        $routes = require $file;
        if (!is_array($routes)) {
            throw new \UnexpectedValueException(sprintf('Route cache file must return an array: %s', $file));
        }

        $this->registry = $routes;

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
        return $this->router->dispatch($request);
    }

    public function run(): void
    {
        $request = RequestFactory::fromGlobals();

        try {
            $response = $this->dispatch($request);
        } catch (NotFoundException $e) {
            if ($this->notFoundHandler === null) {
                throw new \RuntimeException('Route not found.', previous: $e);
            }

            $response = $this->normalizeHandlerResponse(($this->notFoundHandler)($request));
        }

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

            $prefixAttr = $this->firstAttr($ref, Prefix::class)?->newInstance();
            $prefixPath = isset($prefixAttr?->path) ? $this->normalizePrefix($prefixAttr->path) : '';
            $namePrefix = $prefixAttr?->name ?? null;
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
            $handler = [$this->resolveController($controller->getName()), $method->getName()];

            foreach ($routeAttrs as $attr) {
                /** @var RouteAttr $routeMeta */
                $routeMeta = $attr->newInstance();
                $methods = array_values(array_filter(
                    array_map(static fn (string $item): string => strtoupper(trim($item)), $routeMeta->methods),
                    static fn (string $item): bool => $item !== '',
                ));

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
                    $this->collectUseMiddlewares($method),
                    $routeMeta->middlewares,
                );

                $this->configureMappedRoutes(
                    $this->mapRoutes($target, $methods, $mappedPath, $handler),
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
            }
        }
    }

    public function fluent(): Fluent\RouteRegistrar
    {
        return new Fluent\RouteRegistrar($this);
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
        $finalMethods = array_values(array_filter(
            array_map(static fn (string $item): string => strtoupper(trim($item)), (array) $methods),
            static fn (string $item): bool => $item !== '',
        ));

        if ($finalMethods === []) {
            throw new \InvalidArgumentException('map(): at least one HTTP method is required.');
        }

        $child = ltrim(trim($path), '/');
        $prettyPath = $this->joinPath('', $this->applyWhere($child, $where ?? []));
        $mappedPath = $this->toMappable($prettyPath);

        $controllerName = null;
        $actionName = null;
        $callable = $handler;
        if (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null) && $handler[1] !== '') {
            $controllerName = $handler[0];
            $actionName = $handler[1];
            $callable = [$this->resolveController($controllerName), $actionName];
        }

        $this->assertUniqueRoute($finalMethods, $prettyPath, $domain);
        $this->assertUniqueName($name);

        $throttleConfig = null;
        if ($throttle !== null) {
            $this->assertValidThrottle((int) $throttle['limit'], (int) $throttle['per'], 'fluent route');
            $throttleConfig = $throttle;
        }

        $this->configureMappedRoutes(
            $this->mapRoutes($this->router, $finalMethods, $mappedPath, $callable),
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

        return $this;
    }

    private function resolveController(string $class): object
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Controller class does not exist: %s', $class));
        }

        if ($this->container !== null && $this->container->has($class)) {
            return $this->container->get($class);
        }

        return new $class();
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

        if ($this->container !== null && $this->container->has($middleware)) {
            return $this->container->get($middleware);
        }

        return new $middleware();
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

        return $this->trailingSlashOptional ? rtrim($pretty, '/') . '[/]' : $pretty;
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

        if (isset($this->seenNames[$name])) {
            throw new RouteConflictException(sprintf('Duplicate route name: %s', $name));
        }

        $this->seenNames[$name] = true;
    }

    private function canonicalPath(string $pretty): string
    {
        $path = '/' . ltrim($pretty, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function canonicalDomain(?string $domain): string
    {
        return $domain === null || $domain === '' ? '*' : strtolower($domain);
    }

    /**
     * @param array<int, string> $methods
     * @param callable|class-string|array{0: object|class-string, 1: non-empty-string} $handler
     * @return array<int, mixed>
     */
    private function mapRoutes(LeagueRouter $target, array $methods, string $path, callable|array|string $handler): array
    {
        $routes = [];
        foreach ($methods as $method) {
            $routes[] = $target->map($method, $path, $handler);
        }

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
        foreach ($routes as $index => $route) {
            $this->callRouteMethodIfAvailable($route, 'setName', $name !== null && $name !== '' && $index === 0, $name);
            $this->callRouteMethodIfAvailable($route, 'setHost', $domain !== null && $domain !== '', $domain);

            foreach ($middlewares as $middleware) {
                $this->callRouteMethod($route, 'middleware', $this->resolveMiddleware($middleware));
            }

            if ($throttle !== null) {
                $this->assertValidThrottle($throttle->limit, $throttle->perSeconds, $context);

                if ($this->cache === null) {
                    throw new \RuntimeException('Throttle support requires a CacheInterface.');
                }

                $this->callRouteMethod($route, 'middleware', new ThrottleMiddleware(
                    $this->cache,
                    $throttle->limit,
                    $throttle->perSeconds,
                    $throttle->key,
                ));
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

        throw new \UnexpectedValueException('Not-found handler must return a ResponseInterface, string, or array.');
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
}
