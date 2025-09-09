<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Discovers PHP files under directories and requires them so Reflection can see classes.
 * - Skips non-existing or non-readable directories by default.
 * - Optional strict mode to throw if a directory is missing/unreadable.
 *
 * SECURITY: Only use on trusted application code.
 */
final class ClassLocator
{
    /**
     * Require all PHP files from the given directories.
     *
     * @param string[] $paths
     * @param bool $strict If true, throws \UnexpectedValueException on missing/unreadable dirs.
     */
    public static function requirePhpFiles(array $paths, bool $strict = false): void
    {

        foreach ($paths as $dir) {
            $dir = rtrim($dir, "\\/");

            if (!is_dir($dir)) {
                if ($strict) {
                    throw new \UnexpectedValueException("Directory does not exist: {$dir}");
                }
                // Soft skip
                continue;
            }
            if (!is_readable($dir)) {
                if ($strict) {
                    throw new \UnexpectedValueException("Directory is not readable: {$dir}");
                }
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                ));
                $files = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

                foreach ($files as $match) {
                    $file = $match[0] ?? null;
                    if ($file && is_file($file)) {
                        require_once $file;
                    }
                }
            } catch (\Throwable $e) {
                if ($strict) {
                    // Re-throw with path context for clearer debugging
                    throw new \UnexpectedValueException("Failed to scan directory: {$dir}. " . $e->getMessage(), 0, $e);
                }
                // Non-strict: skip this directory silently
                continue;
            }
        }
    }

    /**
     * Return classes declared AFTER running the loader (delta).
     *
     * @param callable $loader Function that loads files (e.g., requirePhpFiles)
     * @return array<class-string>
     */
    public static function loadAndCollectClasses(callable $loader): array
    {
        $before = get_declared_classes();
        $loader();
        $after  = get_declared_classes();
        $new    = array_values(array_diff($after, $before));

        // Filter only existing classes (defensive)
        return array_values(array_filter($new, static fn(string $c) => class_exists($c)));
    }
}
