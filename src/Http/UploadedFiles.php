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
     * @return array<string, mixed>
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

            $normalized[$field] = self::createUploadedFileFromSpec($factory, $spec);
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

                if (is_array($spec['tmp_name'])) {
                    $out[$idx] = self::normalizeNested($spec, $factory);
                    continue;
                }

                $out[$idx] = self::createUploadedFileFromSpec($factory, $spec);
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

    /** @param array<string, mixed> $spec */
    private static function createUploadedFileFromSpec(UploadedFileFactory $factory, array $spec): UploadedFileInterface
    {
        $stream = is_string($spec['tmp_name']) && $spec['tmp_name'] !== ''
            ? self::streamFromPath($spec['tmp_name'])
            : new \Laminas\Diactoros\Stream('php://temp', 'rb+');

        return $factory->createUploadedFile(
            stream: $stream,
            size: (int) $spec['size'],
            error: (int) $spec['error'],
            clientFilename: $spec['name'] !== '' ? (string) $spec['name'] : null,
            clientMediaType: $spec['type'] !== '' ? (string) $spec['type'] : null,
        );
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
