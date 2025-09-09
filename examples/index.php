<?php

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';


use Marwa\Router\RouterFactory;
use Psr\SimpleCache\CacheInterface;
use Laminas\Diactoros\Response\JsonResponse;
// Use any PSR-16 cache implementation you like.
// Example: symfony/cache PSR-16 adapter, or your own.
$cache = new class implements CacheInterface {
    private array $s = [];
    public function get($key, $default = null): mixed
    {
        return $this->s[$key][0] ?? $default;
    }
    public function set($key, $value, $ttl = null): bool
    {
        $this->s[$key] = [$value, time() + (is_int($ttl) ? $ttl : 60)];
        return true;
    }
    public function delete($key): bool
    {
        unset($this->s[$key]);
        return true;
    }
    public function clear(): bool
    {
        $this->s = [];
        return true;
    }
    public function getMultiple($keys, $default = null): iterable
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->get($k, $default);
        }
        return $out;
    }
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set($k, $v, $ttl);
        }
        return true;
    }
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $k) {
            $this->delete($k);
        }
        return true;
    }
    public function has($key): bool
    {
        return array_key_exists($key, $this->s);
    }
};

//$cache = new FilesystemCache(__DIR__ . '/storage/cache/');

$app = new RouterFactory(cache: $cache);

// 1) Annotation scan (optional)
$app->registerFromDirectories([__DIR__ . '/Controllers']);

// 2) Manual (Laravel-like) routes
// $app->fluent()->group(['prefix' => '/api', 'name' => 'api.'], function ($r) {
//     // GET /api/hello  (also matches /api/hello/)
//     $r->get('/hello', fn() => new JsonResponse(['hi' => 'there']))
//         ->name('hello')
//         ->register();

//     // GET /api/users/{id}  (also /api/users/{id}/)
//     $r->get('/users/{id}', fn($req) => new JsonResponse(['id' => (int)($req->getAttribute('id') ?? 0)]))
//         ->name('users.show')
//         ->where('id', '\d+')
//         ->register();
// });

// Run app (reads globals, dispatches, emits)
$app->run();
