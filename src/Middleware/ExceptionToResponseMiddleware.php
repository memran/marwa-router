<?php

declare(strict_types=1);

namespace Marwa\Router\Middleware;

use Marwa\Router\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class ExceptionToResponseMiddleware implements MiddlewareInterface
{
    /** @var array<class-string<Throwable>, int> */
    private array $statusMap;

    /**
     * @param array<class-string<Throwable>, int> $statusMap
     */
    public function __construct(
        array $statusMap = [
            \InvalidArgumentException::class => 400,
            \DomainException::class => 422,
        ],
        private bool $exposeMessages = false,
        private ?LoggerInterface $logger = null,
    ) {
        $this->statusMap = $statusMap;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            $status = $this->resolveStatus($exception);
            $message = $this->exposeMessages && $exception->getMessage() !== ''
                ? $exception->getMessage()
                : ($status >= 500 ? 'Internal server error' : 'Request failed');

            $this->logger?->error('Unhandled exception converted to response.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'status' => $status,
                'path' => $request->getUri()->getPath(),
                'method' => $request->getMethod(),
            ]);

            return Response::error($message, $status);
        }
    }

    private function resolveStatus(Throwable $exception): int
    {
        foreach ($this->statusMap as $class => $status) {
            if ($exception instanceof $class) {
                return $status;
            }
        }

        return 500;
    }
}
