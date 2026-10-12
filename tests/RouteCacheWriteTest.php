<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Support\RouteCache;
use PHPUnit\Framework\TestCase;

final class RouteCacheWriteTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/marwa-router-write-tests-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->cacheDir, 0755);
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testWriteReplacesFileWithoutLeavingTemporaryFiles(): void
    {
        $cache = new RouteCache();
        $file = $this->cacheDir . '/routes.php';

        $cache->writeMetadata($file, []);
        $cache->writeMetadata($file, [$this->route('/users')]);

        self::assertSame([$this->route('/users')], $cache->loadMetadata($file));
        self::assertSame([$file], glob($this->cacheDir . '/*'));
    }

    public function testWriteKeepsTheModeAPlainWriteWouldGive(): void
    {
        $file = $this->cacheDir . '/routes.php';

        (new RouteCache())->writeMetadata($file, []);

        clearstatcache();
        self::assertSame(0666 & ~umask(), fileperms($file) & 0777);
    }

    public function testFailedWriteKeepsThePreviousFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not apply to root.');
        }

        $cache = new RouteCache();
        $file = $this->cacheDir . '/routes.php';
        $cache->writeMetadata($file, [$this->route('/users')]);

        chmod($this->cacheDir, 0555);

        try {
            $cache->writeMetadata($file, []);
            self::fail('Writing into a read-only directory should fail.');
        } catch (\RuntimeException) {
        }

        chmod($this->cacheDir, 0755);

        self::assertSame([$this->route('/users')], $cache->loadMetadata($file));
        self::assertSame([$file], glob($this->cacheDir . '/*'));
    }

    /**
     * @return array{methods:array<int,string>,path:string,name:?string,controller:?string,action:?string,domain:?string}
     */
    private function route(string $path): array
    {
        return [
            'methods' => ['GET'],
            'path' => $path,
            'name' => null,
            'controller' => null,
            'action' => null,
            'domain' => null,
        ];
    }
}
