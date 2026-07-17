<?php
/**
 * PHP built-in server router.
 * Usage: php -S localhost:8080 router.php
 *
 * Serves real files (CSS, JS, images) directly; routes everything else
 * through public/index.php — replicating the .htaccess front-controller.
 */

// Load .env if present (only needed for php -S; real servers use SetEnv/environment)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            // Real environment variables take precedence over the .env file so
            // that the test runner can point at a different database by setting
            // DB_NAME etc. in the process environment.
            if (getenv($key) === false && !isset($_ENV[$key])) {
                $_ENV[$key] = trim($val);
                putenv($key . '=' . trim($val));
            }
        }
    }
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve real static files from public/ with correct MIME type
$file = __DIR__ . '/public' . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'html' => 'text/html',
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff2'=> 'font/woff2',
        'woff' => 'font/woff',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    readfile($file);
    exit;
}

// Everything else goes through the front controller
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
require __DIR__ . '/public/index.php';
