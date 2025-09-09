<?php

declare(strict_types=1);

namespace Marwa\Router\Strategy;

use League\Route\Http\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets a strategy accept a custom Not Found renderer from app code.
 *
 * Dev can provide:
 *   - RequestHandlerInterface
 *   - callable(ServerRequestInterface $req, NotFoundException $e): ResponseInterface|array|string
 *
 * The strategy decides how to wrap array|string into its content type.
 */
trait CustomNotFoundTrait
{
    /** @var callable|RequestHandlerInterface|null */
    private $notFoundHandler = null;

    /**
     * Strategy-side setter; return $this to allow chaining in factory helpers.
     *
     * @param callable|RequestHandlerInterface $handler
     */
    public function setNotFoundHandler($handler): self
    {
        $this->notFoundHandler = $handler;
        return $this;
    }

    /**
     * Call the custom 404 handler if present.
     * Return:
     *  - ResponseInterface to use directly
     *  - array|string to be wrapped by the strategy
     *  - null if no custom handler set
     */
    public function maybeHandleNotFound(
        ServerRequestInterface $request,
        NotFoundException $e
    ): ResponseInterface|array|string|null {
        if ($this->notFoundHandler === null) {
            return null;
        }

        if ($this->notFoundHandler instanceof RequestHandlerInterface) {
            return $this->notFoundHandler->handle($request);
        }

        /** @var callable $cb */
        $cb = $this->notFoundHandler;
        return $cb($request, $e);
    }
}
