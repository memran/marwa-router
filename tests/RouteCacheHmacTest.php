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

        // Modify the payload, keeping the old SIG
        $content = file_get_contents($file);
        self::assertNotFalse($content);
        $modified = str_replace('a:0:{}', 'a:1:{i:0;s:6:"hacked";}', $content);
        self::assertNotSame($content, $modified);
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

    public function testSignedCacheIsDataThatNeverExecutes(): void
    {
        $file = $this->cacheDir . '/compiled.php';

        $router = new RouterFactory(routeCache: new RouteCache('my-secret-key'));
        $router->registerFromClasses([\Marwa\Router\Tests\Fixtures\TestController::class]);
        $router->compileRoutesTo($file);

        $content = file_get_contents($file);
        self::assertNotFalse($content);
        self::assertStringStartsWith("<?php __halt_compiler();\n", $content);
        self::assertStringNotContainsString('return ', $content);

        // Requiring the file directly runs nothing and outputs nothing.
        ob_start();
        $result = require $file;
        self::assertSame('', ob_get_clean());
        self::assertSame(1, $result);
    }

    public function testSignedCacheOfOneKindIsRejectedAsTheOther(): void
    {
        $file = $this->cacheDir . '/routes.php';
        $cache = new RouteCache('my-secret-key');
        $cache->writeMetadata($file, []);

        $router = new RouterFactory(routeCache: new RouteCache('my-secret-key'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');

        $router->loadCompiledRoutesFrom($file);
    }

    public function testLegacySignedMetadataCacheStillLoads(): void
    {
        $file = $this->cacheDir . '/metadata.php';
        $routes = [['methods' => ['GET'], 'path' => '/legacy', 'name' => null, 'controller' => null, 'action' => null, 'domain' => null]];

        $unsigned = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($routes, true) . ";\n";
        $signature = hash_hmac('sha256', $unsigned, 'my-secret-key');
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n/* SIG:{$signature} */\n\nreturn " . var_export($routes, true) . ";\n");

        self::assertSame($routes, (new RouteCache('my-secret-key'))->loadMetadata($file));
    }

    public function testLegacySignedCompiledCacheStillLoadsAndRejectsTampering(): void
    {
        $file = $this->cacheDir . '/compiled.php';
        $code = <<<'PHP'
            <?php

            declare(strict_types=1);

            return static function (\Marwa\Router\RouterFactory $router): void {
                $router->map(['GET'], '/legacy', [\Marwa\Router\Tests\Fixtures\TestController::class, 'test']);
            };

            PHP;
        file_put_contents($file, '/* SIG:' . hash_hmac('sha256', $code, 'my-secret-key') . " */\n" . $code);

        $router = new RouterFactory(routeCache: new RouteCache('my-secret-key'));
        $router->loadCompiledRoutesFrom($file);
        $response = $router->handle(\Marwa\Router\Http\RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/legacy', 'REQUEST_METHOD' => 'GET'],
        ));
        self::assertSame(200, $response->getStatusCode());

        file_put_contents($file, str_replace("'/legacy'", "'/hacked'", (string) file_get_contents($file)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');

        (new RouterFactory(routeCache: new RouteCache('my-secret-key')))->loadCompiledRoutesFrom($file);
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
