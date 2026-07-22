<?php

declare(strict_types=1);

namespace Marwa\Router\Http;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Marwa\Router\Exceptions\UntrustedHostException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Single-responsibility: build PSR-7 ServerRequest instances.
 */
final class RequestFactory
{
    /** @var list<string> */
    private static array $trustedProxies = [];
    /** @var list<string> */
    private static array $trustedHosts = [];

    /**
     * Create from PHP superglobals.
     */
    public static function fromGlobals(): ServerRequestInterface
    {
        return self::fromArrays($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES);
    }

    /**
     * Trust the given proxy IPs or CIDR ranges for forwarded headers.
     *
     * @param list<string> $proxies
     */
    public static function trustProxies(array $proxies): void
    {
        self::$trustedProxies = array_values(array_filter(
            array_map(static fn (string $proxy): string => trim($proxy), $proxies),
            static fn (string $proxy): bool => $proxy !== '',
        ));
    }

    public static function clearTrustedProxies(): void
    {
        self::$trustedProxies = [];
    }

    /**
     * Trust the given host patterns.
     *
     * Supported patterns:
     * - example.com
     * - *.example.com
     *
     * @param list<string> $hosts
     */
    public static function trustHosts(array $hosts): void
    {
        self::$trustedHosts = array_values(array_filter(
            array_map(static fn (string $host): string => strtolower(trim($host)), $hosts),
            static fn (string $host): bool => $host !== '',
        ));
    }

    public static function clearTrustedHosts(): void
    {
        self::$trustedHosts = [];
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
        array $files = [],
    ): ServerRequestInterface {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uriStr = (string) ($server['REQUEST_URI'] ?? '/');
        $scheme = self::resolveScheme($server);
        $host = self::resolveHost($server);
        $remoteAddr = self::resolveRemoteAddr($server);

        self::assertTrustedHost($host);

        if ($remoteAddr !== null) {
            $server['REMOTE_ADDR'] = $remoteAddr;
        }

        $uri = new Uri($scheme . '://' . $host . $uriStr);

        $request = new ServerRequest(
            $server,
            UploadedFiles::normalize($files),
            $uri,
            $method,
            'php://input',
            self::extractHeaders($server),
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
     * @return array<non-empty-string, string>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name  = str_replace('_', '-', strtolower(substr($key, 5)));
                $parts = array_map('ucfirst', explode('-', $name));
                $norm  = implode('-', $parts);
                $headers[$norm] = (string) $value;
            }

            // Content-* headers are not prefixed with HTTP_
            if ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = (string) $value;
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function resolveScheme(array $server): string
    {
        if (self::isTrustedProxyServer($server)) {
            $forwardedProto = self::firstForwardedValue((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($forwardedProto !== null) {
                $normalized = strtolower($forwardedProto);
                if ($normalized === 'http' || $normalized === 'https') {
                    return $normalized;
                }
            }
        }

        return (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function resolveHost(array $server): string
    {
        if (self::isTrustedProxyServer($server)) {
            $forwardedHost = self::firstForwardedValue((string) ($server['HTTP_X_FORWARDED_HOST'] ?? ''));
            if ($forwardedHost !== null) {
                return $forwardedHost;
            }
        }

        return (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function resolveRemoteAddr(array $server): ?string
    {
        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');

        if (!self::isTrustedProxyServer($server)) {
            return $remoteAddr !== '' ? $remoteAddr : null;
        }

        // Walk X-Forwarded-For from right (closest proxy) to left, skipping
        // trusted proxies. The first untrusted IP is the real client. The
        // leftmost value is client-controlled and must never be trusted
        // blindly, otherwise clients can spoof their IP.
        $forwardedFor = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwardedFor !== '') {
            $ips = [];
            foreach (explode(',', $forwardedFor) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $candidate;
                }
            }

            for ($i = count($ips) - 1; $i >= 0; $i--) {
                if (!self::isTrustedProxy($ips[$i])) {
                    return $ips[$i];
                }
            }

            // Every hop is a trusted proxy: the leftmost is the original
            // client as reported by the outermost trusted proxy.
            if ($ips !== []) {
                return $ips[0];
            }
        }

        return $remoteAddr !== '' ? $remoteAddr : null;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function isTrustedProxyServer(array $server): bool
    {
        if (self::$trustedProxies === []) {
            return false;
        }

        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');

        return $remoteAddr !== '' && self::isTrustedProxy($remoteAddr);
    }

    private static function isTrustedProxy(string $ip): bool
    {
        foreach (self::$trustedProxies as $proxy) {
            if (self::matchesProxy($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesProxy(string $ip, string $proxy): bool
    {
        if ($proxy === $ip) {
            return true;
        }

        if (!str_contains($proxy, '/')) {
            return false;
        }

        [$network, $prefix] = explode('/', $proxy, 2);
        if ($network === '' || $prefix === '' || !ctype_digit($prefix)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);

        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $maxBits = strlen($networkBin) * 8;
        if ($prefixLength < 0 || $prefixLength > $maxBits) {
            return false;
        }

        $bytes = intdiv($prefixLength, 8);
        $bits = $prefixLength % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($networkBin[$bytes]) & $mask);
    }

    private static function firstForwardedValue(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $value));
        $candidate = $parts[0];

        return $candidate !== '' ? $candidate : null;
    }

    private static function assertTrustedHost(string $host): void
    {
        if (self::$trustedHosts === []) {
            return;
        }

        $normalizedHost = self::stripPort(strtolower(trim($host)));
        if ($normalizedHost === '') {
            throw new UntrustedHostException('Request host is empty.');
        }

        foreach (self::$trustedHosts as $pattern) {
            if (self::hostMatches($normalizedHost, $pattern)) {
                return;
            }
        }

        throw new UntrustedHostException(sprintf('Untrusted request host: %s', $host));
    }

    /**
     * Strip an optional port suffix ("example.com:8080", "[::1]:8080").
     */
    private static function stripPort(string $host): string
    {
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 0, $end + 1);
        }

        $colon = strpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }

    private static function hostMatches(string $host, string $pattern): bool
    {
        if ($pattern === $host) {
            return true;
        }

        if (!str_starts_with($pattern, '*.')) {
            return false;
        }

        $suffix = substr($pattern, 1);

        return $suffix !== '' && str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.');
    }
}
