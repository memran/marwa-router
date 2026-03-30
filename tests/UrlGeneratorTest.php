<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;

final class UrlGeneratorTest extends TestCase
{
    public function testForBuildsRouteAndEncodesUnusedParamsIntoQueryString(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'users.show', 'path' => '/users/{id}'],
        ]);

        $url = $generator->for('users.show', ['id' => 'john/doe', 'tab' => 'profile']);

        self::assertSame('/users/john%2Fdoe?tab=profile', $url);
    }

    public function testSignedAndVerifyWorkWithExistingQueryParameters(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'users.show', 'path' => '/users/{id}'],
        ]);

        $signed = $generator->signed('users.show', ['id' => 42, 'tab' => 'activity'], 300, 'secret');

        self::assertTrue($generator->verify($signed, 'secret'));
        self::assertFalse($generator->verify(str_replace('tab=activity', 'tab=admin', $signed), 'secret'));
    }

    public function testSignedRejectsNonPositiveTtl(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'home', 'path' => '/'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $generator->signed('home', [], 0, 'secret');
    }
}
