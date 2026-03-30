<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Http\Input;
use Marwa\Router\Http\RequestFactory;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    protected function tearDown(): void
    {
        Input::reset();
    }

    public function testHasAndExistsSupportDotNotation(): void
    {
        $request = RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/users', 'REQUEST_METHOD' => 'POST'],
            parsedBody: ['user' => ['email' => 'dev@example.com']],
        );

        Input::setRequest($request);

        self::assertTrue(Input::has('user.email'));
        self::assertTrue(Input::exists('user.email'));
        self::assertFalse(Input::has('user.name'));
    }

    public function testMergeRebindsRequestWithMergedParsedBody(): void
    {
        $request = RequestFactory::fromArrays(
            server: ['REQUEST_URI' => '/users', 'REQUEST_METHOD' => 'POST'],
            parsedBody: ['role' => 'user'],
        );

        Input::setRequest($request);
        Input::merge(['active' => true]);

        self::assertSame(['role' => 'user', 'active' => true], Input::all());
    }
}
