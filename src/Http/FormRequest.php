<?php

declare(strict_types=1);

namespace Marwa\Router\Http;

use Marwa\Router\Contract\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class FormRequest
{
    private ServerRequestInterface $request;
    private ?ValidatorInterface $validator;
    /** @var array<string, mixed>|null */
    private ?array $validated = null;
    private ?HttpRequest $httpInstance = null;
    private ?InputBag $queryBag = null;
    private ?InputBag $bodyBag = null;
    private ?InputBag $cookieBag = null;

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
        return $this->httpInstance ??= new HttpRequest($this->request);
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
        return $this->queryBag ??= new InputBag($this->request->getQueryParams());
    }

    public function body(): InputBag
    {
        if ($this->bodyBag === null) {
            $parsed = $this->request->getParsedBody();
            /** @var array<string, mixed> $safe */
            $safe = is_array($parsed) ? $parsed : [];
            $this->bodyBag = new InputBag($safe);
        }
        return $this->bodyBag;
    }

    public function cookies(): InputBag
    {
        return $this->cookieBag ??= new InputBag($this->request->getCookieParams());
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
            // Fallback: merge query + body, filtered by rules() keys to prevent mass assignment.
            $all = array_merge(
                $this->request->getQueryParams(),
                is_array($this->request->getParsedBody()) ? $this->request->getParsedBody() : [],
            );
            $rules = $this->rules();
            if ($rules !== []) {
                $all = array_intersect_key($all, array_flip(array_keys($rules)));
            }
            $this->validated = $all;
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
