<?php

declare(strict_types=1);

namespace Marwa\Router\Tests;

use Marwa\Router\Exceptions\FileNotFoundException;
use Marwa\Router\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testDownloadSanitizesFilenameInHeader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'marwa-router');
        self::assertNotFalse($path);
        file_put_contents($path, 'payload');

        try {
            $response = Response::download($path, "report\r\nX-Test: injected.txt");
            self::assertSame(
                'attachment; filename="reportX-Test: injected.txt"',
                $response->getHeaderLine('Content-Disposition'),
            );
        } finally {
            @unlink($path);
        }
    }

    public function testDownloadThrowsForMissingFile(): void
    {
        $this->expectException(FileNotFoundException::class);

        Response::download(__DIR__ . '/Fixtures/missing.file');
    }
}
