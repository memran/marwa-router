<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Response;
use PHPUnit\Framework\TestCase;

final class ResponseExtendedTest extends TestCase
{
    public function testCookieBasicNameValue(): void
    {
        $response = (new Response())
            ->cookie('session', 'abc123');

        $setCookie = $response->getResponse()->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('session=abc123', $setCookie);
    }

    public function testCookieWithExpiry(): void
    {
        $expires = time() + 3600;
        $response = (new Response())
            ->cookie('session', 'abc123', expires: $expires);

        $setCookie = $response->getResponse()->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('Expires=', $setCookie);
        self::assertStringContainsString('Max-Age=', $setCookie);
    }

    public function testCookieWithPathAndDomain(): void
    {
        $response = (new Response())
            ->cookie('session', 'abc123', path: '/api', domain: '.example.com');

        $setCookie = $response->getResponse()->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('Path=/api', $setCookie);
        self::assertStringContainsString('Domain=.example.com', $setCookie);
    }

    public function testCookieHttpOnlyByDefault(): void
    {
        $response = (new Response())
            ->cookie('session', 'abc123');

        $setCookie = $response->getResponse()->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('HttpOnly', $setCookie);
    }

    public function testCookieCanDisableHttpOnly(): void
    {
        $response = (new Response())
            ->cookie('session', 'abc123', httponly: false);

        $setCookie = $response->getResponse()->getHeaderLine('Set-Cookie');

        self::assertStringNotContainsString('HttpOnly', $setCookie);
    }

    public function testCookieSameSite(): void
    {
        $response = (new Response())
            ->cookie('session', 'abc123', samesite: 'Strict');

        $setCookie = $response->getResponse()->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('SameSite=Strict', $setCookie);
    }

    public function testCookieRejectsInvalidSameSite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Response())->cookie('session', 'abc123', samesite: 'Invalid');
    }

    public function testSuccessResponseStructure(): void
    {
        $response = Response::success(['id' => 1], 'Created', 201);

        self::assertSame(201, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('"success":true', $body);
        self::assertStringContainsString('"message":"Created"', $body);
    }

    public function testErrorResponseStructure(): void
    {
        $response = Response::error('Bad request', 400, ['field' => 'required']);

        self::assertSame(400, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('"success":false', $body);
        self::assertStringContainsString('"field":"required"', $body);
    }

    public function testConvenienceResponses(): void
    {
        self::assertSame(404, Response::notFound()->getStatusCode());
        self::assertSame(500, Response::serverError()->getStatusCode());
        self::assertSame(401, Response::unauthorized()->getStatusCode());
        self::assertSame(403, Response::forbidden()->getStatusCode());
        self::assertSame(201, Response::created()->getStatusCode());
        self::assertSame(204, Response::noContent()->getStatusCode());
    }

    public function testFromStringSerialization(): void
    {
        $response = (new Response())
            ->status(200)
            ->header('X-Custom', 'value')
            ->body('hello');

        $output = (string) $response;

        self::assertStringContainsString("HTTP/1.1 200 OK\r\n", $output);
        self::assertStringContainsString("X-Custom: value\r\n", $output);
        self::assertStringContainsString("\r\n\r\nhello", $output);
    }

    public function testBuilderChain(): void
    {
        $response = (new Response())
            ->status(201)
            ->header('X-First', 'a')
            ->addHeader('X-Second', 'b')
            ->body('done');

        $psr = $response->getResponse();

        self::assertSame(201, $psr->getStatusCode());
        self::assertSame('a', $psr->getHeaderLine('X-First'));
        self::assertSame('b', $psr->getHeaderLine('X-Second'));
        self::assertSame('done', (string) $psr->getBody());
    }

    public function testFromArrayAutoDetectsJson(): void
    {
        $response = Response::fromArray(['key' => 'value']);

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testFromArrayAutoDetectsHtml(): void
    {
        $response = Response::fromArray(['html' => '<p>Hello</p>'], headers: ['Content-Type' => 'text/html']);

        self::assertSame('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<p>Hello</p>', (string) $response->getBody());
    }
}
