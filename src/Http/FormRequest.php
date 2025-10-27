<?php

declare(strict_types=1);

namespace Marwa\Router\Request;

use Marwa\Router\Contract\ValidatorInterface;
use Marwa\Router\Http\HttpRequest;
use Marwa\Router\Http\InputBag;
use Psr\Http\Message\ServerRequestInterface;

abstract class FormRequest
{
    private ServerRequestInterface $request;
    private ?ValidatorInterface $validator;
    /** @var array<string, mixed>|null */
    private ?array $validated = null;

    public function __construct(ServerRequestInterface $request, ?ValidatorInterface $validator = null)
    {
        $this->request   = $request;
        $this->validator = $validator;
    }

    /**
     * Laravel-style wrapper.
     */
    public function http(): HttpRequest
    {
        return new HttpRequest($this->request);
    }

    /**
     * Keep PSR-7 access too.
     */
    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * Define validation rules.
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    public function query(): InputBag
    {
        return new InputBag($this->request->getQueryParams());
    }

    public function body(): InputBag
    {
        $parsed = $this->request->getParsedBody();
        /** @var array<string, mixed> $safe */
        $safe = is_array($parsed) ? $parsed : [];
        return new InputBag($safe);
    }

    public function cookies(): InputBag
    {
        return new InputBag($this->request->getCookieParams());
    }

    /**
     * Run validation via injected validator (or passthrough if none).
     *
     * @return array<string, mixed>
     */
    public function validate(): array
    {
        if ($this->validated !== null) {
            return $this->validated;
        }

        if ($this->validator === null) {
            // Fallback: just merge query + body.
            $this->validated = array_merge(
                $this->request->getQueryParams(),
                is_array($this->request->getParsedBody()) ? $this->request->getParsedBody() : []
            );
            return $this->validated;
        }

        $this->validated = $this->validator->validate($this->request, $this->rules());
        return $this->validated;
    }
    public function authorize(): bool
    {
        return true;
    }
}
