<?php

declare(strict_types=1);

namespace Passway\Core;

/**
 * HTTP-ответ.
 *
 * Предоставляет fluent-интерфейс для формирования ответа.
 * send() выводит все заголовки и тело, после чего выполнение завершается.
 */
final class Response
{
    private int    $statusCode = 200;
    private string $body       = '';
    private string $contentType = 'text/html; charset=utf-8';

    /** @var array<string, string> */
    private array $headers = [];

    // ------------------------------------------------------------------ //
    //  Статические фабрики                                                 //
    // ------------------------------------------------------------------ //

    public static function make(int $status = 200): self
    {
        $response = new self();
        $response->statusCode = $status;
        return $response;
    }

    /**
     * Отправить JSON-ответ.
     *
     * @param array<string, mixed>|list<mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return self::make($status)
            ->withContentType('application/json')
            ->withBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Отправить JSON-ответ об успехе.
     *
     * @param array<string, mixed>|null $data
     */
    public static function success(?array $data = null, int $status = 200): self
    {
        $body = ['success' => true];
        if ($data !== null) {
            $body['data'] = $data;
        }
        return self::json($body, $status);
    }

    /**
     * Отправить JSON-ответ об ошибке.
     */
    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        $body = array_merge(
            ['success' => false, 'error' => $message],
            $extra
        );
        return self::json($body, $status);
    }

    /**
     * Перенаправление.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return self::make($status)->withHeader('Location', $url);
    }

    /**
     * Ответ 404.
     */
    public static function notFound(string $message = 'Not Found'): self
    {
        return self::error($message, 404);
    }

    /**
     * Ответ 401.
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, 401);
    }

    /**
     * Ответ 403.
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::error($message, 403);
    }

    /**
     * Ответ 422 (ошибка валидации).
     *
     * @param array<string, string[]> $errors
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): self
    {
        return self::error($message, 422, ['errors' => $errors]);
    }

    /**
     * Ответ 500.
     */
    public static function serverError(string $message = 'Internal Server Error'): self
    {
        return self::error($message, 500);
    }

    // ------------------------------------------------------------------ //
    //  Fluent-методы построения ответа                                     //
    // ------------------------------------------------------------------ //

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->statusCode = $status;
        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function withContentType(string $type): self
    {
        $clone = clone $this;
        $clone->contentType = $type;
        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    // ------------------------------------------------------------------ //
    //  Отправка                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Отправить ответ клиенту.
     * После вызова execution продолжается (exit не вызывается).
     */
    public function send(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($this->statusCode);
        header("Content-Type: {$this->contentType}");

        // Заголовки безопасности (базовые)
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }

    /**
     * Отправить ответ и завершить выполнение.
     */
    public function sendAndExit(): never
    {
        $this->send();
        exit;
    }

    // ------------------------------------------------------------------ //
    //  Геттеры                                                             //
    // ------------------------------------------------------------------ //

    public function getStatusCode(): int    { return $this->statusCode; }
    public function getBody(): string       { return $this->body; }
    public function getContentType(): string { return $this->contentType; }

    /** @return array<string, string> */
    public function getHeaders(): array     { return $this->headers; }
}
