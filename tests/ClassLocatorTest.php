<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Support\ClassLocator;
use PHPUnit\Framework\TestCase;

final class ClassLocatorTest extends TestCase
{
    public function testLoadAndCollectClassesReturnsConcreteFixtureClasses(): void
    {
        $fixtures = __DIR__ . '/Fixtures/ClassLocator';

        $classes = ClassLocator::loadAndCollectClasses(
            static fn (): array => ClassLocator::requirePhpFiles([$fixtures], true),
            [$fixtures],
        );

        self::assertContains('Marwa\\Router\\Tests\\Fixtures\\ClassLocator\\FixtureController', $classes);
    }

    public function testRequirePhpFilesThrowsForMissingDirectoryInStrictMode(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ClassLocator::requirePhpFiles([__DIR__ . '/Fixtures/Missing'], true);
    }
}
