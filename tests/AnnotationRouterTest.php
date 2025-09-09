<?php

namespace Marwa\Router\Tests;

use League\Route\Router;
use Marwa\Router\AnnotationRouter;
use Marwa\Router\Exceptions\FileNotFoundException;
use PHPUnit\Framework\TestCase;

class AnnotationRouterTest extends TestCase
{
    private Router $router;
    private string $testControllersPath;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->testControllersPath = __DIR__ . '/test-controllers';

        // Create test directory
        if (!is_dir($this->testControllersPath)) {
            mkdir($this->testControllersPath, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->testControllersPath)) {
            array_map('unlink', glob($this->testControllersPath . '/*.php'));
            rmdir($this->testControllersPath);
        }
    }

    public function testThrowsExceptionForInvalidPath()
    {
        $this->expectException(FileNotFoundException::class);

        $router = new AnnotationRouter(
            $this->router,
            'Test\Controllers',
            '/invalid/path'
        );

        $router->registerRoutesFromAnnotations();
    }

    public function testCreatesAnnotationRouterInstance()
    {
        $annotationRouter = new AnnotationRouter(
            $this->router,
            'Test\Controllers',
            $this->testControllersPath
        );

        $this->assertInstanceOf(AnnotationRouter::class, $annotationRouter);
    }

    // More test methods would be added here...
}
