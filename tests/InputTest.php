<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Http\HttpRequest;
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

    public function testInputExposesHostAndSubdomain(): void
    {
        $request = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/dashboard',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'tenant.example.com',
            ],
        );

        Input::setRequest($request);

        self::assertSame('tenant.example.com', Input::host());
        self::assertSame('tenant', Input::subdomain());
        self::assertSame('tenant', Input::subdomainFor('example.com'));
    }

    public function testSubdomainReturnsNullForRootDomainAndIpHosts(): void
    {
        $rootDomainRequest = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'example.com',
            ],
        );

        $ipRequest = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => '127.0.0.1:8080',
            ],
        );

        self::assertNull((new HttpRequest($rootDomainRequest))->subdomain());
        self::assertNull((new HttpRequest($ipRequest))->subdomain());
        self::assertNull((new HttpRequest($rootDomainRequest))->subdomainFor('example.com'));
        self::assertNull((new HttpRequest($ipRequest))->subdomainFor('127.0.0.1'));
    }

    public function testSubdomainForSupportsConfiguredBaseDomain(): void
    {
        $request = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'admin.eu.example.co.uk',
            ],
        );

        $input = new HttpRequest($request);

        self::assertSame('admin.eu', $input->subdomainFor('example.co.uk'));
        self::assertNull($input->subdomainFor('example.com'));
    }
}
