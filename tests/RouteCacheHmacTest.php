<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\RouterFactory;
use Marwa\Router\Support\RouteCache;
use PHPUnit\Framework\TestCase;

final class RouteCacheHmacTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/marwa-router-hmac-tests-' . uniqid('', true);
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testMetadataCacheWithSigningKeyWritesAndLoads(): void
    {
        $cache = new RouteCache('my-secret-key');
        $file = $this->cacheDir . '/metadata.php';

        $routes = [
            [
                'methods' => ['GET'],
                'path' => '/users',
                'name' => 'users.index',
                'controller' => 'App\\Controller\\UserController',
                'action' => 'index',
                'domain' => null,
            ],
        ];

        $cache->writeMetadata($file, $routes);
        $loaded = $cache->loadMetadata($file);

        self::assertSame($routes, $loaded);
    }

    public function testMetadataCacheRejectsWrongSigningKey(): void
    {
        $cache = new RouteCache('correct-key');
        $file = $this->cacheDir . '/metadata.php';

        $cache->writeMetadata($file, []);

        $wrongKeyCache = new RouteCache('wrong-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');

        $wrongKeyCache->loadMetadata($file);
    }

    public function testMetadataCacheRejectsTamperedFile(): void
    {
        $cache = new RouteCache('my-secret-key');
        $file = $this->cacheDir . '/metadata.php';

        $cache->writeMetadata($file, []);

        // Read the signed content and modify the payload, keeping the old SIG
        $content = file_get_contents($file);
        self::assertNotFalse($content);
        $modified = str_replace("return array (\n)", "return array (\n  0 => 'hacked',\n)", $content);
        file_put_contents($file, $modified);

        $freshCache = new RouteCache('my-secret-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');

        $freshCache->loadMetadata($file);
    }

    public function testMetadataCacheRejectsUnsignedFile(): void
    {
        $cache = new RouteCache('my-secret-key');
        $file = $this->cacheDir . '/metadata.php';

        // Write a file without signature
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing HMAC signature');

        $cache->loadMetadata($file);
    }

    public function testCompiledCacheWithSigningKeyWritesAndLoads(): void
    {
        $file = $this->cacheDir . '/compiled.php';

        $router = new RouterFactory(routeCache: new RouteCache('my-secret-key'));
        $router->registerFromClasses([\Marwa\Router\Tests\Fixtures\TestController::class]);

        $router->compileRoutesTo($file);

        $cachedRouter = new RouterFactory(routeCache: new RouteCache('my-secret-key'));
        $cachedRouter->loadCompiledRoutesFrom($file);

        $response = $cachedRouter->handle(\Marwa\Router\Http\RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/test', 'REQUEST_METHOD' => 'GET'],
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCompiledCacheRejectsWrongSigningKey(): void
    {
        $file = $this->cacheDir . '/compiled.php';

        $router = new RouterFactory(routeCache: new RouteCache('correct-key'));
        $router->registerFromClasses([\Marwa\Router\Tests\Fixtures\TestController::class]);
        $router->compileRoutesTo($file);

        $wrongKeyRouter = new RouterFactory(routeCache: new RouteCache('wrong-key'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');

        $wrongKeyRouter->loadCompiledRoutesFrom($file);
    }

    public function testMetadataCacheWithoutSigningKeyWorksBackwardCompatible(): void
    {
        $cache = new RouteCache();
        $file = $this->cacheDir . '/metadata.php';

        $routes = [
            [
                'methods' => ['GET'],
                'path' => '/ping',
                'name' => 'ping',
                'controller' => null,
                'action' => null,
                'domain' => null,
            ],
        ];

        $cache->writeMetadata($file, $routes);
        $loaded = $cache->loadMetadata($file);

        self::assertSame($routes, $loaded);

        // Verify no signature comment in file
        $content = file_get_contents($file);
        self::assertNotFalse($content);
        self::assertStringNotContainsString('SIG:', $content);
    }
}
