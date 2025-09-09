<?php

declare(strict_types=1);

namespace Marwa\Router\Strategy;

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

final class HtmlStrategy extends AbstractStrategy implements OptionsHandlerInterface, ContainerAwareInterface
{
    use CustomNotFoundTrait, ContainerAwareTrait;

    public function __construct(private ResponseFactoryInterface $responseFactory) {}

    public function getNotFoundDecorator(NotFoundException $exception): MiddlewareInterface
    {
        // capture $this inside an anonymous class
        $self = $this;

        return new class($this->responseFactory, $self, $exception) implements MiddlewareInterface {
            public function __construct(
                private ResponseFactoryInterface $rf,
                private HtmlStrategy $strategy,
                private NotFoundException $e
            ) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                // 1) Let app-provided handler run first (if any)
                $custom = $this->strategy->maybeHandleNotFound($request, $this->e);
                if ($custom instanceof ResponseInterface) {
                    return $custom;
                }
                if (is_string($custom)) {
                    return $this->html(404, $custom);
                }
                if (is_array($custom) || is_object($custom)) {
                    $html = '<!doctype html><meta charset="utf-8"><pre>'
                        . htmlspecialchars(print_r($custom, true))
                        . '</pre>';
                    return $this->html(404, $html);
                }

                // 2) Default HTML 404
                $html = '<!doctype html><meta charset="utf-8">'
                    . '<title>404 Not Found</title>'
                    . '<h1>404 Not Found</h1>'
                    . '<p>' . htmlspecialchars($request->getUri()->getPath()) . '</p>';
                return $this->html(404, $html);
            }

            private function html(int $status, string $body): ResponseInterface
            {
                $resp = $this->rf->createResponse($status)->withHeader('content-type', 'text/html; charset=utf-8');
                $resp->getBody()->write($body);
                return $resp;
            }
        };
    }

    public function getMethodNotAllowedDecorator(MethodNotAllowedException $exception): MiddlewareInterface
    {
        $allowed = implode(', ', $exception->getAllowedMethods());

        return new class($this->responseFactory, $allowed) implements MiddlewareInterface {
            public function __construct(private ResponseFactoryInterface $rf, private string $allowed) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $html = '<!doctype html><meta charset="utf-8">'
                    . '<title>405 Method Not Allowed</title>'
                    . '<h1>405 Method Not Allowed</h1>'
                    . '<p>Allowed: ' . htmlspecialchars($this->allowed) . '</p>';
                $resp = $this->rf->createResponse(405)
                    ->withHeader('content-type', 'text/html; charset=utf-8')
                    ->withHeader('allow', $this->allowed);
                $resp->getBody()->write($html);
                return $resp;
            }
        };
    }

    public function getThrowableHandler(): MiddlewareInterface
    {
        return new class($this->responseFactory) implements MiddlewareInterface {
            public function __construct(private ResponseFactoryInterface $rf) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                try {
                    return $handler->handle($request);
                } catch (Throwable $e) {
                    $status  = $e instanceof Http\Exception ? $e->getStatusCode() : 500;
                    $message = $e->getMessage() ?: 'Internal Server Error';
                    $html = '<!doctype html><meta charset="utf-8">'
                        . '<title>Error</title>'
                        . '<h1>' . htmlspecialchars((string)$status) . ' Error</h1>'
                        . '<pre>' . htmlspecialchars($message) . '</pre>';
                    $resp = $this->rf->createResponse($status)->withHeader('content-type', 'text/html; charset=utf-8');
                    $resp->getBody()->write($html);
                    return $resp;
                }
            }
        };
    }

    public function getOptionsCallable(array $methods): callable
    {
        return function () use ($methods): ResponseInterface {
            $opts  = implode(', ', $methods);
            return $this->responseFactory->createResponse()
                ->withHeader('allow', $opts)
                ->withHeader('access-control-allow-methods', $opts);
        };
    }

    public function invokeRouteCallable(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        $controller = $route->getCallable($this->getContainer());
        $result     = $controller($request, $route->getVars());

        if ($result instanceof ResponseInterface) {
            return $this->decorateResponse($result);
        }

        $html = is_string($result)
            ? $result
            : '<!doctype html><meta charset="utf-8"><pre>' . htmlspecialchars(print_r($result, true)) . '</pre>';

        $resp = $this->responseFactory->createResponse()
            ->withHeader('content-type', 'text/html; charset=utf-8');
        $resp->getBody()->write($html);
        return $this->decorateResponse($resp);
    }
}
