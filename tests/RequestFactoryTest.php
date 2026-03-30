<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Exceptions\UntrustedHostException;
use Marwa\Router\Http\RequestFactory;
use PHPUnit\Framework\TestCase;

final class RequestFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestFactory::clearTrustedProxies();
        RequestFactory::clearTrustedHosts();
    }

    public function testTrustedProxyUsesForwardedHostSchemeAndClientIp(): void
    {
        RequestFactory::trustProxies(['10.0.0.1']);

        $request = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/dashboard',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'router.internal',
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_HOST' => 'tenant.example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 10.0.0.1',
            ],
        );

        self::assertSame('tenant.example.com', $request->getUri()->getHost());
        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('203.0.113.10', $request->getServerParams()['REMOTE_ADDR']);
    }

    public function testUntrustedProxyIgnoresForwardedHeaders(): void
    {
        $request = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/dashboard',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'router.internal',
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_HOST' => 'tenant.example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 10.0.0.1',
            ],
        );

        self::assertSame('router.internal', $request->getUri()->getHost());
        self::assertSame('http', $request->getUri()->getScheme());
        self::assertSame('10.0.0.1', $request->getServerParams()['REMOTE_ADDR']);
    }

    public function testTrustedHostsAllowKnownDomains(): void
    {
        RequestFactory::trustHosts(['example.com', '*.example.com']);

        $request = RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'tenant.example.com',
            ],
        );

        self::assertSame('tenant.example.com', $request->getUri()->getHost());
    }

    public function testTrustedHostsRejectUnknownDomains(): void
    {
        RequestFactory::trustHosts(['example.com', '*.example.com']);

        $this->expectException(UntrustedHostException::class);

        RequestFactory::fromArrays(
            server: [
                'REQUEST_URI' => '/',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'evil.test',
            ],
        );
    }
}
