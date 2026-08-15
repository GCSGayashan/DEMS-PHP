<?php
declare(strict_types=1);

const BASE_PATH = __DIR__;

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function env(string $key, mixed $default = null): mixed
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $file = BASE_PATH . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $v = trim($v, "\"'");
                $_ENV[$k] = $v;
            }
        }
    }
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return match (strtolower((string)$value)) {
        'true' => true,
        'false' => false,
        'null' => null,
        default => $value,
    };
}

function config(string $key, mixed $default = null): mixed
{
    static $cache = [];
    [$file, $item] = array_pad(explode('.', $key, 2), 2, null);
    if (!isset($cache[$file])) {
        $path = BASE_PATH . '/config/' . $file . '.php';
        $cache[$file] = is_file($path) ? require $path : [];
    }
    return $item === null ? ($cache[$file] ?? $default) : ($cache[$file][$item] ?? $default);
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = rtrim((string)config('app.url', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}
