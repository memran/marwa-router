<?php

declare(strict_types=1);

namespace Marwa\Router\Http;

use Laminas\Diactoros\ServerRequestFactory as DiactorosFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Single-responsibility: build PSR-7 ServerRequest instances.
 */
final class RequestFactory
{
    /**
     * Create from PHP superglobals.
     */
    public static function fromGlobals(): ServerRequestInterface
    {
        return DiactorosFactory::fromGlobals();
    }

    /**
     * Create from arrays (useful for tests or custom runtimes).
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files in $_FILES shape
     */
    public static function fromArrays(
        array $server = [],
        array $query = [],
        array $parsedBody = [],
        array $cookies = [],
        array $files = []
    ): ServerRequestInterface {
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        $uriStr = (string)($server['REQUEST_URI'] ?? '/');
        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');

        $uri = new Uri($scheme . '://' . $host . $uriStr);

        $request = new ServerRequest(
            $server,
            UploadedFiles::normalize($files),
            $uri,
            $method,
            'php://input',
            self::extractHeaders($server)
        );

        $request = $request
            ->withQueryParams($query)
            ->withCookieParams($cookies);

        if ($parsedBody !== []) {
            $request = $request->withParsedBody($parsedBody);
        }

        return $request;
    }

    /**
     * Extract headers from $_SERVER-like array (DRY util).
     *
     * @param array<string, mixed> $server
     * @return array<string, array<int, string>>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name  = str_replace('_', '-', strtolower(substr($key, 5)));
                $parts = array_map('ucfirst', explode('-', $name));
                $norm  = implode('-', $parts);
                $headers[$norm] = [(string)$value];
            }

            // Content-* headers are not prefixed with HTTP_
            if ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = [(string)$value];
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = [(string)$value];
            }
        }

        return $headers;
    }
}
