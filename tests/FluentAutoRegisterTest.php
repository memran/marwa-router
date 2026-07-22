<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\RouterFactory;
use PHPUnit\Framework\TestCase;

final class FluentAutoRegisterTest extends TestCase
{
    public function testDefinitionsAutoRegisterByDefault(): void
    {
        $factory = new RouterFactory();

        $factory->fluent()->get('/auto', static fn () => 'ok');
        // destructor of the temporary definition runs at end of statement

        self::assertCount(1, $factory->routes());
        self::assertSame('/auto', $factory->routes()[0]['path']);
    }

    public function testAutoRegisterDisabledViaFactory(): void
    {
        $factory = new RouterFactory();

        $factory->fluent(autoRegister: false)->get('/manual', static fn () => 'ok');

        self::assertCount(0, $factory->routes());
    }

    public function testExplicitRegisterWorksWhenAutoRegisterDisabled(): void
    {
        $factory = new RouterFactory();

        $factory->fluent(autoRegister: false)
            ->get('/manual', static fn () => 'ok')
            ->name('manual')
            ->register();

        self::assertCount(1, $factory->routes());
        self::assertSame('/manual', $factory->routes()[0]['path']);
        self::assertSame('manual', $factory->routes()[0]['name']);
    }

    public function testAutoRegisterDisabledViaRegistrarSetter(): void
    {
        $factory = new RouterFactory();

        $registrar = $factory->fluent();
        $registrar->setAutoRegister(false);
        $registrar->get('/manual', static fn () => 'ok');

        self::assertCount(0, $factory->routes());
    }

    public function testAutoRegisterFlagPropagatesToNestedGroups(): void
    {
        $factory = new RouterFactory();

        $factory->fluent(autoRegister: false)->group(
            ['prefix' => '/api'],
            function ($reg): void {
                $reg->group(['prefix' => '/v1'], function ($reg2): void {
                    $reg2->get('/users', static fn () => 'ok');
                });
            },
        );

        self::assertCount(0, $factory->routes());
    }

    public function testNestedGroupsRegisterExplicitlyWhenAutoRegisterDisabled(): void
    {
        $factory = new RouterFactory();

        $factory->fluent(autoRegister: false)->group(
            ['prefix' => '/api'],
            function ($reg): void {
                $reg->group(['prefix' => '/v1'], function ($reg2): void {
                    $reg2->get('/users', static fn () => 'ok')->register();
                });
            },
        );

        self::assertCount(1, $factory->routes());
        self::assertSame('/api/v1/users', $factory->routes()[0]['path']);
    }

    public function testAutoRegisterCanBeDisabledPerDefinition(): void
    {
        $factory = new RouterFactory();

        $factory->fluent()->get('/deferred', static fn () => 'ok')->setAutoRegister(false);

        self::assertCount(0, $factory->routes());
    }

    public function testDoubleRegisterIsStillGuarded(): void
    {
        $factory = new RouterFactory();

        $definition = $factory->fluent(autoRegister: false)->get('/once', static fn () => 'ok');
        $definition->register();
        $definition->register();

        self::assertCount(1, $factory->routes());
    }
}
