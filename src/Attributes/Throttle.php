<?php

declare(strict_types=1);

namespace Marwa\Router\Attributes;

use Attribute;

/**
 * Simple rate-limiting sugar; handled by ThrottleMiddleware.
 * $key: "ip" (default) or a header name (e.g., "X-API-Key").
 *
 * Example: #[Throttle(100, 60, 'ip')]  // 100 requests per 60s per IP
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Throttle
{
    public function __construct(
        public int $limit,             // allowed requests
        public int $perSeconds = 60,   // window in seconds
        public string $key = 'ip'      // "ip" or header name
    ) {}
}
