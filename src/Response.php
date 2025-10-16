<?php

declare(strict_types=1);

namespace Marwa\Router;

use Laminas\Diactoros\Response as HttpResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use JsonSerializable;

final class Response
{
    protected ResponseInterface $response;

    /**
     * Constructor
     */
    public function __construct(?ResponseInterface $response = null)
    {
        $this->response = $response ?? new HttpResponse();
    }

    /**
     * Create JSON response
     */
    public static function json(array|JsonSerializable $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Create HTML response
     */
    public static function html(string $html, int $status = 200, array $headers = []): ResponseInterface
    {
        return  new HtmlResponse($html, $status, $headers);
    }

    /**
     * Create text response
     */
    public static function text(string $text, int $status = 200, array $headers = []): ResponseInterface
    {
        return  new TextResponse($text, $status, $headers);

    }

    /**
     * Create empty response
     */
    public static function empty(int $status = 204, array $headers = []): ResponseInterface
    {
        return  new EmptyResponse($status, $headers);
    }

    /**
     * Create redirect response
     */
    public static function redirect(string $uri, int $status = 302, array $headers = []): ResponseInterface
    {
        return  new RedirectResponse($uri, $status, $headers);
    }

    /**
     * Create file download response
     */
    public static function download(string $filePath, ?string $filename = null, array $headers = []): ResponseInterface
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $filename = $filename ?? basename($filePath);
        $stream = new Stream($filePath, 'r');

        $defaultHeaders = [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) filesize($filePath),
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $headers = array_merge($defaultHeaders, $headers);

       return new HttpResponse($stream, 200, $headers);

    }

    /**
     * Set response status code
     */
    public function status(int $status): self
    {
        $this->response = $this->response->withStatus($status);
        return $this;
    }

    /**
     * Set response header
     */
    public function header(string $name, string $value): self
    {
        $this->response = $this->response->withHeader($name, $value);
        return $this;
    }

    /**
     * Add response header (without replacing existing)
     */
    public function addHeader(string $name, string $value): self
    {
        $this->response = $this->response->withAddedHeader($name, $value);
        return $this;
    }

    /**
     * Set multiple headers at once
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->response = $this->response->withHeader($name, $value);
        }
        return $this;
    }

    /**
     * Set response body
     */
    public function body(string $content): self
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($content);
        $stream->rewind();

        $this->response = $this->response->withBody($stream);
        return $this;
    }

    /**
     * Set JSON response with success structure
     */
    public static function success(array $data = [], string $message = 'Success', int $status = 200): ResponseInterface
    {
        $responseData = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ];

        return self::json($responseData, $status);
    }

    /**
     * Set JSON response with error structure
     */
    public static function error(string $message = 'Error', int $status = 400, array $errors = []): ResponseInterface
    {
        $responseData = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => time(),
        ];

        return self::json($responseData, $status);
    }

    /**
     * Create 404 Not Found response
     */
    public static function notFound(string $message = 'Resource not found'): ResponseInterface
    {
        return self::error($message, 404);
    }

    /**
     * Create 500 Internal Server Error response
     */
    public static function serverError(string $message = 'Internal server error'): ResponseInterface
    {
        return self::error($message, 500);
    }

    /**
     * Create 401 Unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): ResponseInterface
    {
        return self::error($message, 401);
    }

    /**
     * Create 403 Forbidden response
     */
    public static function forbidden(string $message = 'Forbidden'): ResponseInterface
    {
        return self::error($message, 403);
    }

    /**
     * Create 201 Created response
     */
    public static function created(array $data = [], string $message = 'Resource created'): ResponseInterface
    {
        return self::success($data, $message, 201);
    }

    /**
     * Create 204 No Content response
     */
    public static function noContent(): ResponseInterface
    {
        return self::empty(204);
    }

    /**
     * Set cookie
     */
    public function cookie(
        string $name,
        string $value = '',
        int $expires = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false,
        string $samesite = ''
    ): self {
        $cookieString = sprintf(
            '%s=%s',
            rawurlencode($name),
            rawurlencode($value)
        );

        if ($expires !== 0) {
            $cookieString .= '; Expires=' . gmdate('D, d M Y H:i:s T', $expires);
            $cookieString .= '; Max-Age=' . max(0, $expires - time());
        }

        if ($path !== '') {
            $cookieString .= '; Path=' . $path;
        }

        if ($domain !== '') {
            $cookieString .= '; Domain=' . $domain;
        }

        if ($secure) {
            $cookieString .= '; Secure';
        }

        if ($httponly) {
            $cookieString .= '; HttpOnly';
        }

        if ($samesite !== '') {
            $cookieString .= '; SameSite=' . $samesite;
        }

        return $this->addHeader('Set-Cookie', $cookieString);
    }

    /**
     * Get the underlying PSR-7 response
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Send the response
     */
    public function send(): void
    {
        if (!headers_sent()) {
            // Send status code
            http_response_code($this->response->getStatusCode());

            // Send headers
            foreach ($this->response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        // Send body
        echo $this->response->getBody()->getContents();
    }

    /**
     * Get response as string
     */
    public function __toString(): string
    {
        $output = sprintf(
            "HTTP/%s %d %s\r\n",
            $this->response->getProtocolVersion(),
            $this->response->getStatusCode(),
            $this->response->getReasonPhrase()
        );

        foreach ($this->response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $output .= sprintf("%s: %s\r\n", $name, $value);
            }
        }

        $output .= "\r\n";
        $output .= $this->response->getBody()->getContents();

        return $output;
    }

    /**
     * Create response from array (auto-detects content type)
     */
    public static function fromArray(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $instance = new self();
        
        if (isset($headers['Content-Type'])) {
            $contentType = $headers['Content-Type'];
        } else {
            $contentType = 'application/json';
        }

        $headers['Content-Type'] = $contentType;

        switch ($contentType) {
            case 'application/json':
                return $instance->json($data, $status, $headers);
            case 'text/html':
                return $instance->html($data['html'] ?? '', $status, $headers);
            case 'text/plain':
                return $instance->text($data['text'] ?? '', $status, $headers);
            default:
                return $instance->json($data, $status, $headers);
        }
    }
}