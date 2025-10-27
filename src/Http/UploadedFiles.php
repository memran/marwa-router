<?php

declare(strict_types=1);

namespace Marwa\Router\Http;

use Laminas\Diactoros\UploadedFileFactory;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Single-responsibility: normalize $_FILES to PSR-7 UploadedFileInterface[].
 */
final class UploadedFiles
{
    /**
     * @param array<string, mixed> $files
     * @return array<string, UploadedFileInterface|array>
     */
    public static function normalize(array $files): array
    {
        $factory = new UploadedFileFactory();
        $normalized = [];

        foreach ($files as $field => $spec) {
            if (!is_array($spec) || !isset($spec['tmp_name'])) {
                // Nested structure (e.g., arrays of files)
                $normalized[$field] = self::normalizeNested($spec, $factory);
                continue;
            }

            $normalized[$field] = $factory->createUploadedFile(
                stream: is_string($spec['tmp_name']) && $spec['tmp_name'] !== '' ? self::streamFromPath($spec['tmp_name']) : null,
                size: isset($spec['size']) ? (int)$spec['size'] : null,
                error: isset($spec['error']) ? (int)$spec['error'] : \UPLOAD_ERR_OK,
                clientFilename: isset($spec['name']) ? (string)$spec['name'] : null,
                clientMediaType: isset($spec['type']) ? (string)$spec['type'] : null
            );
        }

        return $normalized;
    }

    /**
     * @param mixed $node
     * @return array<int|string, mixed>
     */
    private static function normalizeNested(mixed $node, UploadedFileFactory $factory): array
    {
        if (!is_array($node)) {
            return [];
        }

        // Rebuild tree for each "index" of the parallel arrays
        if (isset($node['tmp_name']) && is_array($node['tmp_name'])) {
            $out = [];
            foreach ($node['tmp_name'] as $idx => $_) {
                $spec = [
                    'tmp_name' => $node['tmp_name'][$idx] ?? '',
                    'size'     => $node['size'][$idx] ?? 0,
                    'error'    => $node['error'][$idx] ?? \UPLOAD_ERR_NO_FILE,
                    'name'     => $node['name'][$idx] ?? '',
                    'type'     => $node['type'][$idx] ?? '',
                ];

                $out[$idx] = $factory->createUploadedFile(
                    stream: is_string($spec['tmp_name']) && $spec['tmp_name'] !== '' ? self::streamFromPath($spec['tmp_name']) : null,
                    size: isset($spec['size']) ? (int)$spec['size'] : null,
                    error: isset($spec['error']) ? (int)$spec['error'] : \UPLOAD_ERR_OK,
                    clientFilename: isset($spec['name']) ? (string)$spec['name'] : null,
                    clientMediaType: isset($spec['type']) ? (string)$spec['type'] : null
                );
            }
            return $out;
        }

        // Recursively walk
        $out = [];
        foreach ($node as $k => $v) {
            $out[$k] = self::normalizeNested($v, $factory);
        }
        return $out;
    }

    private static function streamFromPath(string $path): \Psr\Http\Message\StreamInterface
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open uploaded file stream: ' . $path);
        }
        return new \Laminas\Diactoros\Stream($stream);
    }
}
