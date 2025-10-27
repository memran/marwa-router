<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Minimal PSR-15 middleware: parses JSON and form bodies.
 * - application/json     => parsedBody = array
 * - application/x-www-form-urlencoded / multipart/form-data => let Diactoros handle in factories/globals
 */
final class BodyParsingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $type = $this->extractMediaType(
            $request->getHeaderLine('Content-Type')
        );

        if ($type === 'application/json') {
            $raw = (string)$request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \UnexpectedValueException('JSON payload must decode to an associative array.');
                }
                $request = $request->withParsedBody($decoded);
            } else {
                $request = $request->withParsedBody([]);
            }
        }

        return $handler->handle($request);
    }

    private function extractMediaType(string $contentType): string
    {
        if ($contentType === '') {
            return '';
        }
        $semi = strpos($contentType, ';');
        $type = $semi === false ? $contentType : substr($contentType, 0, $semi);
        return strtolower(trim($type));
    }
}
