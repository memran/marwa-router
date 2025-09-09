<?php

declare(strict_types=1);

namespace Marwa\Router\Strategy;

use JsonSerializable;
use League\Route\Http;
use League\Route\Http\Exception\{MethodNotAllowedException, NotFoundException};
use League\Route\Route;
use League\Route\Strategy\AbstractStrategy;
use League\Route\Strategy\OptionsHandlerInterface;
use Marwa\Router\Strategy\CustomNotFoundTrait;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Throwable;
use League\Route\{ContainerAwareInterface, ContainerAwareTrait};

/**
 * JSON-only strategy:
 * - 404/405/500 emitted as JSON
 * - Controller return values:
 *     • ResponseInterface -> passed through
 *     • array/object/JsonSerializable -> JSON-encoded
 *     • string/scalar -> {"data": ...}
 * - Optional custom 404 via setNotFoundHandler() (callable or RequestHandlerInterface)
 */
final class JsonStrategy extends AbstractStrategy implements OptionsHandlerInterface, ContainerAwareInterface
{
    use CustomNotFoundTrait, ContainerAwareTrait; // provides setNotFoundHandler() + maybeHandleNotFound()

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private int $jsonFlags = 0
    ) {
        // Ensure JSON content-type if none was set by downstream
        $this->addResponseDecorator(static function (ResponseInterface $response): ResponseInterface {
            if (!$response->hasHeader('content-type')) {
                $response = $response->withHeader('content-type', 'application/json');
            }
            return $response;
        });
    }

    // -------------------- Strategy hooks --------------------

    public function getNotFoundDecorator(NotFoundException $exception): MiddlewareInterface
    {
        $self = $this;

        return new class($this->responseFactory, $self, $exception, $this->jsonFlags) implements MiddlewareInterface {
            public function __construct(
                private ResponseFactoryInterface $rf,
                private JsonStrategy $strategy,
                private NotFoundException $e,
                private int $flags
            ) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                // Let developer-supplied 404 run first (if provided)
                $custom = $this->strategy->maybeHandleNotFound($request, $this->e);
                if ($custom instanceof ResponseInterface) {
                    return $custom;
                }

                $payload = match (true) {
                    is_array($custom), is_object($custom), $custom instanceof JsonSerializable => $custom,
                    is_string($custom), is_scalar($custom) => ['message' => (string)$custom],
                    default => [
                        'status_code' => 404,
                        'error'       => 'Not Found',
                        'path'        => $request->getUri()->getPath(),
                    ],
                };

                $resp = $this->rf->createResponse(404)->withHeader('content-type', 'application/json');
                $resp->getBody()->write(json_encode($payload, $this->flags) ?: 'null');
                return $resp;
            }
        };
    }

    public function getMethodNotAllowedDecorator(MethodNotAllowedException $exception): MiddlewareInterface
    {
        $allowed = implode(', ', $exception->getAllowedMethods());

        return new class($this->responseFactory, $allowed, $this->jsonFlags) implements MiddlewareInterface {
            public function __construct(
                private ResponseFactoryInterface $rf,
                private string $allowed,
                private int $flags
            ) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $payload = [
                    'status_code' => 405,
                    'error'       => 'Method Not Allowed',
                    'allowed'     => $this->allowed,
                ];

                $resp = $this->rf->createResponse(405)
                    ->withHeader('content-type', 'application/json')
                    ->withHeader('allow', $this->allowed);

                $resp->getBody()->write(json_encode($payload, $this->flags) ?: 'null');
                return $resp;
            }
        };
    }

    public function getThrowableHandler(): MiddlewareInterface
    {
        return new class($this->responseFactory, $this->jsonFlags) implements MiddlewareInterface {
            public function __construct(
                private ResponseFactoryInterface $rf,
                private int $flags
            ) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                try {
                    return $handler->handle($request);
                } catch (Throwable $e) {
                    $status  = $e instanceof Http\Exception ? $e->getStatusCode() : 500;
                    $message = $e->getMessage() ?: ($status === 500 ? 'Internal Server Error' : 'Error');

                    $payload = [
                        'status_code' => $status,
                        'error'       => $message,
                    ];

                    $resp = $this->rf->createResponse($status)->withHeader('content-type', 'application/json');
                    $resp->getBody()->write(json_encode($payload, $this->flags) ?: 'null');
                    return $resp;
                }
            }
        };
    }

    public function getOptionsCallable(array $methods): callable
    {
        return function () use ($methods): ResponseInterface {
            $options  = implode(', ', $methods);
            return $this->responseFactory->createResponse()
                ->withHeader('allow', $options)
                ->withHeader('access-control-allow-methods', $options);
        };
    }

    public function invokeRouteCallable(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        $controller = $route->getCallable($this->getContainer());
        $result     = $controller($request, $route->getVars());

        if ($result instanceof ResponseInterface) {
            return $this->decorateResponse($result);
        }

        $payload = $this->normalizePayload($result);
        $resp = $this->responseFactory->createResponse()
            ->withHeader('content-type', 'application/json');

        $resp->getBody()->write(json_encode($payload, $this->jsonFlags) ?: 'null');
        return $this->decorateResponse($resp);
    }

    // -------------------- Helpers --------------------

    private function normalizePayload(mixed $value): array|JsonSerializable
    {
        if ($value instanceof JsonSerializable || is_array($value) || is_object($value)) {
            return $value;
        }
        // string/scalar/null -> wrap
        return ['data' => $value];
    }
}
