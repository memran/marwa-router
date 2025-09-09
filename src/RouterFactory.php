<?php

declare(strict_types=1);

namespace Marwa\Router;

use League\Route\Router;
use League\Route\RouteGroup;
use Marwa\Router\Attributes\Prefix;
use Marwa\Router\Attributes\Route as RouteAttr;
use Marwa\Router\Attributes\UseMiddleware;
use Marwa\Router\Exceptions\InvalidRouteDefinitionException;
use Marwa\Router\Support\ClassLocator;
use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * RouterFactory
 *
 * Builds league/route router by scanning controller classes for PHP 8 attributes.
 * - Supports controller-level Prefix and UseMiddleware
 * - Supports method-level Route and UseMiddleware
 * - Optional PSR-11 container for controller and middleware instantiation
 */
final class RouterFactory
{
    private Router $router;
    private ?ContainerInterface $container;
    private array $registry = [];


    public function __construct(?Router $router = null, ?ContainerInterface $container = null)
    {
        $this->router    = $router ?? new Router();
        $this->container = $container;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    /** @return array<int, array{methods:array,path:string,name:?string,controller:string,action:string}> */
    public function routes(): array
    {
        return $this->registry;
    }

    /**
     * Scan one or more directories, require PHP files, discover classes and register routes.
     * WARNING: This requires application files. Use only with trusted code bases.
     *
     * @param string[] $controllerDirs
     * @return $this
     */
    public function registerFromDirectories(array $controllerDirs): self
    {
        $classes = ClassLocator::loadAndCollectClasses(
            fn() => ClassLocator::requirePhpFiles($controllerDirs)
        );

        $this->registerFromClasses($classes);
        return $this;
    }

    /**
     * Register from an explicit list of class names (already autoloaded).
     * @param array<class-string> $classNames
     * @return $this
     */
    public function registerFromClasses(array $classNames): self
    {
        // var_dump($classNames);
        // die();
        foreach ($classNames as $class) {
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || $ref->isInterface()) {
                continue;
            }

            $prefixAttr = self::firstAttr($ref, Prefix::class);
            $ctrlMw     = self::collectMiddlewares($ref);

            $prefixPath = $prefixAttr?->newInstance()->path ?? '';
            $namePrefix = $prefixAttr?->newInstance()->name ?? null;

            if ($prefixPath) {
                $this->router->group($prefixPath, function (RouteGroup $group) use ($ref, $ctrlMw, $namePrefix) {
                    $this->registerControllerMethods($group, $ref, $ctrlMw, $namePrefix);
                });
            } else {
                $this->registerControllerMethods($this->router, $ref, $ctrlMw, $namePrefix);
            }
        }
        return $this;
    }

    public function registerFromRegistry(array $entries): self
    {
        foreach ($entries as $e) {
            $handler = [$this->resolveController($e['controller']), $e['action']];
            $route = $this->router->map($e['methods'], $e['path'] ?: '/', $handler);
            if (!empty($e['name'])) {
                $route->setName($e['name']);
            }
        }
        return $this;
    }

    /**
     * @param Router|RouteGroup $target
     */
    private function registerControllerMethods($target, ReflectionClass $controller, array $classMiddlewares, ?string $namePrefix): void
    {
        foreach ($controller->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(RouteAttr::class, ReflectionAttribute::IS_INSTANCEOF);
            if (!$routeAttrs) {
                continue;
            }

            foreach ($routeAttrs as $attr) {
                /** @var RouteAttr $routeMeta */
                $routeMeta = $attr->newInstance();

                // Normalize methods (must not be empty)
                $methods = array_values(array_filter(
                    array_map(static fn(string $m) => strtoupper(trim($m)), $routeMeta->methods),
                    static fn(string $m) => $m !== ''
                ));
                if (empty($methods)) {
                    throw new InvalidRouteDefinitionException(sprintf(
                        'Invalid Route attribute (no methods) on %s::%s',
                        $controller->getName(),
                        $method->getName()
                    ));
                }

                $path = self::normalizePath($routeMeta->path, $target instanceof RouteGroup);
                $handler = [$this->resolveController($controller->getName()), $method->getName()];
                $route   = $target->map($methods, $path, $handler);

                /** for storing in the array  */
                $this->registry[] = [
                    'methods'    => $methods,
                    'path'       => $path,
                    'name'       => $routeMeta->name ? (($namePrefix ?? '') . $routeMeta->name) : null,
                    'controller' => $controller->getName(),
                    'action'     => $method->getName(),
                ];
                // Optional name (with prefix if provided)
                if ($routeMeta->name) {
                    $route->setName(($namePrefix ?? '') . $routeMeta->name);
                }

                // Middlewares: class-level first, then method-level, then inline on Route attribute
                foreach ($classMiddlewares as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }
                foreach (self::collectMiddlewares($method) as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }
                foreach ($routeMeta->middlewares as $mw) {
                    $route->middleware($this->resolveMiddleware($mw));
                }
            }
        }
    }
    /** Normalize a route path string based on whether target is a RouteGroup. */
    private static function normalizePath(string $raw, bool $inGroup): string
    {
        if ($raw === '' || trim($raw) === '') {
            return $inGroup ? '' : '/';
        }
        $p = $raw[0] === '/' ? $raw : '/' . $raw;
        return preg_replace('#//+#', '/', $p);
    }

    /** @return class-string[] */
    private static function collectMiddlewares(ReflectionClass|ReflectionMethod $ref): array
    {
        $mw = [];
        foreach ($ref->getAttributes(UseMiddleware::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            /** @var UseMiddleware $def */
            $def = $attr->newInstance();
            foreach ($def->middlewares as $class) {
                $mw[] = $class;
            }
        }
        return $mw;
    }

    private static function firstAttr(ReflectionClass $ref, string $attribute): ?ReflectionAttribute
    {
        $attrs = $ref->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF);
        return $attrs[0] ?? null;
    }

    /** @return object Controller instance */
    private function resolveController(string $class): object
    {
        if ($this->container && $this->container->has($class)) {
            return $this->container->get($class);
        }
        return new $class();
    }

    /** @return object Middleware instance */
    private function resolveMiddleware(string $class): object
    {
        if ($this->container && $this->container->has($class)) {
            return $this->container->get($class);
        }
        return new $class();
    }
}
