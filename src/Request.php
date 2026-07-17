<?php
declare(strict_types=1);

/**
 * Wraps the current HTTP request.
 * Provides typed access to method, path, GET/POST params, JSON body, cookies, headers.
 */
class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** URL path, trailing slash stripped (root stays as '/'). */
    public function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return rtrim((string)$uri, '/') ?: '/';
    }

    /** Query string param ($_GET). */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Form body param ($_POST). */
    public function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /** Raw request body (for JSON API calls). */
    public function body(): string
    {
        static $body = null;
        if ($body === null) {
            $body = file_get_contents('php://input') ?: '';
        }
        return $body;
    }

    /** Parse body as JSON; returns null if empty or invalid. */
    public function json(): ?array
    {
        $data = json_decode($this->body(), true);
        return is_array($data) ? $data : null;
    }

    /** Cookie value. */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $_COOKIE[$key] ?? $default;
    }

    /** HTTP header (case-insensitive, use hyphen-separated names like 'Content-Type'). */
    public function header(string $key, mixed $default = null): mixed
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $_SERVER[$normalized] ?? $default;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /** True if this looks like an AJAX/fetch call expecting JSON back. */
    public function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }
}
