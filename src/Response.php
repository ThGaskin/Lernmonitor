<?php
declare(strict_types=1);

/**
 * HTTP response helpers.
 */
class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public static function html(string $templatePath, int $status = 200, array $vars = []): void
    {
        if (!file_exists($templatePath)) {
            http_response_code(500);
            echo 'Template not found: ' . htmlspecialchars($templatePath);
            return;
        }
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        extract($vars, EXTR_SKIP);
        include $templatePath;
    }

    public static function text(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain');
        echo $body;
    }

    public static function unauthorized(): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
    }

    public static function forbidden(): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
    }

    public static function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }

    public static function csv(string $content, string $filename): void
    {
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        // BOM so Excel opens UTF-8 correctly
        echo "\xEF\xBB\xBF" . $content;
    }
}
