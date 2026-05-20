<?php

declare(strict_types=1);

namespace Passway\Core;

/**
 * Wrapper around the HTTP request.
 *
 * Encapsulates $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES and the request body.
 * All read methods return null/default when a value is missing -
 * never throw exceptions when accessing a missing key.
 */
final class Request
{
    /** @var array<string, mixed> Parsed JSON request body */
    private ?array $jsonBody = null;

    /** @var array<string, string> Route parameters (:id, :slug etc.) */
    private array $routeParams = [];

    public function __construct(
        private readonly array $server,
        private readonly array $get,
        private readonly array $post,
        private readonly array $cookie,
        private readonly array $files,
        private readonly string $rawBody
    ) {}

    // ------------------------------------------------------------------ //
    //  Factory method                                                     //
    // ------------------------------------------------------------------ //

    public static function fromGlobals(): self
    {
        return new self(
            server:  $_SERVER,
            get:     $_GET,
            post:    $_POST,
            cookie:  $_COOKIE,
            files:   $_FILES,
            rawBody: (string) file_get_contents('php://input'),
        );
    }

    // ------------------------------------------------------------------ //
    //  Method and path                                                        //
    // ------------------------------------------------------------------ //

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * URL path without query string and trailing slash.
     */
    public function path(): string
    {
        $uri  = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    public function uri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isGet(): bool    { return $this->isMethod('GET'); }
    public function isPost(): bool   { return $this->isMethod('POST'); }
    public function isPut(): bool    { return $this->isMethod('PUT'); }
    public function isPatch(): bool  { return $this->isMethod('PATCH'); }
    public function isDelete(): bool { return $this->isMethod('DELETE'); }

    public function isAjax(): bool
    {
        return ($this->header('X-Requested-With') ?? '') === 'XMLHttpRequest';
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path(), '/api/');
    }

    public function expectsJson(): bool
    {
        return $this->isApi()
            || str_contains($this->header('Accept') ?? '', 'application/json')
            || $this->isAjax();
    }

    // ------------------------------------------------------------------ //
    //  Query string, POST, JSON body                                       //
    // ------------------------------------------------------------------ //

    /**
     * Get a parameter from GET, POST, or the JSON body (in the specified priority).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key]
            ?? $this->get[$key]
            ?? $this->json($key)
            ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Get a value from the parsed JSON body.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->jsonBody === null) {
            $this->parseJson();
        }

        if ($key === null) {
            return $this->jsonBody;
        }

        return $this->jsonBody[$key] ?? $default;
    }

    /**
     * Get the full request body as a string.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Get multiple fields at once.
     *
     * @param  string[] $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->input($key);
        }
        return $result;
    }

    // ------------------------------------------------------------------ //
    //  Headers                                                           //
    // ------------------------------------------------------------------ //

    public function header(string $name): ?string
    {
        // HTTP headers in $_SERVER are stored as HTTP_NAME_NAME
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        // Content-Type and Content-Length - without the HTTP_ prefix
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        } elseif ($name === 'Content-Length') {
            $key = 'CONTENT_LENGTH';
        }

        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function contentType(): ?string
    {
        return $this->server['CONTENT_TYPE'] ?? null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    public function apiKey(): ?string
    {
        return $this->header('X-Api-Key') ?? $this->header('X-API-Key');
    }

    // ------------------------------------------------------------------ //
    //  IP address and User-Agent                                               //
    // ------------------------------------------------------------------ //

    /**
     * Get the real client IP address.
     * Supports X-Forwarded-For and X-Real-IP behind a proxy.
     * IMPORTANT: trust proxy headers only when the application is explicitly behind a proxy.
     */
    public function ip(): string
    {
        // In production behind a proxy (nginx) trust X-Real-IP
        if (($_ENV['APP_BEHIND_PROXY'] ?? 'false') === 'true') {
            if (!empty($this->server['HTTP_X_REAL_IP'])) {
                $ip = filter_var($this->server['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP);
                if ($ip !== false) {
                    return $ip;
                }
            }
            if (!empty($this->server['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', $this->server['HTTP_X_FORWARDED_FOR']);
                $ip    = filter_var(trim($parts[0]), FILTER_VALIDATE_IP);
                if ($ip !== false) {
                    return $ip;
                }
            }
        }

        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    // ------------------------------------------------------------------ //
    //  Cookies                                                             //
    // ------------------------------------------------------------------ //

    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookie[$name] ?? $default;
    }

    // ------------------------------------------------------------------ //
    //  Route parameters (set by the router)                         //
    // ------------------------------------------------------------------ //

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function routeParam(string $name, mixed $default = null): mixed
    {
        return $this->routeParams[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    // ------------------------------------------------------------------ //
    //  Private methods                                                    //
    // ------------------------------------------------------------------ //

    private function parseJson(): void
    {
        $contentType = $this->contentType() ?? '';
        if (!str_contains($contentType, 'application/json')) {
            $this->jsonBody = [];
            return;
        }

        if ($this->rawBody === '') {
            $this->jsonBody = [];
            return;
        }

        $decoded = json_decode($this->rawBody, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];
    }
}
