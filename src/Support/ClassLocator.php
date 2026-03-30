<?php

declare(strict_types=1);

namespace Marwa\Router\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RegexIterator;

final class ClassLocator
{
    /**
     * Require all PHP files under the given directories (recursively).
     *
     * @param string[] $directories
     * @return string[] list of required file paths (realpath)
     */
    public static function requirePhpFiles(array $directories, bool $strict = false): array
    {
        $loaded = [];

        foreach ($directories as $dir) {
            $dir = self::normalizeDir($dir);
            //check directory exists or not
            if (!is_dir($dir)) {
                if ($strict) {
                    throw new \UnexpectedValueException("Directory not found: {$dir}");
                }
                continue;
            }

            $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            $rit = new RegexIterator($it, '/^.+\.php$/i', RegexIterator::GET_MATCH);

            foreach ($rit as $match) {
                $file = $match[0];
                // Skip non-regular files (symlinks etc. are fine)
                if (is_file($file)) {
                    require_once $file;
                    $loaded[] = realpath($file) ?: $file;
                    //continue;
                }
            }
        }

        return $loaded;
    }

    /**
     * @param string[] $paths
     * @return array<class-string>
     */
    private static function pathsToCallableClasses(array $paths): array
    {
        $classes = [];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            preg_match('/namespace\s+([^;]+);/', $content, $matches);

            $namespace = $matches[1] ?? '';
            $className = pathinfo($path, PATHINFO_FILENAME);
            $fqcn = $namespace ? $namespace . '\\' . $className : $className;

            if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Run a loader (e.g., requirePhpFiles) and return the fully-qualified class
     * names that were declared by those loads. Optionally restrict to classes
     * whose source filename lives under $limitToDirs (Windows-safe).
     *
     * @param callable(): (string[]|void) $loader
     * @param string[]|null               $limitToDirs
     * @return array<class-string>
     */
    public static function loadAndCollectClasses(callable $loader, ?array $limitToDirs = null): array
    {
        $before = get_declared_classes();
        $loaderReturn = $loader();
        $after = get_declared_classes();
        $new = array_values(array_diff($after, $before));

        if ($new === []) {
            $new = self::pathsToCallableClasses(is_array($loaderReturn) ? $loaderReturn : []);
        }

        if ($new === []) {
            return [];
        }

        if ($limitToDirs === null || $limitToDirs === []) {
            return array_values(array_unique($new));
        }

        // Normalize directories for case-insensitive comparison on Windows
        $normDirs = array_map([self::class, 'normalizeDir'], $limitToDirs);
        $isWindows = \DIRECTORY_SEPARATOR === '\\';

        // Prepare lowercase dirs for Windows
        $cmpDirs = $isWindows ? array_map('strtolower', $normDirs) : $normDirs;

        $kept = [];
        foreach ($new as $fqcn) {
            $rc = new ReflectionClass($fqcn);
            if (!$rc->isUserDefined()) {
                continue;
            }
            $file = $rc->getFileName();
            if (!$file) {
                continue;
            }

            $fileReal = realpath($file) ?: $file;
            $fileNorm = self::normalizePath($fileReal);
            $cmpFile  = $isWindows ? strtolower($fileNorm) : $fileNorm;

            foreach ($cmpDirs as $dir) {
                // include trailing separator to avoid partial prefix matches
                $dirWithSep = rtrim($dir, '/\\') . '/';
                if (str_starts_with($cmpFile, $dirWithSep)) {
                    $kept[] = $fqcn;
                    break;
                }
            }
        }

        return array_values(array_unique($kept));
    }

    // -------------------- helpers --------------------

    private static function normalizeDir(string $path): string
    {
        $real = realpath($path) ?: $path;
        $norm = self::normalizePath($real);
        return rtrim($norm, '/\\');
    }

    private static function normalizePath(string $path): string
    {
        // Use forward slashes internally
        $path = str_replace('\\', '/', $path);
        // On Windows the FS is case-insensitive, but we keep original case here;
        // comparisons lower-case both sides when needed.
        return $path;
    }
}
