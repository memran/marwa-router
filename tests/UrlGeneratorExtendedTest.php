<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;

final class UrlGeneratorExtendedTest extends TestCase
{
    public function testForThrowsForMissingRouteName(): void
    {
        $generator = new UrlGenerator([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Route not found by name: nonexistent');

        $generator->for('nonexistent');
    }

    public function testForThrowsForMissingRequiredParam(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'users.show', 'path' => '/users/{id}'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing route param: id');

        $generator->for('users.show');
    }

    public function testForReturnsRootForEmptyPath(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'home', 'path' => ''],
        ]);

        self::assertSame('/', $generator->for('home'));
    }

    public function testVerifyRejectsExpiredUrl(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'test', 'path' => '/test'],
        ]);

        $signed = $generator->signed('test', [], 1, 'secret');

        // Manipulate the expiry to be in the past
        $parts = parse_url($signed);
        self::assertNotFalse($parts);
        parse_str($parts['query'] ?? '', $query);
        $query['exp'] = (string) (time() - 100);
        $tampered = $parts['path'] . '?' . http_build_query($query);

        self::assertFalse($generator->verify($tampered, 'secret'));
    }

    public function testVerifyRejectsUrlMissingSig(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'test', 'path' => '/test'],
        ]);

        self::assertFalse($generator->verify('/test?exp=9999999999', 'secret'));
    }

    public function testVerifyRejectsUrlMissingExp(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'test', 'path' => '/test'],
        ]);

        self::assertFalse($generator->verify('/test?sig=abc', 'secret'));
    }

    public function testVerifyRejectsDifferentKey(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'test', 'path' => '/test'],
        ]);

        $signed = $generator->signed('test', [], 300, 'key-a');

        self::assertFalse($generator->verify($signed, 'key-b'));
    }

    public function testVerifyRejectsTamperedUrl(): void
    {
        $generator = new UrlGenerator([
            ['name' => 'test', 'path' => '/test'],
        ]);

        $signed = $generator->signed('test', [], 300, 'secret');

        self::assertFalse($generator->verify($signed . '&extra=1', 'secret'));
    }
}
