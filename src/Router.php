<?php
declare(strict_types=1);

/**
 * Simple HTTP router.
 *
 * Supports exact paths and named parameters:
 *   $router->get('/student/{id}', function(Request $req, string $id) { ... });
 *
 * Routes are matched in registration order; exact matches take priority.
 */
class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = $request->path();

        // Exact match first (fast path, no regex)
        if (isset($this->routes[$method][$path])) {
            ($this->routes[$method][$path])($request);
            return;
        }

        // Pattern match for routes with {param} segments
        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            [$regex, $paramNames] = $this->compilePattern($pattern);
            if (preg_match($regex, $path, $matches)) {
                array_shift($matches); // drop full-match capture
                $params = array_combine($paramNames, $matches);
                $handler($request, $params);
                return;
            }
        }

        $this->notFound($request);
    }

    /** Convert '/student/{id}' → ['#^/student/([^/]+)$#', ['id']] */
    private function compilePattern(string $pattern): array
    {
        $paramNames = [];
        $regex = preg_replace_callback('/\{([^}]+)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        return ['#^' . $regex . '$#', $paramNames];
    }

    private function notFound(Request $request): void
    {
        http_response_code(404);
        if ($request->wantsJson()) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found']);
        } else {
            // Will be replaced with a proper 404 template once templates are wired up
            echo '404 Not Found';
        }
    }
}
