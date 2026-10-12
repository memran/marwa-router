<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Http\RequestFactory;
use Marwa\Router\RouterFactory;
use Marwa\Router\Support\RouteCache;
use PHPUnit\Framework\TestCase;

final class RouteCacheBuildScriptTest extends TestCase
{
    private string $outDir;

    protected function setUp(): void
    {
        $this->outDir = sys_get_temp_dir() . '/marwa-build-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->outDir);
    }

    public function testBuildsCachesThatLoadAndDispatch(): void
    {
        [$exitCode, $output] = $this->runScript(
            '--dir=' . dirname(__DIR__) . '/examples/Controllers',
            '--out=' . $this->outDir,
        );

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Wrote ' . $this->outDir . '/routes.php', $output);
        self::assertMatchesRegularExpression('/routes\.compiled\.php \(\d+ routes\)/', $output);

        $metadata = (new RouteCache())->loadMetadata($this->outDir . '/routes.php');
        self::assertContains('home', array_column($metadata, 'name'));

        $router = new RouterFactory();
        $router->loadCompiledRoutesFrom($this->outDir . '/routes.compiled.php');
        self::assertSame($metadata, $router->routes());

        $response = $router->handle(RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/about', 'REQUEST_METHOD' => 'GET'],
        ));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('This is the about page', (string) $response->getBody());
    }

    public function testRejectsUnknownArguments(): void
    {
        [$exitCode, $output] = $this->runScript('--bogus');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Unknown argument: --bogus', $output);
        self::assertDirectoryDoesNotExist($this->outDir);
    }

    /**
     * @return array{0:int, 1:string}
     */
    private function runScript(string ...$args): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/routes-build-cache.php'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        self::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
