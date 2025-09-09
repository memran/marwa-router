<?php

declare(strict_types=1);

namespace Marwa\Router;

final class UrlGenerator
{
    /** @param array<int, array{path:string,name:?string}> $routes */
    public function __construct(private array $routes) {}

    public function for(string $name, array $params = []): string
    {
        foreach ($this->routes as $r) {
            if ($r['name'] !== $name) continue;
            $path = $r['path'] ?: '/';
            // Replace tokens like {id:\d+} or {slug}
            $url = preg_replace_callback(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
                function ($m) use (&$params) {
                    $key = $m[1];
                    if (!array_key_exists($key, $params)) {
                        throw new \InvalidArgumentException("Missing route param: {$key}");
                    }
                    $v = (string)$params[$key];
                    unset($params[$key]);
                    return $v;
                },
                $path
            );
            if (!empty($params)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            }
            return $url;
        }
        throw new \RuntimeException("Route not found by name: {$name}");
    }
}
