<?php
declare(strict_types=1);
namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $middleware = []): void { $this->add('GET', $path, $handler, $middleware); }
    public function post(string $path, array $handler, array $middleware = []): void { $this->add('POST', $path, $handler, $middleware); }

    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $this->routes[] = compact('method', 'path', 'handler', 'middleware');
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $basePath = parse_url((string)config('app.url'), PHP_URL_PATH) ?: '';
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        foreach ($this->routes as $route) {
            $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['path']);
            if ($route['method'] === $method && preg_match('#^' . $pattern . '$#', $path, $m)) {
                foreach ($route['middleware'] as $middleware) {
                    $middleware();
                }
                [$class, $action] = $route['handler'];
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                (new $class())->$action(...array_values($params));
                return;
            }
        }
        http_response_code(404);
        echo '<h1>404</h1><p>Page not found.</p>';
    }
}
