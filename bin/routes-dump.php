#!/usr/bin/env php
<?php

declare(strict_types=1);

// Usage:
//   php bin/routes-dump.php --dir=examples/controllers --dir=app/Http/Controllers
//
// Prints: METHODS | PATH | NAME | DOMAIN | HANDLER

require __DIR__ . '/../vendor/autoload.php';

use Marwa\Router\RouterFactory;
use Psr\SimpleCache\CacheInterface;

// CLI: --dir=path  (repeat)  |  --bootstrap=path/to/bootstrap.php
$dirs = [];
$bootstrap = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir='))        $dirs[] = substr($arg, 6);
    elseif (str_starts_with($arg, '--bootstrap=')) $bootstrap = substr($arg, 12);
}


// Minimal in-memory cache to satisfy throttle if any
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
$app = new RouterFactory(cache: $cache);

$app->registerFromDirectories([__DIR__ . '\..\examples\Controllers'], strict: true);


$app->fluent()->group(['prefix' => '/api', 'name' => 'api.'], function ($r) {
    $r->get('/hello', fn() => "hi")->name('hello')->register();
    $r->get('/hello1', fn() => "hi")->name('hello1')->register();
    $r->get('/hello2', fn() => "hi")->name('hello2')->register();
});

$rows = $app->routes();

// Pretty print header
printf("%-16s %-50s %-36s %-28s %s\n", 'METHODS', 'PATH', 'NAME', 'DOMAIN', 'HANDLER');
echo str_repeat('-', 150) . PHP_EOL;

if (!$rows) {
    fwrite(STDERR, "No routes registered.\n");
    if ($bootstrap) {
        fwrite(STDERR, "• Checked bootstrap: {$bootstrap}\n");
    } else {
        fwrite(STDERR, "• Scanned dirs:\n");
        foreach ($dirs as $d) {
            $exists = is_dir($d) ? 'OK' : 'MISSING';
            fwrite(STDERR, "  - {$d}  [{$exists}]\n");
        }
        fwrite(STDERR, "Tip: pass --dir=/absolute/path/to/your/controllers or --bootstrap=path/to/bootstrap.php\n");
    }
    exit(1);
}

foreach ($rows as $r) {
    $methods = !empty($r['methods']) ? implode('|', $r['methods']) : '';
    $path    = $r['path'] ?? '';
    $name    = $r['name'] ?? '';
    $domain  = $r['domain'] ?? '';
    $handler = (!empty($r['controller']) && !empty($r['action']))
        ? ($r['controller'] . '::' . $r['action'])
        : '(closure/invokable)';

    printf("%-16s %-50s %-36s %-28s %s\n", $methods, $path, $name, $domain, $handler);
}
